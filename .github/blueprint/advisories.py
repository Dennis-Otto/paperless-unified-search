"""The versions that an osv-scanner.toml accepts with every advisory, as rules of
Renovate that open no branch to fix them.

OSV-Scanner reads the osv-scanner.toml in the folder of each lock file. An entry of
[[PackageOverrides]] with vulnerability.ignore accepts every advisory of one version
of a package there, such as one that another package pins exactly: Home Assistant pins
the Pillow and the uv of its test plugin. Renovate doesn't read that file, so it tried
a fix of each advisory in every run, which the lock file couldn't take, and logged
"Detected empty commit - aborting git push" each time. .github/renovate-accepted.json5
holds a rule for each such version, which .github/renovate-blueprint.json5 extends;
this module writes it and says where it and the files of OSV-Scanner disagree."""

from __future__ import annotations

import datetime
import json
import re
import tomllib
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from blueprint.diagrams import git

CONFIG = "osv-scanner.toml"
PRESET = ".github/renovate-accepted.json5"
HEADER = """\
// The versions that an osv-scanner.toml of this repository accepts with every
// advisory, as rules of Renovate, which .github/renovate-blueprint.json5 extends:
// Renovate opens no branch to fix a vulnerability of such a version, which the lock
// file couldn't take, as for a version that another package pins exactly. The command
// advisories of blueprint.py writes this file from the [[PackageOverrides]] of each
// osv-scanner.toml, and scripts/check.sh fails when they disagree; change those, not
// this file. https://github.com/Dennis-Otto/repo-blueprint
"""
# The datasource of Renovate for each ecosystem of OSV that a lock file names.
DATASOURCES = {
    "PyPI": "pypi",
    "npm": "npm",
    "Packagist": "packagist",
    "Go": "go",
    "crates.io": "crate",
    "RubyGems": "rubygems",
    "Maven": "maven",
    "NuGet": "nuget",
}
# The keys that OSV-Scanner knows. It ignores a whole file with any other key, with no
# more than a line in its log, and then reports every advisory that the file accepts.
KEYS = {
    "": {"IgnoredVulns", "PackageOverrides", "GoVersionOverride"},
    "IgnoredVulns": {"id", "ignoreUntil", "reason"},
    "PackageOverrides": {
        "name",
        "version",
        "ecosystem",
        "group",
        "ignore",
        "vulnerability",
        "license",
        "effectiveUntil",
        "reason",
    },
    "PackageOverrides.vulnerability": {"ignore"},
    "PackageOverrides.license": {"override", "ignore"},
}


@dataclass(frozen=True)
class Accepted:
    """One version of a package whose every advisory an osv-scanner.toml accepts."""

    folder: str
    name: str
    version: str
    ecosystem: str
    until: datetime.date
    reason: str

    @property
    def config(self) -> str:
        return f"{self.folder}/{CONFIG}" if self.folder else CONFIG

    def rule(self) -> dict[str, Any]:
        """The rule of Renovate: no update of this version in the lock files of its
        folder. A rule with enabled false keeps Renovate from the fix of a
        vulnerability too, where an indirect dependency would be switched on for it;
        the version moves with the package that pins it."""
        folder = f"{escaped(self.folder)}/" if self.folder else ""
        return {
            "description": f"{self.config} accepts every advisory of {self.name} "
            f"{self.version} until {self.until.isoformat()}: {self.reason}",
            "matchFileNames": [f"/^{folder}[^/]+$/"],
            "matchDatasources": [DATASOURCES[self.ecosystem]],
            "matchPackageNames": [self.name],
            "matchCurrentVersion": f"/^{escaped(self.version)}$/",
            "enabled": False,
        }


def escaped(text: str) -> str:
    """The text as a regular expression of Renovate that matches it alone; a / and a
    - stay as they are, so that the folder reads as in the description."""
    return re.sub(r"([.^$*+?()\[\]{}|\\])", r"\\\1", text)


def configs(root: Path) -> list[Path]:
    """Every osv-scanner.toml of the repository, as OSV-Scanner finds them: without
    what git ignores."""
    listed = git(
        root,
        "ls-files",
        "-z",
        "--cached",
        "--others",
        "--exclude-standard",
        "--",
        CONFIG,
        f"**/{CONFIG}",
    )
    names = {Path(name) for name in listed.split("\0") if name}
    return sorted(name for name in names if (root / name).is_file())


def tables(data: dict[str, Any], table: str) -> list[dict[str, Any]] | None:
    """The entries of [[table]], or None when the file has the key as something else,
    which OSV-Scanner can't read."""
    entries = data.get(table, [])
    if isinstance(entries, list) and all(isinstance(entry, dict) for entry in entries):
        return entries
    return None


def unknown_keys(data: dict[str, Any]) -> list[str]:
    """The keys of the file that OSV-Scanner doesn't know, by their place."""
    found = [key for key in data if key not in KEYS[""]]
    for table in ("IgnoredVulns", "PackageOverrides"):
        for entry in tables(data, table) or []:
            found += [f"{table}.{key}" for key in entry if key not in KEYS[table]]
            for inner in ("vulnerability", "license"):
                value = entry.get(inner)
                known = KEYS.get(f"{table}.{inner}", set())
                if isinstance(value, dict):
                    found += [
                        f"{table}.{inner}.{key}" for key in value if key not in known
                    ]
    return sorted(set(found))


def accepts_every_advisory(entry: dict[str, Any]) -> bool:
    vulnerability = entry.get("vulnerability")
    return entry.get("ignore") is True or (
        isinstance(vulnerability, dict) and vulnerability.get("ignore") is True
    )


def text_of(entry: dict[str, Any], key: str) -> str:
    """The text of a key of an entry, on one line, or "" when it has none."""
    value = entry.get(key)
    return " ".join(value.split()) if isinstance(value, str) else ""


def accepted_version(
    folder: str, entry: dict[str, Any], where: str
) -> Accepted | list[str]:
    """The version that an entry of [[PackageOverrides]] accepts with every advisory,
    or the problems that keep it from being one."""
    name, version, reason = (
        text_of(entry, key) for key in ("name", "version", "reason")
    )
    ecosystem, until = entry.get("ecosystem"), entry.get("effectiveUntil")
    problems = [
        f"{where} names no {key}: accept one version of one package, so that the "
        "acceptance ends with the update that moves it"
        for key, value in (("name", name), ("version", version))
        if not value
    ]
    if ecosystem not in DATASOURCES:
        problems.append(
            f"{where} names the ecosystem {ecosystem!r}, which is none of "
            f"{', '.join(DATASOURCES)}"
        )
    if not isinstance(until, datetime.date):
        problems.append(
            f"{where} has no effectiveUntil, a date such as 2026-11-22: an acceptance "
            "ends, so that a person looks at the advisories again"
        )
    if not reason:
        problems.append(f"{where} has no reason: say why no update can fix it")
    if problems or not isinstance(until, datetime.date):
        return problems
    if isinstance(until, datetime.datetime):
        until = until.date()
    return Accepted(folder, name, version, str(ecosystem), until, reason)


def read(root: Path, config: Path) -> tuple[list[Accepted], list[str]]:
    """The versions that one osv-scanner.toml accepts with every advisory, and the
    problems of the file."""
    shown = config.as_posix()
    try:
        data = tomllib.loads((root / config).read_text(encoding="utf-8"))
    except (tomllib.TOMLDecodeError, UnicodeDecodeError) as error:
        return [], [f"{shown}: OSV-Scanner can't read it: {error}"]
    malformed = [
        table
        for table in ("IgnoredVulns", "PackageOverrides")
        if tables(data, table) is None
    ]
    if malformed:
        return [], [
            f"{shown}: OSV-Scanner can't read {name}, which is a list of tables, each "
            f"written as [[{name}]]"
            for name in malformed
        ]
    keys = unknown_keys(data)
    if keys:
        return [], [
            f"{shown}: OSV-Scanner ignores the whole file for the unknown key(s) "
            f"{', '.join(keys)}, and reports every advisory that it accepts"
        ]
    folder = "" if config.parent == Path() else config.parent.as_posix()
    found: list[Accepted] = []
    problems: list[str] = []
    for number, entry in enumerate(tables(data, "PackageOverrides") or [], start=1):
        if not accepts_every_advisory(entry):
            continue
        outcome = accepted_version(
            folder, entry, f"{shown}: [[PackageOverrides]] {number}"
        )
        if isinstance(outcome, Accepted):
            found.append(outcome)
        else:
            problems += outcome
    return found, problems


def preset(accepted: list[Accepted]) -> str:
    """The text of .github/renovate-accepted.json5 for these versions."""
    rules = [entry.rule() for entry in accepted]
    body = json.dumps({"packageRules": rules}, indent=2, ensure_ascii=False)
    return f"{HEADER}{body}\n"


@dataclass(frozen=True)
class Outcome:
    """What the command found: the versions, the problems of the files of OSV-Scanner,
    and whether the preset of Renovate says the same."""

    accepted: list[Accepted]
    problems: list[str]
    expected: str
    current: str | None

    @property
    def in_step(self) -> bool:
        return self.current == self.expected


def check(root: Path) -> Outcome:
    accepted: list[Accepted] = []
    problems: list[str] = []
    for config in configs(root):
        found, wrong = read(root, config)
        accepted += found
        problems += wrong
    path = root / PRESET
    current = path.read_text(encoding="utf-8") if path.is_file() else None
    return Outcome(accepted, problems, preset(accepted), current)


def write(root: Path, outcome: Outcome) -> None:
    path = root / PRESET
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(outcome.expected, encoding="utf-8", newline="\n")
