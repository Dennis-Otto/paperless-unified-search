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

A pull request from a fork takes its place as well, once its author allows edits by
maintainers. GitHub holds the runs of each new head of a fork until they are approved,
so such a head isn't in flight: it waits for a person and leaves its place to the next
one, and the bot doesn't bring it up to date again until someone has approved, or every
merge would start its runs anew for nothing. The one exception is a head that the bot
made itself on top of commits that a person approved: nothing new from the fork runs,
so the bot approves those runs (decision 0099). Anything unclear means no approval.
"""

from __future__ import annotations

import json
import time
import urllib.parse
from collections.abc import Iterable, Mapping
from dataclasses import dataclass, field
from enum import Enum

from blueprint.bot_health import code, default_branch
from blueprint.coach import escape_data
from blueprint.github import PAGE, Api, GitHubError, Json, run_gh_as
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
    "number,headRefName,headRefOid,autoMergeRequest,isCrossRepository,"
    "maintainerCanModify,mergeable,labels,reviewDecision,statusCheckRollup"
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
# The workflow of the bot, which starts the next run of the bot.
WORKFLOW = "update-branches.yml"
# How often the bot looks at the head of a fork after an update, and the seconds between.
HEAD_LOOKS = 5
HEAD_PAUSE = 3
# The account of the commits that GitHub makes and signs itself, such as the merge of
# an update: nobody else can get a commit verified under it.
GITHUB_COMMITTER = "web-flow"
# The most files of a comparison that GitHub lists without pages.
COMPARED_FILES = 300
# A run that GitHub held for an approval runs as its second attempt; of those, what
# shows that it really ran. A run that is queued may not have left the wait yet, and one
# that was cancelled may never have started, so neither shows anything.
RAN_CONCLUSIONS = frozenset({"success", "failure", "neutral", "timed_out"})
# What decides what a workflow of a pull request runs, besides the code that it tests:
# the workflows and the actions, the scripts they call, and the files that name commands
# or what a job installs. A person approves the runs of a pull request that changes one.
WORKFLOW_FOLDERS = frozenset({".github", ".githooks", "scripts"})
WORKFLOW_FILES = frozenset(
    {
        ".pre-commit-config.yaml",
        "action.yaml",
        "action.yml",
        "compose.yaml",
        "compose.yml",
        "composer.json",
        "composer.lock",
        "copier.yml",
        "docker-compose.yaml",
        "docker-compose.yml",
        "justfile",
        "makefile",
        "mkdocs.yml",
        "noxfile.py",
        "package-lock.json",
        "package.json",
        "pyproject.toml",
        "setup.py",
        "tox.ini",
        "uv.lock",
    }
)
WORKFLOW_FILE_PREFIXES = ("dockerfile", "requirements")


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
    # Whether it comes from a fork, and whether its author allows edits by maintainers.
    fork: bool = False
    editable: bool = False

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
            fork=bool(data.get("isCrossRepository")),
            editable=bool(data.get("maintainerCanModify")),
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
        # The name of a fork's branch says nothing about who made it.
        and (
            data.get("isCrossRepository")
            or not str(data.get("headRefName")).startswith(RELEASE_BRANCH)
        )
    ]
    return sorted(pulls, key=lambda pull: (pull.enabled_at, pull.number))


@dataclass(frozen=True)
class Rules:
    """What the rules of base require of a pull request before it merges."""

    checks: frozenset[str]
    threads: bool
    signatures: bool


def rules_of(api: Api, repo: str, base: str) -> Rules:
    """The names of the checks that the rules of base require, and whether they want
    every conversation resolved and every commit signed."""
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
        signatures=any(rule.get("type") == "required_signatures" for rule in rules),
    )


def quoted(branch: str) -> str:
    return urllib.parse.quote(branch, safe="/")


def plural(count: int, word: str) -> str:
    return f"{count} {word}" + ("" if count == 1 else "s")


def verified(commit: Mapping[str, Json]) -> bool:
    """Whether GitHub verified the signature of a commit of a list of commits."""
    verification = (commit.get("commit") or {}).get("verification") or {}
    return verification.get("verified") is True


def account(commit: Mapping[str, Json], role: str) -> str:
    """The account of the author or the committer of a commit, if GitHub knows one."""
    return str((commit.get(role) or {}).get("login") or "")


def parents(commit: Mapping[str, Json]) -> list[str]:
    return [str(parent.get("sha")) for parent in commit.get("parents") or []]


def decides_what_runs(path: str) -> bool:
    """Whether a file of a pull request decides what a workflow runs; in small letters,
    since a file of another spelling may be the same one on another system."""
    *folders, name = path.lower().split("/")
    return (
        not WORKFLOW_FOLDERS.isdisjoint(folders)
        or name in WORKFLOW_FILES
        or name.startswith(WORKFLOW_FILE_PREFIXES)
    )


def ran_after_a_wait(run: Mapping[str, Json], sha: str) -> bool:
    """Whether a run of this commit left its wait for an approval and ran: GitHub keeps
    the wait as its first attempt, whose conclusion is action_required, and runs the
    second attempt once someone approved it."""
    return (
        run.get("head_sha") == sha
        and run.get("event") == "pull_request"
        and int(run.get("run_attempt") or 0) > 1
        and (
            run.get("status") == "in_progress"
            or run.get("conclusion") in RAN_CONCLUSIONS
        )
    )


@dataclass(frozen=True)
class Job:
    """What the workflow gives the bot for the runs of the pull requests from forks: the
    token of its job, which may read, approve and start runs where the token of the
    release app may not, and the account of the release app, whose token makes the
    merges of an update."""

    api: Api
    login: str


def job_from(environment: Mapping[str, str]) -> Job | None:
    """The job that runs the bot, or None outside of one, such as on the computer of a
    person: then the bot approves nothing and starts no further run."""
    token = environment.get("ACTIONS_TOKEN", "")
    login = environment.get("BOT_ACCOUNT", "")
    if not token or not login:
        return None
    return Job(Api(run_gh_as(token)), login)


@dataclass
class Line:
    """One run of the bot along the line, with what it found for each pull request."""

    api: Api
    repo: str
    base: str
    required: frozenset[str]
    threads: bool = False
    signatures: bool = False
    job: Job | None = None
    notes: dict[int, list[str]] = field(default_factory=dict)
    warnings: list[str] = field(default_factory=list)
    # Warnings about a pull request from a fork, which fail no run: a refusal can last as
    # long as the pull request waits, and it is about that pull request alone.
    notices: list[str] = field(default_factory=list)
    compared: dict[int, Mapping[str, Json]] = field(default_factory=dict)
    # The runs of the head of each pull request from a fork that the bot may approve.
    approvable: dict[int, list[Mapping[str, Json]]] = field(default_factory=dict)
    # Whether this run made new runs of a fork's head likely, for the next run to find.
    look_again_wanted: bool = False

    @property
    def actions(self) -> Api:
        """Who reads and approves runs: the token of the job, or outside of a job the
        account of the person who runs the bot, which approves nothing."""
        return self.job.api if self.job else self.api

    def note(self, pull: Pull, text: str) -> None:
        self.notes.setdefault(pull.number, []).append(text)

    def refused(self, pull: Pull, error: GitHubError) -> None:
        self.note(pull, f"GitHub refused a request: {code(str(error))}.")
        (self.notices if pull.fork else self.warnings).append(
            f"::warning::The branch bot couldn't handle #{pull.number}: "
            + escape_data(str(error))
        )

    def comparison(self, pull: Pull) -> Mapping[str, Json]:
        """Its head against base, once per run: how far it is behind, and for a head of
        a fork also its commits with their signatures and its files, at this very head,
        which no later push of the contributor can change."""
        if pull.number not in self.compared:
            compared = self.api.get(
                f"repos/{self.repo}/compare/{quoted(self.base)}...{pull.head}"
            )
            if not isinstance(compared, dict):
                raise GitHubError(404, f"no comparison of {pull.head} with the base")
            self.compared[pull.number] = compared
        return self.compared[pull.number]

    def commits_behind(self, pull: Pull) -> int:
        return int(self.comparison(pull)["behind_by"])

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
        if pull.branch.startswith(RENOVATE_BRANCH) and not pull.fork:
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
            if pull.fork and not pull.editable:
                self.note(
                    pull,
                    "its author doesn't allow edits by maintainers, so the bot can't "
                    "bring it up to date: it stays out of the line until they do, and "
                    "the next one takes its place.",
                )
                continue
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
                # Any request that fails here leaves it out of the line: no update and
                # no approval.
                if pull.fork and self.fork_waits(pull):
                    continue
            except GitHubError as error:
                self.refused(pull, error)
                continue
            line.append(pull)
        return line

    def fork_waits(self, pull: Pull) -> bool:
        """Whether a pull request from a fork waits outside the line: for its commits
        to be signed, which the rules of base want, or for a person to approve the runs
        of its head."""
        compared = self.comparison(pull)
        if self.signatures:
            unsigned = [
                commit
                for commit in compared.get("commits") or []
                if not verified(commit)
            ]
            if unsigned:
                first = code(str(unsigned[0].get("sha"))[:7])
                self.note(
                    pull,
                    f"{plural(len(unsigned), 'commit')} of it, such as {first}, "
                    f"{'has' if len(unsigned) == 1 else 'have'} no verified signature, "
                    f"which the rules of {code(self.base)} require: it can't merge, so "
                    "it stays out of the line until they are signed.",
                )
                return True
        runs = self.waiting_runs(pull)
        if not runs:
            return False
        reason = self.why_a_person(pull, compared, runs)
        if reason:
            self.note(
                pull,
                f"{plural(len(runs), 'run')} of its head {'waits' if len(runs) == 1 else 'wait'} "
                f"for a person to approve, since {reason}: the bot doesn't bring it up "
                "to date until then, and the next one takes its place.",
            )
            return True
        self.approvable[pull.number] = runs
        return False

    def waiting_runs(self, pull: Pull) -> list[Mapping[str, Json]]:
        """The runs of exactly its head that GitHub holds for an approval."""
        data = self.actions.get(
            f"repos/{self.repo}/actions/runs?head_sha={pull.head}"
            f"&status=action_required&per_page={PAGE}"
        )
        return [
            run
            for run in (data or {}).get("workflow_runs") or []
            if run.get("head_sha") == pull.head
            and run.get("conclusion") == "action_required"
        ]

    def why_a_person(
        self,
        pull: Pull,
        compared: Mapping[str, Json],
        runs: list[Mapping[str, Json]],
    ) -> str:
        """Why a person has to approve the runs of this head, or nothing when the bot
        may: it made the head itself, a person approved every commit of the pull request
        below it, and the pull request changes nothing that decides what a workflow
        runs (decision 0099). All of it comes from the API each time, so that a run of
        the bot needs no memory, and whatever is unclear is a reason."""
        if self.job is None:
            return "only a run of the workflow may approve them"
        if any(run.get("event") != "pull_request" for run in runs):
            return "a run of another event than a pull request waits as well"
        commits = compared.get("commits") or []
        if compared.get("total_commits") != len(commits):
            return "GitHub doesn't list all of its commits"
        return self.changed_files_reason(compared) or self.history_reason(
            pull, commits, self.job.login
        )

    def changed_files_reason(self, compared: Mapping[str, Json]) -> str:
        """What a person has to look at among the files of the pull request, or nothing.
        A renamed file counts by its old name as well."""
        files = compared.get("files") or []
        if not files or len(files) >= COMPARED_FILES:
            return "GitHub doesn't list all of its files"
        paths = sorted(
            str(path)
            for changed in files
            for path in (changed.get("filename"), changed.get("previous_filename"))
            if path
        )
        for path in paths:
            if decides_what_runs(path):
                return f"it changes {code(path)}, which decides what a workflow runs"
        return ""

    def history_reason(
        self, pull: Pull, commits: list[Mapping[str, Json]], login: str
    ) -> str:
        """What a person has to look at among the commits of the pull request, or
        nothing. From the head down, each commit is a merge of the bot until one of them
        ran: that one has every commit below it in it, and what ran of it was approved
        before. A commit that is no merge of the bot and that never ran is new."""
        by_sha = {str(commit.get("sha")): commit for commit in commits}
        commit = by_sha.get(pull.head)
        if commit is None or not self.merge_of_the_bot(commit, login):
            return "its head isn't a merge that the bot made"
        while True:
            below = by_sha.get(parents(commit)[0])
            if below is None:
                return "GitHub doesn't show a commit of it below a merge of the bot"
            if self.ran(str(below.get("sha"))):
                return ""
            if not self.merge_of_the_bot(below, login):
                return "nobody has approved its newest commit yet"
            commit = below

    def merge_of_the_bot(self, commit: Mapping[str, Json], login: str) -> bool:
        """Whether the bot made this commit by bringing a branch up to date: a merge of
        base into it, by the account of the bot, which GitHub made and signed itself. A
        contributor can name the bot as the author of a commit, but not get it verified
        with GitHub as its committer, and pressing *Update branch* or pushing a merge
        makes them the author."""
        merged = parents(commit)
        return (
            len(merged) == 2
            and account(commit, "author") == login
            and account(commit, "committer") == GITHUB_COMMITTER
            and verified(commit)
            and self.on_base(merged[1])
        )

    def on_base(self, sha: str) -> bool:
        """Whether a commit is on base: it has nothing that base hasn't."""
        compared = self.api.get(
            f"repos/{self.repo}/compare/{quoted(self.base)}...{sha}"
        )
        return isinstance(compared, dict) and compared.get("ahead_by") == 0

    def ran(self, sha: str) -> bool:
        """Whether runs of this commit left their wait for an approval and ran."""
        data = self.actions.get(
            f"repos/{self.repo}/actions/runs?head_sha={sha}"
            f"&event=pull_request&per_page={PAGE}"
        )
        return any(
            ran_after_a_wait(run, sha)
            for run in (data or {}).get("workflow_runs") or []
        )

    def approve(self, pull: Pull) -> None:
        """Approve the runs of its head, each of which the summary names."""
        for run in self.approvable[pull.number]:
            self.actions.request(
                "POST", f"repos/{self.repo}/actions/runs/{run['id']}/approve"
            )
            self.note(
                pull, f"approved [run {run['id']}]({run.get('html_url')}) of its head."
            )
        self.note(
            pull,
            "Its head is a merge of the bot on top of commits that a person approved, "
            "so nothing new from the fork runs: its checks run now, and it merges next.",
        )
        # GitHub may make further runs of this head a moment later. The next run approves
        # them, and one with nothing to approve starts no further run.
        self.look_again_wanted = True

    def in_flight(self, line: list[Pull]) -> Pull | None:
        """The pull request whose checks run while it is up to date: it merges next,
        and its merge starts the next run of the bot."""
        for pull in line:
            # What waits for an approval doesn't run yet, whatever else has reported.
            if pull.number in self.approvable:
                continue
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
                if behind == 0 and pull.number not in self.approvable:
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
                if behind == 0:
                    self.approve(pull)
                    front = pull
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
                    if pull.fork:
                        # The runs of a contributor's new commit wait for a person.
                        self.note(
                            pull,
                            "got a new commit since the list, so the bot leaves it as "
                            "it is, and the next one takes its place.",
                        )
                        continue
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
            if pull.fork:
                self.note(
                    pull,
                    f"was {plural(behind, 'commit')} behind {base}: brought up to date.",
                )
                self.follow_update(pull)
                continue
            self.note(
                pull,
                f"was {plural(behind, 'commit')} behind {base}: brought up to date, "
                "its checks run now.",
            )

    def follow_update(self, pull: Pull) -> None:
        """Have the next run of the bot look at the new head of a fork, once there is
        one: GitHub answers an update before it makes the merge, and a run that starts
        again for a head that stays would update it again, without end."""
        try:
            moved = self.head_moved(pull)
        except GitHubError as error:
            self.refused(pull, error)
            return
        if moved:
            self.look_again_wanted = True
            self.note(
                pull,
                "GitHub may hold its runs for an approval, which the next run of the "
                "bot looks at.",
            )
            return
        self.note(
            pull,
            "Its head hasn't moved yet, so the next merge or the schedule looks at it "
            "again.",
        )
        self.notices.append(
            f"::warning::GitHub took the update of #{pull.number}, but its head hasn't "
            "moved, so the bot starts no further run for it."
        )

    def head_moved(self, pull: Pull) -> bool:
        """Whether its head is another one than in the list, within a few seconds."""
        for _ in range(HEAD_LOOKS):
            data = self.api.get(f"repos/{self.repo}/pulls/{pull.number}")
            head = (data.get("head") or {}) if isinstance(data, dict) else {}
            if head.get("sha") not in (None, pull.head):
                return True
            time.sleep(HEAD_PAUSE)
        return False

    def look_again(self) -> None:
        """Start the next run of the bot after an update of a fork's branch or an
        approval: the runs of a new head wait for an approval, and a run that GitHub
        holds doesn't report as completed, so no event tells the bot of them. A run of
        the workflow starts late enough to find them, and the token of a job may start
        one by hand."""
        if not self.look_again_wanted or self.job is None:
            return
        try:
            self.job.api.post(
                f"repos/{self.repo}/actions/workflows/{WORKFLOW}/dispatches",
                {"ref": self.base},
            )
        except GitHubError as error:
            self.notices.append(
                "::warning::The branch bot couldn't start its next run, so the next "
                "merge or its schedule looks at the runs of the pull request from a "
                "fork: " + escape_data(str(error))
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
    # What fails the run, unless it shows as a check of a pull request.
    warnings: list[str]
    # What only shows as an annotation.
    notices: list[str]


def next_branch(api: Api, repo: str, job: Job | None = None) -> Outcome:
    """One run of the branch bot: the labels of the conflicts, and the next pull
    request of the line brought up to date, unless one is in flight."""
    base = default_branch(api, repo)
    rules = rules_of(api, repo, base)
    line = Line(api, repo, base, rules.checks, rules.threads, rules.signatures, job)
    pulls = waiting(api, repo, base)
    line.move_on(line.sort_out(pulls))
    line.look_again()
    return Outcome(line.summary(pulls), line.warnings, line.notices)
