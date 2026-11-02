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

import re
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path

LABELS = ".github/labels.toml"
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


@dataclass(frozen=True)
class Migration:
    """What a release moves in one file: moved returns the text of the file with it
    moved, or the same text when nothing is left to move."""

    path: str
    summary: str
    moved: Callable[[str], str]


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


MIGRATIONS = (
    Migration(
        LABELS,
        "the labels good first issue and help wanted move to the group contribution, "
        "for which the issue assistant names candidates in its digest",
        contribution_labels,
    ),
)


def migrate(
    root: Path, fix: bool, migrations: tuple[Migration, ...] = MIGRATIONS
) -> list[Migration]:
    """The migrations with something left to move in the files of root; with fix, each
    is moved, the next one on what the one before wrote."""
    found = []
    for migration in migrations:
        path = root / migration.path
        if not path.is_file():
            continue
        text = path.read_text(encoding="utf-8")
        moved = migration.moved(text)
        if moved == text:
            continue
        found.append(migration)
        if fix:
            path.write_text(moved, encoding="utf-8", newline="\n")
    return found
