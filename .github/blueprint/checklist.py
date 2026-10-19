"""The checklist of a repository: the steps outside the API, and which are done."""

from __future__ import annotations

import json
import re
import urllib.error
import urllib.request
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import urlencode

from blueprint.github import Api
from blueprint.settings import Drift, Settings, check

# The permissions of the release app, as its settings page names them and as the link
# that registers it does, with the access that the bots need: the settings bot,
# Renovate, the release bot, the blueprint bot and the others. GitHub's link has no
# name for Variables, so the checklist names it.
RELEASE_APP_PERMISSIONS = (
    ("Actions", "actions", "write"),
    ("Administration", "administration", "write"),
    ("Checks", "checks", "write"),
    ("Commit statuses", "statuses", "write"),
    ("Contents", "contents", "write"),
    ("Issues", "issues", "write"),
    ("Pages", "pages", "write"),
    ("Pull requests", "pull_requests", "write"),
    ("Variables", "", "write"),
    ("Workflows", "workflows", "write"),
    ("Dependabot alerts", "vulnerability_alerts", "read"),
    ("Environments", "environments", "read"),
    ("Secrets", "secrets", "read"),
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
    except (urllib.error.URLError, ValueError, OSError):
        return False


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


def release_app_registration(owner: str, organization: bool) -> str:
    """The address that registers the release app of the owner: on the account itself,
    with its name, its permissions and no webhook filled in."""
    page = (
        f"https://github.com/organizations/{owner}/settings/apps/new"
        if organization
        else "https://github.com/settings/apps/new"
    )
    query = {
        "name": release_app_name(owner),
        "description": "Opens the release and update pull requests of the repositories"
        " made from Repo Blueprint, so that their checks run, and runs their bots.",
        "url": f"https://github.com/{owner}",
        "public": "false",
        "webhook_active": "false",
        **{key: access for _, key, access in RELEASE_APP_PERMISSIONS if key},
    }
    return f"{page}?{urlencode(query)}"


def release_app(api: Api, repo: str, settings: Settings) -> tuple[Step, Step]:
    """The release app: registered by the owner and named in the settings, then
    installed on the repository. Every owner registers their own, since only the
    owner of an app has its private key."""
    owner = repo.split("/", 1)[0]
    name = str(settings.variables.get("RELEASE_APP", "")).strip()
    client = str(settings.variables.get("RELEASE_AUTOMATION_CLIENT_ID", "")).strip()
    installed = Step(
        "Release app installed on the repository",
        None,
        f"https://github.com/apps/{name}/installations/new"
        if name
        else "Install App on the settings page of the app, for this repository",
    )
    if client:
        named = f"Release app {name or client} named in .github/repository.toml"
        return Step(named, True), installed
    account = api.get(f"users/{owner}")
    organization = isinstance(account, dict) and account.get("type") == "Organization"
    how = [
        "Register it, with its name and permissions filled in and without a webhook:",
        release_app_registration(owner, organization),
        f"It needs read and write access to {listed('write')}, and read access to"
        f" {listed('read')}; set Variables by hand, which the link can't fill in.",
        "Create the app and generate a private key on its settings page. Then name it"
        " in the answers of the blueprint, with the name in its address and the Client"
        " ID and App ID of that page, and commit the update:",
        "copier update --defaults --vcs-ref=:current: --data release_app=NAME"
        " --data release_app_client_id=CLIENT_ID --data release_app_id=APP_ID",
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
# The issue that Renovate keeps open once it runs, by the rules of the blueprint.
DASHBOARD = "Dependency dashboard"


def renovate_runner(root: Path) -> str:
    """Who runs Renovate for the repository in root."""
    answers = root / ".copier-answers.yml"
    text = answers.read_text(encoding="utf-8") if answers.exists() else ""
    match = re.search(r"^renovate_runner: *['\"]?(\w+)", text, re.MULTILINE)
    return match.group(1) if match and match.group(1) in RENOVATE_RUNNERS else "hub"


def renovate(api: Api, repo: str, settings: Settings, root: Path) -> Step:
    """Renovate, which runs once its issue Dependency dashboard is open, and how it
    comes to run."""
    issues = api.get_all(f"repos/{repo}/issues?labels=dependencies&state=open")
    running = any(
        isinstance(issue, dict)
        and issue.get("title") == DASHBOARD
        and "pull_request" not in issue
        for issue in issues
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
            " .github/renovate.json5 and opens the dashboard on its first run:",
            f"{MEND_APP}/installations/new",
            *ignored_author(api, settings),
        ]
    else:
        how = [
            "The Renovate workflow of the blueprint runs it every two hours for each"
            " repository of its owner on which the release app is installed; its next"
            " run after the installation opens the dashboard."
        ]
    return Step(
        f"Renovate runs: its issue '{DASHBOARD}' is open", running, "\n".join(how)
    )


def ignored_author(api: Api, settings: Settings) -> list[str]:
    """The rule that lets the Mend Renovate app take the commits of the release app,
    with which the branch bot and the fix bot update its branches, for none of
    someone else, which would stop it from rebasing them."""
    name = str(settings.variables.get("RELEASE_APP", "")).strip()
    user = api.get(f"users/{name}%5Bbot%5D") if name else None
    number = user.get("id") if isinstance(user, dict) else None
    known = number is not None
    address = (
        f"{number}+{name}[bot]" if known else "ID+NAME[bot]"
    ) + "@users.noreply.github.com"
    return [
        "The branch bot and the fix bot commit to its branches as the release app;"
        " so that Renovate goes on rebasing them, add to .github/renovate.json5:",
        f'gitIgnoredAuthors: ["{address}"]',
        *(
            []
            if known
            else [
                "with the name of the release app for NAME, and for ID the number of"
                " its bot user: gh api users/NAME%5Bbot%5D --jq .id"
            ]
        ),
    ]


def listed(access: str) -> str:
    """The permissions of the release app with that access, as a sentence lists them."""
    names = [name for name, _, need in RELEASE_APP_PERMISSIONS if need == access]
    return ", ".join(names[:-1]) + " and " + names[-1]


def checklist(
    api: Api,
    repo: str,
    settings: Settings,
    root: Path,
    reuse: Callable[[str], bool] | None = None,
) -> list[Step]:
    owner, name = repo.split("/", 1)
    data = api.graphql(
        f'{{ repository(owner: "{owner}", name: "{name}") {{ usesCustomOpenGraphImage }} }}'
    )
    readme = (
        (root / "README.md").read_text(encoding="utf-8")
        if (root / "README.md").exists()
        else ""
    )
    drift = check(api, repo, settings)
    # What a person sets, which the API can't: the secrets and the files that the
    # community profile counts, each a step of its own.
    secrets_missing = [item for item in drift if item.setting.startswith("secret ")]
    community = [item for item in drift if item.setting == "community profile"]
    target = settings.variables.get("PUBLISH_TO", "none")
    # The app first: the settings and the secrets that follow name it.
    registered, installed = release_app(api, repo, settings)
    steps = [
        registered,
        Step(
            "Settings as code applied",
            all(item.manual for item in drift),
            "python3 blueprint.py settings apply",
        ),
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
        renovate(api, repo, settings, root),
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
        Step(
            "Social preview uploaded (bash scripts/social-preview.sh renders it)",
            bool(data["repository"]["usesCustomOpenGraphImage"]),
            f"https://github.com/{repo}/settings",
        ),
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
    }
    if target == "hacs":
        steps.append(listing(settings, drift))
    if target in delivery:
        text, how = delivery[target]
        steps.append(Step(text, None, how))
    return steps


def listing(settings: Settings, drift: list[Drift]) -> Step:
    """HACS lists an integration by the description and the topics of its repository,
    and its check fails without topics: the settings as code name both, and settings
    apply sets them on GitHub."""
    named = all(settings.repository.get(key) for key in ("description", "topics"))
    applied = not any(
        item.setting in ("repository.description", "repository.topics")
        for item in drift
    )
    how = "python3 blueprint.py settings apply"
    if not named:
        how = (
            'copier update --defaults --vcs-ref=:current: --data "topics=home-assistant,'
            ' hacs", which names them in .github/repository.toml, then: ' + how
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
