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
changed file whose versions it can't read, holds the refresh as well. A version of
main that the refresh replaces only with versions released before it, such as the fix
of a vulnerability that npm's refresh resolves back, holds it for as long as it does,
however old that version is.

The refresh of Composer merges by itself with versions of any age (decision 0174):
Composer has no cooldown, and the time of a version in composer.lock comes from
whoever published it, so nothing dates it. The check reads the versions that it adds
to composer.lock and names them as versions that nothing dates, and lets them pass,
on the branch of that refresh alone: a composer.lock that another refresh changes
holds it, as a lock file whose versions the check can't date."""

from __future__ import annotations

import json
import re
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
from blueprint.locks import AGE, NoAnswer, lock_files, read_pins

NPM_LOCK = "package-lock.json"
COMPOSER_LOCK = "composer.lock"
# The branch of the refresh of Composer, by the rule of .github/renovate-blueprint.json5.
COMPOSER_BRANCH = "renovate/composer-lock-file-maintenance"
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


class Unanswered(Undated, NoAnswer):
    """The registry gave no answer about a version, also at the last attempt: an
    OSError or a 5xx, which a later run may not get, unlike a refusal such as a 404 or
    an answer without a date (decision 1130)."""


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


def composer_versions(text: str | None) -> set[tuple[str, str]] | None:
    """The packages and versions in composer.lock, those of the tests too; none for a
    file that is no lock file of Composer."""
    if text is None:
        return set()
    try:
        data = json.loads(text)
    except ValueError:
        return None
    if not isinstance(data, dict):
        return None
    found = set()
    for section in ("packages", "packages-dev"):
        entries = data.get(section, [])
        if not isinstance(entries, list):
            return None
        for entry in entries:
            if not isinstance(entry, dict):
                return None
            name, version = entry.get("name"), entry.get("version")
            if not isinstance(name, str) or not isinstance(version, str):
                return None
            found.add((name, version))
    return found


READERS: dict[str, Callable[[str | None], set[tuple[str, str]] | None]] = {
    "pip": pip_versions,
    "npm": npm_versions,
    "composer": composer_versions,
}


def added(
    root: Path, base: str, composer: bool = False
) -> tuple[list[Added], list[str]]:
    """The versions that HEAD adds to the lock files of base, those of base that it
    replaces with another version of the same package, and the problems of the files
    whose versions the check can't read: each file that the refresh changes is a lock
    file of pip-compile, of npm or, in the refresh of Composer, of Composer, or the
    check can't tell what it brings."""
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
        elif composer and PurePosixPath(name).name == COMPOSER_LOCK:
            manager = "composer"
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
        if manager not in RELEASED:
            continue
        # npm's refresh resolves each range anew from the versions a week old, and
        # takes back a young version that main got within the range, such as the fix
        # of a vulnerability: the refresh holds for as long as it does, also once
        # that version is a week old, until Renovate builds the refresh anew. Nothing
        # dates a version of Composer, so nothing tells whether its refresh takes one
        # back.
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
            # A refusal of the registry, such as 404, stays the same when asked again;
            # a timeout (408) or a limit of the rate (429) passes like an error of 5xx.
            passing = True
            if isinstance(error, urllib.error.HTTPError):
                passing = error.code >= 500 or error.code in (408, 429)
                # The error is the answer too, and holds its body until it is closed.
                error.close()
            if not passing:
                raise Undated(f"{url}: {error}") from error
            if attempt >= ATTEMPTS:
                raise Unanswered(f"{url}: {error}") from error
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


def pypi_uploaded(
    name: str, version: str, opener: Callable[..., Any] | None = None
) -> datetime | None:
    """When PyPI received the last file of a version; none when PyPI can't date it,
    as one that it doesn't know or that has no file; Unanswered when PyPI gave no
    answer, which the refresh tells apart (decision 1130). uv's cutoff judges each
    file on its own: a version whose wheels came after the cutoff, hours after its
    sdist, would reach the lock with its sdist alone, so the refresh takes it as young
    as its last file."""
    quoted = f"{urllib.parse.quote(name)}/{urllib.parse.quote(version)}"
    try:
        answer = ask(f"{PYPI}{quoted}/json", opener)
        files = answer.get("urls") if isinstance(answer, dict) else None
        if not files:
            return None
        return max(moment(file.get("upload_time_iso_8601")) for file in files)
    except Unanswered:
        raise
    except Undated:
        return None


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


def major(version: str) -> str:
    """The major line of a version: its first number, such as 3 of 3.0.0."""
    return re.split(r"[^0-9]", version, maxsplit=1)[0]


def young(
    versions: Iterable[Added],
    now: datetime,
    opener: Callable[..., Any] | None = None,
) -> list[str]:
    """What holds the refresh: each version released less than a week before now, or
    whose release the check can't learn, one sentence each; and each version of main
    that the refresh replaces only with versions released before it, however old it
    is, since the refresh would take it back, such as the fix of a vulnerability."""

    def dated(version: Added) -> datetime | Undated:
        try:
            return released(version, opener)
        except Undated as error:
            return error

    unique = sorted(set(versions))
    with ThreadPoolExecutor(max_workers=8) as pool:
        dates = dict(zip(unique, pool.map(dated, unique), strict=True))

    def judge(version: Added) -> str:
        found = dates[version]
        if isinstance(found, Undated):
            return f"{version}: the check can't learn its release: {found}."
        when = f"{found.astimezone(UTC):%Y-%m-%d %H:%M} UTC"
        if version.replaced and taken_back(version, found):
            return (
                f"{version.file}: the refresh replaces {version.name} "
                f"{version.version}, which main has and which came out {when}, with "
                "a version released before it, and so would take back what main got, "
                "such as the fix of a vulnerability; the refresh holds until Renovate "
                "builds it anew."
            )
        if now - found >= AGE:
            return ""
        if version.replaced:
            return (
                f"{version.file}: the refresh replaces {version.name} "
                f"{version.version}, which main has and which came out {when}, less "
                "than a week ago, such as the fix of a vulnerability; the refresh "
                "waits until it is a week old."
            )
        return f"{version}: released {when}, less than a week ago."

    def taken_back(version: Added, came: datetime) -> bool:
        """Whether the refresh replaces version, of main, only with versions of the
        same package that came out before it: a refresh that moves it up, or that
        brings a version that the check can't date, is judged by the age of what it
        brings, and one that only takes out a version while another version of the
        package stays, by the age of the one that it takes out. Only a version of the
        same major line replaces it: package-lock.json keeps several lines of a
        package side by side, and a refresh that drops the line 3 while it moves the
        line 2 to an older backport takes nothing back."""
        replacements = [
            dates[other]
            for other in unique
            if not other.replaced
            and (other.file, other.name) == (version.file, version.name)
            and major(other.version) == major(version.version)
        ]
        return bool(replacements) and all(
            isinstance(other, datetime) and other < came for other in replacements
        )

    return [found for found in map(judge, unique) if found]


@dataclass(frozen=True)
class Found:
    """What the check found in a refresh: how many versions that a registry dates it
    adds, what holds it, and the versions that nothing dates, those of Composer."""

    dated: int
    problems: list[str]
    undated: list[Added]


def check(
    root: Path,
    base: str,
    now: datetime,
    opener: Callable[..., Any] | None = None,
    branch: str = "",
) -> Found:
    """What the refresh in HEAD, on this branch, adds to base: the versions that a
    registry dates, what holds the refresh, and the versions that nothing dates, which
    only the refresh of Composer, on a branch of its own, may bring."""
    versions, problems = added(root, base, branch.startswith(COMPOSER_BRANCH))
    dated = [version for version in versions if version.manager in RELEASED]
    return Found(
        dated=sum(not version.replaced for version in dated),
        problems=problems + young(dated, now, opener),
        undated=[version for version in versions if version.manager not in RELEASED],
    )
