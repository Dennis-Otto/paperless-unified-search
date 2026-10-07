#!/usr/bin/env python3
"""The settings of a repository as code, the checklist of what only a person can do,
and the changelog of its releases.

    python3 blueprint.py settings check     compare .github/repository.toml with GitHub
    python3 blueprint.py settings apply     make GitHub match .github/repository.toml
    python3 blueprint.py checklist          the steps outside the API, and which are done
    python3 blueprint.py changelog ...      the changelog of a release (the release bot)
    python3 blueprint.py unreleased ...     an entry under Unreleased (the other bots)

Run it in the root of a repository made from the blueprint, with the GitHub CLI `gh`
signed in as an administrator. It needs Python 3.12 and nothing else. It never reads,
prints or sets the value of a secret: it only says which secrets are missing and how
to set them. https://github.com/Dennis-Otto/repo-blueprint
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import tomllib
import urllib.error
import urllib.parse
import urllib.request
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from pathlib import Path
from typing import Any

CONFIG = Path(".github/repository.toml")
# The GitHub Actions app, which reports the checks of the workflows.
ACTIONS_APP = 15368
RELEASE_APP = "dennis-otto-release-automation"
REUSE_API = "https://api.reuse.software/status/github.com/"

Json = Any


class GitHubError(Exception):
    """A request to GitHub failed."""

    def __init__(self, status: int, message: str) -> None:
        super().__init__(f"GitHub answered {status}: {message}")
        self.status = status


@dataclass(frozen=True)
class Response:
    status: int
    data: Json


Runner = Callable[[Sequence[str], str | None], subprocess.CompletedProcess[str]]


def run_gh(
    arguments: Sequence[str], body: str | None
) -> subprocess.CompletedProcess[str]:
    """Run the GitHub CLI; the tests replace this with a simulated GitHub."""
    return subprocess.run(  # pragma: no cover - the tests simulate gh
        ["gh", *arguments], input=body, capture_output=True, text=True, check=False
    )


class Api:
    """The REST and GraphQL API of GitHub, through the GitHub CLI."""

    def __init__(self, runner: Runner = run_gh) -> None:
        self.runner = runner

    def request(self, method: str, path: str, body: Json = None) -> Response:
        arguments = ["api", "--include", "--method", method, path]
        payload = None
        if body is not None:
            arguments += ["--input", "-"]
            payload = json.dumps(body)
        result = self.runner(arguments, payload)
        head, _, text = result.stdout.replace("\r\n", "\n").partition("\n\n")
        status_line = head.split("\n", 1)[0]
        try:
            status = int(status_line.split()[1])
        except (IndexError, ValueError):
            raise GitHubError(0, (result.stderr or result.stdout).strip()) from None
        try:
            data = json.loads(text) if text.strip() else None
        except (ValueError, RecursionError):
            data = text
        if status >= 400 and status != 404:
            message = data.get("message", "") if isinstance(data, dict) else text
            raise GitHubError(status, message)
        return Response(status, data)

    def get(self, path: str) -> Json:
        """The data at path, or None when GitHub doesn't know it."""
        response = self.request("GET", path)
        return None if response.status == 404 else response.data

    def exists(self, path: str) -> bool:
        return self.request("GET", path).status != 404

    def put(self, path: str, body: Json = None) -> None:
        self.request("PUT", path, {} if body is None else body)

    def patch(self, path: str, body: Json) -> None:
        self.request("PATCH", path, body)

    def post(self, path: str, body: Json) -> None:
        self.request("POST", path, body)

    def delete(self, path: str) -> None:
        self.request("DELETE", path)

    def graphql(self, query: str) -> Json:
        result = self.runner(["api", "graphql", "-f", f"query={query}"], None)
        if result.returncode != 0:
            raise GitHubError(0, (result.stderr or result.stdout).strip())
        return json.loads(result.stdout)["data"]


@dataclass(frozen=True)
class Drift:
    """A setting whose value on GitHub differs from the one in the configuration."""

    setting: str
    want: Json
    have: Json
    # How a person sets it, when the API can't.
    manual: str = ""


# --------------------------------------------------------------------------- repository


def check_repository(api: Api, repo: str, want: dict[str, Json]) -> list[Drift]:
    data = api.get(f"repos/{repo}") or {}
    drift = []
    for key, value in want.items():
        have = data.get(key)
        if key == "topics":
            have, value = sorted(have or []), sorted(value)
        elif key in ("description", "homepage"):
            have = have or ""
        if have != value:
            drift.append(Drift(f"repository.{key}", value, have))
    return drift


def apply_repository(api: Api, repo: str, drift: list[Drift]) -> None:
    fields = {item.setting.removeprefix("repository."): item.want for item in drift}
    topics = fields.pop("topics", None)
    if fields:
        api.patch(f"repos/{repo}", fields)
    if topics is not None:
        api.put(f"repos/{repo}/topics", {"names": topics})


# --------------------------------------------------------------------------- security

ANALYSIS = ("secret_scanning", "secret_scanning_push_protection")


def read_security(api: Api, repo: str) -> dict[str, bool]:
    analysis = (api.get(f"repos/{repo}") or {}).get("security_and_analysis") or {}
    reporting = api.get(f"repos/{repo}/private-vulnerability-reporting") or {}
    fixes = api.get(f"repos/{repo}/automated-security-fixes") or {}
    immutable = api.get(f"repos/{repo}/immutable-releases") or {}
    have = {
        "immutable_releases": bool(immutable.get("enabled")),
        "private_vulnerability_reporting": bool(reporting.get("enabled")),
        "dependabot_alerts": api.exists(f"repos/{repo}/vulnerability-alerts"),
        "dependabot_security_updates": bool(fixes.get("enabled")),
    }
    for key in ANALYSIS:
        have[key] = (analysis.get(key) or {}).get("status") == "enabled"
    return have


def check_security(api: Api, repo: str, want: dict[str, bool]) -> list[Drift]:
    have = read_security(api, repo)
    return [
        Drift(f"security.{key}", value, have.get(key))
        for key, value in want.items()
        if have.get(key) != value
    ]


def apply_security(api: Api, repo: str, drift: list[Drift]) -> None:
    switches = {
        "immutable_releases": f"repos/{repo}/immutable-releases",
        "private_vulnerability_reporting": f"repos/{repo}/private-vulnerability-reporting",
        "dependabot_alerts": f"repos/{repo}/vulnerability-alerts",
        "dependabot_security_updates": f"repos/{repo}/automated-security-fixes",
    }
    analysis = {}
    # Alerts come before the security updates, which need them.
    for item in sorted(
        drift, key=lambda item: item.setting != "security.dependabot_alerts"
    ):
        key = item.setting.removeprefix("security.")
        if key in switches:
            if item.want:
                api.put(switches[key])
            else:
                api.delete(switches[key])
        else:
            analysis[key] = {"status": "enabled" if item.want else "disabled"}
    if analysis:
        api.patch(f"repos/{repo}", {"security_and_analysis": analysis})


# --------------------------------------------------------------------------- actions


def check_actions(api: Api, repo: str, want: dict[str, Json]) -> list[Drift]:
    have = dict(api.get(f"repos/{repo}/actions/permissions/workflow") or {})
    have["sha_pinning_required"] = (
        api.get(f"repos/{repo}/actions/permissions") or {}
    ).get("sha_pinning_required", False)
    approval = (
        api.get(f"repos/{repo}/actions/permissions/fork-pr-contributor-approval") or {}
    )
    have["fork_pr_approval"] = approval.get("approval_policy")
    return [
        Drift(f"actions.{key}", value, have.get(key))
        for key, value in want.items()
        if have.get(key) != value
    ]


def apply_actions(
    api: Api, repo: str, want: dict[str, Json], drift: list[Drift]
) -> None:
    keys = {item.setting.removeprefix("actions.") for item in drift}
    if keys & {"default_workflow_permissions", "can_approve_pull_request_reviews"}:
        api.put(
            f"repos/{repo}/actions/permissions/workflow",
            {
                "default_workflow_permissions": want["default_workflow_permissions"],
                "can_approve_pull_request_reviews": want[
                    "can_approve_pull_request_reviews"
                ],
            },
        )
    if "sha_pinning_required" in keys:
        current = api.get(f"repos/{repo}/actions/permissions") or {}
        api.put(
            f"repos/{repo}/actions/permissions",
            {
                "enabled": True,
                "allowed_actions": current.get("allowed_actions", "all"),
                "sha_pinning_required": want["sha_pinning_required"],
            },
        )
    if "fork_pr_approval" in keys:
        api.put(
            f"repos/{repo}/actions/permissions/fork-pr-contributor-approval",
            {"approval_policy": want["fork_pr_approval"]},
        )


# --------------------------------------------------------------------------- rulesets


def main_ruleset(checks: list[str]) -> dict[str, Json]:
    """Pull requests into main: squashed, linear, with every required check passing."""
    return {
        "name": "Protect main",
        "target": "branch",
        "enforcement": "active",
        "bypass_actors": [],
        "conditions": {"ref_name": {"include": ["~DEFAULT_BRANCH"], "exclude": []}},
        "rules": [
            {"type": "deletion"},
            {"type": "non_fast_forward"},
            {"type": "required_linear_history"},
            # Every commit signed: the maintainer's and those that bots make through the API.
            {"type": "required_signatures"},
            {
                "type": "pull_request",
                "parameters": {
                    "allowed_merge_methods": ["squash"],
                    "dismiss_stale_reviews_on_push": True,
                    "require_code_owner_review": False,
                    "require_last_push_approval": False,
                    "required_approving_review_count": 0,
                    "required_review_thread_resolution": True,
                },
            },
            {
                "type": "required_status_checks",
                "parameters": {
                    "do_not_enforce_on_create": True,
                    "strict_required_status_checks_policy": True,
                    "required_status_checks": [
                        {"context": check, "integration_id": ACTIONS_APP}
                        for check in checks
                    ],
                },
            },
        ],
    }


def tag_ruleset() -> dict[str, Json]:
    """Release tags such as v1.2.3 never move; major tags such as v1 may."""
    return {
        "name": "Release tags",
        "target": "tag",
        "enforcement": "active",
        "bypass_actors": [],
        "conditions": {"ref_name": {"include": ["refs/tags/v*.*.*"], "exclude": []}},
        "rules": [
            {"type": "deletion"},
            {"type": "non_fast_forward"},
            {"type": "update"},
        ],
    }


def ruleset_differences(want: dict[str, Json], have: dict[str, Json]) -> list[str]:
    """What differs between two rulesets, in the parts this tool manages. A token that
    may only read the administration, such as the release app's, gets the rulesets
    without bypass_actors; then nothing can be said about them."""
    differences = [
        key
        for key in ("target", "enforcement", "bypass_actors", "conditions")
        if want[key] != have.get(key)
        and not (key == "bypass_actors" and key not in have)
    ]
    have_rules = {
        rule["type"]: rule.get("parameters", {}) for rule in have.get("rules", [])
    }
    for rule in want["rules"]:
        parameters = have_rules.pop(rule["type"], None)
        if parameters is None:
            differences.append(f"rule {rule['type']} is missing")
            continue
        for key, value in rule.get("parameters", {}).items():
            current = parameters.get(key)
            if key == "required_status_checks":
                value = sorted(check["context"] for check in value)
                current = sorted(check["context"] for check in current or [])
            if current != value:
                differences.append(f"{rule['type']}.{key}")
    differences += [f"rule {kind} is extra" for kind in have_rules]
    return differences


def rulesets(api: Api, repo: str) -> dict[str, Json]:
    return {
        item["name"]: item["id"] for item in api.get(f"repos/{repo}/rulesets") or []
    }


def check_rulesets(api: Api, repo: str, wanted: list[dict[str, Json]]) -> list[Drift]:
    existing = rulesets(api, repo)
    drift = []
    for want in wanted:
        if want["name"] not in existing:
            drift.append(Drift(f"ruleset {want['name']}", "present", "missing"))
            continue
        have = api.get(f"repos/{repo}/rulesets/{existing[want['name']]}") or {}
        differences = ruleset_differences(want, have)
        if differences:
            drift.append(
                Drift(
                    f"ruleset {want['name']}", "as configured", ", ".join(differences)
                )
            )
    return drift


def apply_rulesets(
    api: Api, repo: str, wanted: list[dict[str, Json]], drift: list[Drift]
) -> None:
    existing = rulesets(api, repo)
    names = {item.setting.removeprefix("ruleset ") for item in drift}
    for want in wanted:
        if want["name"] not in names:
            continue
        if want["name"] in existing:
            api.put(f"repos/{repo}/rulesets/{existing[want['name']]}", want)
        else:
            api.post(f"repos/{repo}/rulesets", want)


# --------------------------------------------------------------------------- variables


def check_variables(api: Api, repo: str, want: dict[str, str]) -> list[Drift]:
    drift = []
    for name, value in want.items():
        have = api.get(f"repos/{repo}/actions/variables/{name}")
        current = None if have is None else have.get("value")
        if current != value:
            drift.append(Drift(f"variable {name}", value, current))
    return drift


def apply_variables(api: Api, repo: str, drift: list[Drift]) -> None:
    for item in drift:
        name = item.setting.removeprefix("variable ")
        if item.have is None:
            api.post(
                f"repos/{repo}/actions/variables", {"name": name, "value": item.want}
            )
        else:
            api.patch(
                f"repos/{repo}/actions/variables/{name}",
                {"name": name, "value": item.want},
            )


# --------------------------------------------------------------------------- environments

MAIN_ONLY = {"protected_branches": False, "custom_branch_policies": True}


def check_environments(
    api: Api, repo: str, want: dict[str, dict[str, Json]]
) -> list[Drift]:
    drift = []
    for name, config in want.items():
        environment = api.get(f"repos/{repo}/environments/{name}")
        if environment is None:
            drift.append(Drift(f"environment {name}", "main only", "missing"))
        else:
            policy = environment.get("deployment_branch_policy")
            branches = (
                api.get(f"repos/{repo}/environments/{name}/deployment-branch-policies")
                or {}
            )
            names = sorted(
                f"{item.get('type', 'branch')}:{item['name']}"
                for item in branches.get("branch_policies", [])
            )
            if policy != MAIN_ONLY or names != ["branch:main"]:
                drift.append(Drift(f"environment {name}", "main only", names or policy))
        present = {
            item["name"]
            for item in (
                api.get(f"repos/{repo}/environments/{name}/secrets") or {}
            ).get("secrets", [])
        }
        for secret in config.get("secrets", []):
            if secret not in present:
                command = f"gh secret set {secret} --env {name} --repo {repo}"
                drift.append(
                    Drift(
                        f"secret {secret} of {name}", "set", "missing", manual=command
                    )
                )
    return drift


def apply_environments(api: Api, repo: str, drift: list[Drift]) -> None:
    for item in drift:
        name = item.setting.removeprefix("environment ")
        api.put(
            f"repos/{repo}/environments/{name}", {"deployment_branch_policy": MAIN_ONLY}
        )
        policies = (
            api.get(f"repos/{repo}/environments/{name}/deployment-branch-policies")
            or {}
        )
        for policy in policies.get("branch_policies", []):
            if (policy.get("type", "branch"), policy["name"]) != ("branch", "main"):
                api.delete(
                    f"repos/{repo}/environments/{name}/deployment-branch-policies/{policy['id']}"
                )
        if not any(
            (policy.get("type", "branch"), policy["name"]) == ("branch", "main")
            for policy in policies.get("branch_policies", [])
        ):
            api.post(
                f"repos/{repo}/environments/{name}/deployment-branch-policies",
                {"name": "main", "type": "branch"},
            )


# --------------------------------------------------------------------------- labels


def check_labels(api: Api, repo: str, want: dict[str, dict[str, str]]) -> list[Drift]:
    """Every label of .github/labels.toml, with its color and description.

    The Labels workflow keeps them current later; a new repository needs them before
    its first pull request, whose title check sets one.
    """
    have = {
        item["name"]: item
        for item in api.get(f"repos/{repo}/labels?per_page=100") or []
    }
    drift = []
    for name, label in want.items():
        current = have.get(name)
        wanted = f"#{label['color'].lower()} {label['description']}"
        if current is None:
            drift.append(Drift(f"label {name}", wanted, None))
        elif (
            f"#{current['color'].lower()} {current.get('description') or ''}" != wanted
        ):
            drift.append(
                Drift(
                    f"label {name}",
                    wanted,
                    f"#{current['color']} {current.get('description') or ''}",
                )
            )
    return drift


def apply_labels(
    api: Api, repo: str, want: dict[str, dict[str, str]], drift: list[Drift]
) -> None:
    for item in drift:
        name = item.setting.removeprefix("label ")
        body = {
            "name": name,
            "color": want[name]["color"].lower(),
            "description": want[name]["description"],
        }
        if item.have is None:
            api.post(f"repos/{repo}/labels", body)
        else:
            api.patch(f"repos/{repo}/labels/{urllib.parse.quote(name, safe='')}", body)


def load_labels(path: Path) -> dict[str, dict[str, str]]:
    if not path.exists():
        return {}
    labels = tomllib.loads(path.read_text(encoding="utf-8")).get("label", [])
    return {
        label["name"]: {
            "color": label["color"],
            "description": label.get("description", ""),
        }
        for label in labels
    }


# --------------------------------------------------------------------------- settings


def merge(base: dict[str, Json], extra: dict[str, Json]) -> dict[str, Json]:
    """Extra settings on top of base: tables merge, lists gain the new items, and
    values replace."""
    result = dict(base)
    for key, value in extra.items():
        current = result.get(key)
        if isinstance(current, dict) and isinstance(value, dict):
            result[key] = merge(current, value)
        elif isinstance(current, list) and isinstance(value, list):
            result[key] = current + [item for item in value if item not in current]
        else:
            result[key] = value
    return result


@dataclass(frozen=True)
class Settings:
    repository: dict[str, Json]
    security: dict[str, bool]
    actions: dict[str, Json]
    rulesets: list[dict[str, Json]]
    variables: dict[str, str]
    environments: dict[str, dict[str, Json]]
    labels: dict[str, dict[str, str]]

    @classmethod
    def load(cls, path: Path) -> Settings:
        config = tomllib.loads(path.read_text(encoding="utf-8"))
        # The settings of this project alone, on top of those the blueprint keeps
        # current: its own checks, environments, secrets and variables.
        project = path.with_name("repository.project.toml")
        if project.exists():
            config = merge(config, tomllib.loads(project.read_text(encoding="utf-8")))
        checks = config.get("branch", {}).get("required_checks", [])
        return cls(
            repository=config.get("repository", {}),
            security=config.get("security", {}),
            actions=config.get("actions", {}),
            rulesets=[main_ruleset(checks), tag_ruleset()],
            variables=config.get("variables", {}),
            environments=config.get("environments", {}),
            # The labels of the issue assistant, next to the settings.
            labels=load_labels(path.parent / "labels.toml"),
        )


def check(api: Api, repo: str, settings: Settings) -> list[Drift]:
    return (
        check_repository(api, repo, settings.repository)
        + check_security(api, repo, settings.security)
        + check_actions(api, repo, settings.actions)
        + check_rulesets(api, repo, settings.rulesets)
        + check_variables(api, repo, settings.variables)
        + check_environments(api, repo, settings.environments)
        + check_labels(api, repo, settings.labels)
    )


def apply(api: Api, repo: str, settings: Settings, drift: list[Drift]) -> None:
    def part(prefix: str) -> list[Drift]:
        return [item for item in drift if item.setting.startswith(prefix)]

    apply_repository(api, repo, part("repository."))
    apply_security(api, repo, part("security."))
    apply_actions(api, repo, settings.actions, part("actions."))
    apply_rulesets(api, repo, settings.rulesets, part("ruleset "))
    apply_variables(api, repo, part("variable "))
    apply_environments(api, repo, part("environment "))
    apply_labels(api, repo, settings.labels, part("label "))


def describe(drift: list[Drift]) -> str:
    lines = []
    for item in drift:
        line = f"  {item.setting}: GitHub has {json.dumps(item.have)}, the configuration wants {json.dumps(item.want)}"
        if item.manual:
            line += f"\n      {item.manual}"
        lines.append(line)
    return "\n".join(lines)


# --------------------------------------------------------------------------- checklist


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


# --------------------------------------------------------------------------- changelog

# Pull requests write what changes for users under "## Unreleased" of CHANGELOG.md.
# release-please decides the version from their titles and writes a section of them;
# the release bot puts the text of Unreleased in its place, and the release notes
# show that text with the list of the pull requests below it.

HEADING = re.compile(r"^## .*$", re.MULTILINE)
UNRELEASED = re.compile(r"^## \[?unreleased\]?\s*$", re.IGNORECASE)
COMPARE = re.compile(r"\((https://\S+/compare/\S+?)\)")
# Plain Markdown, which also the update dialogs of Home Assistant and the like show.
PULL_REQUESTS = "## Every pull request of this release"


def block(text: str) -> str:
    """The body of a section: the text after a blank line, or nothing."""
    text = text.strip("\n")
    return f"\n\n{text}\n\n" if text.strip() else "\n\n"


@dataclass
class Changelog:
    """A changelog as its preamble and its sections, each a heading and its body."""

    preamble: str
    sections: list[tuple[str, str]]

    @classmethod
    def parse(cls, text: str) -> Changelog:
        headings = list(HEADING.finditer(text))
        if not headings:
            return cls(text, [])
        ends = [match.start() for match in headings[1:]] + [len(text)]
        sections = [
            (match.group(), text[match.end() : end])
            for match, end in zip(headings, ends, strict=True)
        ]
        return cls(text[: headings[0].start()], sections)

    def find(self, pattern: re.Pattern[str]) -> int | None:
        return next(
            (
                index
                for index, (heading, _) in enumerate(self.sections)
                if pattern.match(heading)
            ),
            None,
        )

    def render(self) -> str:
        text = self.preamble.rstrip("\n") + "\n\n" if self.preamble.strip() else ""
        text += "".join(heading + body for heading, body in self.sections)
        return text.rstrip("\n") + "\n"


def version_heading(version: str) -> re.Pattern[str]:
    return re.compile(rf"^## \[?v?{re.escape(version)}\]?(?=[\s(]|$)")


def release_changelog(version: str, main: str, branch: str) -> str:
    """The changelog of the release pull request for the version: the changelog of main,
    with the text of Unreleased moved into the section that release-please wrote on the
    branch. Without that text, the section lists the pull requests. A prerelease keeps
    the text under Unreleased for the release that follows it."""
    written = Changelog.parse(branch)
    found = written.find(version_heading(version))
    if found is None:
        return branch
    heading, generated = written.sections[found]
    changelog = Changelog.parse(main)
    unreleased = changelog.find(UNRELEASED)
    text = "" if unreleased is None else changelog.sections[unreleased][1]
    keep = "-" in version or not text.strip()
    others = [
        section
        for section in changelog.sections
        if not UNRELEASED.match(section[0])
        and not version_heading(version).match(section[0])
    ]
    changelog.sections = [
        ("## Unreleased", block(text) if keep else block("")),
        (heading, block(generated if keep else text)),
        *others,
    ]
    return changelog.render()


def release_notes(version: str, changelog: str, generated: str) -> str:
    """The notes of the release: its section of the changelog, followed by the list of
    its pull requests that release-please wrote, or only that list. Notes that have
    both already stay as they are."""
    log = Changelog.parse(changelog)
    found = log.find(version_heading(version))
    listed = Changelog.parse(generated)
    pulls = listed.sections[0][1] if listed.sections else generated
    if (
        found is None
        or PULL_REQUESTS in generated
        or log.sections[found][1].split() == pulls.split()
    ):
        return generated.rstrip("\n") + "\n"
    compare = COMPARE.search(listed.sections[0][0]) if listed.sections else None
    lines = [
        log.sections[found][1].strip("\n"),
        "",
        PULL_REQUESTS,
        "",
        pulls.strip("\n"),
    ]
    if compare:
        lines += ["", f"[Compare with the previous release]({compare.group(1)})"]
    return "\n".join([*lines, ""])


def add_unreleased(changelog: str, heading: str, entry: str) -> str:
    """The changelog with the entry under its heading in Unreleased; both are added
    when they are missing, and an entry that is there already stays once."""
    log = Changelog.parse(changelog)
    found = log.find(UNRELEASED)
    if found is None:
        log.sections.insert(0, ("## Unreleased", block("")))
        found = 0
    lines = log.sections[found][1].strip("\n").split("\n")
    if entry in lines:
        return changelog
    title = f"### {heading}"
    if title not in lines:
        lines += ["", title]
    start = lines.index(title) + 1
    end = start
    while end < len(lines) and not lines[end].startswith("### "):
        end += 1
    while end > start and not lines[end - 1].strip():
        end -= 1
    lines[end:end] = [entry] if end > start else ["", entry]
    log.sections[found] = (log.sections[found][0], block("\n".join(lines)))
    return log.render()


def changelog_command(arguments: argparse.Namespace) -> int:
    if arguments.command == "unreleased":
        path = Path(arguments.file)
        text = path.read_text(encoding="utf-8")
        path.write_text(
            add_unreleased(text, arguments.heading, arguments.entry), encoding="utf-8"
        )
        return 0
    read = [Path(name).read_text(encoding="utf-8") for name in arguments.files]
    if arguments.action == "release":
        sys.stdout.write(release_changelog(arguments.version, *read))
    else:
        sys.stdout.write(release_notes(arguments.version, *read))
    return 0


# --------------------------------------------------------------------------- command line


def current_repo(api: Api) -> str:
    result = api.runner(
        ["repo", "view", "--json", "nameWithOwner", "--jq", ".nameWithOwner"], None
    )
    if result.returncode != 0:
        raise GitHubError(
            0,
            "this folder is no GitHub repository the CLI knows; pass --repo OWNER/NAME",
        )
    return result.stdout.strip()


def main(
    argv: Sequence[str] | None = None, api: Api | None = None, root: Path = Path()
) -> int:
    parser = argparse.ArgumentParser(
        prog="blueprint.py", description=__doc__.split("\n\n")[0]
    )
    parser.add_argument(
        "--repo", help="OWNER/NAME; by default the repository of this folder"
    )
    parser.add_argument(
        "--config",
        type=Path,
        default=CONFIG,
        help="the settings, by default %(default)s",
    )
    commands = parser.add_subparsers(dest="command", required=True)
    settings_parser = commands.add_parser(
        "settings", help="compare or apply the settings as code"
    )
    settings_parser.add_argument("action", choices=["check", "apply"])
    commands.add_parser(
        "checklist", help="the steps outside the API, and which are done"
    )
    changelog_parser = commands.add_parser(
        "changelog",
        help="the changelog and the notes of a release, for the release bot",
    )
    changelog_parser.add_argument(
        "action",
        choices=["release", "notes"],
        help="release: the changelog of the release pull request; notes: the release notes",
    )
    changelog_parser.add_argument("version")
    changelog_parser.add_argument(
        "files",
        nargs=2,
        metavar="FILE",
        help="release: the changelog of main and that of the release branch; "
        "notes: the changelog of the release and the notes of release-please",
    )
    unreleased_parser = commands.add_parser(
        "unreleased", help="add an entry under Unreleased in the changelog, for bots"
    )
    unreleased_parser.add_argument("heading", help="such as Changed, without ###")
    unreleased_parser.add_argument("entry", help="the line, such as '- Supports ...'")
    unreleased_parser.add_argument("--file", default="CHANGELOG.md")
    arguments = parser.parse_args(argv)

    if arguments.command in ("changelog", "unreleased"):
        try:
            return changelog_command(arguments)
        except OSError as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    api = api or Api()
    try:
        repo = arguments.repo or current_repo(api)
        settings = Settings.load(root / arguments.config)
        if arguments.command == "checklist":
            print(f"The set-up of {repo}:")
            steps = checklist(api, repo, settings, root)
            print(describe_steps(steps))
            return 0 if all(step.done is not False for step in steps) else 1
        drift = check(api, repo, settings)
        if arguments.action == "apply" and drift:
            apply(api, repo, settings, drift)
            drift = check(api, repo, settings)
        if not drift:
            print(f"The settings of {repo} match {arguments.config}.")
            return 0
        print(f"The settings of {repo} differ from {arguments.config}:")
        print(describe(drift))
        if arguments.action == "check":
            print("python3 blueprint.py settings apply sets what the API can set.")
        return 1
    except (GitHubError, OSError, tomllib.TOMLDecodeError) as error:
        print(f"blueprint.py: {error}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
