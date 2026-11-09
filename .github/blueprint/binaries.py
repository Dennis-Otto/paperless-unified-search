"""The binary files in Git, which the step *Binary files* of scripts/check.sh checks.

The OSPS Baseline of OpenSSF allows no generated program in the repository and no other
binary file that nobody can review (OSPS-QA-05.01 and OSPS-QA-05.02). A release builds
what it ships from the sources, so a program, a library, compiled code or an archive has
no place in Git, whatever the name of its file says. A person reviews a picture, a
video, a sound, a font or a document by looking at it, and the inputs of the fuzz tests
in fuzz/ are data of the tests, so these may be binary; a document of Office, of
OpenDocument or an EPUB is a zip, which passes with the name of such a document. An input
of the fuzz tests that starts like a program or an archive fails as one.
"""

from __future__ import annotations

import subprocess
from dataclasses import dataclass
from pathlib import Path, PurePosixPath

# The binary files that a person reviews by looking at them or listening to them.
MEDIA = frozenset(
    {
        # Pictures.
        ".apng",
        ".avif",
        ".bmp",
        ".gif",
        ".ico",
        ".jpeg",
        ".jpg",
        ".png",
        ".tif",
        ".tiff",
        ".webp",
        # Videos and sounds.
        ".flac",
        ".m4a",
        ".m4v",
        ".mov",
        ".mp3",
        ".mp4",
        ".oga",
        ".ogg",
        ".opus",
        ".wav",
        ".webm",
        # Fonts and documents.
        ".otf",
        ".pdf",
        ".ttf",
        ".woff",
        ".woff2",
    }
)
# The documents of Office, of OpenDocument and EPUB, which are zip files and pass with
# the first bytes of one.
ZIPPED = frozenset({".docx", ".epub", ".odp", ".ods", ".odt", ".pptx", ".xlsx"})
ZIP = b"PK\x03\x04"
# The folder of the inputs of the fuzz tests, which may be any bytes.
FUZZ = "fuzz/"
# The first bytes of a program, a library, compiled code or an archive: ELF, the
# programs of Windows and DOS, Mach-O, Java classes and the universal programs of macOS,
# WebAssembly, the archives of ar, such as a static library or a Debian package, zip,
# such as a JAR, a wheel or an extension, gzip, bzip2, xz, 7-Zip, RAR, an RPM and the
# package of a Chrome extension.
EXECUTABLE = (
    b"\x7fELF",
    b"MZ",
    b"\xfe\xed\xfa\xce",
    b"\xfe\xed\xfa\xcf",
    b"\xce\xfa\xed\xfe",
    b"\xcf\xfa\xed\xfe",
    b"\xca\xfe\xba\xbe",
    b"\0asm",
    b"!<arch>\n",
    ZIP,
    b"\x1f\x8b",
    b"BZh",
    b"\xfd7zXZ\0",
    b"7z\xbc\xaf\x27\x1c",
    b"Rar!\x1a\x07",
    b"\xed\xab\xee\xdb",
    b"Cr24",
)
# Git takes a file for binary when its first 8000 bytes hold a NUL, and so does this.
SNIFFED = 8000
PROGRAM = (
    "is a program, a library, compiled code or an archive, which nobody can review"
)
UNREVIEWABLE = (
    "is a binary file but no picture, video, sound, font or document, which nobody can "
    "review"
)


@dataclass(frozen=True)
class Binary:
    """A binary file of Git that nobody can review, and why."""

    path: str
    problem: str


def listed(root: Path) -> list[str]:
    """The files of git, tracked or new, each once, as scripts/check.sh lists them."""
    found = subprocess.run(
        ["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"],
        cwd=root,
        capture_output=True,
        check=True,
    ).stdout
    return sorted(
        {name.decode("utf-8", "surrogateescape") for name in found.split(b"\0") if name}
    )


def problem(path: str, start: bytes) -> str:
    """What is wrong with a file that starts with these bytes, or nothing."""
    if b"\0" not in start:
        return ""
    suffix = PurePosixPath(path).suffix.lower()
    if start.startswith(EXECUTABLE):
        return "" if suffix in ZIPPED and start.startswith(ZIP) else PROGRAM
    if suffix in MEDIA or path.startswith(FUZZ):
        return ""
    return UNREVIEWABLE


def binaries(root: Path) -> list[Binary]:
    """The binary files of git that nobody can review, by name."""
    found = []
    for path in listed(root):
        file = root / path
        if file.is_symlink() or not file.is_file():
            continue
        with file.open("rb") as source:
            start = source.read(SNIFFED)
        if wrong := problem(path, start):
            found.append(Binary(path, wrong))
    return found
