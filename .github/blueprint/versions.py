"""The versions that the release bot writes, back where a hand changed them.

release-please keeps the version of the last release in .release-please-manifest.json
and writes it into the files of its release type and into the extra-files of
release-please-config.json, and the step Version of scripts/check.sh fails while one
of them has another version. The version decides nothing in a pull request: the
release bot takes the next one from the titles. So `fix version`, the autofix of the
step, writes the version of the manifest back into each file, at the place where the
step reads it, and leaves every other byte of the file as it is."""

from __future__ import annotations

import json
import re
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Any

MANIFEST = ".release-please-manifest.json"
CONFIG = "release-please-config.json"
# The files that release-please writes the version into for each release type of the
# template, besides its extra-files.
OF_THE_TYPE = {
    "simple": ("version.txt",),
    "python": ("pyproject.toml",),
    "node": ("package.json", "package-lock.json"),
}
# Where the step Version reads the version of a file, by its name: the first match of
# the pattern of grep -oE -m 1, a line at most.
JSON_VERSION = re.compile(r'"version": "[^"\n]+"')
PLACES = {
    "version.txt": re.compile(r"^[0-9]\S*", re.M),
    "pyproject.toml": re.compile(r'^version = "[^"\n]+"', re.M),
    "package.json": JSON_VERSION,
    "package-lock.json": JSON_VERSION,
    "manifest.json": JSON_VERSION,
    "info.xml": re.compile(r"<version>[^<\n]+</version>"),
    "Dockerfile": re.compile(r'org\.opencontainers\.image\.version="[^"\n]+"'),
}
# The version at that place, as the step reads it.
VERSION = re.compile(r'[0-9]+\.[0-9]+\.[0-9]+[^"<\n]*')


@dataclass(frozen=True)
class Versioned:
    """A file with the version of the release bot: the version that it has, None for
    one without a version at the place of the step, and where that version is."""

    path: str
    found: str | None
    start: int = 0
    end: int = 0


def loaded(path: Path) -> dict[str, Any]:
    """A file of the release bot, in JSON: {} for one that is missing or no object."""
    data = json.loads(path.read_text(encoding="utf-8")) if path.is_file() else {}
    return data if isinstance(data, dict) else {}


def release(root: Path) -> str | None:
    """The version of the last release, from the manifest of the release bot."""
    version = loaded(root / MANIFEST).get(".")
    return version if isinstance(version, str) else None


def files(root: Path) -> list[str]:
    """The files that the release bot writes the version into and the step Version
    checks: those of its release type and its extra-files, by the configuration of
    the release bot, each once."""
    packages = loaded(root / CONFIG).get("packages")
    package = packages.get(".") if isinstance(packages, dict) else None
    if not isinstance(package, dict):
        return []
    paths = list(OF_THE_TYPE.get(str(package.get("release-type")), ()))
    extras = package.get("extra-files")
    for extra in extras if isinstance(extras, list) else []:
        path = extra.get("path") if isinstance(extra, dict) else extra
        if isinstance(path, str):
            paths.append(path)
    return [
        path
        for index, path in enumerate(paths)
        if PurePosixPath(path).name in PLACES and path not in paths[:index]
    ]


def read(root: Path, path: str) -> Versioned:
    """The version of a file at the place where the step Version reads it."""
    file = root / path
    text = file.read_bytes().decode("utf-8") if file.is_file() else ""
    place = PLACES[PurePosixPath(path).name].search(text)
    found = VERSION.search(place.group()) if place else None
    if place is None or found is None:
        return Versioned(path, None)
    return Versioned(
        path, found.group(), place.start() + found.start(), place.start() + found.end()
    )


def differing(root: Path, version: str) -> list[Versioned]:
    """Each file of the release bot that has another version than this one."""
    found = [read(root, path) for path in files(root)]
    return [file for file in found if file.found != version]


@dataclass(frozen=True)
class Fixed:
    """What `fix version` did: the version of the manifest, None without one, the
    files that it wrote it into, each with the version that it had, and those without
    a version at the place of the step, which a person gives one."""

    version: str | None
    fixed: list[Versioned]
    left: list[Versioned]


def fix(root: Path) -> Fixed:
    """Writes the version of the manifest into each file that has another one."""
    version = release(root)
    if version is None:
        return Fixed(None, [], [])
    wrong = differing(root, version)
    fixed = [file for file in wrong if file.found is not None]
    for file in fixed:
        path = root / file.path
        text = path.read_bytes().decode("utf-8")
        path.write_bytes((text[: file.start] + version + text[file.end :]).encode())
    return Fixed(version, fixed, [file for file in wrong if file.found is None])
