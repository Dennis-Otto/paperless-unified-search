"""Which changed files of a pull request belong to the blueprint, and where the change
belongs instead.

The next update of the blueprint merges each of its files with the changes of the
repository, line by line, and marks a conflict where both changed the same lines; an
update with conflicts then waits for a person, and the Rollout stops at that repository.
AGENTS.md says so, but only coding agents read it, so a pull request hears it here:
the step of the check lint annotates each such file, as a notice, or as a failure with
the repository variable BLUEPRINT_OWNERSHIP set to fail, and the one report of the PR
coach names them with their way out and adds a label, also on a pull request from a
fork.

.github/blueprint/owned.txt lists the files, exactly: those that the blueprint renders
for this kind of project, without those of the project and without a workflow of this
project alone. reference.py of the blueprint writes the template of the list. The
blueprint itself has no list, so it gets no notice. The bots that bring these files
get none either: the blueprint bot's update and the pull requests of the other bots.

One file of the list has lines of the project: an update keeps the text between the two
marked lines of CONTRIBUTING.md, so a change that stays between them gets no notice.
"""

from __future__ import annotations

import base64
import re
import sys
from collections.abc import Iterable, Iterator, Sequence
from pathlib import Path
from urllib.parse import urlencode

from blueprint.coach import Part, code, escape_data, escape_property
from blueprint.github import Api, GitHubError, Json
from blueprint.update import BRANCH as UPDATE_BRANCH
from blueprint.update import SOURCE

LIST = Path(".github/blueprint/owned.txt")
# The file of the blueprint with a part of the project, and the two lines around that
# part, as template/CONTRIBUTING.md.jinja writes them.
PART_FILE = "CONTRIBUTING.md"
PART_START = (
    "<!-- The text of this project about its releases starts below this line, "
    "and every update of the blueprint keeps it. -->"
)
PART_END = "<!-- The text of this project about its releases ends above this line. -->"
# The line of a diff that starts a hunk, with the number of its first line in the new
# file.
HUNK = re.compile(r"@@ -\S+ \+(\d+)")
# The branches of the bots in this repository: the fix bot's bot/self-heal, those of the
# diagrams and of the pictures. A fork may name a branch the same, and gets the notice.
BOT_BRANCHES = "bot/"
# The type of the account of a GitHub App, on a pull request of such a bot.
BOT = "Bot"
# The only value of the variable that does anything: every other one leaves a notice.
FAIL = "fail"
LABEL = "blueprint-file"
LABEL_COLOR = "c5def5"
LABEL_DESCRIPTION = "Changes a file of the blueprint, with which an update can conflict"
# The files that the report names and that the link to a new issue carries, at most:
# a link of a few kilobytes is all that a browser and GitHub take.
MOST_NAMED = 15
ANNOTATION_TITLE = "A file of the blueprint"
ANNOTATION = (
    "The blueprint keeps this file current, and the next update that changes the same "
    "lines conflicts with this change. See the report of the PR coach for where the "
    "change belongs instead."
)
# Where a change belongs instead of a file of the blueprint, as the FAQ says.
INSTEAD = (
    "**Settings** of this repository go into `.github/repository.project.toml`.",
    "**A check** goes into `scripts/check-project.sh`, and that of the website into "
    "`scripts/check-site.sh`; their explanations go into "
    "`.github/checks.project.toml`.",
    "**Notes for coding agents** go into `.github/agents.project.md`.",
    f"**Text about the releases of this project** goes between the two marked lines "
    f"of `## Releases` in `{PART_FILE}`, which an update keeps.",
    "**A workflow** of this project alone goes into a file of its own in "
    "`.github/workflows/`.",
)


def load(root: Path) -> frozenset[str] | None:
    """The files of the blueprint of the repository in root, or None where no list
    says so, such as in the blueprint itself."""
    try:
        text = (root / LIST).read_text(encoding="utf-8")
    except FileNotFoundError:
        return None
    lines = (line.strip() for line in text.splitlines())
    return frozenset(line for line in lines if line and not line.startswith("#"))


def exempt(branch: str, head_repo: str, repo: str, author_type: str) -> bool:
    """Whether a bot brings the change of this pull request, which no notice can
    help: an account of a GitHub App, or a branch of a bot in this repository."""
    if author_type == BOT:
        return True
    return head_repo == repo and (
        branch == UPDATE_BRANCH or branch.startswith(BOT_BRANCHES)
    )


def of_the_blueprint(paths: Iterable[str], owned: frozenset[str]) -> list[str]:
    """The paths that the list names, each once, in their order on a page."""
    return sorted(set(paths) & owned)


def changed_lines(diff: str) -> Iterator[tuple[str, int]]:
    """Each line that a diff adds or removes: its sign, and its place in the new file,
    for a removed line that of the line after it."""
    place = 0
    for row in diff.split("\n"):
        if hunk := HUNK.match(row):
            place = int(hunk[1])
        elif not place:
            # The lines above the first hunk, which name the file, and a hunk of a file
            # that the diff deletes, which leaves no place.
            continue
        elif row.startswith("+"):
            yield "+", place
            place += 1
        elif row.startswith("-"):
            yield "-", place
        elif row.startswith(" "):
            place += 1


def stays_in_the_part(text: str, diff: str) -> bool:
    """Whether all that a diff of CONTRIBUTING.md adds or removes lies between its two
    marked lines, in text, the file as the diff leaves it. A marked line that the diff
    changes counts as outside, and so does a diff without a line, such as one that
    GitHub finds too long to show."""
    lines = [line.rstrip("\r") for line in text.split("\n")]
    if PART_START not in lines or PART_END not in lines:
        return False
    start = lines.index(PART_START) + 1
    end = lines.index(PART_END) + 1
    changes = list(changed_lines(diff))
    return bool(changes) and all(
        start < place < end or (sign == "-" and place == end) for sign, place in changes
    )


def part_on_github(api: Api, repo: str, sha: str, item: Json) -> bool:
    """Whether the change of a file of a pull request stays in the part of the project,
    by the file at the head of the pull request. A file that GitHub doesn't give counts
    as changed: the report then names one file too many."""
    if item.get("filename") != PART_FILE or item.get("status") != "modified":
        return False
    try:
        answer = api.get(f"repos/{repo}/contents/{PART_FILE}?ref={sha}") or {}
    except GitHubError:
        return False
    text = base64.b64decode(answer.get("content") or "").decode(errors="replace")
    return stays_in_the_part(text, str(item.get("patch") or ""))


def workflow_commands(changed: Sequence[str], fail: bool) -> list[str]:
    """The workflow command for each file, which the files of the pull request show."""
    level = "error" if fail else "notice"
    title = escape_property(ANNOTATION_TITLE)
    message = escape_data(ANNOTATION)
    return [
        f"::{level} file={escape_property(path)},title={title}::{message}"
        for path in changed
    ]


def part_stays(root: Path, diff: str) -> bool:
    """Whether the diff of CONTRIBUTING.md stays in its part, in the checkout at root,
    where a pull request may have deleted the file."""
    try:
        text = (root / PART_FILE).read_text(encoding="utf-8", errors="replace")
    except FileNotFoundError:
        return False
    return stays_in_the_part(text, diff)


def check(
    root: Path,
    listing: bytes,
    *,
    branch: str,
    head_repo: str,
    repo: str,
    author_type: str,
    mode: str,
    part_diff: str = "",
) -> int:
    """The step of the check lint: listing holds the paths that the pull request
    changes, each ending with a NUL byte, as git diff -z writes them, and part_diff
    the diff of CONTRIBUTING.md. The exit code fails only in the mode fail."""
    owned = load(root)
    if owned is None:
        print(f"No {LIST.as_posix()} here, so no file belongs to a blueprint.")
        return 0
    if exempt(branch, head_repo, repo, author_type):
        print("A bot brings this change, so no file needs a notice.")
        return 0
    paths = listing.decode(errors="replace").split("\0")
    changed = of_the_blueprint(paths, owned)
    if PART_FILE in changed and part_stays(root, part_diff):
        changed.remove(PART_FILE)
    if not changed:
        print("This pull request changes no file of the blueprint.")
        return 0
    fail = mode == FAIL
    print("\n".join(workflow_commands(changed, fail)))
    return 1 if fail else 0


def proposal_link(repo: str, changed: Sequence[str]) -> str:
    """A new issue of the blueprint, filled in through the fields of its form, whose
    author only says what the repository needs."""
    named = "\n".join(f"- `{path}`" for path in changed[:MOST_NAMED])
    query = urlencode(
        {
            "template": "feature_request.yml",
            "title": f"Change a file of the blueprint for {repo}",
            "problem": f"{repo} needs a change of these files of the blueprint:"
            f"\n\n{named}\n\nWhat it needs, and why:",
            "proposal": "What every repository made from the blueprint would get:",
        }
    )
    return f"https://github.com/{SOURCE}/issues/new?{query}"


def report_text(repo: str, changed: Sequence[str]) -> str:
    """The part of the report: which files, what follows from that, and the way out."""
    lines = [
        "#### Files of the blueprint",
        "",
        "📌 This pull request changes files that the blueprint keeps current. The next "
        "update of the blueprint that changes the same lines conflicts with this "
        "change, and that update then waits for a person:",
        "",
    ]
    lines += [f"- {code(path)}" for path in changed[:MOST_NAMED]]
    if len(changed) > MOST_NAMED:
        lines.append(f"- … and {len(changed) - MOST_NAMED} more")
    lines += ["", "Where the change belongs instead:", ""]
    lines += [f"- {line}" for line in INSTEAD]
    lines += [
        "",
        f"And what every repository needs belongs in the blueprint: [propose the "
        f"change there]({proposal_link(repo, changed)}), and its next release brings "
        "it to all of them.",
    ]
    return "\n".join(lines)


def keep_label(api: Api, repo: str, pull: Json, wanted: bool) -> None:
    """Keeps the label in step with what the head of the pull request changes. The
    label is a help: when GitHub refuses it, the report still follows."""
    number = pull["number"]
    labels = {str(label.get("name")) for label in pull.get("labels") or ()}
    if wanted == (LABEL in labels):
        return
    try:
        if not wanted:
            # Someone may have taken it off a moment ago.
            path = f"repos/{repo}/issues/{number}/labels/{LABEL}"
            api.delete(path, missing_ok=True)
            return
        # The label of a repository whose .github/labels.toml, its own, lacks it.
        if not api.exists(f"repos/{repo}/labels/{LABEL}"):
            api.post(
                f"repos/{repo}/labels",
                {"name": LABEL, "color": LABEL_COLOR, "description": LABEL_DESCRIPTION},
            )
        api.post(f"repos/{repo}/issues/{number}/labels", {"labels": [LABEL]})
    except GitHubError as error:
        print(
            f"The label {LABEL} of #{number} stays as it is: {error}", file=sys.stderr
        )


def changed_names(files: Iterable[Json]) -> list[str]:
    """Every path of the files of a pull request, the old one of a rename as well: the
    next update misses that file just the same."""
    names: list[str] = []
    for item in files:
        names += [str(item.get(key) or "") for key in ("filename", "previous_filename")]
    return names


def report_part(
    api: Api, repo: str, pull: Json, files: Iterable[Json], root: Path
) -> Part | None:
    """The part of the report, news for a pull request whose checks all pass, from the
    list of the default branch that the coach runs on, which no pull request controls."""
    owned = load(root)
    if owned is None:
        return None
    head = pull["head"]
    # A pull request from a fork that its owner deleted has no repository.
    head_repo = str((head.get("repo") or {}).get("full_name") or "")
    author_type = str((pull.get("user") or {}).get("type") or "")
    changed = (
        []
        if exempt(str(head["ref"]), head_repo, repo, author_type)
        else of_the_blueprint(
            changed_names(
                item
                for item in files
                if not part_on_github(api, repo, str(head["sha"]), item)
            ),
            owned,
        )
    )
    keep_label(api, repo, pull, bool(changed))
    return Part(report_text(repo, changed), news=True) if changed else None
