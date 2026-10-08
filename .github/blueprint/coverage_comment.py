"""The comment of the coverage bot, from the Cobertura reports of a pull request
and of main."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from xml.etree import ElementTree

COVERAGE_MARKER = "<!-- coverage-bot -->"


@dataclass(frozen=True)
class Coverage:
    """The lines of each file that the tests run, from a Cobertura report."""

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
            covered = sum(1 for line in lines if int(line.get("hits", "0")) > 0)
            have = files.get(name, (0, 0))
            files[name] = (have[0] + covered, have[1] + len(lines))
        return cls(files)

    @property
    def total(self) -> tuple[int, int]:
        return (
            sum(covered for covered, _ in self.files.values()),
            sum(lines for _, lines in self.files.values()),
        )


def percent(covered: int, lines: int) -> float:
    return 100.0 if lines == 0 else 100 * covered / lines


def coverage_comment(head: Coverage, base: Coverage | None) -> str:
    """The comment of the coverage bot: the coverage of a pull request, compared with
    that of main, and every file whose coverage differs."""
    covered, lines = head.total
    now = percent(covered, lines)
    rows = [
        COVERAGE_MARKER,
        "### Coverage",
        "",
        "| | Lines | Covered |",
        "| --- | ---: | ---: |",
        f"| This pull request | {lines} | {now:.2f} % |",
    ]
    if base is None:
        rows += ["", "main has no coverage report yet to compare with."]
        return "\n".join([*rows, ""])
    before = percent(*base.total)
    rows.append(f"| main | {base.total[1]} | {before:.2f} % ({now - before:+.2f}) |")
    changed = []
    for name in sorted(set(head.files) | set(base.files)):
        old = base.files.get(name)
        new = head.files.get(name)
        old_text = "new" if old is None else f"{percent(*old):.2f} %"
        new_text = "removed" if new is None else f"{percent(*new):.2f} %"
        if old_text != new_text:
            changed.append(f"| `{name}` | {old_text} | {new_text} |")
    if changed:
        rows += ["", "| File | main | This pull request |", "| --- | ---: | ---: |"]
        rows += changed
    else:
        rows += ["", "No file changes its coverage."]
    return "\n".join([*rows, ""])
