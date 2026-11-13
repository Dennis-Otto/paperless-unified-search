"""An outage of GitHub, which the bots sit out instead of failing (decision 0136).

In GitHub's outage of 9 November 2026, from 18:09 to 18:14 UTC, GitHub ended the runs
of every repository of the blueprint before their first job, with "Internal server
error", while its status page still said that all was well. Each such run of a check
or a bot started the bots that follow it, which GitHub ended the same way, and each
failed run mailed the maintainer: over a hundred mails in five minutes. A run that
GitHub couldn't start says nothing about the code or about a bot, and another try
while GitHub fails only fails again: the bots that look at the end of another run do
nothing for it, and a bot that can't reach GitHub ends with a notice. Neither is
something that a person must act on.
"""

from __future__ import annotations

import os
from pathlib import Path

from blueprint.github import Api, GitHubError

# The title of the notices of the bots in an outage of GitHub.
TITLE = "GitHub is unavailable"
# The job of a run ended badly; one that a failure cancelled says nothing.
FAILED = frozenset({"failure", "timed_out"})


def notice(text: str) -> str:
    """An annotation of the run, which tells without failing it."""
    return f"::notice title={TITLE}::{text}"


def unreachable(error: GitHubError) -> str:
    """The notice of a bot that GitHub kept failing: what GitHub answered last, and
    that the bot's next event or a later run does what this one couldn't."""
    return notice(
        f"GitHub kept failing ({error}), as in an outage of GitHub "
        "(https://www.githubstatus.com), so the bot did nothing this time; its next "
        "run catches up. Nothing to do for a person."
    )


def sit_out() -> None:
    """Leaves the mark of a job that sat out an outage, $RUNNER_TEMP/github-sat-out,
    as the trap of the bots in bash does: a later step of the job, such as the one
    that closes the issue of the fix bot of main, learns that the job did nothing,
    so that it passed nothing either (decision 0155). Outside a runner, there is no
    job to tell."""
    if folder := os.environ.get("RUNNER_TEMP"):
        (Path(folder) / "github-sat-out").touch()


def never_started(
    api: Api, repo: str, run: int, attempt: int, checks: Api | None = None
) -> bool:
    """Whether GitHub ended the attempt of a failed run before any job of it began,
    as in its outage: then the run has no job at all, or every job that failed has no
    step and no annotation. A job whose condition or matrix GitHub couldn't evaluate
    has no step either, but an annotation that says why: that is a failure of the
    workflow, and a person must fix it. `checks` reads the annotations where the
    token of `api` may read the runs but not the checks."""
    data = api.get(
        f"repos/{repo}/actions/runs/{run}/attempts/{attempt}/jobs?per_page=100"
    )
    for job in (data or {}).get("jobs", []):
        if job.get("conclusion") not in FAILED:
            continue
        if job.get("steps") or (checks or api).get(
            f"repos/{repo}/check-runs/{job['id']}/annotations?per_page=50"
        ):
            return False
    return True


def couldnt_start(run: str) -> str:
    """The notice of a bot that does nothing for the end of a run that GitHub
    couldn't start."""
    return notice(
        f"GitHub couldn't start {run}, as in an outage of GitHub "
        "(https://www.githubstatus.com), so the bot leaves it alone instead of trying "
        "again while GitHub fails. Nothing of the code or the bots failed."
    )


def failed_unstarted(
    api: Api, repo: str, run: int, attempt: int, checks: Api | None = None
) -> bool:
    """Whether the attempt of a run failed because GitHub couldn't start it, for a
    bot that knows the run only by its id: `api` reads the run and its jobs, and
    `checks`, if given, the annotations of the jobs."""
    data = api.get(f"repos/{repo}/actions/runs/{run}/attempts/{attempt}") or {}
    return data.get("conclusion") == "failure" and never_started(
        api, repo, run, attempt, checks
    )
