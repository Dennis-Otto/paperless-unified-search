"""The settings of a repository as code: where GitHub differs from
.github/repository.toml, and how it comes to match."""

from __future__ import annotations

import base64
import json
import re
import tomllib
import urllib.parse
from collections.abc import Callable, Iterable
from dataclasses import dataclass
from pathlib import Path

from blueprint.github import Api, GitHubError, Json

CONFIG = Path(".github/repository.toml")
# The branch that every workflow runs on: their triggers name main.
BRANCH = "main"
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


class ConfigError(ValueError):
    """A value in the settings as code that can't be right."""


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
    return drift + check_default_branch(repo, data.get("default_branch"))


def check_default_branch(repo: str, have: str | None) -> list[Drift]:
    """The workflows run on pushes to main and on pull requests to it, so with another
    default branch, such as the master of a Git without init.defaultBranch, none of
    them runs. Renaming it is a step for a person, which apply leaves alone: GitHub
    moves the open pull requests and the rules to the new name, but every clone needs
    the new name too."""
    if have in (None, BRANCH):
        return []
    return [
        Drift(
            "default branch",
            BRANCH,
            have,
            manual=f"the workflows expect {BRANCH}: rename {have} to {BRANCH} at "
            f"https://github.com/{repo}/branches, then in each clone: "
            f"git branch --move {have} {BRANCH} && git fetch origin && "
            f"git branch --set-upstream-to origin/{BRANCH} {BRANCH}",
        )
    ]


def apply_repository(api: Api, repo: str, drift: list[Drift]) -> None:
    fields = {item.setting.removeprefix("repository."): item.want for item in drift}
    topics = fields.pop("topics", None)
    if fields:
        api.patch(f"repos/{repo}", fields)
    if topics is not None:
        api.put(f"repos/{repo}/topics", {"names": topics})


# --------------------------------------------------------------------------- security

# The switches of security_and_analysis: secret scanning, its push protection and its
# generic patterns, which find private keys, connection strings and passwords that no
# provider issued. Validity checks have no switch for a repository in the API, only in
# the security configurations of an organization (decision 0038).
ANALYSIS = (
    "secret_scanning",
    "secret_scanning_push_protection",
    "secret_scanning_non_provider_patterns",
)
# A switch that GitHub doesn't offer for a repository; it never comes on.
UNOFFERED = "not offered"


def read_security(api: Api, repo: str) -> dict[str, bool | str]:
    analysis = (api.get(f"repos/{repo}") or {}).get("security_and_analysis")
    reporting = api.get(f"repos/{repo}/private-vulnerability-reporting") or {}
    fixes = api.get(f"repos/{repo}/automated-security-fixes") or {}
    immutable = api.get(f"repos/{repo}/immutable-releases") or {}
    have: dict[str, bool | str] = {
        "immutable_releases": bool(immutable.get("enabled")),
        "private_vulnerability_reporting": bool(reporting.get("enabled")),
        "dependabot_alerts": api.exists(f"repos/{repo}/vulnerability-alerts"),
        "dependabot_security_updates": bool(fixes.get("enabled")),
    }
    for key in ANALYSIS:
        if not isinstance(analysis, dict):
            # A token that may not read them gets none.
            have[key] = False
        elif isinstance(analysis.get(key), dict):
            have[key] = analysis[key].get("status") == "enabled"
        else:
            # GitHub names every switch that it offers for the repository.
            have[key] = UNOFFERED
    return have


def check_security(api: Api, repo: str, want: dict[str, bool]) -> list[Drift]:
    have = read_security(api, repo)
    drift = []
    for key, value in want.items():
        current = have.get(key)
        if current == UNOFFERED and not value:
            continue
        if current != value:
            manual = ""
            if current == UNOFFERED:
                manual = (
                    f"GitHub doesn't offer {key} for {repo}; set {key} = false in"
                    " .github/repository.project.toml"
                )
            drift.append(Drift(f"security.{key}", value, current, manual=manual))
    return drift


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
        if item.manual:
            # Not offered: a request would be refused, and the others with it.
            continue
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

# Which actions may run: all, only those of the repository, or those of GitHub and of
# the patterns that [actions.selected] lists.
ALLOWED_ACTIONS = ("all", "local_only", "selected")
# The owners of the actions that GitHub makes, which github_owned_allowed allows.
GITHUB_OWNERS = ("actions", "github")
# A step or a job that names an action or a reusable workflow, in the characters that
# such a name may have: the file of an action that GitHub gives is no Markdown for the
# summary of the settings bot.
USES = re.compile(r"""^[ \t]*(?:-[ \t]+)?uses:[ \t]*["']?([\w.:/@+-]+)""", re.MULTILINE)


def references(text: str) -> list[str]:
    """The actions and reusable workflows that a workflow or an action names: not
    those of the repository itself, which every policy allows, nor the images of
    Docker, which no pattern names and which are pinned by their digest instead."""
    return [
        reference
        for reference in USES.findall(text)
        if not reference.startswith(("./", "docker://"))
    ]


def workflow_references(root: Path) -> dict[str, str]:
    """Every action and reusable workflow that the workflows and actions of the
    repository at root name, with the first file that names it."""
    files = sorted(
        [
            *(root / ".github/workflows").glob("*.y*ml"),
            *(root / ".github/actions").rglob("action.y*ml"),
            *root.glob("action.y*ml"),
        ]
    )
    found: dict[str, str] = {}
    for path in files:
        for reference in references(path.read_text(encoding="utf-8")):
            found.setdefault(reference, path.relative_to(root).as_posix())
    return found


def pattern_of(reference: str) -> str:
    """The pattern that allows an action or a reusable workflow at every commit."""
    return reference.partition("@")[0] + "@*"


def matches(pattern: str, reference: str) -> bool:
    """Whether a pattern of GitHub, in which * stands for any text, names the action;
    GitHub ignores the case of owners and repositories."""
    parts = pattern.casefold().split("*")
    return (
        re.fullmatch(".*".join(map(re.escape, parts)), reference.casefold()) is not None
    )


def allows(selected: dict[str, Json], reference: str) -> bool:
    """Whether [actions.selected] lets the action or reusable workflow run. A pattern
    with ! in front blocks what it names, also an action of GitHub."""
    patterns = [str(pattern) for pattern in selected.get("patterns_allowed") or []]
    if any(
        matches(pattern[1:], reference) for pattern in patterns if pattern[:1] == "!"
    ):
        return False
    owner = reference.casefold().split("/", 1)[0]
    if selected.get("github_owned_allowed") and owner in GITHUB_OWNERS:
        return True
    return any(matches(pattern, reference) for pattern in patterns)


def run_by(api: Api, reference: str) -> list[str]:
    """The actions and reusable workflows that an action or a reusable workflow runs in
    turn, at the commit that it is pinned to: GitHub allows or blocks them as well."""
    name, _, ref = reference.partition("@")
    owner, _, rest = name.partition("/")
    repo, _, path = rest.partition("/")
    if path.endswith((".yml", ".yaml")):
        candidates = [path]
    else:
        candidates = [
            f"{path}/{file}".lstrip("/") for file in ("action.yml", "action.yaml")
        ]
    for candidate in candidates:
        data = api.get(
            f"repos/{owner}/{repo}/contents/{urllib.parse.quote(candidate)}"
            f"?ref={urllib.parse.quote(ref, safe='')}"
        )
        if isinstance(data, dict) and data.get("content"):
            text = base64.b64decode(str(data["content"])).decode("utf-8", "replace")
            return references(text)
    return []


def actions_that_run(
    api: Api, named: dict[str, str]
) -> tuple[dict[str, str], dict[str, str]]:
    """Every action and reusable workflow that the workflows run, with what names it:
    those that the workflows name, and those that these run in turn; and those whose
    file GitHub refused to give, with its answer."""
    found = dict(named)
    refused = {}
    todo = sorted(named)
    while todo:
        reference = todo.pop()
        try:
            inner_references = run_by(api, reference)
        except GitHubError as error:
            refused[reference] = str(error)
            continue
        for inner in inner_references:
            if inner not in found:
                found[inner] = reference.partition("@")[0]
                todo.append(inner)
    return found, refused


def check_actions(
    api: Api, repo: str, want: dict[str, Json], named: dict[str, str] | None = None
) -> list[Drift]:
    permissions = api.get(f"repos/{repo}/actions/permissions") or {}
    have = dict(api.get(f"repos/{repo}/actions/permissions/workflow") or {})
    have["sha_pinning_required"] = permissions.get("sha_pinning_required", False)
    have["allowed_actions"] = permissions.get("allowed_actions", "all")
    approval = (
        api.get(f"repos/{repo}/actions/permissions/fork-pr-contributor-approval") or {}
    )
    have["fork_pr_approval"] = approval.get("approval_policy")
    drift = [
        Drift(f"actions.{key}", value, have.get(key))
        for key, value in want.items()
        if key != "selected" and have.get(key) != value
    ]
    selected = want.get("selected")
    if want.get("allowed_actions") == "selected" and isinstance(selected, dict):
        active = have["allowed_actions"] == "selected"
        drift += check_selected_actions(api, repo, selected, active, named or {})
    return drift


def check_selected_actions(
    api: Api, repo: str, selected: dict[str, Json], active: bool, named: dict[str, str]
) -> list[Drift]:
    """The list of the actions that may run, and every action that a workflow runs
    but the list lacks: while one does, the list on GitHub never narrows, because that
    would stop that workflow."""
    # GitHub keeps a list only while the repository allows the selected actions.
    path = f"repos/{repo}/actions/permissions/selected-actions"
    current = (api.get(path) or {}) if active else {}
    drift = []
    for key, value in selected.items():
        have = current.get(key)
        same = have == value
        if key == "patterns_allowed":
            value, have = sorted(value or []), sorted(have or [])
            # GitHub ignores the case of owners and repositories.
            same = sorted(map(str.casefold, value)) == sorted(map(str.casefold, have))
        if not same:
            drift.append(Drift(f"actions.selected.{key}", value, have))
    found, refused = actions_that_run(api, named)
    lacking: dict[str, str] = {}
    for reference, where in sorted(found.items()):
        if not allows(selected, reference):
            lacking.setdefault(pattern_of(reference), where)
    drift += [
        Drift(
            f"actions.selected.patterns_allowed lacks {pattern}",
            "listed",
            "missing",
            manual=(
                f"{where} runs it: add {json.dumps(pattern)} to patterns_allowed of"
                " [actions.selected] in .github/repository.project.toml; until then"
                " the list of the allowed actions on GitHub only grows"
            ),
        )
        for pattern, where in lacking.items()
    ]
    # What an action runs in turn that no one could read may be missing as well.
    drift += [
        Drift(
            f"actions.selected.patterns_allowed unknown for {reference}",
            "read",
            "unreadable",
            manual=(
                f"GitHub refused the file of {reference}: {answer}; what it runs in"
                " turn is unknown, so the list of the allowed actions on GitHub only"
                " grows until the next run reads it"
            ),
        )
        for reference, answer in sorted(refused.items())
    ]
    return drift


def widen_selected_actions(api: Api, repo: str, selected: dict[str, Json]) -> None:
    """While a workflow runs an action that the list lacks, a list that GitHub already
    uses only grows by the configured patterns: that never stops a workflow, and the
    actions of a newer release of the blueprint can still start."""
    permissions = api.get(f"repos/{repo}/actions/permissions") or {}
    if permissions.get("allowed_actions") != "selected":
        return
    path = f"repos/{repo}/actions/permissions/selected-actions"
    current = dict(api.get(path) or {})
    have = [str(pattern) for pattern in current.get("patterns_allowed") or []]
    known = {pattern.casefold() for pattern in have}
    added = [
        str(pattern)
        for pattern in selected.get("patterns_allowed") or []
        if str(pattern).casefold() not in known
    ]
    if added:
        api.put(path, {**current, "patterns_allowed": have + added})


def apply_actions(
    api: Api, repo: str, want: dict[str, Json], drift: list[Drift]
) -> None:
    keys = {item.setting.removeprefix("actions.") for item in drift}
    # An action that a workflow runs and the list lacks: the list would stop it.
    lacking = any(item.manual for item in drift)
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
    switch = "allowed_actions" in keys and not lacking
    if switch or "sha_pinning_required" in keys:
        current = api.get(f"repos/{repo}/actions/permissions") or {}
        api.put(
            f"repos/{repo}/actions/permissions",
            {
                "enabled": True,
                "allowed_actions": (
                    want["allowed_actions"]
                    if switch
                    else current.get("allowed_actions", "all")
                ),
                "sha_pinning_required": want.get(
                    "sha_pinning_required", current.get("sha_pinning_required", False)
                ),
            },
        )
    # The list comes after the switch to the selected actions, which it needs.
    if any(key.startswith("selected.") for key in keys):
        if lacking:
            widen_selected_actions(api, repo, want["selected"])
        else:
            api.put(
                f"repos/{repo}/actions/permissions/selected-actions", want["selected"]
            )
    if "fork_pr_approval" in keys:
        api.put(
            f"repos/{repo}/actions/permissions/fork-pr-contributor-approval",
            {"approval_policy": want["fork_pr_approval"]},
        )


def check_actions_config(actions: dict[str, Json]) -> None:
    """Which actions may run, as GitHub knows it."""
    allowed = actions.get("allowed_actions", "all")
    if allowed not in ALLOWED_ACTIONS:
        raise ConfigError(
            f"allowed_actions of [actions] is one of {', '.join(ALLOWED_ACTIONS)},"
            f" not {allowed!r}"
        )
    if allowed == "selected" and not isinstance(actions.get("selected"), dict):
        raise ConfigError(
            'allowed_actions = "selected" needs the table [actions.selected], with'
            " github_owned_allowed, verified_allowed and patterns_allowed, the"
            " actions that may run"
        )


# --------------------------------------------------------------------------- rulesets


# The thresholds of a tool of code scanning: the alerts that stop a merge, by the
# severity of a problem and by that of a security alert.
THRESHOLDS = {
    "alerts_threshold": ("none", "errors", "errors_and_warnings", "all"),
    "security_alerts_threshold": (
        "none",
        "critical",
        "high_or_higher",
        "medium_or_higher",
        "all",
    ),
}


def code_scanning_tools(tools: Iterable[Json]) -> list[dict[str, Json]]:
    """The tools of code scanning whose new alerts stop a merge, each once: a later
    entry of a tool, such as one of repository.project.toml, replaces an earlier one."""
    chosen: dict[str, dict[str, Json]] = {}
    for tool in tools:
        if not (
            isinstance(tool, dict)
            and set(tool) == {"tool", *THRESHOLDS}
            and isinstance(tool["tool"], str)
            and tool["tool"]
            and all(tool[key] in values for key, values in THRESHOLDS.items())
        ):
            raise ConfigError(
                "each entry of code_scanning_tools in [branch] names a tool and its"
                ' thresholds, such as { tool = "CodeQL", alerts_threshold = "errors",'
                ' security_alerts_threshold = "high_or_higher" }, with an'
                f" alerts_threshold of {', '.join(THRESHOLDS['alerts_threshold'])}"
                " and a security_alerts_threshold of"
                f" {', '.join(THRESHOLDS['security_alerts_threshold'])}, not {tool!r}"
            )
        chosen[tool["tool"]] = {
            "tool": tool["tool"],
            **{key: tool[key] for key in THRESHOLDS},
        }
    return list(chosen.values())


def main_ruleset(
    checks: list[str], tools: Iterable[dict[str, Json]] = ()
) -> dict[str, Json]:
    """Pull requests into main: squashed, linear, with every required check passing,
    and without a new alert of a tool of code scanning from the thresholds on."""
    rules: list[Json] = [
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
    ]
    listed = list(tools)
    if listed:
        # GitHub compares the analysis of the pull request with that of main: an alert
        # that main has already stops no merge, and neither does one below the
        # thresholds. A tool that hasn't uploaded its analysis yet stops the merge, so
        # only tools that run on every pull request belong here.
        rules.append(
            {"type": "code_scanning", "parameters": {"code_scanning_tools": listed}}
        )
    return {
        "name": "Protect main",
        "target": "branch",
        "enforcement": "active",
        "bypass_actors": [],
        "conditions": {"ref_name": {"include": ["~DEFAULT_BRANCH"], "exclude": []}},
        "rules": rules,
    }


RELEASE_TAGS = "refs/tags/v*.*.*"
# The rulesets that the settings as code keep, by their names.
MANAGED_RULESETS = ("Protect main", "Release tags", "Release tag creation")


def tag_ruleset() -> dict[str, Json]:
    """Release tags such as v1.2.3 never move; major tags such as v1 may."""
    return {
        "name": "Release tags",
        "target": "tag",
        "enforcement": "active",
        "bypass_actors": [],
        "conditions": {"ref_name": {"include": [RELEASE_TAGS], "exclude": []}},
        "rules": [
            {"type": "deletion"},
            {"type": "non_fast_forward"},
            {"type": "update"},
        ],
    }


def tag_creation_ruleset(app_id: int) -> dict[str, Json]:
    """Only the release app, whose release workflow signs them, creates release tags:
    not the job token of a workflow, nor any other token that may push. A ruleset of
    its own, so that the app may create release tags but, like everyone, never move or
    delete one."""
    return {
        "name": "Release tag creation",
        "target": "tag",
        "enforcement": "active",
        "bypass_actors": [
            {"actor_id": app_id, "actor_type": "Integration", "bypass_mode": "always"}
        ],
        "conditions": {"ref_name": {"include": [RELEASE_TAGS], "exclude": []}},
        "rules": [{"type": "creation"}],
    }


def release_app_id(variables: dict[str, str]) -> int | None:
    """The ID of the release app, from the variable RELEASE_AUTOMATION_APP_ID; None
    without it, and then anyone who may push creates release tags, as before."""
    value = str(variables.get("RELEASE_AUTOMATION_APP_ID", "")).strip()
    if not value:
        return None
    if not value.isdecimal():
        raise ConfigError(
            "RELEASE_AUTOMATION_APP_ID must be the number of the release app, the App ID"
            f" on its settings page, not {value!r}"
        )
    return int(value)


def own_release_app(blueprint: dict[str, str], own: dict[str, str]) -> None:
    """A project that names a release app of its own names its App ID too: with the
    blueprint's App ID, only the blueprint's app could create its release tags, and its
    next release couldn't push its tag."""
    client = "RELEASE_AUTOMATION_CLIENT_ID"
    if (
        "RELEASE_AUTOMATION_APP_ID" in blueprint
        and "RELEASE_AUTOMATION_APP_ID" not in own
        and own.get(client, blueprint.get(client)) != blueprint.get(client)
    ):
        raise ConfigError(
            "repository.project.toml names a release app of its own in"
            " RELEASE_AUTOMATION_CLIENT_ID, but not its App ID; set"
            " RELEASE_AUTOMATION_APP_ID there too, the App ID on the settings page of"
            " the app, so that the app may create the release tags"
        )


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
            elif key == "code_scanning_tools":
                value = sorted(json.dumps(tool, sort_keys=True) for tool in value)
                current = sorted(
                    json.dumps(tool, sort_keys=True) for tool in current or []
                )
            if current != value:
                differences.append(f"{rule['type']}.{key}")
    differences += [f"rule {kind} is extra" for kind in have_rules]
    return differences


def rulesets(api: Api, repo: str) -> dict[str, Json]:
    return {item["name"]: item["id"] for item in api.get_all(f"repos/{repo}/rulesets")}


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
    # A ruleset of the settings as code that the configuration no longer wants, such
    # as Release tag creation once the App ID is gone, goes; others stay.
    names = {want["name"] for want in wanted}
    drift += [
        Drift(f"ruleset {name}", "absent", "present")
        for name in existing
        if name in MANAGED_RULESETS and name not in names
    ]
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
    unwanted = names - {want["name"] for want in wanted}
    for name, number in existing.items():
        if name in unwanted:
            api.delete(f"repos/{repo}/rulesets/{number}")


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
            names = sorted(
                f"{item.get('type', 'branch')}:{item['name']}"
                for item in branch_policies(api, repo, name)
            )
            if policy != MAIN_ONLY or names != ["branch:main"]:
                drift.append(Drift(f"environment {name}", "main only", names or policy))
        present = {
            item["name"]
            for item in api.get_all(f"repos/{repo}/environments/{name}/secrets")
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


def branch_policies(api: Api, repo: str, environment: str) -> list[Json]:
    """The branches and tags from which an environment may be used."""
    return api.get_all(
        f"repos/{repo}/environments/{environment}/deployment-branch-policies"
    )


def apply_environments(api: Api, repo: str, drift: list[Drift]) -> None:
    for item in drift:
        name = item.setting.removeprefix("environment ")
        api.put(
            f"repos/{repo}/environments/{name}", {"deployment_branch_policy": MAIN_ONLY}
        )
        policies = branch_policies(api, repo, name)
        for policy in policies:
            if (policy.get("type", "branch"), policy["name"]) != ("branch", "main"):
                api.delete(
                    f"repos/{repo}/environments/{name}/deployment-branch-policies/{policy['id']}"
                )
        if not any(
            (policy.get("type", "branch"), policy["name"]) == ("branch", "main")
            for policy in policies
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


def labels(api: Api, repo: str) -> dict[str, Json]:
    """The labels of the repository, by their names in lower case: GitHub ignores the
    case of a name, so Bug is the label bug in another case."""
    return {
        item["name"].casefold(): item for item in api.get_all(f"repos/{repo}/labels")
    }


def check_labels(api: Api, repo: str, want: dict[str, dict[str, str]]) -> list[Drift]:
    """Every label of .github/labels.toml, with its name, color and description.

    The Labels workflow keeps them current later; a new repository needs them before
    its first pull request, whose title check sets one.
    """
    have = labels(api, repo)
    drift = []
    for name, label in want.items():
        current = have.get(name.casefold())
        wanted = f"#{label['color'].lower()} {label['description']}"
        if current is None:
            drift.append(Drift(f"label {name}", wanted, None))
            continue
        found = f"#{current['color'].lower()} {current.get('description') or ''}"
        if current["name"] != name:
            # The name in another case: renamed, which the drift shows.
            drift.append(
                Drift(f"label {name}", f"{name} {wanted}", f"{current['name']} {found}")
            )
        elif found != wanted:
            drift.append(Drift(f"label {name}", wanted, found))
    return drift


def apply_labels(
    api: Api, repo: str, want: dict[str, dict[str, str]], drift: list[Drift]
) -> None:
    have = labels(api, repo)
    for item in drift:
        name = item.setting.removeprefix("label ")
        body = {
            "name": name,
            "color": want[name]["color"].lower(),
            "description": want[name]["description"],
        }
        current = have.get(name.casefold())
        if current is None:
            try:
                api.post(f"repos/{repo}/labels", body)
                continue
            except GitHubError as error:
                # Created a moment ago, such as by the Labels workflow when a push
                # starts both: then it is updated like any other.
                if "already_exists" not in error.codes:
                    raise
            current = {"name": name}
        path = urllib.parse.quote(current["name"], safe="")
        # GitHub renames a label, such as Bug to bug, through new_name.
        update = {"new_name": name, **{k: v for k, v in body.items() if k != "name"}}
        api.patch(f"repos/{repo}/labels/{path}", update)


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
    # The actions and reusable workflows that the workflows name, with the file of each.
    workflows: dict[str, str]

    @classmethod
    def load(cls, path: Path, root: Path | None = None) -> Settings:
        """The settings in path, by default .github/repository.toml of the repository
        at root, whose workflows they let run."""
        config = tomllib.loads(path.read_text(encoding="utf-8"))
        # The settings of this project alone, on top of those the blueprint keeps
        # current: its own checks, environments, secrets and variables.
        project = path.with_name("repository.project.toml")
        if project.exists():
            own = tomllib.loads(project.read_text(encoding="utf-8"))
            own_release_app(config.get("variables", {}), own.get("variables", {}))
            config = merge(config, own)
        branch = config.get("branch", {})
        checks = branch.get("required_checks", [])
        tools = code_scanning_tools(branch.get("code_scanning_tools", []))
        actions = config.get("actions", {})
        check_actions_config(actions)
        variables = config.get("variables", {})
        rulesets = [main_ruleset(checks, tools), tag_ruleset()]
        app_id = release_app_id(variables)
        if app_id is not None:
            rulesets.append(tag_creation_ruleset(app_id))
        return cls(
            repository=config.get("repository", {}),
            security=config.get("security", {}),
            actions=actions,
            rulesets=rulesets,
            variables=variables,
            environments=config.get("environments", {}),
            pages=config.get("pages", {}),
            # The labels of the issue assistant, next to the settings.
            labels=load_labels(path.parent / "labels.toml"),
            workflows=workflow_references(path.parent.parent if root is None else root),
        )


def check(api: Api, repo: str, settings: Settings) -> list[Drift]:
    return (
        check_repository(api, repo, settings.repository)
        + check_security(api, repo, settings.security)
        + check_actions(api, repo, settings.actions, settings.workflows)
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
            refused.append(
                f"the {name} could not be applied: {error}{hint(name, error)}"
            )
    return refused


def hint(part: str, error: GitHubError) -> str:
    """What a refusal of GitHub likely means. GitHub answers 403, and for some changes
    404, to a token without the permission."""
    if error.rate_limited:
        return "; that is the rate limit of GitHub; the next run tries again"
    if error.status in (403, 404):
        return (
            f"; the token may lack the permission {PERMISSIONS[part]} (read and write)"
        )
    return ""


def describe(drift: list[Drift]) -> str:
    lines = []
    for item in drift:
        line = f"  {item.setting}: GitHub has {json.dumps(item.have)}, the configuration wants {json.dumps(item.want)}"
        if item.manual:
            line += f"\n      {item.manual}"
        lines.append(line)
    return "\n".join(lines)
