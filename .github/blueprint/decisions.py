"""The records of the decisions in docs/decisions/, which the step *Records of
decisions* of scripts/check.sh checks.

A new record takes the number of its pull request, or that of an issue that the pull
request fixes, which GitHub counts together, so that no other pull request can take it
(decision 1033). Each record has a number of its own; the numbers have gaps. The index
docs/decisions/README.md lists each released record once, in the order of the numbers,
and pull requests leave it alone: the release writes the rows of the new records, with
`decisions --complete`, in the commit that writes CHANGELOG.md, and the website adds
them while it builds. So two pull requests that each add a record merge without a
conflict of Git.

The step fails when two records share a number, when a file of the folder is no record,
and when the index lists a record twice, names a file that doesn't exist, names a
record by another number or lists the records out of order. In the CI of a pull
request, it also fails on a record that main doesn't have and that carries neither the
number of the pull request nor that of an issue that the pull request fixes, and the
log names the number to take.

`decisions --fix` repairs what only the index gets wrong, which the fix bot commits: it
gives a row the number of its file, points the row of a renamed record at its new file,
drops a second copy of a row and puts the rows in the order of the numbers. A number
that two records share needs a new name of a file, which only the author can choose and
which the fix bot never commits, so that stays for a person.
"""

from __future__ import annotations

import json
import re
import subprocess
from collections import Counter, defaultdict
from collections.abc import Callable, Collection, Iterable, Mapping
from dataclasses import dataclass
from itertools import pairwise
from pathlib import Path

# The folder of the records, the index that lists them, and the record to copy, which is
# no decision and stays out of the index.
FOLDER = "docs/decisions"
INDEX = "README.md"
TEMPLATE = "0000-template.md"
# A record: four digits or more and the words of its title, such as
# 0001-record-decisions.md or 1033-a-new-record-takes-the-number-of-its-pull-request.md.
RECORD = re.compile(r"(?P<number>\d{4,})-(?P<title>[a-z0-9]+(?:-[a-z0-9]+)*)\.md")
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
# A row of the index with its three cells, the last one the status, in which a | is
# \|, such as | [0001](0001-a.md) | A decision | accepted |.
CELLS = re.compile(r"(?P<head>\|[^|\n]*\|(?:[^|\\\n]|\\.)*\|)(?:[^|\\\n]|\\.)*\|[ \t]*")
# The words of the description of a pull request that close an issue, as GitHub
# reads them, such as Fixes #12, Closes: #12, resolved owner/repo#12 or Fixes
# https://github.com/owner/repo/issues/12; the last two name the repository.
CLOSING = re.compile(
    r"\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?):?[ \t]+"
    r"(?:(?P<repository>[\w.-]+/[\w.-]+)#"
    r"|https://github\.com/(?P<link>[\w.-]+/[\w.-]+)/issues/"
    r"|#)(?P<number>\d+)\b",
    re.IGNORECASE,
)
# The ref that GitHub checks out for the CI of a pull request: its merge into the
# branch that it goes into.
MERGE_REF = re.compile(r"refs/pull/(?P<number>\d+)/merge")
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


def number_of(name: str) -> int | None:
    """The number of a record by the name of its file, or None for another file."""
    match = RECORD.fullmatch(name)
    return None if match is None else int(match["number"])


def place(name: str) -> tuple[int, int]:
    """Where a file goes in the index: a record by the value of its number, so that
    1033 follows 0181, and any other file after the records."""
    number = number_of(name)
    return (1, 0) if number is None else (0, number)


def next_free(numbers: Collection[int]) -> int:
    """The number after the last of these."""
    return max(numbers, default=0) + 1


def number_findings(records: dict[int, list[str]]) -> list[str]:
    """Each number of two records or more, and a record of 0000, the template's. The
    numbers have gaps, since a new record takes the number of its pull request."""
    found = [
        f"{number:04d} is the number of {len(files)} records: {', '.join(files)}"
        for number, files in sorted(records.items())
        if len(files) > 1
    ]
    if 0 in records:
        found.append(f"0000 is the number of the template, not of {records[0][0]}")
    return found


@dataclass(frozen=True)
class PullRequest:
    """The pull request whose merge the CI checks out: its number, the numbers of the
    records of main, or None when the checkout lacks main, and the issues that its
    description closes."""

    number: int
    main: frozenset[int] | None
    issues: frozenset[int]


def pull_request_of(
    root: Path, environment: Mapping[str, str], folder: str = FOLDER
) -> PullRequest | None:
    """The pull request of a run of the CI, from the variables of the runner, or None
    outside one, such as on your machine. The records of main are those of the first
    parent of the merge that GitHub made for the pull request, which a checkout of
    fetch-depth 2 holds, as the checks of every kind of project make it; where the
    checkout is another commit, such as the head of the pull request, or lacks that
    parent, main is None."""
    ref = MERGE_REF.fullmatch(environment.get("GITHUB_REF", ""))
    if ref is None:
        return None
    number = int(ref["number"])
    head = subprocess.run(
        ["git", "rev-parse", "--verify", "--quiet", "HEAD"],
        cwd=root,
        capture_output=True,
        encoding="utf-8",
        check=False,
    ).stdout.strip()
    main = None
    if head and head == environment.get("GITHUB_SHA"):
        main = numbers_of(root, "HEAD^1", folder)
    description = description_of(environment.get("GITHUB_EVENT_PATH", ""), number)
    repository = environment.get("GITHUB_REPOSITORY", "")
    return PullRequest(number, main, frozenset(closed_issues(description, repository)))


def numbers_of(root: Path, commit: str, folder: str = FOLDER) -> frozenset[int] | None:
    """The numbers of the records in the folder of a commit, or None when the checkout
    lacks the commit, such as the parent of the merge in a clone of depth 1."""
    listed = subprocess.run(
        ["git", "ls-tree", "-z", "--name-only", commit, "--", f"{folder}/"],
        cwd=root,
        capture_output=True,
        check=False,
    )
    if listed.returncode != 0:
        return None
    prefix = f"{folder}/"
    names = (
        path.decode("utf-8", "surrogateescape").removeprefix(prefix)
        for path in listed.stdout.split(b"\0")
    )
    return frozenset(
        number
        for name in names
        if name != TEMPLATE and (number := number_of(name)) is not None
    )


def description_of(event: str, number: int) -> str:
    """The description of the pull request in the event that started the run, or
    nothing when the event is of another one or can't be read."""
    try:
        payload = json.loads(Path(event).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return ""
    pull = payload.get("pull_request") if isinstance(payload, dict) else None
    if not isinstance(pull, dict) or pull.get("number") != number:
        return ""
    body = pull.get("body")
    return body if isinstance(body, str) else ""


def closed_issues(description: str, repository: str = "") -> set[int]:
    """The issues of the repository, such as owner/repo, that the description of a
    pull request closes: 12 of Fixes #12, of Fixes owner/repo#12 and of Fixes
    https://github.com/owner/repo/issues/12; an issue of another repository is none
    of them."""
    return {
        int(match["number"])
        for match in CLOSING.finditer(description)
        if (match["repository"] or match["link"] or repository).casefold()
        == repository.casefold()
    }


def own_number(main: Collection[int], pull: int) -> int:
    """The number of a new record of a pull request: its own, or, where a record of
    main has it, as in a young repository whose pull request #1 meets the template's
    0001, the next one above the records of main, so that the pull request is never
    stuck."""
    return next_free(main) if pull in main else pull


def pull_request_findings(
    records: dict[int, list[str]], pull: PullRequest
) -> list[str]:
    """Each record that main doesn't have and whose number is neither that of its pull
    request nor that of an issue that the pull request closes; nothing when the
    checkout lacks main. A record of main that the pull request renames keeps its
    number, and two new records of one number are a clash, which number_findings
    names."""
    if pull.main is None:
        return []
    main = pull.main
    own = own_number(main, pull.number)
    issues = sorted(issue for issue in pull.issues if issue not in main)
    wanted = f"the number {own:04d}"
    if own != pull.number:
        wanted += (
            ", the next one above the records of main, since one of them has "
            f"{pull.number:04d}"
        )
    if issues:
        named = " or ".join(f"{issue:04d}" for issue in issues)
        wanted += f", or that of an issue that it fixes, {named}"
    allowed = {own, *issues}
    return [
        f"{file} of pull request #{pull.number} takes {wanted}"
        for number, files in sorted(records.items())
        if number not in main and number not in allowed
        for file in files
    ]


def index_findings(names: Iterable[str], index: str) -> list[str]:
    """Each record that the index lists twice, names by another number or lists out of
    order, and each file that it names but the folder doesn't have. A record that it
    misses is none: the release writes its row."""
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
    # By the values of the numbers alone: two records of one number are a clash, which
    # the numbers name, not a row out of order.
    order = [file for _, file in rows]
    found += [
        f"the index lists {later} after {earlier}"
        for earlier, later in pairwise(order)
        if place(later) < place(earlier)
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
    number, words = parts_of(name)
    heading = HEADING.search(text)
    if heading:
        title = heading["title"]
    else:
        words = words.replace("-", " ")
        title = words[:1].upper() + words[1:]
    status = STATUS.search(text)
    status_cell = cell(status["status"]) if status else ""
    return f"| [{number}]({name}) | {cell(title)} | {status_cell} |\n"


def parts_of(name: str) -> tuple[str, str]:
    """The number of a record as its name writes it, and the words of its title."""
    record = RECORD.fullmatch(name)
    assert record is not None, f"{name} is no record"
    return record["number"], record["title"]


def renamed(line: str, row: re.Match[str], file: str) -> str:
    """A row of the index that names this record, by its number."""
    return (
        line[: row.start("number")]
        + parts_of(file)[0]
        + line[row.end("number") : row.start("file")]
        + file
        + line[row.end("file") :]
    )


def restated(line: str, text: str) -> str:
    """A row of the index with the status of its record, from the text of the record,
    where the record has one; a row of other cells stays as it is."""
    status = STATUS.search(text)
    body = line.rstrip("\r\n")
    cells = CELLS.fullmatch(body)
    if status is None or cells is None:
        return line
    return f"{cells['head']} {cell(status['status'])} |{line[len(body) :]}"


def fixed(
    index: str,
    names: Iterable[str],
    read: Callable[[str], str],
    *,
    complete: bool = False,
) -> str:
    """The index with what only it gets wrong fixed: each row names its record by the
    number of its file, a row of a file that is gone names the one record of the same
    title that no row names, a second copy of a row is gone, and the rows are in the
    order of the numbers. Two records of one number stay as they are. Complete, as the
    release writes it and the website shows it, the index also has a row of each
    record that it misses, from the text that read gives for its name, and each row
    the status of its record, so that a pull request that amends a record changes
    only the record."""
    records, _ = numbered(names)
    recorded = {file for files in records.values() for file in files}
    lines = index.splitlines(keepends=True)
    rows = [(at, row) for at, line in enumerate(lines) if (row := ROW.match(line))]
    named = {row["file"] for _, row in rows}
    # The records that no row names, by their titles, for the rows of renamed files.
    unnamed: dict[str, list[str]] = defaultdict(list)
    for file in sorted(recorded - named):
        unnamed[parts_of(file)[1]].append(file)
    kept: list[tuple[int, str, str]] = []
    seen = set()
    for at, row in rows:
        file = row["file"]
        gone = RECORD.fullmatch(file)
        if file not in recorded and gone and len(unnamed[gone["title"]]) == 1:
            file = unnamed[gone["title"]].pop()
        line = renamed(lines[at], row, file) if file in recorded else lines[at]
        if complete and file in recorded:
            line = restated(line, read(file))
        if line not in seen:
            seen.add(line)
            kept.append((at, line, file))
    listed = {file for _, _, file in kept}
    missing = (
        [(row_of(file, read(file)), file) for file in sorted(recorded - listed)]
        if complete
        else []
    )
    # A stable order by the values of the numbers, so that two rows of one number keep
    # theirs; the last line of the index may lack its line break, and moves.
    entries = [
        ended(line)
        for line, _ in sorted(
            [(line, file) for _, line, file in kept] + missing,
            key=lambda entry: place(entry[1]),
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
