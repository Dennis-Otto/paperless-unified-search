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
import tomllib
from collections.abc import Callable, Mapping
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
    found = re.search(rf"^{name}: *['\"]?([\w-]+)", text, re.MULTILINE)
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
)
# The migrations whose paths came to MOVABLE in this release: the bot of main in each
# repository decides from the release before, which can't know a path that the update
# brings (decision 0142), so they join MIGRATIONS in the next release, once every
# repository knows their paths.
NEXT: tuple[Migration | Move, ...] = (
    Move(
        (ISSUE_ASSISTANT, ISSUE_ASSISTANT_PROJECT),
        "the hosts and the files that this project added to the issue assistant move "
        f"to {ISSUE_ASSISTANT_PROJECT}, which adds to those of the template and which "
        "an update never overwrites",
        issue_assistant_project,
    ),
)


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
