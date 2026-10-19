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
but pictures, by paths of plain names.
"""

from __future__ import annotations

import base64
import io
import json
import os
import re
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any

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


@dataclass(frozen=True)
class Picture:
    """A picture that the script changed: its path, whether it is new, changed or
    deleted, and the share of its pixels that changed, when the two have the same
    size and number of frames."""

    path: str
    status: str
    share: float | None = None


@dataclass(frozen=True)
class Kept:
    """What keep left for the pull request: the pictures by their paths, and the
    other files that the script changed, which the bot leaves out."""

    pictures: tuple[Picture, ...]
    ignored: tuple[str, ...] = ()


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


def is_picture(path: str) -> bool:
    """Whether the bot may write a file: a picture by its ending, by a path of plain
    names that stays in the repository, out of .git and of the workflows."""
    parts = path.split("/")
    if not all(PART.fullmatch(part.removeprefix(".")) for part in parts):
        return False
    if parts[0] == ".git" or path.startswith(".github/workflows/"):
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
        pictures.append(Picture(path, "changed", share))
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
                    {"path": p.path, "status": p.status, "share": p.share}
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


def parts(folder: Path, largest: int = LARGEST) -> list[list[Picture]]:
    """The pictures of folder in the commits that carry them, each request below
    largest once its pictures are in base64."""
    found: list[list[Picture]] = [[]]
    size = 0
    for picture in kept(folder).pictures:
        length = 0
        if picture.status != "deleted":
            length = (folder / "files" / picture.path).stat().st_size * 4 // 3
        if found[-1] and length and size + length > largest:
            found.append([])
            size = 0
        found[-1].append(picture)
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
) -> dict[str, Any]:
    """The request to GitHub's GraphQL API that commits the part of what keep left in
    folder onto a branch, as whoever sends it, and only while the branch is still at
    head. The headline of one of several parts names it."""
    every = parts(folder, LARGEST)
    if not 1 <= part <= len(every):
        raise ValueError(
            f"The screenshots of the run have no part {part}, of {len(every)}."
        )
    pictures = every[part - 1]
    if len(every) > 1:
        headline = f"{headline} ({part} of {len(every)})"
    additions = [
        {
            "path": picture.path,
            "contents": base64.b64encode(
                (folder / "files" / picture.path).read_bytes()
            ).decode(),
        }
        for picture in pictures
        if picture.status != "deleted"
    ]
    deletions = [{"path": p.path} for p in pictures if p.status == "deleted"]
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
        f"commits pictures only: {named}.\n"
    )


def summary(found: Kept) -> str:
    """What keep found, for the summary of the run or the terminal."""
    if found.pictures:
        text = "These pictures look different:\n\n" + listing(found)
    else:
        text = "Every picture looks as committed, so no file changes.\n"
    extra = others(found)
    return f"{text}\n{extra}" if extra else text


def report(folder: Path, sha: str, run: str, script: Path = SCRIPT) -> str:
    """The description of the bot's pull request, for the pictures of script."""
    found = kept(folder)
    text = (
        f"`bash {script.as_posix()}` rendered the pictures of `main` anew, on {sha}, "
        "and these look different:\n\n" + listing(found)
    )
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
