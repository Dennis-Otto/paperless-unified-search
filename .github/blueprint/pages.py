"""The pages that GitHub shows, in one column, so that they read well in the GitHub app
and on a phone.

A README, a CONTRIBUTING file or a page of docs/ that sets columns side by side, such
as an HTML table with the text in one cell and a picture in the other, reads well on a
wide screen alone: the GitHub app and a phone keep the columns and squeeze them, until
the text is a strip of a few words and the picture a thumbnail. layouts finds such a
table, and a cell with a width, at its line; its edits put the cells of a layout table
one below the other, the text before its picture, and take the widths out of the cells
of a table of data. Code and comments of HTML don't count, so that a page can show
such markup as an example.
"""

from __future__ import annotations

import re
import textwrap
from collections.abc import Iterable
from dataclasses import dataclass
from pathlib import Path

from blueprint.diagrams import markdown_pages

# The pages of a repository that GitHub shows as such: its READMEs, its guide for
# contributors and the pages of its documentation, also of a folder further down.
NAMES = ("README", "CONTRIBUTING")
DOCS = "docs"
# What the annotations of the CI call a layout.
TITLE = "Pages in one column"

# A tag of HTML that a layout is made of, with its attributes, whose values may hold a >.
TAG = re.compile(
    r"<(?P<close>/?)(?P<name>table|thead|tbody|tfoot|tr|td|th|img|picture)\b"
    r"(?P<attributes>(?:\"[^\"]*\"|'[^']*'|[^'\">])*)>",
    re.I,
)
PICTURES = frozenset({"img", "picture"})
CELLS = frozenset({"td", "th"})
# The attribute width of a cell, with its value; data-width is another one.
WIDTH = re.compile(r"\s+width\s*=\s*(?:\"[^\"]*\"|'[^']*'|[^\s\"'>]+)", re.I)
# The fence of a block of code, also indented in a list; a run of backticks that its
# line repeats starts a span of code instead.
FENCE = re.compile(r"[ \t]*(?P<fence>`{3,}(?=[^`]*$)|~{3,})")
# A comment of HTML, and a span of code: a run of backticks up to the next run of as
# many, within its paragraph.
HIDDEN = re.compile(
    r"<!--.*?-->|(?<!`)(?P<ticks>`+)(?!`)(?:(?!\n[ \t]*\n).)*?(?<!`)(?P=ticks)(?!`)",
    re.S,
)

PICTURE_BESIDE_TEXT = (
    "a <table> that sets a picture beside other content, which the GitHub app and "
    "phones squeeze into narrow columns: the text gets a few words wide and the picture "
    "tiny. Put the text in one column and the picture below it, at full width; a table "
    "is for tabular data."
)
CELL_WIDTH = (
    "a cell with a width, <td width=…>, which lays out columns that the GitHub app and "
    "phones squeeze. Leave the width out; a table is for tabular data, and a page has "
    "one column."
)

# What an edit replaces: from where, up to where, and with what.
Edit = tuple[int, int, str]


@dataclass(frozen=True)
class Layout:
    """Columns side by side on a page: the line where they start, what is wrong, and
    the edits that put them in one column, where there is a safe way to."""

    line: int
    message: str
    edits: tuple[Edit, ...] = ()


@dataclass(frozen=True)
class Table:
    """A table of a page, with the tables inside it: where it starts and ends, and its
    tags."""

    start: int
    end: int
    tags: tuple[re.Match[str], ...]
    closed: bool = True


def shown(page: Path) -> bool:
    """Whether GitHub shows a file of Markdown as a page of the repository: a README,
    a CONTRIBUTING file or a page of docs/."""
    if page.suffix.lower() != ".md":
        return False
    return page.name.upper().startswith(NAMES) or DOCS in page.parts[:-1]


def pages(root: Path) -> list[Path]:
    """The pages of the repository that GitHub shows, without what git ignores."""
    return [page for page in markdown_pages(root) if shown(page)]


def blank(text: str) -> str:
    return re.sub(r"[^\n]", " ", text)


def masked(text: str) -> str:
    """The text with its blocks and spans of code and its comments of HTML as blanks,
    at the same places, so that only the markup that GitHub draws counts."""
    lines = []
    fence = ""
    for line in text.split("\n"):
        if fence:
            closing = re.escape(fence[0]) + "{" + str(len(fence)) + ",}"
            if re.fullmatch(rf"[ \t]*{closing}[ \t]*", line):
                fence = ""
            lines.append(blank(line))
            continue
        opened = FENCE.match(line)
        if opened:
            fence = opened["fence"]
            lines.append(blank(line))
            continue
        lines.append(line)
    return HIDDEN.sub(lambda match: blank(match.group()), "\n".join(lines))


def scan(text: str) -> tuple[list[Table], list[re.Match[str]]]:
    """The tables of a page, each with the tables inside it, and the cells outside of
    a table, which HTML takes as cells of a table as well."""
    tables: list[Table] = []
    stray: list[re.Match[str]] = []
    depth = 0
    start = 0
    tags: list[re.Match[str]] = []
    for tag in TAG.finditer(masked(text)):
        name = tag["name"].lower()
        if name == "table" and not tag["close"]:
            if not depth:
                start = tag.start()
                tags = []
            depth += 1
            tags.append(tag)
        elif depth:
            tags.append(tag)
            if name == "table":
                depth -= 1
                if not depth:
                    tables.append(Table(start, tag.end(), tuple(tags)))
        elif name in CELLS and not tag["close"]:
            stray.append(tag)
    if depth:
        # HTML ends a table that has no end with the page.
        tables.append(Table(start, len(text), tuple(tags), closed=False))
    return tables, stray


def widths(tags: Iterable[re.Match[str]]) -> list[Edit]:
    """The edits that take the widths out of these cells."""
    edits = []
    for tag in tags:
        if tag["name"].lower() in CELLS and not tag["close"]:
            offset = tag.start("attributes")
            edits += [
                (offset + width.start(), offset + width.end(), "")
                for width in WIDTH.finditer(tag["attributes"])
            ]
    return edits


def line_of(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def layouts(text: str) -> list[Layout]:
    """The layouts of a page, in their order: each table that sets a picture beside
    other content, and else each table and each cell outside of one with a width."""
    tables, stray = scan(text)
    found = []
    for table in tables:
        sized = widths(table.tags)
        if any(tag["name"].lower() in PICTURES for tag in table.tags):
            column = one_column(text, table)
            edits = (column,) if column else ()
            found.append(Layout(line_of(text, table.start), PICTURE_BESIDE_TEXT, edits))
        elif sized:
            found.append(Layout(line_of(text, sized[0][0]), CELL_WIDTH, tuple(sized)))
    for cell in stray:
        sized = widths([cell])
        if sized:
            found.append(Layout(line_of(text, cell.start()), CELL_WIDTH, tuple(sized)))
    return sorted(found, key=lambda layout: layout.line)


def one_column(text: str, table: Table) -> Edit | None:
    """The edit that puts the cells of a layout table one below the other, row by row,
    the text of a row before its pictures; None for a table that isn't made of rows
    and cells alone on lines of its own, which a person takes apart."""
    nested = sum(
        1 for tag in table.tags if tag["name"].lower() == "table" and not tag["close"]
    )
    first = text.rfind("\n", 0, table.start) + 1
    last = text.find("\n", table.end)
    last = len(text) if last < 0 else last
    indent = text[first : table.start]
    if (
        not table.closed
        or nested > 1
        or indent.strip()
        or text[table.end : last].strip()
    ):
        return None
    rows: list[list[tuple[str, bool]]] = []
    cell = -1
    picture = False
    position = table.start
    for tag in table.tags:
        name = tag["name"].lower()
        if name in PICTURES:
            if cell < 0:
                return None
            picture = True
            continue
        # A tag of the table ends the cell before it, also where its </td> is missing.
        if cell >= 0:
            rows[-1].append((text[cell : tag.start()], picture))
            cell = -1
        elif text[position : tag.start()].strip():
            return None
        position = tag.end()
        if tag["close"]:
            continue
        if name == "tr" or (name in CELLS and not rows):
            rows.append([])
        if name in CELLS:
            cell = tag.end()
            picture = False
    blocks = [block for content in in_order(rows) if (block := dedented(content))]
    column = "\n".join(
        indent + line if line else line for line in "\n\n".join(blocks).split("\n")
    )
    # Blank lines around it, so that its text starts and ends a paragraph of its own.
    if first and not text[:first].endswith("\n\n"):
        column = "\n" + column
    if text[last + 1 :].split("\n", 1)[0].strip():
        column += "\n"
    return first, last, column


def in_order(rows: list[list[tuple[str, bool]]]) -> list[str]:
    """The contents of the cells, each with whether it holds a picture, in the order of
    one column: row by row, the text of a row before its pictures. A row of pictures
    and the row of their captions, above or below it, go column by column instead, each
    caption before its picture."""
    order: list[str] = []
    index = 0
    while index < len(rows):
        row = rows[index]
        following = rows[index + 1] if index + 1 < len(rows) else []
        if captions(row, following):
            for pair in zip(row, following, strict=True):
                order += [content for content, _ in sorted(pair, key=lambda c: c[1])]
            index += 2
            continue
        order += [content for content, _ in sorted(row, key=lambda c: c[1])]
        index += 1
    return order


def captions(row: list[tuple[str, bool]], following: list[tuple[str, bool]]) -> bool:
    """Whether of two rows of as many cells one holds pictures and the other text alone,
    as a row of captions does."""

    def kinds(cells: list[tuple[str, bool]]) -> set[str]:
        return {
            "picture" if picture else "text"
            for content, picture in cells
            if picture or content.strip()
        }

    return len(row) == len(following) and {
        frozenset(kinds(row)),
        frozenset(kinds(following)),
    } == {frozenset({"picture"}), frozenset({"text"})}


def dedented(content: str) -> str:
    """The content of a cell without the blank lines around it and without the
    indentation that all its lines share."""
    lines = [line.rstrip() for line in content.split("\n")]
    while lines and not lines[0]:
        lines.pop(0)
    while lines and not lines[-1]:
        lines.pop()
    return textwrap.dedent("\n".join(lines))


def fixed(text: str, found: Iterable[Layout]) -> str:
    """The page with the edits of its layouts, which never overlap."""
    edits = sorted(
        (edit for layout in found for edit in layout.edits),
        key=lambda edit: edit[0],
        reverse=True,
    )
    for start, end, replacement in edits:
        text = text[:start] + replacement + text[end:]
    return text


def command(root: Path) -> str:
    """How this repository runs the tool: .github/blueprint.py, or blueprint.py in the
    blueprint itself."""
    script = ".github/blueprint.py"
    return f"python3 {script if (root / script).is_file() else 'blueprint.py'}"


def annotation(page: Path, layout: Layout) -> str:
    """The layout as an annotation of the CI at its line, which the files of a pull
    request show at their place."""
    name = page.as_posix()
    for sign, code in (("%", "%25"), ("\r", "%0D"), ("\n", "%0A"), (":", "%3A")):
        name = name.replace(sign, code)
    name = name.replace(",", "%2C")
    return f"::error file={name},line={layout.line},title={TITLE}::{layout.message}"
