"""The bot for replaced commits: it cancels the runs of the checks of a commit that no
open pull request has as its head any more.

A run of a check only tells about its commit. Once a newer push replaced that commit, or
its pull request merged or closed, nobody reads the answer, yet the run still waits for a
runner of the account, which all the repositories of its owner share. Most workflows
cancel the run for the same pull request with their concurrency, but not the quick checks,
whose runs for different commits must not cancel each other (see after-checks.yml), and
none of them cancels a run when its pull request merges or closes.

A run of the head that the bot cancels by mistake leaves a required check cancelled, and
the bots after the checks take that for the decision of a person. A run that stays only
costs a runner. So anything unclear leaves a run alone:

- Only the workflows of the checks, which the bots after the checks follow too. A
  workflow such as the release or the issue lifecycle does something when a pull
  request closes.
- Only runs of a pull request: a push to main, a schedule and a release have no head that
  could replace them.
- Only the first attempt: whoever started a run again wants its answer.
- The runs first and the heads after them, so that a push in between makes a run look
  current and never the other way round; and only a run older than twenty seconds, so
  that a list of GitHub that lags behind a push can't call its new head stale.
- A commit, not a branch: the name of a branch says nothing, since two forks can have
  the same one. A commit that is the head of any open pull request stays.
- A second look at the commit, in another list of GitHub, right before the cancel.

The bot costs no runner of its own. It is a step of two jobs that run anyway: one of the
checks on every push to a pull request, and the branch bot on every merge.
"""

from __future__ import annotations

import re
import time
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path

from blueprint.bot_health import code
from blueprint.coach import escape_data
from blueprint.github import PAGE, Api, GitHubError, Json

# The workflow that names the workflows of the checks of a repository; the blueprint
# renders the list for each kind of project. It names the release as well, which is no
# check: the bot leaves its runs alone.
CHECKS_FILE = Path(".github/workflows/after-checks.yml")
NO_CHECKS = frozenset({"Release"})
CHECKS = re.compile(r"^\s*workflows: \[([^\]]+)\]\s*$", re.MULTILINE)
# The events of a run of a pull request: nothing else has a head that a push replaces.
EVENTS = frozenset({"pull_request", "pull_request_target"})
# What GitHub calls a run that holds a runner or waits for one, for a concurrency group
# or for an environment. A run that waits for an approval has ended as action_required.
STATUSES = ("queued", "in_progress", "waiting", "pending")
# How old a run must be, in seconds, before the bot looks at its commit: GitHub creates
# a run after the push, and its lists follow within a moment. Not much more: the bot looks
# when the next push comes, which is often within a minute of the last one, and it doesn't
# come back until the push after that.
GRACE = 20
# What GitHub answers to the cancel of a run that ended a moment ago.
ALREADY_ENDED = 409


def checks_of(root: Path) -> frozenset[str]:
    """The names of the workflows of the checks of the repository in root; none when
    it has no such list, which leaves every run alone."""
    path = root / CHECKS_FILE
    found = CHECKS.search(path.read_text("utf-8")) if path.is_file() else None
    names = frozenset(map(str.strip, found[1].split(","))) if found else frozenset()
    return names - NO_CHECKS


def older_than_the_grace(run: dict[str, Json], now: float) -> bool:
    created = datetime.fromisoformat(str(run["created_at"])).timestamp()
    return now - created >= GRACE


@dataclass
class Sweep:
    """One look at the runs of a repository, with what it did about them."""

    api: Api
    repo: str
    checks: frozenset[str]
    # The run of the job itself: it goes on with the others when its commit is replaced.
    own_run: str
    now: float
    dry_run: bool
    done: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)

    def may_go(self, run: dict[str, Json]) -> bool:
        """Whether the bot may cancel the run at all, whatever its commit."""
        return (
            run["event"] in EVENTS
            and run["name"] in self.checks
            and str(run["id"]) != self.own_run
            and run["run_attempt"] == 1
            and older_than_the_grace(run, self.now)
        )

    def candidates(self) -> list[dict[str, Json]]:
        """The runs that hold a place, whose commit is no head of an open pull request.
        The heads come after the runs: a run for a commit that is pushed in between has
        its head in the list, and a run that is not in the list is safe anyway."""
        runs: dict[int, dict[str, Json]] = {}
        for status in STATUSES:
            for run in self.api.get_all(
                f"repos/{self.repo}/actions/runs?status={status}&per_page={PAGE}"
            ):
                runs[run["id"]] = run
        found = [run for run in runs.values() if self.may_go(run)]
        if not found:
            return []
        # Not missing_ok: without this list, every run would look replaced.
        heads = {
            pull["head"]["sha"]
            for pull in self.api.get_all(
                f"repos/{self.repo}/pulls?state=open&per_page={PAGE}",
                missing_ok=False,
            )
        }
        return [run for run in found if run["head_sha"] not in heads]

    def still_a_head(self, sha: str) -> bool:
        """The second look: whether an open pull request has this commit as its head, in
        the list of GitHub for the commit."""
        pulls = self.api.get(f"repos/{self.repo}/commits/{sha}/pulls") or []
        return any(
            pull["state"] == "open" and pull["head"]["sha"] == sha for pull in pulls
        )

    def replaced(self, runs: list[dict[str, Json]]) -> list[dict[str, Json]]:
        """Those runs whose commit the second look finds replaced as well."""
        heads: dict[str, bool] = {}
        for sha in sorted({str(run["head_sha"]) for run in runs}):
            try:
                heads[sha] = self.still_a_head(sha)
            except GitHubError as error:
                # Without an answer, it may be a head.
                heads[sha] = True
                self.warn(f"couldn't look at {sha[:7]}", error)
        return [run for run in runs if not heads[str(run["head_sha"])]]

    def cancel(self, run: dict[str, Json]) -> None:
        line = (
            f"{code(str(run['name']))} of {code(str(run['head_sha'])[:7])} "
            f"on {code(str(run['head_branch']))}"
        )
        if not self.dry_run:
            try:
                self.api.request(
                    "POST", f"repos/{self.repo}/actions/runs/{run['id']}/cancel"
                )
            except GitHubError as error:
                if error.status != ALREADY_ENDED:
                    self.warn(f"couldn't cancel run {run['id']}", error)
                return
        self.done.append(line)

    def warn(self, what: str, error: Exception) -> None:
        self.warnings.append(
            f"::warning::The bot for replaced commits {what}: "
            + escape_data(str(error))
        )

    def run(self) -> None:
        try:
            for run in self.replaced(self.candidates()):
                self.cancel(run)
        except (GitHubError, KeyError, TypeError, ValueError) as error:
            # Whatever it can't read: a run that goes on costs a runner, a cancelled head
            # a required check.
            self.warn("stopped and cancels no more", error)

    def summary(self) -> str:
        lines = ["### Runs of replaced commits", ""]
        if not self.done:
            lines.append("No run of a commit that a newer one replaced holds a runner.")
            return "\n".join(lines) + "\n"
        count = len(self.done)
        lines += [
            f"{'The bot would cancel' if self.dry_run else 'The bot cancelled'} "
            f"{count} {'run' if count == 1 else 'runs'} of commits that no open pull "
            "request has as its head any more:",
            "",
        ]
        lines += [f"- {line}" for line in sorted(self.done)]
        return "\n".join(lines) + "\n"


@dataclass(frozen=True)
class Outcome:
    summary: str
    # What only shows as an annotation: this bot saves runners and fails no run.
    warnings: list[str]


def cancel_stale_runs(
    api: Api, repo: str, root: Path, *, own_run: str = "", dry_run: bool = False
) -> Outcome:
    """Cancel the runs of the checks of the commits that no open pull request has as its
    head any more; with dry_run, only say which."""
    checks = checks_of(root)
    sweep = Sweep(api, repo, checks, own_run, time.time(), dry_run)
    if checks:
        sweep.run()
    return Outcome(sweep.summary(), sweep.warnings)
