"""Whether the public entry of the project on bestpractices.dev still gives the answers
of .bestpractices.json.

bestpractices.dev proposes the answers of .bestpractices.json only for those that its
entry doesn't have yet: it reads the file with less confidence than it needs to replace
an answer, and a justification stays as it is while its status doesn't change. So an
answer of the file that changes after its first save, such as the link to a workflow
that a release of the blueprint renamed, never reaches the entry, and Save (and
continue) doesn't take it in either. The command best-practices of blueprint.py reads
the public entry once, compares each answer of the file with it and keeps one issue
with the answers that differ, or that link a file of the repository that is gone, and
for each level a link of its edit page that proposes the file's answers and forces
exactly those (overrides=), as the site's documentation of automation proposals
describes. It never writes the entry: a maintainer opens the links while signed in,
checks the highlighted fields and saves (decision 1046)."""

from __future__ import annotations

import http.client
import json
import re
import urllib.error
import urllib.parse
import urllib.request
from collections.abc import Callable
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Protocol

from blueprint.github import Json

SITE = "https://www.bestpractices.dev"
# A badge of OpenSSF Best Practices in a README: of the metal series, "badge", or of
# the OSPS Baseline, "baseline", with the number of the project.
BADGE = re.compile(r"bestpractices\.dev/projects/(\d+)/(badge|baseline)")
# The button of each form of bestpractices.dev that reads .bestpractices.json anew.
SAVE = "Save (and continue) 🤖"
ANSWERS = ".bestpractices.json"
# The criteria of bestpractices.dev, with the levels that ask for each, from the source
# that the file names.
CRITERIA = Path(__file__).with_name("bestpractices-criteria.json")
# The levels in the order of the site, each with an edit page of its own, which takes
# the proposals of its own criteria only.
LEVELS = ("passing", "silver", "gold", "baseline-1", "baseline-2", "baseline-3")
# The site refuses a long address: one of about 9,500 characters got 414 on 18 November
# 2026, one of 7,000 did not. A link of the issue stays below this, and the answers of
# a level that need more are split into several links.
LIMIT = 8000
# GitHub takes at most 65,536 characters in the text of an issue; above this, the
# issue leaves out the texts to enter, which its links carry all the same.
BODY_LIMIT = 60000
TITLE = "The answers on bestpractices.dev differ from .bestpractices.json"
# The issue of the command, which it finds again by this mark in its text.
MARKER = "<!-- blueprint: best practices entry -->"
LABELS = ["documentation"]
# A status that says nothing: .bestpractices.json and the entry leave it unknown.
UNKNOWN = {"", "?", "unknown"}
# A field of an OSPS criterion, osps_br_07_01_justification in the file and in the
# addresses of the site, OSPS-BR-07.01_justification in the public entry.
OSPS = re.compile(r"osps_([a-z]{2})_(\d{2})_(\d{2})_(status|justification)")
FIELD = re.compile(r"(.+)_(status|justification)")
# The characters that end a sentence after a link rather than belong to it.
TRAILING = ".,;:)]'\""


class Client(Protocol):
    """What the command asks GitHub, as blueprint.github.Api does."""

    def get_all(self, path: str) -> list[Json]: ...

    def patch(self, path: str, body: Json) -> None: ...

    def post(self, path: str, body: Json) -> None: ...


class Unreachable(Exception):
    """bestpractices.dev gave no entry that the command can read."""


def project_number(readme: str) -> str | None:
    """The number of the project on bestpractices.dev, from a badge of the README, of
    either series."""
    found = BADGE.search(readme)
    return found.group(1) if found else None


def entry_field(name: str) -> str:
    """The name of a field of .bestpractices.json in the public entry."""
    osps = OSPS.fullmatch(name)
    if not osps:
        return name
    family, section, item, kind = osps.groups()
    return f"OSPS-{family.upper()}-{section}.{item}_{kind}"


def criteria_levels(path: Path = CRITERIA) -> dict[str, str]:
    """The level whose edit page holds each criterion: the first of those that ask
    for it, as a criterion of passing that silver asks for more strictly is the same
    field."""
    criteria: dict[str, Json] = json.loads(path.read_text(encoding="utf-8"))["criteria"]
    return {name: about["levels"][0] for name, about in criteria.items()}


def read_entry(
    number: str, opener: Callable[..., Any] | None = None
) -> dict[str, Json]:
    """The public entry of the project, as its JSON shows it without a login: one
    request."""
    send = opener or urllib.request.urlopen
    request = urllib.request.Request(
        f"{SITE}/projects/{number}.json",
        headers={
            "Accept": "application/json",
            "User-Agent": "repo-blueprint (https://github.com/Dennis-Otto/repo-blueprint)",
        },
    )
    try:
        with send(request, timeout=30) as response:
            entry = json.load(response)
    # http.client.HTTPException: an answer that the site cuts off while it is read.
    except (OSError, ValueError, http.client.HTTPException) as error:
        if isinstance(error, urllib.error.HTTPError):
            # The error is the answer too, and holds its body until it is closed.
            error.close()
        raise Unreachable(f"{SITE}/projects/{number}.json: {error}") from error
    if not isinstance(entry, dict):
        raise Unreachable(f"{SITE}/projects/{number}.json gave no entry")
    return entry


@dataclass(frozen=True)
class Difference:
    """An answer of .bestpractices.json that the entry doesn't give."""

    name: str
    level: str | None
    expected: str
    found: str
    # The files of the repository that the entry links in this answer, and that the
    # checkout doesn't have.
    gone: tuple[str, ...] = ()

    @property
    def status(self) -> bool:
        return self.name.endswith("_status")

    @property
    def forced(self) -> bool:
        """Whether the edit page must force the proposal: an answer that the entry
        has stays as it is otherwise, while an unknown one takes the proposal."""
        if self.status:
            return self.found.strip().lower() not in UNKNOWN
        return bool(self.found.strip())


def gone_files(text: str, repo: str, root: Path) -> tuple[str, ...]:
    """The files and folders of the repository that a text links on GitHub, on any
    branch, and that the checkout doesn't have. A link may stand in angle brackets, as
    Markdown writes one on its own."""
    pattern = re.compile(
        rf"https://github\.com/{re.escape(repo)}/(?:blob|tree)/[^/\s]+/([^\s#?<>]+)",
        re.IGNORECASE,
    )
    gone: list[str] = []
    for link in pattern.findall(text):
        path = urllib.parse.unquote(link.rstrip(TRAILING))
        if path and not (root / path).exists() and path not in gone:
            gone.append(path)
    return tuple(gone)


def same(name: str, expected: str, found: str) -> bool:
    """Whether the entry gives the answer: a status by its value, whatever its case,
    and a justification by its text, as the site strips both."""
    if name.endswith("_status"):
        return expected.strip().lower() == found.strip().lower()
    return expected.strip() == found.strip()


def differences(
    answers: dict[str, Json],
    entry: dict[str, Json],
    repo: str,
    root: Path,
    levels: dict[str, str],
) -> list[Difference]:
    """Each answer of .bestpractices.json that the entry doesn't give, in the order of
    the file. Only the answers of the file count: what a maintainer answers on the
    site and the file leaves out is the project's own. A status that the file leaves
    unknown proposes nothing, as the site reads it."""
    found: list[Difference] = []
    for name, value in answers.items():
        match = FIELD.fullmatch(name)
        if not match or not isinstance(value, str):
            continue
        if name.endswith("_status") and value.strip().lower() in UNKNOWN:
            continue
        given = entry.get(entry_field(name))
        text = given if isinstance(given, str) else ""
        if same(name, value, text):
            continue
        gone = () if name.endswith("_status") else gone_files(text, repo, root)
        found.append(
            Difference(name, levels.get(match.group(1)), value.strip(), text, gone)
        )
    return found


@dataclass(frozen=True)
class Link:
    """A link of an edit page that proposes some of the answers of one level."""

    level: str
    url: str
    names: tuple[str, ...]
    part: int = 1
    parts: int = 1

    @property
    def label(self) -> str:
        if self.parts == 1:
            return self.level
        return f"{self.level}, part {self.part} of {self.parts}"


def edit_url(number: str, level: str, found: list[Difference]) -> str:
    """The address of the edit page of a level that proposes the answers and forces
    those that the entry has: overrides= names exactly those fields, comma-separated,
    and the site forces nothing else."""
    query = [(item.name, item.expected) for item in found]
    forced = [item.name for item in found if item.forced]
    if forced:
        query.append(("overrides", ",".join(forced)))
    return f"{SITE}/en/projects/{number}/{level}/edit?" + urllib.parse.urlencode(
        query, quote_via=urllib.parse.quote
    )


def edit_links(number: str, found: list[Difference], limit: int = LIMIT) -> list[Link]:
    """The links of the edit pages, one for each level with answers to take in, or
    several when one would be longer than the limit. The status of a criterion and its
    justification stay in the same link, since the site couples them."""
    links: list[Link] = []
    for level in LEVELS:
        criteria: dict[str, list[Difference]] = {}
        for item in found:
            if item.level == level:
                criteria.setdefault(item.name.rsplit("_", 1)[0], []).append(item)
        chunks: list[list[Difference]] = []
        for group in criteria.values():
            if chunks and len(edit_url(number, level, chunks[-1] + group)) <= limit:
                chunks[-1].extend(group)
            else:
                chunks.append(list(group))
        links.extend(
            Link(
                level,
                edit_url(number, level, chunk),
                tuple(item.name for item in chunk),
                index,
                len(chunks),
            )
            for index, chunk in enumerate(chunks, 1)
        )
    return links


def reason(item: Difference) -> str:
    """Why the answer is in the issue."""
    if item.gone:
        files = ", ".join(f"`{path}`" for path in item.gone)
        return f"links {files}, which the repository no longer has"
    if not item.found.strip():
        return "is empty on the site"
    if item.status:
        return f"is {item.found} on the site"
    return "says something else on the site"


def quoted(text: str) -> str:
    """A text to enter, as a quote of Markdown."""
    return "\n".join(f"> {line}" if line else ">" for line in text.splitlines())


def body(number: str, found: list[Difference], links: list[Link]) -> str:
    """The text of the issue: the links to open, the answers that differ and why, and
    the texts to enter. When the issue would pass the limit of GitHub, it leaves out
    the texts, which the links carry, and then the last links, which the next weekly
    run gives once the others are saved."""
    page = f"{SITE}/projects/{number}"
    intro = [
        MARKER,
        f"The public entry of this project on bestpractices.dev, [project {number}]"
        f"({page}), differs from `{ANSWERS}` in {len(found)} answer(s). The site "
        f"proposes the answers of the file only where its entry has none: '{SAVE}' "
        "never replaces an answer that the entry gives, and so a changed answer of the "
        "file, such as a link to a file that moved, doesn't reach the entry by itself.",
        "",
        "Open each link while you are signed in as a maintainer of the entry. It "
        "proposes the answers of the file and forces exactly those that the entry "
        "answers otherwise, which the form highlights. Check them, then save:",
        "",
    ]
    unlinked = (
        [""]
        if all(item.level for item in found)
        else [
            "",
            "No edit page takes these answers, as no level of the site knows them: "
            "enter them by hand, or take them out of the file.",
            "",
        ]
    )
    listed = [
        "The answers that differ:",
        "",
        *[
            f"- `{item.name}` ({item.level or 'no level'}) {reason(item)}"
            for item in found
        ],
        "",
    ]
    texts = [
        "<details><summary>The answers of the file, to enter by hand</summary>",
        "",
        *[f"`{item.name}`:\n\n{quoted(item.expected)}\n" for item in found],
        "</details>",
    ]
    short = [
        "The texts to enter are left out, as the issue would be too long: the links "
        "carry them."
    ]
    tail = [
        "",
        "The weekly run of the workflow *Links* reads the public entry once, keeps "
        "this issue current and closes it once the entry gives every answer of the "
        "file. It never writes the entry, and it leaves out the answers that the file "
        "doesn't give, which are the project's own.",
    ]

    def text(shown: int, details: list[str]) -> str:
        more = len(links) - shown
        rest = (
            [
                "",
                f"{more} more link(s) follow in the next weekly run, once these are "
                "saved.",
            ]
            if more
            else []
        )
        return "\n".join(
            intro
            + [f"- [{link.label}]({link.url})" for link in links[:shown]]
            + rest
            + unlinked
            + listed
            + details
            + tail
        )

    full = text(len(links), texts)
    if len(full) <= BODY_LIMIT:
        return full
    shown = len(links)
    while len(text(shown, short)) > BODY_LIMIT and shown > 1:
        shown -= 1
    return text(shown, short)


def own_issue(client: Client, repo: str) -> Json:
    """The open issue of the command, or None, found by its mark alone, so that a
    label that someone takes away opens no second one."""
    for item in client.get_all(f"repos/{repo}/issues?state=open"):
        if "pull_request" not in item and MARKER in str(item.get("body") or ""):
            return item
    return None


@dataclass(frozen=True)
class Outcome:
    """What the command found and did with the issue."""

    summary: list[str]
    warnings: list[str] = field(default_factory=list)
    found: list[Difference] = field(default_factory=list)


def keep_issue(
    client: Client,
    repo: str,
    root: Path,
    *,
    opener: Callable[..., Any] | None = None,
    dry_run: bool = False,
) -> Outcome:
    """Opens the one issue while the entry differs from .bestpractices.json, keeps its
    text current and closes it once the entry gives every answer; with dry_run, only
    says so. A repository without a badge in its README asks nothing, and a site that
    doesn't answer leaves the issue as it is."""
    readme = root / "README.md"
    number = project_number(
        readme.read_text(encoding="utf-8") if readme.is_file() else ""
    )
    if number is None:
        return Outcome(
            ["The README shows no badge of bestpractices.dev: nothing to compare."]
        )
    path = root / ANSWERS
    if not path.is_file():
        return Outcome([f"The repository has no {ANSWERS}: nothing to compare."])
    answers: dict[str, Json] = json.loads(path.read_text(encoding="utf-8"))
    try:
        entry = read_entry(number, opener)
    except Unreachable as error:
        message = (
            f"bestpractices.dev gave no entry ({error}), so the issue stays as it is "
            "until the next run."
        )
        return Outcome(
            [message], [f"::warning title=bestpractices.dev didn't answer::{message}"]
        )
    found = differences(answers, entry, repo, root, criteria_levels())
    summary = [
        f"- `{item.name}` ({item.level or 'no level'}) {reason(item)}" for item in found
    ] or [f"The entry of project {number} gives every answer of {ANSWERS}."]
    issue = own_issue(client, repo)
    if not found:
        if issue is not None:
            closed = issue["number"]
            if not dry_run:
                client.patch(
                    f"repos/{repo}/issues/{closed}",
                    {"state": "closed", "state_reason": "completed"},
                )
            summary.append(
                f"Would close #{closed}." if dry_run else f"Closed #{closed}."
            )
        return Outcome(summary)
    links = edit_links(number, found)
    text = body(number, found, links)
    warnings = [
        f"::warning title=The entry on bestpractices.dev is stale::{len(found)} "
        f"answer(s) of {ANSWERS} differ from the entry of project {number}."
    ]
    if issue is None:
        if not dry_run:
            client.post(
                f"repos/{repo}/issues",
                {"title": TITLE, "body": text, "labels": LABELS},
            )
        summary.append(
            "Would open an issue with the links of the edit pages."
            if dry_run
            else "Opened an issue with the links of the edit pages."
        )
    elif issue.get("body") != text:
        updated = issue["number"]
        if not dry_run:
            client.patch(f"repos/{repo}/issues/{updated}", {"body": text})
        summary.append(
            f"Would update #{updated}." if dry_run else f"Updated #{updated}."
        )
    else:
        summary.append(f"#{issue['number']} names them already.")
    return Outcome(summary, warnings, found)
