"""What a pull request makes, for the one report of the PR coach: the release that it
brings and the entry that it adds to the changelog, the coverage of its tests against
that of main, the website that it builds, and the parts that workflows add, such as the
size of a module or the pictures of visual tests.

The coach adds these parts after the sections of the checks that failed. A pull request
controls its title, its description, its files, its coverage report and what its
workflows keep, so their text appears only as code, and a link leads only to GitHub or
to the website of the repository, built from names that can't end it. The versions, the
address of the website and the titles of the parts come from the files of the default
branch; nothing of the pull request runs.
"""

from __future__ import annotations

import json
import re
import tempfile
import textwrap
from collections.abc import Callable, Iterable, Sequence
from dataclasses import dataclass
from itertools import islice
from pathlib import Path
from typing import Any

from blueprint.coach import (
    CONTROL,
    MOST_REPORTS,
    PAGE,
    TYPES,
    Part,
    ReportEntry,
    code,
    fence,
    paged,
)
from blueprint.coverage_comment import COVERAGE_MARKER, Coverage, coverage_table
from blueprint.github import Api, Json

MANIFEST = Path(".release-please-manifest.json")
CONFIG = Path("release-please-config.json")
MKDOCS = Path("mkdocs.yml")
# The kinds of change that release-please shows in the notes of a release when its
# config names none; a change of another kind releases nothing by itself.
SHOWN = frozenset({"feat", "fix", "perf", "revert"})
# A title that passes the check of the title, and the lines of the description that
# release-please reads in the squash commit, whose message is the description.
TITLE = re.compile(r"(?P<kind>[a-z]+)(?:\([a-z0-9._/-]+\))?(?P<bang>!)?: \S")
BREAKING = re.compile(r"^BREAKING[ -]CHANGE: ", re.MULTILINE)
RELEASE_AS = re.compile(r"^Release-As: *v?(\d+\.\d+\.\d+)[ \t]*\r?$", re.M | re.I)
VERSION = re.compile(r"v?(\d+)\.(\d+)\.(\d+)")
LEVELS = ("patch", "minor", "major")
# The files of a pull request that the report reads: GitHub lists up to 3000.
MOST_FILES = 300
# The entry of the changelog, wrapped for reading: its first lines, so many at most.
ENTRY_LINES = 40
ENTRY_WIDTH = 100
# The pages of the website that the report names, at most.
MOST_PAGES = 10
# A page whose name can't end a link or leave the website: no space, no parenthesis.
PAGE_NAME = re.compile(
    r"docs/(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*\.md"
)
# What the website shows besides the pages of docs/, as the READMEs and the changelog.
WEBSITE = re.compile(r"(docs/.+|mkdocs\.yml|README(\.[a-z]{2})?\.md|CHANGELOG\.md)")
FRAGMENT = re.compile(r"changelog\.d/[A-Za-z0-9][A-Za-z0-9._-]*\.md")
WEBSITE_DAYS = 7
# The artifact of the website that the check docs keeps of a pull request.
WEBSITE_ARTIFACT = "website"
# A part that a workflow adds: the artifact pr-report-<name> that it keeps for the head
# of the pull request, with the file report.md, where [report.<name>] of the catalog
# declares it. Its first line QUIET makes it no news by itself, such as a size that
# stays as it was.
REPORT_ARTIFACT = "pr-report-"
REPORT_FILE = "report.md"
QUIET = "<!-- quiet -->"
# What the coach takes of the parts of the workflows at most (MOST_REPORTS parts): each
# artifact so large as GitHub keeps it zipped, so much of its report.md, so long a
# part, so many rows and columns of a table, so long a cell, paragraph or line of code,
# and so many lines of a block of code: the longest table and block of code fit into a
# part, and the parts into the comment beside the others.
REPORT_BYTES = 256 * 1024
REPORT_TEXT = 64 * 1024
REPORT_LENGTH = 8000
TABLE_ROWS = 20
TABLE_COLUMNS = 6
CELL_LENGTH = 50
PARAGRAPH_LENGTH = 1000
CODE_LINES = 30
CODE_WIDTH = 200

Version = tuple[int, int, int]


# ---------------------------------------------------------------- the release


def version_of(text: str) -> Version | None:
    """A version of three numbers, such as 0.12.0, and none of a prerelease."""
    found = VERSION.fullmatch(text.strip())
    return (int(found[1]), int(found[2]), int(found[3])) if found else None


def shown(version: Version) -> str:
    return ".".join(str(number) for number in version)


@dataclass(frozen=True)
class Releases:
    """How release-please releases this repository: its version now, the kinds of
    change that its notes show, and how it counts a version below 1.0.0."""

    version: Version | None
    kinds: frozenset[str] = SHOWN
    minor_pre_major: bool = False
    patch_pre_major: bool = False

    @classmethod
    def load(cls, root: Path) -> Releases | None:
        """The releases of the repository at root, none without release-please."""
        try:
            versions = json.loads((root / MANIFEST).read_text(encoding="utf-8"))
            path = root / CONFIG
            config = (
                json.loads(path.read_text(encoding="utf-8")) if path.is_file() else {}
            )
        except (OSError, ValueError):
            return None
        if not isinstance(versions, dict) or not isinstance(config, dict):
            return None
        package: dict[str, Any] = {**config, **config.get("packages", {}).get(".", {})}
        sections = package.get("changelog-sections")
        kinds = SHOWN
        if isinstance(sections, list):
            kinds = frozenset(
                str(section.get("type"))
                for section in sections
                if isinstance(section, dict) and not section.get("hidden")
            )
        return cls(
            version_of(str(versions.get(".", ""))),
            kinds,
            package.get("bump-minor-pre-major") is True,
            package.get("bump-patch-for-minor-pre-major") is True,
        )

    def level(self, kind: str, breaking: bool) -> str | None:
        """The part of the version that a change of a kind raises, as release-please
        counts it, or none when it releases nothing by itself."""
        if breaking:
            level = "major"
        elif kind == "feat":
            level = "minor"
        elif kind in self.kinds:
            level = "patch"
        else:
            return None
        if self.version is not None and self.version[0] == 0:
            if level == "major" and self.minor_pre_major:
                return "minor"
            if level == "minor" and self.patch_pre_major:
                return "patch"
        return level


def bump(version: Version, level: str) -> Version:
    major, minor, patch = version
    if level == "major":
        return (major + 1, 0, 0)
    if level == "minor":
        return (major, minor + 1, 0)
    return (major, minor, patch + 1)


def pending_release(api: Api, repo: str) -> tuple[int, Version] | None:
    """The release pull request of release-please and its version: a branch of this
    repository, as no fork can make one."""
    for pull in paged(api, f"repos/{repo}/pulls?state=open"):
        head = pull.get("head") or {}
        ref = str(head.get("ref") or "")
        same = (head.get("repo") or {}).get("full_name") == repo
        title = str(pull.get("title") or "")
        found = re.search(r"\brelease v?(\d+)\.(\d+)\.(\d+)$", title)
        if same and ref.startswith("release-please--") and found:
            version = (int(found[1]), int(found[2]), int(found[3]))
            return int(pull["number"]), version
    return None


def release_lines(
    level: str,
    breaking: bool,
    releases: Releases,
    pending: Callable[[], tuple[int, Version] | None],
) -> list[str]:
    """What the merge does to the next release."""
    kind = f"a {level} release"
    note = ""
    if breaking and level == "major":
        note = " It is a breaking change."
    elif breaking:
        note = (
            " It is a breaking change, which makes a minor release while the version "
            "is below 1.0.0."
        )
    if releases.version is None:
        return [f"📦 Merging this pull request makes the next release {kind}.{note}"]
    after = bump(releases.version, level)
    waiting = pending()
    if waiting is not None and waiting[1] >= after:
        number, version = waiting
        return [
            f"📦 This pull request goes into the next release, **{shown(version)}**, "
            f"which the release pull request #{number} has already.{note}"
        ]
    was = (
        f"instead of {shown(waiting[1])} of #{waiting[0]}"
        if waiting
        else f"after {shown(releases.version)}"
    )
    return [
        f"📦 Merging this pull request makes the next release **{shown(after)}**, "
        f"{kind} {was}.{note}"
    ]


def added(patch: str) -> list[str]:
    """The lines that a patch of the files API adds."""
    return [line[1:] for line in patch.splitlines() if line.startswith("+")]


def entry_of(files: Iterable[Json]) -> tuple[list[str], list[str]]:
    """The lines that a pull request adds to the changelog, in fragments of
    changelog.d/ and in CHANGELOG.md, and the files too large for GitHub to show."""
    lines: list[str] = []
    large: list[str] = []
    for item in files:
        name = str(item.get("filename") or "")
        if item.get("status") == "removed" or not (
            FRAGMENT.fullmatch(name) or name == "CHANGELOG.md"
        ):
            continue
        patch = item.get("patch")
        if isinstance(patch, str):
            lines += added(patch)
        else:
            large.append(name)
    return lines, large


def wrapped(lines: Iterable[str]) -> list[str]:
    """The lines of an entry as code to read: no control character, each one wrapped,
    with the indent of a list under its item."""
    result: list[str] = []
    for line in lines:
        line = CONTROL.sub(" ", line).rstrip()
        width = len(line) - len(line.lstrip())
        bullet = re.match(r"(?:[-*+]|\d+\.) ", line.lstrip())
        if bullet:
            width += len(bullet[0])
        result += textwrap.wrap(
            line,
            ENTRY_WIDTH,
            subsequent_indent=" " * width,
            break_long_words=False,
            break_on_hyphens=False,
        ) or [""]
    return result


def entry_lines(lines: list[str], large: list[str]) -> list[str]:
    """The entry of the changelog that the pull request adds, as code."""
    text = wrapped(lines)
    while text and not text[-1].strip():
        text.pop()
    while text and not text[0].strip():
        text.pop(0)
    result: list[str] = []
    if text:
        shown_lines = text[:ENTRY_LINES]
        more = len(text) - len(shown_lines)
        result += [
            "<details><summary>The entry in the changelog, which the release takes into "
            "its notes</summary>",
            "",
            fence("markdown", shown_lines),
            "",
            *([f"… and {more} more lines.", ""] if more else []),
            "</details>",
        ]
    for name in large[:MOST_PAGES]:
        result.append(f"- {code(name)} changes too much for GitHub to show here.")
    return result


def release_part(
    pull: Json,
    files: Sequence[Json],
    releases: Releases | None,
    pending: Callable[[], tuple[int, Version] | None],
) -> Part | None:
    """What the pull request releases, and how its entry of the changelog reads."""
    title = str(pull.get("title") or "")
    body = str(pull.get("body") or "")
    match = TITLE.match(title)
    # A title that is no Conventional Commit fails its check, which explains it.
    if releases is None or match is None or match["kind"] not in TYPES:
        return None
    lines, large = entry_of(files)
    breaking = bool(match["bang"]) or bool(BREAKING.search(body))
    level = releases.level(match["kind"], breaking)
    forced = RELEASE_AS.search(body)
    text = ["#### Release", ""]
    if forced:
        # Digits and dots alone, which the pattern of the line lets through.
        text.append(
            f"📦 The line `Release-As: {forced[1]}` of the description makes the next "
            f"release **{forced[1]}**."
        )
    elif level is None:
        text.append(
            f"This pull request releases nothing by itself: a change of the kind "
            f"`{match['kind']}` goes out with the next release that another one makes."
        )
    else:
        text += release_lines(level, breaking, releases, pending)
    if not lines and not large and (forced or level):
        text += [
            "",
            "It adds no entry to the changelog, so the notes of the release list its "
            "title.",
        ]
    entry = entry_lines(lines, large)
    if entry:
        text += ["", *entry]
    return Part("\n".join(text), news=bool(forced or level or lines or large))


# ---------------------------------------------------------------- coverage


def coverage_part(head: Coverage, base: Coverage | None) -> Part:
    """The coverage of the tests of the pull request against that of main, which is
    news when the coverage of a file changes; it takes the place of the comment of the
    coverage bot."""
    rows, changed = coverage_table(head, base)
    return Part(
        "\n".join(["#### Coverage", "", *rows]), news=changed, replaces=COVERAGE_MARKER
    )


# ---------------------------------------------------------------- the website


@dataclass(frozen=True)
class Website:
    """The website of the repository, from mkdocs.yml of the default branch: its
    address, and whether it has German pages under de/."""

    url: str
    german: bool

    @classmethod
    def load(cls, root: Path) -> Website | None:
        try:
            text = (root / MKDOCS).read_text(encoding="utf-8")
        except OSError:
            return None
        found = re.search(r"^site_url: *(https://[^\s#'\"]+)", text, re.MULTILINE)
        url = found[1].rstrip("/") + "/" if found else ""
        german = re.search(r"^ +- locale: de\b", text, re.MULTILINE) is not None
        return cls(url, german)

    def page(self, name: str) -> str:
        """The address of a page of docs/ after the merge: guide.md at guide/, a
        README.md or index.md at its folder, guide.de.md at de/guide/."""
        path = name.removeprefix("docs/").removesuffix(".md")
        prefix = ""
        if self.german and path.endswith(".de"):
            path, prefix = path.removesuffix(".de"), "de/"
        folders = path.split("/")
        if folders[-1] in ("index", "README"):
            folders.pop()
        return self.url + prefix + "".join(f"{folder}/" for folder in folders)


@dataclass(frozen=True)
class Artifact:
    """An artifact that a run kept: the number of the run, its own, and its size."""

    run: int
    id: int
    size: int = 0

    def link(self, repo: str) -> str:
        return f"https://github.com/{repo}/actions/runs/{self.run}/artifacts/{self.id}"


def newest_artifact(api: Api, repo: str, sha: str, name: str) -> Artifact | None:
    """The newest artifact of a name that a run kept for sha and that hasn't expired:
    GitHub lists the newest first, so a run again takes the place of the one before."""
    data = api.get(f"repos/{repo}/actions/artifacts?name={name}&per_page={PAGE}")
    for item in (data or {}).get("artifacts") or []:
        run = item.get("workflow_run") or {}
        if run.get("head_sha") == sha and not item.get("expired"):
            size = int(item.get("size_in_bytes") or 0)
            return Artifact(int(run["id"]), int(item["id"]), size)
    return None


def website_artifact(api: Api, repo: str, sha: str) -> str | None:
    """The link to the artifact website that the check docs kept for sha."""
    found = newest_artifact(api, repo, sha, WEBSITE_ARTIFACT)
    return found.link(repo) if found else None


def website_part(
    files: Sequence[Json], website: Website | None, artifact: Callable[[], str | None]
) -> Part | None:
    """The website that the pull request builds and the pages that it changes, news
    when it changes what the website shows."""
    names = [
        str(item.get("filename") or "")
        for item in files
        if item.get("status") != "removed"
    ]
    if website is None or not any(WEBSITE.fullmatch(name) for name in names):
        return None
    pages = [name for name in names if PAGE_NAME.fullmatch(name)]
    lines = ["#### Website", ""]
    link = artifact()
    if link:
        lines += [
            f"🌐 [The website of this pull request]({link}), as the check *docs* built "
            f"it, stays for {WEBSITE_DAYS} days: unzip the artifact `website` into a "
            "folder, run `python3 -m http.server --directory website` and open "
            "http://localhost:8000.",
            "",
        ]
    else:
        lines += [
            "🌐 The check *docs* has kept no build of the website yet; once it "
            "passes, this links it.",
            "",
        ]
    if pages:
        lines += ["The pages that it changes, at their address after the merge:", ""]
        for name in pages[:MOST_PAGES]:
            url = website.page(name) if website.url else ""
            lines.append(f"- [{code(name)}]({url})" if url else f"- {code(name)}")
        if len(pages) > MOST_PAGES:
            lines.append(f"- … and {len(pages) - MOST_PAGES} more")
    return Part("\n".join(lines).rstrip(), news=True)


# ---------------------------------------------------------------- the parts of workflows

# The lines of the Markdown of a part that begin a block of their own: the fence of a
# block of code with what follows it, of which the language comes first, a row of a
# table and the row below its head that aligns its columns, an item of a list with its
# indent, and a heading. Each pattern reads a line in one pass: a line of the 64 kB of
# a report.md from a fork can't make a pattern try again at every place.
CODE_FENCE = re.compile(r" {0,3}(`{3,}|~{3,}) *([A-Za-z0-9+-]*)(.*)")
TABLE_ROW = re.compile(r" {0,3}\|.*\|")
TABLE_DELIMITER = re.compile(r" {0,3}\|(?: *:?-+:? *\|)+")
LIST_ITEM = re.compile(r"( *)(?:[-*+]|\d{1,9}[.)]) +(\S.*)")
HEADING = re.compile(r" {0,3}#{1,6} +(.*)")
# The languages of a block of code that GitHub draws as a diagram, a map, a model or a
# formula instead of showing it as code; such a block of a part shows without one.
DRAWN = frozenset({"mermaid", "geojson", "topojson", "stl", "math"})


@dataclass
class Block:
    """A block of the Markdown of a part: its kind, its lines, how deep an item of a
    list is, and whether the next line can continue it, as one of a paragraph can."""

    kind: str
    lines: list[str]
    depth: int = 0
    open: bool = False


def code_fence(line: str) -> re.Match[str] | None:
    """The fence that opens a block of code on line; a fence of backticks with a
    backtick after it is none, but code inside a paragraph."""
    opening = CODE_FENCE.fullmatch(line)
    if opening and opening[1][0] == "`" and "`" in opening[3]:
        return None
    return opening


def fenced(lines: list[str], start: int, opening: re.Match[str]) -> tuple[Block, int]:
    """The block of code that opens at start, up to the fence that closes it or the end
    of the text, with its language first, and the line after it."""
    closing = re.compile(rf" {{0,3}}{re.escape(opening[1][0])}{{{len(opening[1])},}} *")
    end = start + 1
    while end < len(lines) and not closing.fullmatch(lines[end]):
        end += 1
    return Block("code", [opening[2], *lines[start + 1 : end]]), end + 1


def tabled(lines: list[str], start: int) -> tuple[Block, int] | None:
    """The table whose head is at start, with the row below it that aligns its
    columns, and the line after it; none when start begins no table."""
    if not (
        TABLE_ROW.fullmatch(lines[start])
        and start + 1 < len(lines)
        and TABLE_DELIMITER.fullmatch(lines[start + 1])
    ):
        return None
    end = start + 2
    while end < len(lines) and TABLE_ROW.fullmatch(lines[end]):
        end += 1
    return Block("table", lines[start:end]), end


def block_of(line: str) -> Block:
    """The block that a line begins outside a block of code or a table."""
    item = LIST_ITEM.fullmatch(line)
    if item:
        return Block("item", [item[2]], len(item[1]) // 2, open=True)
    heading = HEADING.fullmatch(line)
    if heading:
        # Without the marks that may close it, after a space.
        closed = heading[1].rstrip("#")
        text = closed.rstrip() if closed.endswith(" ") else heading[1]
        return Block("heading", [text])
    return Block("text", [line.strip()], open=True)


def next_block(lines: list[str], start: int, blocks: list[Block]) -> int:
    """Reads the line at start into blocks, as a block of its own or as the next line
    of a paragraph or an item, and returns the line after what it read."""
    opening = code_fence(lines[start])
    found = fenced(lines, start, opening) if opening else tabled(lines, start)
    if found:
        blocks.append(found[0])
        return found[1]
    last = blocks[-1] if blocks else None
    block = block_of(lines[start]) if lines[start].strip() else None
    if block is None:
        if last:
            last.open = False
    elif last and last.open and block.kind == "text":
        last.lines += block.lines
    else:
        blocks.append(block)
    return start + 1


def blocks_of(text: str) -> list[Block]:
    """The blocks of the Markdown of a part, without control characters: blocks of
    code, tables, items of lists, headings and paragraphs."""
    lines = [
        CONTROL.sub(" ", line.expandtabs(4)).rstrip() for line in text.splitlines()
    ]
    blocks: list[Block] = []
    start = 0
    while start < len(lines):
        start = next_block(lines, start, blocks)
    return blocks


def cells(row: str) -> list[str]:
    """The cells of a row of a table, split at each pipe that no backslash escapes."""
    inner = row.strip().removeprefix("|")
    inner = inner.removesuffix("|") if not inner.endswith("\\|") else inner
    return [part.strip().replace("\\|", "|") for part in re.split(r"(?<!\\)\|", inner)]


def cell(text: str) -> str:
    """A cell of a table as code, whose pipes end neither its code nor its cell."""
    return code(text, CELL_LENGTH).replace("|", "\\|")


def alignment(delimiter: str) -> str:
    """How a column of the row below the head aligns its cells, as GitHub reads it."""
    left, right = delimiter.startswith(":"), delimiter.endswith(":")
    return (":" if left else "") + "---" + (":" if right else "")


def table_lines(block: Block) -> list[str]:
    """A table with every cell as code, so many rows and columns at most, each row
    with as many cells as its head."""
    head = cells(block.lines[0])[:TABLE_COLUMNS]
    width = len(head)
    aligned = [alignment(part) for part in cells(block.lines[1])]
    aligned = (aligned + ["---"] * width)[:width]
    rows = [cells(row) for row in block.lines[2:]]

    def row(values: list[str]) -> str:
        values = (values + [""] * width)[:width]
        return "| " + " | ".join(cell(value) for value in values) + " |"

    lines = [row(head), "| " + " | ".join(aligned) + " |"]
    lines += [row(values) for values in rows[:TABLE_ROWS]]
    if len(rows) > TABLE_ROWS:
        lines += ["", f"… and {len(rows) - TABLE_ROWS} more rows."]
    return lines


def code_lines(block: Block) -> list[str]:
    """A block of code that none of its lines can close, so many lines at most."""
    language, lines = block.lines[0], block.lines[1:]
    language = "" if language.lower() in DRAWN else language
    shown_lines = [line[:CODE_WIDTH] for line in lines[:CODE_LINES]]
    more = len(lines) - len(shown_lines)
    return [
        fence(language, shown_lines),
        *([f"… and {more} more lines."] if more else []),
    ]


def shown_block(block: Block) -> str:
    """A block of the Markdown of a part as the report shows it: its text as code."""
    if block.kind == "code":
        return "\n".join(code_lines(block))
    if block.kind == "table":
        return "\n".join(table_lines(block))
    text = code(" ".join(block.lines), PARAGRAPH_LENGTH)
    if block.kind == "item":
        return "  " * block.depth + "- " + text
    if block.kind == "heading":
        return f"**{text}**"
    return text


def shown_report(text: str) -> tuple[str, bool]:
    """The Markdown of a part as data, at most REPORT_LENGTH long, and whether its
    report.md holds more than that. Every block shows some text: a line that has none
    begins no block."""
    shown_text = ""
    previous = ""
    depth = 0
    for block in blocks_of(text):
        if block.kind == "item":
            # At most one deeper than the item before it, and none deep after anything
            # else, which GitHub would show indented as code instead of as an item.
            deepest = depth + 1 if previous == "item" else 0
            depth = block.depth = min(block.depth, deepest, 2)
        rendered = shown_block(block)
        # Items next to each other stay one list.
        gap = "\n" if previous == block.kind == "item" else "\n\n"
        gap = gap if shown_text else ""
        if len(shown_text) + len(gap) + len(rendered) > REPORT_LENGTH:
            return shown_text, True
        shown_text += gap + rendered
        previous = block.kind
    return shown_text, False


def read_report(api: Api, repo: str, name: str, artifact: Artifact) -> str | None:
    """The first REPORT_TEXT bytes of report.md of an artifact, or none when gh can't
    download it or it holds no such file. gh unzips the artifact into a folder of its
    own, and nothing of it runs."""
    with tempfile.TemporaryDirectory() as folder:
        arguments = ["run", "download", str(artifact.run), "--repo", repo]
        done = api.runner([*arguments, "--name", name, "--dir", folder], None)
        path = Path(folder) / REPORT_FILE
        if done.returncode != 0 or path.is_symlink() or not path.is_file():
            return None
        with path.open("rb") as file:
            data = file.read(REPORT_TEXT)
    # Without the byte order mark that some editors write, so QUIET stays first.
    return data.decode("utf-8-sig", errors="replace")


def workflow_part(
    entry: ReportEntry, repo: str, artifact: Artifact, text: str | None
) -> Part | None:
    """The part that a workflow added, under the title and the text of its entry in the
    catalog, with a link to its artifact; none when its report.md says nothing, and a
    note when the artifact is too large or the coach couldn't read it. It is news
    unless its first line is QUIET."""
    link = f"[`{REPORT_ARTIFACT}{entry.key}`]({artifact.link(repo)})"
    lines = [f"#### {entry.title}", ""]
    if entry.about:
        lines += [entry.about, ""]
    # The older comment stays until the part shows what it said.
    news, replaces = True, ""
    if artifact.size > REPORT_BYTES:
        lines.append(
            f"The artifact {link} is {artifact.size // 1024} kB, more than the "
            f"{REPORT_BYTES // 1024} kB that the PR coach reads; let its workflow "
            f"keep `{REPORT_FILE}` alone in it."
        )
    elif text is None:
        news = False
        lines.append(
            f"The PR coach couldn't read `{REPORT_FILE}` of the artifact {link}; its "
            "next run tries again."
        )
    else:
        first, _, rest = text.partition("\n")
        news = first.strip() != QUIET
        shown_text, more = shown_report(text if news else rest)
        if not shown_text and not more:
            return None
        shown_text = shown_text or f"Its `{REPORT_FILE}` begins with too much to show."
        held = ", which holds more" if more else ""
        replaces = entry.replaces
        lines += [shown_text, "", f"<sub>From the artifact {link}{held}.</sub>"]
    return Part("\n".join(lines), news=news, replaces=replaces)


def workflow_parts(
    api: Api, repo: str, sha: str, entries: Iterable[ReportEntry]
) -> list[Part]:
    """The parts that workflows added for sha, in the order of the catalog: one for
    each artifact pr-report-<name> that [report.<name>] declares."""
    parts: list[Part] = []
    for entry in islice(entries, MOST_REPORTS):
        name = REPORT_ARTIFACT + entry.key
        artifact = newest_artifact(api, repo, sha, name)
        if artifact is None:
            continue
        text = None
        if artifact.size <= REPORT_BYTES:
            text = read_report(api, repo, name, artifact)
        part = workflow_part(entry, repo, artifact, text)
        if part is not None:
            parts.append(part)
    return parts


# ---------------------------------------------------------------- the parts


def about(
    api: Api,
    repo: str,
    pull: Json,
    root: Path,
    coverage: tuple[Coverage, Coverage | None] | None = None,
    reports: Iterable[ReportEntry] = (),
) -> list[Part]:
    """The parts of the report about the pull request: its release, its coverage when
    the tests kept a report, its website, and the parts that its workflows added, as
    reports of the catalog declares them."""
    number = int(pull["number"])
    sha = str(pull["head"]["sha"])
    files = list(islice(paged(api, f"repos/{repo}/pulls/{number}/files"), MOST_FILES))
    parts = [
        release_part(
            pull, files, Releases.load(root), lambda: pending_release(api, repo)
        ),
        coverage_part(*coverage) if coverage else None,
        website_part(
            files, Website.load(root), lambda: website_artifact(api, repo, sha)
        ),
        *workflow_parts(api, repo, sha, reports),
    ]
    return [part for part in parts if part is not None]
