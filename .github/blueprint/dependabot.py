"""The Dependabot alerts of what an osv-scanner.toml accepts, closed with its reason
while the acceptance lasts, and open again once it ends.

OSV-Scanner reads the osv-scanner.toml in the folder of each lock file, and Renovate
the rules that blueprint/advisories.py writes from it, but Dependabot reads neither:
it kept an alert open for every advisory of a version that another package pins, such
as the 41 of the Pillow that Home Assistant pins for the tests of an integration. The
command advisories --dependabot of blueprint.py lists the open and the dismissed
alerts of the repository, and

- dismisses each open alert that an acceptance which hasn't ended covers, as a
  tolerable risk, with a comment that starts with MARKER and names the file, the end
  of the acceptance and its reason: an entry of [[PackageOverrides]] covers every
  advisory of its version of the package in the lock files of its folder, which the
  lock file must pin, and one of [[IgnoredVulns]] its advisory, by its GHSA or CVE id
  or an alias, in every package there;
- opens again each alert that it dismissed, by the MARKER of its comment, once no
  acceptance covers it: the date has passed, the entry is gone, or an update moved the
  version, so that no alert stays hidden.

The token of a workflow can't read the Dependabot alerts; the OSV-Scanner workflow asks
an app of the owner for a token with Dependabot alerts (write)."""

from __future__ import annotations

import datetime
import json
import re
import tomllib
from dataclasses import dataclass, field
from pathlib import Path, PurePosixPath
from typing import Protocol

from blueprint import advisories
from blueprint.github import Json

# The start of the comment of every alert that the command dismisses, by which it finds
# them again; a person who dismisses an alert writes something else.
MARKER = "Accepted in "
# GitHub takes a dismissed_comment of at most 280 characters.
COMMENT_LIMIT = 280
# The ecosystem of Dependabot for each ecosystem of OSV that an osv-scanner.toml names.
ECOSYSTEMS = {
    "PyPI": "pip",
    "npm": "npm",
    "Packagist": "composer",
    "Go": "go",
    "crates.io": "rust",
    "RubyGems": "rubygems",
    "Maven": "maven",
    "NuGet": "nuget",
}
# A pin of a requirements file, such as pillow==12.3.0, with extras or not.
PIN = re.compile(
    r"^([A-Za-z0-9][A-Za-z0-9._-]*)\s*(?:\[[^\]]*\])?\s*===?\s*([^\s;\\#]+)", re.M
)
# The lock files of TOML whose [[package]] tables name each version.
TOML_LOCKS = ("uv.lock", "poetry.lock", "pylock.toml")


class Client(Protocol):
    """What the command asks GitHub, as blueprint.github.Api does."""

    def get_all(self, path: str) -> list[Json]: ...

    def patch(self, path: str, body: Json) -> None: ...


def today() -> datetime.date:
    """The day in UTC, in which OSV-Scanner ends an acceptance."""
    return datetime.datetime.now(datetime.UTC).date()


def folder_of(path: str) -> str:
    parent = PurePosixPath(path).parent.as_posix()
    return "" if parent == "." else parent


def package_key(ecosystem: str, name: str) -> str:
    """A name as the ecosystem compares it: pip ignores the case and whether a name has
    -, _ or . (PEP 503), as Dependabot's Pillow and pillow show."""
    if ecosystem == "pip":
        return re.sub(r"[-_.]+", "-", name).lower()
    return name.lower()


@dataclass(frozen=True)
class Acceptance:
    """What one entry of an osv-scanner.toml accepts until a date: a version of a
    package with every advisory, or one advisory in every package."""

    config: str
    until: datetime.date
    reason: str
    # Of Dependabot, with the package and its version; empty for [[IgnoredVulns]].
    ecosystem: str = ""
    name: str = ""
    version: str = ""
    # The id of [[IgnoredVulns]]; empty for [[PackageOverrides]].
    advisory: str = ""

    def active(self, day: datetime.date) -> bool:
        """OSV-Scanner takes the date as its first moment: it reports the advisories
        again on that day."""
        return day < self.until

    def comment(self) -> str:
        text = f"{MARKER}{self.config} until {self.until.isoformat()}: {self.reason}"
        if len(text) <= COMMENT_LIMIT:
            return text
        return text[: COMMENT_LIMIT - 1].rstrip() + "…"


@dataclass(frozen=True)
class Alert:
    """A Dependabot alert, as the command needs it."""

    number: int
    state: str
    ecosystem: str
    name: str
    manifest: str
    # The GHSA id, the CVE id and every other alias, in capitals.
    ids: frozenset[str]
    comment: str
    url: str

    @classmethod
    def of(cls, data: Json) -> Alert:
        dependency = data.get("dependency") or {}
        package = dependency.get("package") or {}
        advisory = data.get("security_advisory") or {}
        ids = {
            advisory.get("ghsa_id"),
            advisory.get("cve_id"),
            *(item.get("value") for item in advisory.get("identifiers") or []),
        }
        return cls(
            number=int(data["number"]),
            state=str(data.get("state") or ""),
            ecosystem=str(package.get("ecosystem") or ""),
            name=str(package.get("name") or ""),
            manifest=str(dependency.get("manifest_path") or ""),
            ids=frozenset(str(value).upper() for value in ids if value),
            comment=str(data.get("dismissed_comment") or ""),
            url=str(data.get("html_url") or ""),
        )

    @property
    def ours(self) -> bool:
        """Whether this command dismissed it."""
        return self.state == "dismissed" and self.comment.startswith(MARKER)


def requirement_pins(text: str) -> list[tuple[str, str]]:
    return [(name, version) for name, version in PIN.findall(text)]


def toml_pins(text: str) -> list[tuple[str, str]]:
    try:
        packages = tomllib.loads(text).get("package")
    except tomllib.TOMLDecodeError:
        return []
    if not isinstance(packages, list):
        return []
    return [
        (entry["name"], entry["version"])
        for entry in packages
        if isinstance(entry, dict)
        and isinstance(entry.get("name"), str)
        and isinstance(entry.get("version"), str)
    ]


def json_pins(text: str, composer: bool) -> list[tuple[str, str]]:
    """The versions of package-lock.json, under node_modules/ in packages, or of
    composer.lock, in packages and packages-dev, with and without a v before them."""
    try:
        data = json.loads(text)
    except ValueError:
        return []
    if not isinstance(data, dict):
        return []
    if composer:
        entries = [
            (entry.get("name"), entry.get("version"))
            for section in ("packages", "packages-dev")
            if isinstance(data.get(section), list)
            for entry in data[section]
            if isinstance(entry, dict)
        ]
        found = [
            (name, version)
            for name, version in entries
            if isinstance(name, str) and isinstance(version, str)
        ]
        return found + [
            (name, version[1:]) for name, version in found if version[:1] == "v"
        ]
    packages = data.get("packages")
    if not isinstance(packages, dict):
        return []
    return [
        (key.rsplit("node_modules/", 1)[1], entry["version"])
        for key, entry in packages.items()
        if "node_modules/" in key
        and isinstance(entry, dict)
        and isinstance(entry.get("version"), str)
    ]


def pinned(root: Path, manifest: str, ecosystem: str) -> dict[str, set[str]]:
    """The versions that a lock file of the checkout pins, by the name of each package
    as the ecosystem compares it; none for a file that the command can't read, whose
    alerts then stay open."""
    if (
        not manifest
        or PurePosixPath(manifest).is_absolute()
        or ".." in manifest.split("/")
    ):
        return {}
    path = root / manifest
    try:
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError):
        return {}
    name = path.name
    if name.endswith(".txt"):
        pairs = requirement_pins(text)
    elif name in TOML_LOCKS:
        pairs = toml_pins(text)
    elif name in ("package-lock.json", "composer.lock"):
        pairs = json_pins(text, composer=name == "composer.lock")
    else:
        pairs = []
    versions: dict[str, set[str]] = {}
    for package, version in pairs:
        versions.setdefault(package_key(ecosystem, package), set()).add(version)
    return versions


@dataclass
class Manifests:
    """The versions of each lock file, read once."""

    root: Path
    read: dict[tuple[str, str], dict[str, set[str]]] = field(default_factory=dict)

    def versions(self, manifest: str, ecosystem: str) -> dict[str, set[str]]:
        key = (manifest, ecosystem)
        if key not in self.read:
            self.read[key] = pinned(self.root, manifest, ecosystem)
        return self.read[key]


def covers(acceptance: Acceptance, alert: Alert, manifests: Manifests) -> bool:
    """Whether the acceptance covers the alert: in the folder of its file, and its
    advisory, or its version of the package as the lock file pins it."""
    if folder_of(acceptance.config) != folder_of(alert.manifest):
        return False
    if acceptance.advisory:
        return acceptance.advisory.upper() in alert.ids
    key = package_key(alert.ecosystem, acceptance.name)
    if (
        acceptance.ecosystem != alert.ecosystem
        or package_key(alert.ecosystem, alert.name) != key
    ):
        return False
    versions = manifests.versions(alert.manifest, alert.ecosystem)
    return acceptance.version in versions.get(key, set())


def ignored(root: Path, config: Path) -> tuple[list[Acceptance], list[str]]:
    """The advisories that the [[IgnoredVulns]] of a file accept until a date, and why
    an entry closes no alert. A file that OSV-Scanner can't use accepts nothing, as
    advisories.read says."""
    try:
        data = tomllib.loads((root / config).read_text(encoding="utf-8"))
    except (tomllib.TOMLDecodeError, UnicodeDecodeError):
        return [], []
    entries = advisories.tables(data, "IgnoredVulns")
    if (
        entries is None
        or advisories.tables(data, "PackageOverrides") is None
        or advisories.unknown_keys(data)
    ):
        return [], []
    shown = config.as_posix()
    found: list[Acceptance] = []
    notes: list[str] = []
    for number, entry in enumerate(entries, start=1):
        advisory = advisories.text_of(entry, "id")
        reason = advisories.text_of(entry, "reason")
        until = entry.get("ignoreUntil")
        if isinstance(until, datetime.datetime):
            until = until.date()
        if advisory and reason and isinstance(until, datetime.date):
            found.append(Acceptance(shown, until, reason, advisory=advisory))
            continue
        lacks = [
            what
            for what, there in (
                ("id", advisory),
                ("reason", reason),
                ("ignoreUntil", isinstance(until, datetime.date)),
            )
            if not there
        ]
        notes.append(
            f"{shown}: [[IgnoredVulns]] {number} has no {' and no '.join(lacks)}, so "
            "its Dependabot alerts stay open: an acceptance that closes them names "
            "its advisory, says why and ends"
        )
    return found, notes


def acceptances(root: Path) -> tuple[list[Acceptance], list[str]]:
    """Every acceptance of the osv-scanner.toml files of the repository, and why an
    entry or a file closes no alert."""
    found: list[Acceptance] = []
    notes: list[str] = []
    for config in advisories.configs(root):
        versions, problems = advisories.read(root, config)
        found += [
            Acceptance(
                entry.config,
                entry.until,
                entry.reason,
                ECOSYSTEMS[entry.ecosystem],
                entry.name,
                entry.version,
            )
            for entry in versions
        ]
        single, lacking = ignored(root, config)
        found += single
        notes += problems + lacking
    return found, notes


@dataclass(frozen=True)
class Change:
    """An alert that the command dismisses with its acceptance, or opens again
    without one."""

    alert: Alert
    acceptance: Acceptance | None

    def body(self) -> Json:
        if self.acceptance is None:
            return {"state": "open"}
        return {
            "state": "dismissed",
            "dismissed_reason": "tolerable_risk",
            "dismissed_comment": self.acceptance.comment(),
        }

    def line(self, dry_run: bool) -> str:
        where = f"#{self.alert.number} {self.alert.url}".rstrip()
        if self.acceptance is None:
            verb = "would open again" if dry_run else "opened again"
            return f"- {verb} {where}: no acceptance covers it any more"
        verb = "would close" if dry_run else "closed"
        return (
            f"- {verb} {where}: accepted in {self.acceptance.config} until "
            f"{self.acceptance.until.isoformat()}"
        )


@dataclass(frozen=True)
class Outcome:
    changes: list[Change]
    # The alerts that it dismissed before and that an acceptance still covers.
    kept: int
    # The open alerts that no acceptance covers.
    uncovered: int
    notes: list[str]

    def report(self, repo: str, dry_run: bool) -> list[str]:
        """What the command did, by the number and the link of each alert alone: the
        log of a public repository shows no more of an alert than its maintainers'
        page does."""
        lines = [
            f"The Dependabot alerts of {repo} and the advisories that its "
            "osv-scanner.toml files accept"
            + (" (a dry run: nothing changed):" if dry_run else ":")
        ]
        lines += [change.line(dry_run) for change in self.changes]
        closed = sum(change.acceptance is not None for change in self.changes)
        opened = len(self.changes) - closed
        lines.append(
            f"{closed} {'to close' if dry_run else 'closed'}, {opened} "
            f"{'to open again' if dry_run else 'opened again'}, {self.kept} closed "
            f"before and still accepted, {self.uncovered} open that no acceptance "
            "covers."
        )
        lines += self.notes
        return lines


def sync(client: Client, repo: str, root: Path, *, dry_run: bool = False) -> Outcome:
    """Dismisses the open alerts that an acceptance covers and opens again those that
    it dismissed and that none covers any more; with dry_run, only says so."""
    found, notes = acceptances(root)
    day = today()
    active = [acceptance for acceptance in found if acceptance.active(day)]
    manifests = Manifests(root)
    alerts = [
        Alert.of(data)
        for data in client.get_all(
            f"repos/{repo}/dependabot/alerts?state=open,dismissed"
        )
    ]
    changes: list[Change] = []
    kept = uncovered = 0
    for alert in sorted(alerts, key=lambda alert: alert.number):
        if alert.state != "open" and not alert.ours:
            continue
        cover = next(
            (
                acceptance
                for acceptance in active
                if covers(acceptance, alert, manifests)
            ),
            None,
        )
        if alert.state == "open" and cover is None:
            uncovered += 1
        elif alert.state == "open" or cover is None:
            changes.append(Change(alert, cover))
        else:
            kept += 1
    if not dry_run:
        for change in changes:
            client.patch(
                f"repos/{repo}/dependabot/alerts/{change.alert.number}", change.body()
            )
    return Outcome(changes, kept, uncovered, notes)
