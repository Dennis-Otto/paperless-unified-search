"""The comment of the coverage bot, from the coverage reports of a pull request and
of main: a Cobertura report, coverage.xml, of coverage.py and PHPUnit, or an LCOV
report, lcov.info, of the test runner of Node.

The report of a pull request is its own: one from a fork writes the names of its
files and the hits of its lines as it likes. A name reaches the comment only as code
in its cell, so that it adds no Markdown, link or mention to what the bot says, and
hits that are no count count as a number or as 0."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from xml.etree import ElementTree

from blueprint.coach import code

COVERAGE_MARKER = "<!-- coverage-bot -->"
# The table shows the first files by name, each name in at most so many characters:
# GitHub takes a comment of up to 65,536 characters.
MOST_FILES = 100
NAME_LENGTH = 200


@dataclass(frozen=True)
class Coverage:
    """The lines of each file that the tests run, from a Cobertura or an LCOV report."""

    files: dict[str, tuple[int, int]]

    @classmethod
    def parse(cls, report: str, root: Path | None = None) -> Coverage:
        """A report names each file relative to the source that holds it, such as lib/
        of PHPUnit or a folder that coverage.py measures. With one source under root,
        every path starts at root; with several, the report doesn't say which source a
        file belongs to, and the names stay as they are."""
        tree = ElementTree.fromstring(report)
        sources = [Path(source.text or "") for source in tree.iter("source")]
        folder = ""
        if root is not None and len(sources) == 1 and sources[0].is_relative_to(root):
            folder = sources[0].relative_to(root).as_posix()
        files: dict[str, tuple[int, int]] = {}
        for element in tree.iter("class"):
            name = element.get("filename", "")
            if folder not in ("", "."):
                name = f"{folder}/{name}"
            lines = element.findall("./lines/line")
            covered = sum(1 for line in lines if runs(line.get("hits", "0")))
            have = files.get(name, (0, 0))
            files[name] = (have[0] + covered, have[1] + len(lines))
        return cls(files)

    @classmethod
    def parse_lcov(cls, report: str, root: Path | None = None) -> Coverage:
        """An LCOV report, as the test runner of Node writes it: SF: names a file,
        relative to where the tests ran or as an absolute path, each DA: one of its
        lines with its hits, and end_of_record ends the file. An absolute name under
        root starts at root; any other name stays as it is."""
        files: dict[str, tuple[int, int]] = {}
        name = ""
        for line in report.splitlines():
            key, _, value = line.strip().partition(":")
            if key == "SF":
                name = value
                if root is not None and Path(name).is_relative_to(root):
                    name = Path(name).relative_to(root).as_posix()
                files.setdefault(name, (0, 0))
            elif key == "DA":
                # DA:<line>,<hits>, and a checksum after them for some tools.
                hits = value.split(",")[1] if "," in value else "0"
                covered, lines = files.get(name, (0, 0))
                files[name] = (covered + (1 if runs(hits) else 0), lines + 1)
            elif key == "end_of_record":
                name = ""
        return cls(files)

    @classmethod
    def read(cls, path: Path, root: Path | None = None) -> Coverage:
        """The report in a file, by its name: LCOV in a file that ends in .info, such
        as lcov.info, and Cobertura in any other, such as coverage.xml."""
        text = path.read_text(encoding="utf-8")
        if path.suffix == ".info":
            return cls.parse_lcov(text, root)
        return cls.parse(text, root)

    @property
    def total(self) -> tuple[int, int]:
        return (
            sum(covered for covered, _ in self.files.values()),
            sum(lines for _, lines in self.files.values()),
        )


def runs(hits: str) -> bool:
    """Whether the tests run a line that many times: a count such as 1.5 counts as a
    number, one that is no number as 0."""
    try:
        return float(hits) > 0
    except ValueError:
        return False


def cell(name: str) -> str:
    """The name of a file as code in a cell of the table, on one line: no pipe of it
    ends the cell, which GitHub looks for inside code as well."""
    return code(name, NAME_LENGTH).replace("|", "\\|") or "(unnamed file)"


def percent(covered: int, lines: int) -> float:
    return 100.0 if lines == 0 else 100 * covered / lines


def coverage_comment(head: Coverage, base: Coverage | None) -> str:
    """The comment of the coverage bot: the coverage of a pull request, compared with
    that of main, and each file whose coverage differs, the first MOST_FILES by name."""
    rows, _ = coverage_table(head, base)
    return "\n".join([COVERAGE_MARKER, "### Coverage", "", *rows, ""])


def coverage_table(head: Coverage, base: Coverage | None) -> tuple[list[str], bool]:
    """The coverage of a pull request against that of main, as the lines of Markdown
    of its tables, and whether the coverage of a file changes."""
    covered, lines = head.total
    now = percent(covered, lines)
    rows = [
        "| | Lines | Covered |",
        "| --- | ---: | ---: |",
        f"| This pull request | {lines} | {now:.2f} % |",
    ]
    if base is None:
        return [*rows, "", "main has no coverage report yet to compare with."], False
    before = percent(*base.total)
    rows.append(f"| main | {base.total[1]} | {before:.2f} % ({now - before:+.2f}) |")
    changed = []
    for name in sorted(set(head.files) | set(base.files)):
        old = base.files.get(name)
        new = head.files.get(name)
        old_text = "new" if old is None else f"{percent(*old):.2f} %"
        new_text = "removed" if new is None else f"{percent(*new):.2f} %"
        if old_text != new_text:
            changed.append(f"| {cell(name)} | {old_text} | {new_text} |")
    if not changed:
        return [*rows, "", "No file changes its coverage."], False
    rows += ["", "| File | main | This pull request |", "| --- | ---: | ---: |"]
    rows += changed[:MOST_FILES]
    if len(changed) > MOST_FILES:
        rows += [
            "",
            f"{len(changed) - MOST_FILES} more files change their coverage; the "
            "artifact coverage of the checks has the whole report.",
        ]
    return rows, True
