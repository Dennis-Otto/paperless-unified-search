"""The age of the versions that the weekly refresh of the lock files brings.

The refresh merges by itself only from versions at least a week old, as old as every
other update of Renovate waits for (decision 0137): Renovate gives npm `--before=` a
week ago, and `blueprint.py locks --refresh` compiles the lock files of pip-compile
again from uploads at least a week old. This check, a step of the check
dependency-review of each pull request of the refresh, holds the pull request when a
younger version comes anyway: one that npm kept because the project asks for a version
that new, a compile that failed, or a lock file of another package manager. It asks
the registry when each version that the pull request adds was released, PyPI for the
lock files of pip-compile and the registry of npm for package-lock.json, whose dates
the registries set and no author can choose; a version that it can't date, or a
changed file whose versions it can't read, holds the refresh as well."""

from __future__ import annotations

import json
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from collections.abc import Callable, Iterable
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path, PurePosixPath
from typing import Any

from blueprint import github
from blueprint.diagrams import git
from blueprint.locks import AGE, lock_files, read_pins

NPM_LOCK = "package-lock.json"
NPM_REGISTRY = "https://registry.npmjs.org/"
PYPI = "https://pypi.org/pypi/"
TIMEOUT = 30
ATTEMPTS = 3
PAUSE = 5


@dataclass(frozen=True, order=True)
class Added:
    """A version that the refresh adds to a lock file, by its package manager; or,
    replaced, a version of base that the refresh takes out while the package stays."""

    file: str
    manager: str
    name: str
    version: str
    replaced: bool = False

    def __str__(self) -> str:
        return f"{self.file}: {self.name} {self.version}"


class Undated(Exception):
    """The registry names no release date of a version."""


def show(root: Path, revision: str, name: str) -> str | None:
    """The file root/name in revision; none when revision doesn't have it."""
    try:
        return git(root, "show", f"{revision}:{name}")
    except subprocess.CalledProcessError:
        return None


def pip_versions(text: str | None) -> set[tuple[str, str]] | None:
    """The packages and versions that a lock file of pip-compile pins, a pin by
    address with no version; none for a file with a line that is no pin."""
    if text is None:
        return set()
    pins = read_pins(text)
    if pins is None:
        return None
    return {
        (name.removesuffix(" @"), version)
        for name, _, version in (pin.partition("==") for pin in pins.pinned)
    }


def npm_versions(text: str | None) -> set[tuple[str, str]] | None:
    """The packages and versions in package-lock.json, as npm installs them from its
    registry, a version from elsewhere, such as a tarball or git, with no version;
    none for a file that is no lock file of npm 7 or newer. The project's own folders,
    such as its workspaces, are no versions of a registry."""
    if text is None:
        return set()
    try:
        data = json.loads(text)
    except ValueError:
        return None
    packages = data.get("packages") if isinstance(data, dict) else None
    if not isinstance(packages, dict):
        return None
    found = set()
    for path, entry in packages.items():
        if "node_modules/" not in path or not isinstance(entry, dict):
            continue
        if entry.get("link"):
            continue
        name = str(entry.get("name") or path.rpartition("node_modules/")[2])
        version = str(entry.get("version") or "")
        resolved = str(entry.get("resolved") or NPM_REGISTRY)
        found.add((name, version if resolved.startswith(NPM_REGISTRY) else ""))
    return found


READERS: dict[str, Callable[[str | None], set[tuple[str, str]] | None]] = {
    "pip": pip_versions,
    "npm": npm_versions,
}


def added(root: Path, base: str) -> tuple[list[Added], list[str]]:
    """The versions that HEAD adds to the lock files of base, those of base that it
    replaces with another version of the same package, and the problems of the files
    whose versions the check can't read: each file that the refresh changes is a lock
    file of pip-compile or of npm, or the check can't date what it brings."""
    changed = sorted(
        filter(None, git(root, "diff", "--name-only", "-z", base, "HEAD").split("\0"))
    )
    pip = set(lock_files(root))
    versions: list[Added] = []
    problems: list[str] = []
    for name in changed:
        if name in pip:
            manager = "pip"
        elif PurePosixPath(name).name == NPM_LOCK:
            manager = "npm"
        else:
            problems.append(
                f"{name}: the refresh changes it, and the check can't date the "
                "versions of such a file."
            )
            continue
        new = READERS[manager](show(root, "HEAD", name))
        old = READERS[manager](show(root, base, name))
        if new is None or old is None:
            problems.append(f"{name}: the check can't read its versions.")
            continue
        versions += [
            Added(name, manager, package, version)
            for package, version in sorted(new - old)
        ]
        # npm's refresh resolves each range anew from the versions a week old, and
        # takes back a young version that main got within the range, such as the fix
        # of a vulnerability: the refresh holds until that version is a week old.
        staying = {package for package, _ in new}
        versions += [
            Added(name, manager, package, version, replaced=True)
            for package, version in sorted(old - new)
            if package in staying and version
        ]
    return versions, problems


def ask(url: str, opener: Callable[..., Any] | None = None) -> Any:
    """The answer of a registry in JSON, asked again after a failure that may pass."""
    attempt = 1
    while True:
        try:
            # The opener of urllib unless a test names another, at the time of the call.
            with (opener or urllib.request.urlopen)(url, timeout=TIMEOUT) as response:
                return json.load(response)
        except OSError as error:
            # A refusal of the registry, such as 404, stays the same when asked again.
            passing = True
            if isinstance(error, urllib.error.HTTPError):
                passing = error.code >= 500
                # The error is the answer too, and holds its body until it is closed.
                error.close()
            if not passing or attempt >= ATTEMPTS:
                raise Undated(f"{url}: {error}") from error
        attempt += 1
        github.sleep(PAUSE)


def moment(text: object) -> datetime:
    """A date of a registry, in UTC."""
    if not isinstance(text, str):
        raise Undated(f"the registry names no date but {text!r}")
    try:
        found = datetime.fromisoformat(text)
    except ValueError as error:
        raise Undated(f"the registry names {text!r} as its date") from error
    return found if found.tzinfo else found.replace(tzinfo=UTC)


def pypi_released(
    name: str, version: str, opener: Callable[..., Any] | None
) -> datetime:
    """When PyPI received the first file of a version."""
    quoted = f"{urllib.parse.quote(name)}/{urllib.parse.quote(version)}"
    answer = ask(f"{PYPI}{quoted}/json", opener)
    files = answer.get("urls") if isinstance(answer, dict) else None
    if not files:
        raise Undated("PyPI names no file of it")
    return min(moment(file.get("upload_time_iso_8601")) for file in files)


def npm_released(
    name: str, version: str, opener: Callable[..., Any] | None
) -> datetime:
    """When the registry of npm received a version, by the times of its package."""
    answer = ask(NPM_REGISTRY + urllib.parse.quote(name, safe="@"), opener)
    times = answer.get("time") if isinstance(answer, dict) else None
    if not isinstance(times, dict) or version not in times:
        raise Undated("the registry of npm names no time of it")
    return moment(times[version])


RELEASED: dict[str, Callable[[str, str, Callable[..., Any] | None], datetime]] = {
    "pip": pypi_released,
    "npm": npm_released,
}


def released(version: Added, opener: Callable[..., Any] | None = None) -> datetime:
    """When the registry received the version; Undated when the check can't learn."""
    if not version.version:
        raise Undated("it comes from no registry but by an address of its own")
    return RELEASED[version.manager](version.name, version.version, opener)


def young(
    versions: Iterable[Added],
    now: datetime,
    opener: Callable[..., Any] | None = None,
) -> list[str]:
    """What holds the refresh: each version released less than a week before now, or
    whose release the check can't learn, one sentence each."""

    def judge(version: Added) -> str:
        try:
            date = released(version, opener)
        except Undated as error:
            return f"{version}: the check can't learn its release: {error}."
        if now - date >= AGE:
            return ""
        when = f"{date.astimezone(UTC):%Y-%m-%d %H:%M} UTC"
        if version.replaced:
            return (
                f"{version.file}: the refresh replaces {version.name} "
                f"{version.version}, which main has and which came out {when}, less "
                "than a week ago, such as the fix of a vulnerability; the refresh "
                "waits until it is a week old, so that it doesn't take the fix back."
            )
        return f"{version}: released {when}, less than a week ago."

    with ThreadPoolExecutor(max_workers=8) as pool:
        return [found for found in pool.map(judge, sorted(set(versions))) if found]


def check(
    root: Path, base: str, now: datetime, opener: Callable[..., Any] | None = None
) -> tuple[int, list[str]]:
    """How many versions the refresh in HEAD adds to base, and what holds it."""
    versions, problems = added(root, base)
    count = sum(not version.replaced for version in versions)
    return count, problems + young(versions, now, opener)
