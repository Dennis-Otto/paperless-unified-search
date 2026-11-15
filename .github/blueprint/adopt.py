"""blueprint.py adopt: what an existing repository needs after copier copy, by command.

An existing repository takes the blueprint on a branch: copier copy writes the files of
the blueprint and leaves those of the project as they are. Some of those files then
lack what the bots need, which the guide named as steps by hand before: the version of
the latest release, which release-please counts on from, the heading Unreleased of the
changelog, which the pull requests write under, and the labels that the bots set. adopt
writes them, and only what is missing:

- The version comes from the tag of the latest release on GitHub, or from --version for
  a repository whose releases GitHub doesn't have. A repository without a latest
  release that has the tag of a version stops adopt until --version names the version
  that it released last, and a tag other than v<version>, which release-please writes,
  is named as a warning. release-please reads it from
  .release-please-manifest.json for every kind of release; the kind simple writes it
  into version.txt as well, which only that kind has. The manifest of a Python or Node
  package belongs to the project, and release-please writes the next version into it.
- CHANGELOG.md gets the heading ## Unreleased above its first release.
- .github/labels.toml, which belongs to the project, gets the labels of LABELS that it
  lacks. A copied repository has no template to read them from, so they are here, and a
  test keeps them equal to those of template/.github/labels.toml.jinja.

Before it writes, adopt checks what it needs, as the preflight of create does: Python,
Git, a folder whose origin pushes to the repository of the answers, the GitHub CLI and
the repository on GitHub, which it only asks. It then lists the workflows that the
blueprint doesn't bring, which the maintainer keeps or removes, and prints the steps
of the guide that are left. A second run finds nothing more to write.
"""

from __future__ import annotations

import json
import re
import sys
import tomllib
from dataclasses import dataclass
from pathlib import Path

from blueprint import ownership
from blueprint.bootstrap import (
    MARKS,
    REMOTE,
    SIGNED_OUT,
    Answer,
    Finding,
    Runner,
    ask,
    body,
    checked_repo,
    gh_finding,
    git_finding,
    installed,
    python_finding,
    run_command,
    stops,
)
from blueprint.changelog import HEADING, UNRELEASED
from blueprint.migrate import LABELS as LABELS_FILE

MANIFEST = ".release-please-manifest.json"
CONFIG = "release-please-config.json"
VERSION_FILE = "version.txt"
CHANGELOG = "CHANGELOG.md"
WORKFLOWS = ".github/workflows"
# The kind of release that keeps its version in version.txt, as the template has it.
SIMPLE = "simple"
# The version of a tag, such as v1.2.3, 1.2.3 or name-v1.2.3-beta.1, at its end.
VERSION = re.compile(r"(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$")
# The labels that the bots of the blueprint set, as template/.github/labels.toml.jinja
# has them: those of the group release, which the title check, the release bot, the
# branch bot and the bot-health bot set, maintenance, which the title check sets as
# well, and no-bot-fixes, with which a person keeps the fix bot away. A test keeps them
# equal to the template.
LABELS: tuple[dict[str, str], ...] = (
    {
        "name": "maintenance",
        "color": "6f42c1",
        "description": "CI, repository maintenance and release tooling",
        "group": "type",
    },
    {
        "name": "no-bot-fixes",
        "color": "c5def5",
        "description": "The fix bot leaves this pull request alone: no commit, no "
        "suggestion",
        "group": "decision",
    },
    {
        "name": "breaking-change",
        "color": "b60205",
        "description": "Changes requiring migration or user action",
        "group": "release",
    },
    {
        "name": "security",
        "color": "ee0701",
        "description": "Fixes a vulnerability or hardens security",
        "group": "release",
    },
    {
        "name": "dependencies",
        "color": "0366d6",
        "description": "Dependency updates",
        "group": "release",
    },
    {
        "name": "release",
        "color": "5319e7",
        "description": "The pull request of the release bot",
        "group": "release",
    },
    {
        "name": "autorelease: pending",
        "color": "ededed",
        "description": "Release bot: the pull request of the next release, until it "
        "is released",
        "group": "release",
    },
    {
        "name": "autorelease: tagged",
        "color": "ededed",
        "description": "Release bot: the release is created",
        "group": "release",
    },
    {
        "name": "merge-conflict",
        "color": "b60205",
        "description": "Conflicts with main: a person merges it, or the bot that made "
        "it builds it anew",
        "group": "release",
    },
    {
        "name": "blueprint-file",
        "color": "c5def5",
        "description": "Changes a file of the blueprint, with which an update can "
        "conflict",
        "group": "release",
    },
    {
        "name": "bot-failure",
        "color": "d93f0b",
        "description": "A bot or a scheduled workflow fails on the default branch; "
        "closes when it passes again",
        "group": "release",
    },
    {
        "name": "manual-step",
        "color": "1d76db",
        "description": "A bot waits for a step that only a person can take, such as "
        "an upload; closes once it is done",
        "group": "release",
    },
)
LABELS_SECTION = (
    "# The labels of the bots of the blueprint, which blueprint.py adopt added"
)
LABELS_HEAD = (
    "# Every label of the repository. The Labels workflow creates missing labels and\n"
    "# updates color and description from this file when it changes on main.\n"
)
NEW_CHANGELOG = "# Changelog\n\n## Unreleased\n"
GUIDE = "https://dennis-otto.github.io/repo-blueprint/guide.html#an-existing-repository"
NEXT = (
    "Keep or remove each workflow above; put the checks of the project alone into "
    ".github/repository.project.toml and scripts/check-project.sh.",
    "Look at the diff, commit it with a sign-off and open the pull request.",
    "Once its new checks pass, run python3 .github/blueprint.py settings apply, and "
    "remove a classic branch protection of main that it names.",
)


class AdoptError(Exception):
    """A folder that adopt can't complete, or a file that it can't read."""


@dataclass(frozen=True)
class Change:
    """A file that adopt writes: its path in the folder, what changes, its new text."""

    path: str
    summary: str
    text: str


def origin_finding(run: Runner, root: Path, repo: str) -> Finding:
    """Whether origin pushes to the repository of the answers."""
    result = run(["git", "remote", "get-url", "origin"], root)
    if result.returncode != 0:
        return Finding(
            False,
            "this folder has no origin, and adopt completes a repository on GitHub",
            f"git remote add origin https://github.com/{repo}.git",
        )
    address = result.stdout.strip()
    match = REMOTE.fullmatch(address)
    pushes_to = match[1] if match else address
    if pushes_to.lower() != repo.lower():
        return Finding(
            False,
            f"origin pushes to {pushes_to}, and the answers name {repo}",
            f"name the repository: python3 .github/blueprint.py --repo {pushes_to} "
            "adopt",
        )
    return Finding(True, f"origin pushes to {repo}")


def repository_finding(answer: Answer, repo: str) -> Finding:
    if answer.found:
        return Finding(True, f"{repo} is on GitHub")
    if answer.found is False:
        return Finding(
            False,
            f"GitHub has no repository {repo}: adopt completes one that exists",
            "for a new repository: python3 .github/blueprint.py create",
        )
    if SIGNED_OUT.search(answer.text):
        return Finding(
            False,
            "gh is not signed in to github.com",
            "gh auth login --hostname github.com",
        )
    return Finding(None, f"GitHub didn't say whether it has {repo}: {answer.text}")


def version_key(version: str) -> tuple[tuple[int, ...], bool, str]:
    """The order of versions: by their numbers, a pre-release before its release."""
    numbers, _, pre = version.partition("-")
    return tuple(int(part) for part in numbers.split(".")), not pre, pre


def latest_tag(text: str) -> tuple[str, str] | None:
    """The tag of the highest version among the tags that GitHub answered, with its
    version; None when no tag names one."""
    try:
        tags = json.loads(text)
    except ValueError:
        tags = []
    versions = [
        (str(tag["name"]), match[1])
        for tag in (tags if isinstance(tags, list) else [])
        if isinstance(tag, dict)
        and (match := VERSION.search(str(tag.get("name", "")))) is not None
    ]
    return max(versions, key=lambda item: version_key(item[1]), default=None)


def tags_finding(answer: Answer, repo: str) -> tuple[Finding, str | None]:
    """For a repository that GitHub has no latest release of: 0.0.0 when it has no
    tag of a version either, and a stop when it has one, since its releases may be
    tags alone, drafts or pre-releases, which the latest release leaves out."""
    if answer.found is not True:
        return (
            Finding(
                False,
                f"{repo} has no latest release, and GitHub didn't say which tags it "
                f"has: {answer.text}",
                "name it: python3 .github/blueprint.py adopt --version X.Y.Z",
            ),
            None,
        )
    latest = latest_tag(answer.text)
    if latest is None:
        return (
            Finding(
                True,
                f"{repo} has no release yet: the version stays 0.0.0, and the first "
                "release of the release bot is 0.1.0",
            ),
            "0.0.0",
        )
    tag, version = latest
    return (
        Finding(
            False,
            f"{repo} has no latest release on GitHub, but the tag {tag}: name the "
            "version that it released last",
            f"python3 .github/blueprint.py adopt --version {version}",
        ),
        None,
    )


def tag_format_finding(tag: str, version: str) -> list[Finding]:
    """A warning for a tag of the latest release that release-please would not have
    written: it tags v<version>, without a component, as release-please-config.json
    says."""
    if tag == f"v{version}":
        return []
    return [
        Finding(
            None,
            f"the tag {tag} is not v{version}, as release-please tags a release: it "
            "may not find this release, and then its first release lists every "
            "commit",
            f"set last-release-sha in {CONFIG} to the commit of {tag}",
        )
    ]


def release_finding(answer: Answer, repo: str) -> tuple[list[Finding], str | None]:
    """The version of the latest release of the repository, from its tag, with a
    warning for a tag that release-please would not have written; None when GitHub
    didn't say or the tag names no version."""
    if answer.found is None:
        return [
            Finding(
                False,
                f"GitHub didn't say which release of {repo} is the latest: "
                f"{answer.text}",
                "name it: python3 .github/blueprint.py adopt --version X.Y.Z",
            )
        ], None
    tag = str(body(answer).get("tag_name", ""))
    match = VERSION.search(tag)
    if match is None:
        return [
            Finding(
                False,
                f"the latest release of {repo} has the tag {tag}, which names no "
                "version",
                "name it: python3 .github/blueprint.py adopt --version X.Y.Z",
            )
        ], None
    return [
        Finding(True, f"the latest release of {repo} is {tag}"),
        *tag_format_finding(tag, match[1]),
    ], match[1]


def preflight(
    root: Path,
    repo: str,
    run: Runner,
    python: tuple[int, ...],
    version: str | None,
) -> tuple[list[Finding], str | None]:
    """What adopt needs, and the version of the latest release; git and gh only
    answer."""
    git = git_finding(run, root)
    findings = [python_finding(python), git]
    if git.ok:
        findings.append(origin_finding(run, root, repo))
    gh = installed(run, ["gh", "--version"], root)
    if gh is None:
        findings.append(
            Finding(
                False,
                "GitHub CLI is not installed",
                "install GitHub CLI: https://github.com/cli/cli#installation",
            )
        )
        return findings, version
    findings.append(gh_finding(gh.stdout))
    repository = repository_finding(ask(run, root, f"repos/{repo}"), repo)
    findings.append(repository)
    if version is not None:
        findings.append(
            Finding(True, f"the version of the latest release is {version}")
        )
        return findings, version
    if repository.ok is False:
        return findings, None
    answer = ask(run, root, f"repos/{repo}/releases/latest")
    if answer.found is False:
        # The latest release leaves out drafts and pre-releases, and a repository
        # may tag its releases without releases on GitHub: its tags say whether it
        # released before.
        tags, found = tags_finding(
            ask(run, root, f"repos/{repo}/tags?per_page=100"), repo
        )
        return [*findings, tags], found
    release, found = release_finding(answer, repo)
    return [*findings, *release], found


def release_type(root: Path) -> str:
    """The kind of release that release-please-config.json names for the repository."""
    path = root / CONFIG
    try:
        config = json.loads(path.read_text(encoding="utf-8"))
        return str(config["packages"]["."]["release-type"])
    except (OSError, ValueError, KeyError, TypeError) as error:
        raise AdoptError(
            f"{CONFIG} names no release-type of the package '.': run copier copy "
            f"first, as step 1 of the guide says ({error})"
        ) from error


def read(root: Path, path: str) -> str | None:
    """The text of a file of the folder, None when it is missing."""
    file = root / path
    if not file.is_file():
        return None
    try:
        return file.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as error:
        raise AdoptError(f"{path} can't be read as UTF-8 text: {error}") from error


def version_changes(root: Path, version: str) -> list[Change]:
    """The manifest of release-please, and version.txt for the kind simple."""
    changes = []
    manifest = json.dumps({".": version}, indent=2) + "\n"
    if read(root, MANIFEST) != manifest:
        changes.append(Change(MANIFEST, f"the version {version}", manifest))
    if release_type(root) == SIMPLE and read(root, VERSION_FILE) != f"{version}\n":
        changes.append(Change(VERSION_FILE, f"the version {version}", f"{version}\n"))
    return changes


def changelog_change(root: Path) -> list[Change]:
    """The heading Unreleased of CHANGELOG.md, above the section of the first release,
    or at the end of a changelog without one."""
    text = read(root, CHANGELOG)
    summary = "the heading ## Unreleased"
    if text is None:
        return [Change(CHANGELOG, summary, NEW_CHANGELOG)]
    if any(UNRELEASED.match(line) for line in text.splitlines()):
        return []
    first = HEADING.search(text)
    if first is None:
        return [Change(CHANGELOG, summary, text.rstrip("\n") + "\n\n## Unreleased\n")]
    place = first.start()
    return [
        Change(CHANGELOG, summary, f"{text[:place]}## Unreleased\n\n{text[place:]}")
    ]


def label_block(label: dict[str, str]) -> str:
    # A basic string of TOML takes the escapes of JSON.
    return "[[label]]\n" + "".join(
        f"{key} = {json.dumps(label[key], ensure_ascii=False)}\n"
        for key in ("name", "color", "description", "group")
    )


def labels_change(root: Path) -> list[Change]:
    """The labels of LABELS that .github/labels.toml lacks, in a section of their own
    at its end; GitHub ignores the case of a name, and so does adopt."""
    text = read(root, LABELS_FILE)
    try:
        have = tomllib.loads(text or "").get("label", [])
    except tomllib.TOMLDecodeError as error:
        raise AdoptError(f"{LABELS_FILE} is no TOML: {error}") from error
    names = {str(label.get("name", "")).casefold() for label in have}
    missing = [label for label in LABELS if label["name"].casefold() not in names]
    if not missing:
        return []
    head = text.rstrip("\n") + "\n\n" if text and text.strip() else LABELS_HEAD + "\n"
    blocks = "\n".join(label_block(label) for label in missing)
    shown = ", ".join(label["name"] for label in missing)
    return [
        Change(
            LABELS_FILE,
            f"the labels of the bots {shown}",
            f"{head}{LABELS_SECTION}\n\n{blocks}",
        )
    ]


def own_workflows(root: Path) -> list[str]:
    """The workflows of .github/workflows/ that the blueprint doesn't bring."""
    owned = ownership.load(root)
    if owned is None:
        raise AdoptError(
            f"{ownership.LIST.as_posix()} is missing: run copier copy first, as step 1 "
            "of the guide says"
        )
    folder = root / WORKFLOWS
    found = sorted(
        f"{WORKFLOWS}/{path.name}"
        for path in (folder.iterdir() if folder.is_dir() else ())
        if path.suffix in (".yml", ".yaml")
    )
    return [path for path in found if path not in owned]


def describe(repo: str, findings: list[Finding]) -> str:
    lines = [f"The preflight of adopt for {repo}:"]
    for finding in findings:
        lines.append(f"  {MARKS[finding.ok]} {finding.text}")
        if finding.fix:
            lines.append(f"      {finding.fix}")
    return "\n".join(lines)


def adopt(
    root: Path,
    *,
    repo: str | None = None,
    version: str | None = None,
    dry_run: bool = False,
    run: Runner | None = None,
    python: tuple[int, ...] | None = None,
) -> bool:
    """blueprint.py adopt: write what the repository at root lacks of the version, the
    changelog and the labels; with dry_run, print it and write nothing. False when
    the preflight found what stops it."""
    if version is not None and not VERSION.fullmatch(version):
        raise AdoptError(f"{version} is no version such as 1.2.3")
    repo = checked_repo(root, repo)
    workflows = own_workflows(root)
    findings, found = preflight(
        root, repo, run or run_command, python or sys.version_info[:2], version
    )
    print(describe(repo, findings))
    if stops(findings) or found is None:
        print(
            "Fix what is marked with ✘, then run python3 .github/blueprint.py adopt "
            "again; it wrote nothing."
        )
        return False
    changes = [
        *version_changes(root, found),
        *changelog_change(root),
        *labels_change(root),
    ]
    if not changes:
        print(
            "Nothing is missing: the version, the changelog and the labels are there."
        )
    else:
        print("adopt would write:" if dry_run else "adopt writes:")
        for change in changes:
            print(f"  {change.path}: {change.summary}")
            if not dry_run:
                try:
                    (root / change.path).write_text(
                        change.text, encoding="utf-8", newline="\n"
                    )
                except OSError as error:
                    raise AdoptError(
                        f"{change.path} can't be written: {error}"
                    ) from error
    if workflows:
        print("Workflows that the blueprint doesn't bring, to keep or to remove:")
        for path in workflows:
            print(f"  {path}")
    if dry_run:
        if changes:
            print("Run it without --dry-run to write them.")
        return True
    print(f"What is left, as the guide says ({GUIDE}):")
    for step in NEXT:
        print(f"  - {step}")
    return True
