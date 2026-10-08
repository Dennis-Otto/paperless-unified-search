"""The changelog of the releases, and the notes of each."""

from __future__ import annotations

import re
from dataclasses import dataclass

# Pull requests write what changes for users under "## Unreleased" of CHANGELOG.md.
# release-please decides the version from their titles and writes a section of them;
# the release bot puts the text of Unreleased in its place, and the release notes
# show that text with the list of the pull requests below it.

HEADING = re.compile(r"^## .*$", re.MULTILINE)
UNRELEASED = re.compile(r"^## \[?unreleased\]?\s*$", re.IGNORECASE)
COMPARE = re.compile(r"\((https://\S+/compare/\S+?)\)")
# Plain Markdown, which also the update dialogs of Home Assistant and the like show.
PULL_REQUESTS = "## Every pull request of this release"


def block(text: str) -> str:
    """The body of a section: the text after a blank line, or nothing."""
    text = text.strip("\n")
    return f"\n\n{text}\n\n" if text.strip() else "\n\n"


SUBHEADING = re.compile(r"^### .*$", re.MULTILINE)
LIST_LINE = re.compile(r"^(?:\s*(?:[-*+]|\d+\.)\s|\s{2,}\S)")


def merge_headings(text: str) -> str:
    """The text with each kind of change under one heading: when two pull requests
    each start a "### Features", the entries of the second join those of the first."""
    headings = list(SUBHEADING.finditer(text))
    if len(headings) == len({match.group().strip() for match in headings}):
        return text
    ends = [match.start() for match in headings[1:]] + [len(text)]
    bodies: dict[str, list[str]] = {}
    for match, end in zip(headings, ends, strict=True):
        body = text[match.end() : end].strip("\n")
        chunks = bodies.setdefault(match.group().strip(), [])
        if body.strip():
            chunks.append(body)
    parts = [text[: headings[0].start()].strip("\n")]
    for heading, chunks in bodies.items():
        # Entries of a list stay one list; other text keeps its paragraphs.
        lines = [
            line for chunk in chunks for line in chunk.splitlines() if line.strip()
        ]
        joint = "\n" if all(LIST_LINE.match(line) for line in lines) else "\n\n"
        parts.append(f"{heading}\n\n{joint.join(chunks)}" if chunks else heading)
    return "\n\n".join(part for part in parts if part) + "\n"


@dataclass
class Changelog:
    """A changelog as its preamble and its sections, each a heading and its body."""

    preamble: str
    sections: list[tuple[str, str]]

    @classmethod
    def parse(cls, text: str) -> Changelog:
        headings = list(HEADING.finditer(text))
        if not headings:
            return cls(text, [])
        ends = [match.start() for match in headings[1:]] + [len(text)]
        sections = [
            (match.group(), text[match.end() : end])
            for match, end in zip(headings, ends, strict=True)
        ]
        return cls(text[: headings[0].start()], sections)

    def find(self, pattern: re.Pattern[str]) -> int | None:
        return next(
            (
                index
                for index, (heading, _) in enumerate(self.sections)
                if pattern.match(heading)
            ),
            None,
        )

    def render(self) -> str:
        text = self.preamble.rstrip("\n") + "\n\n" if self.preamble.strip() else ""
        text += "".join(heading + body for heading, body in self.sections)
        return text.rstrip("\n") + "\n"


def version_heading(version: str) -> re.Pattern[str]:
    return re.compile(rf"^## \[?v?{re.escape(version)}\]?(?=[\s(]|$)")


def release_changelog(version: str, main: str, branch: str) -> str:
    """The changelog of the release pull request for the version: the changelog of main,
    with the text of Unreleased moved into the section that release-please wrote on the
    branch. Without that text, the section lists the pull requests. A prerelease keeps
    the text under Unreleased for the release that follows it."""
    written = Changelog.parse(branch)
    found = written.find(version_heading(version))
    if found is None:
        return branch
    heading, generated = written.sections[found]
    changelog = Changelog.parse(main)
    unreleased = changelog.find(UNRELEASED)
    text = (
        "" if unreleased is None else merge_headings(changelog.sections[unreleased][1])
    )
    keep = "-" in version or not text.strip()
    others = [
        section
        for section in changelog.sections
        if not UNRELEASED.match(section[0])
        and not version_heading(version).match(section[0])
    ]
    changelog.sections = [
        ("## Unreleased", block(text) if keep else block("")),
        (heading, block(generated if keep else text)),
        *others,
    ]
    return changelog.render()


def release_notes(version: str, changelog: str, generated: str) -> str:
    """The notes of the release: its section of the changelog, followed by the list of
    its pull requests that release-please wrote, or only that list. Notes that have
    both already stay as they are."""
    log = Changelog.parse(changelog)
    found = log.find(version_heading(version))
    listed = Changelog.parse(generated)
    pulls = listed.sections[0][1] if listed.sections else generated
    if (
        found is None
        or PULL_REQUESTS in generated
        or log.sections[found][1].split() == pulls.split()
    ):
        return generated.rstrip("\n") + "\n"
    compare = COMPARE.search(listed.sections[0][0]) if listed.sections else None
    lines = [
        log.sections[found][1].strip("\n"),
        "",
        PULL_REQUESTS,
        "",
        pulls.strip("\n"),
    ]
    if compare:
        lines += ["", f"[Compare with the previous release]({compare.group(1)})"]
    return "\n".join([*lines, ""])


def beta_notes(version: str, changelog: str) -> str:
    """The notes of a beta of the next release: what main holds, from the text of
    Unreleased, for the testers who take prereleases."""
    release = version.split("-", 1)[0]
    log = Changelog.parse(changelog)
    found = log.find(UNRELEASED)
    text = "" if found is None else merge_headings(log.sections[found][1]).strip("\n")
    lines = [
        f"A beta of the next release, {release}, with what `main` holds now, for the "
        "testers who take prereleases. The release itself follows when it is ready.",
        "",
        text or "The changes of this beta are not described yet.",
    ]
    return "\n".join([*lines, ""])


def add_unreleased(changelog: str, heading: str, entry: str) -> str:
    """The changelog with the entry under its heading in Unreleased; both are added
    when they are missing, and an entry that is there already stays once."""
    log = Changelog.parse(changelog)
    found = log.find(UNRELEASED)
    if found is None:
        log.sections.insert(0, ("## Unreleased", block("")))
        found = 0
    lines = log.sections[found][1].strip("\n").split("\n")
    if entry in lines:
        return changelog
    title = f"### {heading}"
    if title not in lines:
        lines += ["", title]
    start = lines.index(title) + 1
    end = start
    while end < len(lines) and not lines[end].startswith("### "):
        end += 1
    while end > start and not lines[end - 1].strip():
        end -= 1
    lines[end:end] = [entry] if end > start else ["", entry]
    log.sections[found] = (log.sections[found][0], block("\n".join(lines)))
    return log.render()
