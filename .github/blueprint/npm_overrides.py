"""The overrides of npm that an update has made needless.

An entry of `overrides` in package.json raises a package that a dependency asks for
in a version with a vulnerability, such as minimatch 10 under ESLint, whose fix is
only in minimatch 11. Once the update of that dependency asks for the fixed version
itself, the override is needless, and a test of the project that wants it gone fails
the update of Renovate. The fix bot leaves package.json and package-lock.json to a
person on a pull request of a bot, rightly, so Renovate runs this module after its
update, in its own checkout, by a rule of .github/renovate-blueprint.json5.

For each entry that names a version, also one nested below the package that asks
for it, the module removes the entry and lets npm write the lock file anew, with
--package-lock-only and --ignore-scripts, so that no code of a package runs. It keeps
the removal only when every copy of the package in the new lock file is still at or
above the lowest version that the override allowed, or sat at the same path below it
with the override too, outside its reach, at the same version, and when the new lock file brings no
version that the old one lacked: Renovate gives npm --before= only on its own call,
and this one must not let a version younger than a week into an update that merges by
itself. An entry that caps a package, with < or <= or a range such as 1.2.3 - 1.4.0,
stays without npm: it holds the package back from a version that no lock file of
today shows. Otherwise it puts both files back byte for byte."""

from __future__ import annotations

import json
import re
import subprocess
from collections.abc import Callable
from copy import deepcopy
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Any

from blueprint.diagrams import git

MANIFEST = "package.json"
LOCK = "package-lock.json"
# npm writes the lock file alone and runs no script of a package; the audit and the
# call for funds ask the registry for nothing that the lock file needs.
INSTALL = (
    "install",
    "--package-lock-only",
    "--ignore-scripts",
    "--no-audit",
    "--no-fund",
)
# The key of a note in package.json, which npm leaves alone.
NOTE = "//"
COMPARATOR = re.compile(
    r"(?P<operator>>=|<=|>|<|=|\^|~>?)?\s*v?"
    r"(?P<major>\d+|[xX*])(?:\.(?P<minor>\d+|[xX*]))?(?:\.(?P<patch>\d+|[xX*]))?"
    r"(?P<prerelease>-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?"
)

# A version as a key that sorts as semver does: a prerelease comes before its release.
Version = tuple[int, int, int, int, tuple[tuple[int, int | str], ...]]
ZERO: Version = (0, 0, 0, 0, ())
# Runs npm in the folder of a package.json, as npm_in() does.
Npm = Callable[[Path], "subprocess.CompletedProcess[str]"]


@dataclass(frozen=True)
class Override:
    """One entry of overrides: the keys from overrides down to it, the package that it
    sets and what it sets it to."""

    keys: tuple[str, ...]
    name: str
    spec: str

    def label(self) -> str:
        """The override as the output names it, with the packages it is nested in."""
        parents = [package_name(key) for key in self.keys[:-1]]
        if parents and self.keys[-1] == ".":
            parents.pop()
        below = f" below {' > '.join(parents)}" if parents else ""
        return f"{self.name} {self.spec}{below}"


@dataclass(frozen=True)
class Outcome:
    """What happened to one override of one package.json: whether npm needs it, why,
    and whether a note // beside overrides may speak of it."""

    manifest: str
    override: Override
    needless: bool
    reason: str
    note: bool = False


def version_of(text: str) -> Version | None:
    """The version of a lock file, such as 11.0.8 or 2.0.0-rc.1; None for anything
    else, such as the address of a repository."""
    match = COMPARATOR.fullmatch(text.strip())
    if match is None or match["operator"]:
        return None
    return comparable(match)


def comparable(match: re.Match[str]) -> Version:
    """The version of a comparator, with 0 for each part that it leaves open."""
    parts = [match["major"], match["minor"] or "0", match["patch"] or "0"]
    major, minor, patch = (int(part) if part.isdigit() else 0 for part in parts)
    prerelease = match["prerelease"]
    if not prerelease:
        return (major, minor, patch, 1, ())
    identifiers = tuple(
        (0, int(part)) if part.isdigit() else (1, part)
        for part in prerelease[1:].split(".")
    )
    return (major, minor, patch, 0, identifiers)


def caps(spec: str) -> bool:
    """Whether a range of an override sets a ceiling, with < or <= or as a range such
    as 1.2.3 - 1.4.0: it holds a package back from a version, which no lock file of
    today can show to be needless."""
    for alternative in spec.split("||"):
        if " - " in alternative:
            return True
        for word in alternative.split():
            match = COMPARATOR.fullmatch(word)
            if match is not None and match["operator"] in ("<", "<="):
                return True
    return False


def floor_of(spec: str) -> Version | None:
    """The lowest version that a version or a range of an override allows, such as
    11.0.3 for ^11.0.3; None for what names no version, such as $eslint, npm:other@1
    or the address of a repository, and for a range that caps the package."""
    if caps(spec):
        return None
    floors = []
    for alternative in spec.split("||"):
        lows = []
        for word in alternative.split():
            match = COMPARATOR.fullmatch(word)
            if match is None:
                return None
            lows.append(comparable(match))
        floors.append(max(lows, default=ZERO))
    return min(floors)


def package_name(key: str) -> str:
    """The package that a key of overrides names, without the range after its @, such
    as minimatch for minimatch@^3 and @scope/name for @scope/name@1."""
    at = key.find("@", 1)
    return key if at == -1 else key[:at]


def overrides_of(entries: dict[str, Any], keys: tuple[str, ...] = ()) -> list[Override]:
    """Every entry of overrides that names something, nested ones too: a key . sets
    the package that the entry around it names."""
    found = []
    for key, value in entries.items():
        if isinstance(value, dict):
            found += overrides_of(value, (*keys, key))
        elif isinstance(value, str):
            name = package_name(keys[-1] if key == "." and keys else key)
            found.append(Override((*keys, key), name, value))
    return found


def without(manifest: dict[str, Any], override: Override) -> dict[str, Any]:
    """The package.json without the override, and without what it leaves empty."""
    copy = deepcopy(manifest)
    trail = [copy["overrides"]]
    for key in override.keys[:-1]:
        trail.append(trail[-1][key])
    del trail[-1][override.keys[-1]]
    for parent, key in zip(
        reversed(trail[:-1]), reversed(override.keys[:-1]), strict=True
    ):
        if parent[key]:
            break
        del parent[key]
    if not copy["overrides"]:
        del copy["overrides"]
    return copy


def written(manifest: dict[str, Any], original: str) -> str:
    """The package.json as npm writes it, with the indent, the line ends and the last
    newline of the original."""
    indent = re.search(r"\n([ \t]+)\S", original)
    text = json.dumps(manifest, indent=indent[1] if indent else 2, ensure_ascii=False)
    if original.endswith("\n"):
        text += "\n"
    return text.replace("\n", "\r\n") if "\r\n" in original else text


def unique_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    """An object of JSON whose keys are each there once, which json.loads otherwise
    merges, so that the package.json written anew would lose one."""
    keys = [key for key, _ in pairs]
    if len(set(keys)) != len(keys):
        raise ValueError("a key twice in one object")
    return dict(pairs)


def copies(lock: dict[str, Any], name: str) -> dict[str, Version | None]:
    """The version of every copy of the package in the lock file, by its path."""
    found = {}
    for path, entry in lock.get("packages", {}).items():
        folder = path.rpartition("node_modules/")[2]
        if path and entry.get("name", folder) == name and "version" in entry:
            found[path] = version_of(entry["version"])
    return found


def versions(lock: dict[str, Any]) -> set[str]:
    """Every package of the lock file with its version, as name@version."""
    return {
        f"{entry.get('name', path.rpartition('node_modules/')[2])}@{entry['version']}"
        for path, entry in lock.get("packages", {}).items()
        if path and "version" in entry
    }


def below(version: Version | None, floor: Version) -> bool:
    """Whether a copy is below the floor; one with no version that compares is."""
    return version is None or version < floor


def judge(
    old: dict[str, Any], new: dict[str, Any], override: Override, floor: Version
) -> tuple[bool, str]:
    """Whether the lock file that npm wrote without the override keeps every copy of
    its package where the override held it and brings no version that the old one
    lacked, and what npm takes without it."""
    before = copies(old, override.name)
    after = copies(new, override.name)
    for path, version in sorted(after.items()):
        # A copy outside the reach of the override, below its floor before, may stay
        # there, but only as the very same version.
        if below(version, floor) and not (
            path in before
            and below(before[path], floor)
            and old["packages"][path]["version"] == new["packages"][path]["version"]
        ):
            taken = new["packages"][path]["version"]
            return False, (
                f"without it npm takes {override.name} {taken} at {path}, below "
                f"{override.spec}"
            )
    brought = sorted(versions(new) - versions(old))
    if brought:
        return False, (
            f"without it npm brings {', '.join(brought)}, which the lock file doesn't "
            "have, and only Renovate's own call of npm keeps to versions a week old"
        )
    taken = sorted({new["packages"][path]["version"] for path in after})
    return True, (
        f"without it npm takes {override.name} {', '.join(taken)}"
        if taken
        else f"without it npm takes no {override.name}"
    )


def manifests(root: Path) -> list[str]:
    """Every package.json that git keeps, in any folder, that has overrides and a
    package-lock.json beside it."""
    listed = git(root, "ls-files", "-z", "--cached", "--others", "--exclude-standard")
    found = []
    for name in sorted(set(filter(None, listed.split("\0")))):
        path = PurePosixPath(name)
        if (
            path.name == MANIFEST
            and "node_modules" not in path.parts
            and (root / name).is_file()
            and (root / path.parent / LOCK).is_file()
            and '"overrides"' in (root / name).read_text(encoding="utf-8")
        ):
            found.append(name)
    return found


def npm_in(npm: str) -> Npm:
    """npm, which writes the lock file of a folder anew without running any code of a
    package."""

    def run(folder: Path) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [npm, *INSTALL],
            cwd=folder,
            capture_output=True,
            check=False,
            text=True,
            encoding="utf-8",
            errors="replace",
        )

    return run


def needless(root: Path, name: str, npm: Npm, *, fix: bool) -> list[Outcome]:
    """Tries each override of root/name without it; with fix, keeps each removal that
    npm doesn't need, and otherwise puts both files back byte for byte."""
    manifest_path = root / name
    lock_path = manifest_path.parent / LOCK
    text = manifest_path.read_bytes().decode("utf-8")
    try:
        manifest = json.loads(text, object_pairs_hook=unique_keys)
    except ValueError as error:
        raise ValueError(f"{name} isn't JSON that it can write back: {error}") from None
    entries = manifest.get("overrides")
    if not isinstance(entries, dict):
        return []
    outcomes = []
    for override in overrides_of(entries):
        if caps(override.spec):
            outcomes.append(
                Outcome(name, override, False, "it caps the package, so it stays")
            )
            continue
        floor = floor_of(override.spec)
        if floor is None:
            outcomes.append(
                Outcome(name, override, False, "it names no version, so it stays")
            )
            continue
        kept_text, kept_lock = manifest_path.read_bytes(), lock_path.read_bytes()
        old = json.loads(kept_lock)
        trial = without(manifest, override)
        put_back = True
        try:
            manifest_path.write_bytes(written(trial, text).encode("utf-8"))
            answer = npm(manifest_path.parent)
            if answer.returncode:
                lines = (answer.stderr or answer.stdout).strip().splitlines()
                outcome = Outcome(
                    name,
                    override,
                    False,
                    f"npm failed without it: {lines[-1] if lines else 'no message'}",
                )
            else:
                ok, reason = judge(
                    old, json.loads(lock_path.read_bytes()), override, floor
                )
                outcome = Outcome(name, override, ok, reason, ok and NOTE in manifest)
                put_back = not (ok and fix)
        finally:
            if put_back:
                manifest_path.write_bytes(kept_text)
                lock_path.write_bytes(kept_lock)
        if not put_back:
            text = manifest_path.read_bytes().decode("utf-8")
            manifest = json.loads(text, object_pairs_hook=unique_keys)
        outcomes.append(outcome)
    return outcomes
