"""blueprint.py app: the release app of an owner, from one command.

GitHub registers an app from a manifest: the owner confirms one page, and the API hands
over the id, the client ID and the private key of the app, this once. app register
builds the manifest from the permissions that the checklist names and sends it to
GitHub through a form, which a server of its own shows on a free port of 127.0.0.1,
so that only this machine reaches it and no file holds the form. GitHub comes back to
that server with a code and the state of the form, a random string that no other page
knows.

gh trades the code for the app, and gh secret set takes the private key on its stdin:
the key stays in memory, no file holds it and nothing prints it. The answers of the
blueprint then name the app on a branch of their own, since main takes pull requests
only. What only the owner can do stays theirs: pressing Create on the page of GitHub,
and installing the app.

app connect does the same for an app that exists, such as for a further repository of
the owner: GitHub shows a private app to nobody through the token of gh, so the name,
the client ID and the App ID come from the answers or the command line, and the
private key from a file that GitHub gave the owner.
"""

from __future__ import annotations

import html
import json
import re
import secrets
import shlex
import subprocess
import sys
import threading
import time
import webbrowser
from collections.abc import Callable, Sequence
from dataclasses import astuple, dataclass, field
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler
from pathlib import Path
from socketserver import ThreadingTCPServer
from typing import Protocol
from urllib.parse import parse_qs, quote, urlencode, urlsplit

from blueprint.bootstrap import (
    ANSWERS,
    PUSH_AS_GH,
    Answer,
    CreateError,
    Finding,
    ask,
    body,
    checked_repo,
    describe,
    installed,
    problem,
    stops,
)
from blueprint.checklist import (
    RELEASE_APP_DESCRIPTION,
    RELEASE_APP_PERMISSIONS,
    release_app_name,
)

KEY_NAME = "RELEASE_AUTOMATION_PRIVATE_KEY"
ENVIRONMENT = "release"
BRANCH = "name-the-release-app"
MESSAGE = "chore: name the release app"
TOOL = "python3 .github/blueprint.py"
KEY_FILE = "private-key.pem"
# How long the page may wait for the owner, in seconds. GitHub's code lasts an hour.
TIMEOUT = 600
LOCAL = "127.0.0.1"
CALLBACK = "/callback"
# How often the server looks for the end of its wait, in seconds.
POLL = 0.05
# How long one wait for GitHub lasts: on Windows before Python 3.14, Ctrl+C reaches
# Python only between two waits, so one long wait would ignore it.
SLICE = 0.2
# Copier of the quick start, by the first program of the owner that runs it, or one
# of their own. A manager in .github/renovate.json5 moves the version with the pages.
PINNED = "copier==9.19.1 copier update"
COPIERS = (f"uvx --from {PINNED}", f"pipx run --spec {PINNED}", "copier update")
# The answers that name the app, as Copier writes them, and as its questions take them.
ANSWER = re.compile(
    r"^(release_app|release_app_client_id|release_app_id): (['\"]?)([\w.-]*)\2$",
    re.MULTILINE,
)
NAME = re.compile(r"[a-z0-9](?:[a-z0-9-]*[a-z0-9])?")
CLIENT_ID = re.compile(r"[A-Za-z0-9.]+")
APP_ID = re.compile(r"[0-9]+")
PRIVATE_KEY = re.compile(r"-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----")
PAGE = """\
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="color-scheme" content="light dark">
<title>The release app</title>
<body>
{}
</html>
"""
FORM = """\
<form action="{action}" method="post">
<input type="hidden" name="manifest" value="{manifest}">
<p>This page sends the manifest of the release app to GitHub, where you confirm it.</p>
<button type="submit">Go on to GitHub</button>
</form>
<script>document.forms[0].submit()</script>
"""
DONE = (
    "<p>GitHub has registered the app. Close this page and go back to the terminal.</p>"
)
CANCELLED = (
    "<p>GitHub sent no app. Go back to the terminal, and run the command again.</p>"
)
WRONG_STATE = (
    "<p>This answer doesn't belong to the page of this run, and it was refused.</p>"
)
NOT_HERE = "<p>Nothing is here.</p>"


class AppError(CreateError):
    """What stops app register or app connect; what it says holds no secret."""


class Runner(Protocol):
    """Runs git, gh or Copier in a folder, with text on its stdin."""

    def __call__(
        self, arguments: Sequence[str], folder: Path, stdin: str = ""
    ) -> subprocess.CompletedProcess[str]: ...


@dataclass(frozen=True)
class App:
    """What names an app in the answers of the blueprint, none of it secret."""

    name: str = ""
    client_id: str = ""
    app_id: str = ""

    def data(self) -> list[str]:
        return [
            "--data",
            f"release_app={self.name}",
            "--data",
            f"release_app_client_id={self.client_id}",
            "--data",
            f"release_app_id={self.app_id}",
        ]

    def settings_lines(self) -> list[str]:
        return [
            f"release_app: {self.name}",
            f"release_app_client_id: {self.client_id}",
            f'release_app_id: "{self.app_id}"',
        ]

    def connect_command(self) -> str:
        return (
            f"{TOOL} app connect --name {self.name} --client-id {self.client_id}"
            f" --app-id {self.app_id} --key-file {KEY_FILE}"
        )

    @property
    def installation(self) -> str:
        return f"https://github.com/apps/{self.name}/installations/new"


@dataclass(frozen=True)
class Made:
    """What GitHub handed over for the code: the app, its owner and its private key,
    which no repr shows."""

    app: App
    owner: str
    key: str = field(repr=False)


@dataclass(frozen=True)
class Ready:
    """What the preflight found out: whether the owner is an organization, and the
    command that runs Copier."""

    findings: list[Finding]
    organization: bool = False
    copier: list[str] = field(default_factory=list)


def run_command(
    arguments: Sequence[str], folder: Path, stdin: str = ""
) -> subprocess.CompletedProcess[str]:
    """Run a command with its output kept from the terminal, and text on its stdin,
    which is empty and closed without any, so that no program can wait for a person.
    The stdin goes as bytes: in text mode, Windows would change the line ends of the
    private key on the way."""
    result = subprocess.run(
        list(arguments),
        cwd=folder,
        input=stdin.encode(),
        capture_output=True,
        check=False,
    )
    return subprocess.CompletedProcess(
        result.args,
        result.returncode,
        result.stdout.decode(errors="replace"),
        result.stderr.decode(errors="replace"),
    )


def manifest(owner: str, address: str) -> dict[str, object]:
    """The manifest of the release app of owner, private and without a webhook; GitHub
    asks for the address of a webhook even when it is off."""
    home = f"https://github.com/{owner}"
    return {
        "name": release_app_name(owner),
        "url": home,
        "description": RELEASE_APP_DESCRIPTION,
        "public": False,
        "redirect_url": f"{address}{CALLBACK}",
        "hook_attributes": {"url": home, "active": False},
        "default_permissions": {
            key: access for _, key, access in RELEASE_APP_PERMISSIONS
        },
    }


def settings_page(owner: str, organization: bool, slug: str = "new") -> str:
    """The page of GitHub that registers an app, or the settings page of the app."""
    account = f"organizations/{owner}/" if organization else ""
    return f"https://github.com/{account}settings/apps/{slug}"


class Redirects(BaseHTTPRequestHandler):
    """The answers of the local server: the form, and GitHub's way back."""

    server: Waiting

    def do_GET(self) -> None:
        address = urlsplit(self.path)
        if self.headers.get("Host") != self.server.host:
            # A name of a stranger's page that points to this address, by which that
            # page could read the state of the form.
            self.show(HTTPStatus.FORBIDDEN, NOT_HERE)
        elif address.path == "/":
            self.show(HTTPStatus.OK, self.server.form)
        elif address.path == CALLBACK:
            self.came_back(parse_qs(address.query))
        else:
            self.show(HTTPStatus.NOT_FOUND, NOT_HERE)

    def came_back(self, query: dict[str, list[str]]) -> None:
        """GitHub's way back with the state of the form. A wrong state ends nothing,
        so that any page that calls this address can't end the wait of the owner, and
        its code goes nowhere."""
        state = query.get("state", [""])[0]
        if not secrets.compare_digest(state.encode(), self.server.state.encode()):
            print(
                "Refused an answer whose state doesn't belong to this run.",
                file=sys.stderr,
            )
            self.show(HTTPStatus.BAD_REQUEST, WRONG_STATE)
            return
        self.server.code = query.get("code", [""])[0]
        try:
            self.show(HTTPStatus.OK, DONE if self.server.code else CANCELLED)
        finally:
            self.server.arrived.set()

    def show(self, status: HTTPStatus, content: str) -> None:
        data = PAGE.format(content).encode()
        self.send_response(status)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("Referrer-Policy", "no-referrer")
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, format: str, *args: object) -> None:
        """Nothing: the address of GitHub's way back holds the code, which trades for
        the private key for an hour, and it goes on no terminal."""


class Waiting(ThreadingTCPServer):
    """The server on a free port of 127.0.0.1 that shows the form and waits for
    GitHub. Each request has a thread, since a browser may open a connection that
    stays idle."""

    daemon_threads = True

    def __init__(self, owner: str, organization: bool) -> None:
        super().__init__((LOCAL, 0), Redirects)
        self.host = f"{LOCAL}:{self.server_address[1]}"
        self.address = f"http://{self.host}"
        self.state = secrets.token_urlsafe(32)
        self.code = ""
        self.arrived = threading.Event()
        action = (
            f"{settings_page(owner, organization)}?{urlencode({'state': self.state})}"
        )
        self.form = FORM.format(
            action=html.escape(action),
            manifest=html.escape(json.dumps(manifest(owner, self.address))),
        )

    def handle_error(self, request: object, client_address: object) -> None:
        """Nothing: a browser may go away at any time, which is no error of this run."""


def wait_for_code(
    server: Waiting, browser: Callable[[str], bool] | None, timeout: float
) -> str:
    """The code of GitHub's way back to server, once the owner confirmed the page."""
    threading.Thread(target=server.serve_forever, args=(POLL,), daemon=True).start()
    again = "Nothing is set: run the command again."
    try:
        print(
            "Sign in to GitHub in your browser, as the owner of the app or an owner of"
            f" its organization, and confirm the page at:\n  {server.address}",
            flush=True,
        )
        try:
            opened = browser is not None and browser(server.address)
        except webbrowser.Error:
            opened = False
        if not opened:
            print(
                "Open it in a browser on this machine. On another one, forward the port"
                " to it first, such as with ssh -L.",
                flush=True,
            )
        deadline = time.monotonic() + timeout
        while not server.arrived.wait(
            min(SLICE, max(0.0, deadline - time.monotonic()))
        ):
            if time.monotonic() >= deadline:
                raise AppError(
                    f"GitHub didn't come back within {timeout:g} seconds, because the"
                    f" page was closed or wasn't confirmed. {again}"
                )
    except KeyboardInterrupt:
        raise AppError(f"Stopped before GitHub came back. {again}") from None
    finally:
        server.shutdown()
    if not server.code:
        raise AppError(f"The page was cancelled: GitHub sent no app. {again}")
    return server.code


def trade(run: Runner, root: Path, code: str, owner: str, organization: bool) -> Made:
    """The app and its private key for the code. What gh printed stays here: it holds
    the private key, the client secret and the secret of the webhook, and only the
    first of them is used."""
    result = run(
        [
            "gh",
            "api",
            "--method",
            "POST",
            f"app-manifests/{quote(code, safe='')}/conversions",
        ],
        root,
    )
    lost = (
        f"If the page made the app, delete it at {settings_page(owner, organization, '')}"
        " and run the command again."
    )
    if result.returncode != 0:
        raise AppError(f"GitHub didn't hand over the app: {problem(result)}\n{lost}")
    data = body(Answer(True, result.stdout))
    account = data.get("owner")
    login = str(account.get("login", "")) if isinstance(account, dict) else ""
    app = App(
        str(data.get("slug", "")),
        str(data.get("client_id", "")),
        str(data.get("id", "")),
    )
    key = str(data.get("pem", ""))
    if not all(astuple(app)) or not login or not key:
        raise AppError(f"GitHub's answer names no complete app.\n{lost}")
    return Made(app, login, key)


def set_key(run: Runner, root: Path, repo: str, key: str) -> str:
    """Hand the private key to gh on its stdin; what gh said when it failed, or nothing."""
    result = run(
        ["gh", "secret", "set", KEY_NAME, "--env", ENVIRONMENT, "--repo", repo],
        root,
        key,
    )
    return problem(result) if result.returncode != 0 else ""


def naming_steps(copier: Sequence[str], app: App) -> list[list[str]]:
    """The steps that name the app in the answers, on a branch of their own: Copier
    writes .github/repository.toml from them, and main takes pull requests only."""
    return [
        ["git", "switch", "--create", BRANCH],
        [*copier, "--defaults", "--vcs-ref=:current:", *app.data()],
        ["git", "add", "--all"],
        ["git", "commit", "--signoff", "--message", MESSAGE],
        [*PUSH_AS_GH, "push", "--set-upstream", "origin", "HEAD"],
        ["gh", "pr", "create", "--fill"],
    ]


def name_app(run: Runner, root: Path, steps: list[list[str]]) -> str:
    """Run the steps, and return the address of the pull request; a step that fails
    stops them, with the steps that are left to run by hand."""
    print("Naming the app in the answers of the blueprint, in a pull request:")
    pull = ""
    for place, step in enumerate(steps):
        print(f"  {shlex.join(step)}", flush=True)
        result = run(step, root)
        if result.returncode != 0:
            left = "\n".join(f"  {shlex.join(item)}" for item in steps[place:])
            raise AppError(
                f"{shlex.join(step)} failed: {problem(result)}\nThe private key is set,"
                f" and the app isn't named yet. Fix that, and go on with:\n{left}"
            )
        pull = result.stdout.strip()
    return pull


def show_steps(steps: list[list[str]]) -> None:
    print("Name the app in the answers of the blueprint, in a pull request:")
    for step in steps:
        print(f"  {shlex.join(step)}")


def answered_app(root: Path) -> App:
    """The app that the answers of Copier name, with empty parts for what they lack."""
    path = root / ANSWERS
    text = path.read_text(encoding="utf-8") if path.exists() else ""
    answers = {key: value for key, _, value in ANSWER.findall(text)}
    return App(
        answers.get("release_app", ""),
        answers.get("release_app_client_id", ""),
        answers.get("release_app_id", ""),
    )


def wrong_parts(app: App) -> list[str]:
    """What is missing or can't be right in the names of an app, by the same rules as
    the questions of Copier, so that a typing error shows here and not in a late bot."""
    parts = []
    if not NAME.fullmatch(app.name):
        parts.append("--name, the name in the address of the app")
    if not CLIENT_ID.fullmatch(app.client_id) or APP_ID.fullmatch(app.client_id):
        parts.append("--client-id, the Client ID on its settings page, such as Iv23li…")
    if not APP_ID.fullmatch(app.app_id):
        parts.append("--app-id, the App ID on its settings page, a number")
    return parts


def read_key(path: Path) -> str:
    """The private key in the file; what is wrong with it never quotes it."""
    try:
        key = path.read_text(encoding="utf-8-sig")
    except UnicodeDecodeError:
        key = ""
    if not PRIVATE_KEY.search(key):
        raise AppError(
            f"{path} holds no private key: the file that GitHub gives you starts with"
            " -----BEGIN RSA PRIVATE KEY-----"
        )
    return key


def copier_command(run: Runner, root: Path) -> list[str]:
    """The command that runs Copier, by the first program that runs here."""
    for command in COPIERS:
        words = shlex.split(command)
        if installed(run, [words[0], "--version"], root) is not None:
            return words
    return []


def github_findings(run: Runner, root: Path) -> tuple[list[Finding], str]:
    """Whether gh runs and is signed in, and as whom."""
    if installed(run, ["gh", "--version"], root) is None:
        return [
            Finding(
                False,
                "GitHub CLI is not installed",
                "install it: https://github.com/cli/cli#installation",
            )
        ], ""
    user = ask(run, root, "user")
    login = str(body(user).get("login", "")) if user.found else ""
    if not login:
        said = user.text.splitlines()[0] if user.text else "gh said nothing"
        return [
            Finding(
                False,
                f"gh is not signed in to github.com: {said}",
                "gh auth login --hostname github.com --scopes workflow",
            )
        ], ""
    return [Finding(True, f"gh is signed in to github.com as {login}")], login


def environment_finding(run: Runner, root: Path, repo: str) -> Finding:
    """The private key goes into the environment, which gh doesn't create."""
    answer = ask(run, root, f"repos/{repo}/environments/{ENVIRONMENT}")
    if answer.found:
        return Finding(True, f"{repo} has the environment {ENVIRONMENT}")
    if answer.found is False:
        return Finding(
            False,
            f"{repo} has no environment {ENVIRONMENT}, which takes the private key",
            f"{TOOL} create, or {TOOL} settings apply for a repository that exists",
        )
    return Finding(
        None, f"GitHub didn't say whether {repo} has {ENVIRONMENT}: {answer.text}"
    )


def branch_findings(run: Runner, root: Path) -> list[Finding]:
    """Whether the branch of the pull request can start at main: a branch of an earlier
    attempt would fail after the key is made, and another branch would come along."""
    current = installed(run, ["git", "branch", "--show-current"], root)
    left = installed(
        run, ["git", "rev-parse", "--verify", "--quiet", f"refs/heads/{BRANCH}"], root
    )
    return [
        Finding(True, "This folder is on main, where the pull request starts")
        if current is not None and current.stdout.strip() == "main"
        else Finding(
            False,
            "This folder isn't on main, where the pull request has to start",
            "git switch main, or add --no-update",
        ),
        Finding(True, f"No branch {BRANCH} is left of an earlier attempt")
        if left is None
        else Finding(
            False,
            f"The branch {BRANCH} is left of an earlier attempt",
            f"git branch -D {BRANCH}, or add --no-update",
        ),
    ]


def local_findings(run: Runner, root: Path) -> tuple[list[Finding], list[str]]:
    """Whether Copier can write the answers: it refuses a folder with changes that no
    commit holds, and the command that runs it."""
    status = installed(run, ["git", "status", "--porcelain"], root)
    clean = (
        Finding(
            False,
            "Git can't read this folder, which has to be the copy of the blueprint",
            f"{TOOL} create",
        )
        if status is None
        else Finding(True, "This folder has no changes that a commit doesn't hold")
        if not status.stdout.strip()
        else Finding(
            False,
            "This folder has changes that no commit holds, which Copier refuses",
            "commit them or stash them, or add --no-update",
        )
    )
    copier = copier_command(run, root)
    return [
        clean,
        *branch_findings(run, root),
        Finding(True, f"Copier runs with {copier[0]}")
        if copier
        else Finding(
            False,
            "Neither uv, pipx nor Copier is installed, which the answers need",
            "install uv, https://docs.astral.sh/uv/, or add --no-update",
        ),
    ], copier


def owner_findings(
    run: Runner, root: Path, repo: str, login: str, org: str | None
) -> tuple[list[Finding], bool]:
    """Whether the app can be registered on the owner of the repository, and whether
    that is an organization. A private app installs only on the account that owns it,
    so no other account will do."""
    owner = repo.split("/")[0]
    if org and org.lower() != owner.lower():
        return [
            Finding(
                False,
                f"An app of {org} can't be installed on {repo}, since a private app"
                " installs only on the account that owns it",
                f"--org {owner}, or a repository of {org}",
            )
        ], True
    account = ask(run, root, f"users/{owner}")
    if account.found is False:
        return [
            Finding(
                False,
                f"GitHub has no account or organization {owner}",
                f"{TOOL} --repo OWNER/NAME app register",
            )
        ], False
    if account.found is None and not org:
        return [
            Finding(None, f"GitHub didn't say who {owner} is: {account.text}")
        ], False
    if org or body(account).get("type") == "Organization":
        return [organization_finding(run, root, owner, login)], True
    if owner.lower() == login.lower():
        return [Finding(True, f"{owner} is the account that gh is signed in as")], False
    return [
        Finding(
            False,
            f"{owner} is an account of its own, and gh is signed in as {login}",
            f"gh auth switch --user {owner}, or gh auth login as {owner}",
        )
    ], False


def organization_finding(run: Runner, root: Path, owner: str, login: str) -> Finding:
    """Whether the user may register an app on the organization, which takes an owner
    of it or a manager of its apps, which GitHub doesn't tell."""
    membership = ask(run, root, f"user/memberships/orgs/{owner}")
    if membership.found is False:
        return Finding(
            False,
            f"{login} is no member of the organization {owner}",
            f"gh auth login as an owner of {owner}",
        )
    if membership.found and body(membership).get("role") == "admin":
        return Finding(True, f"{owner} is an organization that {login} owns")
    return Finding(
        None,
        f"{owner} is an organization, and only its owners and the managers of its apps"
        f" can register an app, which GitHub didn't confirm for {login}",
    )


def preflight(
    run: Runner, root: Path, repo: str, update: bool, register_on: str | None = None
) -> Ready:
    """What the command needs, before the browser or the private key are involved: a
    private key comes once, so nothing may stop the command after it for a reason that
    could be known now. register_on is None for app connect, which registers nothing,
    and "" for the owner of the repository."""
    findings, login = github_findings(run, root)
    if not login:
        return Ready(findings)
    organization = False
    if register_on is not None:
        found, organization = owner_findings(
            run, root, repo, login, register_on or None
        )
        findings += found
    findings.append(environment_finding(run, root, repo))
    copier: list[str] = []
    if update:
        found, copier = local_findings(run, root)
        findings += found
    return Ready(findings, organization, copier)


def checked(repo: str, ready: Ready) -> None:
    print(describe(repo, ready.findings), flush=True)
    count = stops(ready.findings)
    if count:
        raise AppError(
            f"The preflight found {count} {'problem' if count == 1 else 'problems'}, and"
            " nothing is changed: fix what is marked with ✘, then run it again."
        )


def finish(
    app: App, repo: str, pull: str, steps: list[list[str]], update: bool
) -> None:
    """What is left for the owner, the order of which lets the checklist see the app:
    GitHub opens a check suite of an installed app for each push. steps are those that
    name the app, if it isn't named yet, which were run with update."""
    if pull:
        print(f"The pull request that names it: {pull}")
    if steps and not update:
        show_steps(steps)
    print("What is left for you:")
    print(f"  1. Install the app on {repo}: {app.installation}")
    if steps:
        print("  2. Merge the pull request once its checks are green.")
        print(
            f"  3. Apply the settings: git switch main, git pull, {TOOL} settings apply"
        )
    else:
        print(f"  2. Apply the settings: {TOOL} settings apply")


def register(
    root: Path,
    *,
    repo: str | None = None,
    org: str | None = None,
    update: bool = True,
    browser: Callable[[str], bool] | None = webbrowser.open,
    timeout: float = TIMEOUT,
    run: Runner | None = None,
) -> App:
    """Register the release app of the owner of the repository from its manifest, set
    its private key and name it; browser is None to only print the address."""
    # Looked up at the call, so that a test can replace it.
    runner = run or run_command
    repo = checked_repo(root, repo)
    owner = repo.split("/")[0]
    ready = preflight(runner, root, repo, update, org or "")
    named = answered_app(root).name
    if named:
        ready.findings.append(
            Finding(
                False,
                f"{ANSWERS} names the release app {named} already",
                f"for a new private key of it: {TOOL} app connect --key-file {KEY_FILE}",
            )
        )
    checked(repo, ready)
    with Waiting(owner, ready.organization) as server:
        code = wait_for_code(server, browser, timeout)
    made = trade(runner, root, code, owner, ready.organization)
    app = made.app
    if made.owner.lower() != owner.lower():
        raise AppError(
            f"GitHub made the app {app.name} on {made.owner}, as whom the browser is"
            f" signed in, and a private app installs only on the account that owns it,"
            f" not on {repo}. Delete it at"
            f" {settings_page(made.owner, False, app.name)}/advanced, sign in as"
            f" {owner}, or as an owner of it, and run the command again."
        )
    page = settings_page(owner, ready.organization, app.name)
    print(f"Registered {app.name} on {owner}: {page}", flush=True)
    refused = set_key(runner, root, repo, made.key)
    if refused:
        raise AppError(
            f"GitHub refused the private key of {app.name}: {refused}\nNo file holds"
            f" it, so make another on {page}, on the tab Credentials, under Key pairs,"
            f" and go on with:\n  {app.connect_command()}"
        )
    print(f"Set its private key as {KEY_NAME} of {ENVIRONMENT}; no file holds it.")
    steps = naming_steps(ready.copier or shlex.split(COPIERS[0]), app)
    pull = name_app(runner, root, steps) if update else ""
    finish(app, repo, pull, steps, update)
    print(
        "For a further repository of the owner, keep these in the settings file of"
        " Copier, so that its copy names the app:"
    )
    for line in app.settings_lines():
        print(f"  {line}")
    print(
        f"  Then make a private key on {page}, on the tab Credentials, under Key pairs,"
        f" and set it there: {TOOL} app connect --key-file {KEY_FILE}"
    )
    return app


def connect(
    root: Path,
    key_file: Path,
    *,
    repo: str | None = None,
    name: str = "",
    client_id: str = "",
    app_id: str = "",
    update: bool = True,
    run: Runner | None = None,
) -> App:
    """Set the private key of an app that exists from a file, and name the app in the
    answers unless they name it already."""
    runner = run or run_command
    repo = checked_repo(root, repo)
    answered = answered_app(root)
    app = App(
        name or answered.name,
        client_id or answered.client_id,
        app_id or answered.app_id,
    )
    wrong = wrong_parts(app)
    if wrong:
        raise AppError(
            f"{ANSWERS} and the command line don't name the app: "
            + "; ".join(wrong)
            + ". On a new repository, app register makes one."
        )
    key = read_key(key_file)
    differs = app != answered
    ready = preflight(runner, root, repo, update and differs)
    checked(repo, ready)
    refused = set_key(runner, root, repo, key)
    if refused:
        raise AppError(f"GitHub refused the private key of {app.name}: {refused}")
    print(
        f"Set the private key of {app.name} as {KEY_NAME} of {ENVIRONMENT}.", flush=True
    )
    steps = (
        naming_steps(ready.copier or shlex.split(COPIERS[0]), app) if differs else []
    )
    pull = name_app(runner, root, steps) if update and steps else ""
    finish(app, repo, pull, steps, update)
    print(f"GitHub has the key now: delete {key_file}, which nothing needs any more.")
    return app
