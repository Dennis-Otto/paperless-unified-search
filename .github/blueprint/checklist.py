"""The checklist of a repository: the steps outside the API, and which are done."""

from __future__ import annotations

import contextlib
import json
import re
import shlex
import urllib.error
import urllib.request
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from blueprint import workflows
from blueprint.bootstrap import PUSH_AS_GH
from blueprint.github import Api, GitHubError
from blueprint.settings import (
    BRANCH,
    Drift,
    Plan,
    Settings,
    check,
    fitted,
    gated,
    read_plan,
)
from blueprint.social_preview import (
    DEFAULT_PANEL,
    PICTURE,
    PRIVATE,
    SOURCE,
    Unreadable,
    preview,
    settings_page,
    shows_the_project,
)

# The permissions of the release app, as its settings page names them and as its
# manifest does, with the access that the bots need: the settings bot, Renovate, the
# release bot, the blueprint bot and the others.
RELEASE_APP_PERMISSIONS = (
    ("Actions", "actions", "write"),
    ("Administration", "administration", "write"),
    ("Checks", "checks", "write"),
    ("Commit statuses", "statuses", "write"),
    ("Contents", "contents", "write"),
    # The OSV-Scanner workflow closes the alerts of the accepted advisories with it.
    ("Dependabot alerts", "vulnerability_alerts", "write"),
    ("Issues", "issues", "write"),
    ("Pages", "pages", "write"),
    ("Pull requests", "pull_requests", "write"),
    ("Variables", "actions_variables", "write"),
    ("Workflows", "workflows", "write"),
    ("Environments", "environments", "read"),
    ("Secrets", "secrets", "read"),
)
RELEASE_APP_DESCRIPTION = (
    "Opens the release and update pull requests of the repositories made from Repo"
    " Blueprint, so that their checks run, and runs their bots."
)
REUSE_API = "https://api.reuse.software/status/github.com/"
BEST_PRACTICES = "https://www.bestpractices.dev"
# A badge of OpenSSF Best Practices in a README: of the metal series, "badge", or of
# the OSPS Baseline, "baseline", with the number of the project.
BADGE = re.compile(r"bestpractices\.dev/projects/(\d+)/(badge|baseline)")
# The button of each form of bestpractices.dev that reads .bestpractices.json anew.
SAVE = "Save (and continue) 🤖"


@dataclass(frozen=True)
class Step:
    """A step of the set-up; done is None when no API can tell."""

    text: str
    done: bool | None
    how: str = ""


def reuse_compliant(
    repo: str, opener: Callable[[str], Any] = urllib.request.urlopen
) -> bool:
    try:
        with opener(REUSE_API + repo) as response:
            return bool(json.load(response).get("status") == "compliant")
    except (ValueError, OSError) as error:
        if isinstance(error, urllib.error.HTTPError):
            # The error is the answer too, and holds its body until it is closed.
            error.close()
        return False


def social_preview(root: Path) -> Step:
    """The social preview of a new repository shows a drawing of its kind of project,
    since the template knows no more of it: the project replaces it with itself."""
    return Step(
        "Social preview shows the project",
        shows_the_project(root),
        f"{SOURCE} still shows the blueprint's default panel, the same in"
        " every project of its kind. Replace it with the project itself: a screenshot"
        " of what it shows, such as one that scripts/screenshots.sh takes, or a"
        f" faithful drawing of what it produces, without {DEFAULT_PANEL}. Then render"
        " the picture with bash scripts/social-preview.sh and upload it.",
    )


def social_preview_uploaded(
    api: Api,
    repo: str,
    root: Path,
    opener: Callable[..., Any] | None = None,
) -> Step:
    """GitHub shows the uploaded picture, which only a person can upload: once the
    repository holds one, the step compares the upload with it, so that a picture that
    changed after its upload shows as well. Without one, an upload is enough. A
    private repository shows no social preview: its step waits until it is public."""
    page = settings_page(repo)
    upload = f"upload {PICTURE} under Settings → General → Social preview: {page}"
    try:
        found = preview(api, repo, root, opener)
    except Unreadable as error:
        return Step(
            "Social preview uploaded and current",
            None,
            f"Couldn't read the uploaded picture ({error}). Compare it with {PICTURE},"
            f" or {upload}",
        )
    title = "Social preview uploaded and current"
    if not found.picture:
        title = "Social preview uploaded (bash scripts/social-preview.sh renders it)"
    if not found.public:
        return Step(title, None, f"{PRIVATE}: once it is public, {upload}")
    if not found.picture:
        return Step(title, found.uploaded, page)
    how = ""
    if not found.fits:
        how = (
            f"{PICTURE} is larger than the 1 MB that GitHub takes for an upload:"
            " render a smaller one, with fewer colors or a simpler screenshot, with"
            f" bash scripts/social-preview.sh, then {upload}"
        )
    elif not found.uploaded:
        how = f"GitHub shows a card of its own: {upload}"
    elif not found.current:
        how = f"The uploaded picture is not {PICTURE} of this checkout: {upload}"
    return Step(title, found.current, how)


def best_practices(readme: str) -> list[Step]:
    """The badges of OpenSSF Best Practices and of the OSPS Baseline in the README.
    The form of each level proposes the answers of .bestpractices.json, and a project
    that shows one badge has its number for the other."""
    badges = {series: number for number, series in BADGE.findall(readme)}
    number = badges.get("badge") or badges.get("baseline")
    take = (
        f"On each level, press '{SAVE}' to take in the answers that"
        " .bestpractices.json proposes, and answer the rest."
    )
    steps: list[Step] = []
    for series, name, level in (
        ("badge", "Best Practices", "passing"),
        ("baseline", "Baseline", "baseline-1"),
    ):
        if number:
            how = (
                f"{BEST_PRACTICES}/en/projects/{number}/{level}/edit\n{take}\n"
                f"Then add its badge to the README: [![OpenSSF {name}]"
                f"({BEST_PRACTICES}/projects/{number}/{series})]"
                f"({BEST_PRACTICES}/projects/{number})"
            )
        else:
            how = (
                "Add the project with the URL of the repository: "
                f"{BEST_PRACTICES}/en/projects/new\n{take}\n"
                "Then add its badges to the README, as the project page shows them."
            )
        steps.append(Step(f"OpenSSF {name} badge in the README", series in badges, how))
    return steps


APP_NAME_LIMIT = 34
"""GitHub allows at most 34 characters in the name of an app."""


def release_app_name(owner: str) -> str:
    """The suggested name of the owner's release app, within GitHub's limit: the
    owner and "-release-automation", or "-release" and as much of the owner as
    fits when the owner's name is long."""
    name = f"{owner.lower()}-release-automation"
    if len(name) <= APP_NAME_LIMIT:
        return name
    suffix = "-release"
    return owner.lower()[: APP_NAME_LIMIT - len(suffix)].rstrip("-") + suffix


def installed_on(api: Api, repo: str, app_id: str) -> bool | None:
    """Whether the app shows as installed on the repository: GitHub opens a check suite
    of every installed app with Checks for each push, so one on the head of main tells.
    No API tells the opposite to the owner: an app may have come after that push, so
    None and never False."""
    if not app_id:
        return None
    try:
        suites = api.get(f"repos/{repo}/commits/{BRANCH}/check-suites?app_id={app_id}")
    except GitHubError:
        return None
    return True if isinstance(suites, dict) and suites.get("total_count") else None


def release_app(api: Api, repo: str, settings: Settings) -> tuple[Step, Step]:
    """The release app: registered by the owner and named in the settings, then
    installed on the repository. Every owner registers their own, since only the
    owner of an app has its private key."""
    name = str(settings.variables.get("RELEASE_APP", "")).strip()
    client = str(settings.variables.get("RELEASE_AUTOMATION_CLIENT_ID", "")).strip()
    app_id = str(settings.variables.get("RELEASE_AUTOMATION_APP_ID", "")).strip()
    installed = Step(
        "Release app installed on the repository",
        installed_on(api, repo, app_id),
        f"https://github.com/apps/{name}/installations/new"
        if name
        else "Install App on the settings page of the app, for this repository",
    )
    if client:
        named = f"Release app {name or client} named in .github/repository.toml"
        return Step(named, True), installed
    how = [
        "Register it with one command: you confirm one page on GitHub, and it sets the"
        " private key and opens the pull request that names the app. Turn"
        " commit.gpgsign on first: main takes signed commits only, also this one:",
        "python3 .github/blueprint.py app register",
        f"The app gets read and write access to {listed('write')}, read access to"
        f" {listed('read')}, and no webhook. For an app that exists, such as for a"
        " further repository: python3 .github/blueprint.py app connect --key-file FILE",
    ]
    registered = Step(
        "Release app registered and named in .github/repository.toml",
        False,
        "\n".join(how),
    )
    return registered, installed


# Who runs Renovate, by the answer renovate_runner of the blueprint in
# .copier-answers.yml: the Renovate workflow of the blueprint for the repositories of
# its owner, which was the only one before the answer; the Renovate workflow of the
# repository itself; or the Mend Renovate app.
RENOVATE_RUNNERS = ("hub", "self", "mend")
MEND_APP = "https://github.com/apps/renovate"
# The branches of Renovate, whose pull requests show that it runs: by the rules of
# the blueprint, it keeps no issue open.
BRANCHES = "renovate/"
# The latest pull requests, newest first, among which one of Renovate shows that it
# runs; the checklist reads no older ones.
LATEST = 100


def answer(root: Path, name: str) -> str:
    """An answer of Copier for the repository in root, empty without one."""
    answers = root / ".copier-answers.yml"
    text = answers.read_text(encoding="utf-8") if answers.exists() else ""
    match = re.search(rf"^{name}: *['\"]?([\w-]+)", text, re.MULTILINE)
    return match.group(1) if match else ""


def renovate_runner(root: Path) -> str:
    """Who runs Renovate for the repository in root."""
    runner = answer(root, "renovate_runner")
    return runner if runner in RENOVATE_RUNNERS else "hub"


# Copier of the quick start, which puts no copier on the PATH. A manager in
# .github/renovate.json5 moves the version with the pages.
PINNED = "copier==9.19.1 copier update"
ANSWER_BRANCH = "change-the-answers"
ANSWER_MESSAGE = "chore: change the answers of the blueprint"
SETTINGS_APPLY = "python3 .github/blueprint.py settings apply"


def answer_steps(
    copier: Sequence[str],
    data: Sequence[str],
    branch: str = ANSWER_BRANCH,
    message: str = ANSWER_MESSAGE,
) -> list[list[str]]:
    """The steps that change answers of Copier, on a branch of their own: Copier writes
    .github/repository.toml from them, and main takes pull requests only. The branch
    starts at the main of GitHub, so that the pull request holds this one commit, also
    when a merged branch of the same name is left, or another branch is checked out."""
    return [
        ["git", "fetch", "origin", "main"],
        ["git", "switch", "--force-create", branch, "FETCH_HEAD"],
        [*copier, "--defaults", "--vcs-ref=:current:", *data],
        ["git", "add", "--all"],
        ["git", "commit", "--signoff", "--message", message],
        [*PUSH_AS_GH, "push", "--set-upstream", "origin", "HEAD"],
        ["gh", "pr", "create", "--fill"],
    ]


def answer_commands(data: str) -> list[str]:
    """The steps as a person runs them, who has the copier of the quick start."""
    copier = shlex.split(f"uvx --from {PINNED}")
    return [shlex.join(step) for step in answer_steps(copier, ["--data", data])]


def change_answer(data: str) -> list[str]:
    """What to run for another answer, and why it takes these steps: a commit that lacks
    a signature fails nothing until the merge, which the rules of main then block."""
    return [
        "main takes only pull requests of signed commits, so run this on a branch, with"
        " commit.gpgsign on or --gpg-sign added to the commit:",
        *answer_commands(data),
        f"With pipx in place of uv, the Copier line starts pipx run --spec {PINNED};"
        " a Copier of your own, copier update.",
    ]


def unanswered_app(app: str, root: Path) -> list[str]:
    """What to do when the settings as code name a release app that the answers of
    Copier don't: the rule that lets the Mend Renovate app take its commits for its own
    follows the answer alone."""
    if not app or app == answer(root, "release_app"):
        return []
    return [
        f"The settings as code name the release app {app} and the answers of Copier"
        " don't, whose name the rule gitIgnoredAuthors comes from; so that Renovate"
        " goes on rebasing the branches that its bots commit to, change the answer."
        " Then merge the pull request.",
        *change_answer(f"release_app={app}"),
    ]


def renovate(api: Api, repo: str, root: Path, app: str = "") -> Step:
    """Renovate, which runs once it has opened a pull request, and how it comes to
    run; app is the release app by the settings as code."""
    pulls = api.get(f"repos/{repo}/pulls?state=all&per_page={LATEST}")
    running = isinstance(pulls, list) and any(
        isinstance(pull, dict)
        and isinstance(head := pull.get("head"), dict)
        and str(head.get("ref", "")).startswith(BRANCHES)
        for pull in pulls
    )
    runner = renovate_runner(root)
    if runner == "self":
        how = [
            "The Renovate workflow of this repository runs it every two hours as the"
            " release app, once the app is installed; start its first run:",
            f"https://github.com/{repo}/actions/workflows/renovate.yml",
        ]
    elif runner == "mend":
        how = [
            "Install the Mend Renovate app on this repository; it reads"
            " .github/renovate.json5 and opens its pull requests from its first run:",
            f"{MEND_APP}/installations/new",
            *unanswered_app(app, root),
        ]
    else:
        how = [
            "The Renovate workflow of the blueprint runs it every two hours for each"
            " repository of its owner on which the release app is installed; its next"
            " run after the installation opens its pull requests."
        ]
    return Step("Renovate runs: it has opened a pull request", running, "\n".join(how))


def listed(access: str) -> str:
    """The permissions of the release app with that access, as a sentence lists them."""
    names = [name for name, _, need in RELEASE_APP_PERMISSIONS if need == access]
    return ", ".join(names[:-1]) + " and " + names[-1]


# The parts that GitHub offers a private repository only on a paid plan, by the
# prefix of their differences: the rulesets and the website.
PAID_PARTS = ("ruleset", "pages")


def applied_step(drift: list[Drift], private: bool = False) -> Step:
    """Whether the settings as code are applied; neutral when only a switch that
    depends on the plan is off, which GitHub may keep off on any write, or when a
    private repository lacks only what GitHub may not offer it on its plan."""
    open_items = [item for item in drift if not item.manual]
    only_plan = all(
        (
            item.setting.removeprefix("security.") in gated(private)
            and item.want is True
            and item.have is False
        )
        or (private and item.setting.startswith(PAID_PARTS))
        for item in open_items
    )
    if open_items and only_plan:
        return Step(
            "Settings as code applied",
            None,
            f"{SETTINGS_APPLY}; it tells whether GitHub keeps "
            + ", ".join(item.setting for item in open_items)
            + " off for the plan of the repository",
        )
    return Step(
        "Settings as code applied",
        not open_items,
        SETTINGS_APPLY,
    )


def code_security_step(repo: str, plan: Plan) -> Step:
    """On a private repository: whether it has GitHub Code Security, without which the
    settings switch the workflows to what they do without code scanning (decision
    0082); neutral without it, since the plan of the owner may not offer it."""
    return Step(
        "Private repository: GitHub Code Security on, for code scanning and the"
        " dependency review",
        True if plan.code_security else None,
        "Without it, the settings set CODE_SCANNING and DEPENDENCY_REVIEW to off:"
        " CodeQL, zizmor, OSV-Scanner and the Scorecard keep what they find in their"
        " checks and artifacts, and OSV-Scanner checks the lock files of every pull"
        " request. Turn it on where the plan of the owner offers it, or make the"
        f" repository public: https://github.com/{repo}/settings/security_analysis",
    )


# The OSV-Scanner workflow closes the Dependabot alerts that an osv-scanner.toml
# accepts as the settings app, or as the release app without one; no API tells the
# permissions of an app to its repository (decision 0086).
DEPENDABOT_ALERTS = Step(
    "The settings app, or the release app without one, may write the Dependabot alerts,"
    " so that the OSV-Scanner workflow closes those that osv-scanner.toml accepts",
    None,
    "On the settings page of the app, under Permissions & events -> Repository"
    " permissions, set Dependabot alerts to Read and write and save; then accept the new"
    " permission on its installation: Settings -> Applications -> Installed GitHub Apps,"
    " Configure, Review request.\n"
    "https://github.com/Dennis-Otto/repo-blueprint/blob/main/docs/own-account.md"
    "#8-the-settings-app-optional",
)


def workflows_step(api: Api, repo: str, root: Path) -> list[Step]:
    """Whether GitHub knows each workflow, which it starts only then, and whether the
    comments that registered some are out again (#652). Both are of main as GitHub
    has it, so that a pull request merged on GitHub counts before a pull; the
    checkout stands in only when GitHub can't answer for main."""
    texts = workflows.local_workflows(root)
    if not texts:
        return []
    with contextlib.suppress(GitHubError):
        texts = workflows.read_main(api, repo).texts
    missing = workflows.unknown(texts, workflows.registered(api, repo))
    marked = workflows.marked(texts)
    how: list[str] = []
    if missing:
        how.append(
            f"GitHub hasn't registered {workflows.names(missing)}: it starts none of"
            " them, not even on a schedule, and gh workflow run answers 404"
            f" ({workflows.ISSUE}). {workflows.COMMAND} opens the pull request that"
            " registers them, or names the one that is open; once it has merged, run"
            " it again, and it opens the one that takes its comments out."
        )
    if marked:
        how.append(
            f"The comments that registered {workflows.names(marked)} are still in"
            f" them: {workflows.COMMAND} opens the pull request that takes them out,"
            " or names the one that is open."
        )
    return [
        Step(
            f"GitHub knows every workflow of {workflows.FOLDER}/, so that it starts"
            " each",
            not how,
            "\n".join(how),
        )
    ]


def checklist(
    api: Api,
    repo: str,
    settings: Settings,
    root: Path,
    reuse: Callable[[str], bool] | None = None,
    opener: Callable[..., Any] | None = None,
) -> list[Step]:
    name = repo.split("/", 1)[1]
    readme = (
        (root / "README.md").read_text(encoding="utf-8")
        if (root / "README.md").exists()
        else ""
    )
    plan = read_plan(api, repo)
    settings = fitted(api, repo, settings, plan)
    drift = check(api, repo, settings, plan.private)
    # What a person sets, which the API can't: the secrets and the files that the
    # community profile counts, each a step of its own.
    secrets_missing = [item for item in drift if item.setting.startswith("secret ")]
    community = [item for item in drift if item.setting == "community profile"]
    target = settings.variables.get("PUBLISH_TO", "none")
    # The app first: the settings and the secrets that follow name it.
    registered, installed = release_app(api, repo, settings)
    steps = [
        registered,
        applied_step(drift, plan.private),
        *([code_security_step(repo, plan)] if plan.private else []),
        *workflows_step(api, repo, root),
        Step(
            "Secrets of the environments set",
            not secrets_missing,
            "\n".join(item.manual for item in secrets_missing),
        ),
        Step(
            "Community profile complete (100 %)",
            not community,
            "\n".join(item.manual for item in community),
        ),
        installed,
        DEPENDABOT_ALERTS,
        renovate(
            api, repo, root, str(settings.variables.get("RELEASE_APP", "")).strip()
        ),
        Step(
            "Settings → General → Issues: 'Auto-close issues with merged linked pull requests' off "
            "(the issue assistant closes them with the release)",
            None,
            f"https://github.com/{repo}/settings",
        ),
    ]
    if (root / ".github/FUNDING.yml").exists():
        steps.append(
            Step(
                "Settings → General → Features: Sponsorships on",
                None,
                f"https://github.com/{repo}/settings",
            )
        )
    steps += [
        social_preview(root),
        social_preview_uploaded(api, repo, root, opener),
        *best_practices(readme),
        Step(
            "Registered with the REUSE API, for its badge",
            (reuse or reuse_compliant)(repo),
            "https://api.reuse.software/register",
        ),
    ]
    delivery = {
        "pypi": (
            "Trusted publisher on PyPI: workflow release.yml, environment pypi",
            "https://pypi.org/manage/account/publishing/",
        ),
        "npm": (
            "Trusted publisher on npm: workflow release.yml, environment npm",
            "https://www.npmjs.com/settings/~/packages",
        ),
        "ghcr": (
            "After the first release: the package of the image is public",
            f"https://github.com/{repo}/pkgs/container/{name}",
        ),
        "hacs": (
            "Submitted to the HACS default repositories",
            "https://github.com/hacs/default",
        ),
        "nextcloud-appstore": (
            "App certificate requested and the app registered in the App Store",
            "Request the certificate: "
            "https://nextcloudappstore.readthedocs.io/en/latest/developer.html\n"
            "then run Actions -> Register the app with the id of the app.",
        ),
        "cloudflare-workers": (
            "Cloudflare: the Worker created with a first npx wrangler deploy, and the"
            " repository connected with Workers Builds (build command"
            " `npm ci --ignore-scripts`, SKIP_DEPENDENCY_INSTALL=1, deploy"
            " command npm run deploy)",
            f"https://github.com/{repo}/blob/main/docs/releases.md"
            "#set-up-cloudflare-once",
        ),
    }
    if target == "hacs":
        steps.append(listing(settings, drift))
    if target in delivery:
        text, how = delivery[target]
        steps.append(Step(text, None, how))
    if target == "chrome-web-store":
        steps += chrome_web_store(api, repo)
    return steps


# The variables of the workflow Chrome Web Store, publish-store.yml, which only a
# person can find (decision 0085): the IDs of the item and of its publisher in the
# Developer Dashboard, and the provider of the workload identity pool and the service
# account of the sign-in without a stored secret.
CHROME_WEB_STORE_ITEM = ("CWS_EXTENSION_ID", "CWS_PUBLISHER_ID")
CHROME_WEB_STORE_SIGN_IN = ("CWS_WORKLOAD_IDENTITY_PROVIDER", "CWS_SERVICE_ACCOUNT")


def chrome_web_store(api: Api, repo: str) -> list[Step]:
    """The Chrome Web Store creates an item only from a first upload by hand, and
    Google trusts the OIDC token of GitHub only after the one-time set-up of
    docs/releases.md; each is done once its variables are set."""

    def named(variables: tuple[str, ...]) -> bool:
        return all(
            api.get(f"repos/{repo}/actions/variables/{name}") for name in variables
        )

    docs = f"https://github.com/{repo}/blob/main/docs/releases.md"
    return [
        Step(
            "Chrome Web Store: the first version uploaded by hand in the Developer"
            " Dashboard, its listing and privacy practices filled in, and the variables"
            " CWS_EXTENSION_ID and CWS_PUBLISHER_ID set",
            named(CHROME_WEB_STORE_ITEM),
            f"{docs}#the-first-listing",
        ),
        Step(
            "Chrome Web Store: the sign-in without a stored secret set up, with the"
            " variables CWS_WORKLOAD_IDENTITY_PROVIDER and CWS_SERVICE_ACCOUNT",
            named(CHROME_WEB_STORE_SIGN_IN),
            f"{docs}#sign-in-without-a-stored-secret",
        ),
    ]


def listing(settings: Settings, drift: list[Drift]) -> Step:
    """HACS lists an integration by the description and the topics of its repository,
    and its check fails without topics: the settings as code name both, and settings
    apply sets them on GitHub."""
    named = all(settings.repository.get(key) for key in ("description", "topics"))
    applied = not any(
        item.setting in ("repository.description", "repository.topics")
        for item in drift
    )
    how = SETTINGS_APPLY
    if not named:
        how = "\n".join(
            [
                "The answer topics names them in .github/repository.toml. Merge the"
                f" pull request, then run {how}.",
                *change_answer("topics=home-assistant, hacs"),
            ]
        )
    return Step("Description and topics set (HACS lists them)", named and applied, how)


def describe_steps(steps: list[Step]) -> str:
    marks = {True: "✔", False: "✘", None: "·"}
    lines = []
    for step in steps:
        lines.append(f"  {marks[step.done]} {step.text}")
        if step.done is not True and step.how:
            lines += [f"      {line}" for line in step.how.splitlines()]
    return "\n".join(lines)
