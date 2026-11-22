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
checks the highlighted fields and saves (decision 1046).

.bestpractices.json is a file of the blueprint, and an answer that only one repository
can give, such as its end-to-end tests or its security review, goes into
.github/bestpractices.project.toml, a file of the project: a justification there
replaces the file's own for the same criterion, and a criterion that only the project
file answers is added. The command compares the entry with both, and --verify checks
the project file without the network (decision 1088)."""

from __future__ import annotations

import http.client
import json
import re
import tomllib
import urllib.error
import urllib.parse
import urllib.request
from collections.abc import Callable, Collection
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
# The answers of the repository alone, which an update of the blueprint never touches:
# one table for each criterion, named as in .bestpractices.json, with its status and
# justification, or only the justification of one that .bestpractices.json answers.
PROJECT_ANSWERS = ".github/bestpractices.project.toml"
# The keys of a table of the project file, and the statuses that the site knows.
KEYS = ("status", "justification")
STATUSES = ("Met", "Unmet", "N/A")
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
# A link in a justification, and a link of Markdown in a heading, such as
# [1.0.0](https://…/compare/v0.13.3...v1.0.0).
URL = re.compile(r"https?://[^\s<>]+")
LINK_IN_HEADING = re.compile(r"\[([^\]]*)\]\([^)]*\)")
# The repository that the links of .bestpractices.json name.
REPOSITORY = re.compile(r"https://github\.com/([\w.-]+/[\w.-]+)/(?:blob|tree)/")
# The number of a project for a link whose length counts, where the README shows no
# badge: the numbers of the site have five digits now.
SOME_NUMBER = "99999"


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


def criteria(path: Path = CRITERIA) -> dict[str, dict[str, Any]]:
    """The criteria of bestpractices.dev, each with its levels, whether it may be N/A
    (na_allowed) and whether Met needs a link (met_url_required)."""
    known: dict[str, dict[str, Any]] = json.loads(path.read_text(encoding="utf-8"))[
        "criteria"
    ]
    return known


def criteria_levels(path: Path = CRITERIA) -> dict[str, str]:
    """The level whose edit page holds each criterion: the first of those that ask
    for it, as a criterion of passing that silver asks for more strictly is the same
    field."""
    return {name: about["levels"][0] for name, about in criteria(path).items()}


class Unreadable(Exception):
    """The project file is no TOML."""


def file_answers(root: Path) -> dict[str, Json]:
    """The answers of .bestpractices.json, or none without the file."""
    path = root / ANSWERS
    if not path.is_file():
        return {}
    loaded: dict[str, Json] = json.loads(path.read_text(encoding="utf-8"))
    return loaded


def own_answers(root: Path) -> dict[str, Any]:
    """The tables of .github/bestpractices.project.toml, or none without the file."""
    path = root / PROJECT_ANSWERS
    if not path.is_file():
        return {}
    try:
        return tomllib.loads(path.read_text(encoding="utf-8"))
    except tomllib.TOMLDecodeError as error:
        raise Unreadable(f"{PROJECT_ANSWERS} is no TOML: {error}") from error


def answers(root: Path) -> tuple[dict[str, Json], frozenset[str]]:
    """The answers of the repository: those of .bestpractices.json, with each answer
    of the project file in place of the file's own for the same field and after them
    those that only the project file gives; and the fields that the project file
    gives, which are the project's own. As --verify demands, it takes no criterion
    that the site doesn't know, which would keep the issue open for ever, and only the
    justification of a criterion that .bestpractices.json answers."""
    merged = file_answers(root)
    answered = answered_criteria(merged)
    known = criteria()
    own: set[str] = set()
    for criterion, table in own_answers(root).items():
        if not isinstance(table, dict) or criterion not in known:
            continue
        for key in KEYS:
            value = table.get(key)
            if key == "status" and criterion in answered:
                continue
            if isinstance(value, str):
                merged[f"{criterion}_{key}"] = value
                own.add(f"{criterion}_{key}")
    return merged, frozenset(own)


def anchors(markdown: str) -> set[str]:
    """The anchors of the headings of a Markdown file, as GitHub makes them: from the
    text that a heading shows, so of a link in it only its text, as of the version
    that heads each release of the changelog."""
    found: set[str] = set()
    seen: dict[str, int] = {}
    for title in re.findall(r"^#{1,6} (.+)$", markdown, re.MULTILINE):
        anchor = re.sub(
            r"[^\w\- ]", "", LINK_IN_HEADING.sub(r"\1", title).strip().lower()
        ).replace(" ", "-")
        # GitHub gives a repeated heading the suffix -1, -2 and so on.
        count = seen.get(anchor, 0)
        seen[anchor] = count + 1
        found.add(f"{anchor}-{count}" if count else anchor)
    return found


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
    # Whether .github/bestpractices.project.toml gives the answer, as the project's own.
    own: bool = False

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
    own: Collection[str] = frozenset(),
) -> list[Difference]:
    """Each answer of the repository that the entry doesn't give, in the order of the
    answers, of which own names those of the project file. Only these answers count:
    what a maintainer answers on the site and neither file gives stays as it is. A
    status that the file leaves unknown proposes nothing, as the site reads it."""
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
            Difference(
                name,
                levels.get(match.group(1)),
                value.strip(),
                text,
                gone,
                name in own,
            )
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


def listed(item: Difference) -> str:
    """The line of an answer in the issue and in the summary of the job."""
    own = f", the project's own in `{PROJECT_ANSWERS}`" if item.own else ""
    return f"- `{item.name}` ({item.level or 'no level'}{own}) {reason(item)}"


def sources(own: bool) -> str:
    """The files whose answers the entry should give, as code of Markdown."""
    return f"`{ANSWERS}` and `{PROJECT_ANSWERS}`" if own else f"`{ANSWERS}`"


def quoted(text: str) -> str:
    """A text to enter, as a quote of Markdown."""
    return "\n".join(f"> {line}" if line else ">" for line in text.splitlines())


def body(
    number: str, found: list[Difference], links: list[Link], own: bool = False
) -> str:
    """The text of the issue: the links to open, the answers that differ and why, and
    the texts to enter; with own, the repository has answers of its own in the
    project file. When the issue would pass the limit of GitHub, it leaves out the
    texts, which the links carry, and then the last links, which the next weekly run
    gives once the others are saved."""
    page = f"{SITE}/projects/{number}"
    intro = [
        MARKER,
        f"The public entry of this project on bestpractices.dev, [project {number}]"
        f"({page}), differs from {sources(own)} in {len(found)} answer(s). The site "
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
    lines = [
        "The answers that differ:",
        "",
        *[listed(item) for item in found],
        "",
    ]
    if any(item.own for item in found):
        lines += [
            f"The answers marked as the project's own come from `{PROJECT_ANSWERS}`, "
            f"which '{SAVE}' doesn't read: they reach the entry through these links "
            "only. Change them in that file, not on the site, as the next weekly run "
            "forces them back over an edit on bestpractices.dev.",
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
    if own:
        tail = [
            "",
            "The weekly run of the workflow *Links* reads the public entry once, keeps "
            "this issue current and closes it once the entry gives every answer of "
            f"{sources(own)}. It never writes the entry, and it leaves out the answers "
            "that neither file gives, which stay as the site has them.",
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
            + lines
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
    if not (root / ANSWERS).is_file():
        return Outcome([f"The repository has no {ANSWERS}: nothing to compare."])
    try:
        given, own = answers(root)
    except Unreadable as error:
        message = (
            f"{error}, so the issue stays as it is until the file passes "
            "blueprint.py best-practices --verify."
        )
        return Outcome(
            [message], [f"::warning title={PROJECT_ANSWERS} is no TOML::{message}"]
        )
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
    found = differences(given, entry, repo, root, criteria_levels(), own)
    named = sources(bool(own)).replace("`", "")
    summary = [listed(item) for item in found] or [
        f"The entry of project {number} gives every answer of {named}."
    ]
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
    text = body(number, found, links, bool(own))
    warnings = [
        f"::warning title=The entry on bestpractices.dev is stale::{len(found)} "
        f"answer(s) of {named} differ from the entry of project {number}."
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


@dataclass(frozen=True)
class Verification:
    """What --verify found in the project file, and what --fix removed from it."""

    problems: list[str]
    removed: list[str] = field(default_factory=list)
    # The criteria that the project file answers, once it passes.
    answered: int = 0


def answered_criteria(template: dict[str, Json]) -> set[str]:
    """The criteria that .bestpractices.json answers, by their names there."""
    return {
        match.group(1)
        for name in template
        if (match := FIELD.fullmatch(name)) and isinstance(template[name], str)
    }


def repository_of(template: dict[str, Json]) -> str | None:
    """The repository that the links of .bestpractices.json name, as owner/name."""
    for value in template.values():
        found = REPOSITORY.search(str(value))
        if found:
            return found.group(1)
    return None


def missing_headings(text: str, repo: str, root: Path) -> tuple[str, ...]:
    """The links of a text to a heading of a Markdown file of the repository that the
    file of the checkout doesn't have, as path#anchor."""
    pattern = re.compile(
        rf"https://github\.com/{re.escape(repo)}/blob/[^/\s]+/([^\s#?<>]+\.md)#(\S+)",
        re.IGNORECASE,
    )
    missing: list[str] = []
    for link, anchor in pattern.findall(text):
        path = urllib.parse.unquote(link)
        # A browser copies a heading with an umlaut percent-encoded, and GitHub
        # finds a heading whatever the case of its anchor.
        anchor = urllib.parse.unquote(anchor.rstrip(TRAILING)).lower()
        target = root / path
        if not target.is_file() or anchor == "readme":
            continue
        if anchor not in anchors(target.read_text(encoding="utf-8")):
            missing.append(f"{path}#{anchor}")
    return tuple(missing)


def redundant(
    tables: dict[str, Any], template: dict[str, Json]
) -> list[tuple[str, str]]:
    """What --fix removes: the status Met of a criterion that .bestpractices.json
    answers, which only says what the file says, and a justification that only repeats
    the file's own."""
    answered = answered_criteria(template)
    found: list[tuple[str, str]] = []
    for criterion, table in tables.items():
        if criterion not in answered or not isinstance(table, dict):
            continue
        if table.get("status") == "Met":
            found.append((criterion, "status"))
        justification = table.get("justification")
        expected = template.get(f"{criterion}_justification")
        if (
            isinstance(justification, str)
            and isinstance(expected, str)
            and same(f"{criterion}_justification", expected, justification)
        ):
            found.append((criterion, "justification"))
    return found


# The line that starts a table of TOML, [criterion], with a comment after it or not.
HEADER = re.compile(r"""^\s*\[\s*["']?([^\[\]"']+?)["']?\s*\]\s*(?:#.*)?$""")


def without(text: str, removals: list[tuple[str, str]]) -> str:
    """The text of the project file without the keys of removals, each as the lines of
    its value, and without the header of a table that is then empty. Comments and the
    other lines stay as they are."""
    lines = text.splitlines(keepends=True)
    headers = [
        (index, found.group(1).strip())
        for index, line in enumerate(lines)
        if (found := HEADER.match(line))
    ]
    drop: set[int] = set()
    for position, (start, criterion) in enumerate(headers):
        end = headers[position + 1][0] if position + 1 < len(headers) else len(lines)
        keys = {key for name, key in removals if name == criterion}
        if not keys:
            continue
        for key, taken in statements(lines, start + 1, end):
            if key in keys:
                drop.update(taken)
        rest = "".join(
            line
            for index, line in enumerate(lines[start + 1 : end], start + 1)
            if index not in drop
        )
        if not parsed(rest):
            drop.add(start)
    return "".join(line for index, line in enumerate(lines) if index not in drop)


def parsed(text: str) -> dict[str, Any] | None:
    """A text of TOML as tomllib reads it, or None for one that it can't read."""
    try:
        return tomllib.loads(text)
    except tomllib.TOMLDecodeError:
        return None


def statements(lines: list[str], start: int, end: int) -> list[tuple[str, range]]:
    """The keys of a table, each with the lines of its value, which may be a string of
    several lines: from the line of a key, the fewest that TOML reads. Comments and
    empty lines belong to no key."""
    found: list[tuple[str, range]] = []
    first = start
    while first < end:
        line = lines[first].strip()
        if not line or line.startswith("#"):
            first += 1
            continue
        last = first + 1
        while parsed("".join(lines[first:last])) is None and last < end:
            last += 1
        keys = parsed("".join(lines[first:last])) or {}
        found += [(key, range(first, last)) for key in keys]
        first = last
    return found


@dataclass(frozen=True)
class Checkout:
    """What --verify compares the project file with: the checkout, the answers of
    .bestpractices.json and the criteria of the site."""

    root: Path
    template: dict[str, Json]
    known: dict[str, dict[str, Any]]
    answered: set[str]
    # The repository that the links of .bestpractices.json name, and the number of
    # the project for the length of a link.
    repo: str | None
    number: str


def checkout(root: Path) -> Checkout:
    """The checkout in root, as --verify reads it."""
    template = file_answers(root)
    readme = root / "README.md"
    number = project_number(
        readme.read_text(encoding="utf-8") if readme.is_file() else ""
    )
    return Checkout(
        root,
        template,
        criteria(),
        answered_criteria(template),
        repository_of(template),
        number or SOME_NUMBER,
    )


def verify(root: Path, *, fix: bool = False) -> Verification:
    """Whether .github/bestpractices.project.toml gives only answers that the site
    takes and that the repository can show, without a request; with fix, it first
    removes what only repeats .bestpractices.json. A missing file passes."""
    path = root / PROJECT_ANSWERS
    if not path.is_file():
        return Verification([])
    text = path.read_text(encoding="utf-8")
    try:
        tables = tomllib.loads(text)
    except tomllib.TOMLDecodeError as error:
        return Verification([f"{PROJECT_ANSWERS} is no TOML: {error}"])
    found = checkout(root)
    removed: list[str] = []
    removals = redundant(tables, found.template) if fix else []
    if removals:
        text = without(text, removals)
        path.write_text(text, encoding="utf-8", newline="\n")
        tables = tomllib.loads(text)
        removed = [
            f"{key} of [{criterion}]"
            for criterion, key in removals
            if key not in tables.get(criterion, {})
        ]
    problems = [
        problem
        for criterion, table in tables.items()
        for problem in table_problems(criterion, table, found)
    ]
    return Verification(problems, removed, len(tables))


def table_problems(criterion: str, table: Any, found: Checkout) -> list[str]:
    """What is wrong with the table of one criterion of the project file."""
    where = f"{PROJECT_ANSWERS}: [{criterion}]"
    about = found.known.get(criterion)
    if about is None:
        return [
            f"{where} is no criterion of bestpractices.dev: a table takes the name of "
            f"its criterion in {ANSWERS}, such as [test] or [osps_br_07_01]."
        ]
    if not isinstance(table, dict):
        return [f"{where} is no table with status and justification."]
    problems = [
        f"{where} has the key {key}: a table gives only status and justification."
        for key in table
        if key not in KEYS
    ]
    problems += [
        f"{where}: {key} is no string."
        for key in KEYS
        if key in table and not isinstance(table[key], str)
    ]
    status, justification = (
        value if isinstance(value := table.get(key), str) else None for key in KEYS
    )
    answered = criterion in found.answered
    if answered and "status" in table:
        problems.append(
            f"{where} gives a status, which {ANSWERS} gives for it: the table of a "
            "criterion that the file answers gives only its justification."
        )
    if answered and justification is None:
        problems.append(f"{where} gives no justification.")
    if not answered and (status is None or justification is None):
        problems.append(
            f"{where} needs both status and justification, as {ANSWERS} doesn't "
            "answer it."
        )
    if status is not None and status not in STATUSES:
        problems.append(f"{where}: the status is Met, Unmet or N/A, not {status!r}.")
    if status == "N/A" and not about["na_allowed"]:
        problems.append(f"{where}: bestpractices.dev doesn't take N/A for it.")
    if justification is not None:
        given = found.template.get(f"{criterion}_status") if answered else status
        problems += justification_problems(
            criterion, justification, str(given or ""), about, found
        )
    return problems


def justification_problems(
    criterion: str,
    justification: str,
    status: str,
    about: dict[str, Any],
    found: Checkout,
) -> list[str]:
    """What is wrong with the justification of a criterion: its links, its length and
    whether it only repeats .bestpractices.json."""
    where = f"{PROJECT_ANSWERS}: [{criterion}]"
    problems: list[str] = []
    links = [link.rstrip(TRAILING) for link in URL.findall(justification)]
    if status == "Met" and about["met_url_required"] and not links:
        problems.append(f"{where}: Met needs a link that shows it.")
    problems += [
        f"{where} links {link}: a link uses https."
        for link in links
        if link.startswith("http://")
    ]
    if found.repo is not None:
        problems += [
            f"{where} links {path}, which the repository doesn't have."
            for path in gone_files(justification, found.repo, found.root)
        ]
        problems += [
            f"{where} links {heading}, a heading that the file doesn't have."
            for heading in missing_headings(justification, found.repo, found.root)
        ]
    level = about["levels"][0]
    # The longest link that the answer can need: its status and its justification,
    # both forced.
    longest = edit_url(
        found.number,
        level,
        [
            Difference(f"{criterion}_status", level, status or "Met", "?!"),
            Difference(f"{criterion}_justification", level, justification, "?!"),
        ],
    )
    if len(longest) > LIMIT:
        problems.append(
            f"{where}: the justification is too long for one link of the issue, "
            f"which takes {LIMIT} characters: {len(longest)}."
        )
    expected = found.template.get(f"{criterion}_justification")
    if isinstance(expected, str) and same(
        f"{criterion}_justification", expected, justification
    ):
        problems.append(
            f"{where} repeats the justification of {ANSWERS}, which then only goes "
            "stale here: leave the table out."
        )
    return problems
