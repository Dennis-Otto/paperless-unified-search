"""The checklist of a repository: the steps outside the API, and which are done."""

from __future__ import annotations

import json
import urllib.error
import urllib.request
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from blueprint.github import Api
from blueprint.settings import Settings, check

RELEASE_APP = "dennis-otto-release-automation"
REUSE_API = "https://api.reuse.software/status/github.com/"


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
    secrets_missing = [item for item in drift if item.manual]
    target = settings.variables.get("PUBLISH_TO", "none")
    steps = [
        Step(
            "Settings as code applied",
            len(drift) == len(secrets_missing),
            "python3 blueprint.py settings apply",
        ),
        Step(
            "Secrets of the environments set",
            not secrets_missing,
            "\n".join(item.manual for item in secrets_missing),
        ),
        Step(
            "Release app installed on the repository",
            None,
            f"https://github.com/apps/{RELEASE_APP}/installations/new",
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
        Step(
            "Social preview uploaded (bash scripts/social-preview.sh renders it)",
            bool(data["repository"]["usesCustomOpenGraphImage"]),
            f"https://github.com/{repo}/settings",
        ),
        Step(
            "OpenSSF Best Practices badge in the README",
            "bestpractices.dev/projects/" in readme,
            "https://www.bestpractices.dev/en/projects/new",
        ),
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
    if target in delivery:
        text, how = delivery[target]
        steps.append(Step(text, None, how))
    return steps


def describe_steps(steps: list[Step]) -> str:
    marks = {True: "✔", False: "✘", None: "·"}
    lines = []
    for step in steps:
        lines.append(f"  {marks[step.done]} {step.text}")
        if step.done is not True and step.how:
            lines += [f"      {line}" for line in step.how.splitlines()]
    return "\n".join(lines)
