"""What a release moves in a file that belongs to the project.

`copier update` leaves the files of _skip_if_exists in copier.yml as they are, and the
migrations of Copier would need --trust, which the blueprint never asks for. So a
release that needs such a file changed names the change here, as text that becomes
other text. The step *Files of the project* of scripts/check.sh finds what is left to
move, and `migrate --fix` moves it: the pull request of an update brings this code and
fails the step, and the fix bot commits the move onto that pull request. Each migration
stays here for a repository that updates later, whatever version it comes from, and
finds nothing once its text has moved. A project that declines one says so in the file,
as each migration describes, and is left alone.
"""

from __future__ import annotations

import json
import re
import subprocess
import tomllib
from collections.abc import Callable, Iterable, Mapping
from dataclasses import dataclass
from pathlib import Path
from typing import Any

LABELS = ".github/labels.toml"
# The settings of the issue assistant, a file of the blueprint, and the file of the
# project whose [links] hosts and [transparency] files add to theirs, which the issue
# assistant reads since its release 1.12.0 and an update never overwrites.
ISSUE_ASSISTANT = ".github/issue-assistant/config.toml"
ISSUE_ASSISTANT_PROJECT = ".github/issue-assistant/config.project.toml"
ANSWERS = ".copier-answers.yml"
# The files that a migration may move: the fix bot commits a fix of them onto the pull
# request of an update, which merges by itself (decision 0142), and of no other file
# that decides what a workflow runs. They are data that no job runs as code. The bot
# that decides is the copy of main, from the release before, so a new path comes here
# one release before the first migration of it; a test keeps each migration's path in.
MOVABLE = frozenset({LABELS, ISSUE_ASSISTANT, ISSUE_ASSISTANT_PROJECT})
# The pages of Markdown that `migrate --fix` points from a file that a release removed
# to the one that took its place, as the writes of the step Files of the project name
# them, one release before the first entry of MOVED.
PAGES = "**/*.md"
# The first label of the group contribution is the one that the digest of the issue
# assistant tells the maintainer to add, so this is their order.
CONTRIBUTION = ("good first issue", "help wanted")
CONTRIBUTION_GROUP = (
    "#   contribution set by the maintainer; the assistant's digest names candidates"
)
CONTRIBUTION_SECTION = (
    "# Contribution: the assistant's digest suggests the first of these labels"
)
BLOCK = re.compile(r"^\[\[label\]\]\n(?:[^\n#\[].*(?:\n|\Z))*\n*", re.MULTILINE)
NAME = re.compile(r'^name = "(.*)"$', re.MULTILINE)
# Exactly this line: a comment behind the group keeps the label where it is.
DECISION = re.compile(r'^group = "decision"$', re.MULTILINE)
DECISION_GROUP = re.compile(r"^(#   decision .*\n)(?!#   contribution )", re.MULTILINE)
SECTION = re.compile(r"^# ", re.MULTILINE)
# The label that keeps the fix bot away from a pull request (fixes.OPT_OUT), as a new
# repository has it since release 1.1.0, in template/.github/labels.toml.jinja.
NO_BOT_FIXES = "no-bot-fixes"
NO_BOT_FIXES_LABEL = (
    "[[label]]\n"
    f'name = "{NO_BOT_FIXES}"\n'
    'color = "c5def5"\n'
    'description = "The fix bot leaves this pull request alone: no commit, no '
    'suggestion"\n'
    'group = "decision"'
)
# The label no-bot-fixes as a label of the file, or named in a comment, which declines
# it; a label such as no-bot-fixes-docs, or a description that names it, doesn't count.
HAS_NO_BOT_FIXES = re.compile(
    rf"""^(?:name\s*=\s*["']{NO_BOT_FIXES}["']|#.*(?<![\w-]){NO_BOT_FIXES}(?![\w-]))""",
    re.MULTILINE,
)
# A label of the group decision, with a comment behind the group or without one.
IN_DECISION = re.compile(r'^group = "decision"(?:[ \t#].*)?$', re.MULTILINE)
# The hosts of config.toml by the kind of project, as
# template/.github/issue-assistant/config.toml.jinja sets them; a test keeps both equal.
LINK_HOSTS: Mapping[str, tuple[str, ...]] = {
    "home-assistant": (
        "www.home-assistant.io",
        "developers.home-assistant.io",
        "hacs.xyz",
    ),
    "nextcloud-app": ("docs.nextcloud.com", "apps.nextcloud.com"),
    "github-action": ("docs.github.com",),
    "python-package": ("docs.python.org", "pypi.org"),
    "node-package": ("nodejs.org", "www.npmjs.com"),
    "chrome-extension": ("developer.chrome.com", "chromewebstore.google.com"),
    "cloudflare-worker": ("developers.cloudflare.com",),
    "container": ("docs.docker.com",),
    "generic": (),
}
# The file of the project as a new repository gets it, for one that lacks it; a test
# keeps it equal to template/.github/issue-assistant/config.project.toml.
ISSUE_ASSISTANT_PROJECT_TEXT = """\
# The hosts and the files of the issue assistant that belong to this project. They add
# to those of config.toml, which belongs to the blueprint, and no update of the
# blueprint changes this file. https://github.com/Dennis-Otto/issue-assistant

[links]
# Hosts that links in the assistant's comments may lead to, besides this repository and
# the hosts of config.toml.
hosts = []

[transparency]
# Files besides those of config.toml that tell reporters which AI reads their issue; the
# check command makes sure they name the engine.
files = []
"""
# A line [section] of a TOML file, with a comment behind it or without one.
TABLE = re.compile(r"^\[([\w.-]+)\][ \t]*(?:#.*)?$", re.MULTILINE)


@dataclass(frozen=True)
class Migration:
    """What a release moves in one file: moved returns the text of the file with it
    moved, or the same text when nothing is left to move."""

    path: str
    summary: str
    moved: Callable[[str], str]

    @property
    def paths(self) -> tuple[str, ...]:
        """The files that the move writes."""
        return (self.path,)

    def changes(self, root: Path) -> dict[str, str]:
        """The new text of the file in root, by its path, when something moves."""
        file = root / self.path
        if not file.is_file():
            return {}
        text = file.read_text(encoding="utf-8")
        moved = self.moved(text)
        return {} if moved == text else {self.path: moved}


@dataclass(frozen=True)
class Move:
    """What a release moves from one file into others: changes returns the new text of
    each file of paths that it changes in the root of a repository, by its path, and
    nothing when nothing is left to move. The log names the first of paths."""

    paths: tuple[str, ...]
    summary: str
    changes: Callable[[Path], dict[str, str]]

    @property
    def path(self) -> str:
        """The file that the log names."""
        return self.paths[0]


def label_name(block: str) -> str:
    """The name of the label that this block of the file describes, or nothing."""
    name = NAME.search(block)
    return name[1] if name else ""


def contribution_labels(text: str) -> str:
    """The labels of CONTRIBUTION from the group decision to the group contribution,
    in a section of their own at the end of the section they come from, with the group
    named in the comment at the top of the file. Issue assistant 1.9.0 and later names
    candidates for this group; `group = "decision" # with a comment` declines that."""
    blocks = [
        block
        for block in BLOCK.finditer(text)
        if DECISION.search(block[0]) and label_name(block[0]) in CONTRIBUTION
    ]
    if not blocks:
        return text
    next_section = SECTION.search(text, blocks[-1].end())
    place = next_section.start() if next_section else len(text)
    head, rest = text[:place], text[place:]
    for block in reversed(blocks):
        head = head[: block.start()] + head[block.end() :]
    moved = sorted(blocks, key=lambda block: CONTRIBUTION.index(label_name(block[0])))
    section = "\n\n".join(
        (
            CONTRIBUTION_SECTION,
            *(
                DECISION.sub('group = "contribution"', block[0].rstrip("\n"))
                for block in moved
            ),
        )
    )
    parts = (head.rstrip("\n"), section, rest.rstrip("\n"))
    joined = "\n\n".join(part for part in parts if part) + "\n"
    return DECISION_GROUP.sub(
        lambda found: f"{found[1]}{CONTRIBUTION_GROUP}\n", joined, count=1
    )


def no_bot_fixes_label(text: str) -> str:
    """The label no-bot-fixes, which the documents of the blueprint name as the way to
    keep the fix bot away from a pull request, after the last label of the group
    decision, or at the end of the file without one. A file that names the label
    as a label of its own or in a comment such as `# no-bot-fixes: not here`, has it or
    declines it, and stays as it is."""
    if HAS_NO_BOT_FIXES.search(text):
        return text
    decisions = [
        block for block in BLOCK.finditer(text) if IN_DECISION.search(block[0])
    ]
    place = decisions[-1].end() if decisions else len(text)
    parts = (text[:place].rstrip("\n"), NO_BOT_FIXES_LABEL, text[place:].rstrip("\n"))
    return "\n\n".join(part for part in parts if part) + "\n"


def answer(root: Path, name: str) -> str:
    """An answer of Copier for the repository in root, empty without one."""
    answers = root / ANSWERS
    text = answers.read_text(encoding="utf-8") if answers.is_file() else ""
    # A dot as well: the name of a repository can have one, such as owner.github.io.
    found = re.search(rf"^{name}: *['\"]?([\w.-]+)", text, re.MULTILINE)
    return found[1] if found else ""


def toml_table(text: str) -> dict[str, Any]:
    """The TOML of text, empty when it is none: the check of the issue assistant names
    the mistake of such a file, and nothing moves out of it."""
    try:
        return tomllib.loads(text)
    except tomllib.TOMLDecodeError:
        return {}


def names(table: Mapping[str, Any], section: str, key: str) -> list[str]:
    """The list of texts key of [section] in table; empty without one or for a value of
    another type, which the check of the issue assistant names."""
    part = table.get(section)
    value = part.get(key) if isinstance(part, dict) else None
    if not isinstance(value, list) or not all(isinstance(item, str) for item in value):
        return []
    return value


def with_names(text: str, section: str, key: str, values: list[str]) -> str:
    """text with the list key of [section] set to values, on one line, as the template
    writes it; a key that the section lacks comes first in it, and a section that the
    file lacks at its end."""
    line = f"{key} = [{', '.join(json.dumps(value) for value in values)}]"
    tables = list(TABLE.finditer(text))
    for index, table in enumerate(tables):
        if table[1] != section:
            continue
        end = tables[index + 1].start() if index + 1 < len(tables) else len(text)
        value = re.compile(rf"^{key}[ \t]*=[ \t]*\[[^\]]*\]", re.MULTILINE)
        found = value.search(text, table.end(), end)
        if found:
            return text[: found.start()] + line + text[found.end() :]
        return f"{text[: table.end()]}\n{line}{text[table.end() :]}"
    return f"{text.rstrip()}\n\n[{section}]\n{line}\n"


def issue_assistant_project(root: Path) -> dict[str, str]:
    """The hosts and the files that a repository added to [links] hosts and
    [transparency] files of config.toml of the issue assistant, a file of the blueprint,
    moved to config.project.toml, its own file, whose lists the issue assistant adds to
    those of config.toml since its release 1.12.0. config.toml keeps the hosts and the
    files of the template for the kind of the project, in its order, so that the next
    update changes them without a conflict, and the issue assistant still reads every
    host and file that it read before. A kind that .copier-answers.yml doesn't name, as
    in the blueprint itself, leaves both files alone. Nothing moves that the template
    names, and the issue assistant reads the same, so there is nothing to decline."""
    config_file = root / ISSUE_ASSISTANT
    kind = answer(root, "project_type")
    if not config_file.is_file() or kind not in LINK_HOSTS:
        return {}
    config_text = config_file.read_text(encoding="utf-8")
    config = toml_table(config_text)
    own = {
        ("links", "hosts"): LINK_HOSTS[kind],
        ("transparency", "files"): (
            () if answer(root, "issue_assistant_ai") == "false" else ("SUPPORT.md",)
        ),
    }
    added = {
        place: [name for name in names(config, *place) if name not in template]
        for place, template in own.items()
    }
    if not any(added.values()):
        return {}
    project_file = root / ISSUE_ASSISTANT_PROJECT
    project_text = (
        project_file.read_text(encoding="utf-8")
        if project_file.is_file()
        else ISSUE_ASSISTANT_PROJECT_TEXT
    )
    project = toml_table(project_text)
    wanted: list[tuple[str, tuple[str, str], list[str]]] = []
    for place, moving in added.items():
        if not moving:
            continue
        kept = [name for name in names(config, *place) if name not in moving]
        config_text = with_names(config_text, *place, kept)
        there = names(project, *place)
        merged = list(dict.fromkeys((*there, *moving)))
        project_text = with_names(project_text, *place, merged)
        wanted += [
            (ISSUE_ASSISTANT, place, kept),
            (ISSUE_ASSISTANT_PROJECT, place, merged),
        ]
    # A list that the plain search of with_names can't follow, such as one with a `]`
    # in a comment, would leave a file that the issue assistant can't read: the move
    # runs unattended, so both files then stay as they are. Each file has the section
    # of a list it got, so an empty table is one that isn't TOML.
    changes = {ISSUE_ASSISTANT: config_text, ISSUE_ASSISTANT_PROJECT: project_text}
    tables = {path: toml_table(text) for path, text in changes.items()}
    if not all(tables.values()) or any(
        names(tables[path], *place) != lists for path, place, lists in wanted
    ):
        return {}
    return changes


MIGRATIONS: tuple[Migration | Move, ...] = (
    Migration(
        LABELS,
        "the labels good first issue and help wanted move to the group contribution, "
        "for which the issue assistant names candidates in its digest",
        contribution_labels,
    ),
    Migration(
        LABELS,
        f"the label {NO_BOT_FIXES} comes to the group decision: the documents of the "
        "blueprint name it as the way to keep the fix bot away from a pull request",
        no_bot_fixes_label,
    ),
    # In NEXT for release 1.19.0, which brought both paths to MOVABLE and to the writes
    # of the step Files of the project in every repository.
    Move(
        (ISSUE_ASSISTANT, ISSUE_ASSISTANT_PROJECT),
        "the hosts and the files that this project added to the issue assistant move "
        f"to {ISSUE_ASSISTANT_PROJECT}, which adds to those of the template and which "
        "an update never overwrites",
        issue_assistant_project,
    ),
)
# The migrations whose paths came to MOVABLE in this release: the bot of main in each
# repository decides from the release before, which can't know a path that the update
# brings (decision 0142), so they join MIGRATIONS in the next release, once every
# repository knows their paths.
NEXT: tuple[Migration | Move, ...] = ()


def migrate(
    root: Path, fix: bool, migrations: tuple[Migration | Move, ...] = MIGRATIONS
) -> list[Migration | Move]:
    """The migrations with something left to move in the files of root; with fix, each
    is moved, the next one on what the one before wrote."""
    found: list[Migration | Move] = []
    for migration in migrations:
        changes = migration.changes(root)
        if not changes:
            continue
        found.append(migration)
        if fix:
            for path, text in changes.items():
                (root / path).write_text(text, encoding="utf-8", newline="\n")
    return found


# The files of the blueprint that a release removed, each with the file that took its
# place, or None for one that nothing replaces. An update deletes such a file, but the
# files of the project can still name it, such as the badge of a README or a link of a
# page, and no check notices once the update has merged. moved_lines finds them, and
# `migrate --fix` points a page of Markdown at the file that took its place (decision
# 1051). An entry stays for a repository that updates later, whatever version it comes
# from. The Variants workflow fails a pull request whose update removes a file of
# .github/blueprint/owned.txt without an entry here or in MOVED_NEXT.
MOVED: Mapping[str, str | None] = {}
# The entries whose fix the bot of main can't commit yet: the writes of the step Files
# of the project came to **/*.md in this release, and the bot of a repository reads
# them from the catalog of its main, which is the release before (decision 0142). They
# join MOVED in the next release, once every repository knows them.
MOVED_NEXT: Mapping[str, str | None] = {
    # Release 1.22.0 joined Secret scan and Dependency review into Quick checks (#1026).
    ".github/workflows/dependency-review.yml": ".github/workflows/quick-checks.yml",
    ".github/workflows/secret-scan.yml": ".github/workflows/quick-checks.yml",
}
# The files of the blueprint in a repository, which an update writes anew.
OWNED = ".github/blueprint/owned.txt"
WORKFLOWS = ".github/workflows/"
# The history of the project, which rightly names a file as it was then.
HISTORY = ("CHANGELOG.md", "changelog.d/", "docs/decisions/")
# A line that the project keeps as it is says so in a comment, such as
# `<!-- blueprint: keep -->`, and is neither changed nor reported.
KEEP = "blueprint: keep"
# The branch of every repository of the blueprint; a link to another one, to a tag or
# to a commit shows the file as it was there, which stays right.
BRANCH = "main"
# A larger file is no page that names a file of the blueprint.
MOST_BYTES = 1_000_000
# The text in front of a name, from the last space, quote or bracket on.
FRONT = re.compile(r"[^\s()\[\]<>\"'`]*\Z")
# In front of a path: nothing, or the folders up to it, a page of this repository on
# GitHub at its branch, or its address on raw.githubusercontent.com.
LOCAL = re.compile(r"/?(?:\.{1,2}/)*")
RELATIVE_PAGE = re.compile(rf"(?:\.\./)+(?:blob|raw|tree|edit)/{BRANCH}/")
PAGE = re.compile(
    r"(?:https?://)?(?:www\.)?github\.com/([\w.-]+/[\w.-]+)/(?:blob|raw|tree|edit)/"
    rf"{BRANCH}/",
    re.IGNORECASE,
)
RAW = re.compile(
    r"(?:https?://)?raw\.githubusercontent\.com/([\w.-]+/[\w.-]+)/(?:refs/heads/)?"
    rf"{BRANCH}/",
    re.IGNORECASE,
)
# In front of actions/workflows/<name>, the page of a workflow and its badge: this
# repository on GitHub, or the folders up to it from a page of the branch.
REPOSITORY = re.compile(
    r"(?:(?:https?://)?(?:www\.)?github\.com)?/([\w.-]+/[\w.-]+)/", re.IGNORECASE
)
UP = re.compile(r"(?:\.\./)+")
ACTIONS = "actions/workflows/"


@dataclass(frozen=True)
class Named:
    """A place in a file of the project that names a file that a release removed: the
    text from start to end of its line, the text that takes its place there, or None
    when nothing does, and the file that took the place of the removed one, when it
    differs from that text, as the name of a workflow in the address of its badge."""

    path: str
    line: int
    start: int
    end: int
    removed: str
    new: str | None
    successor: str | None = None

    @property
    def fixable(self) -> bool:
        """Whether `migrate --fix` changes it: a page of Markdown outside .github/,
        which the fix bot may commit onto the update (decision 0142), that names a file
        that another took the place of. A workflow, a comment of the configuration of a
        bot or a script stays with a person."""
        return (
            self.new is not None
            and self.path.endswith(".md")
            and not self.path.startswith(".github/")
        )

    def describe(self) -> str:
        """What the place names, and what takes its place."""
        successor = self.successor or self.new
        if successor is None:
            return (
                f"{self.path}:{self.line}: names {self.removed}, which a release of the "
                "blueprint removed and nothing replaces"
            )
        return (
            f"{self.path}:{self.line}: names {self.removed}, which a release of the "
            f"blueprint removed: {successor} takes its place"
        )


def owned_paths(text: str) -> set[str]:
    """The files that an owned.txt lists."""
    return {
        line.strip()
        for line in text.splitlines()
        if line.strip() and not line.startswith("#")
    }


def project_files(root: Path) -> list[str]:
    """The files of the repository in root that Git tracks or would track, as
    scripts/check.sh lists them, or every file of a folder without Git."""
    if not (root / ".git").exists():
        return sorted(
            path.relative_to(root).as_posix()
            for path in root.rglob("*")
            if path.is_file() and ".git" not in path.relative_to(root).parts
        )
    listed = subprocess.run(
        ["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"],
        cwd=root,
        capture_output=True,
        check=True,
    ).stdout
    return sorted(
        {
            name.decode("utf-8", "surrogateescape")
            for name in listed.split(b"\0")
            if name
        }
    )


def page_text(file: Path) -> str | None:
    """The text of a file that can name a path, or None for a link, a file that is
    gone, a large one or one that isn't text."""
    if file.is_symlink() or not file.is_file() or file.stat().st_size > MOST_BYTES:
        return None
    data = file.read_bytes()
    try:
        return None if b"\0" in data else data.decode("utf-8")
    except UnicodeDecodeError:
        return None


def ours(found: re.Match[str] | None, repository: str) -> bool:
    """Whether an address of GitHub is one of this repository."""
    return found is not None and found[1].lower() == repository


def at_path(front: str, repository: str) -> bool:
    """Whether the text in front of a path makes it the file of this repository at its
    branch: a path of its own, or its address on GitHub."""
    return bool(
        LOCAL.fullmatch(front)
        or RELATIVE_PAGE.fullmatch(front)
        or ours(PAGE.fullmatch(front), repository)
        or ours(RAW.fullmatch(front), repository)
    )


def at_workflow(front: str, repository: str) -> bool:
    """Whether the text in front of actions/workflows/<name> makes it the page of a
    workflow of this repository, or its badge."""
    front = front.removesuffix(ACTIONS)
    return bool(UP.fullmatch(front) or ours(REPOSITORY.fullmatch(front), repository))


@dataclass(frozen=True)
class Search:
    """How one file of moved is named: the pattern of the name, whether the text in
    front of it makes it this file, and the text that takes its place."""

    pattern: re.Pattern[str]
    fits: Callable[[str, str], bool]
    removed: str
    new: str | None
    successor: str | None = None


def searches(moved: Mapping[str, str | None]) -> list[Search]:
    """The names of each file of moved: its path, and for a workflow the page of the
    workflow and its badge, whose address never holds the path."""
    found = []
    for removed, new in moved.items():
        # The path, but not the start of a longer one, such as a copy with .bak.
        path = re.compile(rf"(?<![\w.-]){re.escape(removed)}(?![\w-]|[./]\w)")
        found.append(Search(path, at_path, removed, new))
        if removed.startswith(WORKFLOWS):
            name = removed.removeprefix(WORKFLOWS)
            # Only a workflow has the page of a workflow.
            new_name = (
                new.removeprefix(WORKFLOWS)
                if new is not None and new.startswith(WORKFLOWS)
                else None
            )
            page = re.compile(rf"(?<={ACTIONS}){re.escape(name)}(?![\w-]|\.\w)")
            found.append(Search(page, at_workflow, removed, new_name, new))
    return found


def named_in(
    path: str, text: str, wanted: list[Search], repository: str
) -> list[Named]:
    """The places of one file that a search finds; a line with KEEP stays."""
    found = []
    for number, line in enumerate(text.splitlines(), 1):
        if KEEP in line:
            continue
        for search in wanted:
            for place in search.pattern.finditer(line):
                front = FRONT.search(line, 0, place.start())
                if front and search.fits(front[0], repository):
                    found.append(
                        Named(
                            path,
                            number,
                            place.start(),
                            place.end(),
                            search.removed,
                            search.new,
                            search.successor,
                        )
                    )
    return sorted(found, key=lambda item: (item.line, item.start))


def moved_lines(
    root: Path, moved: Mapping[str, str | None] | None = None
) -> list[Named]:
    """The places of the files of the project in root that name a file of moved (MOVED
    by default), which a release removed. Only in a repository made from the blueprint,
    which has .github/blueprint/owned.txt, and not in its files of the blueprint, which
    the update writes, nor in its history. A path whose file the repository has is one
    of its own, and stays."""
    owned = root / OWNED
    left = {
        removed: new
        for removed, new in (MOVED if moved is None else moved).items()
        if not (root / removed).exists()
    }
    if not owned.is_file() or not left:
        return []
    skipped = owned_paths(owned.read_text(encoding="utf-8"))
    repository = f"{answer(root, 'owner')}/{answer(root, 'project_slug')}".lower()
    wanted = searches(left)
    found: list[Named] = []
    for path in project_files(root):
        if path in skipped or path.startswith(HISTORY):
            continue
        text = page_text(root / path)
        if text is not None:
            found += named_in(path, text, wanted, repository)
    return found


def move_lines(root: Path, found: Iterable[Named]) -> list[Named]:
    """Change each place that `migrate --fix` changes to the text that takes its
    place, and give them; the rest of each line stays as the project wrote it."""
    fixed = [item for item in found if item.fixable]
    for path in dict.fromkeys(item.path for item in fixed):
        file = root / path
        lines = file.read_bytes().decode("utf-8").splitlines(keepends=True)
        for item in sorted(
            (item for item in fixed if item.path == path),
            key=lambda item: (item.line, item.start),
            reverse=True,
        ):
            line = lines[item.line - 1]
            lines[item.line - 1] = f"{line[: item.start]}{item.new}{line[item.end :]}"
        file.write_bytes("".join(lines).encode("utf-8"))
    return fixed


def unlisted(before: str, root: Path) -> list[str]:
    """The files of the blueprint that before, the owned.txt of an earlier release,
    lists, and that the update of the repository in root removed without an entry in
    MOVED or MOVED_NEXT: the guard that the Variants workflow runs on every update. A
    file that owned.txt no longer lists but the repository still has became one of
    the project, and stays."""
    owned = root / OWNED
    after = owned_paths(owned.read_text(encoding="utf-8")) if owned.is_file() else set()
    return sorted(
        path
        for path in owned_paths(before) - after
        if path not in MOVED and path not in MOVED_NEXT and not (root / path).exists()
    )
