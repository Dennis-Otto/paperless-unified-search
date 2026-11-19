"""The settings of a repository as code: where GitHub differs from
.github/repository.toml, and how it comes to match."""

from __future__ import annotations

import json
import re
import tomllib
import urllib.parse
from collections.abc import Callable, Iterable, Sequence
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Any, NoReturn

from blueprint.github import Api, GitHubError, Json
from blueprint.outage import check_unavailable

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
    # GitHub doesn't do it for the repository, whatever the settings ask: named, with
    # the line that stops asking for it, but it fails nothing.
    notice: bool = False


class ConfigError(ValueError):
    """A value in the settings as code that can't be right."""


class Refused(GitHubError):
    """GitHub refused some of the items of a part of the settings, such as one
    ruleset, and the others were applied all the same: what happened to each, by its
    name, and each refusal, the first of which gives the status and the message that
    the hints read. present names the rulesets that GitHub has after them."""

    def __init__(
        self,
        outcomes: list[tuple[str, str]],
        errors: list[GitHubError],
        present: frozenset[str] | None = None,
    ) -> None:
        first = errors[0]
        super().__init__(first.status, first.message, first.codes, first.details)
        self.outcomes = outcomes
        self.errors = errors
        self.present = present


class Attempts:
    """The requests of the items of one part of the settings, each by itself: a
    refusal of GitHub is kept for the end, so that it keeps no other item from being
    applied. A rate limit or an outage of GitHub ends the part at once, as every
    other request would meet it too, and still tells the refusals before it."""

    def __init__(self, part: str) -> None:
        self.part = part
        self.outcomes: list[tuple[str, str]] = []
        self.errors: list[GitHubError] = []

    def send(
        self, item: str, done: str, request: Callable[..., None], *arguments: Any
    ) -> bool:
        """Whether GitHub took the request of item, which says done when it did."""
        try:
            request(*arguments)
        except GitHubError as error:
            if error.rate_limited or error.unavailable:
                self.stop(item, error)
            self.errors.append(error)
            self.outcomes.append((item, f"refused: {error}"))
            return False
        self.outcomes.append((item, done))
        return True

    def stop(self, item: str, error: GitHubError) -> NoReturn:
        """Ends the part at the rate limit or the outage of GitHub that item met. The
        refusals before it are told with it, which the next run would otherwise
        only meet again; what GitHub has after it is unknown, so present stays
        None. Without a refusal before it, the error itself ends the part, with its
        hint."""
        if not self.errors:
            raise error
        self.errors.append(error)
        self.outcomes.append((item, f"stopped: {error}"))
        raise self.refused(None)

    def finish(self, present: frozenset[str] | None = None) -> None:
        """Refused, with the hint of each refusal, when GitHub refused an item."""
        if self.errors:
            raise self.refused(present)

    def refused(self, present: frozenset[str] | None) -> Refused:
        """What happened to each item, with the hint of each refusal; a stop says
        too that nothing after it was sent."""
        hints = iter([hint(self.part, error) for error in self.errors])
        outcomes = []
        for item, what in self.outcomes:
            if what.startswith("refused"):
                what += next(hints)
            elif what.startswith("stopped"):
                what += f"{next(hints)}; nothing after it was sent"
            outcomes.append((item, what))
        return Refused(outcomes, self.errors, present)


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
# The switches that the plan of a repository decides: GitHub accepts their write and
# keeps them off where the plan lacks them, as on a repository of a user. Secret
# scanning and its push protection are not among them: when GitHub keeps one of those
# off, the run fails.
PLAN_GATED = ("secret_scanning_non_provider_patterns",)
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
        if current == UNOFFERED:
            if value:
                manual = (
                    f"GitHub doesn't offer {key} for {repo}; set {key} = false under"
                    " [security] in .github/repository.project.toml to stop asking"
                    " for it"
                )
                drift.append(
                    Drift(f"security.{key}", value, current, manual, notice=True)
                )
        elif current != value:
            drift.append(Drift(f"security.{key}", value, current))
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


def gated(private: bool) -> tuple[str, ...]:
    """The switches of security_and_analysis that the plan of a repository decides:
    on a private one every switch of secret scanning, which GitHub offers it only with
    GitHub Secret Protection (decision 0082)."""
    return ANALYSIS if private else PLAN_GATED


def kept(
    repo: str, sent: list[Drift], have: list[Drift], private: bool = False
) -> list[Drift]:
    """The drift after an apply that sent `sent`, in which a switch of
    security_and_analysis that depends on the plan and that GitHub accepted but kept
    as it was is a notice.

    GitHub answers the write of a switch that the plan of the repository lacks with
    success and leaves the switch as it was, as it keeps the generic patterns of secret
    scanning off on a repository of a user. Only a write shows it; GitHub names such a
    switch as off, like one that is merely switched off.
    """
    tried = {
        item.setting
        for item in sent
        if item.setting.removeprefix("security.") in gated(private) and not item.notice
    }
    result = []
    for item in have:
        if item.setting in tried and not item.notice:
            key = item.setting.removeprefix("security.")
            state = "on" if item.have else "off"
            item = replace(
                item,
                notice=True,
                manual=(
                    f"GitHub accepted {key} = {json.dumps(item.want)} for {repo} but"
                    f" keeps it {state}, likely because the plan of the repository lacks"
                    " the switch;"
                    f" set {key} = {json.dumps(item.have)} under [security] in"
                    " .github/repository.project.toml to stop asking for it"
                ),
            )
        result.append(item)
    return result


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
# A step that runs an image of Docker. GitHub counts it as an action, which it refuses
# unless every action may run, and no pattern of [actions.selected] names an image.
DOCKER = "docker://"


def references(text: str) -> list[str]:
    """The actions and reusable workflows that a workflow or an action names, and the
    images of Docker that its steps run, which GitHub counts as actions: not those of
    the repository itself, which every policy allows."""
    return [
        reference for reference in USES.findall(text) if not reference.startswith("./")
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
    with ! in front blocks what it names, also an action of GitHub. No pattern lets an
    image of Docker run, not even *."""
    if reference.startswith(DOCKER):
        return False
    patterns = [str(pattern) for pattern in selected.get("patterns_allowed") or []]
    if any(
        matches(pattern[1:], reference) for pattern in patterns if pattern[:1] == "!"
    ):
        return False
    owner = reference.casefold().split("/", 1)[0]
    if selected.get("github_owned_allowed") and owner in GITHUB_OWNERS:
        return True
    return any(matches(pattern, reference) for pattern in patterns)


# The actions and reusable workflows whose files one query of GraphQL reads: GitHub
# counts such a query as one request, where its contents API took one for each file,
# about 40 for a repository of the blueprint, of the 1,000 in an hour that the token of
# a workflow has for every workflow of the repository.
BATCH = 50
# The file of each action or reusable workflow, which the query reads as text.
BLOB = "... on Blob { text }"


@dataclass(frozen=True)
class Runs:
    """Every action and reusable workflow that the workflows run: those that the
    workflows name, and those that these run in turn."""

    # Each, with what names it: the file of the repository, or the action or reusable
    # workflow that runs it in turn.
    found: dict[str, str]
    # Each, with the one that a file of the repository names and that runs it, at last.
    via: dict[str, str]
    # Those whose file GitHub refused to give, with its answer: what they run in turn
    # is unknown.
    refused: dict[str, str]
    # The error of GitHub when it kept failing itself, as in an outage of GitHub or of
    # the network of the runner: what the actions run in turn is unknown, and no
    # further query asks, as each would only wait and fail as well. None when GitHub
    # answered every query.
    unavailable: GitHubError | None = None


def action_files(reference: str) -> tuple[str, str, list[str]]:
    """The owner and the name of the repository of an action or a reusable workflow,
    and each place of its file at the commit that it is pinned to, as Git names it."""
    name, _, ref = reference.partition("@")
    owner, _, rest = name.partition("/")
    repo, _, path = rest.partition("/")
    if path.endswith((".yml", ".yaml")):
        candidates = [path]
    else:
        candidates = [
            f"{path}/{file}".lstrip("/") for file in ("action.yml", "action.yaml")
        ]
    return owner, repo, [f"{ref}:{candidate}" for candidate in candidates]


def files_query(references: Sequence[str]) -> tuple[str, dict[str, str]]:
    """The query of GraphQL of the files of the actions and reusable workflows, the
    repository of each as r0, r1, ... and each place of its file as f0, f1, ...: the
    names come as variables, never as text of the query."""
    variables: dict[str, str] = {}
    fields = []
    for index, reference in enumerate(references):
        owner, repo, places = action_files(reference)
        variables[f"o{index}"], variables[f"n{index}"] = owner, repo
        files = []
        for number, place in enumerate(places):
            variables[f"e{index}_{number}"] = place
            files.append(
                f"f{number}: object(expression: $e{index}_{number}) {{ {BLOB} }}"
            )
        fields.append(
            f"r{index}: repository(owner: $o{index}, name: $n{index})"
            f" {{ {' '.join(files)} }}"
        )
    declared = ", ".join(f"${key}: String!" for key in variables)
    return f"query({declared}) {{ {' '.join(fields)} }}", variables


def graphql_problems(answer: Json) -> dict[str, str]:
    """Why GraphQL refused a part of a query, by the alias of the part, or by "" for
    the whole query. A repository or a file that GitHub doesn't know is no refusal: the
    contents API answers 404 to both, and an action that isn't there runs nothing."""
    problems: dict[str, str] = {}
    errors = answer.get("errors") if isinstance(answer, dict) else None
    for error in errors if isinstance(errors, list) else []:
        if not isinstance(error, dict) or error.get("type") == "NOT_FOUND":
            continue
        place = error.get("path") or [""]
        kind = error.get("type") or "an error"
        problems.setdefault(
            str(place[0]), f"GitHub answered {kind}: {error.get('message', '')}"
        )
    return problems


def file_text(found: Json, places: int) -> str:
    """The text of the first file of an action that GitHub has, or none."""
    if not isinstance(found, dict):
        return ""
    for number in range(places):
        blob = found.get(f"f{number}")
        if isinstance(blob, dict) and isinstance(blob.get("text"), str):
            return str(blob["text"])
    return ""


def run_by(
    api: Api, wanted: Sequence[str]
) -> tuple[dict[str, list[str]], dict[str, str]]:
    """What each action and reusable workflow runs in turn, at the commit that it is
    pinned to: GitHub allows or blocks those as well; and those whose file GitHub
    refused to give, with its answer. One query of GraphQL reads the files of BATCH of
    them. An image of Docker runs none, and GitHub has no file of it. GitHubError when
    GitHub kept failing itself: that says nothing of the actions."""
    inner: dict[str, list[str]] = {}
    refused: dict[str, str] = {}
    files = []
    for reference in wanted:
        if reference.startswith(DOCKER):
            inner[reference] = []
        else:
            files.append(reference)
    for start in range(0, len(files), BATCH):
        batch = files[start : start + BATCH]
        query, variables = files_query(batch)
        try:
            # A query only reads: a gateway that timed out is asked once more.
            answer = api.request(
                "POST", "graphql", {"query": query, "variables": variables}, retry=True
            ).data
        except GitHubError as error:
            if error.unavailable:
                raise
            refused.update(dict.fromkeys(batch, str(error)))
            continue
        problems = graphql_problems(answer)
        data = answer.get("data") if isinstance(answer, dict) else None
        data = data if isinstance(data, dict) else {}
        for index, reference in enumerate(batch):
            found = data.get(f"r{index}")
            problem = problems.get(f"r{index}") or (
                problems.get("") if found is None else None
            )
            if problem:
                refused[reference] = problem
                continue
            places = len(action_files(reference)[2])
            inner[reference] = references(file_text(found, places))
    return inner, refused


def actions_that_run(api: Api, named: dict[str, str]) -> Runs:
    """Every action and reusable workflow that the workflows run: those that the
    workflows name, and those that these run in turn, read one level after another."""
    found = dict(named)
    via = {reference: reference for reference in named}
    refused: dict[str, str] = {}
    todo = sorted(named)
    while todo:
        try:
            inner, unread = run_by(api, todo)
        except GitHubError as error:
            # After every try of the request: another query would fail as well.
            return Runs(found, via, refused, error)
        refused.update(unread)
        todo = []
        for reference, items in sorted(inner.items()):
            for item in items:
                if item not in found:
                    found[item] = reference.partition("@")[0]
                    via[item] = via[reference]
                    todo.append(item)
    return Runs(found, via, refused)


def lacking_patterns(selected: dict[str, Json], found: Iterable[str]) -> dict[str, str]:
    """The pattern of each action and reusable workflow that runs but that
    [actions.selected] doesn't allow, with the first one that needs it. An image of
    Docker needs a step of its own, not a pattern."""
    lacking: dict[str, str] = {}
    for reference in sorted(found):
        if not reference.startswith(DOCKER) and not allows(selected, reference):
            lacking.setdefault(pattern_of(reference), reference)
    return lacking


@dataclass(frozen=True)
class Unlisted:
    """An action, a reusable workflow or an image of Docker that a workflow runs but
    that GitHub won't start, or one whose file GitHub refused to give."""

    reference: str
    # What names it: a file of the repository, or the action that runs it in turn.
    where: str
    # The one that a file of the repository names and that runs it, at last.
    via: str
    # What to change.
    fix: str


def unlisted_actions(
    api: Api, actions: dict[str, Json], named: dict[str, str], listed_in: str
) -> tuple[list[Unlisted], GitHubError | None]:
    """Every action and reusable workflow that the workflows run, also in turn, that
    [actions] doesn't let start, from the files alone, not the settings on GitHub: a
    pattern that a pull request leaves out stops every workflow that runs the action
    once the list is on GitHub. The patterns go into the file listed_in. With them,
    the error of GitHub when it kept failing, so that what the actions run in turn is
    unknown, or None."""
    allowed = actions.get("allowed_actions", "all")
    if allowed == "all":
        return [], None
    selected = actions.get("selected") if allowed == "selected" else None
    selected = selected if isinstance(selected, dict) else {}
    runs = actions_that_run(api, named)
    found: list[Unlisted] = []
    for pattern, reference in lacking_patterns(selected, runs.found).items():
        fix = (
            f"add {json.dumps(pattern)} to patterns_allowed of [actions.selected]"
            f" in {listed_in}"
        )
        if allowed != "selected":
            fix = f'set allowed_actions = "selected" in [actions] and {fix}'
        found.append(
            Unlisted(reference, runs.found[reference], runs.via[reference], fix)
        )
    found += [
        Unlisted(
            reference,
            runs.found[reference],
            runs.via[reference],
            "run the image in a run: step instead, as docker run --rm --volume"
            ' "$PWD:/github/workspace" --workdir /github/workspace'
            f" {reference.removeprefix(DOCKER)}: GitHub refuses a step with docker://"
            " whatever [actions.selected] lists",
        )
        for reference in sorted(runs.found)
        if reference.startswith(DOCKER)
    ]
    found += [
        Unlisted(
            reference,
            runs.found[reference],
            runs.via[reference],
            f"GitHub refused its file ({answer}), so what it runs in turn is unknown:"
            " run the check again",
        )
        for reference, answer in sorted(runs.refused.items())
    ]
    return found, runs.unavailable


def line_of(text: str, reference: str) -> int:
    """The line of the first step or job that names the action, or 0."""
    for match in USES.finditer(text):
        if match[1] == reference:
            return text.count("\n", 0, match.start(1)) + 1
    return 0


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
        if key not in ("selected", TARGET) and have.get(key) != value
    ]
    selected = want.get("selected")
    if want.get("allowed_actions") == "selected" and isinstance(selected, dict):
        active = have["allowed_actions"] == "selected"
        drift += check_selected_actions(api, repo, selected, active, named or {})
    elif want.get("allowed_actions") == "local_only":
        drift += refused_images(named or {})
    return drift


def refused_images(found: dict[str, str]) -> list[Drift]:
    """Every image of Docker that a step runs with docker://, which GitHub refuses
    unless every action may run. No pattern allows it, so the step itself changes: it
    runs the image with docker run. Until then, the actions that may run on GitHub
    never narrow, which would stop the workflow."""
    return [
        Drift(
            f"actions.allowed_actions refuses {reference}",
            "docker run",
            DOCKER,
            manual=(
                f"{where} runs it, and GitHub refuses a step with docker:// unless"
                " every action may run, whatever [actions.selected] lists: run the"
                " image in a run: step instead, as docker run --rm --volume"
                ' "$PWD:/github/workspace" --workdir /github/workspace'
                f" {reference.removeprefix(DOCKER)} with the step's with: args after"
                " it and its entrypoint as --entrypoint; until then the actions that"
                " may run on GitHub never narrow"
            ),
        )
        for reference, where in sorted(found.items())
        if reference.startswith(DOCKER)
    ]


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
    runs = actions_that_run(api, named)
    found, refused = runs.found, runs.refused
    drift += [
        Drift(
            f"actions.selected.patterns_allowed lacks {pattern}",
            "listed",
            "missing",
            manual=(
                f"{found[reference]} runs it: add {json.dumps(pattern)} to"
                " patterns_allowed of [actions.selected] in"
                " .github/repository.project.toml; until then the list of the allowed"
                " actions on GitHub only grows"
            ),
        )
        for pattern, reference in lacking_patterns(selected, found).items()
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
    if runs.unavailable:
        drift.append(
            Drift(
                "actions.selected.patterns_allowed unknown",
                "read",
                "unavailable",
                manual=check_unavailable(
                    runs.unavailable,
                    "read what the actions run in turn, and the list of the allowed"
                    " actions on GitHub only grows until it does",
                ),
            )
        )
    return drift + refused_images(found)


def widen_selected_actions(api: Api, repo: str, selected: dict[str, Json]) -> list[str]:
    """While a workflow runs an action that the list lacks, a list that GitHub already
    uses only grows by the configured patterns: that never stops a workflow, and the
    actions of a newer release of the blueprint can still start. The patterns that it
    added, none when GitHub lets every action run."""
    permissions = api.get(f"repos/{repo}/actions/permissions") or {}
    if permissions.get("allowed_actions") != "selected":
        return []
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
    return added


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
    listed = actions.get(TARGET, [])
    if not isinstance(listed, list) or not all(
        isinstance(path, str) and WORKFLOW_PATH.fullmatch(path) for path in listed
    ):
        raise ConfigError(
            f"{TARGET} of [actions] lists the workflows that may start on it, each by"
            f' its path, such as ".github/workflows/welcome.yml", not {listed!r}'
        )


# --------------------------------------------------------------------------- policies

# The event that GitHub blocks in a public repository from 2 December 2026, unless an
# Actions event policy allows it (decision 0138). A workflow that starts on it runs as
# the default branch has it, with a token that may write and the secrets, also for a
# pull request of a fork. TARGET of [actions] lists the workflows that may.
TARGET = "pull_request_target"
# The path of a workflow, as the condition of a policy names it.
WORKFLOW_PATH = re.compile(r"\.github/workflows/[^/]+\.ya?ml")
# The policy of the settings as code, by its name; the others stay as they are.
EVENT_POLICY = "Allow pull_request_target"
# Its line in the differences, and the start of those that name a workflow.
POLICY = f"policy {TARGET}"
# The key on at the top level of a workflow, also quoted, and what follows it.
ON = re.compile(r"""(?:on|"on"|'on')[ \t]*:(.*)""")
# A key of the table below on, or an item of a list of events.
EVENT = re.compile(r"""(?:-[ \t]+)?["']?([A-Za-z_]+)""")
# A comment at the end of a line, or a whole line of one.
COMMENT = re.compile(r"(?:^|[ \t])#.*")


def flow_events(value: str) -> list[str]:
    """The events of on in the flow style of YAML: the names of a list in brackets,
    or the keys of a table in braces, at its first level."""
    table = value.startswith("{")
    entries: list[str] = []
    entry, depth = "", 0
    for char in value[1:]:
        if char in "]}" and depth == 0:
            break
        if char == "," and depth == 0:
            entries.append(entry)
            entry = ""
            continue
        depth += (char in "[{") - (char in "]}")
        entry += char
    entries.append(entry)
    names = [entry.split(":", 1)[0] if table else entry for entry in entries]
    return [name.strip().strip("\"'") for name in names if name.strip()]


def flow_depth(value: str) -> int:
    """How many brackets and braces of value are still open."""
    return sum(value.count(char) for char in "[{") - sum(
        value.count(char) for char in "]}"
    )


def workflow_events(text: str) -> list[str]:
    """The events that start a workflow, from the key on at its top level: one name,
    a list or a table of them in the flow style of YAML, or a table or a list below
    the key. The tests compare it with PyYAML for every workflow of every kind."""
    lines = [COMMENT.sub("", line).rstrip() for line in text.splitlines()]
    for index, line in enumerate(lines):
        match = ON.fullmatch(line)
        if match is None:
            continue
        value = match[1].strip()
        if value[:1] in ("[", "{"):
            # A list or a table in the flow style may go on over the next lines.
            for below in lines[index + 1 :]:
                if flow_depth(value) <= 0:
                    break
                value += " " + below.strip()
            return flow_events(value)
        if value:
            return [value.strip("\"'")]
        events: list[str] = []
        indent: int | None = None
        listed = False
        for below in lines[index + 1 :]:
            if not below:
                continue
            depth = len(below) - len(below.lstrip())
            item = below.lstrip().startswith("-")
            # A list may stand as far in as on itself; any other key ends the table.
            if depth == 0 and not item:
                break
            if indent is None:
                # The first entry decides: a list of names, or a table of them.
                indent, listed = depth, item
            found = EVENT.match(below.strip())
            # In a table, a list as far in as its keys is the value of the key above.
            if depth == indent and item == listed and found:
                events.append(found[1])
        return events
    return []


def workflow_triggers(root: Path) -> dict[str, list[str]]:
    """The events that start each workflow of the repository at root, by its path."""
    return {
        path.relative_to(root).as_posix(): workflow_events(
            path.read_text(encoding="utf-8")
        )
        for path in sorted((root / ".github/workflows").glob("*.y*ml"))
    }


def target_workflows(triggers: dict[str, list[str]]) -> list[str]:
    """The workflows that start on pull_request_target."""
    return sorted(path for path, events in triggers.items() if TARGET in events)


def unlisted_targets(
    listed: Sequence[str], triggers: dict[str, list[str]]
) -> list[str]:
    """The workflows that start on pull_request_target but that TARGET of [actions]
    lacks: GitHub blocks them in a public repository."""
    return [path for path in target_workflows(triggers) if path not in listed]


def event_policy(
    listed: Sequence[str], triggers: dict[str, list[str]]
) -> dict[str, Json] | None:
    """The Actions event policy that lets the listed workflows start on
    pull_request_target, and no other. An event rule allows only the events that it
    names, so it names every event that starts one of them as well; None when no
    listed workflow starts on pull_request_target, as then nothing needs it."""
    files = [path for path in target_workflows(triggers) if path in listed]
    if not files:
        return None
    events = sorted({event for path in files for event in triggers[path]})
    return {
        "name": EVENT_POLICY,
        "enforcement": "active",
        "conditions": {"workflow_path": {"include": files, "exclude": []}},
        "rules": [
            {
                "type": "restrict_action_events",
                "parameters": {"allowed_events": events},
            }
        ],
    }


def own_policies(api: Api, repo: str) -> dict[str, int]:
    """The Actions policies of the repository itself, by name, with their ids: not
    those of an organization or an enterprise above it."""
    path = f"repos/{repo}/actions/policies?has_parents=false&per_page=100"
    data = api.get(path) or {}
    return {item["name"]: item["id"] for item in data.get("policies") or []}


def policy_differences(want: dict[str, Json], have: dict[str, Json]) -> list[str]:
    """The parts in which the policy on GitHub differs from the wanted one."""
    differences = []
    if have.get("enforcement") != want["enforcement"]:
        differences.append(f"enforcement {have.get('enforcement')}")
    path = (have.get("conditions") or {}).get("workflow_path") or {}
    wanted = want["conditions"]["workflow_path"]
    for key, name in (("include", "workflows"), ("exclude", "excluded workflows")):
        found = sorted(path.get(key) or [])
        if found != wanted[key]:
            differences.append(f"{name} {found}")
    rules = have.get("rules") or []
    events = (rules[0].get("parameters") or {}).get("allowed_events") if rules else []
    if [rule.get("type") for rule in rules] != ["restrict_action_events"]:
        differences.append(f"rules {[rule.get('type') for rule in rules]}")
    elif sorted(events or []) != want["rules"][0]["parameters"]["allowed_events"]:
        differences.append(f"events {sorted(events or [])}")
    return differences


def check_policies(
    api: Api,
    repo: str,
    listed: Sequence[str],
    triggers: dict[str, list[str]],
    private: bool = False,
) -> list[Drift]:
    """The Actions event policy that allows pull_request_target for the listed
    workflows, and every workflow that starts on it but that the list lacks: the
    policy leaves it out, so GitHub blocks it in a public repository."""
    drift = [
        Drift(
            f"{POLICY} lacks {path}",
            "listed",
            "missing",
            manual=(
                f"{path} starts on {TARGET}, which GitHub blocks in a public"
                " repository from 2 December 2026 unless a policy allows it: start it"
                " on pull_request where it needs neither a token that writes nor a"
                f" secret for a pull request of a fork, or add {json.dumps(path)} to"
                f" {TARGET} of [actions] in .github/repository.project.toml"
            ),
        )
        for path in unlisted_targets(listed, triggers)
    ]
    want = event_policy(listed, triggers)
    try:
        existing = own_policies(api, repo)
        number = existing.get(EVENT_POLICY)
        have = (
            api.get(f"repos/{repo}/actions/policies/{number}") or {}
            if want is not None and number is not None
            else {}
        )
    except GitHubError as error:
        if private and refused_by_plan(error):
            # A private repository on GitHub Free has no policies, and GitHub
            # blocks pull_request_target only in a public one.
            if want is None:
                return drift
            return [*drift, lacking(repo, Drift(POLICY, "present", UNOFFERED), error)]
        # Any other refusal, such as one of a token without the permission, names
        # the part and fails the run, but keeps the other parts from being skipped:
        # apply reads the policies again before it writes one.
        return [
            *drift,
            Drift(
                POLICY,
                "present" if want is not None else "absent",
                "unreadable",
                manual=f"GitHub refused the policies: {error}{hint('policies', error)}",
            ),
        ]
    if want is None:
        return drift + (
            [Drift(POLICY, "absent", "present")] if EVENT_POLICY in existing else []
        )
    if number is None:
        return [*drift, Drift(POLICY, "present", "missing")]
    differences = policy_differences(want, have)
    if differences:
        drift.append(Drift(POLICY, "as configured", "; ".join(differences)))
    return drift


def apply_policies(
    api: Api,
    repo: str,
    listed: Sequence[str],
    triggers: dict[str, list[str]],
    drift: list[Drift],
) -> None:
    """The policy as the settings want it: created, changed, or deleted once no
    listed workflow starts on pull_request_target. A workflow that the list lacks
    needs a person, who decides whether it may start on the event."""
    if not any(item.setting == POLICY and not item.notice for item in drift):
        return
    want = event_policy(listed, triggers)
    number = own_policies(api, repo).get(EVENT_POLICY)
    if want is None:
        if number is not None:
            api.delete(f"repos/{repo}/actions/policies/{number}")
    elif number is not None:
        api.put(f"repos/{repo}/actions/policies/{number}", want)
    else:
        api.post(f"repos/{repo}/actions/policies", want)


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
REVIEWS_RULESET = "Reviews of main"
# The rulesets that the settings as code keep, by their names.
MANAGED_RULESETS = (
    "Protect main",
    REVIEWS_RULESET,
    "Release tags",
    "Release tag creation",
)
# The variable that tells the bots after the checks whether main wants reviews, so that
# a pull request of a bot whose checks passed starts the branch bot, which merges it
# past them: "on" or "off", by [branch], unless [variables] sets it.
REVIEWS_VARIABLE = "REVIEWS_OF_MAIN"
# Where GitHub looks for the code owners, in this order.
CODEOWNERS = (".github/CODEOWNERS", "CODEOWNERS", "docs/CODEOWNERS")
# The drift of a review of the code owners that GitHub has no file for.
CODE_OWNERS = f"code owners of {BRANCH}"
# The most approving reviews that a ruleset can require.
MOST_APPROVALS = 10


@dataclass(frozen=True)
class Reviews:
    """What [branch] wants of the reviews of a change of a person."""

    approvals: int = 0
    code_owners: bool = False

    @property
    def wanted(self) -> bool:
        return self.approvals > 0 or self.code_owners


def branch_reviews(branch: dict[str, Json], root: Path) -> Reviews:
    """The reviews that required_approvals and code_owner_review of [branch] want; the
    code owners only with a file that names them, as GitHub requires no review of a
    code owner where it finds none."""
    approvals = branch.get("required_approvals", 0)
    if (
        isinstance(approvals, bool)
        or not isinstance(approvals, int)
        or not 0 <= approvals <= MOST_APPROVALS
    ):
        raise ConfigError(
            "required_approvals in [branch] is a whole number from 0 to"
            f" {MOST_APPROVALS}, the approving reviews of a change, not {approvals!r}"
        )
    code_owners = branch.get("code_owner_review", False)
    if not isinstance(code_owners, bool):
        raise ConfigError(
            f"code_owner_review in [branch] is true or false, not {code_owners!r}"
        )
    if code_owners and not any((root / name).is_file() for name in CODEOWNERS):
        raise ConfigError(
            "code_owner_review in [branch] needs the code owners in"
            f" {', '.join(CODEOWNERS)}: without the file, GitHub requires no review of"
            " a code owner"
        )
    return Reviews(approvals, code_owners)


def reviews_ruleset(reviews: Reviews, app_id: int | None) -> dict[str, Json]:
    """The reviews that a change of a person needs before it merges into main, in a
    ruleset of its own: the release app may merge a pull request past it, which the
    branch bot does for the green pull requests of the bots, while every rule of
    *Protect main*, such as the required checks, still holds for the app."""
    return {
        "name": REVIEWS_RULESET,
        "target": "branch",
        "enforcement": "active",
        "bypass_actors": (
            []
            if app_id is None
            else [
                {
                    "actor_id": app_id,
                    "actor_type": "Integration",
                    "bypass_mode": "pull_request",
                }
            ]
        ),
        "conditions": {"ref_name": {"include": ["~DEFAULT_BRANCH"], "exclude": []}},
        "rules": [
            {
                "type": "pull_request",
                "parameters": {
                    "allowed_merge_methods": ["squash"],
                    # A push after an approval needs a new one, of someone other than
                    # the person who pushed.
                    "dismiss_stale_reviews_on_push": True,
                    "require_code_owner_review": reviews.code_owners,
                    "require_last_push_approval": True,
                    "required_approving_review_count": reviews.approvals,
                    "required_review_thread_resolution": True,
                },
            }
        ],
    }


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


# The secret of the private key of the release app, in the environment release.
RELEASE_KEY = "RELEASE_AUTOMATION_PRIVATE_KEY"


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


def required_check(check: dict[str, Json]) -> str:
    """A required status check as its name and the app that must report it, which is
    null when any source may."""
    return json.dumps([check["context"], check.get("integration_id")])


def ruleset_differences(
    want: dict[str, Json], have: dict[str, Json]
) -> dict[str, tuple[Json, Json]]:
    """What differs between two rulesets, in the parts this tool manages: by its key,
    such as pull_request.required_approving_review_count, what the configuration
    wants and what GitHub has. A token that may only read the administration, such as
    the release app's, gets the rulesets without bypass_actors; then nothing can be
    said about them."""
    differences: dict[str, tuple[Json, Json]] = {
        key: (want[key], have.get(key))
        for key in ("target", "enforcement", "bypass_actors", "conditions")
        if want[key] != have.get(key)
        and not (key == "bypass_actors" and key not in have)
    }
    have_rules = {
        rule["type"]: rule.get("parameters", {}) for rule in have.get("rules", [])
    }
    for rule in want["rules"]:
        parameters = have_rules.pop(rule["type"], None)
        if parameters is None:
            differences[f"rule {rule['type']}"] = ("present", "missing")
            continue
        for key, value in rule.get("parameters", {}).items():
            current = parameters.get(key)
            if key == "required_status_checks":
                # The source counts as well: a check without integration_id accepts
                # any app and any commit status of its name, not GitHub Actions alone.
                same = sorted(map(required_check, value)) == sorted(
                    map(required_check, current or [])
                )
            elif key == "code_scanning_tools":
                same = sorted(map(canonical, value)) == sorted(
                    map(canonical, current or [])
                )
            else:
                same = current == value
            if not same:
                differences[f"{rule['type']}.{key}"] = (value, current)
    differences.update((f"rule {kind}", ("absent", "present")) for kind in have_rules)
    return differences


def canonical(value: Json) -> str:
    """A value as JSON with its keys in order, which compares two equal ones as one."""
    return json.dumps(value, sort_keys=True)


def rulesets(api: Api, repo: str) -> dict[str, Json]:
    """The rulesets of the repository itself, by name, with their ids: not those of
    an organization above it, which GitHub lists too unless asked without them, and
    which the repository can't change, even when one has the name of a managed one."""
    path = f"repos/{repo}/rulesets?includes_parents=false"
    return {item["name"]: item["id"] for item in api.get_all(path)}


def check_rulesets(
    api: Api, repo: str, wanted: list[dict[str, Json]], private: bool = False
) -> list[Drift]:
    try:
        existing = rulesets(api, repo)
    except GitHubError as error:
        if not (private and refused_by_plan(error)):
            raise
        # A private repository on GitHub Free may have no rulesets to read.
        return [lacking(repo, Drift("rulesets", "present", "not offered"), error)]
    drift = []
    for want in wanted:
        if want["name"] not in existing:
            drift.append(Drift(f"ruleset {want['name']}", "present", "missing"))
            continue
        have = api.get(f"repos/{repo}/rulesets/{existing[want['name']]}") or {}
        differences = ruleset_differences(want, have)
        if differences:
            # Each key that differs, with what the configuration wants and what
            # GitHub has, each on its side.
            drift.append(
                Drift(
                    f"ruleset {want['name']}",
                    {key: wanted for key, (wanted, _) in differences.items()},
                    {key: had for key, (_, had) in differences.items()},
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


def check_code_owners(
    api: Api, repo: str, wanted: list[dict[str, Json]]
) -> list[Drift]:
    """The code owners on the default branch of GitHub, where a ruleset wants their
    review: the settings load only with a CODEOWNERS file in the checkout, but GitHub
    reads the one of its default branch, and without it the rule binds nobody."""
    if not any(
        rule.get("parameters", {}).get("require_code_owner_review") is True
        for ruleset in wanted
        for rule in ruleset["rules"]
    ):
        return []
    if any(api.exists(f"repos/{repo}/contents/{name}") for name in CODEOWNERS):
        return []
    return [
        Drift(
            CODE_OWNERS,
            "present",
            "missing",
            manual=(
                f"merge {CODEOWNERS[0]} into {BRANCH} through a pull request: GitHub"
                " reads the code owners of its default branch, and without them the"
                " review of a code owner binds nobody"
            ),
        )
    ]


def rulesets_and_code_owners(
    api: Api, repo: str, wanted: list[dict[str, Json]], private: bool
) -> list[Drift]:
    """The rulesets, and the code owners where GitHub offers rulesets at all: without
    them no review of a code owner can be asked for."""
    drift = check_rulesets(api, repo, wanted, private)
    if any(item.setting == "rulesets" for item in drift):
        return drift
    return drift + check_code_owners(api, repo, wanted)


def apply_rulesets(
    api: Api, repo: str, wanted: list[dict[str, Json]], drift: list[Drift]
) -> None:
    """Each ruleset by itself: one that GitHub refuses, such as one whose bypass actor
    is an app that the repository hasn't installed, keeps no other from being made,
    and the unwanted ones go whatever happened to the others. Refused then says what
    happened to each, and which rulesets GitHub has."""
    existing = rulesets(api, repo)
    names = {item.setting.removeprefix("ruleset ") for item in drift}
    present = set(existing)
    attempts = Attempts("rulesets")
    for want in wanted:
        name = want["name"]
        if name not in names:
            continue
        if name in existing:
            path = f"repos/{repo}/rulesets/{existing[name]}"
            attempts.send(f"ruleset {name}", "updated", api.put, path, want)
        elif attempts.send(
            f"ruleset {name}",
            "created",
            api.post,
            f"repos/{repo}/rulesets",
            want,
        ):
            present.add(name)
    unwanted = names - {want["name"] for want in wanted}
    for name, number in existing.items():
        path = f"repos/{repo}/rulesets/{number}"
        if name in unwanted and attempts.send(
            f"ruleset {name}", "deleted", api.delete, path
        ):
            present.discard(name)
    attempts.finish(frozenset(present))


# --------------------------------------------------------------------------- classic protection

CLASSIC_PROTECTION = f"branch protection {BRANCH}"


def enabled(protection: dict[str, Json], key: str) -> bool:
    """Whether a switch of a classic branch protection is on, which GitHub answers as
    {"enabled": true}."""
    value = protection.get(key)
    return isinstance(value, dict) and value.get("enabled") is True


def classic_requirements(protection: dict[str, Json]) -> list[str]:
    """What a classic branch protection asks of a change of its branch, in words."""
    found = []
    checks = protection.get("required_status_checks")
    if isinstance(checks, dict):
        names = sorted(str(name) for name in checks.get("contexts") or [])
        if names:
            found.append(f"the checks {', '.join(names)}")
        if checks.get("strict"):
            found.append("a branch that is up to date")
    reviews = protection.get("required_pull_request_reviews")
    if isinstance(reviews, dict):
        count = int(reviews.get("required_approving_review_count") or 0)
        found.append(
            f"{count} approving review{'' if count == 1 else 's'}"
            if count
            else "a pull request"
        )
        if reviews.get("require_code_owner_reviews"):
            found.append("a review of the code owners")
        if reviews.get("require_last_push_approval"):
            found.append("an approval of the last push")
    if protection.get("restrictions"):
        found.append("pushes only of the people, teams and apps that it names")
    for key, words in (
        ("required_signatures", "signed commits"),
        ("required_linear_history", "a linear history"),
        ("required_conversation_resolution", "resolved conversations"),
        ("lock_branch", "a locked branch, which takes no change at all"),
        ("enforce_admins", "all of it of the administrators as well"),
    ):
        if enabled(protection, key):
            found.append(words)
    return found


def check_classic_protection(api: Api, repo: str, private: bool = False) -> list[Drift]:
    """A classic branch protection of main, which an existing repository may still
    have from before the blueprint. The rulesets protect main; a classic rule that
    requires the old names of the checks or an approval keeps every pull request of
    the bots waiting, which no ruleset shows. It is named with what it requires and
    how it goes, and never removed by the settings themselves, as a person may have
    set it on purpose."""
    path = f"repos/{repo}/branches/{BRANCH}/protection"
    try:
        # GitHub answers 404 for a branch without one.
        protection = api.get(path)
    except GitHubError as error:
        if not (private and refused_by_plan(error)):
            raise
        # A private repository on GitHub Free can have no branch protection.
        return []
    if not isinstance(protection, dict):
        return []
    return [
        Drift(
            CLASSIC_PROTECTION,
            "none",
            "; ".join(classic_requirements(protection)) or "present",
            manual=(
                f"the rulesets protect {BRANCH}, and a classic branch protection adds"
                " what it requires, which the pull requests of the bots may never"
                f" meet; remove it with gh api --method DELETE {path}, or under"
                f" https://github.com/{repo}/settings/branches"
            ),
        )
    ]


# --------------------------------------------------------------------------- variables


def check_variables(api: Api, repo: str, want: dict[str, str]) -> list[Drift]:
    """The variables that differ. An empty value says that the repository has no such
    variable, as GitHub refuses one with an empty value: RELEASE_AUTOMATION_APP_ID = ""
    for a repository without the release app wants none (null) on GitHub."""
    drift = []
    for name, value in want.items():
        have = api.get(f"repos/{repo}/actions/variables/{name}")
        current = None if have is None else have.get("value")
        wanted = None if value == "" else value
        if current != wanted:
            drift.append(Drift(f"variable {name}", wanted, current))
    return drift


def apply_variables(api: Api, repo: str, drift: list[Drift]) -> None:
    """Each variable by itself, so that one that GitHub refuses keeps no other from
    being set; one that the configuration wants empty goes."""
    attempts = Attempts("variables")
    path = f"repos/{repo}/actions/variables"
    for item in drift:
        name = item.setting.removeprefix("variable ")
        body = {"name": name, "value": item.want}
        if item.want is None:
            attempts.send(item.setting, "deleted", api.delete, f"{path}/{name}")
        elif item.have is None:
            attempts.send(item.setting, "set", api.post, path, body)
        else:
            attempts.send(item.setting, "set", api.patch, f"{path}/{name}", body)
    attempts.finish()


# --------------------------------------------------------------------------- environments

MAIN_ONLY = {"protected_branches": False, "custom_branch_policies": True}
# Whether an administrator may deploy past the rules of an environment, which GitHub
# allows by default. The rules of every environment of the settings bind the
# administrators too, so that a person who may administer the repository, or a token
# of theirs, reaches its secrets only as a workflow on main does.
BYPASS = "can_admins_bypass"


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
            # An answer without the field has GitHub's default.
            bypass = environment.get(BYPASS, True)
            if bypass is not False:
                drift.append(Drift(f"environment {name}.{BYPASS}", False, bypass))
        present = {
            item["name"]
            for item in api.get_all(f"repos/{repo}/environments/{name}/secrets")
        }
        for secret in config.get("secrets", []):
            if secret not in present:
                command = (
                    "python3 .github/blueprint.py app connect --key-file FILE"
                    if secret == RELEASE_KEY
                    else f"gh secret set {secret} --env {name} --repo {repo}"
                    # The prompt of gh reads one line, which of a private key is
                    # stored as if it were all of it.
                    + (" < KEY_FILE" if secret.endswith("_PRIVATE_KEY") else "")
                )
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
    # An environment may differ in its branches and in its bypass at once, and one
    # write sets both.
    names = dict.fromkeys(
        item.setting.removeprefix("environment ").removesuffix(f".{BYPASS}")
        for item in drift
    )
    for name in names:
        path = f"repos/{repo}/environments/{name}"
        try:
            api.put(path, {"deployment_branch_policy": MAIN_ONLY, BYPASS: False})
        except GitHubError as error:
            # A private repository without GitHub Enterprise may refuse the bypass,
            # which GitHub keeps to its protection rules there: the environment is
            # still created for main only, and the next check names the bypass.
            if error.status != 422:
                raise
            api.put(path, {"deployment_branch_policy": MAIN_ONLY})
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


def no_release_app_by_choice(variables: dict[str, str]) -> bool:
    """Whether the variables say that the repository has no release app, by choice:
    RELEASE_AUTOMATION_CLIENT_ID = "". Without the variable, it has none yet."""
    return not str(variables.get("RELEASE_AUTOMATION_CLIENT_ID", "-")).strip()


def without_release_key(
    environments: dict[str, dict[str, Json]], variables: dict[str, str]
) -> dict[str, dict[str, Json]]:
    """The environments, which ask for no private key of the release app where the
    variables say that the repository has none."""
    if not no_release_app_by_choice(variables):
        return environments
    return {
        name: {
            **environment,
            "secrets": [
                secret
                for secret in environment.get("secrets", [])
                if secret != RELEASE_KEY
            ],
        }
        for name, environment in environments.items()
    }


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
    # The events that start each workflow, by its path.
    triggers: dict[str, list[str]]

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
        root = path.parent.parent if root is None else root
        reviews = branch_reviews(branch, root)
        rulesets = [main_ruleset(checks, tools), tag_ruleset()]
        app_id = release_app_id(variables)
        if reviews.wanted:
            rulesets.insert(1, reviews_ruleset(reviews, app_id))
        if app_id is not None:
            rulesets.append(tag_creation_ruleset(app_id))
        variables = {
            **variables,
            REVIEWS_VARIABLE: str(
                variables.get(REVIEWS_VARIABLE, "on" if reviews.wanted else "off")
            ),
        }
        return cls(
            repository=config.get("repository", {}),
            security=config.get("security", {}),
            actions=actions,
            rulesets=rulesets,
            variables=variables,
            environments=without_release_key(config.get("environments", {}), variables),
            pages=config.get("pages", {}),
            # The labels of the issue assistant, next to the settings.
            labels=load_labels(path.parent / "labels.toml"),
            workflows=workflow_references(root),
            triggers=workflow_triggers(root),
        )


def check(
    api: Api, repo: str, settings: Settings, private: bool = False
) -> list[Drift]:
    """The differences of GitHub from the settings; private says whether a refusal
    that names the plan is one of what the plan of a private repository lacks."""
    return (
        check_repository(api, repo, settings.repository)
        + check_security(api, repo, settings.security)
        + check_actions(api, repo, settings.actions, settings.workflows)
        + check_policies(
            api, repo, settings.actions.get(TARGET, []), settings.triggers, private
        )
        + rulesets_and_code_owners(api, repo, settings.rulesets, private)
        + check_classic_protection(api, repo, private)
        + check_variables(api, repo, settings.variables)
        + check_environments(api, repo, settings.environments)
        + check_pages(api, repo, settings.pages)
        + check_labels(api, repo, settings.labels)
        + check_community(api, repo)
    )


# --------------------------------------------------------------------------- plan

# The variables that switch the workflows to what they do without a part of GitHub
# that a private repository lacks on its plan, with the part. GitHub offers code
# scanning and the dependency review to a private repository only with GitHub Code
# Security, or with GitHub Advanced Security, which includes it (decision 0082):
# without it, CodeQL, zizmor, OSV-Scanner and the Scorecard keep their findings out
# of code scanning, and the dependency review leaves the vulnerabilities of the lock
# files to OSV-Scanner.
PLAN_VARIABLES = {
    "CODE_SCANNING": "code scanning",
    "DEPENDENCY_REVIEW": "the dependency review",
}
# The switches of security_and_analysis that bring both.
CODE_SECURITY = ("advanced_security", "code_security")
# How GitHub refuses a part that the plan of a private repository lacks, such as a
# ruleset ("Upgrade to GitHub Pro or make this repository public to enable this
# feature"), the website ("Your current plan does not support GitHub Pages for this
# repository") or a switch that needs a product of GitHub Advanced Security.
PLAN_REFUSAL = re.compile(
    r"upgrade to github|make this repository public|current plan"
    r"|advanced security|secret protection|code security",
    re.IGNORECASE,
)
# The prefix of the differences of each part of the settings.
PARTS = {
    "repository": "repository.",
    "security": "security.",
    "actions": "actions.",
    "policies": "policy ",
    "rulesets": "ruleset",
    "variables": "variable ",
    "environments": "environment ",
    "pages": "pages",
    "labels": "label ",
}


@dataclass(frozen=True)
class Plan:
    """What GitHub offers the repository on its plan; a public one has everything."""

    private: bool
    # GitHub Code Security on a private repository; None where the token may not
    # read security_and_analysis, which then decides nothing.
    code_security: bool | None

    @property
    def offers_code_security(self) -> bool | None:
        return True if not self.private else self.code_security


def read_plan(api: Api, repo: str) -> Plan:
    data = api.get(f"repos/{repo}") or {}
    private = data.get("private") is True
    analysis = data.get("security_and_analysis")
    if not isinstance(analysis, dict):
        return Plan(private, None)
    return Plan(
        private,
        any(
            isinstance(analysis.get(key), dict)
            and analysis[key].get("status") == "enabled"
            for key in CODE_SECURITY
        ),
    )


def refused_by_plan(error: GitHubError) -> bool:
    """Whether GitHub refused a part of the settings with an answer that names the
    plan; the callers ask only for a private repository, since a public one has every
    part, and such an answer there, as from a policy of an organization, still fails."""
    return error.status in (403, 422) and bool(PLAN_REFUSAL.search(error.message))


def lacking(repo: str, item: Drift, error: GitHubError) -> Drift:
    """The difference of a part that the plan of the repository lacks, as a notice."""
    return replace(
        item,
        notice=True,
        manual=(
            f"GitHub doesn't offer it to the private repository {repo} on its plan"
            f' and answered "{error.message}"; the next run of the settings sets it'
            " once the repository is public or on a plan that offers it"
        ),
    )


def offered_only(
    repo: str, drift: list[Drift], unoffered: dict[str, GitHubError]
) -> list[Drift]:
    """The drift after an apply, in which each difference of a part that GitHub
    refused for the plan of the repository is a notice, which fails nothing."""
    result = []
    for item in drift:
        part = next(
            (name for name in unoffered if item.setting.startswith(PARTS[name])), None
        )
        if part is not None and not item.notice:
            item = lacking(repo, item, unoffered[part])
        result.append(item)
    return result


def fitted(api: Api, repo: str, settings: Settings, plan: Plan) -> Settings:
    """The settings for what GitHub offers the repository: where a private repository
    lacks GitHub Code Security, the variables of PLAN_VARIABLES are off, and the rule
    of main asks for no alerts of code scanning, to which no tool can upload. One that
    a repository no longer needs, after it became public or got Code Security, is on
    again, and a variable that the settings as code set themselves wins."""
    variables = dict(settings.variables)
    offered = plan.offers_code_security
    for name in PLAN_VARIABLES:
        if name in settings.variables or offered is None:
            continue
        if not offered:
            variables[name] = "off"
            continue
        have = api.get(f"repos/{repo}/actions/variables/{name}")
        if have is not None and have.get("value") != "on":
            variables[name] = "on"
    rulesets = settings.rulesets
    if variables.get("CODE_SCANNING") == "off":
        rulesets = [
            {
                **ruleset,
                "rules": [
                    rule for rule in ruleset["rules"] if rule["type"] != "code_scanning"
                ],
            }
            for ruleset in rulesets
        ]
    return replace(settings, variables=variables, rulesets=rulesets)


# The permissions of the release app that each part of the settings needs to write,
# as the settings page of the app names them. GitHub writes an environment and the
# branches that may use it with Administration, and a website with Administration and
# Pages; Environments only reads the names of the secrets of an environment.
PERMISSIONS = {
    "repository": ("Administration",),
    "security": ("Administration",),
    "actions": ("Administration",),
    "policies": ("Administration",),
    "rulesets": ("Administration",),
    "variables": ("Variables",),
    "environments": ("Administration",),
    "pages": ("Administration", "Pages"),
    "labels": ("Issues",),
}


def apply(
    api: Api, repo: str, settings: Settings, drift: list[Drift], private: bool = False
) -> tuple[dict[str, str], dict[str, GitHubError]]:
    """Apply every part of the drift and return the parts that GitHub refused, each
    with the reason, and those that it refused because the plan of a private
    repository lacks them, such as the rulesets and the website on GitHub Free, each
    with its answer.

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
            "policies",
            lambda: apply_policies(
                api,
                repo,
                settings.actions.get(TARGET, []),
                settings.triggers,
                part("policy "),
            ),
        ),
        (
            "rulesets",
            lambda: apply_rulesets(api, repo, settings.rulesets, part("ruleset ")),
        ),
        (
            "variables",
            lambda: apply_variables(
                api,
                repo,
                reviews_as_on_github(
                    settings, part("variable "), failed.get("rulesets"), private
                ),
            ),
        ),
        ("environments", lambda: apply_environments(api, repo, part("environment "))),
        ("pages", lambda: apply_pages(api, repo, settings.pages, part("pages"))),
        ("labels", lambda: apply_labels(api, repo, settings.labels, part("label "))),
    ]
    refused: dict[str, str] = {}
    unoffered: dict[str, GitHubError] = {}
    failed: dict[str, GitHubError] = {}
    for name, step in steps:
        try:
            step()
        except GitHubError as error:
            failed[name] = error
            errors = error.errors if isinstance(error, Refused) else [error]
            if private and all(map(refused_by_plan, errors)):
                unoffered[name] = error
                continue
            refused[name] = refusal(name, error)
    return refused, unoffered


def refusal(part: str, error: GitHubError) -> str:
    """Why GitHub refused a part of the settings; for a part whose items GitHub took
    one by one, what happened to each of them."""
    # Where GitHub refused every item alike, as it does a token without the
    # permission, the refusal is said once.
    if isinstance(error, Refused) and not (
        len(error.errors) == len(error.outcomes)
        and len(set(map(str, error.errors))) == 1
    ):
        lines = "".join(f"\n  {item}: {what}" for item, what in error.outcomes)
        # After a stop, GitHub never saw the items that came after it.
        if any(what.startswith("stopped") for _, what in error.outcomes):
            return (
                f"GitHub refused some of the {part} before it stopped answering:{lines}"
            )
        return f"GitHub refused some of the {part}, and took the others:{lines}"
    return f"the {part} could not be applied: {error}{hint(part, error)}"


def reviews_as_on_github(
    settings: Settings,
    drift: list[Drift],
    error: GitHubError | None,
    private: bool,
) -> list[Drift]:
    """The variables to apply, in which REVIEWS_OF_MAIN says what GitHub has after the
    rulesets were applied, not what the configuration wanted where GitHub refused it:
    on with Reviews of main, off without, so that the bots after the checks start the
    branch bot exactly where main requires reviews. A value that [variables] sets for
    itself stays, and so does the variable on GitHub where the rulesets there can't
    be told."""
    if error is None:
        return drift
    names = [ruleset["name"] for ruleset in settings.rulesets]
    derived = "on" if REVIEWS_RULESET in names else "off"
    if settings.variables.get(REVIEWS_VARIABLE) != derived:
        return drift
    setting = f"variable {REVIEWS_VARIABLE}"
    others = [item for item in drift if item.setting != setting]
    if isinstance(error, Refused):
        # A rate limit or an outage that stopped the rulesets leaves them unknown.
        if error.present is None:
            return others
        present = REVIEWS_RULESET in error.present
    elif private and refused_by_plan(error):
        # A private repository whose plan offers no rulesets has none.
        present = False
    else:
        return others
    have = next((item.have for item in drift if item.setting == setting), derived)
    value = "on" if present else "off"
    return others if have == value else [*others, Drift(setting, value, have)]


# GitHub's error for a bypass actor of a ruleset that the repository doesn't have,
# such as an app that isn't installed on it: "Actor <name> integration must be part
# of the ruleset source or owner organization".
ACTOR_REFUSAL = re.compile(r"\bActor\b.* must be part of the ruleset source\b")


def hint(part: str, error: GitHubError) -> str:
    """What a refusal of GitHub likely means. GitHub answers 403, and for some changes
    404, to a token without the permission."""
    if error.rate_limited:
        return "; that is the rate limit of GitHub; the next run tries again"
    if part == "rulesets" and any(map(ACTOR_REFUSAL.search, error.details)):
        return (
            "; GitHub takes an app as the bypass actor of a ruleset only where the app"
            " is installed: install the release app of RELEASE_AUTOMATION_APP_ID on"
            " the repository, or, for a repository without it, set RELEASE_APP,"
            ' RELEASE_AUTOMATION_CLIENT_ID and RELEASE_AUTOMATION_APP_ID to ""'
            " under [variables] in .github/repository.project.toml"
        )
    if error.status in (403, 404):
        names = PERMISSIONS[part]
        noun = "permission" if len(names) == 1 else "permissions"
        return f"; the token may lack the {noun} {' and '.join(names)} (read and write)"
    return ""


def describe(drift: list[Drift]) -> str:
    lines = []
    for item in drift:
        line = f"  {item.setting}: GitHub has {json.dumps(item.have)}, the configuration wants {json.dumps(item.want)}"
        if item.manual:
            line += f"\n      {item.manual}"
        lines.append(line)
    return "\n".join(lines)
