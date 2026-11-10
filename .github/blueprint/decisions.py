"""The records of the decisions in docs/decisions/, which the step *Records of
decisions* of scripts/check.sh checks.

Each record has a number of its own, the next one after the last, and the index
docs/decisions/README.md lists each record once, in the order of the numbers. Two pull
requests that each take the next free number for a record of their own merge without a
conflict of Git, since their files have different names: nothing else fails when two
records share a number, when the index lists a record twice, misses one or names a file
that doesn't exist. This does, so that a merge of main into the second branch, which the
branch bot makes, fails that branch with the numbers and the files, before the clash
reaches main.

`decisions --fix` repairs what only the index gets wrong, which the fix bot commits: it
gives a row the number of its file, points the row of a renamed record at its new file,
drops a second copy of a row, adds the row of a record that the index misses, from the
title and the status of the record, and puts the rows in the order of the numbers. A
number that two records share or that the numbers skip needs a new name of a file, which
only the author can choose and which the fix bot never commits, so that stays for a
person, with the next free number in the message.
"""

from __future__ import annotations

import re
import subprocess
from collections import Counter, defaultdict
from collections.abc import Callable, Iterable
from itertools import pairwise
from pathlib import Path

# The folder of the records, the index that lists them, and the record to copy, which is
# no decision and stays out of the index.
FOLDER = "docs/decisions"
INDEX = "README.md"
TEMPLATE = "0000-template.md"
# A record: four digits and the words of its title, such as 0001-record-decisions.md.
RECORD = re.compile(r"(?P<number>\d{4})-(?P<title>[a-z0-9]+(?:-[a-z0-9]+)*)\.md")
# A row of the index, which names the record by its number and links its file, such as
# | [0001](0001-record-decisions.md) | Record the decisions ... | accepted |
# A link may start with ./, which belongs to the link and not to the name of the file.
ROW = re.compile(
    r"^\|\s*\[(?P<number>[^\]]*)\]\((?:\./)?(?P<file>[^)]*)\)\s*\|", re.MULTILINE
)
# The line below the head of a table of Markdown, such as | --- | --- | --- |.
SEPARATOR = re.compile(r"\|(?:\s*:?-+:?\s*\|)+\s*")
# The title of a record, its first heading, and its status, a line of the list below it.
HEADING = re.compile(r"^#[ \t]+(?P<title>.+?)[ \t]*$", re.MULTILINE)
STATUS = re.compile(r"^[-*][ \t]+Status:[ \t]*(?P<status>.+?)[ \t]*$", re.MULTILINE)
# The table of the template's index, for an index that has none.
TABLE = "| Record | Decision | Status |\n| --- | --- | --- |\n"


def names_of(root: Path, folder: str = FOLDER) -> list[str]:
    """The names of the files right in the folder that Git tracks or would track,
    without those that it ignores, such as the backup of an editor, as scripts/check.sh
    lists them; a folder below it, such as one of pictures, is no record."""
    listed = subprocess.run(
        [
            "git",
            *("ls-files", "-z", "--cached", "--others", "--exclude-standard"),
            *("--deduplicate", "--", folder),
        ],
        cwd=root,
        capture_output=True,
        check=True,
    ).stdout
    prefix = f"{folder}/"
    paths = (path.decode("utf-8", "surrogateescape") for path in listed.split(b"\0"))
    return sorted(
        path.removeprefix(prefix)
        for path in paths
        if path.startswith(prefix)
        and "/" not in path.removeprefix(prefix)
        # A file that the index of Git holds but the work tree deleted is gone.
        and (root / path).is_file()
    )


def numbered(names: Iterable[str]) -> tuple[dict[int, list[str]], list[str]]:
    """The records of a folder by their numbers, and the findings of the files that
    are neither a record, the template nor the index."""
    records: dict[int, list[str]] = defaultdict(list)
    found = []
    for name in sorted(names):
        if name in {INDEX, TEMPLATE}:
            continue
        match = RECORD.fullmatch(name)
        if match is None:
            found.append(f"{name} is no record: a record is named NNNN-its-title.md")
        else:
            records[int(match["number"])].append(name)
    return records, found


def next_free(records: dict[int, list[str]]) -> int:
    """The number that the next record takes: the one after the last."""
    return max(records, default=0) + 1


def number_findings(records: dict[int, list[str]]) -> list[str]:
    """Each number of two records or more, and each that the records skip: the numbers
    run from 0001 to the last, 0000 being the template's."""
    found = [
        f"{number:04d} is the number of {len(files)} records: {', '.join(files)}"
        for number, files in sorted(records.items())
        if len(files) > 1
    ]
    if 0 in records:
        found.append(f"0000 is the number of the template, not of {records[0][0]}")
    skipped = [
        number for number in range(1, max(records, default=0)) if number not in records
    ]
    if skipped:
        numbers = ", ".join(f"{number:04d}" for number in skipped)
        found.append(f"no record has the number {numbers}, which the numbers skip")
    return found


def index_findings(names: Iterable[str], index: str) -> list[str]:
    """Each record that the index lists twice, misses, names by another number or lists
    out of order, and each file that it names but the folder doesn't have."""
    files = set(names)
    rows = [(row["number"], row["file"]) for row in ROW.finditer(index)]
    found = []
    for file, times in sorted(Counter(file for _, file in rows).items()):
        if times > 1:
            found.append(f"the index lists {file} {times} times")
    for number, file in rows:
        match = RECORD.fullmatch(file)
        if file not in files or file in {INDEX, TEMPLATE}:
            found.append(f"the index names {file}, which is no record of the folder")
        elif match is not None and match["number"] != number:
            found.append(f"the index names {file} as {number}")
    listed = {file for _, file in rows}
    records, _ = numbered(files)
    found += [
        f"the index misses {file}"
        for number in sorted(records)
        for file in records[number]
        if file not in listed
    ]
    # By the numbers alone: two records of one number are a clash, which the numbers
    # name, not a row out of order.
    order = [file for _, file in rows]
    found += [
        f"the index lists {later} after {earlier}"
        for earlier, later in pairwise(order)
        if later[:4] < earlier[:4]
    ]
    return found


def findings(names: Iterable[str], index: str | None) -> list[str]:
    """What is wrong with the numbers of the records of a folder, from the names of its
    files, and with its index, from its text, if it has one."""
    names = list(names)
    records, found = numbered(names)
    found += number_findings(records)
    return found if index is None else found + index_findings(names, index)


def cell(text: str) -> str:
    """Text for a cell of a table of Markdown, whose columns a | would split."""
    return text.replace("|", r"\|")


def row_of(name: str, text: str) -> str:
    """The row of the index for a record, from its title and its status, or from the
    words of its name when it has no heading."""
    heading = HEADING.search(text)
    if heading:
        title = heading["title"]
    else:
        words = name[5:-3].replace("-", " ")
        title = words[:1].upper() + words[1:]
    status = STATUS.search(text)
    status_cell = cell(status["status"]) if status else ""
    return f"| [{name[:4]}]({name}) | {cell(title)} | {status_cell} |\n"


def renamed(line: str, row: re.Match[str], file: str) -> str:
    """A row of the index that names this file, by its number."""
    return (
        line[: row.start("number")]
        + file[:4]
        + line[row.end("number") : row.start("file")]
        + file
        + line[row.end("file") :]
    )


def fixed(index: str, names: Iterable[str], read: Callable[[str], str]) -> str:
    """The index with what only it gets wrong fixed: each row names its record by the
    number of its file, a row of a file that is gone names the one record of the same
    title that no row names, a second copy of a row is gone, each record that it misses
    has a row, from the text that read gives for its name, and the rows are in the order
    of the numbers. Two records of one number stay as they are."""
    records, _ = numbered(names)
    recorded = {file for files in records.values() for file in files}
    lines = index.splitlines(keepends=True)
    rows = [(at, row) for at, line in enumerate(lines) if (row := ROW.match(line))]
    named = {row["file"] for _, row in rows}
    # The records that no row names, by their titles, for the rows of renamed files.
    unnamed: dict[str, list[str]] = defaultdict(list)
    for file in sorted(recorded - named):
        unnamed[file[5:-3]].append(file)
    kept: list[tuple[int, str, str]] = []
    seen = set()
    for at, row in rows:
        file = row["file"]
        gone = RECORD.fullmatch(file)
        if file not in recorded and gone and len(unnamed[gone["title"]]) == 1:
            file = unnamed[gone["title"]].pop()
        line = renamed(lines[at], row, file) if file in recorded else lines[at]
        if line not in seen:
            seen.add(line)
            kept.append((at, line, file))
    listed = {file for _, _, file in kept}
    missing = [(row_of(file, read(file)), file) for file in sorted(recorded - listed)]
    # A stable order by the numbers, so that two rows of one number keep theirs; the
    # last line of the index may lack its line break, and moves.
    entries = [
        ended(line)
        for line, _ in sorted(
            [(line, file) for _, line, file in kept] + missing,
            key=lambda entry: entry[1][:4],
        )
    ]
    places = {at for at, _, _ in kept}
    dropped = {at for at, _ in rows} - places
    tables = [at for at, line in enumerate(lines) if SEPARATOR.fullmatch(line.rstrip())]
    # The new rows follow the last row, or the head of the table when it has none.
    after = max(places) if places else tables[0] if tables else None
    queue = iter(entries)
    out: list[str] = []
    for at, line in enumerate(lines):
        if at in dropped:
            continue
        out.append(next(queue) if at in places else line)
        if at == after:
            out[-1] = ended(out[-1])
            out += queue
    rest = list(queue)
    if rest and out:
        out[-1] = ended(out[-1])
        out.append("\n")
    return "".join(out + ([TABLE, *rest] if rest else []))


def ended(line: str) -> str:
    """A line with its line break, before the lines that follow it."""
    return line if line.endswith("\n") else line + "\n"
