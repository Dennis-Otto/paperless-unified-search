"""What a pull request makes, for the one report of the PR coach: the release that it
brings and the entry that it adds to the changelog, the coverage of its tests against
that of main, and the website that it builds.

The coach adds these parts after the sections of the checks that failed. A pull request
controls its title, its description, its files and its coverage report, so their text
appears only as code, and a link leads only to GitHub or to the website of the
repository, built from names that can't end it. The versions and the address of the
website come from the files of the default branch; nothing of the pull request runs.
"""

from __future__ import annotations

import json
import re
import textwrap
from collections.abc import Callable, Iterable, Sequence
from dataclasses import dataclass
from itertools import islice
from pathlib import Path
from typing import Any

from blueprint.coach import CONTROL, PAGE, TYPES, Part, code, fence, paged
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


def website_artifact(api: Api, repo: str, sha: str) -> str | None:
    """The link to the artifact website that the check docs kept for sha."""
    data = api.get(
        f"repos/{repo}/actions/artifacts?name={WEBSITE_ARTIFACT}&per_page={PAGE}"
    )
    for item in (data or {}).get("artifacts") or []:
        run = item.get("workflow_run") or {}
        if run.get("head_sha") == sha and not item.get("expired"):
            return (
                f"https://github.com/{repo}/actions/runs/{int(run['id'])}"
                f"/artifacts/{int(item['id'])}"
            )
    return None


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


# ---------------------------------------------------------------- the parts


def about(
    api: Api,
    repo: str,
    pull: Json,
    root: Path,
    coverage: tuple[Coverage, Coverage | None] | None = None,
) -> list[Part]:
    """The parts of the report about the pull request: its release, its coverage when
    the tests kept a report, and its website."""
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
    ]
    return [part for part in parts if part is not None]
