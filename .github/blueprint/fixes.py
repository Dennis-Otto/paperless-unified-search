"""The fix bot: what a command can fix, a pull request fixes by itself.

Each explanation of .github/checks.toml and .github/checks.project.toml says in
autofix which commands fix what its check finds, or why no command can. A check that
fails runs these commands on the head of its pull request, or on main, with `fix run`,
and keeps each file that they changed as an artifact fixes-<job>. After the checks,
the fix bot of after-checks.yml takes these artifacts as data that the pull request
made: it runs nothing of them and lets through only text files that the commit has
already, by paths of plain names, outside .git and .github/workflows. `fix collect`
waits for the other checks of the commit that keep fixes, so that one commit holds
them all. Then `fix commit` commits them onto the branch of a pull request of this
repository as the release app, `fix suggest` suggests them at their lines of a pull
request from a fork, which no bot may push to, and `fix main` keeps one pull request
from bot/self-heal with the fixes of main, which merges itself.
"""

from __future__ import annotations

import base64
import difflib
import fnmatch
import hashlib
import os
import re
import shlex
import shutil
import subprocess
import sys
import time
from collections.abc import Callable, Iterable, Sequence
from dataclasses import dataclass, field
from pathlib import Path

from blueprint.coach import (
    BOT,
    FIX_HEADLINE,
    NAME_LENGTH,
    OWN_CHECKS,
    Catalog,
    check_steps,
    code,
    find_pull,
    fixed_by_bot,
    paged,
)
from blueprint.diagrams import COMMIT, PART, git
from blueprint.github import Api, GitHubError, Json
from blueprint.pages import command as tool_command
from blueprint.update import gh, graphql, post

HEADLINE = FIX_HEADLINE
# The label of a pull request whose author wants no commits of the bot.
OPT_OUT = "no-bot-fixes"
# The branch of the one pull request with the fixes of main.
BRANCH = "bot/self-heal"
# The artifacts of the checks with what their commands fixed, one for each job.
ARTIFACT = "fixes-"
# The workflows whose checks keep fixes: Lint and the CI of each kind of project.
WORKFLOWS = ("Lint", "CI (*)")
# In a command of autofix, blueprint.py of this repository, with the Python that runs.
TOOL = "{blueprint}"
# In a command of autofix, the Python that runs the bot, for a script of the repository.
PYTHON = "{python}"
# The first line of the body of each commit of the bot, before the run of the checks.
MADE_BY = "Made by the commands of .github/checks.toml in the checks of"
# How often the commands run at most, until they change nothing more.
ROUNDS = 3
# What one commit of the bot holds at most.
MOST_FILES = 100
MOST_BYTES = 1_000_000
# The suggestions on a pull request from a fork at most, in one review of each run.
SUGGESTIONS = 10
SUGGESTION_LINES = 40
SUGGESTED = "The fix bot: what the commands of the checks make of these lines."
# How long the bot waits for the other checks of a commit that keep fixes.
WAIT = 30
WAITS = 28
# The modes of the files that the bot may change: a file and an executable one. A
# commit through the API keeps the mode of a file that it changes.
FILE_MODES = frozenset({"100644", "100755"})
HUNK = re.compile(r"^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@", re.M)


# ---------------------------------------------------------------- in the check


def fixers(catalog: Catalog, root: Path, check: str | None) -> list[tuple[str, str]]:
    """The commands that fix what a check found, each with what it fixes: those of
    each step of scripts/check.sh for a check that runs it, or without a check, as on
    your machine, and those of its own for any other check."""
    if check is not None and not fnmatch.fnmatchcase(check, OWN_CHECKS):
        entry = catalog.check(check)
        return [(check, command) for command in entry.autofix] if entry else []
    found: list[tuple[str, str]] = []
    for name in check_steps(root):
        step = catalog.step(name)
        if step is not None:
            found += [(name, command) for command in step.autofix]
    return found


def shown(command: str, root: Path) -> str:
    """A command of autofix as a person runs it."""
    return command.replace(TOOL, tool_command(root)).replace(PYTHON, "python3")


def expanded(command: str, root: Path) -> str:
    """A command of autofix as the bot runs it, with the Python that runs the bot."""
    script = tool_command(root).removeprefix("python3 ")
    python = shlex.quote(Path(sys.executable).as_posix())
    return command.replace(TOOL, f"{python} {script}").replace(PYTHON, python)


def run(
    commands: Sequence[tuple[str, str]], root: Path, shell: str | None = None
) -> list[str]:
    """Runs each command in bash, in root, with its output in the log, and says how it
    went. A command that leaves findings for a person ends with an error, as ruff check
    --fix does; the next command runs all the same."""
    bash = shell or shutil.which("bash") or "bash"
    said = []
    for name, command in commands:
        result = subprocess.run(
            [bash, "-c", expanded(command, root)],
            cwd=root,
            capture_output=True,
            encoding="utf-8",
            errors="replace",
            check=False,
        )
        sys.stderr.write(result.stdout + result.stderr)
        ended = f", which ended with {result.returncode}" if result.returncode else ""
        said.append(f"- {name}: `{shown(command, root)}`{ended}")
    return said


def run_until_done(
    commands: Sequence[tuple[str, str]], root: Path, shell: str | None = None
) -> list[str]:
    """Runs the commands until they change nothing more, ROUNDS times at most, and
    says how the last round went: the fix of one step may leave work for another, as a
    fix of Ruff can for the format, and the checks of the bot's commit would find it."""
    state = git(root, "diff", "--binary", "HEAD")
    said: list[str] = []
    for _ in range(ROUNDS):
        said = run(commands, root, shell)
        now = git(root, "diff", "--binary", "HEAD")
        if now == state:
            break
        state = now
    return said


def changed(root: Path) -> list[str]:
    """The files of the commit that the work tree changed."""
    listed = git(
        root, "diff", "--name-only", "-z", "--no-renames", "--diff-filter=M", "HEAD"
    )
    return sorted(filter(None, listed.split("\0")))


def keep(root: Path, folder: Path) -> list[str]:
    """Copies each file of the commit that the commands changed into folder/files, at
    its path, for the fix bot; the folder stays away when they changed none."""
    paths = changed(root)
    for path in paths:
        target = folder / "files" / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes((root / path).read_bytes())
    return paths


def report_kept(paths: Sequence[str], kept: bool) -> list[str]:
    """What the summary of a check says of the files that its commands changed."""
    if not paths:
        return ["They changed no file."]
    listing = [f"- {code(path, NAME_LENGTH)}" for path in paths]
    if not kept:
        return ["They changed these files; commit them:", "", *listing]
    return [
        "They changed these files, which the fix bot commits onto a branch of this "
        "repository after the checks, and suggests at their lines on a pull request "
        "from a fork:",
        "",
        *listing,
    ]


def text_fixed(data: bytes) -> bytes:
    """A text file with LF line endings, no blanks at the ends of its lines and the
    line break at its end, as the step Text files of scripts/check.sh wants it."""
    data = re.sub(rb"[ \t\r]+(?=\n|\Z)", b"", data)
    return data if data.endswith(b"\n") else data + b"\n"


def is_text(data: bytes) -> bool:
    """Whether data is text, as grep -I sees it with UTF-8: no NUL and no byte that
    UTF-8 lacks."""
    try:
        data.decode("utf-8")
    except UnicodeDecodeError:
        return False
    return b"\0" not in data


def fix_text(root: Path) -> list[str]:
    """Fixes the text files that the step Text files checks: those of git, tracked or
    new, without the licenses, empty files, links and binary files. A file that isn't
    UTF-8 counts as text, as grep sees it without UTF-8; its blanks are ASCII all the
    same."""
    listed = git(root, "ls-files", "-z", "--cached", "--others", "--exclude-standard")
    fixed = []
    for path in sorted(set(filter(None, listed.split("\0")))):
        file = root / path
        if path == "LICENSE" or path.startswith("LICENSES/"):
            continue
        if file.is_symlink() or not file.is_file():
            continue
        data = file.read_bytes()
        if not data.strip(b"\n") or b"\0" in data:
            continue
        clean = text_fixed(data)
        if clean != data:
            file.write_bytes(clean)
            fixed.append(path)
    return fixed


# ---------------------------------------------------------------- what the checks kept


@dataclass(frozen=True)
class Fixes:
    """The files that the checks fixed, by their paths, and those that the bot leaves
    to a person, each with the reason."""

    files: dict[str, bytes]
    left: dict[str, str] = field(default_factory=dict)


def allowed(path: str) -> bool:
    """Whether the bot may write a file at path: plain names that stay in the
    repository, outside .git and outside the workflows, which only a person changes."""
    parts = path.split("/")
    if not all(PART.fullmatch(part.removeprefix(".")) for part in parts):
        return False
    lower = [part.lower() for part in parts]
    return lower[0] != ".git" and lower[:2] != [".github", "workflows"]


def refused(path: Path, relative: str) -> str:
    """Why the bot doesn't write a file that a check kept, if it doesn't."""
    if path.is_symlink():
        return "a link, which the bot never writes"
    if not allowed(relative):
        return "a path that the bot never writes"
    if path.stat().st_size > MOST_BYTES:
        return "too large for a commit of the bot"
    if not is_text(path.read_bytes()):
        return "no text file"
    return ""


def load(folders: Iterable[Path]) -> Fixes:
    """The fixes that the checks kept, each folder an artifact with its files under
    files/. They come from a pull request: only text files by allowed paths count, at
    most MOST_FILES of them, and the rest is left to a person."""
    files: dict[str, bytes] = {}
    left: dict[str, str] = {}
    for folder in folders:
        base = folder / "files"
        for directory, subfolders, names in os.walk(base):
            for name in sorted([*subfolders, *names]):
                path = Path(directory, name)
                relative = path.relative_to(base).as_posix()
                if path.is_dir() and not path.is_symlink():
                    continue
                # Where two checks fixed a file, the first one wins; the checks of the
                # commit of the bot find what the other one fixes, and the bot's next
                # commit, the one more that it makes onto its own, brings it.
                if relative in files:
                    continue
                reason = refused(path, relative)
                if reason:
                    left[relative] = reason
                else:
                    files[relative] = path.read_bytes()
    kept = dict(sorted(files.items())[:MOST_FILES])
    left |= {
        path: "one file too many for one commit" for path in files if path not in kept
    }
    return Fixes(kept, dict(sorted(left.items())))


def blob_sha(data: bytes) -> str:
    """The id of a file in git."""
    return hashlib.sha1(
        b"blob %d\0" % len(data) + data, usedforsecurity=False
    ).hexdigest()


def files_of(api: Api, repo: str, sha: str) -> dict[str, tuple[str, str]]:
    """The files of a commit, by their paths, each with its mode and its id."""
    tree = api.get(f"repos/{repo}/git/trees/{sha}?recursive=1") or {}
    if tree.get("truncated"):
        raise GitHubError(0, f"the files of {sha[:7]} are too many for one answer")
    return {
        str(item["path"]): (str(item.get("mode")), str(item.get("sha")))
        for item in tree.get("tree", [])
        if item.get("type") == "blob"
    }


def changeable(files: dict[str, tuple[str, str]], sha: str, fixes: Fixes) -> Fixes:
    """The fixes of files that the commit sha has, as files or executables, and that
    they change; the bot changes files, it adds none and touches no link."""
    kept: dict[str, bytes] = {}
    left = dict(fixes.left)
    for path, data in fixes.files.items():
        mode, blob = files.get(path, ("", ""))
        if mode not in FILE_MODES:
            left[path] = f"no file of {sha[:7]}; the bot changes files, it adds none"
        elif blob != blob_sha(data):
            kept[path] = data
    return Fixes(kept, dict(sorted(left.items())))


def listing(fixes: Fixes) -> list[str]:
    """The files of the fixes, and those left to a person with the reason."""
    lines = [f"- {code(path, NAME_LENGTH)}" for path in fixes.files]
    if fixes.left:
        lines += ["", "Left to you:", ""]
        lines += [
            f"- {code(path, NAME_LENGTH)}: {reason}"
            for path, reason in fixes.left.items()
        ]
    return lines


# ---------------------------------------------------------------- after the checks


@dataclass(frozen=True)
class WorkflowRun:
    id: int
    name: str
    status: str
    conclusion: str


def runs_of(api: Api, repo: str, sha: str, event: str) -> list[WorkflowRun]:
    """The newest run of each workflow of a commit that keeps fixes."""
    data = api.get(
        f"repos/{repo}/actions/runs?head_sha={sha}&event={event}&per_page=100"
    ) or {"workflow_runs": []}
    latest: dict[str, WorkflowRun] = {}
    for item in data["workflow_runs"]:
        name = str(item.get("name") or "")
        if not any(fnmatch.fnmatchcase(name, pattern) for pattern in WORKFLOWS):
            continue
        found = WorkflowRun(
            int(item["id"]),
            name,
            str(item.get("status") or ""),
            str(item.get("conclusion") or ""),
        )
        if name not in latest or found.id > latest[name].id:
            latest[name] = found
    return sorted(latest.values(), key=lambda found: found.id)


def artifacts_of(api: Api, repo: str, run_id: int) -> list[str]:
    """The names of the fixes that a run kept."""
    data = api.get(f"repos/{repo}/actions/runs/{run_id}/artifacts?per_page=100") or {}
    return sorted(
        {
            str(item.get("name"))
            for item in data.get("artifacts", [])
            if str(item.get("name") or "").startswith(ARTIFACT)
            and not item.get("expired")
        }
    )


Download = Callable[[int, str, Path], None]


def downloader(api: Api, repo: str) -> Download:
    """Downloads an artifact of a run into a folder, with the GitHub CLI."""

    def download(run_id: int, name: str, target: Path) -> None:
        gh(
            api,
            "run",
            "download",
            str(run_id),
            "--repo",
            repo,
            "--name",
            name,
            "--dir",
            str(target),
        )

    return download


@dataclass(frozen=True)
class Plan:
    """What the fix bot does with the fixes of a commit: commit them onto the branch
    of a pull request, suggest them on one from a fork, keep the pull request of main
    or close it; nothing, when the action is empty."""

    action: str
    text: str
    number: int | None = None


def collect(
    api: Api,
    repo: str,
    run_id: int,
    sha: str,
    event: str,
    folder: Path,
    download: Download,
    wait: Callable[[float], None] = time.sleep,
) -> list[Path]:
    """Downloads the fixes of every check of a commit into folder, one folder for
    each, once the checks that keep fixes have ended, so that one commit holds them
    all. A pull request whose run kept no fix has nothing for the bot."""
    if event != "push" and not artifacts_of(api, repo, run_id):
        return []
    runs = runs_of(api, repo, sha, event)
    for _ in range(WAITS):
        if all(found.status == "completed" for found in runs):
            break
        wait(WAIT)
        runs = runs_of(api, repo, sha, event)
    folders = []
    for found in runs:
        if found.status != "completed" or found.conclusion != "failure":
            continue
        for name in artifacts_of(api, repo, found.id):
            target = folder / f"{found.id}-{name}"
            download(found.id, name, target)
            folders.append(target)
    return folders


class FixLoop(Exception):
    """The commands of the checks changed the files of the bot's own commit again."""


def open_pull_of_main(api: Api, repo: str) -> Json | None:
    owner = repo.split("/")[0]
    pulls = api.get(f"repos/{repo}/pulls?state=open&head={owner}:{BRANCH}") or []
    return pulls[0] if pulls else None


def parent_of(api: Api, repo: str, sha: str) -> str:
    """The first parent of a commit."""
    commit = api.get(f"repos/{repo}/commits/{sha}") or {}
    parents = commit.get("parents") or [{}]
    return str(parents[0].get("sha") or "")


def plan(
    api: Api,
    repo: str,
    sha: str,
    event: str,
    branch: str,
    number: int | None,
    fixes: Fixes,
) -> Plan:
    """Decides what the bot does with the fixes of a commit."""
    if event == "push":
        ref = api.get(f"repos/{repo}/git/ref/heads/{branch}") or {}
        if (ref.get("object") or {}).get("sha") != sha:
            return Plan(
                "", f"{branch} moved on from {sha[:7]}; its newest run decides.\n"
            )
        if fixes.files:
            return Plan("pull-request", f"{branch} needs fixes that a command makes.\n")
        pull = open_pull_of_main(api, repo)
        if pull is None:
            return Plan("", f"{branch} needs no fix that a command makes.\n")
        return Plan(
            "close", f"{branch} needs the fixes of #{pull['number']} no more.\n"
        )
    pull = find_pull(api, repo, sha, number)
    if pull is None:
        return Plan("", f"{sha[:7]} is no longer the head of an open pull request.\n")
    number = int(pull["number"])
    labels = {str(label.get("name")) for label in pull.get("labels") or []}
    if OPT_OUT in labels:
        return Plan(
            "", f"#{number} has the label {OPT_OUT}: the bot leaves it alone.\n"
        )
    if not fixes.files:
        return Plan("", f"No command fixed what the checks of #{number} found.\n")
    head = pull.get("head") or {}
    if str((head.get("repo") or {}).get("full_name") or "") != repo:
        return Plan("suggest", f"#{number} comes from a fork.\n", number)
    # One more commit onto its own is fine: the checks of a commit end at different
    # times, and a check may fix a file after another one did. Two in a row are not.
    if fixed_by_bot(api, repo, sha) is not None and (
        fixed_by_bot(api, repo, parent_of(api, repo, sha)) is not None
    ):
        raise FixLoop(
            f"The commands of the checks changed the files of the fix bot's own commits "
            f"up to {sha[:7]} again, so they don't fix the same way twice; the bot stops."
        )
    return Plan(
        "commit", f"#{number} comes from a branch of this repository.\n", number
    )


def signed_off(api: Api, app: str, text: str) -> str:
    """The body of a commit of the release app, with the sign-off of its account."""
    user = api.get(f"users/{app}[bot]") or {}
    email = f"{user.get('id')}+{app}[bot]@users.noreply.github.com"
    return f"{text}\n\nSigned-off-by: {app}[bot] <{email}>"


def commit(api: Api, repo: str, branch: str, sha: str, fixes: Fixes, body: str) -> str:
    """Commits the fixes onto branch while it is still at sha, as whoever sends the
    request; GitHub signs the commit, and the push starts the checks."""
    data = graphql(
        api,
        COMMIT,
        {
            "input": {
                "branch": {"repositoryNameWithOwner": repo, "branchName": branch},
                "expectedHeadOid": sha,
                "message": {"headline": HEADLINE, "body": body},
                "fileChanges": {
                    "additions": [
                        {"path": path, "contents": base64.b64encode(data).decode()}
                        for path, data in fixes.files.items()
                    ]
                },
            }
        },
    )
    return str(data["createCommitOnBranch"]["commit"]["oid"])


def head_of(api: Api, repo: str, branch: str) -> str:
    ref = api.get(f"repos/{repo}/git/ref/heads/{branch}") or {}
    return str((ref.get("object") or {}).get("sha") or "")


def commit_onto_branch(
    api: Api, repo: str, branch: str, sha: str, fixes: Fixes, app: str, url: str
) -> str:
    """Commits the fixes onto the branch of a pull request of this repository, and
    says what it did."""
    fixes = changeable(files_of(api, repo, sha), sha, fixes)
    if not fixes.files:
        return "\n".join(
            ["Every fix is in the branch already.", "", *listing(fixes), ""]
        )
    if head_of(api, repo, branch) != sha:
        return f"{branch} moved on from {sha[:7]}; its checks fix it again.\n"
    body = signed_off(api, app, f"{MADE_BY} {url}")
    try:
        made = commit(api, repo, branch, sha, fixes, body)
    except GitHubError:
        if head_of(api, repo, branch) != sha:
            return f"{branch} moved on from {sha[:7]}; its checks fix it again.\n"
        raise
    lines = [f"The fix bot committed {made[:7]} onto {code(branch, NAME_LENGTH)}:", ""]
    return "\n".join([*lines, *listing(fixes), ""])


# ---------------------------------------------------------------- a pull request from a fork


@dataclass(frozen=True)
class Suggestion:
    """A change of lines of the head that GitHub can show as a suggestion: the lines
    from start to end, both counted from 1, become lines."""

    path: str
    start: int
    end: int
    lines: tuple[str, ...]

    @property
    def body(self) -> str:
        text = "\n".join(self.lines)
        longest = max((len(run) for run in re.findall(r"`+", text)), default=0)
        mark = "`" * max(3, longest + 1)
        block = (
            f"{mark}suggestion\n{text}\n{mark}"
            if self.lines
            else f"{mark}suggestion\n{mark}"
        )
        return f"{SUGGESTED}\n\n{block}"

    def comment(self) -> dict[str, Json]:
        found: dict[str, Json] = {
            "path": self.path,
            "line": self.end,
            "side": "RIGHT",
            "body": self.body,
        }
        if self.start < self.end:
            found |= {"start_line": self.start, "start_side": "RIGHT"}
        return found

    def key(self) -> tuple[str, int, int | None, str]:
        return (
            self.path,
            self.end,
            self.start if self.start < self.end else None,
            self.body,
        )


def lines_of(text: str) -> list[str]:
    """The lines of a text as GitHub numbers them: split at each newline only, not at
    a form feed or any other character that str.splitlines() takes for one."""
    parts = text.split("\n")
    return parts[:-1] if text.endswith("\n") or not text else parts


def suggestions_of(path: str, before: str, after: str) -> tuple[list[Suggestion], bool]:
    """The suggestions that turn the lines of before into those of after, and whether
    the fix changes more than they show: line endings, the end of the file, or lines
    too many for one suggestion."""
    if "\r" in before or not before.strip("\n"):
        return [], True
    old, new = lines_of(before), lines_of(after)
    rest = before.endswith("\n") != after.endswith("\n")
    found = []
    matcher = difflib.SequenceMatcher(None, old, new, autojunk=False)
    for tag, start, end, first, last in matcher.get_opcodes():
        if tag == "equal":
            continue
        lines = new[first:last]
        if start == end:
            # Lines to insert need a line of the head to hang on: the one before.
            if start == 0:
                lines, end = [*lines, old[0]], 1
            else:
                start -= 1
                lines = [old[start], *lines]
        if end - start > SUGGESTION_LINES or len(lines) > SUGGESTION_LINES:
            rest = True
            continue
        found.append(Suggestion(path, start + 1, end, tuple(lines)))
    return found, rest


def shown_lines(patch: str) -> list[tuple[int, int]]:
    """The lines of the head that the diff of a pull request shows, hunk by hunk."""
    hunks = []
    for match in HUNK.finditer(patch):
        start = int(match[1])
        count = 1 if match[2] is None else int(match[2])
        if count:
            hunks.append((start, start + count - 1))
    return hunks


def placeable(suggestion: Suggestion, hunks: Sequence[tuple[int, int]]) -> bool:
    return any(
        first <= suggestion.start and suggestion.end <= last for first, last in hunks
    )


def blob_text(api: Api, repo: str, blob: str) -> str:
    data = api.get(f"repos/{repo}/git/blobs/{blob}") or {}
    return base64.b64decode(str(data.get("content") or "")).decode("utf-8")


def suggest(api: Api, repo: str, number: int, sha: str, fixes: Fixes) -> str:
    """Suggests the fixes at their lines of a pull request from a fork, at most
    SUGGESTIONS new ones in one review; one that the bot made already stays."""
    files = files_of(api, repo, sha)
    fixes = changeable(files, sha, fixes)
    patches = {
        str(item.get("filename")): str(item.get("patch") or "")
        for item in paged(api, f"repos/{repo}/pulls/{number}/files")
    }
    made = {
        (
            str(item.get("path")),
            item.get("line"),
            item.get("start_line"),
            str(item.get("body")),
        )
        for item in paged(api, f"repos/{repo}/pulls/{number}/comments")
        if (item.get("user") or {}).get("login") == BOT
    }
    new: list[Suggestion] = []
    elsewhere: list[str] = []
    for path, data in fixes.files.items():
        before = blob_text(api, repo, files[path][1])
        found, rest = suggestions_of(path, before, data.decode("utf-8"))
        hunks = shown_lines(patches.get(path, ""))
        rest = rest or not all(placeable(item, hunks) for item in found)
        new += [
            item for item in found if placeable(item, hunks) and item.key() not in made
        ]
        if rest:
            elsewhere.append(path)
    if not new:
        return "\n".join(
            [f"No new suggestion for #{number}; the PR coach names the commands.", ""]
        )
    posted, later = new[:SUGGESTIONS], len(new) - SUGGESTIONS
    lines = [
        "### The fix bot",
        "",
        "The checks found what a command fixes, and each suggestion below is what the "
        "command makes of its lines: **Commit suggestion** takes it, and **Add "
        "suggestion to batch** takes several in one commit.",
    ]
    if later > 0:
        lines += ["", f"{later} more follow once you push these."]
    if elsewhere:
        lines += [
            "",
            "The fixes of these files change more than lines of this pull request, "
            "such as their line endings or their end; the PR coach names the command "
            "of each check that makes them on your machine:",
            "",
            *(f"- {code(path, NAME_LENGTH)}" for path in elsewhere),
        ]
    post(
        api,
        f"repos/{repo}/pulls/{number}/reviews",
        {
            "commit_id": sha,
            "event": "COMMENT",
            "body": "\n".join(lines),
            "comments": [item.comment() for item in posted],
        },
    )
    return f"Suggested {len(posted)} fix(es) on #{number}.\n"


# ---------------------------------------------------------------- main


def point(api: Api, repo: str, branch: str, sha: str) -> None:
    """Points a branch at a commit, new or moved."""
    if api.exists(f"repos/{repo}/git/ref/heads/{branch}"):
        api.patch(f"repos/{repo}/git/refs/heads/{branch}", {"sha": sha, "force": True})
    else:
        post(api, f"repos/{repo}/git/refs", {"ref": f"refs/heads/{branch}", "sha": sha})


def pull_request_body(fixes: Fixes, base: str, url: str) -> str:
    """The description of the pull request with the fixes of main."""
    return "\n".join(
        [
            f"`{base}` has findings of its checks that a command fixes. This pull "
            "request holds what the commands of `.github/checks.toml` made of these "
            "files, and merges itself once every check passes:",
            "",
            *listing(fixes),
            "",
            f"The checks of `{base}` that found them: {url}",
            "",
        ]
    )


def heal_main(
    api: Api, repo: str, sha: str, base: str, fixes: Fixes, app: str, url: str
) -> str:
    """Keeps one pull request from bot/self-heal with the fixes of main, which merges
    itself, or closes it once main needs none."""
    pull = open_pull_of_main(api, repo)
    if fixes.files:
        fixes = changeable(files_of(api, repo, sha), sha, fixes)
    if not fixes.files:
        if pull is None:
            return f"{base} needs no fix that a command makes.\n"
        number = int(pull["number"])
        post(
            api,
            f"repos/{repo}/issues/{number}/comments",
            {"body": f"`{base}` needs none of these fixes any more: {url}"},
        )
        api.patch(f"repos/{repo}/pulls/{number}", {"state": "closed"})
        api.delete(f"repos/{repo}/git/refs/heads/{BRANCH}")
        return f"{base} needs the fixes no more; closed #{number}.\n"
    # The commit starts from the commit of main that the checks fixed, on a branch of
    # its own, and the branch of the pull request moves to it in one step: a pull
    # request whose head is a commit of main would be closed by GitHub.
    scratch = f"{BRANCH}-next"
    if api.exists(f"repos/{repo}/git/ref/heads/{scratch}"):
        api.delete(f"repos/{repo}/git/refs/heads/{scratch}")
    post(api, f"repos/{repo}/git/refs", {"ref": f"refs/heads/{scratch}", "sha": sha})
    body = signed_off(api, app, f"{MADE_BY} {url}")
    made = commit(api, repo, scratch, sha, fixes, body)
    point(api, repo, BRANCH, made)
    api.delete(f"repos/{repo}/git/refs/heads/{scratch}")
    text = pull_request_body(fixes, base, url)
    if pull is None:
        created = post(
            api,
            f"repos/{repo}/pulls",
            {"title": HEADLINE, "head": BRANCH, "base": base, "body": text},
        )
        number = int(created["number"])
    else:
        number = int(pull["number"])
        api.patch(f"repos/{repo}/pulls/{number}", {"body": text})
    gh(api, "pr", "merge", str(number), "--repo", repo, "--auto", "--squash")
    return (
        f"#{number} from {BRANCH} has the fixes of {base} in {made[:7]}, and merges "
        "itself once every check passes.\n"
    )
