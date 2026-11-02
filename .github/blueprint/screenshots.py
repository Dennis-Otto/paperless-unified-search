"""The pictures of a project that its own script renders, renewed by a bot only when
their pixels change.

A project turns the screenshot bot on with scripts/screenshots.sh, which renders its
pictures in place, wherever the documentation, the stores or the social preview take
them from, and lists in .github/screenshots-paths.txt the files whose change can
change them. The Screenshots workflow runs the script on main after such a change,
after a release, every week and on demand. keep compares each picture that changed
with the one that main has, by its pixels: one whose pixels stayed the same gets the
committed file back, so that a new encoder, a new browser or another runner changes
no file, and what is left goes into a folder for the bot's pull request.
commit_request turns that folder into commits of the release app, which take nothing
but pictures, by paths of plain names, from that folder. A picture that came out at
another size would leave a page that names its width and height with the wrong shape:
corrected_pages rewrites those numbers on the pages of the checkout, and nothing else.
"""

from __future__ import annotations

import base64
import html
import io
import json
import os
import posixpath
import re
import struct
import subprocess
from collections.abc import Sequence
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import SplitResult, unquote, urlsplit

SCRIPT = Path("scripts/screenshots.sh")
PATHS = Path(".github/screenshots-paths.txt")
# The pictures that the bot commits, by their endings, and how each file starts; an
# MP4 starts with the size of its first box, which looks_like_its_kind skips. A
# video, such as one that a website plays in place of a GIF, has no pixels that
# Pillow reads, so it counts as changed when its bytes change; beside a GIF of the
# same name, which it shows, only together with that GIF.
SIGNATURES = {
    ".png": (b"\x89PNG\r\n\x1a\n",),
    ".jpg": (b"\xff\xd8\xff",),
    ".jpeg": (b"\xff\xd8\xff",),
    ".gif": (b"GIF87a", b"GIF89a"),
    ".webp": (b"RIFF",),
    ".mp4": (b"ftyp",),
}
PART = re.compile(r"[A-Za-z0-9_][A-Za-z0-9._-]*")
# What one request to GitHub's GraphQL API carries at most: larger sets of pictures
# go in several commits.
LARGEST = 8_000_000
COMMIT = (
    "mutation($input: CreateCommitOnBranchInput!) "
    "{ createCommitOnBranch(input: $input) { commit { oid } } }"
)
REPORT = "report.json"
SOCIAL_PREVIEW = ".github/social-preview.png"
# The width and the height of a picture, in pixels.
Size = tuple[int, int]
# The pages whose sizes the bot corrects: the Markdown at the top of the repository
# and in docs/, as pathspecs of git.
PAGES = (":(glob)*.md", ":(glob)docs/**/*.md")
# A picture of Markdown that has attributes, with a text that may hold brackets one
# deep and an optional title, such as ![A](a.png "B"){ width="1600" height="900" }.
MARKDOWN_PICTURE = re.compile(
    r"(?P<shown>!\[(?:[^\[\]]|\[[^\]]*\])*\]"
    r"\(\s*<?(?P<address>[^\s<>)]+)>?(?:\s+(?:\"[^\"]*\"|'[^']*'))?\s*\))"
    r"(?P<attributes>\{[^{}\n]*\})"
)
# A picture of HTML, on one line or several, whose attributes may hold a >.
HTML_PICTURE = re.compile(r"""<img\b(?:[^<>"']|"[^"]*"|'[^']*')*>""", re.IGNORECASE)
# An attribute of either: a quoted value counts as a whole, so that a width in the text
# of a picture stays text.
ATTRIBUTE = re.compile(
    r"""(?P<lead>(?P<name>[\w:.-]+)\s*=\s*)(?P<value>"[^"]*"|'[^']*'|[^\s"'<>{}]+)"""
)
# The branch of a picture that a page shows from main of its own repository, as
# .github/mkdocs_blueprint.py reads such an address for the website.
BRANCHES = ("main", "HEAD")
ORIGIN = re.compile(r"github\.com[:/](?P<repository>[^/\s]+/[^/\s]+?)(?:\.git)?/?$")


@dataclass(frozen=True)
class Picture:
    """A picture that the script changed: its path, whether it is new, changed or
    deleted, the share of its pixels that changed, when the two have the same size
    and number of frames, and the size of the committed file when the new one has
    another."""

    path: str
    status: str
    share: float | None = None
    before: Size | None = None


@dataclass(frozen=True)
class Kept:
    """What keep left for the pull request: the pictures by their paths, and the
    other files that the script changed, which the bot leaves out."""

    pictures: tuple[Picture, ...]
    ignored: tuple[str, ...] = ()


@dataclass(frozen=True)
class Correction:
    """A size that a page named for a picture, and the size that it names now."""

    picture: str
    named: Size
    fitted: Size


@dataclass(frozen=True)
class Page:
    """A page whose named sizes the bot corrected: its path, its content with the new
    numbers, and each correction."""

    path: str
    data: bytes
    corrections: tuple[Correction, ...]


def git(root: Path, *arguments: str) -> str:
    return subprocess.run(
        ["git", *arguments],
        cwd=root,
        capture_output=True,
        check=True,
        text=True,
        encoding="utf-8",
    ).stdout


def committed(root: Path, path: str) -> bytes:
    """The file at path as the commit of the work tree has it."""
    return subprocess.run(
        ["git", "show", f"HEAD:{path}"], cwd=root, capture_output=True, check=True
    ).stdout


def is_plain(path: str) -> bool:
    """Whether a path has only plain names, so that it stays in the repository."""
    return all(PART.fullmatch(part.removeprefix(".")) for part in path.split("/"))


def is_picture(path: str) -> bool:
    """Whether the bot may write a file: a picture by its ending, by a path of plain
    names that stays in the repository, out of .git and of the workflows."""
    if not is_plain(path):
        return False
    if path.split("/")[0] == ".git" or path.startswith(".github/workflows/"):
        return False
    return Path(path).suffix.lower() in SIGNATURES


def looks_like_its_kind(path: str, data: bytes) -> bool:
    """Whether a file starts as a picture of its ending does."""
    suffix = Path(path).suffix.lower()
    if suffix == ".webp" and data[8:12] != b"WEBP":
        return False
    if suffix == ".mp4":
        data = data[4:]
    return data.startswith(SIGNATURES[suffix])


def png_size(data: bytes) -> tuple[int, ...]:
    return struct.unpack(">II", data[16:24]) if data[12:16] == b"IHDR" else ()


def gif_size(data: bytes) -> tuple[int, ...]:
    return struct.unpack("<HH", data[6:10])


def jpeg_size(data: bytes) -> tuple[int, ...]:
    """The size in the first frame header of a JPEG, past the segments before it. A
    file that ends first, or holds no marker where one belongs, has none."""
    at = 2
    while data[at] == 0xFF:
        marker = data[at + 1]
        # The frame headers of every kind of JPEG; C4, C8 and CC only look like them.
        if 0xC0 <= marker <= 0xCF and marker not in (0xC4, 0xC8, 0xCC):
            height, width = struct.unpack(">HH", data[at + 5 : at + 9])
            return width, height
        at += 2 + struct.unpack(">H", data[at + 2 : at + 4])[0]
    return ()


def webp_size(data: bytes) -> tuple[int, ...]:
    """The size in the first chunk of the three kinds of WebP: lossy, lossless, and
    extended, such as an animation."""
    chunk = data[12:16]
    if chunk == b"VP8 ":
        width, height = struct.unpack("<HH", data[26:30])
        return width & 0x3FFF, height & 0x3FFF
    # The two others read fewer bytes as a smaller number without complaint.
    if len(data) < 30:
        return ()
    if chunk == b"VP8L":
        bits = int.from_bytes(data[21:25], "little")
        return (bits & 0x3FFF) + 1, (bits >> 14 & 0x3FFF) + 1
    if chunk == b"VP8X":
        width = int.from_bytes(data[24:27], "little") + 1
        return width, int.from_bytes(data[27:30], "little") + 1
    return ()


SIZE_READERS = {
    ".png": png_size,
    ".jpg": jpeg_size,
    ".jpeg": jpeg_size,
    ".gif": gif_size,
    ".webp": webp_size,
}


def picture_size(path: str, data: bytes) -> Size | None:
    """The width and the height that a picture names in its header, read without
    Pillow, which the job with the release app doesn't have: its file comes from a job
    that ran the script of the project, so a file that names none, or none that makes
    sense, simply has no size. A video has none here either."""
    reader = SIZE_READERS.get(Path(path).suffix.lower())
    if reader is None or not looks_like_its_kind(path, data):
        return None
    try:
        found = reader(data)
    except (IndexError, struct.error):
        return None
    if len(found) != 2 or not all(found):
        return None
    return found[0], found[1]


def patterns(root: Path, paths: Path = PATHS, script: Path = SCRIPT) -> list[str]:
    """The patterns of the files whose change can change the pictures: those of the
    list, one on each line, without its comments and empty lines, and always the
    script and the list itself. A pattern that starts with ! leaves files out."""
    found = [script.as_posix(), paths.as_posix()]
    listed = root / paths
    if listed.is_file():
        for line in listed.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                found.append(line)
    return found


def pathspec(pattern: str) -> str:
    """A pattern of the list as a pathspec of git: * stays within a folder, ** goes
    through folders, and a folder takes everything in it, as the paths of a
    workflow do."""
    if pattern.startswith("!"):
        return f":(glob,exclude){pattern[1:]}"
    return f":(glob){pattern}"


def due(
    root: Path,
    since: str = "",
    until: str = "HEAD",
    paths: Path = PATHS,
    script: Path = SCRIPT,
) -> tuple[bool, str]:
    """Whether the bot renders, and why: never without the script; after a change,
    since..until, only when it touches a file of the list; otherwise always."""
    if not (root / script).is_file():
        return False, (
            f"The repository has no `{script.as_posix()}`, so the bot renders no "
            "pictures; adding the script turns it on."
        )
    if not since:
        return True, f"The bot renders the pictures with `{script.as_posix()}`."
    specs = [pathspec(pattern) for pattern in patterns(root, paths, script)]
    changed = git(
        root, "diff", "--name-only", "--no-renames", since, until, "--", *specs
    ).split()
    if not changed:
        return False, (
            "The change touches no file of the list "
            f"`{paths.as_posix()}`, so the pictures stay as they are."
        )
    named = ", ".join(f"`{path}`" for path in changed[:5])
    more = f" and {len(changed) - 5} more" if len(changed) > 5 else ""
    return True, f"The change touches {named}{more}, so the bot renders the pictures."


def frames(data: bytes) -> list[tuple[tuple[int, int], bytes]]:
    """The frames of a picture as their sizes and pixels, those of an animation in
    their order, without a frame that repeats the one before and without the time
    each frame shows: a recording that waited a moment longer looks the same. A
    pixel that is fully transparent counts as one, whatever its color."""
    from PIL import Image, ImageSequence

    found: list[tuple[tuple[int, int], bytes]] = []
    with Image.open(io.BytesIO(data)) as image:
        for frame in ImageSequence.Iterator(image):
            pixels = frame.convert("RGBA").convert("RGBa").tobytes()
            if not found or found[-1] != (frame.size, pixels):
                found.append((frame.size, pixels))
    return found


def changed_share(before: bytes, after: bytes) -> float | None:
    """The share of the pixels that changed, 0 when the pictures look the same, and
    None when their sizes or numbers of frames differ."""
    from PIL import Image, ImageChops

    old, new = frames(before), frames(after)
    if len(old) != len(new) or any(a[0] != b[0] for a, b in zip(old, new, strict=True)):
        return None
    total = changed = 0
    for (size, first), (_, second) in zip(old, new, strict=True):
        if first == second:
            total += size[0] * size[1]
            continue
        difference = ImageChops.difference(
            Image.frombytes("RGBA", size, first), Image.frombytes("RGBA", size, second)
        )
        bands = difference.split()
        most = bands[0]
        for band in bands[1:]:
            most = ImageChops.lighter(most, band)
        total += size[0] * size[1]
        changed += size[0] * size[1] - most.histogram()[0]
    return changed / total


def same_pixels(before: bytes, after: bytes) -> bool:
    """Whether two pictures look the same: the same frames with the same pixels. A
    file that is no picture Pillow can read differs from every other."""
    if before == after:
        return True
    try:
        return frames(before) == frames(after)
    except (OSError, ValueError):
        return False


def still_videos(root: Path, pictures: list[Picture]) -> list[Picture]:
    """The pictures without each changed NAME.mp4 whose NAME.gif kept its pixels,
    which gets its committed file back.

    The script records the video again on each run, and its bytes differ with the
    time that each step of the recording took, while the GIF of the same recording
    looks the same; the video shows what that GIF shows.
    """
    shown = {picture.path for picture in pictures if picture.status != "deleted"}
    left = []
    for picture in pictures:
        path = Path(picture.path)
        gif = path.with_suffix(".gif").as_posix()
        if (
            picture.status == "changed"
            and path.suffix.lower() == ".mp4"
            and gif not in shown
            and (root / gif).is_file()
        ):
            (root / path).write_bytes(committed(root, picture.path))
            continue
        left.append(picture)
    return left


def size_before(path: str, before: bytes, after: bytes) -> Size | None:
    """The size of a picture before the script changed it, when it has another now."""
    old = picture_size(path, before)
    return old if old != picture_size(path, after) else None


def keep(root: Path, folder: Path | None = None) -> Kept:
    """Compares each picture that the script changed in the work tree with the one
    that the commit has, by its pixels: one that looks the same gets the committed
    file back. Copies what is left into folder, when there is one, for the screenshot
    bot: each new or changed picture under files/ at its path, the paths of the
    deleted ones in deleted.txt and the list of all with their changes in
    report.json. The folder stays away when no picture changed."""
    status = git(
        root,
        "status",
        "--porcelain=v1",
        "-z",
        "--no-renames",
        "--untracked-files=all",
    )
    pictures: list[Picture] = []
    ignored: list[str] = []
    for entry in filter(None, status.split("\0")):
        code, path = entry[:2], entry[3:]
        if not is_picture(path):
            ignored.append(path)
            continue
        if not (root / path).is_file():
            pictures.append(Picture(path, "deleted"))
            continue
        data = (root / path).read_bytes()
        if not looks_like_its_kind(path, data):
            raise ValueError(
                f"The script wrote {path}, which is no "
                f"{Path(path).suffix.lower()[1:].upper()} picture."
            )
        if code == "??":
            pictures.append(Picture(path, "new"))
            continue
        before = committed(root, path)
        if same_pixels(before, data):
            (root / path).write_bytes(before)
            continue
        try:
            share = changed_share(before, data)
        except (OSError, ValueError):
            share = None
        pictures.append(
            Picture(path, "changed", share, size_before(path, before, data))
        )
    pictures = still_videos(root, pictures)
    pictures.sort(key=lambda picture: picture.path)
    found = Kept(tuple(pictures), tuple(sorted(ignored)))
    if folder is None or not pictures:
        return found
    # Also when the script only deleted pictures, which leaves nothing to copy.
    folder.mkdir(parents=True, exist_ok=True)
    for picture in pictures:
        if picture.status != "deleted":
            target = folder / "files" / picture.path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes((root / picture.path).read_bytes())
    deleted = [picture.path for picture in pictures if picture.status == "deleted"]
    if deleted:
        (folder / "deleted.txt").write_text(
            "".join(f"{path}\n" for path in deleted), encoding="utf-8", newline="\n"
        )
    (folder / REPORT).write_text(
        json.dumps(
            {
                "pictures": [
                    {
                        "path": p.path,
                        "status": p.status,
                        "share": p.share,
                        "before": p.before,
                    }
                    for p in pictures
                ],
                "ignored": list(found.ignored),
            },
            indent=1,
        )
        + "\n",
        encoding="utf-8",
        newline="\n",
    )
    return found


def reported_size(value: Any) -> Size | None:
    """A size as the report of a run names it."""
    if value is None:
        return None
    width, height = value
    return int(width), int(height)


def kept(folder: Path) -> Kept:
    """What a run of keep left in folder, once it is checked: the render job runs the
    script of the project, so the bot takes nothing but pictures, whose files start
    as their kind does, at the paths of the report, and deletes nothing but
    pictures. Raises ValueError for anything else."""
    files = folder / "files"
    written = set()
    for directory, folders, names in os.walk(files):
        links = [name for name in folders if Path(directory, name).is_symlink()]
        for name in sorted(names + links):
            path = Path(directory, name)
            relative = path.relative_to(files).as_posix()
            if path.is_symlink() or not is_picture(relative):
                raise ValueError(
                    f"The screenshots of the run hold {relative}, which is no picture."
                )
            if not looks_like_its_kind(relative, path.read_bytes()):
                raise ValueError(
                    f"The screenshots of the run hold {relative}, which is no "
                    f"{Path(relative).suffix.lower()[1:].upper()} picture."
                )
            written.add(relative)
    listed = folder / "deleted.txt"
    deleted = (
        set(listed.read_text(encoding="utf-8").split()) if listed.is_file() else set()
    )
    for gone in sorted(deleted):
        if not is_picture(gone):
            raise ValueError(
                f"The screenshots of the run would delete {gone}, which is no picture."
            )
    try:
        report = json.loads((folder / REPORT).read_text(encoding="utf-8"))
        pictures = tuple(
            Picture(
                str(entry["path"]),
                str(entry["status"]),
                None if entry["share"] is None else float(entry["share"]),
                reported_size(entry["before"]),
            )
            for entry in report["pictures"]
        )
        ignored = tuple(str(path) for path in report["ignored"])
    except (OSError, ValueError, KeyError, TypeError) as error:
        raise ValueError(
            f"The screenshots of the run have no readable {REPORT}: {error}"
        ) from None
    shown = {p.path for p in pictures if p.status != "deleted"}
    removed = {p.path for p in pictures if p.status == "deleted"}
    if shown != written or removed != deleted:
        raise ValueError(
            f"The {REPORT} of the screenshots names other pictures than the run holds."
        )
    if not pictures:
        raise ValueError("The screenshots of the run hold nothing to commit.")
    return Kept(pictures, ignored)


def repository_of(root: Path) -> str:
    """OWNER/NAME of the remote origin on GitHub, or nothing without one: then only an
    address relative to its page shows a picture of this repository."""
    remote = subprocess.run(
        ["git", "remote", "get-url", "origin"],
        cwd=root,
        capture_output=True,
        check=False,
        text=True,
        encoding="utf-8",
    )
    found = ORIGIN.search(remote.stdout.strip())
    return found["repository"] if found else ""


def from_main(address: SplitResult, repository: str) -> str:
    """The path in the repository of what an address shows from main of repository
    on GitHub, as .github/mkdocs_blueprint.py reads it; nothing for any other."""
    segments = address.path.split("/")[1:]
    if address.scheme != "https":
        return ""
    if address.hostname == "github.com" and segments[2:3] == ["raw"]:
        rest = segments[3:]
    elif address.hostname == "github.com" and segments[2:3] == ["blob"]:
        rest = segments[3:] if "raw=true" in address.query.split("&") else []
    elif address.hostname == "raw.githubusercontent.com":
        rest = segments[2:]
    else:
        return ""
    if "/".join(segments[:2]).lower() != repository.lower():
        return ""
    if rest[:2] == ["refs", "heads"]:
        rest = rest[2:]
    if len(rest) < 2 or rest[0] not in BRANCHES:
        return ""
    return posixpath.normpath(unquote("/".join(rest[1:])))


def shown_picture(address: str, page: str, repository: str) -> str:
    """The path in the repository of the file that a page shows at address: relative
    to the page, or from main of repository; nothing for what is neither."""
    try:
        parsed = urlsplit(html.unescape(address))
    except ValueError:
        return ""
    if parsed.scheme or parsed.netloc:
        return from_main(parsed, repository)
    return posixpath.normpath(posixpath.join(posixpath.dirname(page), parsed.path))


def fitted_size(named: Size, before: Size, after: Size) -> Size | None:
    """The size that a page should name for a picture that went from before to after,
    or None when what it names still fits. A page that named the whole picture of
    another shape names the whole new one. Any other keeps its width, as a smaller
    picture in a gallery does, with the height of the new shape; a height may be one
    pixel off, as rounding at a smaller size makes it, and a sharper picture of the
    same shape changes nothing on the page."""
    width, height = named
    across, down = after
    if named == before and width * down != height * across:
        return after
    if abs(height - width * down / across) <= 1:
        return None
    return width, max(1, round(width * down / across))


def attributes_of(text: str) -> dict[str, re.Match[str]]:
    return {match["name"].lower(): match for match in ATTRIBUTE.finditer(text)}


def named_number(attribute: re.Match[str] | None) -> int:
    """The whole number that an attribute names, 0 for anything else, such as 50%."""
    value = attribute["value"].strip("\"'") if attribute else ""
    return int(value) if value.isdecimal() else 0


def fit_page(
    text: str, page: str, sizes: dict[str, tuple[Size, Size]], repository: str
) -> tuple[str, list[Correction]]:
    """The text of a page with the width and the height corrected of each picture of
    sizes that it names both of, in Markdown and in HTML, and what it corrected. The
    numbers are all that changes: their quotes, every other attribute and the rest of
    the page stay as they are."""
    corrections: list[Correction] = []

    def fit(attributes: str, address: str) -> str:
        picture = shown_picture(address, page, repository)
        found = attributes_of(attributes)
        named = named_number(found.get("width")), named_number(found.get("height"))
        if picture not in sizes or not all(named):
            return attributes
        fitted = fitted_size(named, *sizes[picture])
        if fitted is None:
            return attributes
        corrections.append(Correction(picture, named, fitted))
        new = dict(zip(("width", "height"), fitted, strict=True))

        def number(match: re.Match[str]) -> str:
            value = new.get(match["name"].lower())
            if value is None:
                return match[0]
            digits = match["value"].strip("\"'")
            return match["lead"] + match["value"].replace(digits, str(value))

        return ATTRIBUTE.sub(number, attributes)

    def in_markdown(match: re.Match[str]) -> str:
        return match["shown"] + fit(match["attributes"], match["address"])

    def in_html(match: re.Match[str]) -> str:
        source = attributes_of(match[0]).get("src")
        return fit(match[0], source["value"].strip("\"'")) if source else match[0]

    return (
        HTML_PICTURE.sub(in_html, MARKDOWN_PICTURE.sub(in_markdown, text)),
        corrections,
    )


def resized(found: Kept, files: Path) -> dict[str, tuple[Size, Size]]:
    """Each picture that came out at another size, with that of the committed file
    and that of its file in the folder files; one whose file names none stays away,
    so that its pages stay as they are."""
    sizes = {}
    for picture in found.pictures:
        if picture.before is None:
            continue
        after = picture_size(picture.path, (files / picture.path).read_bytes())
        if after is not None:
            sizes[picture.path] = (picture.before, after)
    return sizes


def corrected_pages(
    root: Path, sizes: dict[str, tuple[Size, Size]], repository: str
) -> list[Page]:
    """Each page that git tracks in the work tree at root whose named sizes no longer
    fit a picture of sizes, corrected. Its text comes from the checkout and only the
    numbers from the pictures, so that nothing of a script reaches a page. A page by
    a path that isn't plain, a link and one that isn't UTF-8 stay as they are, and the
    check that names the size explains them."""
    pages: list[Page] = []
    if not sizes:
        return pages
    for path in filter(None, git(root, "ls-files", "-z", "--", *PAGES).split("\0")):
        file = root / path
        if not is_plain(path) or file.is_symlink() or not file.is_file():
            continue
        try:
            text = file.read_bytes().decode("utf-8")
        except UnicodeDecodeError:
            continue
        corrected, corrections = fit_page(text, path, sizes, repository)
        if corrections:
            pages.append(Page(path, corrected.encode("utf-8"), tuple(corrections)))
    return pages


def pages_of_folder(root: Path, folder: Path, repository: str) -> list[Page]:
    """The pages that the pictures of a folder of keep correct, for the job with the
    release app, which reads these numbers from the files once kept checked them."""
    return corrected_pages(root, resized(kept(folder), folder / "files"), repository)


def correct_work_tree(root: Path, found: Kept, repository: str) -> list[Page]:
    """Writes the corrections of what keep found into the pages at root, for a person
    who renders on their machine."""
    pages = corrected_pages(root, resized(found, root), repository)
    for page in pages:
        (root / page.path).write_bytes(page.data)
    return pages


def contents(folder: Path, change: Picture | Page) -> bytes | None:
    """What a commit writes for a picture of folder or for a corrected page, and None
    for a picture that it deletes."""
    if isinstance(change, Page):
        return change.data
    if change.status == "deleted":
        return None
    return (folder / "files" / change.path).read_bytes()


def parts(
    folder: Path, largest: int = LARGEST, pages: Sequence[Page] = ()
) -> list[list[Picture | Page]]:
    """The pictures of folder, then the corrected pages, in the commits that carry
    them, each request below largest once its files are in base64."""
    found: list[list[Picture | Page]] = [[]]
    size = 0
    changes: list[Picture | Page] = [*kept(folder).pictures, *pages]
    for change in changes:
        data = contents(folder, change)
        length = 0 if data is None else len(data) * 4 // 3
        if found[-1] and length and size + length > largest:
            found.append([])
            size = 0
        found[-1].append(change)
        size += length
    return found


def commit_request(
    folder: Path,
    repository: str,
    branch: str,
    head: str,
    headline: str,
    body: str,
    part: int = 1,
    pages: Sequence[Page] = (),
) -> dict[str, Any]:
    """The request to GitHub's GraphQL API that commits the part of what keep left in
    folder, and of the corrected pages, onto a branch, as whoever sends it, and only
    while the branch is still at head. The headline of one of several parts names it."""
    every = parts(folder, LARGEST, pages)
    if not 1 <= part <= len(every):
        raise ValueError(
            f"The screenshots of the run have no part {part}, of {len(every)}."
        )
    if len(every) > 1:
        headline = f"{headline} ({part} of {len(every)})"
    additions = []
    deletions = []
    for change in every[part - 1]:
        data = contents(folder, change)
        if data is None:
            deletions.append({"path": change.path})
        else:
            additions.append(
                {"path": change.path, "contents": base64.b64encode(data).decode()}
            )
    return {
        "query": COMMIT,
        "variables": {
            "input": {
                "branch": {"repositoryNameWithOwner": repository, "branchName": branch},
                "expectedHeadOid": head,
                "message": {"headline": headline, "body": body},
                "fileChanges": {"additions": additions, "deletions": deletions},
            }
        },
    }


def shown_share(share: float | None) -> str:
    """The share of the changed pixels, as the table of the pull request shows it."""
    if share is None:
        return "another size or frames"
    if share >= 1:
        return "100 %"
    if share < 0.0001:
        return "< 0.01 %"
    return f"{share * 100:.2f} %"


def listing(found: Kept) -> str:
    """The pictures of a commit of the screenshot bot, as a table with what happened
    to each."""
    rows = ["| Picture | Change | Pixels changed |", "| --- | --- | ---: |"]
    for picture in found.pictures:
        share = shown_share(picture.share) if picture.status == "changed" else ""
        rows.append(f"| `{picture.path}` | {picture.status} | {share} |")
    return "".join(f"{row}\n" for row in rows)


def others(found: Kept) -> str:
    """The files other than pictures that the script changed, which the bot leaves
    out, as a sentence; nothing when there are none."""
    if not found.ignored:
        return ""
    named = ", ".join(f"`{path}`" for path in found.ignored[:10])
    if len(found.ignored) > 10:
        named += f" and {len(found.ignored) - 10} more"
    return (
        "The script changed other files too, which the bot leaves out, because it "
        f"takes only pictures from the script: {named}.\n"
    )


def corrections(pages: Sequence[Page]) -> str:
    """The sizes that the bot corrected on the pages, as a table with a sentence;
    nothing when it corrected none."""
    if not pages:
        return ""
    rows = ["| Page | Picture | Named | Now |", "| --- | --- | ---: | ---: |"]
    for page in pages:
        for change in page.corrections:
            named = " x ".join(map(str, change.named))
            fitted = " x ".join(map(str, change.fitted))
            rows.append(f"| `{page.path}` | `{change.picture}` | {named} | {fitted} |")
    return (
        "These pictures came out at another size, so the width and the height that "
        "these pages name for them changed, and nothing else on those pages:\n\n"
        + "".join(f"{row}\n" for row in rows)
    )


def summary(found: Kept) -> str:
    """What keep found, for the summary of the run or the terminal."""
    if found.pictures:
        text = "These pictures look different:\n\n" + listing(found)
    else:
        text = "Every picture looks as committed, so no file changes.\n"
    extra = others(found)
    return f"{text}\n{extra}" if extra else text


def report(
    folder: Path,
    sha: str,
    run: str,
    script: Path = SCRIPT,
    pages: Sequence[Page] = (),
) -> str:
    """The description of the bot's pull request, for the pictures of script and the
    pages that they correct."""
    found = kept(folder)
    text = (
        f"`bash {script.as_posix()}` rendered the pictures of `main` anew, on {sha}, "
        "and these look different:\n\n" + listing(found)
    )
    corrected = corrections(pages)
    if corrected:
        text += f"\n{corrected}"
    if any(picture.path == SOCIAL_PREVIEW for picture in found.pictures):
        text += (
            f"\n`{SOCIAL_PREVIEW}` changed: once this pull request is merged, upload "
            "it under **Settings → General → Social preview**, where GitHub takes it "
            "from.\n"
        )
    extra = others(found)
    if extra:
        text += f"\n{extra}"
    return text + (
        "\nA picture whose pixels stayed the same keeps its file, so only these "
        "change. This pull request merges itself once every required check passes; "
        "the bot keeps it current with `main`, and closes it when `main` looks as "
        "rendered without it. The workflow run keeps the pictures for three days: "
        f"{run}\n"
    )
