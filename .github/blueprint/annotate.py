"""The findings of the tools of scripts/check.sh as annotations of GitHub, which the
files of a pull request show at their lines and the PR coach lists in its comment.

Ruff, Psalm and Biome write their annotations in the CI on their own, and the compiler
of TypeScript through the problem matcher of actions/setup-node. For the other tools,
`blueprint.py annotate TOOL` reads what the tool wrote: ShellCheck in the format of gcc,
mypy with one line for each finding, the diffs of PHP CS Fixer and the tests that fail
in the spec reporter of Node, which it passes on as they are, and the reports of JUnit
of pytest and of PHPUnit. After that, it writes a workflow command for each finding.

What a tool writes comes from the code of a pull request, also of one from a fork. A
finding names a file only when it is a file of the repository, outside the folders of
the packages such as vendor/ and node_modules/, and every message and property is
escaped, so that no text of the tool ends its command early or starts another one.
"""

from __future__ import annotations

import re
import sys
from collections.abc import Callable, Iterable, Iterator
from dataclasses import dataclass, replace
from pathlib import Path
from typing import BinaryIO
from urllib.parse import unquote, urlparse
from urllib.request import url2pathname
from xml.etree import ElementTree

from blueprint.coach import escape_data, escape_property

# The folders of packages that the tools install: a finding there is no line of the
# project.
PACKAGES = frozenset({"vendor", "node_modules", "site-packages", ".venv", "venv"})
# A message is cut at so many characters, and a change of PHP CS Fixer shows so many
# of the lines that it writes.
MESSAGE_LENGTH = 2000
ADDED_LINES = 20
# The colors of a terminal, also as pytest writes them into its report of JUnit.
ANSI = re.compile(r"\x1b\[[0-9;?]*[A-Za-z]|#x1B\[[0-9;]*m")
SHELLCHECK = re.compile(
    r"(?P<path>.+?):(?P<line>\d+):(?P<column>\d+): "
    r"(?P<level>error|warning|note|info|style): (?P<message>.*?)"
    r"(?: \[SC(?P<code>\d+)\])?"
)
MYPY = re.compile(
    r"(?P<path>.+?):(?P<line>\d+):(?:(?P<column>\d+):"
    r"(?:(?P<end_line>\d+):(?P<end_column>\d+):)?)? "
    r"(?P<level>error|note): (?P<message>.*?)(?:  \[(?P<code>[a-z0-9-]+)\])?"
)
FIXED_FILE = re.compile(r"\s*\d+\) (?P<path>.+?)(?: \((?P<fixers>[a-z0-9_, ]+)\))?")
HUNK = re.compile(
    r"@@ -(?P<old>\d+)(?:,(?P<old_count>\d+))? \+\d+(?:,(?P<new>\d+))? @@.*"
)
FAILING_TESTS = re.compile(r"\S* ?failing tests:")
TEST_AT = re.compile(r"test at (?P<path>.+):(?P<line>\d+):(?P<column>\d+)")
TEST_NAME = re.compile(r"\S+ (?P<name>.+?)(?: \([\d.]+m?s\))?")
FRAME = re.compile(
    r"\s+at (?:.*\()?(?P<url>file://.+?):(?P<line>\d+):(?P<column>\d+)\)?"
)
# A line of the error of a test, which the reporter indents, before its stack.
INDENTED = re.compile(r"(?:  (?!\s*at ).*)?")
TRACE = re.compile(r"(?P<path>(?:[A-Za-z]:)?[^:\s][^:]*):(?P<line>\d+)(?::.*)?")


@dataclass(frozen=True)
class Annotation:
    """An error at a place of a file of the repository, or of none."""

    title: str
    message: str
    path: str = ""
    line: int = 0
    column: int = 0
    end_line: int = 0
    end_column: int = 0

    def command(self) -> str:
        """The workflow command that makes the annotation."""
        place = [
            ("file", self.path),
            ("line", self.line if self.path else 0),
            ("endLine", self.end_line if self.path and self.line else 0),
            ("col", self.column if self.path and self.line else 0),
            ("endColumn", self.end_column if self.path and self.column else 0),
        ]
        properties = [
            f"{key}={escape_property(str(value))}" for key, value in place if value
        ]
        properties.append(f"title={escape_property(self.title)}")
        return f"::error {','.join(properties)}::{escape_data(cut(self.message))}"


def cut(text: str) -> str:
    text = ANSI.sub("", text).strip()
    return text if len(text) <= MESSAGE_LENGTH else text[: MESSAGE_LENGTH - 1] + "…"


def located(raw: str, root: Path) -> str:
    """The path of a file of the repository from root, as GitHub names it, or "" for
    one that is no file of the repository, or one of a folder of packages."""
    try:
        raw = raw.replace("\\", "/")
        path = Path(raw) if Path(raw).is_absolute() else root / raw
        found = path.resolve()
        inside = found.relative_to(root.resolve())
        if found.is_file() and not PACKAGES & set(inside.parts):
            return inside.as_posix()
    except (OSError, ValueError):
        pass
    return ""


def number(text: str | None) -> int:
    return int(text) if text else 0


# ---------------------------------------------------------------- one line each


def shellcheck(lines: Iterable[str], root: Path) -> Iterator[Annotation]:
    """ShellCheck with --format=gcc: path:line:column: level: message [SC1234]."""
    for line in lines:
        found = SHELLCHECK.fullmatch(line)
        if not found:
            continue
        code = found["code"]
        rule = f" https://www.shellcheck.net/wiki/SC{code}" if code else ""
        yield Annotation(
            title=f"ShellCheck SC{code}" if code else "ShellCheck",
            message=f"{found['message']}{rule}",
            path=located(found["path"], root),
            line=int(found["line"]),
            column=int(found["column"]),
        )


def mypy(lines: Iterable[str], root: Path) -> Iterator[Annotation]:
    """mypy with --no-pretty and --show-error-end, each finding on one line:
    path:line:column:end_line:end_column: error: message  [code]. A note right after
    an error and at its line belongs to it, such as the variants of an overload."""
    last: Annotation | None = None
    for line in lines:
        found = MYPY.fullmatch(line)
        if not found:
            continue
        path = located(found["path"], root)
        if found["level"] == "note":
            if last is not None and (last.path, last.line) == (
                path,
                int(found["line"]),
            ):
                last = replace(last, message=f"{last.message}\n{found['message']}")
            continue
        if last is not None:
            yield last
        code = found["code"]
        last = Annotation(
            title=f"Mypy ({code})" if code else "Mypy",
            message=found["message"],
            path=path,
            line=int(found["line"]),
            column=number(found["column"]),
            end_line=number(found["end_line"]),
            end_column=number(found["end_column"]),
        )
    if last is not None:
        yield last


# ---------------------------------------------------------------- diffs


@dataclass
class Change:
    """The lines of the head that a diff removes or before which it adds lines."""

    first: int
    last: int
    added: list[str]


def changes(
    hunk: Iterator[str], old: int, old_count: int, new_count: int
) -> list[Change]:
    """Each run of lines that a hunk of a unified diff removes or adds, at the lines
    of the old side, which are those of the head."""
    found: list[Change] = []
    current: Change | None = None
    while old_count > 0 or new_count > 0:
        line = next(hunk, None)
        if line is None:
            break
        mark, text = line[:1], line[1:]
        if mark == "-":
            current = current or Change(old, old, [])
            current.last = old
            old, old_count = old + 1, old_count - 1
        elif mark == "+":
            current = current or Change(max(old - 1, 1), max(old - 1, 1), [])
            current.added.append(text)
            new_count -= 1
        elif mark == "\\":
            continue
        else:
            if current is not None:
                found.append(current)
                current = None
            old, old_count, new_count = old + 1, old_count - 1, new_count - 1
    if current is not None:
        found.append(current)
    return found


def php_cs_fixer(lines: Iterable[str], root: Path) -> Iterator[Annotation]:
    """php-cs-fixer fix --dry-run --diff: each file as `   1) path`, then the diff of
    the coding standard, with one annotation for each run of lines that it changes."""
    stream = iter(lines)
    path = ""
    fixers = ""
    for line in stream:
        named = FIXED_FILE.fullmatch(line)
        if named:
            path = located(named["path"], root)
            fixers = named["fixers"] or ""
            continue
        hunk = HUNK.fullmatch(line)
        if not hunk or not path:
            continue
        found = changes(
            stream,
            int(hunk["old"]),
            int(hunk["old_count"] or 1),
            int(hunk["new"] or 1),
        )
        for change in found:
            yield fixed(change, path, fixers)


def fixed(change: Change, path: str, fixers: str) -> Annotation:
    if change.added:
        shown = change.added[:ADDED_LINES]
        more = len(change.added) - len(shown)
        message = "\n".join(
            ["The coding standard writes these lines as:", *shown]
            + ([f"… and {more} more lines"] if more > 0 else [])
        )
    else:
        message = "The coding standard removes these lines."
    return Annotation(
        title=f"PHP CS Fixer ({fixers})" if fixers else "PHP CS Fixer",
        message=f"{message}\nvendor/bin/php-cs-fixer fix makes the change.",
        path=path,
        line=change.first,
        end_line=change.last,
    )


# ---------------------------------------------------------------- tests


def frame_path(url: str) -> str:
    """The path of a file:// address of a frame of a stack."""
    return url2pathname(unquote(urlparse(url).path))


def node_test(lines: Iterable[str], root: Path) -> Iterator[Annotation]:
    """The failing tests that the spec reporter of the test runner of Node lists at the
    end: `test at path:line:column`, the name of the test, then its error and stack. The
    annotation is at the first frame of the stack in a file of the repository, or else
    where the test starts."""
    stream = iter(lines)
    for line in stream:
        if FAILING_TESTS.fullmatch(line):
            break
    block: list[str] = []
    for line in stream:
        if TEST_AT.fullmatch(line) and block:
            yield failed_test(block, root)
            block = []
        if block or TEST_AT.fullmatch(line):
            block.append(line)
    if block:
        yield failed_test(block, root)


def failed_test(block: list[str], root: Path) -> Annotation:
    """A test of the list: its error up to the stack, which the reporter indents, and
    the first frame of the stack in a file of the repository."""
    start = TEST_AT.fullmatch(block[0])
    assert start is not None
    named = TEST_NAME.fullmatch(block[1]) if len(block) > 1 else None
    body = block[2:]
    end = next(
        (index for index, line in enumerate(body) if not INDENTED.fullmatch(line)),
        len(body),
    )
    place = (located(start["path"], root), int(start["line"]), int(start["column"]))
    for line in body[end:]:
        frame = FRAME.fullmatch(line)
        path = located(frame_path(frame["url"]), root) if frame else ""
        if frame and path:
            place = (path, int(frame["line"]), int(frame["column"]))
            break
    message = "\n".join(line.removeprefix("  ") for line in body[:end]).strip()
    return Annotation(
        title=f"Node test: {named['name']}" if named else "Node test",
        message=message or "The test failed.",
        path=place[0],
        line=place[1] if place[0] else 0,
        column=place[2] if place[0] else 0,
    )


@dataclass(frozen=True)
class JUnit:
    """What a report of JUnit of one test framework says, and how."""

    label: str
    # The trace lists the place where the error happened first, as PHPUnit does, or
    # last, as pytest does.
    innermost_first: bool
    # pytest counts the line of a test from 0 in the format xunit1.
    first_line: int
    message: Callable[[ElementTree.Element, ElementTree.Element], str]
    name: Callable[[ElementTree.Element], str]


def pytest_name(case: ElementTree.Element) -> str:
    """The test as pytest names it, such as tests/test_x.py::TestClass::test_y, from
    its file and the dotted name of its module and class; without its file, by the
    dotted name."""
    path = case.get("file", "").replace("\\", "/")
    owner = case.get("classname", "")
    if path:
        module = path.removesuffix(".py").replace("/", ".")
        classes = owner.removeprefix(module).strip(".").split(".")
        owner = "::".join(part for part in (path, *classes) if part)
    return "::".join(part for part in (owner, case.get("name", "")) if part)


def phpunit_name(case: ElementTree.Element) -> str:
    """The test as PHPUnit names it, with the class without its namespace."""
    owner = (case.get("class") or "").rsplit("\\", 1)[-1]
    return "::".join(part for part in (owner, case.get("name", "")) if part)


def pytest_message(case: ElementTree.Element, problem: ElementTree.Element) -> str:
    del case
    return problem.get("message") or (problem.text or "").strip()


def phpunit_message(case: ElementTree.Element, problem: ElementTree.Element) -> str:
    """The text of a failure without the name of the test, which it starts with, and
    without the lines of the trace."""
    lines = (problem.text or "").strip().splitlines()
    name = f"{case.get('class', '')}::{case.get('name', '')}"
    if lines and lines[0] == name:
        lines = lines[1:]
    return "\n".join(line for line in lines if not TRACE.fullmatch(line)).strip()


PYTEST = JUnit("pytest", False, 0, pytest_message, pytest_name)
PHPUNIT = JUnit("PHPUnit", True, 1, phpunit_message, phpunit_name)


def junit(report: str, root: Path, kind: JUnit) -> Iterator[Annotation]:
    """Each test of a report of JUnit that failed or broke."""
    tree = ElementTree.fromstring(report)
    for case in tree.iter("testcase"):
        for problem in (*case.findall("failure"), *case.findall("error")):
            yield failed_case(case, problem, root, kind)


def failed_case(
    case: ElementTree.Element,
    problem: ElementTree.Element,
    root: Path,
    kind: JUnit,
) -> Annotation:
    """The annotation of a test, at the place of the trace in the repository where
    the error happened, or else where the test starts."""
    trace = [TRACE.fullmatch(line) for line in (problem.text or "").splitlines()]
    places = [
        (path, int(found["line"]))
        for found in trace
        if found and (path := located(found["path"], root))
    ]
    if not kind.innermost_first:
        places.reverse()
    start = located(case.get("file", ""), root)
    first = case.get("line", "")
    line = int(first) + 1 - kind.first_line if start and first.isdecimal() else 0
    path, line = places[0] if places else (start, line)
    return Annotation(
        title=f"{kind.label}: {kind.name(case)}",
        message=kind.message(case, problem) or "The test failed.",
        path=path,
        line=line,
    )


# ---------------------------------------------------------------- the command


STREAMS: dict[str, Callable[[Iterable[str], Path], Iterator[Annotation]]] = {
    "mypy": mypy,
    "node-test": node_test,
    "php-cs-fixer": php_cs_fixer,
    "shellcheck": shellcheck,
}
REPORTS = {"phpunit": PHPUNIT, "pytest": PYTEST}
TOOLS = sorted([*STREAMS, *REPORTS])


def passed_on(source: BinaryIO, sink: BinaryIO) -> Iterator[str]:
    """Each line of what a tool writes, passed on as it is as soon as it comes, and as
    text without its line break, also when it isn't UTF-8."""
    for raw in source:
        sink.write(raw)
        sink.flush()
        yield raw.decode("utf-8", errors="replace").rstrip("\r\n")


def commands(found: Iterable[Annotation]) -> list[str]:
    """The workflow commands, in a group of the log, which shows the findings once."""
    lines = [annotation.command() for annotation in found]
    return ["::group::Annotations", *lines, "::endgroup::"] if lines else []


def annotations_of(
    tool: str, report: str, root: Path, source: BinaryIO, sink: BinaryIO
) -> list[str]:
    """The workflow commands of the findings of a tool: from what it wrote, which
    goes on to sink whole, or from its report of JUnit. A report that is missing or no
    XML has none: the tool failed before it wrote one, and its log says why."""
    if tool not in STREAMS:
        try:
            text = Path(report).read_text(encoding="utf-8", errors="replace")
            return commands(junit(text, root, REPORTS[tool]))
        except (OSError, ElementTree.ParseError):
            return []
    lines = passed_on(source, sink)
    try:
        found = commands(STREAMS[tool](lines, root))
    # The exit code of the step is that of the tool: no output that the parser
    # stumbles on may fail a check that passed, or hide the rest of the log.
    except Exception as error:
        print(f"blueprint.py: no annotations of {tool}: {error!r}", file=sys.stderr)
        found = []
    for _ in lines:
        pass
    return found
