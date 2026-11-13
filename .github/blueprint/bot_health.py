"""The bot-health bot: a bot that fails on the default branch keeps one issue open,
which closes once the bot passes again.

GitHub mails the failure of a scheduled run only to whoever last changed its
schedule, and that of another run to whoever started it, which in these repositories
is mostly the release app, so a failing bot went unnoticed for days. When a run of a
watched workflow ends on the default branch, the bot runs its failed jobs once more
if a second try can pass, and otherwise keeps one issue per workflow current, with
the failed jobs, their steps and their first errors; a run that passes closes it
once every job that failed ran again in it and passed, so a run that skipped such a
job, as one that only plans, leaves it open. A bot of events, such as Welcome, also
for a pull request, and a workflow that keeps an issue of its own get neither a second
try nor an issue, unless Harden-Runner stopped a job of their first attempt: then
their failed jobs run once more (decision 0141).
Once a day it looks at the latest run of every workflow, those of the project too,
in case GitHub lost an event, and starts a workflow whose schedule GitHub missed or
switched off. A failure of Lint, which main runs only each week, counts only at the
head of main: a pull request reaches main only when it passes Lint. A run that GitHub
couldn't start, as in its outage of 9 November 2026, is no failure of the bot: its end
starts nothing, and the daily look runs it again once (decision 0136). Nothing
follows the end of this bot itself, so the daily look also runs the failed jobs of
its own runs once more that Harden-Runner stopped (decision 0141). The same look
keeps one issue open while the social preview that GitHub shows is not the committed
.github/social-preview.png, which only a person can upload; its label is manual-step,
not bot-failure, since nothing fails there. It also ticks the box for a rebase of a
pull request of Renovate that Renovate leaves alone after a merge of the default
branch by a person, and keeps one issue with those whose branch carries a change of a
person that the new branch would drop (decision 0144). When the blueprint has a
newer release than the repository and the pull request of the blueprint bot doesn't
bring it yet, the look starts the blueprint bot, so that a repository that the Rollout
doesn't start, such as one of another owner, gets it within a day (decision 0052). In
the blueprint, it also keeps one issue with the warnings and errors in the log of
Renovate.
"""

from __future__ import annotations

import base64
import hashlib
import json
import re
import urllib.parse
from collections.abc import Callable, Collection, Iterable, Mapping, Sequence
from dataclasses import dataclass, replace
from datetime import UTC, date, datetime, time, timedelta
from pathlib import Path
from typing import Any

from blueprint.github import Api, GitHubError, Json
from blueprint.outage import couldnt_start, never_started, unreachable
from blueprint.social_preview import (
    PICTURE,
    PRIVATE,
    SOURCE,
    Unreadable,
    preview,
    settings_page,
    shows_the_project,
)
from blueprint.update import (
    ANSWERS,
    answered_release,
    answered_source,
    released_tag,
    version_key,
)
from blueprint.update import BRANCH as UPDATE_BRANCH

LABEL = "bot-failure"
LABEL_COLOR = "d93f0b"
LABEL_DESCRIPTION = (
    "A bot or a scheduled workflow fails on the default branch; "
    "closes when it passes again"
)
# The label of the issue that waits for a step that only a person can take, such as
# the upload of the social preview, which no API offers: nothing fails there
# (decision 0140).
STEP = "manual-step"
STEP_COLOR = "1d76db"
STEP_DESCRIPTION = (
    "A bot waits for a step that only a person can take, such as an upload; "
    "closes once it is done"
)
# The color and description of the labels of the bot, by name.
LABELS = {
    LABEL: (LABEL_COLOR, LABEL_DESCRIPTION),
    STEP: (STEP_COLOR, STEP_DESCRIPTION),
}
# The type of these issues, which every repository has among its labels.
TYPE = "maintenance"
PULL_REQUESTS = frozenset({"pull_request", "pull_request_target"})
# The verification that a published release starts: its run is one of the tag of the
# release, not of the default branch, and it is the only such run that counts.
VERIFICATION = "Release verification"
FAILED = frozenset({"failure", "timed_out", "startup_failure"})
OUT_OF_TIME = "The job has exceeded the maximum execution time"
# The annotation with which the step "Make sure that Harden-Runner blocks" stops a job
# whose Harden-Runner doesn't block, mostly in an outage of GitHub (decision 0134).
STOPPED = "would reach every host, and it stops here instead"
PASSED = "success"
# A second try only hides or repeats their failure: Findings fails while an alert is
# open, Mutation takes hours to measure the same again, and the property tests and the
# fuzzing failed on an input that they found. Not so when Harden-Runner stopped every
# job that failed: then the second try repeats nothing.
ONE_TRY = frozenset({"Findings", "Mutation", "Property tests", "Fuzzing"})
# Workflows that keep an issue of their own about their failures; a workflow of the
# project says so in a comment line of its own, `# bot-health: own-issue`. The daily
# look still starts one whose schedule GitHub missed or switched off.
OWN_ISSUE = frozenset({"Diagrams", "Newest Node", "Newest Python", "Next runner"})
# The bots of events: a pull request or a comment on one starts them, also one of a
# fork, and a failure shows on that pull request. Like the workflows that keep an issue
# of their own, they get no issue of the bot, and they run again only when
# Harden-Runner stopped them, which no later event of theirs makes up for (decision
# 0141).
EVENT_BOTS = frozenset({"Area labels", "Commands", "Dependabot maintenance", "Welcome"})
# The workflow of this bot, which can't follow its own end: the daily look gives the
# runs of it that Harden-Runner stopped their second try, those of the days back to
# STOPPED_SINCE, so that a look that GitHub starts late or misses leaves none behind.
# A run that ran again has a later attempt, so each gets one second try.
OWN_WORKFLOW = ".github/workflows/bot-health.yml"
# The blueprint bot, which the daily look starts for a newer release of the blueprint.
BLUEPRINT_BOT = ".github/workflows/blueprint-update.yml"
STOPPED_SINCE = timedelta(days=2)
OWN_ISSUE_LINE = re.compile(r"^[ \t]*#[ \t]*bot-health:[ \t]*own-issue\b", re.MULTILINE)
# The runs that do what a schedule does. A push may start the same workflow, but its
# run may leave out what only the schedule does, such as the tests of the coming
# release, so it doesn't count as the run of a schedule.
SCHEDULED = frozenset({"schedule", "workflow_dispatch"})
# A check that main runs only each week, not after a push, because a pull request
# reaches main only when it passes the check. Its failure at a commit that main has left
# says nothing about main any more: mostly the pull request of the fix bot left it.
CHECKED_BEFORE_THE_MERGE = frozenset({"Lint"})
# The runs of a workflow that one request lists, the newest first.
PAGE = 50
# A failure that ended this recently may still get its second try from the run that
# its end started; the daily look leaves it to that run.
SECOND_TRY_TIME = timedelta(hours=1)
# GitHub starts a scheduled run hours late at times; a time of a schedule counts as
# missed only when this much time has passed since.
LATE = timedelta(hours=6)
# The daily look starts at most this many workflows whose schedule GitHub missed, or
# whose latest run GitHub couldn't start, so that it doesn't take the runners of the
# account from the pull requests.
MOST_STARTS = 4
# How many earlier runs that passed the daily look reads when the latest passed
# without a job that failed, so that its requests stay few.
LOOK_BACK = 5
# The failed jobs that an issue lists, and the length of a line from a log.
MOST_JOBS = 10
LONGEST = 200
# The issue with Renovate's problems closes after this many runs in a row without one.
QUIET_RUNS = 3
RENOVATE = "renovate"
RENOVATE_TITLE = "Renovate logs problems"
RENOVATE_LABEL = "dependencies"
MOST_PROBLEMS = 50
# Renovate's line for two updates of one dependency in one branch, of which it drops
# one; the rules of the blueprint raise it from info to a warning.
COLLISION = "Ignoring upgrade collision"
# The issue that lists the pull requests of Renovate that Renovate leaves alone and
# that the daily look can't bring back by itself (decision 0144).
PARKED = "renovate-parked"
PARKED_TITLE = "Renovate leaves pull requests alone"
# Renovate's branches, its comment on a pull request whose branch it takes for edited,
# and the box in the description that asks it to build the branch anew.
RENOVATE_BRANCH = "renovate/"
EDITED = "Edited/Blocked Notification"
BOX = "<!-- rebase-check -->"
UNTICKED = f"- [ ] {BOX}"
TICKED = f"- [x] {BOX}"
# Renovate runs every two hours, or with every push of its Mend app: a box that has
# been ticked since the night before without a new branch needs a person. Less than a
# day, so that the next nightly run counts even when GitHub starts its cron a few
# minutes early.
REBUILD_TIME = timedelta(hours=20)
# The issue that asks for an upload of the social preview, which no API offers.
PREVIEW = "social-preview"
PREVIEW_TITLE = "Upload the new social preview"

# The state of an issue, in its body, where only the raw text shows it.
MARKER = re.compile(r"<!-- bot-health (\{.*?\}) -->")
CONTROL = re.compile(r"[\x00-\x1f\x7f]")
# The annotation that every failed step gets; another one says more.
GENERIC = re.compile(r"Process completed with exit code \d+\.")
REPOSITORY = re.compile(r"[\w.-]+/[\w.-]+")
# One item of the list on.schedule of a workflow, such as - cron: "17 4 * * 3", with
# or without quotes and with a comment after it; no other key of a workflow is cron.
CRON = re.compile(
    r"""^[ \t]*-[ \t]+cron:[ \t]*["']?([^"'#\n]*?)["']?[ \t\r]*(?:#.*)?$""", re.M
)
DISPATCH = re.compile(r"^  workflow_dispatch:.*\n((?:(?: {4}.*)?\n)*)", re.MULTILINE)
# The events of a workflow, under on: or after it on its line, and the workflows whose
# end starts it, listed after workflows: or below it.
ON_BLOCK = re.compile(r"^on:[ \t]*\n((?:(?:[ \t].*)?\n)*)", re.MULTILINE)
ON_LINE = re.compile(r"^on:[ \t]*\[?([\w, -]+?)\]?[ \t]*$", re.MULTILINE)
EVENT = re.compile(r"^  ([\w-]+):", re.MULTILINE)
AFTER = re.compile(r"^  workflow_run:.*\n((?:(?: {4}.*)?\n)*)", re.MULTILINE)
AFTER_NAMES = re.compile(
    r"^ {4}workflows:[ \t]*(?:\[([^\]\n]*)\]|\n((?: {6,}- .*\n)+))", re.MULTILINE
)
EVENTS = {
    "schedule": "its schedule",
    "push": "a push",
    "workflow_dispatch": "hand",
    "workflow_run": "the end of another workflow",
    "release": "a published release",
}


def code(text: str) -> str:
    """A line from a log or a name as Markdown code that fits into a cell of a table:
    the first line that isn't empty, without control characters, backticks or bare
    pipes, and shortened; nothing for an empty text."""
    line = next((part.strip() for part in text.splitlines() if part.strip()), "")
    line = CONTROL.sub(" ", line).replace("`", "'")
    if len(line) > LONGEST:
        line = line[: LONGEST - 1] + "…"
    escaped = line.replace("|", "\\|")
    return f"`{escaped}`" if line else ""


def marker(state: Mapping[str, Any]) -> str:
    # Two hyphens would end the comment; JSON reads the escaped one as a hyphen.
    text = json.dumps(state, sort_keys=True).replace("--", "-\\u002d")
    return f"<!-- bot-health {text} -->"


def moment(when: datetime) -> str:
    return when.strftime("%Y-%m-%d %H:%M UTC")


def default_branch(api: Api, repo: str) -> str:
    data = api.get(f"repos/{repo}")
    if not isinstance(data, dict):
        raise GitHubError(404, f"GitHub doesn't know the repository {repo}")
    return str(data["default_branch"])


@dataclass(frozen=True)
class Run:
    """A run of a workflow, in its latest attempt."""

    id: int
    name: str
    path: str
    event: str
    branch: str
    head: str
    sha: str
    status: str
    conclusion: str
    attempt: int
    url: str
    created: datetime
    updated: datetime

    @classmethod
    def of(cls, data: Json) -> Run:
        return cls(
            id=int(data["id"]),
            name=str(data["name"]),
            # A path may name the ref of the workflow after an @.
            path=str(data["path"]).split("@", 1)[0],
            event=str(data["event"]),
            branch=str(data.get("head_branch") or ""),
            head=str((data.get("head_repository") or {}).get("full_name") or ""),
            sha=str(data.get("head_sha") or ""),
            status=str(data["status"]),
            conclusion=str(data.get("conclusion") or ""),
            attempt=int(data.get("run_attempt") or 1),
            url=str(data["html_url"]),
            created=datetime.fromisoformat(data["created_at"]),
            updated=datetime.fromisoformat(data["updated_at"]),
        )

    def of_branch(self, repo: str, branch: str) -> bool:
        """Whether the run is one of the default branch, not one of a pull request."""
        return (
            self.event not in PULL_REQUESTS
            and self.branch == branch
            and self.head == repo
        )

    @property
    def key(self) -> str:
        """What its issue is about: the workflow, or for the verification of a published
        release that release. The weekly run checks the latest release only, so its pass
        says nothing about a prerelease that fails, and an issue of its own notifies
        where an update of the issue of the workflow would not."""
        return f"{self.path}@{self.branch}" if self.event == "release" else self.path

    def verifies_a_published_release(self, repo: str) -> bool:
        """Whether the run is the verification that a release starts when someone
        publishes it outside the release workflow."""
        return (
            self.event == "release" and self.name == VERIFICATION and self.head == repo
        )

    def deserves_second_try(self) -> bool:
        return (
            self.attempt == 1
            and self.conclusion != "startup_failure"
            and self.name not in ONE_TRY
        )

    def link(self) -> str:
        return f"[the run of {moment(self.created)}]({self.url})"


@dataclass(frozen=True)
class Issue:
    """An open issue of the bot, with the state in its body."""

    number: int
    body: str
    state: dict[str, Any]


def open_issues(
    api: Api, repo: str, labels: Iterable[str] = (LABEL,)
) -> dict[str, Issue]:
    """The open issues of the bot with one of the labels, by the key in their state:
    the path of a workflow, with the tag of a published release for its
    verification, renovate or social-preview. Where a key has several, because two
    runs of the bot opened one each at the same moment, the newest one stays and the
    older ones close."""
    found: dict[str, Issue] = {}
    listed = {
        int(item["number"]): item
        for label in labels
        for item in api.get(
            f"repos/{repo}/issues?labels={label}&state=open&per_page=100"
        )
        or []
    }
    # The newest first, also across the labels; an issue with both counts once.
    for number, item in sorted(listed.items(), reverse=True):
        body = str(item.get("body") or "")
        match = MARKER.search(body)
        if "pull_request" in item or not match:
            continue
        try:
            state = json.loads(match[1])
        except ValueError:
            continue
        key = state.get("key")
        if not isinstance(key, str):
            continue
        if key in found:
            close_issue(
                api,
                repo,
                number,
                f"Continued in #{found[key].number}.",
                reason="not_planned",
            )
        else:
            found[key] = Issue(number, body, state)
    return found


def open_issue(
    api: Api,
    repo: str,
    title: str,
    body: str,
    labels: Iterable[str],
    label: str = LABEL,
) -> int:
    """Opens an issue of the bot with its label, bot-failure or manual-step, and
    the other labels."""
    # The label of a repository whose .github/labels.toml, its own, lacks it.
    if not api.exists(f"repos/{repo}/labels/{label}"):
        color, description = LABELS[label]
        try:
            api.post(
                f"repos/{repo}/labels",
                {"name": label, "color": color, "description": description},
            )
        except GitHubError as error:
            # The run for another workflow created it a moment ago.
            if error.status != 422:
                raise
    response = api.request(
        "POST",
        f"repos/{repo}/issues",
        {"title": title, "body": body, "labels": [label, *labels]},
    )
    return int(response.data["number"])


def close_issue(
    api: Api, repo: str, number: int, comment: str, *, reason: str = "completed"
) -> None:
    api.post(f"repos/{repo}/issues/{number}/comments", {"body": comment})
    api.patch(
        f"repos/{repo}/issues/{number}",
        {"state": "closed", "state_reason": reason},
    )


# ---------------------------------------------------------------- failures of runs


@dataclass(frozen=True)
class Failure:
    """A failed job of a run: where it failed, and the first error that it names."""

    job: str
    url: str
    step: str
    error: str


def annotations_of(api: Api, repo: str, job: int) -> list[Json]:
    try:
        return api.get(f"repos/{repo}/check-runs/{job}/annotations?per_page=50") or []
    except GitHubError:
        return []


def first_error(api: Api, repo: str, job: int) -> str:
    errors = [
        str(annotation.get("message") or "")
        for annotation in annotations_of(api, repo, job)
        if annotation.get("annotation_level") == "failure"
    ]
    telling = [error for error in errors if not GENERIC.fullmatch(error.strip())]
    return (telling or errors or [""])[0]


def jobs_of(api: Api, repo: str, run: int, attempt: int) -> list[Json]:
    """The jobs that ran in an attempt of a run."""
    data = api.get(
        f"repos/{repo}/actions/runs/{run}/attempts/{attempt}/jobs?per_page=100"
    )
    return list((data or {}).get("jobs", []))


def failed_jobs(jobs: Sequence[Json]) -> list[Json]:
    """The jobs that failed or ran out of time. The jobs that a failure cancelled say
    nothing, unless only they ended badly."""
    failed = [job for job in jobs if job.get("conclusion") in ("failure", "timed_out")]
    return failed or [job for job in jobs if job.get("conclusion") == "cancelled"]


def failures(api: Api, repo: str, run: Run) -> list[Failure]:
    if run.conclusion == "startup_failure":
        return []
    found = []
    for job in failed_jobs(jobs_of(api, repo, run.id, run.attempt))[:MOST_JOBS]:
        steps = [
            str(step["name"])
            for step in job.get("steps") or []
            if step.get("conclusion") in ("failure", "timed_out", "cancelled")
        ]
        found.append(
            Failure(
                job=str(job["name"]),
                url=str(job["html_url"]),
                step=steps[0] if steps else "",
                error=first_error(api, repo, int(job["id"])),
            )
        )
    return found


def where(run: Run, found: list[Failure]) -> str:
    """A short mark of the places of a failure, to notice when they change."""
    places = sorted(f"{failure.job}\n{failure.step}" for failure in found)
    text = "\n\n".join(places) if found else run.conclusion
    return hashlib.sha256(text.encode()).hexdigest()[:12]


def failure_table(run: Run, found: list[Failure]) -> str:
    if run.conclusion == "startup_failure":
        return (
            "GitHub couldn't start the run: its workflow file is invalid, or it asks "
            "for more than the repository allows. The page of the run says which."
        )
    if not found:
        return "The run names no failed job; its page says why it failed."
    lines = ["| Job | Step that failed | First error |", "| --- | --- | --- |"]
    for failure in found:
        lines.append(
            f"| [{code(failure.job)}]({failure.url}) | {code(failure.step)} "
            f"| {code(failure.error)} |"
        )
    return "\n".join(lines)


def published_note(run: Run, tag: str) -> str:
    """What the issue about a published release adds: no other run closes it."""
    if run.event != "release":
        return ""
    return (
        f" Only a run for `{tag}` closes this issue: if the release shouldn't stand, "
        "take it down and close the issue by hand."
    )


def failure_body(run: Run, branch: str, found: list[Failure], state: Json) -> str:
    started = EVENTS.get(run.event, f"the event {run.event}")
    failed = state["failed"]
    runs = "1 failed run" if failed == 1 else f"{failed} failed runs"
    return (
        f"{marker(state)}\n"
        f"The workflow **{run.name}** fails on `{branch}` since {state['since']}, "
        f"{runs} so far. GitHub mails such a failure only to whoever started the run "
        "or last changed its schedule, often a bot, so this issue stays open until "
        "the jobs that failed pass again in a run; the bot-health bot closes it then. "
        "A run that skips one of them leaves it open: close it by hand when such a "
        "job is gone or has a new name.\n\n"
        f"**The latest failure:** {run.link()}, attempt {run.attempt}, started by "
        f"{started}.\n\n"
        f"{failure_table(run, found)}\n\n"
        "Fix what the error names, or run the workflow again under "
        f"*Actions → {run.name}* when GitHub was at fault. A failure in another job "
        f"or step adds a comment here.{published_note(run, branch)}\n"
    )


def report(api: Api, repo: str, branch: str, run: Run, issue: Issue | None) -> str:
    if (
        issue is not None
        and issue.state.get("run") == run.id
        and issue.state.get("attempt") == run.attempt
    ):
        return f"fails: #{issue.number} has this run"
    found = failures(api, repo, run)
    place = where(run, found)
    if issue is None:
        state = {
            "key": run.key,
            "run": run.id,
            "attempt": run.attempt,
            "failed": 1,
            "where": place,
            "since": run.created.date().isoformat(),
        }
        number = open_issue(
            api,
            repo,
            f"{run.name} fails on {branch}",
            failure_body(run, branch, found, state),
            [TYPE],
        )
        return f"fails: opened #{number}"
    state = {
        **issue.state,
        "run": run.id,
        "attempt": run.attempt,
        "failed": int(issue.state.get("failed") or 0) + 1,
        "where": place,
    }
    state.setdefault("since", run.created.date().isoformat())
    api.patch(
        f"repos/{repo}/issues/{issue.number}",
        {"body": failure_body(run, branch, found, state)},
    )
    if issue.state.get("where") == place:
        return f"fails again: updated #{issue.number}"
    api.post(
        f"repos/{repo}/issues/{issue.number}/comments",
        {
            "body": f"It now fails in another place, in {run.link()}:\n\n"
            f"{failure_table(run, found)}\n"
        },
    )
    return f"fails in another place: updated #{issue.number}"


def ran_out_of_time(api: Api, repo: str, run: Run) -> bool:
    """Whether GitHub cancelled a job of the run at its time limit, as it says on the
    job, and not a person or a newer run."""
    return any(
        OUT_OF_TIME in str(annotation.get("message"))
        for job in jobs_of(api, repo, run.id, run.attempt)
        if job.get("conclusion") == "cancelled"
        for annotation in annotations_of(api, repo, int(job["id"]))
    )


def stopped_by_harden_runner(
    api: Api, repo: str, run: Run, *, every: bool = False
) -> bool:
    """Whether the step that makes sure that Harden-Runner blocks stopped a job of the
    run that failed, as its annotation says, or with every, each job that failed, of
    which a job that ran out of its time is one that Harden-Runner didn't stop."""
    stopped = []
    for job in jobs_of(api, repo, run.id, run.attempt):
        conclusion = job.get("conclusion")
        if conclusion not in ("failure", "timed_out", "cancelled"):
            continue
        messages = [
            str(annotation.get("message"))
            for annotation in annotations_of(api, repo, int(job["id"]))
        ]
        if conclusion == "cancelled":
            # A job that GitHub cancelled at its time limit failed, one that a person,
            # a newer run or a failed leg of its matrix cancelled didn't.
            if every and any(OUT_OF_TIME in message for message in messages):
                stopped.append(False)
            continue
        stopped.append(
            conclusion == "failure" and any(STOPPED in message for message in messages)
        )
    return any(stopped) and (all(stopped) or not every)


def second_try_can_pass(api: Api, repo: str, run: Run) -> bool:
    """Whether a second try of the failed jobs can pass: at the first attempt of a run
    that started, and for a workflow of one try only when Harden-Runner stopped each
    job that failed, as the second try then repeats nothing of the failure."""
    if run.deserves_second_try():
        return True
    return (
        run.attempt == 1
        and run.conclusion == "failure"
        and run.name in ONE_TRY
        and stopped_by_harden_runner(api, repo, run, every=True)
    )


def base_name(job: str) -> str:
    """The name of a job without the values of its matrix, such as render for
    render (en, 3/4), which another run may give other values or other legs."""
    return job.split(" (", 1)[0]


def not_passed_again(api: Api, repo: str, run: Run, issue: Issue) -> list[str]:
    """The jobs that failed in the run of the issue and didn't pass again in the run,
    by the names before the values of a matrix: such a job passes again when it ran,
    in every leg, and passed. A workflow that plans first passes without the jobs
    that it skipped, and a skipped job proves nothing. A failure that names no failed
    job, as a run that GitHub couldn't start, leaves none."""
    failed = failed_jobs(
        jobs_of(
            api,
            repo,
            int(issue.state.get("run") or 0),
            int(issue.state.get("attempt") or 1),
        )
    )
    if not failed:
        return []
    # The latest try of each job, also of one that a second try of the failed jobs
    # left as it was.
    jobs = [
        (str(job["name"]), str(job.get("conclusion") or ""))
        for job in api.get_all(
            f"repos/{repo}/actions/runs/{run.id}/jobs?filter=latest&per_page=100"
        )
    ]
    missing = set()
    for job in failed:
        name = str(job["name"])
        base = base_name(name)
        if base == name:
            # A job outside a matrix is only itself, not a matrix that shares its name,
            # such as e2e beside e2e (Home Assistant beta).
            legs = {conclusion for other, conclusion in jobs if other == name}
        else:
            # A leg: every leg of its matrix, or the matrix as GitHub names it when it
            # skipped it as a whole.
            legs = {
                conclusion
                for other, conclusion in jobs
                if other == base or other.startswith(f"{base} (")
            }
        if legs != {PASSED}:
            missing.add(base)
    return sorted(missing)


def run_again(api: Api, repo: str, run: Run) -> bool | None:
    """Runs the failed jobs once more: True when it did, False when another bot was
    quicker, and None when GitHub refused and no one else did."""
    try:
        api.post(f"repos/{repo}/actions/runs/{run.id}/rerun-failed-jobs", {})
    except GitHubError:
        # Another bot, such as the one after the checks, may have been quicker.
        latest = api.get(f"repos/{repo}/actions/runs/{run.id}") or {}
        newer = int(latest.get("run_attempt") or 1) > run.attempt
        if newer or latest.get("status") != "completed":
            return False
        return None
    return True


def look_at(
    api: Api,
    repo: str,
    branch: str,
    run: Run,
    issues: Mapping[str, Issue],
    now: datetime,
    *,
    second_try: bool,
) -> str:
    """What a finished run of the default branch means for its issue. With second_try,
    a first failure that a second try can fix runs again instead; without it, as in
    the daily look, such a failure is reported once it is too old for its second try."""
    issue = issues.get(run.key)
    if run.conclusion == PASSED:
        if issue is None:
            return "passes"
        # The end of an older run may come after that of the failure: ids only grow.
        if run.id < int(issue.state.get("run") or 0):
            return f"passes, in a run older than the failure of #{issue.number}"
        missing = not_passed_again(api, repo, run, issue)
        if missing:
            jobs = "the job" if len(missing) == 1 else "the jobs"
            return (
                f"passes, but without {jobs} {', '.join(map(code, missing))} "
                f"that failed in #{issue.number}"
            )
        failed = int(issue.state.get("failed") or 0)
        runs = "1 failed run" if failed == 1 else f"{failed} failed runs"
        close_issue(
            api,
            repo,
            issue.number,
            f"Passes again in {run.link()}, after {runs}.",
        )
        return f"passes again: closed #{issue.number}"
    # A job that hangs until its time limit, as one behind Harden-Runner can, even after
    # its last step, ends as cancelled; it failed like one that GitHub calls timed out.
    if run.conclusion == "cancelled" and ran_out_of_time(api, repo, run):
        run = replace(run, conclusion="timed_out")
    if run.conclusion not in FAILED:
        return f"ended as {run.conclusion or run.status}: no failure"
    note = ""
    if second_try_can_pass(api, repo, run):
        if second_try:
            tried = run_again(api, repo, run)
            if tried is not None:
                return (
                    "failed: runs the failed jobs once more"
                    if tried
                    else "failed: another bot runs it again"
                )
            note = " (GitHub refused to run it again)"
        elif now - run.updated < SECOND_TRY_TIME:
            return "failed: its second try may still come"
    return report(api, repo, branch, run, issue) + note


def watch_run(
    api: Api, repo: str, run_id: int, now: datetime, notices: list[str] | None = None
) -> str:
    """The end of a run of a watched workflow, as the event of GitHub reports it; a
    run that GitHub couldn't start adds a notice to notices."""
    data = api.get(f"repos/{repo}/actions/runs/{run_id}")
    if data is None:
        return f"GitHub doesn't know the run {run_id}."
    run = Run.of(data)
    branch = default_branch(api, repo)
    published = run.verifies_a_published_release(repo)
    if not (run.of_branch(repo, branch) or published):
        return f"{run.name}: not a run of `{branch}`, nothing to do."
    if run.status != "completed":
        return f"{run.name}: runs again, its next end counts."
    # The nightly look runs a stopped run of this bot again up to STOPPED_SINCE late
    # (own_stops): by then a newer run of the workflow may have ended, which counts
    # instead, so an old commit neither runs again nor opens an issue.
    if (
        not published
        and now - run.updated >= SECOND_TRY_TIME
        and newer_end(api, repo, branch, data["workflow_id"], run)
    ):
        return (
            f"{run.name}: a newer run ended after {run.link()}, so that one counts, "
            "nothing to do."
        )
    # The daily look watches the runs of the branch alone: the verification of a
    # published release that GitHub couldn't start gets its second try or its report
    # here, as any other failure.
    if (
        not published
        and run.conclusion == "failure"
        and never_started(api, repo, run.id, run.attempt)
    ):
        # Another try while GitHub fails would only fail again, and mail again.
        if notices is not None:
            notices.append(couldnt_start(f"the run {run.id} of {run.name}"))
        later = (
            "the daily look runs it again"
            if run.attempt == 1
            else "the daily look reports it if its last attempt never started either"
        )
        return (
            f"{run.name}: GitHub couldn't start {run.link()}, so there is nothing to "
            f"report; {later}."
        )
    issues = open_issues(api, repo)
    ref = run.branch if published else branch
    return f"{run.name}: {look_at(api, repo, ref, run, issues, now, second_try=True)}."


def newer_end(api: Api, repo: str, branch: str, workflow: int, run: Run) -> bool:
    """Whether a run of the workflow on the branch that came after the run has ended,
    as passed or failed."""
    return any(
        later.id > run.id
        and later.status == "completed"
        and (later.conclusion == PASSED or later.conclusion in FAILED)
        for later in runs_of(api, repo, branch, {"id": workflow}, most=PAGE)
        if later.of_branch(repo, branch)
    )


def watch_stop(api: Api, repo: str, run_id: int) -> tuple[str, bool]:
    """The end of a run of a bot of events or of a workflow that keeps an issue of its
    own, as the event of GitHub reports it, also one of a pull request or of a fork:
    its failed jobs run once more when Harden-Runner stopped one of them at the first
    attempt, and any other end stays theirs. False when GitHub refused that try."""
    data = api.get(f"repos/{repo}/actions/runs/{run_id}")
    if data is None:
        return f"GitHub doesn't know the run {run_id}.", True
    run = Run.of(data)
    if run.status != "completed" or run.attempt > 1:
        return f"{run.name}: runs again already, nothing to do.", True
    if run.conclusion != "failure":
        return f"{run.name}: ended as {run.conclusion}, nothing to do.", True
    if not stopped_by_harden_runner(api, repo, run):
        return (
            f"{run.name}: Harden-Runner stopped no job of {run.link()}, which stays "
            "failed.",
            True,
        )
    text, done = again_after_a_stop(api, repo, run)
    return f"{run.name}: {text}.", done


def again_after_a_stop(api: Api, repo: str, run: Run) -> tuple[str, bool]:
    """Runs the failed jobs of a run that Harden-Runner stopped once more: what
    happened, and False when GitHub refused that try."""
    tried = run_again(api, repo, run)
    if tried is None:
        return (
            f"Harden-Runner stopped a job of {run.link()}, and GitHub refused to run "
            "it again",
            False,
        )
    again = "its failed jobs run once more" if tried else "another bot runs it again"
    return f"Harden-Runner stopped a job of {run.link()}, so {again}", True


# ---------------------------------------------------------------- the daily look

# The days that the time of a schedule may lie back: every cron comes round within
# a year, but that of a February 29th.
LOOKBACK = 366
MONTHS = (
    "JAN",
    "FEB",
    "MAR",
    "APR",
    "MAY",
    "JUN",
    "JUL",
    "AUG",
    "SEP",
    "OCT",
    "NOV",
    "DEC",
)
WEEKDAYS = ("SUN", "MON", "TUE", "WED", "THU", "FRI", "SAT")
# The five fields of a cron, each with its lowest and highest value and the names of
# its values from the lowest on: the minute, the hour, the day of the month, the month
# and the day of the week, in which 0 and 7 are Sunday.
FIELDS: tuple[tuple[int, int, tuple[str, ...]], ...] = (
    (0, 59, ()),
    (0, 23, ()),
    (1, 31, ()),
    (1, 12, MONTHS),
    (0, 7, WEEKDAYS),
)


def field_values(
    text: str, low: int, high: int, names: Sequence[str]
) -> frozenset[int]:
    """The values of one field of a cron, from low to high: a list of values, ranges
    such as 1-5 and steps such as */15, 10-50/20 or 5/15, from 5 to high, of numbers
    or of names, such as MON or JAN. ValueError for anything else."""

    def value(item: str) -> int:
        named = item.upper()
        if named in names:
            return low + names.index(named)
        if not (item.isascii() and item.isdigit()):
            raise ValueError(f"{item!r} is no number")
        return int(item)

    found: set[int] = set()
    for part in text.split(","):
        span, slash, every = part.partition("/")
        step = int(every) if slash else 1
        if span == "*":
            first, last = low, high
        else:
            start, dash, end = span.partition("-")
            first = value(start)
            last = value(end) if dash else high if slash else first
        if step < 1 or not low <= first <= last <= high:
            raise ValueError(f"{part!r} is no value from {low} to {high}")
        found.update(range(first, last + 1, step))
    return frozenset(found)


@dataclass(frozen=True)
class Cron:
    """A schedule of a workflow as GitHub reads its cron, in UTC: the minutes, hours,
    days of the month, months and days of the week, 0 for Sunday, at which it is due.
    As in cron, a day is due when both kinds of day match it, or either of them where
    both fields are limited: a field that starts with * limits nothing."""

    minutes: frozenset[int]
    hours: frozenset[int]
    days: frozenset[int]
    months: frozenset[int]
    weekdays: frozenset[int]
    either: bool

    @classmethod
    def parse(cls, text: str) -> Cron | None:
        """The schedule of a cron such as "17 4 * * 3", or None for one that cron
        can't read, which GitHub doesn't run either."""
        fields = text.split()
        if len(fields) != len(FIELDS):
            return None
        try:
            minutes, hours, days, months, weekdays = (
                field_values(found, *limits)
                for found, limits in zip(fields, FIELDS, strict=True)
            )
        except ValueError:
            return None
        return cls(
            minutes,
            hours,
            days,
            months,
            frozenset(weekday % 7 for weekday in weekdays),
            not fields[2].startswith("*") and not fields[4].startswith("*"),
        )

    def runs_on(self, day: date) -> bool:
        if day.month not in self.months:
            return False
        of_month = day.day in self.days
        of_week = day.isoweekday() % 7 in self.weekdays
        return (of_month or of_week) if self.either else (of_month and of_week)

    def last(self, moment: datetime) -> datetime | None:
        """The last time at which the schedule was due, at moment or before it, to the
        minute: None when it wasn't within LOOKBACK days."""
        for back in range(LOOKBACK + 1):
            day = moment.date() - timedelta(days=back)
            if not self.runs_on(day):
                continue
            for hour in sorted(self.hours, reverse=True):
                if back == 0 and hour > moment.hour:
                    continue
                latest = moment.minute if back == 0 and hour == moment.hour else 59
                minutes = [minute for minute in self.minutes if minute <= latest]
                if minutes:
                    return datetime.combine(day, time(hour, max(minutes)), UTC)
        return None


def dispatch_inputs(text: str) -> dict[str, str] | None:
    """The inputs that start a workflow by hand as its schedule does, or None when it
    can't be started by hand. Findings and Issue lifecycle only show what they would
    do when started by hand, unless dry_run is false."""
    found = DISPATCH.search(text)
    if found is None:
        return None
    return {"dry_run": "false"} if re.search(r"^ {6}dry_run:", found[1], re.M) else {}


def missed_time(text: str, now: datetime) -> tuple[datetime | None, bool]:
    """The latest time of the schedules of a workflow that is late enough to have run
    by now, and whether every schedule could be read."""
    crons = [Cron.parse(line) for line in CRON.findall(text)]
    times = [
        time
        for cron in crons
        if cron is not None
        for time in [cron.last(now - LATE)]
        if time is not None
    ]
    return max(times, default=None), None not in crons


def events_of(text: str) -> frozenset[str]:
    """The events that start a workflow, from its on: in either form."""
    block = ON_BLOCK.search(text)
    if block is not None:
        return frozenset(EVENT.findall(block[1]))
    line = ON_LINE.search(text)
    names = line[1].split(",") if line else []
    return frozenset(name.strip() for name in names) - {""}


def started_after(text: str) -> frozenset[str] | None:
    """The workflows whose end starts this one: none without workflow_run, and None
    when its list can't be read."""
    block = AFTER.search(text)
    if block is None:
        return frozenset()
    listed = AFTER_NAMES.search(block[1])
    if listed is None:
        return None
    if listed[1] is not None:
        names = listed[1].split(",")
    else:
        names = [line.strip().removeprefix("- ") for line in listed[2].splitlines()]
    return frozenset(name.strip().strip("\"'") for name in names) - {""}


def after_pull_requests(texts: Iterable[tuple[str, str]]) -> frozenset[str]:
    """The names of the workflows, from their names and texts, that the end of a run
    for a pull request starts as well, such as After the checks. GitHub gives such a
    run the default branch and its latest commit, and only its jobs know that it was
    for a pull request, so the daily look counts their other runs alone."""
    texts = list(texts)
    of_pull_requests = {name for name, text in texts if events_of(text) & PULL_REQUESTS}
    found: set[str] = set()
    grows = True
    while grows:
        grows = False
        for name, text in texts:
            after = started_after(text)
            if name not in found and (after is None or after & of_pull_requests):
                found.add(name)
                of_pull_requests.add(name)
                grows = True
    return frozenset(found)


def own_issue(text: str) -> bool:
    """Whether the text of a workflow says that it keeps an issue of its own."""
    return OWN_ISSUE_LINE.search(text) is not None


def leave_to_its_own(
    api: Api, repo: str, workflow: Json, issues: Mapping[str, Issue]
) -> str:
    """What the daily look does for a workflow that keeps an issue of its own: the
    issue that the bot opened for it before it said so closes, as continued there."""
    issue = issues.get(str(workflow["path"]))
    if issue is None:
        return "keeps an issue of its own"
    close_issue(
        api,
        repo,
        issue.number,
        f"{code(str(workflow['name']))} keeps an issue of its own about its failures "
        "now, so this one closes.",
        reason="not_planned",
    )
    return f"keeps an issue of its own: closed #{issue.number}"


def runs_of(
    api: Api, repo: str, branch: str, workflow: Json, *, event: str = "", most: int
) -> list[Run]:
    """A page of the runs of a workflow on the branch, the newest first; with event,
    only those that it started. GitHub lists the runs of a pull request from a branch
    of the same name too, such as main of a fork."""
    of_event = f"&event={event}" if event else ""
    listed = api.get(
        f"repos/{repo}/actions/workflows/{workflow['id']}/runs"
        f"?branch={urllib.parse.quote(branch)}{of_event}"
        f"&exclude_pull_requests=true&per_page={most}"
    )
    return [Run.of(item) for item in (listed or {}).get("workflow_runs", [])]


def left_behind(api: Api, repo: str, branch: str, run: Run) -> bool:
    """Whether the run is a failure of a check of CHECKED_BEFORE_THE_MERGE at a commit
    that the branch has left."""
    if run.name not in CHECKED_BEFORE_THE_MERGE or run.conclusion == PASSED:
        return False
    ref = api.get(f"repos/{repo}/git/ref/heads/{branch}") or {}
    return str((ref.get("object") or {}).get("sha") or run.sha) != run.sha


def scheduled_since(runs: Iterable[Run], repo: str, branch: str, due: datetime) -> bool:
    """Whether one of the runs is one of the branch that its schedule or a hand
    started, as the daily look starts one, at the time due or after it."""
    return any(
        run.of_branch(repo, branch) and run.event in SCHEDULED and run.created >= due
        for run in runs
    )


def ran_as_scheduled(
    api: Api,
    repo: str,
    branch: str,
    workflow: Json,
    listed: Sequence[Run],
    due: datetime,
) -> bool:
    """Whether the schedule's work ran since the time due. Where a full page of runs,
    listed, all began after it, GitHub names the latest run of each such event too."""
    if scheduled_since(listed, repo, branch, due):
        return True
    if len(listed) < PAGE or min(run.created for run in listed) < due:
        return False
    return any(
        scheduled_since(
            runs_of(api, repo, branch, workflow, event=event, most=1), repo, branch, due
        )
        for event in sorted(SCHEDULED)
    )


def passed_before(
    api: Api,
    repo: str,
    branch: str,
    runs: Sequence[Run],
    finished: Run,
    issues: Mapping[str, Issue],
    now: datetime,
    note: str,
    *,
    after_checks: bool,
) -> str:
    """The note of the latest finished run, or of an earlier one that closes the issue
    when the latest passed without a job that failed. The daily look stands in for
    an end of a run that GitHub lost, which may be that of a run before the latest,
    as one that rendered before a push that only planned; it looks at the
    LOOK_BACK latest of them that came after the failure."""
    if not note.startswith("passes, but without"):
        return note
    since = int(issues[finished.key].state.get("run") or 0)
    earlier = [
        run
        for run in runs
        if run.id != finished.id
        and run.id > since
        and run.status == "completed"
        and run.conclusion == PASSED
        and not (after_checks and run.event == "workflow_run")
    ][:LOOK_BACK]
    for run in earlier:
        closed = look_at(api, repo, branch, run, issues, now, second_try=False)
        if closed.startswith("passes again"):
            return closed
    return note


def catch_up(api: Api, repo: str, run: Run, *, start: bool) -> tuple[str, bool]:
    """What the daily look does for the latest run of a workflow, which GitHub
    couldn't start at its first attempt, and whether it started it again: as a whole,
    as GitHub ran none of it, or only jobs that passed."""
    if not start:
        return (
            f"GitHub couldn't start {run.link()}: runs it again in a later look, "
            f"{MOST_STARTS} a day",
            False,
        )
    api.post(f"repos/{repo}/actions/runs/{run.id}/rerun", {})
    return f"GitHub couldn't start {run.link()}: runs it again", True


def look_after(
    api: Api,
    repo: str,
    branch: str,
    workflow: Json,
    issues: Mapping[str, Issue],
    text: str,
    now: datetime,
    *,
    start: bool,
    after_checks: bool,
    own: bool,
    again: Collection[int] = (),
) -> tuple[str, bool]:
    """What the daily look does for one workflow, and whether it started it. With
    after_checks, a run that the end of another workflow started doesn't count, nor
    does a run of again, which runs once more after the look. With
    own, the workflow keeps an issue of its own: its runs open and close none here,
    and only an issue of the bot from before it said so closes. A latest run that
    GitHub couldn't start runs again once; when GitHub couldn't start that try
    either, the run is reported like any failure."""
    listed = runs_of(api, repo, branch, workflow, most=PAGE)
    runs = [run for run in listed if run.of_branch(repo, branch)]
    finished = next(
        (
            run
            for run in runs
            if run.status == "completed"
            and (run.conclusion == PASSED or run.conclusion in FAILED)
            and not (after_checks and run.event == "workflow_run")
            and run.id not in again
        ),
        None,
    )
    notes = []
    waits = False
    started = False
    if own:
        notes.append(leave_to_its_own(api, repo, workflow, issues))
    elif finished is None:
        notes.append(f"no finished run on `{branch}`")
    elif (
        finished.conclusion == "failure"
        and finished.attempt == 1
        and never_started(api, repo, finished.id, finished.attempt)
    ):
        note, started = catch_up(api, repo, finished, start=start)
        notes.append(note)
    elif left_behind(api, repo, branch, finished):
        notes.append(f"failed at {finished.sha[:7]}, which `{branch}` has left")
        # Only a run of the branch closes its issue, and without an issue the next
        # weekly run is soon enough.
        waits = finished.key in issues
    else:
        # Nothing else starts the second try of Lint: after the checks leaves it out,
        # and bot health doesn't watch its end.
        second_try = finished.name in CHECKED_BEFORE_THE_MERGE
        notes.append(
            passed_before(
                api,
                repo,
                branch,
                runs,
                finished,
                issues,
                now,
                look_at(
                    api, repo, branch, finished, issues, now, second_try=second_try
                ),
                after_checks=after_checks,
            )
        )
    if workflow["state"] == "disabled_inactivity":
        api.put(f"repos/{repo}/actions/workflows/{workflow['id']}/enable")
        notes.append("GitHub had switched it off for inactivity: on again")
    due, readable = missed_time(text, now)
    if not readable:
        notes.append("a schedule that can't be read")
    reason = f"its issue waits for a run of the head of `{branch}`" if waits else ""
    if started:
        # The run that starts again is the one of its schedule.
        reason = ""
    elif due is not None and not ran_as_scheduled(
        api, repo, branch, workflow, listed, due
    ):
        reason = f"missed its run of {moment(due)}"
    if reason:
        inputs = dispatch_inputs(text)
        if inputs is None:
            notes.append(f"{reason}, and can't be started by hand")
        elif not start:
            notes.append(f"{reason}: starts in a later look, {MOST_STARTS} a day")
        else:
            api.post(
                f"repos/{repo}/actions/workflows/{workflow['id']}/dispatches",
                {"ref": branch, "inputs": inputs},
            )
            notes.append(f"{reason}: started")
            started = True
    return "; ".join(notes), started


def own_stops(
    api: Api, repo: str, branch: str, workflow: Json, now: datetime
) -> list[Run]:
    """The runs of this bot that Harden-Runner stopped at their first attempt, after
    the end of another workflow, since STOPPED_SINCE, which need a second try: the job
    run gives it to every other bot, so that the end that they looked at is looked at
    again and their run passes (decision 0141). A stopped daily look needs none, as
    the next one makes it up."""
    since = (now - STOPPED_SINCE).strftime("%Y-%m-%dT%H:%M:%SZ")
    listed = api.get(
        f"repos/{repo}/actions/workflows/{workflow['id']}/runs"
        f"?branch={urllib.parse.quote(branch)}&event=workflow_run&status=failure"
        f"&created=%3E%3D{since}&exclude_pull_requests=true&per_page=100"
    )
    # GitHub lists the runs that ended as a failure in their latest attempt; one that
    # ran again already is left to that attempt.
    return [
        run
        for item in (listed or {}).get("workflow_runs", [])
        for run in [Run.of(item)]
        if run.attempt == 1 and stopped_by_harden_runner(api, repo, run, every=True)
    ]


def again_after_stops(
    api: Api, repo: str, runs: Iterable[Run]
) -> tuple[list[str], bool]:
    """Runs the failed jobs of each of the stopped runs once more: lines for the
    summary, and False when GitHub refused a try."""
    lines = []
    done = True
    for run in runs:
        text, tried = again_after_a_stop(api, repo, run)
        lines.append(f"- {text}.")
        done = done and tried
    return lines, done


def newer_release(tag: str, have: str) -> bool:
    """Whether the latest release of the blueprint comes after the version that this
    repository has, as Copier names it. GitHub calls a release latest by its date or by
    hand, so an older one is never newer; a version without a number leaves it to the
    blueprint bot, which verifies the release and refuses an older one."""
    have = released_tag(have)
    ours, theirs = version_key(have), version_key(tag)
    if ours is None or theirs is None:
        return tag != have
    return theirs > ours


def blueprint_report(
    api: Api, repo: str, root: Path, branch: str, bot: Json | None, *, start: bool
) -> tuple[str, bool]:
    """The daily look for a newer release of the blueprint, and whether it started the
    blueprint bot for it. The Rollout starts the bots of the release app of the
    blueprint right after a release; every other repository, such as one of another
    owner, would wait for the weekly run of its bot, so this look starts it within a
    day, without a schedule of its own (decision 0052). Nothing to say without the bot
    or its answers, as in the blueprint itself."""
    answers = root / ANSWERS
    if bot is None or bot["state"] != "active" or not answers.is_file():
        return "", False
    text = answers.read_text(encoding="utf-8")
    source = answered_source(text)
    if not source:
        return "", False
    have = answered_release(text)
    latest = api.get(f"repos/{source}/releases/latest") or {}
    tag = str(latest.get("tag_name") or "")
    if not tag:
        return f"{code(source)} has no published release.", False
    if not newer_release(tag, have):
        return (
            f"This repository has {code(have)}, and {code(tag)} is the latest.",
            False,
        )
    brought = api.get(f"repos/{repo}/contents/{ANSWERS}?ref={UPDATE_BRANCH}")
    content = str((brought or {}).get("content") or "")
    if released_tag(answered_release(base64.b64decode(content).decode())) == tag:
        return f"The pull request of the blueprint bot brings {code(tag)}.", False
    out = f"{code(tag)} is out, and this repository has {code(have or 'none')}"
    # A run of the bot that the Rollout or its schedule started brings it already,
    # and one that failed since the release fails again: its weekly run and its issue
    # of failures take that on, so that the owner gets no mail of a failure each day.
    runs = runs_of(api, repo, branch, bot, most=50)
    if any(run.status != "completed" for run in runs):
        return f"{out}: the blueprint bot is running already.", False
    published = str(latest.get("published_at") or "")
    if published and any(
        run.conclusion == "failure" and run.created >= datetime.fromisoformat(published)
        for run in runs[:1]
    ):
        return (
            f"{out}: the blueprint bot failed on it, so it waits for its weekly run.",
            False,
        )
    if not start:
        return f"{out}: the blueprint bot starts in a later look.", False
    api.post(f"repos/{repo}/actions/workflows/{bot['id']}/dispatches", {"ref": branch})
    return f"{out}: started the blueprint bot.", True


def sweep(
    api: Api,
    repo: str,
    root: Path,
    now: datetime,
    opener: Callable[..., Any] | None = None,
    notices: list[str] | None = None,
) -> tuple[str, bool]:
    """The daily look at every workflow of the repository and at its social preview; a
    summary in Markdown, and whether every look succeeded. A look that GitHub kept
    failing, as in an outage, is no failure of the bot: the next day's look catches
    up, and notices gets one notice about it."""
    branch = default_branch(api, repo)
    # The issue of the social preview has the label manual-step, or bot-failure
    # where an earlier release opened it.
    issues = open_issues(api, repo, (LABEL, STEP))
    listed = api.get(f"repos/{repo}/actions/workflows?per_page=100")
    workflows = sorted(
        (listed or {}).get("workflows", []), key=lambda item: str(item["name"]).lower()
    )
    lines = [
        "### Bot health",
        "",
        f"The latest finished run of every workflow on `{branch}`, and the schedules "
        "that GitHub missed.",
        "",
        "| Workflow | State |",
        "| --- | --- |",
    ]
    texts = {
        str(workflow["path"]): path.read_text(encoding="utf-8")
        if path.is_file()
        else ""
        for workflow in workflows
        for path in [root / str(workflow["path"])]
    }
    after_checks = after_pull_requests(
        (str(workflow["name"]), texts[str(workflow["path"])]) for workflow in workflows
    )
    starts = 0
    begun: set[int] = set()
    healthy = True
    own = next((item for item in workflows if item["path"] == OWN_WORKFLOW), None)
    stopped: list[Run] = []
    stops: list[str] = []
    if own is not None and own["state"] == "active":
        try:
            stopped = own_stops(api, repo, branch, own, now)
        except GitHubError as error:
            stops = [f"Couldn't look: {code(str(error))}"]
            healthy = error.unavailable
            if error.unavailable and notices is not None:
                notices.append(unreachable(error))
    for workflow in workflows:
        name = str(workflow["name"])
        if str(workflow["path"]).startswith("dynamic/") or workflow["state"] not in (
            "active",
            "disabled_inactivity",
        ):
            continue
        text = texts[str(workflow["path"])]
        try:
            state, started = look_after(
                api,
                repo,
                branch,
                workflow,
                issues,
                text,
                now,
                start=starts < MOST_STARTS,
                after_checks=name in after_checks,
                own=name in OWN_ISSUE or own_issue(text),
                # A stopped run of this bot gets its second try below, so that it
                # opens no issue that its second try closes again.
                again={run.id for run in stopped} if workflow is own else (),
            )
        except GitHubError as error:
            state, started = f"couldn't look: {code(str(error))}", False
            if not error.unavailable:
                healthy = False
            elif notices is not None and not notices:
                notices.append(unreachable(error))
        starts += started
        if started:
            begun.add(int(workflow["id"]))
        lines.append(f"| {code(name)} | {state} |")
    # After the look at every workflow, so that a look that runs again doesn't meet
    # the daily look at the same workflow.
    if stopped:
        try:
            stops, tried = again_after_stops(api, repo, stopped)
        except GitHubError as error:
            stops, tried = [f"Couldn't look: {code(str(error))}"], error.unavailable
            if error.unavailable and notices is not None and not notices:
                notices.append(unreachable(error))
        healthy = healthy and tried
    if own is not None and stops:
        heading = f"#### Runs of {code(str(own['name']))} that Harden-Runner stopped"
        lines += ["", heading, "", *stops]
    # A bot that the look started for its missed schedule brings the release anyway.
    bot = next((item for item in workflows if item["path"] == BLUEPRINT_BOT), None)
    if bot is not None and int(bot["id"]) not in begun:
        try:
            news, started = blueprint_report(
                api, repo, root, branch, bot, start=starts < MOST_STARTS
            )
        except GitHubError as error:
            news, started = f"Couldn't look: {code(str(error))}", False
            if not error.unavailable:
                healthy = False
            elif notices is not None and not notices:
                notices.append(unreachable(error))
        starts += started
        if news:
            lines += ["", "#### Releases of the blueprint", "", news]
    try:
        uploaded = preview_report(api, repo, root, branch, issues.get(PREVIEW), opener)
    except (GitHubError, Unreadable) as error:
        uploaded = f"Couldn't compare it: {code(str(error))}"
        if isinstance(error, GitHubError) and error.unavailable:
            # As for the workflows: the next look compares it (decision 0136).
            if notices is not None and not notices:
                notices.append(unreachable(error))
        else:
            healthy = False
    if uploaded:
        lines += ["", "#### Social preview", "", uploaded]
    try:
        parked = parked_report(api, repo, issues.get(PARKED), now)
    except GitHubError as error:
        parked = f"Couldn't look: {code(str(error))}"
        if not error.unavailable:
            healthy = False
        elif notices is not None and not notices:
            notices.append(unreachable(error))
    if parked:
        lines += ["", "#### Pull requests of Renovate", "", parked]
    return "\n".join(lines) + "\n", healthy


# ---------------------------------------------------------------- social preview


def preview_body(
    repo: str, branch: str, commit: str, uploaded: bool, fits: bool = True
) -> str:
    """What the issue asks for: the picture, where to upload it, and when it closes."""
    picture = f"https://github.com/{repo}/blob/{commit}/{PICTURE}?raw=true"
    shown = "an older social preview" if uploaded else "a card of its own"
    # GitHub refuses an upload of more than 1 MB: such a picture is rendered anew first.
    smaller = (
        ""
        if fits
        else (
            f"`{PICTURE}` is larger than the 1 MB that GitHub takes for an upload: "
            "first render a smaller one, with fewer colors or a simpler screenshot, "
            "with `bash scripts/social-preview.sh`, and commit it.\n\n"
        )
    )
    return (
        f"GitHub shows {shown} for links to this repository, not `{PICTURE}` of "
        f"`{branch}`. GitHub takes the social preview only from an upload, which no "
        "API offers:\n\n"
        f"{smaller}"
        f"1. Save [the picture]({picture}).\n"
        "2. Upload it under [Settings → General → Social preview]"
        f"({settings_page(repo)}): **Edit** → **Upload an image…**\n\n"
        "The nightly look of the bot-health bot closes this issue once GitHub shows "
        "this picture, byte for byte.\n\n"
        f"![The new social preview, {PICTURE} of {branch}]({picture})\n"
    )


def preview_report(
    api: Api,
    repo: str,
    root: Path,
    branch: str,
    issue: Issue | None,
    opener: Callable[..., Any] | None = None,
) -> str:
    """Keeps the one issue that asks for an upload of the committed social preview
    current: open while GitHub shows another picture, closed once it shows this one.
    A page that still shows the default panel of its kind has no picture to upload
    yet, since the checklist asks first to replace the panel, and a repository
    without the picture has none. A private repository shows no social preview, so
    it waits for none until it is public. The line of the summary; empty when there
    is nothing to say."""
    if not (root / PICTURE).is_file() or not shows_the_project(root):
        if issue is None:
            return ""
        close_issue(
            api,
            repo,
            issue.number,
            f"`{PICTURE}` is gone, or `{SOURCE}` shows the default panel "
            "again: there is no picture to upload.",
            reason="not_planned",
        )
        return f"No picture to upload: closed #{issue.number}."
    found = preview(api, repo, root, opener)
    if not found.public:
        if issue is None:
            return f"{PRIVATE}: the look compares nothing until it is public."
        close_issue(
            api,
            repo,
            issue.number,
            f"{PRIVATE}: the nightly look asks for the upload again once it is public.",
            reason="not_planned",
        )
        return f"The repository is private: closed #{issue.number}."
    if found.current:
        if issue is None:
            return f"GitHub shows `{PICTURE}`."
        close_issue(
            api, repo, issue.number, f"GitHub shows `{PICTURE}` of `{branch}` now."
        )
        return f"GitHub shows `{PICTURE}` now: closed #{issue.number}."
    if issue is not None and issue.state.get("sha256") == found.picture:
        return f"GitHub shows another picture than `{PICTURE}`: #{issue.number}."
    # The commit that last changed the picture, so that the issue shows that one,
    # not one that a cache keeps under the name of the branch.
    commits = api.get(
        f"repos/{repo}/commits?sha={urllib.parse.quote(branch)}"
        f"&path={PICTURE}&per_page=1"
    )
    commit = str(commits[0]["sha"]) if commits else branch
    body = (
        f"{marker({'key': PREVIEW, 'sha256': found.picture})}\n"
        f"{preview_body(repo, branch, commit, found.uploaded, found.fits)}"
    )
    if issue is None:
        number = open_issue(api, repo, PREVIEW_TITLE, body, [TYPE], STEP)
        return f"GitHub shows another picture than `{PICTURE}`: opened #{number}."
    api.patch(f"repos/{repo}/issues/{issue.number}", {"body": body})
    return f"`{PICTURE}` changed again: updated #{issue.number}."


# ---------------------------------------------------------------- Renovate


@dataclass(frozen=True, order=True)
class Problem:
    """A warning or an error in the log of Renovate."""

    repository: str
    level: str
    message: str
    place: str
    detail: str


def problem_of(entry: Mapping[str, Any]) -> Problem:
    error = entry.get("err")
    detail = ""
    if isinstance(error, dict):
        detail = str(error.get("message") or "")
    if entry.get("msg") == COLLISION:
        detail = (
            f"{entry.get('depName')} {entry.get('currentValue')}: "
            f"kept {entry.get('previousNewValue')}, "
            f"dropped {entry.get('thisNewValue')}"
        )
    place = next(
        (
            str(entry[key])
            for key in ("packageFile", "lockFile", "depName", "branch")
            if entry.get(key)
        ),
        "",
    )
    return Problem(
        repository=str(entry.get("repository") or ""),
        level="WARN" if entry["level"] < 50 else "ERROR",
        message=str(entry.get("msg") or ""),
        place=place,
        detail=detail,
    )


def renovate_problems(lines: Iterable[str]) -> dict[Problem, int]:
    """The warnings and errors of Renovate's log in JSON, each with how often it came."""
    problems: dict[Problem, int] = {}
    for line in lines:
        try:
            entry = json.loads(line)
        except ValueError:
            continue
        if not isinstance(entry, dict):
            continue
        level = entry.get("level")
        if not isinstance(level, int) or level < 40:
            continue
        problem = problem_of(entry)
        problems[problem] = problems.get(problem, 0) + 1
    return problems


def is_public(api: Api, name: str, known: dict[str, bool]) -> bool:
    """Whether a repository is public; an issue of the blueprint names no other."""
    if name not in known:
        data = None
        if REPOSITORY.fullmatch(name):
            try:
                data = api.get(f"repos/{name}")
            except GitHubError:
                data = None
        known[name] = isinstance(data, dict) and data.get("private") is False
    return known[name]


def problems_text(problems: Mapping[Problem, int], hidden: int, run: str) -> str:
    lines = [
        f"Renovate logged warnings or errors in [its run]({run}). Each can mean that a "
        "dependency gets no update, as when it skipped two lock files for days with "
        "no more than a warning. This issue lists those of the latest run with "
        f"problems and closes after {QUIET_RUNS} runs in a row without one."
    ]
    by_repository: dict[str, list[tuple[Problem, int]]] = {}
    for problem, count in sorted(problems.items())[:MOST_PROBLEMS]:
        by_repository.setdefault(problem.repository, []).append((problem, count))
    for repository, items in by_repository.items():
        lines += [
            "",
            f"### {repository or 'Renovate itself'}",
            "",
            "| Level | Message | Where | Detail | Times |",
            "| --- | --- | --- | --- | --- |",
        ]
        for problem, count in items:
            lines.append(
                f"| {problem.level} | {code(problem.message)} | {code(problem.place)} "
                f"| {code(problem.detail)} | {count} |"
            )
    if len(problems) > MOST_PROBLEMS:
        lines += [
            "",
            f"And {len(problems) - MOST_PROBLEMS} more in the log of the run.",
        ]
    if any(problem.message == COLLISION for problem in problems):
        lines += [
            "",
            "An upgrade collision: two updates of one dependency met in one branch, "
            "and Renovate committed only the one that it kept, or neither when that "
            "one waits, as for its release age. A rule that gives one of them a branch "
            "of its own keeps them apart, as the blueprint does with the digests of "
            "the images.",
        ]
    if hidden:
        lines += [
            "",
            f"{hidden} more in private repositories, which a public issue doesn't name.",
        ]
    return "\n".join(lines) + "\n"


def renovate_report(
    api: Api, repo: str, logs: Iterable[Path], run: str, *, complete: bool = True
) -> str:
    """Keeps the one issue with Renovate's problems current from the logs of a run, one
    for each repository where the blueprint runs Renovate for several. A run that isn't
    complete missed a repository, which may be the one with the problems, so it doesn't
    count towards closing the issue."""
    written = [log for log in logs if log.is_file()]
    if not written:
        return "Renovate wrote no log of problems; the issue stays as it is.\n"
    problems = renovate_problems(
        line for log in written for line in log.read_text("utf-8").splitlines()
    )
    known: dict[str, bool] = {}
    shown = {
        problem: count
        for problem, count in problems.items()
        if not problem.repository or is_public(api, problem.repository, known)
    }
    hidden = sum(problems.values()) - sum(shown.values())
    issue = open_issues(api, repo).get(RENOVATE)
    if not problems:
        if issue is None:
            return "Renovate logged no warning and no error.\n"
        if not complete:
            return (
                "Renovate logged no problem, but not every repository finished; "
                f"#{issue.number} stays as it is.\n"
            )
        quiet = int(issue.state.get("quiet") or 0) + 1
        if quiet >= QUIET_RUNS:
            close_issue(
                api,
                repo,
                issue.number,
                f"Renovate logged no problem in {quiet} runs in a row, the latest "
                f"[this one]({run}).",
            )
            return f"Renovate logged no problem: closed #{issue.number}.\n"
        state = {**issue.state, "quiet": quiet}
        api.patch(
            f"repos/{repo}/issues/{issue.number}",
            {"body": MARKER.sub(lambda _: marker(state), issue.body, count=1)},
        )
        return (
            f"Renovate logged no problem; #{issue.number} closes after "
            f"{QUIET_RUNS - quiet} more runs like this.\n"
        )
    text = problems_text(shown, hidden, run)
    body = f"{marker({'key': RENOVATE, 'quiet': 0})}\n{text}"
    if issue is None:
        number = open_issue(api, repo, RENOVATE_TITLE, body, [TYPE, RENOVATE_LABEL])
        return f"{text}\nOpened #{number}.\n"
    api.patch(f"repos/{repo}/issues/{issue.number}", {"body": body})
    return f"{text}\nUpdated #{issue.number}.\n"


# ---------------------------------------------------------------- Renovate's pull requests


def account_of(commit: Mapping[str, Any]) -> str:
    """The GitHub account that authored a commit, or nothing for an address that GitHub
    links to none."""
    return str((commit.get("author") or {}).get("login") or "")


def blocked_after(
    api: Api, repo: str, number: int, renovate: str, since: datetime
) -> bool:
    """Whether Renovate said on the pull request, after since, that it takes the branch
    for edited: its own comment, not someone's quote of it."""
    comments = api.get(f"repos/{repo}/issues/{number}/comments?per_page=100") or []
    return any(
        str((comment.get("user") or {}).get("login") or "") == renovate
        and EDITED in str(comment.get("body") or "")
        and datetime.fromisoformat(str(comment["updated_at"])) > since
        for comment in comments
    )


def look_at_pull(
    api: Api, repo: str, pull: Mapping[str, Any], now: datetime
) -> tuple[str, bool] | None:
    """The line of the summary for a pull request of Renovate that left its automatic
    path, and whether a person has to act; None for one on that path.

    Renovate rebuilds, rebases and merges its branch only while it takes every commit
    on it for its own or for that of an author it ignores, such as the release app of
    the bots (decision 0104). It leaves the branch alone after a commit of a person,
    also a merge of the base that *Update branch* makes in their name, and after one
    of a bot that it doesn't ignore, which its comment Edited/Blocked Notification
    says. The box for a rebase brings it back: Renovate builds the branch anew from
    the base, without those commits. The look ticks it where that drops nothing that
    a new branch doesn't make again, merges of the base and commits of bots, and
    leaves the changes of a person to that person (decision 0144)."""
    number = int(pull["number"])
    renovate = str((pull.get("user") or {}).get("login") or "")
    commits = api.get(f"repos/{repo}/pulls/{number}/commits?per_page=100") or []
    others = [commit for commit in commits if account_of(commit) != renovate]
    people = [commit for commit in others if not account_of(commit).endswith("[bot]")]
    if not people and not (
        others
        and blocked_after(
            api,
            repo,
            number,
            renovate,
            datetime.fromisoformat(str(commits[-1]["commit"]["committer"]["date"])),
        )
    ):
        return None
    link = f"#{number} {code(str(pull['head']['ref']))}"
    kept = [
        str(commit["sha"])[:7]
        for commit in people
        if len(commit.get("parents") or []) < 2
    ]
    if kept:
        shown = ", ".join(f"`{sha}`" for sha in kept)
        return (
            f"{link}: Renovate takes its branch for edited, and it carries changes of "
            f"a person that aren't merges of the base ({shown}), which a new branch "
            "would drop. Move what they change to a pull request of its own, then "
            "tick the box for a rebase in its description, or close it.",
            True,
        )
    body = str(pull.get("body") or "")
    if UNTICKED in body:
        api.patch(
            f"repos/{repo}/pulls/{number}",
            {"body": body.replace(UNTICKED, TICKED, 1)},
        )
        return (
            f"{link}: Renovate took its branch for edited after merges of the base or "
            "commits of bots, which a new branch makes again: ticked the box for a "
            "rebase, and Renovate builds the branch anew in its next run.",
            False,
        )
    if TICKED not in body:
        return (
            f"{link}: Renovate takes its branch for edited, and its description has no "
            "box for a rebase: add the label `rebase`, which asks Renovate the same.",
            True,
        )
    updated = datetime.fromisoformat(str(pull["updated_at"]))
    if now - updated < REBUILD_TIME:
        return (
            f"{link}: its box for a rebase is ticked, and Renovate builds the branch "
            "anew in its next run.",
            False,
        )
    return (
        f"{link}: its box for a rebase has been ticked since {moment(updated)}, and "
        "Renovate hasn't built the branch anew: look at the log of Renovate's latest "
        "run.",
        True,
    )


def parked_body(reported: Sequence[str]) -> str:
    """What the issue says: the pull requests, and when it closes."""
    listed = "\n".join(f"- {line}" for line in reported)
    return (
        "Renovate leaves these pull requests alone: it no longer rebuilds, rebases or "
        "merges their branches, and the daily look of the bot-health bot can't bring "
        "them back by itself.\n\n"
        f"{listed}\n\n"
        "Bring a branch of Renovate up to date by leaving it to Renovate and the "
        "branch bot, not with *Update branch*, which merges in your name. Where the "
        "only commits that aren't Renovate's are such merges or commits of bots, the "
        "daily look ticks the box for a rebase by itself. This issue closes once "
        "Renovate takes each of these pull requests up again, or they are closed.\n"
    )


def parked_report(api: Api, repo: str, issue: Issue | None, now: datetime) -> str:
    """Brings back the open pull requests of Renovate that it leaves alone where that
    drops nothing of a person, and keeps the one issue with the others current. The
    lines of the summary; empty when there is nothing to say."""
    pulls = [
        pull
        for pull in api.get(f"repos/{repo}/pulls?state=open&per_page=100") or []
        if str(pull["head"]["ref"]).startswith(RENOVATE_BRANCH)
        # The name of a fork's branch says nothing about who made it.
        and ((pull["head"].get("repo") or {}).get("full_name")) == repo
    ]
    found = [
        look
        for pull in sorted(pulls, key=lambda item: int(item["number"]))
        for look in [look_at_pull(api, repo, pull, now)]
        if look is not None
    ]
    lines = [f"- {line}" for line, _ in found]
    reported = [line for line, person in found if person]
    if reported:
        body = f"{marker({'key': PARKED})}\n{parked_body(reported)}"
        if issue is None:
            number = open_issue(api, repo, PARKED_TITLE, body, [TYPE, RENOVATE_LABEL])
            lines += ["", f"Opened #{number}."]
        elif issue.body != body:
            api.patch(f"repos/{repo}/issues/{issue.number}", {"body": body})
            lines += ["", f"Updated #{issue.number}."]
        else:
            lines += ["", f"Listed in #{issue.number}."]
    elif issue is not None:
        close_issue(
            api,
            repo,
            issue.number,
            "Renovate takes every one of its open pull requests up again.",
        )
        lines += ["", f"Closed #{issue.number}."]
    # Without a line of a pull request, a line of the issue alone.
    return "\n".join(lines).strip("\n")
