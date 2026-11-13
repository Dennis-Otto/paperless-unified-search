"""The acceptances of the osv-scanner.toml files that end soon, before the checks turn
red.

An entry of [[PackageOverrides]] accepts every advisory of one version until its
effectiveUntil, one of [[IgnoredVulns]] one advisory until its ignoreUntil. From that day
on OSV-Scanner reports the advisories again: on main, in every pull request that
changes a lock file, and in the alerts that the Dependabot alert bot opens again. Such
a date passes quietly, as the Pillow that Home Assistant pins for the tests showed: its
acceptance would have ended before the update that ends it was out. The command
advisories --ending of blueprint.py warns of each acceptance that ends within WINDOW,
in the weekly run of the workflow OSV-Scanner on main, and keeps one issue that names
the package, the version and the date, which it closes once none ends that soon."""

from __future__ import annotations

import datetime
from dataclasses import dataclass
from pathlib import Path
from typing import Protocol

from blueprint import dependabot
from blueprint.dependabot import Acceptance, acceptances
from blueprint.github import Json

# How long before its end an acceptance is named: two weekly runs, so that the next
# one still comes before the date.
WINDOW = datetime.timedelta(days=14)
TITLE = "An acceptance of advisories in osv-scanner.toml ends soon"
# The issue of the command, which it finds again by this mark in its text.
MARKER = "<!-- blueprint: accepted advisories end -->"
LABELS = ["dependencies", "security"]
# The reason that the warning gives an entry of [[IgnoredVulns]] without one: it closes
# no Dependabot alert, but OSV-Scanner reports its advisory again on its date all the
# same.
UNEXPLAINED = "(no reason given)"


class Client(Protocol):
    """What the command asks GitHub, as blueprint.github.Api does."""

    def get_all(self, path: str) -> list[Json]: ...

    def patch(self, path: str, body: Json) -> None: ...

    def post(self, path: str, body: Json) -> None: ...


def ending(found: list[Acceptance], day: datetime.date) -> list[Acceptance]:
    """The acceptances that end within WINDOW of the day, or have ended already, the
    first to end first."""
    soon = [acceptance for acceptance in found if acceptance.until - day <= WINDOW]
    return sorted(
        soon,
        key=lambda item: (item.until, item.config, item.name, item.advisory),
    )


def dated(root: Path) -> list[Acceptance]:
    """Every acceptance of the repository that ends on a date, with a reason or
    without."""
    return acceptances(root, UNEXPLAINED)[0]


def accepted(acceptance: Acceptance) -> str:
    """What the entry accepts, in words."""
    if acceptance.advisory:
        return f"the advisory {acceptance.advisory}"
    return f"every advisory of {acceptance.name} {acceptance.version}"


def when(acceptance: Acceptance, day: datetime.date) -> str:
    """The end, as near as it is: OSV-Scanner reports the advisories again on the
    date itself."""
    date = acceptance.until.isoformat()
    days = (acceptance.until - day).days
    if days <= 0:
        return f"until {date}, which has come: OSV-Scanner reports it again"
    if days == 1:
        return f"until {date}, tomorrow"
    return f"until {date}, in {days} days"


def line(acceptance: Acceptance, day: datetime.date) -> str:
    return (
        f"{acceptance.config} accepts {accepted(acceptance)} "
        f"{when(acceptance, day)}: {acceptance.reason}"
    )


def body(soon: list[Acceptance]) -> str:
    """The text of the issue: each acceptance that ends soon, and what to do. It names
    no day of its own, so that it changes only with the acceptances."""
    # A list, not a table: the GitHub app squeezes a table of reasons into narrow
    # columns.
    items = [
        f"- `{item.config}` accepts {accepted(item)} until "
        f"**{item.until.isoformat()}**: {item.reason}"
        for item in soon
    ]
    days = WINDOW.days
    return "\n".join(
        [
            MARKER,
            f"These acceptances of an `osv-scanner.toml` end within {days} days, or have "
            "ended. On its date OSV-Scanner reports the advisories again: "
            "on `main`, in every pull request that changes a lock file, and in the "
            "Dependabot alerts that the workflow *OSV-Scanner* opens again.",
            "",
            *items,
            "",
            "For each, before its date:",
            "",
            "- When an update moves the version, such as a new release of the package "
            "that pins it, take it and delete the entry. `python3 .github/blueprint.py "
            "advisories --fix` (`python3 blueprint.py advisories --fix` in the "
            "blueprint) writes the rules of Renovate for it anew.",
            "- Otherwise look at the advisories again, and move `effectiveUntil` (or "
            "`ignoreUntil`) with a reason that still holds.",
            "",
            f"The weekly run of *OSV-Scanner* keeps this issue current and closes it once "
            f"no acceptance ends within {days} days.",
        ]
    )


def own_issue(client: Client, repo: str) -> Json:
    """The open issue of the command, or None. It is found by its mark alone, so that
    a label that someone takes away, or that the repository lacks, opens no second
    one."""
    for item in client.get_all(f"repos/{repo}/issues?state=open"):
        if "pull_request" not in item and MARKER in str(item.get("body") or ""):
            return item
    return None


@dataclass(frozen=True)
class Outcome:
    """The acceptances that end soon, and what the command did with the issue."""

    soon: list[Acceptance]
    warnings: list[str]
    summary: list[str]


def keep_issue(
    client: Client, repo: str, root: Path, *, dry_run: bool = False
) -> Outcome:
    """Opens the one issue while an acceptance ends within WINDOW, keeps its text
    current, and closes it once none does; with dry_run, only says so."""
    day = dependabot.today()
    soon = ending(dated(root), day)
    warnings = [
        f"::warning title=An accepted advisory ends soon::{line(item, day)}"
        for item in soon
    ]
    summary = [f"- {line(item, day)}" for item in soon] or [
        f"No acceptance of an osv-scanner.toml ends within {WINDOW.days} days."
    ]
    issue = own_issue(client, repo)
    if not soon and issue is None:
        return Outcome(soon, warnings, summary)
    if not soon:
        number = issue["number"]
        if not dry_run:
            client.patch(
                f"repos/{repo}/issues/{number}",
                {"state": "closed", "state_reason": "completed"},
            )
        summary.append(f"Would close #{number}." if dry_run else f"Closed #{number}.")
        return Outcome(soon, warnings, summary)
    text = body(soon)
    if issue is None:
        if not dry_run:
            client.post(
                f"repos/{repo}/issues",
                {"title": TITLE, "body": text, "labels": LABELS},
            )
        summary.append(
            "Would open an issue that names them."
            if dry_run
            else "Opened an issue that names them."
        )
    elif issue.get("body") != text:
        number = issue["number"]
        if not dry_run:
            client.patch(f"repos/{repo}/issues/{number}", {"body": text})
        summary.append(f"Would update #{number}." if dry_run else f"Updated #{number}.")
    else:
        summary.append(f"#{issue['number']} names them already.")
    return Outcome(soon, warnings, summary)
