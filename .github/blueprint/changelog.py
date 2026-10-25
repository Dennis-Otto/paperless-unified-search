"""The changelog of the releases, and the notes of each."""

from __future__ import annotations

import codecs
import difflib
import re
from collections.abc import Sequence
from dataclasses import dataclass
from pathlib import Path

# Pull requests write what changes for users in a fragment, a file of their own in
# changelog.d/, or under "## Unreleased" of CHANGELOG.md. release-please decides the
# version from their titles and writes a section of them; the release bot puts the
# text of Unreleased and of the fragments in its place and deletes the fragments, and
# the release notes show that text with the list of the pull requests below it. A new
# file conflicts with nothing, so pull requests with fragments merge one after another
# without a conflict, with each other and with the release.

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
# A heading above the kinds of change, such as the title of the changelog or that of a
# release, with up to three spaces before it, as Markdown takes it.
TOP_HEADING = re.compile(r"^ {0,3}#{1,2}(?:[ \t].*)?$", re.MULTILINE)
LIST_LINE = re.compile(r"^(?:\s*(?:[-*+]|\d+\.)\s|\s{2,}\S)")


def kind_of(heading: re.Match[str]) -> str:
    """A heading of a kind of change as the release writes it: "### " and its words,
    however many spaces stand around them, so that "###  Features" is "### Features"."""
    return f"### {heading.group()[4:].strip()}"


def merge_headings(text: str) -> str:
    """The text with each kind of change under one heading: when two pull requests
    each start a "### Features", the entries of the second join those of the first."""
    headings = list(SUBHEADING.finditer(text))
    if len(headings) == len({kind_of(match) for match in headings}):
        return text
    ends = [match.start() for match in headings[1:]] + [len(text)]
    bodies: dict[str, list[str]] = {}
    for match, end in zip(headings, ends, strict=True):
        body = text[match.end() : end].strip("\n")
        chunks = bodies.setdefault(kind_of(match), [])
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


# The folder of the fragments, by its path from the root of the repository.
FRAGMENTS = "changelog.d"
# A fragment is a Markdown file named with letters, digits, dots, dashes and
# underscores, such as changelog.d/dark-mode.md; a hidden file, such as .gitkeep, is
# none.
FRAGMENT_NAME = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*\.md$")
# The kinds of change, in the order in which a release lists those that only the
# fragments bring: the headings of the blueprint's release-please and those of Keep a
# Changelog. A fragment may also take any heading that CHANGELOG.md has already.
KINDS = (
    "Breaking changes",
    "Features",
    "Added",
    "Changes",
    "Changed",
    "Deprecated",
    "Removed",
    "Bug fixes",
    "Fixed",
    "Performance",
    "Security",
)


@dataclass(frozen=True)
class Fragment:
    """A fragment of the changelog: its path, such as changelog.d/dark-mode.md, and its
    text, the entries of one pull request under the headings of their kinds."""

    path: str
    text: str
    # False for a file that is no text in UTF-8, which the check names.
    readable: bool = True


class FragmentError(ValueError):
    """Fragments that a release can't take in; each problem names its fragment."""

    def __init__(self, problems: Sequence[str]) -> None:
        super().__init__("\n".join(problems))
        self.problems = list(problems)


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


def read_fragments(folder: Path) -> list[Fragment]:
    """The fragments in the folder, by their names; none without the folder. Every
    entry but a hidden file counts, so that a wrong name is reported, not left out."""
    if not folder.is_dir():
        return []
    return [
        read_fragment(entry, f"{folder.name}/{entry.name}")
        for entry in sorted(folder.iterdir(), key=lambda entry: entry.name)
        if not entry.name.startswith(".")
    ]


def read_fragment(entry: Path, path: str) -> Fragment:
    """A fragment as text in UTF-8, with or without the byte order mark that some
    editors of Windows write first; a folder has no text."""
    if not entry.is_file():
        return Fragment(path, "")
    try:
        return Fragment(path, entry.read_text(encoding="utf-8-sig"))
    except UnicodeDecodeError:
        return Fragment(path, "", readable=False)


def known_headings(changelog: str) -> list[str]:
    """The headings that a fragment may use: the kinds of change, then every other
    heading of a kind that the changelog has already."""
    used = [match.group()[4:].strip() for match in SUBHEADING.finditer(changelog)]
    return list(dict.fromkeys([*KINDS, *used]))


def shown(text: str) -> str:
    """Text of a fragment in a message: as code, on one line, and short."""
    text = " ".join(text.replace("`", "'").split())
    return f"`{text[:60].rstrip()}…`" if len(text) > 60 else f"`{text}`"


# How close a heading is to a kind of change at least, by the ratio of difflib, to be
# taken for a typo of it: the cutoff of difflib.get_close_matches.
CLOSE = 0.6
# How close a heading is to a kind of change at least for the fix to rename it to that
# kind: much closer than a hint needs, so that `Improved` never turns into Removed,
# `Removed features` into Features, or `Added and changed` into Breaking changes.
HEAL = 0.8
# A line of a fragment that may be meant as the heading of a kind of change: one, as
# the release reads it, or one that would start a section of the changelog of its own.
HEADING_LINE = re.compile(rf"{SUBHEADING.pattern}|{TOP_HEADING.pattern}", re.MULTILINE)


def close_kinds(heading: str, known: Sequence[str]) -> list[tuple[float, str]]:
    """The kinds of change that a heading is close to, each with how close, without
    regard to case, as difflib.get_close_matches finds them: the closest first, and
    those that are as close as each other in the order of known."""
    folded = {kind.casefold(): kind for kind in known}
    matcher = difflib.SequenceMatcher(b=heading.casefold())
    close = []
    for key, kind in folded.items():
        matcher.set_seq1(key)
        ratio = matcher.ratio()
        if ratio >= CLOSE:
            close.append((ratio, kind))
    return sorted(close, key=lambda pair: pair[0], reverse=True)


def meant_kind(heading: str, known: Sequence[str]) -> str | None:
    """The kind of change that a heading surely means: the closest of the kinds that
    are at least as close as HEAL, when no other is as close, such as Fixed for
    `Fixes`; none for `Change`, as close to Changes as to Changed, and none for a
    heading such as `Improved` that is only somewhat close to a kind (Removed)."""
    close = [pair for pair in close_kinds(heading, known) if pair[0] >= HEAL]
    if close and (len(close) == 1 or close[0][0] > close[1][0]):
        return close[0][1]
    return None


def healed(text: str, known: Sequence[str]) -> tuple[str, list[tuple[str, str]]]:
    """The text of a fragment with each heading that surely means a kind of change
    written as the release reads it, and each heading that it renamed, before and
    after: a typo, such as `### Fixes` for `### Fixed` or `### bug fixes`, and the
    heading of a kind that would start a section of its own, such as `## Features`.
    A heading that means no kind for sure, such as `### Change` or `## 1.4.0`, stays
    for a person, and so does every other line, with its line ending."""
    renamed: list[tuple[str, str]] = []

    def rename(match: re.Match[str]) -> str:
        line = match.group()
        words = line.strip().lstrip("#").strip()
        if line.startswith("### ") and words in known:
            return line
        kind = words if words in known else meant_kind(words, known)
        if kind is None:
            return line
        renamed.append((line.strip(), f"### {kind}"))
        return f"### {kind}" + ("\r" if line.endswith("\r") else "")

    return HEADING_LINE.sub(rename, text), renamed


def heal(folder: Path, changelog: str) -> list[str]:
    """Renames each heading of the fragments in the folder that surely means a kind of
    change of the changelog to that kind, as `healed` does, and says what it renamed.
    A fragment keeps its byte order mark and its line endings."""
    known = known_headings(changelog)
    said = []
    for fragment in read_fragments(folder):
        # A folder, a fragment that says nothing and one that is no UTF-8 have no text.
        if not fragment.text:
            continue
        path = folder / fragment.path.rsplit("/", 1)[-1]
        raw = path.read_bytes()
        mark = codecs.BOM_UTF8 if raw.startswith(codecs.BOM_UTF8) else b""
        text, renamed = healed(raw[len(mark) :].decode("utf-8"), known)
        if renamed:
            path.write_bytes(mark + text.encode("utf-8"))
        said += [
            f"{fragment.path}: renamed `{before}` to `{after}`."
            for before, after in renamed
        ]
    return said


def fragment_problems(fragments: Sequence[Fragment], changelog: str) -> list[str]:
    """What keeps each fragment from the release, each with its fix: a wrong name, no
    entry, text outside a heading, a heading that would start a section of its own,
    and a heading that is no kind of change of the changelog."""
    known = known_headings(changelog)
    problems = []
    for fragment in fragments:
        name = fragment.path.rsplit("/", 1)[-1]
        if not FRAGMENT_NAME.match(name):
            problems.append(
                f"{fragment.path}: is no fragment. Name a fragment with letters, "
                "digits, dots, dashes and underscores, ending in .md, such as "
                f"{FRAGMENTS}/dark-mode.md."
            )
        elif not fragment.readable:
            problems.append(
                f"{fragment.path}: is no text in UTF-8. Save it as UTF-8, the encoding "
                "of the changelog."
            )
        elif not fragment.text.strip():
            problems.append(
                f"{fragment.path}: says nothing. Write what changes for users below a "
                "heading such as `### Features` or `### Bug fixes`."
            )
        else:
            problems += [
                f"{fragment.path}: {problem}"
                for problem in problems_of(fragment.text, known)
            ]
    return problems


def problems_of(text: str, known: list[str]) -> list[str]:
    """The problems of the text of a fragment, without its name."""
    problems = []
    headings = list(SUBHEADING.finditer(text))
    before = text[: headings[0].start()] if headings else text
    if before.strip():
        problems.append(
            f"starts with {shown(before)} outside a heading. Put every entry below a "
            "heading such as `### Features` or `### Bug fixes`."
        )
    problems += [
        f"has the heading {shown(match.group())}, which would start a section of the "
        "changelog of its own. A fragment has only headings of kinds of change, such "
        "as `### Features`."
        for match in TOP_HEADING.finditer(text)
    ]
    ends = [match.start() for match in headings[1:]] + [len(text)]
    for match, end in zip(headings, ends, strict=True):
        heading = match.group()[4:].strip()
        if heading not in known:
            close = close_kinds(heading, known)
            hint = f"Did you mean `### {close[0][1]}`? " if close else ""
            problems.append(
                f"{shown(match.group())} is no kind of change of the changelog. "
                f"{hint}The kinds: {', '.join(known)}."
            )
        elif not text[match.end() : end].strip():
            problems.append(
                f"{shown(match.group())} has no entry. Write one below it, or remove "
                "the heading."
            )
    return problems


def checked(fragments: Sequence[Fragment], changelog: str) -> Sequence[Fragment]:
    """The fragments, once nothing keeps them from a release of the changelog."""
    problems = fragment_problems(fragments, changelog)
    if problems:
        raise FragmentError(problems)
    return fragments


def fragment_sections(fragments: Sequence[Fragment]) -> list[tuple[str, str]]:
    """The headings and entries of the fragments: the kinds of change in their order,
    other headings after them as they come, and each kind by the names of the files.
    Text without a heading is none, as on the website, which shows the fragments
    before the release checks them."""
    sections = []
    for fragment in fragments:
        headings = list(SUBHEADING.finditer(fragment.text))
        ends = [match.start() for match in headings[1:]] + [len(fragment.text)]
        if not headings:
            continue
        sections += [
            (kind_of(match), fragment.text[match.end() : end].strip("\n"))
            for match, end in zip(headings, ends, strict=True)
        ]
    rank = {f"### {kind}": index for index, kind in enumerate(KINDS)}
    return sorted(sections, key=lambda section: rank.get(section[0], len(KINDS)))


def unreleased_text(changelog: Changelog, fragments: Sequence[Fragment] = ()) -> str:
    """The text of Unreleased with the entries of the fragments, each kind of change
    under one heading; the headings of Unreleased come first, as they are."""
    found = changelog.find(UNRELEASED)
    parts = [] if found is None else [changelog.sections[found][1].strip("\n")]
    parts += [f"{heading}\n\n{body}" for heading, body in fragment_sections(fragments)]
    text = "\n\n".join(part for part in parts if part.strip())
    return merge_headings(f"{text}\n") if text else ""


def with_fragments(text: str, fragments: Sequence[Fragment]) -> str:
    """The changelog as the next release will have it, for the website: the entries of
    the fragments joined to the text of Unreleased, each kind of change under one
    heading, as the release groups them, and Unreleased first when the changelog has
    none. A changelog without entries in fragments stays as it is."""
    if not fragment_sections(fragments):
        return text
    changelog = Changelog.parse(text)
    body = block(unreleased_text(changelog, fragments))
    found = changelog.find(UNRELEASED)
    if found is None:
        changelog.sections.insert(0, ("## Unreleased", body))
    else:
        changelog.sections[found] = (changelog.sections[found][0], body)
    return changelog.render()


def release_changelog(
    version: str, main: str, branch: str, fragments: Sequence[Fragment] = ()
) -> str:
    """The changelog of the release pull request for the version: the changelog of main,
    with the text of Unreleased and of the fragments moved into the section that
    release-please wrote on the branch. Without such text, the section lists the pull
    requests. A prerelease keeps the text under Unreleased, and the fragments, for the
    release that follows it. A fragment with a problem raises a FragmentError."""
    written = Changelog.parse(branch)
    found = written.find(version_heading(version))
    if found is None:
        return branch
    heading, generated = written.sections[found]
    changelog = Changelog.parse(main)
    text = unreleased_text(changelog, checked(fragments, main))
    keep = "-" in version or not text.strip()
    others = [
        section
        for section in changelog.sections
        if not UNRELEASED.match(section[0])
        and not version_heading(version).match(section[0])
    ]
    changelog.sections = [
        ("## Unreleased", block(unreleased_text(changelog) if keep else "")),
        (heading, block(generated if keep else text)),
        *others,
    ]
    return changelog.render()


def released_fragments(
    version: str, branch: str, fragments: Sequence[Fragment]
) -> list[str]:
    """The paths of the fragments that the release pull request takes into its section,
    and so deletes: every one, unless the version is a prerelease or release-please has
    not written the section yet."""
    if "-" in version or Changelog.parse(branch).find(version_heading(version)) is None:
        return []
    return [fragment.path for fragment in fragments]


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


def beta_notes(version: str, changelog: str, fragments: Sequence[Fragment] = ()) -> str:
    """The notes of a beta of the next release: what main holds, from the text of
    Unreleased and of the fragments, for the testers who take prereleases."""
    release = version.split("-", 1)[0]
    log = Changelog.parse(changelog)
    text = unreleased_text(log, checked(fragments, changelog)).strip("\n")
    lines = [
        f"A beta of the next release, {release}, with what `main` holds now, for the "
        "testers who take prereleases. The release itself follows when it is ready.",
        "",
        text or "The changes of this beta are not described yet.",
    ]
    return "\n".join([*lines, ""])
