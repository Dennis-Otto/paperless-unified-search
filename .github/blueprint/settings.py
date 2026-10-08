"""The settings of a repository as code: where GitHub differs from
.github/repository.toml, and how it comes to match."""

from __future__ import annotations

import json
import tomllib
import urllib.parse
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path

from blueprint.github import Api, GitHubError, Json

CONFIG = Path(".github/repository.toml")
# The GitHub Actions app, which reports the checks of the workflows.
ACTIONS_APP = 15368


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


# --------------------------------------------------------------------------- community


def check_community(api: Api, repo: str) -> list[Drift]:
    """GitHub's community standards: every file that the profile of the repository
    counts. The blueprint brings them all, so a gap is one that a person made."""
    profile = api.get(f"repos/{repo}/community/profile") or {}
    health = profile.get("health_percentage", 0)
    if health == 100:
        return []
    missing = sorted(
        name for name, file in (profile.get("files") or {}).items() if file is None
    )
    if not profile.get("description"):
        missing.append("description")
    what = ", ".join(missing) or "what the profile counts"
    return [
        Drift(
            "community profile",
            100,
            health,
            manual=f"add {what}: https://github.com/{repo}/community",
        )
    ]


# --------------------------------------------------------------------------- pages


def check_pages(api: Api, repo: str, want: dict[str, Json]) -> list[Drift]:
    """The GitHub Pages site, to which the Docs workflow publishes the website."""
    if not want:
        return []
    have = api.get(f"repos/{repo}/pages")
    if have is None:
        return [Drift("pages", "on", "off")]
    return [
        Drift(f"pages.{key}", value, have.get(key))
        for key, value in want.items()
        if have.get(key) != value
    ]


def apply_pages(api: Api, repo: str, want: dict[str, Json], drift: list[Drift]) -> None:
    # A site that is off is created with the settings; one that is on is changed.
    if any(item.setting == "pages" for item in drift):
        api.post(f"repos/{repo}/pages", want)
    elif drift:
        api.put(f"repos/{repo}/pages", want)


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
    pages: dict[str, Json]
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
            pages=config.get("pages", {}),
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
        + check_pages(api, repo, settings.pages)
        + check_labels(api, repo, settings.labels)
        + check_community(api, repo)
    )


# The permission of the release app that each part of the settings needs.
PERMISSIONS = {
    "repository": "Administration",
    "security": "Administration",
    "actions": "Administration",
    "rulesets": "Administration",
    "variables": "Variables",
    "environments": "Environments",
    "pages": "Pages",
    "labels": "Issues",
}


def apply(api: Api, repo: str, settings: Settings, drift: list[Drift]) -> list[str]:
    """Apply every part of the drift and return the parts that GitHub refused.

    A part that GitHub refuses, such as one whose permission the release app lacks,
    doesn't keep the parts after it from being applied.
    """

    def part(prefix: str) -> list[Drift]:
        return [item for item in drift if item.setting.startswith(prefix)]

    steps: list[tuple[str, Callable[[], None]]] = [
        ("repository", lambda: apply_repository(api, repo, part("repository."))),
        ("security", lambda: apply_security(api, repo, part("security."))),
        (
            "actions",
            lambda: apply_actions(api, repo, settings.actions, part("actions.")),
        ),
        (
            "rulesets",
            lambda: apply_rulesets(api, repo, settings.rulesets, part("ruleset ")),
        ),
        ("variables", lambda: apply_variables(api, repo, part("variable "))),
        ("environments", lambda: apply_environments(api, repo, part("environment "))),
        ("pages", lambda: apply_pages(api, repo, settings.pages, part("pages"))),
        ("labels", lambda: apply_labels(api, repo, settings.labels, part("label "))),
    ]
    refused = []
    for name, step in steps:
        try:
            step()
        except GitHubError as error:
            hint = ""
            if error.status == 403:
                hint = (
                    f"; the token may lack the permission {PERMISSIONS[name]}"
                    " (read and write)"
                )
            refused.append(f"the {name} could not be applied: {error}{hint}")
    return refused


def describe(drift: list[Drift]) -> str:
    lines = []
    for item in drift:
        line = f"  {item.setting}: GitHub has {json.dumps(item.have)}, the configuration wants {json.dumps(item.want)}"
        if item.manual:
            line += f"\n      {item.manual}"
        lines.append(line)
    return "\n".join(lines)
