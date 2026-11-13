"""Whether the open release pull request merges itself as a release of dependency
updates (decision 0087), for the release bot.

A release of nothing but dependency updates merges itself once a day, in the run of
the schedule of the release bot or in one started by hand, and at once when an update
fixes a vulnerability; a release with a change of a person waits for the maintainer.
What counts is what the release is made of: a commit that release-please makes
something of, as `matters()` of the overrides says, is in the release, and it must be
a dependency update without a version of its own. A commit that release-please makes
nothing of, such as `docs: renew the pictures` or `chore: update to the blueprint
v1.2.3`, which the bots merge between the updates almost every day, keeps the release
back only when a person made it: its change reaches every repository with the release,
and the maintainer reviews each change of a person before it does.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

from blueprint.bot_health import default_branch
from blueprint.github import Api, Json
from blueprint.overrides import FOOTER, matters, since_last_release
from blueprint.pr_report import BREAKING

# The title of an update of Renovate, as the type of its rule of semantic commits
# makes it: fix for what the users run, chore and build for the rest.
DEPENDENCY = re.compile(r"(?:fix|chore|build)\(deps(?:-dev)?\): ")
# Renovate adds it to the title of every update of its vulnerabilityAlerts.
SECURITY = re.compile(r"\[security\]", re.IGNORECASE)
# The event of the run of each merge into the default branch, which leaves a release
# of routine updates to the daily run of the schedule, or to one started by hand.
MERGE = "push"

FIRST = "The first release waits for the maintainer."
HELD = "The release holds more than dependency updates; the maintainer merges it:"
LATER = (
    "The release of dependency updates merges in the daily run of the release bot,"
    " with the updates of the day."
)
NOW = (
    "The release holds nothing but dependency updates and merges once its checks pass."
)


@dataclass(frozen=True)
class Verdict:
    """Whether the release pull request merges now, and why, for the log of the run."""

    merge: bool
    text: str


def message_of(commit: Json) -> str:
    """The message of a commit of GitHub's compare, with the line ends of Git."""
    return str(commit["commit"]["message"]).replace("\r\n", "\n")


def by_a_bot(commit: Json) -> bool:
    """Whether a GitHub App made the commit, as GitHub names its author: the squash
    commit of a pull request has the author of the pull request. An author that
    GitHub doesn't know is taken for a person."""
    author = commit.get("author")
    return isinstance(author, dict) and author.get("type") == "Bot"


def holds_back(commit: Json) -> bool:
    """Whether the commit keeps the release from merging itself: a change that
    release-please puts into the release other than a dependency update, a dependency
    update with a version of its own, such as one whose description quotes a line
    `BREAKING CHANGE:` of the release notes, or a change of a person that the release
    brings without a word in its notes."""
    message = message_of(commit)
    if DEPENDENCY.match(message):
        return bool(BREAKING.search(message) or FOOTER.search(message))
    return matters(message) or not by_a_bot(commit)


def decide(commits: list[Json], event: str) -> Verdict:
    """Whether the release with these commits since the last one merges in a run of
    the event."""
    titles = [message_of(commit).split("\n", 1)[0].strip() for commit in commits]
    held = [
        title
        for commit, title in zip(commits, titles, strict=True)
        if holds_back(commit)
    ]
    if held:
        return Verdict(False, "\n".join([HELD, *held]))
    if event == MERGE and not any(SECURITY.search(title) for title in titles):
        return Verdict(False, LATER)
    return Verdict(True, NOW)


def dependency_release(api: Api, repo: str, event: str) -> Verdict:
    """Whether the open release pull request merges itself in this run of the release
    bot, by the commits of the default branch since the latest release."""
    if not isinstance(api.get(f"repos/{repo}/releases/latest"), dict):
        return Verdict(False, FIRST)
    branch = default_branch(api, repo)
    return decide(list(since_last_release(api, repo, branch)), event)
