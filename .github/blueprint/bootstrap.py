"""blueprint.py create: the repository on GitHub of a new copy of the blueprint.

Copier writes the files of a project into a folder, and nothing more. create makes a
repository of it: git init on the branch main, the first commit with a sign-off, and
the repository on GitHub, which gh repo create makes and pushes to. The command line
then applies the settings as code and shows the checklist. The owner and the name
come from .copier-answers.yml, or from --repo, and the description from
.github/repository.toml.

Before its first step, create checks what its steps need, which blueprint.py doctor
does alone: Python, Git and the identity that it commits with, the GitHub CLI and its
sign-in with the scopes of the steps, the owner, where its user has to be able to
create the repository, and the name, which has to be free. It makes nothing while
one of them would stop a step, and each such finding names what fixes it, a command
where one does. All of it only asks: git and gh change nothing before the first step.

A folder whose origin pushes to the repository, which GitHub has, is one that create
made and that stopped midway, at the push or at the settings: create goes on from
the first step that is left, the push when GitHub has no branch main yet, then the
settings and the checklist. A folder that pushes to any other repository is no new
copy, and create refuses it. A folder that git init made before, as the quick start
of older releases did, is taken as it is: its branch is renamed to main, and the
commit is made only when it has none.
"""

from __future__ import annotations

import json
import re
import shlex
import subprocess
import sys
from collections.abc import Callable, Sequence
from dataclasses import dataclass, field
from pathlib import Path

from blueprint.github import MINIMUM_GH
from blueprint.settings import BRANCH

ANSWERS = ".copier-answers.yml"
MESSAGE = "chore: start from the blueprint"
# What the command line runs after the commands of create, as the user would type it.
AFTER = (
    ["python3", ".github/blueprint.py", "settings", "apply"],
    ["python3", ".github/blueprint.py", "checklist"],
)
# The owner and the name of the repository, as Copier writes them into its answers:
# the questions take letters, digits, hyphens and the dots of owner.github.io only.
# Copier writes them with PyYAML, which quotes a value that YAML would read as a
# number, a bool or null, such as the slug '2048' or an owner of digits only.
ANSWER = re.compile(r"^(owner|project_slug): (['\"]?)([\w.-]+)\2$", re.MULTILINE)
REPO = re.compile(r"[\w.-]+/[\w.-]+")
# The oldest Python that the tools of a new repository run on, such as its lock files.
MINIMUM_PYTHON = (3, 12)
# The scopes of the token of gh that the steps need: repo creates the repository and
# pushes, workflow pushes .github/workflows/, and read:org reads the membership in an
# organization that owns it, which write:org and admin:org include.
# git as gh repo create --push runs it: signed in by gh, without a change of config.
PUSH_AS_GH = (
    "git",
    "-c",
    "credential.helper=",
    "-c",
    "credential.helper=!gh auth git-credential",
)
WORKFLOW_SCOPE = "workflow"
SCOPES = ("repo", WORKFLOW_SCOPE)
ORGANIZATION_SCOPE = "read:org"
WIDER_SCOPES = {ORGANIZATION_SCOPE: {"write:org", "admin:org"}}
# Where origin pushes to, in each form of address that gh and git give it.
REMOTE = re.compile(
    r"(?:https://(?:[^@/]+@)?github\.com/|(?:ssh://)?git@github\.com[:/])"
    r"([\w.-]+/[\w.-]+?)(?:\.git)?/?"
)
VERSION = re.compile(r"(\d+)\.(\d+)(?:\.\d+)?")
# What gh api says when GitHub has nothing at a path: 404, or 409 for a branch of an
# empty repository.
NOTHING = re.compile(r"\(HTTP 40[49]\)")
# What gh says without a valid sign-in: its hint to sign in, or a 401 of GitHub.
SIGNED_OUT = re.compile(r"gh auth login|\(HTTP 401\)")
SCOPE_HEADER = re.compile(r"^x-oauth-scopes:(.*)$", re.IGNORECASE | re.MULTILINE)
# The time and the zone that git var adds to an identity.
STAMP = re.compile(r" \d+ [+-]\d{4}$")
MARKS = {True: "✔", False: "✘", None: "·"}

Runner = Callable[[Sequence[str], Path], subprocess.CompletedProcess[str]]


class CreateError(Exception):
    """A folder that create can't make a repository of, or a command that failed."""


@dataclass(frozen=True)
class Finding:
    """One check of the preflight: passed (True), stops create (False), or can't tell
    and stops nothing (None); fix is what fixes it, a command where one does."""

    ok: bool | None
    text: str
    fix: str = ""


@dataclass
class Plan:
    """The steps in the folder before GitHub, and whether origin pushes to the
    repository already, from a run of create that stopped midway."""

    steps: list[list[str]] = field(default_factory=list)
    resume: bool = False

    @property
    def commits(self) -> bool:
        return any(step[:2] == ["git", "commit"] for step in self.steps)


@dataclass(frozen=True)
class Answer:
    """What gh api answered: found is True with the body, False when GitHub has
    nothing at the path, and None with the message of gh for any other failure."""

    found: bool | None
    text: str


def run_command(
    arguments: Sequence[str], folder: Path
) -> subprocess.CompletedProcess[str]:
    """Run git or gh in folder; the tests replace this with a simulated git and gh."""
    return subprocess.run(  # pragma: no cover - the tests simulate git and gh
        list(arguments),
        cwd=folder,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        check=False,
    )


def answered_repo(root: Path) -> str:
    """OWNER/NAME of the repository, from the answers that Copier wrote."""
    path = root / ANSWERS
    text = path.read_text(encoding="utf-8") if path.exists() else ""
    answers = {key: value for key, _, value in ANSWER.findall(text)}
    if "owner" not in answers or "project_slug" not in answers:
        raise CreateError(
            f"{ANSWERS} names no owner and project_slug: run create in the folder "
            "that copier copy wrote, or name the repository: "
            "python3 .github/blueprint.py --repo OWNER/NAME create"
        )
    return f"{answers['owner']}/{answers['project_slug']}"


def problem(result: subprocess.CompletedProcess[str]) -> str:
    return (result.stderr or result.stdout).strip()


def output(run: Runner, arguments: Sequence[str], root: Path) -> str:
    """What a command printed; CreateError when it failed."""
    result = run(arguments, root)
    if result.returncode != 0:
        raise CreateError(f"{shlex.join(arguments)} failed: {problem(result)}")
    return result.stdout.strip()


def installed(
    run: Runner, arguments: Sequence[str], root: Path
) -> subprocess.CompletedProcess[str] | None:
    """What the command of a version printed, or None when its program doesn't run."""
    try:
        result = run(arguments, root)
    except FileNotFoundError:
        return None
    return result if result.returncode == 0 else None


def ask(run: Runner, root: Path, *arguments: str) -> Answer:
    """GitHub's answer to gh api, which only reads."""
    result = run(["gh", "api", *arguments], root)
    if result.returncode == 0:
        return Answer(True, result.stdout)
    message = problem(result)
    return Answer(False if NOTHING.search(message) else None, message)


def body(answer: Answer) -> dict[str, object]:
    """The object of JSON that GitHub answered, after the headers of --include."""
    text = answer.text.replace("\r\n", "\n")
    if text.startswith("HTTP/"):
        text = text.partition("\n\n")[2]
    try:
        data = json.loads(text)
    except ValueError:
        return {}
    return data if isinstance(data, dict) else {}


def origin(run: Runner, root: Path) -> str:
    """OWNER/NAME of the repository on GitHub that origin pushes to, or its address."""
    address = output(run, ["git", "remote", "get-url", "origin"], root)
    match = REMOTE.fullmatch(address)
    return match[1] if match else address


def local_plan(root: Path, repo: str, run: Runner) -> Plan:
    """The steps that make the folder at root a repository of Git with its first
    commit. It only asks git about the folder, and refuses one that pushes to
    another repository than repo."""
    plan = Plan()
    committed = False
    if (root / ".git").exists():
        remotes = output(run, ["git", "remote"], root).split()
        if remotes:
            pushes_to = origin(run, root) if "origin" in remotes else ""
            if pushes_to.lower() != repo.lower():
                raise CreateError(
                    f"this folder pushes to {', '.join(remotes)} already, not to "
                    f"{repo}, and create starts a new repository; for one that is "
                    f"on GitHub, push to it and run {shlex.join(AFTER[0])} and "
                    f"{shlex.join(AFTER[1])}"
                )
            plan.resume = True
        if output(run, ["git", "symbolic-ref", "--short", "HEAD"], root) != BRANCH:
            plan.steps.append(["git", "branch", "--move", BRANCH])
        committed = (
            run(["git", "rev-parse", "--verify", "--quiet", "HEAD"], root).returncode
            == 0
        )
    else:
        plan.steps.append(["git", "init", f"--initial-branch={BRANCH}"])
    if committed:
        if output(run, ["git", "status", "--porcelain"], root):
            raise CreateError(
                "this folder has changes that no commit holds, and create pushes "
                "the commits only: commit them, or stash them, and run create again"
            )
    else:
        plan.steps += [
            ["git", "add", "--all"],
            ["git", "commit", "--signoff", "--message", MESSAGE],
        ]
    return plan


def python_finding(python: tuple[int, ...]) -> Finding:
    shown = ".".join(str(part) for part in python[:2])
    if python[:2] >= MINIMUM_PYTHON:
        return Finding(True, f"Python {shown}")
    return Finding(
        False,
        f"Python {shown}: the tools of the new repository need Python 3.12 or later",
        "install Python 3.12 or later: https://www.python.org/downloads/",
    )


def git_finding(run: Runner, root: Path) -> Finding:
    result = installed(run, ["git", "--version"], root)
    if result is None:
        return Finding(
            False, "Git is not installed", "install Git: https://git-scm.com/downloads"
        )
    return Finding(True, result.stdout.strip().replace("git version", "Git", 1))


def identity_finding(run: Runner, root: Path) -> Finding:
    """Whether git has the name and the email address that the first commit needs,
    from its configuration or from the variables such as GIT_AUTHOR_EMAIL."""
    shown = ""
    for role in ("AUTHOR", "COMMITTER"):
        result = run(["git", "var", f"GIT_{role}_IDENT"], root)
        if result.returncode != 0:
            # Git ends with what it lacks, such as "fatal: unable to auto-detect
            # email address" or "fatal: empty ident name".
            # The name's message names the address, which may contain "email".
            if "ident name" in problem(result).rpartition("fatal:")[2]:
                return Finding(
                    False,
                    "Git has no name to commit with",
                    'git config --global user.name "Your Name"',
                )
            return Finding(
                False,
                "Git has no email address to commit with",
                'git config --global user.email "you@example.com"',
            )
        shown = shown or STAMP.sub("", result.stdout.strip())
    return Finding(True, f"Git commits as {shown}")


def gh_finding(version: str) -> Finding:
    match = VERSION.search(version)
    if match is None:
        return Finding(
            None, f"GitHub CLI of a version that it doesn't name: {version.strip()}"
        )
    shown = match[0]
    minimum = tuple(int(part) for part in MINIMUM_GH.split("."))
    if (int(match[1]), int(match[2])) >= minimum:
        return Finding(True, f"GitHub CLI {shown}")
    return Finding(
        False,
        f"GitHub CLI {shown}: create needs GitHub CLI {MINIMUM_GH} or later",
        "update GitHub CLI: https://github.com/cli/cli#installation",
    )


def has_scope(scopes: set[str], scope: str) -> bool:
    return scope in scopes or bool(scopes & WIDER_SCOPES.get(scope, set()))


def scopes_finding(login: str, scopes: set[str] | None, needed: list[str]) -> Finding:
    signed_in = f"gh is signed in to github.com as {login}"
    if scopes is None:
        return Finding(
            None,
            f"{signed_in}, with a token that names no scopes, such as a fine-grained "
            "one: it has to create the repository, push workflows and administer it",
        )
    missing = [scope for scope in needed if not has_scope(scopes, scope)]
    if missing:
        return Finding(
            False,
            f"{signed_in}, without the scopes {', '.join(missing)} that create needs",
            f"gh auth refresh --hostname github.com --scopes {','.join(missing)}",
        )
    return Finding(True, f"{signed_in}, with the scopes {', '.join(sorted(scopes))}")


def owner_finding(
    run: Runner, root: Path, owner: str, login: str, account: Answer, read_org: bool
) -> Finding:
    """Whether the user that gh is signed in as can create the repository of owner."""
    if account.found is False:
        return Finding(
            False,
            f"GitHub has no account or organization {owner}",
            "name the repository of an owner on GitHub: "
            "python3 .github/blueprint.py --repo OWNER/NAME create",
        )
    if account.found is None:
        return Finding(None, f"GitHub didn't say who {owner} is: {account.text}")
    if body(account).get("type") != "Organization":
        if owner.lower() == login.lower():
            return Finding(True, f"{owner} is the account that gh is signed in as")
        return Finding(
            False,
            f"{owner} is an account of its own, and gh is signed in as {login}",
            f"gh auth switch --user {owner}, or gh auth login as {owner}",
        )
    if not read_org:
        return Finding(
            None,
            f"{owner} is an organization, and whether {login} may create "
            f"repositories in it takes the scope {ORGANIZATION_SCOPE}",
        )
    membership = ask(run, root, f"user/memberships/orgs/{owner}")
    if membership.found is False:
        return Finding(
            False,
            f"{login} is no member of the organization {owner}",
            f"gh auth login as an owner of {owner}",
        )
    if membership.found is None:
        return Finding(
            None,
            f"GitHub didn't say whether {login} may create repositories in {owner}: "
            f"{membership.text}",
        )
    details = body(membership)
    if details.get("state") == "pending":
        return Finding(
            False,
            f"{login} is invited to the organization {owner} and hasn't accepted",
            f"accept the invitation: https://github.com/orgs/{owner}/invitation",
        )
    if details.get("role") == "admin":
        return Finding(True, f"{owner} is an organization that {login} owns")
    return Finding(
        None,
        f"{login} is a member of the organization {owner}, not an owner: gh repo "
        f"create works only where {owner} lets its members create repositories",
    )


def name_finding(run: Runner, root: Path, repo: str, resume: bool) -> Finding:
    """Whether the name is free, or whether GitHub has the repository that origin
    pushes to."""
    answer = ask(run, root, f"repos/{repo}")
    if answer.found is None:
        return Finding(None, f"GitHub didn't say whether {repo} is free: {answer.text}")
    if resume and answer.found:
        return Finding(
            True,
            f"{repo} is on GitHub, and origin pushes to it: create goes on from "
            "the first step that is left",
        )
    if resume:
        return Finding(
            False,
            f"origin pushes to {repo}, which GitHub doesn't have",
            "git remote remove origin",
        )
    if answer.found:
        owner = repo.split("/")[0]
        return Finding(
            False,
            f"{repo} is taken on GitHub",
            f"name another: python3 .github/blueprint.py --repo {owner}/NAME create",
        )
    return Finding(True, f"{repo} is free on GitHub")


def github_findings(run: Runner, root: Path, repo: str, resume: bool) -> list[Finding]:
    """The GitHub CLI, its sign-in and its scopes, the owner and the name."""
    version = installed(run, ["gh", "--version"], root)
    if version is None:
        return [
            Finding(
                False,
                "GitHub CLI is not installed",
                f"install GitHub CLI {MINIMUM_GH} or later: "
                "https://github.com/cli/cli#installation",
            )
        ]
    findings = [gh_finding(version.stdout)]
    user = ask(run, root, "--include", "user")
    if user.found is not True:
        said = user.text.splitlines()[0] if user.text else "gh said nothing"
        if user.text and not SIGNED_OUT.search(user.text):
            # Such as no network or an error of GitHub: signing in again won't help.
            return [
                *findings,
                Finding(None, f"GitHub didn't say who gh is signed in as: {said}"),
            ]
        return [
            *findings,
            Finding(
                False,
                f"gh is not signed in to github.com: {said}",
                "gh auth login --hostname github.com --scopes workflow",
            ),
        ]
    login = str(body(user).get("login", ""))
    header = SCOPE_HEADER.search(user.text.replace("\r\n", "\n").partition("\n\n")[0])
    scopes = (
        None
        if header is None
        else {scope.strip() for scope in header[1].split(",") if scope.strip()}
    )
    # Over SSH, git pushes the workflows with the key, which needs no scope workflow.
    protocol = run(
        ["gh", "config", "get", "git_protocol", "--host", "github.com"], root
    )
    ssh = protocol.returncode == 0 and protocol.stdout.strip() == "ssh"
    owner = repo.split("/")[0]
    account = ask(run, root, f"users/{owner}")
    organization = account.found is True and body(account).get("type") == "Organization"
    needed = [
        *(scope for scope in SCOPES if not (ssh and scope == WORKFLOW_SCOPE)),
        *([ORGANIZATION_SCOPE] if organization else []),
    ]
    read_org = scopes is None or has_scope(scopes, ORGANIZATION_SCOPE)
    return [
        *findings,
        scopes_finding(login, scopes, needed),
        owner_finding(run, root, owner, login, account, read_org),
        name_finding(run, root, repo, resume),
    ]


def preflight(
    root: Path, repo: str, run: Runner, python: tuple[int, ...]
) -> tuple[list[Finding], Plan]:
    """What create needs, checked before its first step, and the steps in the folder;
    git and gh only answer."""
    git = git_finding(run, root)
    plan = local_plan(root, repo, run) if git.ok else Plan()
    findings = [python_finding(python), git]
    if git.ok and plan.commits:
        findings.append(identity_finding(run, root))
    return [*findings, *github_findings(run, root, repo, plan.resume)], plan


def describe(repo: str, findings: list[Finding]) -> str:
    lines = [f"The preflight of {repo}:"]
    for finding in findings:
        lines.append(f"  {MARKS[finding.ok]} {finding.text}")
        if finding.fix:
            lines.append(f"      {finding.fix}")
    return "\n".join(lines)


def checked_repo(root: Path, repo: str | None) -> str:
    repo = repo or answered_repo(root)
    if not REPO.fullmatch(repo):
        raise CreateError(f"{repo} is no OWNER/NAME of a repository")
    return repo


def stops(findings: list[Finding]) -> int:
    """How many of the findings would stop create."""
    return sum(finding.ok is False for finding in findings)


def remote_steps(
    run: Runner, root: Path, repo: str, plan: Plan, description: str, private: bool
) -> list[list[str]]:
    """The steps on GitHub: gh repo create for a new repository, or the push that is
    left of one that create made and that stopped before GitHub had its branch."""
    if not plan.resume:
        return [
            [
                "gh",
                "repo",
                "create",
                repo,
                "--private" if private else "--public",
                "--description",
                description,
                "--source",
                ".",
                "--remote",
                "origin",
                "--push",
            ]
        ]
    branch = ask(run, root, f"repos/{repo}/branches/{BRANCH}")
    if branch.found is None:
        raise CreateError(
            f"GitHub didn't say whether {repo} has the branch {BRANCH}: {branch.text}"
        )
    # gh repo create --push signs git in with gh's own credential helper; the push
    # that is left does the same, so that it needs no gh auth setup-git.
    return (
        []
        if branch.found
        else [[*PUSH_AS_GH, "push", "--set-upstream", "origin", BRANCH]]
    )


def doctor(
    root: Path,
    *,
    repo: str | None = None,
    run: Runner | None = None,
    python: tuple[int, ...] | None = None,
) -> bool:
    """blueprint.py doctor: print the preflight of create; True when nothing in it
    would stop create."""
    repo = checked_repo(root, repo)
    findings, _ = preflight(
        root, repo, run or run_command, python or sys.version_info[:2]
    )
    print(describe(repo, findings))
    if stops(findings):
        print(
            "Fix what is marked with ✘, then run python3 .github/blueprint.py create."
        )
        return False
    print("Nothing stops python3 .github/blueprint.py create.")
    return True


def create(
    root: Path,
    description: str,
    *,
    repo: str | None = None,
    private: bool = False,
    dry_run: bool = False,
    run: Runner | None = None,
    python: tuple[int, ...] | None = None,
) -> str | None:
    """Make the repository on GitHub of the copy at root and return its OWNER/NAME;
    with dry_run, print the preflight and the commands and return None."""
    # Looked up at the call, so that a test can replace it.
    runner = run or run_command
    repo = checked_repo(root, repo)
    findings, plan = preflight(root, repo, runner, python or sys.version_info[:2])
    print(describe(repo, findings))
    count = stops(findings)
    if count:
        raise CreateError(
            f"the preflight found {count} {'problem' if count == 1 else 'problems'} "
            "that would stop create, which made nothing: fix what is marked with ✘, "
            "then run it again"
        )
    steps = [
        *plan.steps,
        *remote_steps(runner, root, repo, plan, description, private),
    ]
    visibility = "private" if private else "public"
    if dry_run:
        if plan.resume:
            print(f"create would go on with {repo} on GitHub, with:")
        else:
            print(f"create would make {repo} on GitHub, {visibility}, with:")
        for step in [*steps, *AFTER]:
            print(f"  {shlex.join(step)}")
        print("Run it without --dry-run to do it.")
        return None
    if plan.resume:
        print(f"Going on with {repo} on GitHub, from the first step that is left:")
    else:
        print(f"Creating {repo} on GitHub, {visibility}:")
    for step in steps:
        print(f"  {shlex.join(step)}", flush=True)
        output(runner, step, root)
    if plan.resume:
        print(f"https://github.com/{repo} has the branch {BRANCH}.")
    else:
        print(f"Created https://github.com/{repo}, with the branch {BRANCH}.")
    return repo
