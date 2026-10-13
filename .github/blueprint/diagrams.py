"""The diagrams of the documentation as pictures that every reader sees.

GitHub draws Mermaid only in the browser; its app, the stores of the projects and the
other renderers of Markdown show a ```mermaid block as code. So a diagram is a source
in docs/diagrams/NAME.mmd, which scripts/diagrams.sh draws as the pictures
docs/images/diagrams/NAME.svg and NAME.dark.svg, and the Markdown shows them with a
<picture>. extract moves the blocks of the Markdown there.

The diagram bots commit what scripts/diagrams.sh changed: keep copies it out of the
work tree in a check without secrets, and commit_request turns it into a commit of
the release app, which takes nothing but sources, pictures and pages of Markdown.
"""

from __future__ import annotations

import base64
import html
import os
import posixpath
import re
import subprocess
import unicodedata
from dataclasses import dataclass
from pathlib import Path
from typing import Any

SOURCES = Path("docs/diagrams")
PICTURES = Path("docs/images/diagrams")
# The longest name that a title gives, cut where a word ends.
LONGEST = 72

# A block of Mermaid, also indented in a list, up to its closing fence.
FENCE = re.compile(
    r"^(?P<indent>[ \t]*)(?P<fence>(?P<mark>[`~])(?P=mark){2,})[ \t]*mermaid"
    r"(?:[ \t][^\n]*)?\n(?P<body>(?:[^\n]*\n)*?)[ \t]*(?P=fence)(?P=mark)*[ \t]*(?:\n|\Z)",
    re.M,
)
# A picture of a diagram, as extract writes it.
PICTURE = re.compile(
    r"<picture>(?:(?!</picture>).)*?<img\b[^>]*?\bsrc=\"(?:[^\"]*/)?images/diagrams/"
    r"(?P<name>[\w.-]+?)\.svg\".*?</picture>",
    re.S,
)
TITLE = re.compile(r"^[ \t]*accTitle[ \t]*:[ \t]*(?P<text>[^\n]*?)[ \t]*$", re.M)
DESCRIPTION = re.compile(
    r"^[ \t]*accDescr(?:[ \t]*:[ \t]*(?P<line>[^\n]*?)[ \t]*$|[ \t]*\{(?P<block>[^}]*)\})",
    re.M,
)
FRONT_MATTER = re.compile(r"\A---[ \t]*\n(?:[^\n]*\n)*?---[ \t]*\n")
# A heading of Markdown, and the fence of a block of code, in which # starts no heading.
HEADING = re.compile(r"^ {0,3}#{1,6}[ \t]+(?P<text>.*?)(?:[ \t]+#+)?[ \t]*$")
CODE = re.compile(r"^[ \t]*(?P<fence>`{3,}|~{3,})")
# The label of a node or a subgraph of a flowchart, such as a["Label"] or b(Label).
LABEL = re.compile(
    r"\b\w+[\[({>]+[ \t]*"
    r"(?:\"(?P<quoted>[^\"\n]+)\"|(?P<plain>[^\])}\"\n|]+))"
)
# The labels that a description without accDescr names, at most.
LABELS = 10
QUOTED = re.compile(r'"([^"\n]*)"')
# The tags of HTML that labels hold: a picture shows its labels as SVG text, where
# only <br> breaks a line and Markdown makes bold and italic.
TAG = re.compile(
    r"</?(?P<name>a|b|br|code|em|font|i|kbd|mark|s|small|span|strong|sub|sup|tt|u)"
    r"\b[^>]*>",
    re.I,
)
EMPHASIS = {"b": "**", "strong": "**", "i": "*", "em": "*"}
# What the diagram bots commit: the sources, the pictures and the pages of Markdown,
# by paths of plain names.
KEPT = (SOURCES.as_posix(), PICTURES.as_posix(), "*.md")
PART = re.compile(r"[A-Za-z0-9_][A-Za-z0-9._-]*")
COMMIT = (
    "mutation($input: CreateCommitOnBranchInput!) "
    "{ createCommitOnBranch(input: $input) { commit { oid } } }"
)


@dataclass(frozen=True)
class Diagram:
    """A diagram of a page: a ```mermaid block with its source, or a picture with
    its name."""

    start: int
    end: int
    indent: str = ""
    source: str = ""
    name: str = ""


@dataclass(frozen=True)
class Extracted:
    """A diagram that extract took out of a page into a source."""

    page: Path
    source: Path
    new: bool


@dataclass(frozen=True)
class Changes:
    """What drawing and extract changed: the files to write, by their paths, and the
    pictures to delete."""

    written: tuple[str, ...]
    deleted: tuple[str, ...]


def git(root: Path, *arguments: str) -> str:
    return subprocess.run(
        ["git", *arguments],
        cwd=root,
        capture_output=True,
        check=True,
        text=True,
        encoding="utf-8",
    ).stdout


def markdown_pages(root: Path) -> list[Path]:
    """The Markdown of the repository without what git ignores, English pages before
    the German ones that take their names."""
    listed = git(
        root,
        "ls-files",
        "-z",
        "--cached",
        "--others",
        "--exclude-standard",
        "--",
        "*.md",
    )
    pages = {Path(name) for name in listed.split("\0") if name}
    return sorted(
        (page for page in pages if (root / page).is_file()),
        key=lambda page: (german(page), page.as_posix()),
    )


def german(page: Path) -> bool:
    """Whether a page is the German one of a page, such as README.de.md."""
    return page.name.endswith(".de.md")


def diagrams_of(text: str) -> list[Diagram]:
    """The blocks and the pictures of the diagrams of a page, in their order."""
    found = [
        Diagram(
            match.start(),
            match.end(),
            match["indent"],
            dedent(match["body"], match["indent"]),
        )
        for match in FENCE.finditer(text)
    ]
    found += [
        Diagram(match.start(), match.end(), name=match["name"].removesuffix(".dark"))
        for match in PICTURE.finditer(text)
    ]
    return sorted(found, key=lambda diagram: diagram.start)


def dedent(body: str, indent: str) -> str:
    """The lines of a block without the indentation of its fence, as in a list."""
    lines = []
    for line in body.splitlines():
        if line.startswith(indent):
            line = line[len(indent) :]
        lines.append(line.rstrip() if line.strip() else "")
    return "\n".join(lines).strip("\n") + "\n"


def without_html(source: str) -> str:
    """The source with labels that a picture shows: HTML of the labels becomes text,
    <br> stays, bold and italic become Markdown in a label of Markdown, "`…`"."""
    front = FRONT_MATTER.match(source)
    head = front.group() if front else ""
    lines = source[len(head) :].split("\n")
    return head + "\n".join(_line_without_html(line) for line in lines)


def _line_without_html(line: str) -> str:
    def quoted(match: re.Match[str]) -> str:
        text = match[1]
        emphasis = False

        def tag(found: re.Match[str]) -> str:
            nonlocal emphasis
            name = found["name"].lower()
            if name == "br":
                return "<br>"
            if name in EMPHASIS:
                emphasis = True
                return EMPHASIS[name]
            return ""

        cleaned = TAG.sub(tag, text)
        markdown = (
            cleaned.startswith("`") and cleaned.endswith("`") and len(cleaned) > 1
        )
        if emphasis and not markdown:
            cleaned = f"`{cleaned}`"
        return f'"{cleaned}"'

    parts = []
    position = 0
    for match in QUOTED.finditer(line):
        parts.append(_outside_quotes(line[position : match.start()]))
        parts.append(quoted(match))
        position = match.end()
    parts.append(_outside_quotes(line[position:]))
    return "".join(parts)


def _outside_quotes(text: str) -> str:
    """Text outside of a quoted label, such as a label in brackets, keeps <br> only."""
    return TAG.sub(lambda found: "<br>" if found["name"].lower() == "br" else "", text)


def alt_text(source: str, heading: str = "") -> str:
    """The text of a picture for those who don't see it: the title and the description
    of the diagram for screen readers, accTitle and accDescr. Without them, the
    heading above the diagram and the labels of its nodes say what it shows."""
    title = TITLE.search(source)
    description = DESCRIPTION.search(source)
    parts = [title["text"]] if title and title["text"] else []
    if description:
        text = description["line"] or " ".join((description["block"] or "").split())
        if text:
            parts.append(text)
    if not parts:
        parts = [part for part in (heading, ", ".join(labels(source))) if part]
    return ": ".join(parts) or "A diagram"


def labels(source: str) -> list[str]:
    """The first labels of the nodes and subgraphs of a flowchart, each once, as plain
    words."""
    body = FRONT_MATTER.sub("", source)
    lines = [line.strip() for line in body.splitlines()]
    kind = next((line for line in lines if line and not line.startswith("%%")), "")
    if kind.split(" ")[0] not in ("flowchart", "graph"):
        return []
    found: list[str] = []
    for match in LABEL.finditer(body):
        text = plain(re.sub(r"<br\s*/?>", " ", match["quoted"] or match["plain"]))
        if text and text not in found:
            found.append(text)
    return found[:LABELS]


def plain(text: str) -> str:
    """Words of Markdown without their markup: links, code, emphasis and HTML."""
    text = re.sub(r"!?\[([^\]]*)\]\([^)]*\)", r"\1", text)
    # An attribute list such as {#id .class}; the order of [.#] keeps the template of
    # the blueprint, whose comments start with that pair the other way round, intact.
    text = re.sub(r"\{[.#][^}]*\}[ \t]*$", "", text)
    text = re.sub(r"<[^>]+>|[`*]", "", text)
    return " ".join(text.split())


def heading_before(text: str, position: int) -> str:
    """The words of the last heading of a page above a position, outside of blocks of
    code and of the front matter."""
    front = FRONT_MATTER.match(text)
    heading, fence = "", ""
    for line in text[front.end() if front else 0 : position].splitlines():
        code = CODE.match(line)
        if fence:
            closing = code and code["fence"].startswith(fence)
            if closing and not line.strip(" \t" + fence[0]):
                fence = ""
        elif code:
            fence = code["fence"]
        elif found := HEADING.match(line):
            heading = found["text"]
    return plain(heading)


def slug(text: str) -> str:
    """A name of lowercase words, from a title such as accTitle."""
    text = text.casefold()
    for umlaut, letters in (("ä", "ae"), ("ö", "oe"), ("ü", "ue")):
        text = text.replace(umlaut, letters)
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode()
    name = ""
    for word in re.findall(r"[a-z0-9]+", text):
        if name and len(name) + 1 + len(word) > LONGEST:
            break
        name = f"{name}-{word}" if name else word[:LONGEST]
    return name


def picture(name: str, page: Path, alt: str, indent: str = "") -> str:
    """The <picture> of a diagram on a page: the dark picture in a dark theme, the
    light one elsewhere, by paths from the folder of the page."""
    folder = page.parent.as_posix()
    light = posixpath.relpath((PICTURES / f"{name}.svg").as_posix(), folder)
    dark = posixpath.relpath((PICTURES / f"{name}.dark.svg").as_posix(), folder)
    text = html.escape(alt, quote=False).replace('"', "&quot;")
    lines = [
        "<picture>",
        f'  <source media="(prefers-color-scheme: dark)" srcset="{dark}">',
        f'  <img src="{light}" alt="{text}">',
        "</picture>",
    ]
    return "".join(f"{indent}{line}\n" for line in lines)


class Names:
    """The sources of docs/diagrams/ by their names, and the names of new ones: the
    same source keeps its name, and another one takes the next free name."""

    def __init__(self, root: Path) -> None:
        self.root = root
        folder = root / SOURCES
        self.sources = {
            path.name.removesuffix(".mmd"): path.read_text(encoding="utf-8")
            for path in sorted(folder.glob("*.mmd"))
        }

    def name(self, source: str, wanted: str) -> tuple[str, bool]:
        """The name of a source, and whether it is new."""
        for name, known in self.sources.items():
            if known == source:
                return name, False
        stem, suffix = wanted, ""
        if wanted.endswith(".de"):
            stem, suffix = wanted.removesuffix(".de"), ".de"
        name, number = wanted, 1
        while name in self.sources:
            number += 1
            name = f"{stem}-{number}{suffix}"
        self.sources[name] = source
        path = self.root / SOURCES / f"{name}.mmd"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(source, encoding="utf-8", newline="\n")
        return name, True


def extract(root: Path, pages: list[Path] | None = None) -> list[Extracted]:
    """Takes every ```mermaid block of the pages into a source of docs/diagrams/ and
    puts its <picture> in its place. A source that exists keeps its name; a new one is
    named after its title, accTitle, or else the heading above it, and a diagram of a
    German page after the one at the same place of the English page, with .de.
    Running it again changes nothing."""
    names = Names(root)
    shown: dict[Path, list[str]] = {}
    extracted: list[Extracted] = []
    for page in markdown_pages(root) if pages is None else pages:
        path = root / page
        text = path.read_text(encoding="utf-8")
        diagrams = diagrams_of(text)
        english = shown.get(
            page.with_name(page.name.removesuffix(".de.md") + ".md"), []
        )
        if not german(page) or len(english) != len(diagrams):
            english = []
        pieces: list[str] = []
        position = 0
        found: list[str] = []
        for index, diagram in enumerate(diagrams):
            if not diagram.source:
                found.append(diagram.name)
                continue
            source = without_html(diagram.source)
            heading = heading_before(text, diagram.start)
            if english:
                wanted = f"{english[index].removesuffix('.de')}.de"
            else:
                title = TITLE.search(source)
                wanted = slug(title["text"]) if title else ""
                wanted = wanted or slug(heading)
                wanted = wanted or f"{slug(page.name.removesuffix('.md'))}-{index + 1}"
                if german(page):
                    wanted += ".de"
            name, new = names.name(source, wanted)
            found.append(name)
            extracted.append(Extracted(page, SOURCES / f"{name}.mmd", new))
            pieces.append(text[position : diagram.start])
            snippet = picture(name, page, alt_text(source, heading), diagram.indent)
            if not text[diagram.start : diagram.end].endswith("\n"):
                snippet = snippet.removesuffix("\n")
            pieces.append(snippet)
            position = diagram.end
        shown[page] = found
        if pieces:
            pieces.append(text[position:])
            path.write_text("".join(pieces), encoding="utf-8", newline="\n")
    return extracted


def keep(root: Path, folder: Path) -> Changes:
    """Copies what scripts/diagrams.sh changed in the work tree into folder, for the
    diagram bot: each new or changed source, picture and page under files/ at its
    path, and the paths of the pictures it deleted in deleted.txt. The folder stays
    away when nothing changed."""
    status = git(
        root,
        "status",
        "--porcelain=v1",
        "-z",
        "--no-renames",
        "--untracked-files=all",
        "--",
        *KEPT,
    )
    written: list[str] = []
    deleted: list[str] = []
    for entry in filter(None, status.split("\0")):
        path = entry[3:]
        (written if (root / path).is_file() else deleted).append(path)
    for path in written:
        target = folder / "files" / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes((root / path).read_bytes())
    if deleted:
        folder.mkdir(parents=True, exist_ok=True)
        (folder / "deleted.txt").write_text(
            "".join(f"{path}\n" for path in deleted), encoding="utf-8", newline="\n"
        )
    return Changes(tuple(sorted(written)), tuple(sorted(deleted)))


def committable(path: str) -> bool:
    """Whether the diagram bot may write a file: a source in docs/diagrams/, a
    picture in docs/images/diagrams/ or a page of Markdown, by a path of plain names
    that stays in the repository and out of .git."""
    parts = path.split("/")
    if not all(PART.fullmatch(part.removeprefix(".")) for part in parts):
        return False
    if parts[0] == ".git":
        return False
    folder = "/".join(parts[:-1])
    if folder == SOURCES.as_posix():
        return path.endswith(".mmd")
    if folder == PICTURES.as_posix():
        return path.endswith(".svg")
    return path.endswith(".md")


def changes(folder: Path) -> Changes:
    """What a check kept in folder, once it is checked: it comes from a pull request,
    so the diagram bot writes nothing but sources, pages and pictures that are SVG,
    and deletes nothing but pictures. Raises ValueError for anything else."""
    files = folder / "files"
    written = []
    for directory, folders, names in os.walk(files):
        links = [name for name in folders if Path(directory, name).is_symlink()]
        for name in sorted(names + links):
            path = Path(directory, name)
            relative = path.relative_to(files).as_posix()
            if path.is_symlink() or not committable(relative):
                raise ValueError(
                    f"The diagrams of the check hold {relative}, which is no source, "
                    "picture or page of Markdown."
                )
            if relative.endswith(".svg") and not path.read_bytes().startswith(b"<svg "):
                raise ValueError(
                    f"The diagrams of the check hold {relative}, which is no SVG picture."
                )
            written.append(relative)
    listed = folder / "deleted.txt"
    deleted = listed.read_text(encoding="utf-8").split() if listed.is_file() else []
    for gone in deleted:
        if not (committable(gone) and gone.startswith(f"{PICTURES.as_posix()}/")):
            raise ValueError(
                f"The diagrams of the check would delete {gone}, which is no picture."
            )
    if not written and not deleted:
        raise ValueError("The diagrams of the check hold nothing to commit.")
    return Changes(tuple(sorted(written)), tuple(sorted(deleted)))


def commit_request(
    folder: Path, repository: str, branch: str, head: str, headline: str, body: str
) -> dict[str, Any]:
    """The request to GitHub's GraphQL API that commits what a check kept in folder
    onto a branch, as whoever sends it, and only while the branch is still at head."""
    found = changes(folder)
    additions = [
        {
            "path": path,
            "contents": base64.b64encode(
                (folder / "files" / path).read_bytes()
            ).decode(),
        }
        for path in found.written
    ]
    return {
        "query": COMMIT,
        "variables": {
            "input": {
                "branch": {"repositoryNameWithOwner": repository, "branchName": branch},
                "expectedHeadOid": head,
                "message": {"headline": headline, "body": body},
                "fileChanges": {
                    "additions": additions,
                    "deletions": [{"path": path} for path in found.deleted],
                },
            }
        },
    }


def listing(found: Changes) -> str:
    """The files of a commit of the diagram bot, each with what happened to it."""
    lines = []
    for path in found.written:
        if path.endswith(".md"):
            what = "its `mermaid` blocks are pictures now"
        elif path.endswith(".mmd"):
            what = "the source of a diagram"
        else:
            what = "drawn anew"
        lines.append(f"- `{path}`: {what}")
    lines += [f"- `{path}`: deleted, its source is gone" for path in found.deleted]
    return "".join(f"{line}\n" for line in lines)


def report(folder: Path, kind: str) -> str:
    """What the diagram bot says about its commit: for a pull request whose
    `mermaid` blocks it turned into pictures, a comment, and nothing when it only drew
    pictures anew; for main, the description of its own pull request."""
    found = changes(folder)
    if kind == "comment":
        if not any(path.endswith(".md") for path in found.written):
            return ""
        return (
            "The GitHub app, the stores and other renderers of Markdown show a "
            "`mermaid` block as code, so the diagram bot turned those of this pull "
            "request into pictures of their sources in `docs/diagrams/`:\n\n"
            + listing(found)
            + "\nChange a diagram in its source, and the bot draws its pictures anew. "
            "CONTRIBUTING.md describes the diagrams.\n"
        )
    return (
        "`main` has diagrams that not every reader sees: `mermaid` blocks, which the "
        "GitHub app shows as code, or pictures that differ from their sources. "
        "`bash scripts/diagrams.sh --extract` changes these files:\n\n"
        + listing(found)
        + "\nThis pull request merges itself once every required check passes. The "
        "diagram bot keeps it current with `main`, and closes it when `main` has "
        "every diagram as a picture of its source without it.\n"
    )
