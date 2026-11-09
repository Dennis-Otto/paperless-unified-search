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

.github/screenshots.toml, which belongs to the project, can name the shards that
render side by side, each on a runner of its own, whose folders collect puts together
for the one pull request; the files besides pictures that the script renders and the
bot commits, by their exact paths; and the hosts that the jobs that render may reach,
of which Harden-Runner then lets through no other. It can also leave hosts out of the
wait for Harden-Runner before the script runs, give the script a time limit of its
own, and name the folder that a failed render keeps for a person to see. Without it,
one job renders every picture, the bot takes only pictures, and Harden-Runner only
records the traffic.
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
import tomllib
from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass
from pathlib import Path
from typing import TYPE_CHECKING, Any
from urllib.parse import SplitResult, unquote, urlsplit

if TYPE_CHECKING:
    from PIL import Image

SCRIPT = Path("scripts/screenshots.sh")
PATHS = Path(".github/screenshots-paths.txt")
SETTINGS = Path(".github/screenshots.toml")
# A shard, as it names a job, an artifact and the variable SCREENSHOTS_SHARD.
SHARD = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}")
# The most jobs that one matrix of GitHub Actions runs.
MOST_SHARDS = 256
# The one job of a project that names no shards, which renders every picture.
WHOLE = "all"
# A host as Harden-Runner allows it, by its name in small letters, or *. and a name
# for every name below it, and its port.
HOST = re.compile(
    r"(?:\*\.)?[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+"
    r":(?P<port>[0-9]{1,5})"
)
# The time limit of the job render, in minutes, which a test keeps equal to that of the
# workflow: the script gets it when the settings give it none of its own. A limit of
# the settings leaves the steps around the script at least a quarter of an hour, for
# the wait for the hosts, the tools, the comparison and the artifact of a failure.
JOB_MINUTES = 60
MOST_MINUTES = 45
# The keys that .github/screenshots.toml may set, in the order of the documentation.
KEYS = ("shards", "files", "hosts", "skip_wait", "timeout_minutes", "failure_folder")
# The folders in which the bot writes no file besides pictures: the history, and the
# workflows with the settings of the repository and of its bots.
CLOSED = (".git", ".github")
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
# The most files that GitHub's comparison of two commits lists.
COMPARED_FILES = 300
SOCIAL_PREVIEW = ".github/social-preview.png"
# The width and the height of a picture, in pixels.
Size = tuple[int, int]
# The red of each changed pixel of a difference, over a pale gray copy of the new
# picture, as the visual checks of the repositories draw it.
RED = (220, 38, 38)
# About the width at which a phone, or Telegram, shows a picture: the frames around the
# changes of a difference keep their size in such pixels, so that a change of a
# single pixel still shows. In those pixels: the width of a frame, the gap between a
# frame and its changes, and how near to each other changes are that share a frame.
SHOWN = 1000
FRAME = 4
MARGIN = 6
NEAR = 48
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
    another. A file besides pictures that the settings name is of the kind file,
    with neither. diff is where the folder of keep holds the picture of the
    difference, of a changed picture of the same size and frames that keep could
    read."""

    path: str
    status: str
    share: float | None = None
    before: Size | None = None
    kind: str = "picture"
    diff: str | None = None


@dataclass(frozen=True)
class Settings:
    """What .github/screenshots.toml sets: the shards that render side by side, the
    files besides pictures that the bot commits, the hosts that the jobs that render
    may reach and those of them that they don't wait for, the time limit of the
    script in minutes and the folder that a failed render keeps. Each stays empty
    without the file."""

    shards: tuple[str, ...] = ()
    files: tuple[str, ...] = ()
    hosts: tuple[str, ...] = ()
    skip_wait: tuple[str, ...] = ()
    timeout_minutes: int | None = None
    failure_folder: str = ""


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


def refused_file(path: str) -> str:
    """Why the bot may not commit a file besides pictures that the settings name, or
    nothing for one by a path of plain names, outside .git and .github, that is no
    picture, which the bot takes anyway."""
    if not is_plain(path):
        return "is no path of plain names in the repository"
    if path.split("/")[0] in CLOSED:
        return (
            "is in .git or .github, which hold the history, the workflows and the "
            "settings, never a file that a script renders"
        )
    if Path(path).suffix.lower() in SIGNATURES:
        return "is a picture, which the bot takes anyway"
    return ""


def listed(data: Mapping[str, Any], key: str, where: str) -> tuple[str, ...]:
    """A list of strings of the settings, in its order, with none named twice."""
    value = data.get(key, [])
    if not isinstance(value, list) or not all(isinstance(item, str) for item in value):
        raise ValueError(f"{where}: {key} is a list of strings.")
    twice = [item for index, item in enumerate(value) if item in value[:index]]
    if twice:
        raise ValueError(f"{where}: {key} names {twice[0]} twice.")
    return tuple(value)


def settings(root: Path, path: Path = SETTINGS) -> Settings:
    """The settings of the screenshot bot in the file at path, checked: each shard a
    plain name, each file one that the bot may commit, each host as Harden-Runner
    allows it and each that the jobs don't wait for one of those, the time limit a
    whole number of minutes within that of the job, and the folder of a failure one of
    plain names outside .git. Raises ValueError for anything else, a key that the bot
    doesn't know too, so that a typo never drops a host or a shard without a word."""
    file = root / path
    if not file.is_file():
        return Settings()
    where = path.as_posix()
    try:
        data = tomllib.loads(file.read_text(encoding="utf-8"))
    except (tomllib.TOMLDecodeError, UnicodeDecodeError) as error:
        raise ValueError(f"{where} is no TOML that the bot can read: {error}") from None
    unknown = sorted(set(data) - set(KEYS))
    if unknown:
        raise ValueError(
            f"{where} sets {unknown[0]}, which the bot doesn't know; it knows "
            f"{', '.join(KEYS[:-1])} and {KEYS[-1]}."
        )
    shards = listed(data, "shards", where)
    for shard in shards:
        if not SHARD.fullmatch(shard):
            raise ValueError(
                f"{where}: the shard {shard!r} is no plain name of letters, digits, "
                "dots, underscores and hyphens, up to 64 from a letter or a digit."
            )
    if len(shards) > MOST_SHARDS:
        raise ValueError(
            f"{where} names {len(shards)} shards, more than the {MOST_SHARDS} jobs "
            "that one matrix of GitHub Actions runs."
        )
    files = listed(data, "files", where)
    for name in files:
        reason = refused_file(name)
        if reason:
            raise ValueError(f"{where}: the file {name!r} {reason}.")
    hosts = listed(data, "hosts", where)
    for host in hosts:
        found = HOST.fullmatch(host)
        if not found or not 0 < int(found["port"]) < 65536:
            raise ValueError(
                f"{where}: the host {host!r} is no host and port as Harden-Runner "
                "allows it, such as ghcr.io:443 or *.data.mcr.microsoft.com:443, in "
                "small letters."
            )
    skip_wait = listed(data, "skip_wait", where)
    for host in skip_wait:
        if host not in hosts:
            raise ValueError(
                f"{where}: skip_wait names {host!r}, which hosts doesn't name; the "
                "jobs leave out of their wait only a host of the project."
            )
    return Settings(
        shards,
        files,
        hosts,
        skip_wait,
        time_limit(data, where),
        failure_folder(data, where),
    )


def time_limit(data: Mapping[str, Any], where: str) -> int | None:
    """The time limit of the script in the settings, in whole minutes within that of
    the job, or nothing when they give none."""
    minutes = data.get("timeout_minutes")
    if minutes is None:
        return None
    # TOML's true and false are numbers to Python.
    if isinstance(minutes, bool) or not isinstance(minutes, int):
        raise ValueError(f"{where}: timeout_minutes is a whole number of minutes.")
    if not 0 < minutes <= MOST_MINUTES:
        raise ValueError(
            f"{where}: timeout_minutes is {minutes}, but the script may take from 1 "
            f"to {MOST_MINUTES} minutes, so that the steps after it in the job of "
            f"{JOB_MINUTES} still keep what it left."
        )
    return minutes


def failure_folder(data: Mapping[str, Any], where: str) -> str:
    """The folder that a failed render keeps as an artifact, by a path of plain names
    in the repository outside .git, or nothing when the settings name none."""
    folder = data.get("failure_folder", "")
    if not isinstance(folder, str):
        raise ValueError(f"{where}: failure_folder is a string.")
    if folder and (not is_plain(folder) or folder.split("/")[0] == ".git"):
        raise ValueError(
            f"{where}: the failure_folder {folder!r} is no path of plain names in the "
            "repository outside .git, such as tests/e2e/artifacts."
        )
    return folder


def plan(found: Settings) -> str:
    """The outputs of the job that starts a render, as lines of $GITHUB_OUTPUT: the
    shards as the matrix of the job render, one job named all for a project that
    names none; whether Harden-Runner blocks every host but those of the jobs and of
    the settings, or, without hosts, only records the traffic, and the hosts that the
    jobs don't wait for; the time limit of the script, the most that the settings may
    give when they give none, so that a hung script fails its step before the job
    runs out of time and the folder of the failure is kept; and the folder that a failed render keeps, if any."""
    shards = [{"shard": shard, "id": shard} for shard in found.shards] or [
        {"shard": "", "id": WHOLE}
    ]
    return (
        f"shards={json.dumps(shards, separators=(',', ':'))}\n"
        f"policy={'block' if found.hosts else 'audit'}\n"
        f"hosts={' '.join(found.hosts)}\n"
        f"skip_wait={' '.join(found.skip_wait)}\n"
        f"timeout_minutes={found.timeout_minutes or MOST_MINUTES}\n"
        f"failure_folder={found.failure_folder}\n"
    )


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


def patterns(
    root: Path, paths: Path = PATHS, script: Path = SCRIPT, config: Path = SETTINGS
) -> list[str]:
    """The patterns of the files whose change can change the pictures: those of the
    list, one on each line, without its comments and empty lines, and always the
    script, the list itself and the settings, whose shards, files and hosts a render
    tries out. A pattern that starts with ! leaves files out."""
    found = [script.as_posix(), paths.as_posix(), config.as_posix()]
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


Frames = list[tuple[tuple[int, int], bytes]]
Pairs = list[tuple[Size, bytes, bytes]]
Most = tuple[Size, bytes, "Image.Image"]


def compared(before: bytes, after: bytes) -> Pairs | None:
    """The frames of two pictures side by side, as the size of each pair and the
    pixels of the frame before and after, or None when their sizes or numbers of
    frames differ."""
    return paired(frames(before), frames(after))


def paired(old: Frames, new: Frames) -> Pairs | None:
    """The frames of two pictures that frames read, side by side as compared gives
    them, or None when their sizes or numbers of frames differ."""
    if len(old) != len(new) or any(a[0] != b[0] for a, b in zip(old, new, strict=True)):
        return None
    return [(size, a, b) for (size, a), (_, b) in zip(old, new, strict=True)]


def changed_pixels(size: Size, first: bytes, second: bytes) -> Image.Image:
    """The pixels that differ between two frames of one size, as a mask: 255 where
    a pixel changed, 0 where it stayed."""
    from PIL import Image, ImageChops

    difference = ImageChops.difference(
        Image.frombytes("RGBA", size, first), Image.frombytes("RGBA", size, second)
    )
    bands = difference.split()
    most = bands[0]
    for band in bands[1:]:
        most = ImageChops.lighter(most, band)
    return most.point([0] + [255] * 255)


def changed_share(before: bytes, after: bytes) -> float | None:
    """The share of the pixels that changed, 0 when the pictures look the same, and
    None when their sizes or numbers of frames differ."""
    pairs = compared(before, after)
    return None if pairs is None else changes(pairs)[0]


def changes(pairs: Pairs) -> tuple[float, Most | None]:
    """The share of the pixels that changed between the frames of pairs, and the pair
    of frames with the most changed pixels, as its size, the pixels after and the
    mask of the changes, or None when no pixel changed. Each mask is counted once."""
    total = changed = 0
    most: tuple[int, Most] | None = None
    for size, first, second in pairs:
        total += size[0] * size[1]
        if first == second:
            continue
        mask = changed_pixels(size, first, second)
        count = mask.histogram()[255]
        changed += count
        if most is None or count > most[0]:
            most = (count, (size, second, mask))
    return changed / total, None if most is None else most[1]


def scaled(length: int, longest: int) -> int:
    """A length of a picture shown SHOWN pixels wide at its longer side, in the pixels
    of a picture whose longer side is longest, at least one."""
    return max(1, -(-length * longest // SHOWN))


def regions(mask: Image.Image, near: int) -> list[tuple[int, int, int, int]]:
    """The boxes around the changes of a mask, as getbbox gives them: the changed
    pixels in squares of near pixels, each square with those that touch it, also at a
    corner, so that changes nearer to each other than about near share a box."""
    width, height = mask.size
    boxes: dict[tuple[int, int], tuple[int, int, int, int]] = {}
    for top in range(0, height, near):
        for left in range(0, width, near):
            box = mask.crop((left, top, left + near, top + near)).getbbox()
            if box:
                boxes[left // near, top // near] = (
                    left + box[0],
                    top + box[1],
                    left + box[2],
                    top + box[3],
                )
    found = []
    while boxes:
        square, box = boxes.popitem()
        group, joined = [square], [box]
        for x, y in group:
            for side in [(x + dx, y + dy) for dx in (-1, 0, 1) for dy in (-1, 0, 1)]:
                if side in boxes:
                    group.append(side)
                    joined.append(boxes.pop(side))
        found.append(
            (
                min(b[0] for b in joined),
                min(b[1] for b in joined),
                max(b[2] for b in joined),
                max(b[3] for b in joined),
            )
        )
    return sorted(found)


def difference(before: bytes, after: bytes) -> bytes | None:
    """A picture of what changed between two pictures of the same size and frames, as
    a PNG: each changed pixel in red over a pale gray copy of the new picture, of
    the pair of frames with the most changed pixels for an animation. A red frame
    around each place of change keeps a change of a single pixel in sight when a
    phone shows the picture scaled down to about SHOWN pixels wide. None when the
    sizes or numbers of frames differ or no pixel changed."""
    pairs = compared(before, after)
    most = None if pairs is None else changes(pairs)[1]
    return None if most is None else drawing(most)


def drawing(most: Most) -> bytes:
    """The picture of the difference that difference draws, of the pair of frames
    that changes found with the most changed pixels."""
    from PIL import Image, ImageDraw

    size, pixels, mask = most
    shown = Image.new("RGBA", size, "white")
    shown.alpha_composite(Image.frombytes("RGBa", size, pixels).convert("RGBA"))
    # A quarter of the darkness of each pixel on white, from 191 to 255: the index 0
    # of the palette, which no such gray takes, is the red of the changes.
    drawn = shown.convert("L").point(lambda light: 255 - (256 - light) // 4)
    drawn.paste(0, mask=mask)
    drawn.putpalette([*RED, *(light for light in range(1, 256) for _ in "rgb")])
    longest = max(size)
    frame, margin = scaled(FRAME, longest), scaled(MARGIN, longest)
    draw = ImageDraw.Draw(drawn)
    for left, top, right, bottom in regions(mask, scaled(NEAR, longest)):
        away = margin + frame
        draw.rectangle(
            (left - away, top - away, right - 1 + away, bottom - 1 + away),
            outline=0,
            width=frame,
        )
    out = io.BytesIO()
    drawn.save(out, "PNG")
    return out.getvalue()


def diff_path(path: str) -> str:
    """Where keep puts the picture of the difference of the picture at path, in its
    folder: under diff/ at its path, as a PNG, whose name ends in .png."""
    return f"diff/{path}" + ("" if path.lower().endswith(".png") else ".png")


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


def kind_of(path: str, files: Sequence[str] = ()) -> str:
    """How the bot takes a path that the script changed: as a picture, as a file
    besides pictures that the settings name, or, with nothing, not at all."""
    if is_picture(path):
        return "picture"
    if path in files and not refused_file(path):
        return "file"
    return ""


def is_text(data: bytes) -> bool:
    """Whether a file is text in UTF-8, which a pull request shows line by line."""
    try:
        data.decode("utf-8")
    except UnicodeDecodeError:
        return False
    return True


def store(
    folder: Path,
    found: Kept,
    read: Callable[[str], bytes],
    drawn: Callable[[str], bytes],
) -> None:
    """Writes what keep or collect found into folder, for the screenshot bot: each new
    or changed picture or file under files/ at its path, as read gives it, the
    picture of each difference at its diff, as drawn gives it for the path, the
    paths of the deleted ones in deleted.txt and the list of all with their changes
    in report.json. The bot commits nothing of diff/: it is there for people, in the
    artifact of the run."""
    # Also when the script only deleted pictures, which leaves nothing to copy.
    folder.mkdir(parents=True, exist_ok=True)
    for picture in found.pictures:
        targets = []
        if picture.status != "deleted":
            targets.append((folder / "files" / picture.path, read))
        if picture.diff:
            targets.append((folder / picture.diff, drawn))
        for target, source in targets:
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(source(picture.path))
    deleted = [p.path for p in found.pictures if p.status == "deleted"]
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
                        "diff": p.diff,
                    }
                    for p in found.pictures
                ],
                "ignored": list(found.ignored),
            },
            indent=1,
        )
        + "\n",
        encoding="utf-8",
        newline="\n",
    )


def keep(root: Path, folder: Path | None = None, files: Sequence[str] = ()) -> Kept:
    """Compares each picture that the script changed in the work tree with the one
    that the commit has, by its pixels: one that looks the same gets the committed
    file back. A file of files, besides pictures, which the settings name, counts as
    changed when its bytes do, and has to be text in UTF-8. Copies what is left into
    folder, when there is one, for the screenshot bot, as store writes it, with a
    picture of the difference of each changed picture that kept its size and frames.
    The folder stays away when nothing changed."""
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
    drawings: dict[str, bytes] = {}
    for entry in filter(None, status.split("\0")):
        code, path = entry[:2], entry[3:]
        kind = kind_of(path, files)
        if not kind:
            ignored.append(path)
            continue
        if not (root / path).is_file():
            pictures.append(Picture(path, "deleted", kind=kind))
            continue
        data = (root / path).read_bytes()
        if kind == "file":
            if not is_text(data):
                raise ValueError(
                    f"The script wrote {path}, which {SETTINGS.as_posix()} names as "
                    "a file besides pictures, but which is no text in UTF-8."
                )
            status_of = "new" if code == "??" else "changed"
            pictures.append(Picture(path, status_of, kind=kind))
            continue
        if not looks_like_its_kind(path, data):
            raise ValueError(
                f"The script wrote {path}, which is no "
                f"{Path(path).suffix.lower()[1:].upper()} picture."
            )
        if code == "??":
            pictures.append(Picture(path, "new"))
            continue
        before = committed(root, path)
        # Pillow reads both pictures once, for the comparison, the share and the
        # picture of the difference; a file it can't read differs from every other.
        read: tuple[Frames, Frames] | None
        try:
            read = frames(before), frames(data)
        except (OSError, ValueError):
            read = None
        if before == data or (read is not None and read[0] == read[1]):
            (root / path).write_bytes(before)
            continue
        pairs = None if read is None else paired(*read)
        share, most = (None, None) if pairs is None else changes(pairs)
        drawn = drawing(most) if folder is not None and most is not None else None
        if drawn is not None:
            drawings[path] = drawn
        pictures.append(
            Picture(
                path,
                "changed",
                share,
                size_before(path, before, data),
                diff=None if drawn is None else diff_path(path),
            )
        )
    pictures = still_videos(root, pictures)
    pictures.sort(key=lambda picture: picture.path)
    found = Kept(tuple(pictures), tuple(sorted(ignored)))
    if folder is not None and pictures:
        store(
            folder, found, lambda path: (root / path).read_bytes(), drawings.__getitem__
        )
    return found


def reported_size(value: Any) -> Size | None:
    """A size as the report of a run names it."""
    if value is None:
        return None
    width, height = value
    return int(width), int(height)


def drawn_by_keep(folder: Path, picture: Picture) -> bool:
    """Whether the picture of the difference that a report names for a picture, if it
    names one, is as keep draws it: a PNG at the diff of a changed picture, and no
    link, neither the file nor a folder on its way from folder."""
    if picture.diff is None:
        return True
    drawn = folder / picture.diff
    return (
        picture.status == "changed"
        and picture.kind == "picture"
        and picture.diff == diff_path(picture.path)
        and drawn.resolve() == folder.resolve() / picture.diff
        and drawn.is_file()
        and looks_like_its_kind(picture.diff, drawn.read_bytes())
    )


def kept(folder: Path, files: Sequence[str] = ()) -> Kept:
    """What a run of keep left in folder, once it is checked: the render job runs the
    script of the project, so the bot takes nothing but pictures, whose files start
    as their kind does, and the text of the files of files, which the settings of
    main name, at the paths of the report, and deletes nothing else. The kind of each
    comes from its path, never from the run. The picture of a difference has to be
    a PNG at the diff of a changed picture, as keep names it, for collect, which
    copies it. Raises ValueError for anything else."""
    others = f"no picture and no file that {SETTINGS.as_posix()} names"
    stored = folder / "files"
    written = set()
    for directory, folders, names in os.walk(stored):
        links = [name for name in folders if Path(directory, name).is_symlink()]
        for name in sorted(names + links):
            path = Path(directory, name)
            relative = path.relative_to(stored).as_posix()
            kind = "" if path.is_symlink() else kind_of(relative, files)
            if not kind:
                raise ValueError(
                    f"The screenshots of the run hold {relative}, which is {others}."
                )
            data = path.read_bytes()
            if kind == "picture" and not looks_like_its_kind(relative, data):
                raise ValueError(
                    f"The screenshots of the run hold {relative}, which is no "
                    f"{Path(relative).suffix.lower()[1:].upper()} picture."
                )
            if kind == "file" and not is_text(data):
                raise ValueError(
                    f"The screenshots of the run hold {relative}, which is no text "
                    "in UTF-8."
                )
            written.add(relative)
    listed = folder / "deleted.txt"
    deleted = (
        set(listed.read_text(encoding="utf-8").split()) if listed.is_file() else set()
    )
    for gone in sorted(deleted):
        if not kind_of(gone, files):
            raise ValueError(
                f"The screenshots of the run would delete {gone}, which is {others}."
            )
    try:
        report = json.loads((folder / REPORT).read_text(encoding="utf-8"))
        pictures = tuple(
            Picture(
                str(entry["path"]),
                str(entry["status"]),
                None if entry["share"] is None else float(entry["share"]),
                reported_size(entry["before"]),
                kind_of(str(entry["path"]), files),
                # A report of a keep before the differences has none.
                None if entry.get("diff") is None else str(entry["diff"]),
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
    for picture in pictures:
        if not drawn_by_keep(folder, picture):
            raise ValueError(
                f"The {REPORT} of the screenshots names {picture.diff} as the "
                f"difference of {picture.path}, which is no PNG that keep drew for it."
            )
    return Kept(pictures, ignored)


def collect(folder: Path, shards: Sequence[Path], files: Sequence[str] = ()) -> Kept:
    """Puts together what keep left in the folder of each shard, each checked as the
    job that commits checks it, in folder, as one run of keep over every picture
    would have left it. Each picture belongs to one shard: a path that two shards
    changed raises ValueError rather than take either. The folder stays away when no
    shard kept anything."""
    sources: dict[str, Path] = {}
    pictures: list[Picture] = []
    ignored: set[str] = set()
    for shard in shards:
        found = kept(shard, files)
        for picture in found.pictures:
            if picture.path in sources:
                raise ValueError(
                    f"The shards {sources[picture.path].name} and {shard.name} both "
                    f"changed {picture.path}, which only one of them may render."
                )
            sources[picture.path] = shard
            pictures.append(picture)
        ignored.update(found.ignored)
    pictures.sort(key=lambda picture: picture.path)
    merged = Kept(tuple(pictures), tuple(sorted(ignored)))
    if pictures:
        store(
            folder,
            merged,
            lambda path: (sources[path] / "files" / path).read_bytes(),
            lambda path: (sources[path] / diff_path(path)).read_bytes(),
        )
    return merged


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


def pages_of_folder(
    root: Path, folder: Path, repository: str, files: Sequence[str] = ()
) -> list[Page]:
    """The pages that the pictures of a folder of keep correct, for the job with the
    release app, which reads these numbers from the files once kept checked them."""
    found = kept(folder, files)
    return corrected_pages(root, resized(found, folder / "files"), repository)


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


def written(
    folder: Path, pages: Sequence[Page] = (), files: Sequence[str] = ()
) -> list[str]:
    """The paths that the commits of the bot write: each picture and file of folder,
    a deleted one too, and each corrected page."""
    return [picture.path for picture in kept(folder, files).pictures] + [
        page.path for page in pages
    ]


def overtaken(
    paths: Sequence[str], compared: Mapping[str, Any], base: str = "main"
) -> tuple[bool, str]:
    """Whether base changed one of paths since the commit that was rendered, as
    GitHub's comparison of the two lists the files, and why: a pull request from that
    commit would conflict with base over each, so the bot renders base anew instead.
    That happens when the pull request before merges while the next one renders. A
    comparison in which base lacks the commit, as after a forced push, or that lists
    as many files as GitHub lists at most, counts as such a change."""
    files = compared.get("files") or []
    if int(compared.get("behind_by") or 0):
        return True, (
            f"`{base}` no longer has the commit that was rendered, so the bot renders "
            f"`{base}` anew."
        )
    if len(files) >= COMPARED_FILES:
        return True, (
            f"`{base}` changed {COMPARED_FILES} files or more since the commit that "
            "was rendered, more than GitHub lists, so the bot renders it anew."
        )
    changed = {
        str(name)
        for file in files
        for name in (file.get("filename"), file.get("previous_filename"))
        if name
    }
    found = [path for path in paths if path in changed]
    if not found:
        return False, (
            f"`{base}` changed none of the files of the pull request since the commit "
            "that was rendered."
        )
    named = ", ".join(f"`{path}`" for path in found[:5])
    more = f" and {len(found) - 5} more" if len(found) > 5 else ""
    return True, (
        f"`{base}` changed {named}{more} since the commit that was rendered, so a "
        f"pull request from that commit would conflict: the bot renders `{base}` anew."
    )


def parts(
    folder: Path,
    largest: int = LARGEST,
    pages: Sequence[Page] = (),
    files: Sequence[str] = (),
) -> list[list[Picture | Page]]:
    """The pictures and files of folder, then the corrected pages, in the commits
    that carry them, each request below largest once its files are in base64."""
    found: list[list[Picture | Page]] = [[]]
    size = 0
    changes: list[Picture | Page] = [*kept(folder, files).pictures, *pages]
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
    files: Sequence[str] = (),
) -> dict[str, Any]:
    """The request to GitHub's GraphQL API that commits the part of what keep left in
    folder, and of the corrected pages, onto a branch, as whoever sends it, and only
    while the branch is still at head. The headline of one of several parts names it."""
    every = parts(folder, LARGEST, pages, files)
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
        if picture.kind != "picture":
            continue
        share = shown_share(picture.share) if picture.status == "changed" else ""
        rows.append(f"| `{picture.path}` | {picture.status} | {share} |")
    return "".join(f"{row}\n" for row in rows)


def file_listing(found: Kept) -> str:
    """The files besides pictures of a commit of the screenshot bot, as a sentence
    with a table of what happened to each; nothing when there are none."""
    rows = [f"| `{p.path}` | {p.status} |" for p in found.pictures if p.kind == "file"]
    if not rows:
        return ""
    return (
        f"These files that `{SETTINGS.as_posix()}` names besides pictures changed:\n\n"
        "| File | Change |\n| --- | --- |\n" + "".join(f"{row}\n" for row in rows)
    )


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
        "takes only pictures from the script, and the files that "
        f"`{SETTINGS.as_posix()}` names: {named}.\n"
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


def drawn_differences(found: Kept) -> str:
    """Where the pictures of the differences are, as a sentence; nothing when keep drew
    none."""
    if not any(picture.diff for picture in found.pictures):
        return ""
    return (
        "The artifact of the run also holds, in `diff/`, a picture of what changed in "
        "each of them that kept its size and frames: every changed pixel in red over "
        "a pale copy of the new picture, of the frame of an animation with the most "
        "changes, and a red frame around each place of change, so that a small change "
        "shows on a phone too.\n"
    )


def summary(found: Kept) -> str:
    """What keep or collect found, for the summary of the run or the terminal."""
    if any(picture.kind == "picture" for picture in found.pictures):
        text = "These pictures look different:\n\n" + listing(found)
    elif found.pictures:
        text = "Every picture looks as committed.\n"
    else:
        text = "Every picture looks as committed, so no file changes.\n"
    for extra in (drawn_differences(found), file_listing(found), others(found)):
        if extra:
            text += f"\n{extra}"
    return text


def report(
    folder: Path,
    sha: str,
    run: str,
    script: Path = SCRIPT,
    pages: Sequence[Page] = (),
    files: Sequence[str] = (),
) -> str:
    """The description of the bot's pull request, for the pictures and files of
    script and the pages that they correct."""
    found = kept(folder, files)
    text = f"`bash {script.as_posix()}` rendered the pictures of `main` anew, on {sha}"
    if any(picture.kind == "picture" for picture in found.pictures):
        text += ", and these look different:\n\n" + listing(found)
    else:
        text += ", and every picture looks as committed.\n"
    for extra in (drawn_differences(found), file_listing(found), corrections(pages)):
        if extra:
            text += f"\n{extra}"
    if any(picture.path == SOCIAL_PREVIEW for picture in found.pictures):
        text += (
            f"\n`{SOCIAL_PREVIEW}` changed: once this pull request is merged, upload "
            "it under **Settings → General → Social preview**, where GitHub takes it "
            "from. Until GitHub shows it, the nightly look of the bot-health bot keeps "
            "the issue *Upload the new social preview* open.\n"
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
