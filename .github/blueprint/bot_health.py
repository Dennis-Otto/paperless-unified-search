"""The bot-health bot: a bot that fails on the default branch keeps one issue open,
which closes once the bot passes again.

GitHub mails the failure of a scheduled run only to whoever last changed its
schedule, and that of another run to whoever started it, which in these repositories
is mostly the release app, so a failing bot went unnoticed for days. When a run of a
watched workflow ends on the default branch, the bot runs its failed jobs once more
if a second try can pass, and otherwise keeps one issue per workflow current, with
the failed jobs, their steps and their first errors; a run that passes closes it.
Once a day it looks at the latest run of every workflow, those of the project too,
in case GitHub lost an event, and starts a workflow whose schedule GitHub missed or
switched off. In the blueprint, it also keeps one issue with the warnings and errors
in the log of Renovate.
"""

from __future__ import annotations

import hashlib
import json
import re
import urllib.parse
from collections.abc import Iterable, Mapping
from dataclasses import dataclass
from datetime import UTC, date, datetime, timedelta
from pathlib import Path
from typing import Any

from blueprint.github import Api, GitHubError, Json

LABEL = "bot-failure"
LABEL_COLOR = "d93f0b"
LABEL_DESCRIPTION = (
    "A bot or a scheduled workflow fails on the default branch; "
    "closes when it passes again"
)
# The type of these issues, which every repository has among its labels.
TYPE = "maintenance"
PULL_REQUESTS = frozenset({"pull_request", "pull_request_target"})
FAILED = frozenset({"failure", "timed_out", "startup_failure"})
PASSED = "success"
# A second try only hides or repeats their failure: Findings fails while an alert is
# open, Mutation takes hours to measure the same again, and the property tests and the
# fuzzing failed on an input that they found.
ONE_TRY = frozenset({"Findings", "Mutation", "Property tests", "Fuzzing"})
# Workflows that keep an issue of their own about their failures.
OWN_ISSUE = frozenset({"Diagrams", "Newest Python", "Next runner"})
# A failure that ended this recently may still get its second try from the run that
# its end started; the daily look leaves it to that run.
SECOND_TRY_TIME = timedelta(hours=1)
# GitHub starts a scheduled run hours late at times; a time of a schedule counts as
# missed only when this much time has passed since.
LATE = timedelta(hours=6)
# The daily look starts at most this many workflows whose schedule GitHub missed, so
# that it doesn't take the runners of the account from the pull requests.
MOST_STARTS = 4
# The failed jobs that an issue lists, and the length of a line from a log.
MOST_JOBS = 10
LONGEST = 200
# The issue with Renovate's problems closes after this many runs in a row without one.
QUIET_RUNS = 3
RENOVATE = "renovate"
RENOVATE_TITLE = "Renovate logs problems"
RENOVATE_LABEL = "dependencies"
MOST_PROBLEMS = 50

# The state of an issue, in its body, where only the raw text shows it.
MARKER = re.compile(r"<!-- bot-health (\{.*?\}) -->")
CONTROL = re.compile(r"[\x00-\x1f\x7f]")
# The annotation that every failed step gets; another one says more.
GENERIC = re.compile(r"Process completed with exit code \d+\.")
REPOSITORY = re.compile(r"[\w.-]+/[\w.-]+")
CRON = re.compile(r"""^\s*-\s*cron:\s*["']([^"'\n]+)["']""", re.MULTILINE)
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


def open_issues(api: Api, repo: str) -> dict[str, Issue]:
    """The open issues of the bot by the key in their state: the path of a workflow,
    or renovate. Where a key has several, because two runs of the bot opened one each
    at the same moment, the newest one stays and the older ones close."""
    found: dict[str, Issue] = {}
    listed = api.get(f"repos/{repo}/issues?labels={LABEL}&state=open&per_page=100")
    for item in listed or []:
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
        number = int(item["number"])
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
    api: Api, repo: str, title: str, body: str, labels: Iterable[str]
) -> int:
    # The label of a repository whose .github/labels.toml, its own, lacks it.
    if not api.exists(f"repos/{repo}/labels/{LABEL}"):
        try:
            api.post(
                f"repos/{repo}/labels",
                {"name": LABEL, "color": LABEL_COLOR, "description": LABEL_DESCRIPTION},
            )
        except GitHubError as error:
            # The run for another workflow created it a moment ago.
            if error.status != 422:
                raise
    response = api.request(
        "POST",
        f"repos/{repo}/issues",
        {"title": title, "body": body, "labels": [LABEL, *labels]},
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


def first_error(api: Api, repo: str, job: int) -> str:
    try:
        annotations = api.get(f"repos/{repo}/check-runs/{job}/annotations?per_page=50")
    except GitHubError:
        return ""
    errors = [
        str(annotation.get("message") or "")
        for annotation in annotations or []
        if annotation.get("annotation_level") == "failure"
    ]
    telling = [error for error in errors if not GENERIC.fullmatch(error.strip())]
    return (telling or errors or [""])[0]


def failures(api: Api, repo: str, run: Run) -> list[Failure]:
    if run.conclusion == "startup_failure":
        return []
    data = api.get(
        f"repos/{repo}/actions/runs/{run.id}/attempts/{run.attempt}/jobs?per_page=100"
    )
    jobs = (data or {}).get("jobs", [])
    # The jobs that a failure cancelled say nothing, unless only they ended badly.
    failed = [job for job in jobs if job.get("conclusion") in ("failure", "timed_out")]
    failed = failed or [job for job in jobs if job.get("conclusion") == "cancelled"]
    found = []
    for job in failed[:MOST_JOBS]:
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


def failure_body(run: Run, branch: str, found: list[Failure], state: Json) -> str:
    started = EVENTS.get(run.event, f"the event {run.event}")
    failed = state["failed"]
    runs = "1 failed run" if failed == 1 else f"{failed} failed runs"
    return (
        f"{marker(state)}\n"
        f"The workflow **{run.name}** fails on `{branch}` since {state['since']}, "
        f"{runs} so far. GitHub mails such a failure only to whoever started the run "
        "or last changed its schedule, often a bot, so this issue stays open until a "
        "run passes again; the bot-health bot closes it then.\n\n"
        f"**The latest failure:** {run.link()}, attempt {run.attempt}, started by "
        f"{started}.\n\n"
        f"{failure_table(run, found)}\n\n"
        "Fix what the error names, or run the workflow again under "
        f"*Actions → {run.name}* when GitHub was at fault. A failure in another job "
        "or step adds a comment here.\n"
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
            "key": run.path,
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


def run_again(api: Api, repo: str, run: Run) -> str | None:
    """Runs the failed jobs once more; None when GitHub refuses and no one else did."""
    try:
        api.post(f"repos/{repo}/actions/runs/{run.id}/rerun-failed-jobs", {})
    except GitHubError:
        # Another bot, such as the one after the checks, may have been quicker.
        latest = api.get(f"repos/{repo}/actions/runs/{run.id}") or {}
        newer = int(latest.get("run_attempt") or 1) > run.attempt
        if newer or latest.get("status") != "completed":
            return "failed: another bot runs it again"
        return None
    return "failed: runs the failed jobs once more"


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
    issue = issues.get(run.path)
    if run.conclusion == PASSED:
        if issue is None:
            return "passes"
        # The end of an older run may come after that of the failure: ids only grow.
        if run.id < int(issue.state.get("run") or 0):
            return f"passes, in a run older than the failure of #{issue.number}"
        failed = int(issue.state.get("failed") or 0)
        runs = "1 failed run" if failed == 1 else f"{failed} failed runs"
        close_issue(
            api,
            repo,
            issue.number,
            f"Passes again in {run.link()}, after {runs}.",
        )
        return f"passes again: closed #{issue.number}"
    if run.conclusion not in FAILED:
        return f"ended as {run.conclusion or run.status}: no failure"
    note = ""
    if run.deserves_second_try():
        if second_try:
            tried = run_again(api, repo, run)
            if tried is not None:
                return tried
            note = " (GitHub refused to run it again)"
        elif now - run.updated < SECOND_TRY_TIME:
            return "failed: its second try may still come"
    return report(api, repo, branch, run, issue) + note


def watch_run(api: Api, repo: str, run_id: int, now: datetime) -> str:
    """The end of a run of a watched workflow, as the event of GitHub reports it."""
    data = api.get(f"repos/{repo}/actions/runs/{run_id}")
    if data is None:
        return f"GitHub doesn't know the run {run_id}."
    run = Run.of(data)
    branch = default_branch(api, repo)
    if not run.of_branch(repo, branch):
        return f"{run.name}: not a run of `{branch}`, nothing to do."
    if run.status != "completed":
        return f"{run.name}: runs again, its next end counts."
    issues = open_issues(api, repo)
    return (
        f"{run.name}: {look_at(api, repo, branch, run, issues, now, second_try=True)}."
    )


# ---------------------------------------------------------------- the daily look

FIELDS = ((0, 59), (0, 23), (1, 31), (1, 12), (0, 7))


def cron_field(text: str, low: int, high: int) -> frozenset[int] | None:
    values: set[int] = set()
    for part in text.split(","):
        match = re.fullmatch(r"(?:(\*)|(\d+)(?:-(\d+))?)(?:/(\d+))?", part)
        if not match:
            return None
        if match[1]:
            start, end = low, high
        else:
            start = int(match[2])
            end = int(match[3]) if match[3] else high if match[4] else start
        step = int(match[4]) if match[4] else 1
        if not low <= start <= end <= high or step < 1:
            return None
        values.update(range(start, end + 1, step))
    return frozenset(values)


@dataclass(frozen=True)
class Cron:
    """A schedule of a workflow, in the five fields of cron, in UTC."""

    minutes: frozenset[int]
    hours: frozenset[int]
    days: frozenset[int]
    months: frozenset[int]
    weekdays: frozenset[int]
    # As in cron, a schedule with both a day of the month and a day of the week runs
    # on either; with one of them a star, on the other alone.
    either: bool

    @classmethod
    def parse(cls, text: str) -> Cron | None:
        fields = text.split()
        if len(fields) != 5:
            return None
        values = []
        for field, (low, high) in zip(fields, FIELDS, strict=True):
            value = cron_field(field, low, high)
            if value is None:
                return None
            values.append(value)
        minutes, hours, days, months, weekdays = values
        return cls(
            minutes,
            hours,
            days,
            months,
            # Sunday is 0 and 7.
            frozenset(day % 7 for day in weekdays),
            not fields[2].startswith("*") and not fields[4].startswith("*"),
        )

    def runs_on(self, day: date) -> bool:
        if day.month not in self.months:
            return False
        in_month = day.day in self.days
        in_week = day.isoweekday() % 7 in self.weekdays
        return in_month or in_week if self.either else in_month and in_week

    def last(self, before: datetime) -> datetime | None:
        """The latest time of the schedule at or before a moment, within a year."""
        for back in range(367):
            day = before.date() - timedelta(days=back)
            if not self.runs_on(day):
                continue
            for hour in sorted(self.hours, reverse=True):
                for minute in sorted(self.minutes, reverse=True):
                    time = datetime(
                        day.year, day.month, day.day, hour, minute, tzinfo=UTC
                    )
                    if time <= before:
                        return time
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
) -> tuple[str, bool]:
    """What the daily look does for one workflow, and whether it started it. With
    after_checks, a run that the end of another workflow started doesn't count."""
    listed = api.get(
        f"repos/{repo}/actions/workflows/{workflow['id']}/runs"
        f"?branch={urllib.parse.quote(branch)}&exclude_pull_requests=true&per_page=50"
    )
    runs = [Run.of(item) for item in (listed or {}).get("workflow_runs", [])]
    runs = [
        run
        for run in runs
        if run.of_branch(repo, branch)
        and not (after_checks and run.event == "workflow_run")
    ]
    finished = next(
        (
            run
            for run in runs
            if run.status == "completed"
            and (run.conclusion == PASSED or run.conclusion in FAILED)
        ),
        None,
    )
    notes = []
    if finished is None:
        notes.append(f"no finished run on `{branch}`")
    else:
        notes.append(
            look_at(api, repo, branch, finished, issues, now, second_try=False)
        )
    if workflow["state"] == "disabled_inactivity":
        api.put(f"repos/{repo}/actions/workflows/{workflow['id']}/enable")
        notes.append("GitHub had switched it off for inactivity: on again")
    due, readable = missed_time(text, now)
    if not readable:
        notes.append("a schedule that can't be read")
    started = False
    if due is not None and (not runs or runs[0].created < due):
        inputs = dispatch_inputs(text)
        missed = f"missed its run of {moment(due)}"
        if inputs is None:
            notes.append(f"{missed}, and can't be started by hand")
        elif not start:
            notes.append(f"{missed}: starts in a later look, {MOST_STARTS} a day")
        else:
            api.post(
                f"repos/{repo}/actions/workflows/{workflow['id']}/dispatches",
                {"ref": branch, "inputs": inputs},
            )
            notes.append(f"{missed}: started")
            started = True
    return "; ".join(notes), started


def sweep(api: Api, repo: str, root: Path, now: datetime) -> tuple[str, bool]:
    """The daily look at every workflow of the repository; a summary in Markdown, and
    whether every look succeeded."""
    branch = default_branch(api, repo)
    issues = open_issues(api, repo)
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
    healthy = True
    for workflow in workflows:
        name = str(workflow["name"])
        if (
            str(workflow["path"]).startswith("dynamic/")
            or name in OWN_ISSUE
            or workflow["state"] not in ("active", "disabled_inactivity")
        ):
            continue
        try:
            state, started = look_after(
                api,
                repo,
                branch,
                workflow,
                issues,
                texts[str(workflow["path"])],
                now,
                start=starts < MOST_STARTS,
                after_checks=name in after_checks,
            )
        except GitHubError as error:
            state, started, healthy = f"couldn't look: {code(str(error))}", False, False
        starts += started
        lines.append(f"| {code(name)} | {state} |")
    return "\n".join(lines) + "\n", healthy


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
    if hidden:
        lines += [
            "",
            f"{hidden} more in private repositories, which a public issue doesn't name.",
        ]
    return "\n".join(lines) + "\n"


def renovate_report(api: Api, repo: str, log: Path, run: str) -> str:
    """Keeps the one issue with Renovate's problems current from the log of a run."""
    if not log.is_file():
        return "Renovate wrote no log of problems; the issue stays as it is.\n"
    problems = renovate_problems(log.read_text(encoding="utf-8").splitlines())
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
