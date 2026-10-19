"""The branch bot: of the pull requests that wait for auto-merge, it brings one at a
time up to date with the default branch.

The rules of the default branch take a pull request only when it is up to date with
it, and auto-merge doesn't update a branch by itself. So only one pull request can
merge next, and each merge leaves every other one behind again. Bringing every
waiting pull request up to date after each merge ran the checks of all of them for the
one that merged: about n²/2 runs of every check for n pull requests, which competed
for the runners of the account and made each of them slower. The bot keeps a line
instead, as Kodiak and Bulldozer do: the pull requests that wait for auto-merge, in the
order in which their auto-merge was turned on, of which only the first one that can
merge is brought up to date. While its checks run, the others wait; its merge, or a
required check that fails, starts the next run of the bot. That delays no merge, since
each pull request has to run its checks again after the merge before it anyway.

A pull request whose required check failed waits for a fix and leaves its place to the
next one. One that conflicts with the default branch gets the label merge-conflict and
one comment, which stays for the history; the label goes once it no longer conflicts.
Renovate builds a branch of its own that conflicts anew. One that waits for a review,
or for its conversations to be resolved, can't merge whatever its checks say, so the
bot doesn't bring it up to date either. A request that GitHub refuses is a warning
about that pull request, and the bot goes on with the next one.
"""

from __future__ import annotations

import json
import urllib.parse
from collections.abc import Iterable, Mapping
from dataclasses import dataclass, field
from enum import Enum

from blueprint.bot_health import code, default_branch
from blueprint.coach import escape_data
from blueprint.github import Api, GitHubError, Json
from blueprint.update import LABEL, pages

# The first line of the one comment of the bot on a pull request that conflicts.
MARKER = "<!-- branch-bot: merge-conflict -->"
CONFLICT_COMMENT = (
    "This pull request conflicts with `{base}`, so the branch bot can't bring it up to"
    " date. Merge `{base}` into the branch and resolve the conflict; the bot takes over"
    " again afterwards."
)
# The release bot keeps its own pull request current.
RELEASE_BRANCH = "release-please--"
# Renovate builds a branch of its own that conflicts with the default branch anew.
RENOVATE_BRANCH = "renovate/"
# What the bot needs of each open pull request, in one request.
FIELDS = (
    "number,headRefName,headRefOid,autoMergeRequest,isCrossRepository,mergeable,"
    "labels,reviewDecision,statusCheckRollup"
)
# What a review decision that holds up the merge says about the pull request.
REVIEWS = {
    "CHANGES_REQUESTED": "changes were requested in a review",
    "REVIEW_REQUIRED": "it waits for an approving review",
}
# How GitHub refuses the update of a branch that conflicts, and one whose head moved.
CONFLICT_MESSAGE = "merge conflict"
MOVED_MESSAGE = "expected head sha"
# The conversations of a pull request; one page is plenty for those of a bot's line.
THREADS_QUERY = (
    "query {{ repository(owner: {owner}, name: {name}) {{ pullRequest(number: {number})"
    " {{ reviewThreads(first: 100) {{ nodes {{ isResolved }} }} }} }} }}"
)
# The most pull requests that the bot looks at, as many as one page of GitHub's list.
LIMIT = 100
# GitHub's time of a check run that hasn't started yet.
NO_TIME = "0001-01-01T00:00:00Z"


class State(Enum):
    """Where a check stands, for the merge of its pull request."""

    PASSED = "passed"
    PENDING = "pending"
    FAILED = "failed"
    # Cancelled or stale: it hasn't passed, and a new run of it may pass.
    STOPPED = "stopped"


PASSED_CONCLUSIONS = {"SUCCESS", "NEUTRAL", "SKIPPED"}
FAILED_CONCLUSIONS = {"FAILURE", "TIMED_OUT", "ACTION_REQUIRED", "STARTUP_FAILURE"}
STATUS_STATES = {
    "SUCCESS": State.PASSED,
    "PENDING": State.PENDING,
    "EXPECTED": State.PENDING,
    "FAILURE": State.FAILED,
    "ERROR": State.FAILED,
}


def state_of(entry: Mapping[str, Json]) -> State:
    """The state of a check run or of a commit status, as the rollup of the head of a
    pull request lists them."""
    if entry.get("__typename") == "StatusContext":
        return STATUS_STATES.get(str(entry.get("state") or ""), State.STOPPED)
    if entry.get("status") != "COMPLETED":
        return State.PENDING
    conclusion = str(entry.get("conclusion") or "")
    if conclusion in PASSED_CONCLUSIONS:
        return State.PASSED
    if conclusion in FAILED_CONCLUSIONS:
        return State.FAILED
    return State.STOPPED


def latest_checks(rollup: Iterable[Mapping[str, Json]]) -> dict[str, State]:
    """The state of each check by its name, from its latest run: a run of the check
    that an edit of the pull request started again replaces the one before it."""
    latest: dict[str, tuple[str, State]] = {}
    for entry in rollup:
        name = str(entry.get("name") or entry.get("context") or "")
        started = str(entry.get("startedAt") or NO_TIME)
        # A run that hasn't started is the latest of all.
        when = "~" if started == NO_TIME else started
        if name not in latest or when >= latest[name][0]:
            latest[name] = (when, state_of(entry))
    return {name: state for name, (_, state) in latest.items()}


@dataclass(frozen=True)
class Pull:
    """An open pull request of the repository that waits for auto-merge."""

    number: int
    branch: str
    head: str
    enabled_at: str
    mergeable: str
    labelled: bool
    review: str = ""
    checks: Mapping[str, State] = field(default_factory=dict)

    @classmethod
    def of(cls, data: Mapping[str, Json]) -> Pull:
        auto_merge = data.get("autoMergeRequest") or {}
        return cls(
            number=int(data["number"]),
            branch=str(data["headRefName"]),
            head=str(data["headRefOid"]),
            enabled_at=str(auto_merge.get("enabledAt") or ""),
            mergeable=str(data.get("mergeable") or "UNKNOWN"),
            labelled=any(
                label.get("name") == LABEL for label in data.get("labels") or []
            ),
            review=str(data.get("reviewDecision") or ""),
            checks=latest_checks(data.get("statusCheckRollup") or []),
        )

    def counted(self, required: frozenset[str]) -> dict[str, State]:
        """The checks that decide its merge: the required ones, or every check where
        the rules of the branch require none."""
        return {
            name: state
            for name, state in self.checks.items()
            if not required or name in required
        }

    def failed(self, required: frozenset[str]) -> list[str]:
        """The names of the required checks that failed for its head."""
        counted = self.counted(required)
        return sorted(name for name, state in counted.items() if state is State.FAILED)

    def pending(self, required: frozenset[str]) -> bool:
        """Whether its checks still run: a required one runs, or none has started yet,
        as for a head that the bot has just made."""
        counted = self.counted(required).values()
        return not self.checks or State.PENDING in counted


def waiting(api: Api, repo: str, base: str) -> list[Pull]:
    """The pull requests of the repository into base that wait for auto-merge, in the
    order in which their auto-merge was turned on: the line."""
    result = api.runner(
        [
            "pr",
            "list",
            "--repo",
            repo,
            "--state",
            "open",
            "--base",
            base,
            "--limit",
            str(LIMIT),
            "--json",
            FIELDS,
        ],
        None,
    )
    if result.returncode != 0:
        raise GitHubError(0, (result.stderr or result.stdout).strip())
    pulls = [
        Pull.of(data)
        for data in json.loads(result.stdout)
        if data.get("autoMergeRequest")
        and not data.get("isCrossRepository")
        and not str(data.get("headRefName")).startswith(RELEASE_BRANCH)
    ]
    return sorted(pulls, key=lambda pull: (pull.enabled_at, pull.number))


@dataclass(frozen=True)
class Rules:
    """What the rules of base require of a pull request before it merges."""

    checks: frozenset[str]
    threads: bool


def rules_of(api: Api, repo: str, base: str) -> Rules:
    """The names of the checks that the rules of base require, and whether they want
    every conversation resolved."""
    rules = api.get(f"repos/{repo}/rules/branches/{quoted(base)}?per_page=100") or []
    return Rules(
        checks=frozenset(
            str(check["context"])
            for rule in rules
            if rule.get("type") == "required_status_checks"
            for check in (rule.get("parameters") or {}).get(
                "required_status_checks", []
            )
        ),
        threads=any(
            rule.get("type") == "pull_request"
            and (rule.get("parameters") or {}).get("required_review_thread_resolution")
            for rule in rules
        ),
    )


def quoted(branch: str) -> str:
    return urllib.parse.quote(branch, safe="/")


def plural(count: int, word: str) -> str:
    return f"{count} {word}" + ("" if count == 1 else "s")


@dataclass
class Line:
    """One run of the bot along the line, with what it found for each pull request."""

    api: Api
    repo: str
    base: str
    required: frozenset[str]
    threads: bool = False
    notes: dict[int, list[str]] = field(default_factory=dict)
    warnings: list[str] = field(default_factory=list)
    behind: dict[int, int] = field(default_factory=dict)

    def note(self, pull: Pull, text: str) -> None:
        self.notes.setdefault(pull.number, []).append(text)

    def refused(self, pull: Pull, error: GitHubError) -> None:
        self.note(pull, f"GitHub refused a request: {code(str(error))}.")
        self.warnings.append(
            f"::warning::The branch bot couldn't handle #{pull.number}: "
            + escape_data(str(error))
        )

    def commits_behind(self, pull: Pull) -> int:
        if pull.number not in self.behind:
            compared = self.api.get(
                f"repos/{self.repo}/compare/{quoted(self.base)}...{pull.head}"
            )
            if not isinstance(compared, dict):
                raise GitHubError(404, f"no comparison of {pull.head} with the base")
            self.behind[pull.number] = int(compared["behind_by"])
        return self.behind[pull.number]

    def held(self, pull: Pull) -> str:
        """What holds up its merge besides its checks, or nothing: a review that the
        rules want, or a conversation to resolve."""
        reason = REVIEWS.get(pull.review, "")
        if not reason and self.threads and self.unresolved(pull):
            reason = "it has a conversation to resolve"
        return reason

    def unresolved(self, pull: Pull) -> bool:
        owner, _, name = self.repo.partition("/")
        data = self.api.graphql(
            THREADS_QUERY.format(
                owner=json.dumps(owner), name=json.dumps(name), number=pull.number
            )
        )
        found = (((data or {}).get("repository") or {}).get("pullRequest")) or {}
        threads = (found.get("reviewThreads") or {}).get("nodes") or []
        return any(not thread.get("isResolved") for thread in threads)

    def wait(self, pull: Pull, reason: str) -> None:
        self.note(
            pull,
            f"{reason}, so it can't merge yet: the bot leaves it as it is, and the "
            "next one takes its place.",
        )

    def conflict(self, pull: Pull) -> None:
        """A pull request that conflicts with base: the label and one comment, which a
        person resolves; Renovate builds a branch of its own anew."""
        base = code(self.base)
        if pull.branch.startswith(RENOVATE_BRANCH):
            self.note(pull, f"conflicts with {base}; Renovate builds its branch anew.")
            return
        issue = f"repos/{self.repo}/issues/{pull.number}"
        if not pull.labelled:
            self.api.post(f"{issue}/labels", {"labels": [LABEL]})
            if not any(
                MARKER in str(comment.get("body") or "")
                for comment in pages(self.api, f"{issue}/comments")
            ):
                body = CONFLICT_COMMENT.format(base=self.base)
                self.api.post(f"{issue}/comments", {"body": f"{MARKER}\n{body}"})
        self.note(
            pull,
            f"conflicts with {base}: it has the label {LABEL} and waits for a person "
            f"to merge {base} into it.",
        )

    def sort_out(self, pulls: list[Pull]) -> list[Pull]:
        """The labels of the pull requests that conflict, or no longer do; the others
        are in the line."""
        line = []
        for pull in pulls:
            try:
                if pull.mergeable == "CONFLICTING":
                    self.conflict(pull)
                    continue
                if pull.labelled and pull.mergeable == "MERGEABLE":
                    # Someone may have taken it off a moment ago.
                    self.api.delete(
                        f"repos/{self.repo}/issues/{pull.number}/labels/{LABEL}",
                        missing_ok=True,
                    )
            except GitHubError as error:
                self.refused(pull, error)
                continue
            line.append(pull)
        return line

    def in_flight(self, line: list[Pull]) -> Pull | None:
        """The pull request whose checks run while it is up to date: it merges next,
        and its merge starts the next run of the bot."""
        for pull in line:
            if not pull.pending(self.required) or pull.failed(self.required):
                continue
            try:
                if self.commits_behind(pull) == 0 and not self.held(pull):
                    return pull
            except GitHubError as error:
                self.refused(pull, error)
        return None

    def update(self, pull: Pull) -> None:
        """Merge base into the branch of the pull request, as the button *Update
        branch* does, unless someone pushed to it since the list."""
        response = self.api.request(
            "PUT",
            f"repos/{self.repo}/pulls/{pull.number}/update-branch",
            {"expected_head_sha": pull.head},
            missing_ok=True,
        )
        if response.status == 404:
            raise GitHubError(404, f"no pull request #{pull.number} to update")

    def conflicts_now(self, pull: Pull) -> bool:
        """Whether GitHub, asked again, finds a conflict that its list didn't know of
        yet."""
        data = self.api.get(f"repos/{self.repo}/pulls/{pull.number}")
        return isinstance(data, dict) and data.get("mergeable") is False

    def move_on(self, line: list[Pull]) -> None:
        """Bring the first pull request of the line up to date that can merge, unless
        one is in flight."""
        front = self.in_flight(line)
        base = code(self.base)
        for pull in line:
            if pull.number in self.notes:
                continue
            if pull is front:
                self.note(
                    pull,
                    f"up to date with {base}, and its checks run: it merges next.",
                )
                continue
            failed = pull.failed(self.required)
            if failed:
                names = ", ".join(code(name) for name in failed)
                self.note(
                    pull,
                    f"the required check {names} failed: it waits for a fix, and the "
                    "next one takes its place.",
                )
                continue
            if front is not None:
                self.note(pull, "waits in line.")
                continue
            try:
                behind = self.commits_behind(pull)
                if behind == 0:
                    self.note(
                        pull,
                        f"up to date with {base}, and its checks ended: auto-merge "
                        "merges it once nothing else holds it up, such as a "
                        "conversation to resolve or a check that was cancelled.",
                    )
                    continue
                reason = self.held(pull)
                if reason:
                    self.wait(pull, reason)
                    continue
                try:
                    self.update(pull)
                except GitHubError as error:
                    message = str(error).lower()
                    if CONFLICT_MESSAGE in message or (
                        pull.mergeable == "UNKNOWN" and self.conflicts_now(pull)
                    ):
                        self.conflict(pull)
                        continue
                    if MOVED_MESSAGE not in message:
                        raise
                    # Someone pushed to it since the list: its checks run anyway.
                    front = pull
                    self.note(
                        pull,
                        "got a new commit since the list, so its checks run now: it "
                        "merges next.",
                    )
                    continue
            except GitHubError as error:
                self.refused(pull, error)
                continue
            front = pull
            self.note(
                pull,
                f"was {plural(behind, 'commit')} behind {base}: brought up to date, "
                "its checks run now.",
            )

    def summary(self, pulls: list[Pull]) -> str:
        lines = ["### Branch bot", ""]
        if not pulls:
            lines.append("No pull request waits for auto-merge.")
            return "\n".join(lines) + "\n"
        lines += [
            f"{code(self.base)} takes a pull request only when it is up to date with "
            "it, so the bot brings one at a time up to date, in the order in which "
            "auto-merge was turned on:",
            "",
        ]
        lines += [
            f"- #{pull.number} {code(pull.branch)}: "
            + " ".join(self.notes.get(pull.number, []))
            for pull in pulls
        ]
        return "\n".join(lines) + "\n"


@dataclass(frozen=True)
class Outcome:
    summary: str
    warnings: list[str]


def next_branch(api: Api, repo: str) -> Outcome:
    """One run of the branch bot: the labels of the conflicts, and the next pull
    request of the line brought up to date, unless one is in flight."""
    base = default_branch(api, repo)
    rules = rules_of(api, repo, base)
    line = Line(api, repo, base, rules.checks, rules.threads)
    pulls = waiting(api, repo, base)
    line.move_on(line.sort_out(pulls))
    return Outcome(line.summary(pulls), line.warnings)
