"""The fix bot: what a command can fix, a pull request fixes by itself.

Each explanation of .github/checks.toml and .github/checks.project.toml says in
autofix which commands fix what its check finds, or why no command can. A check that
fails runs these commands on the head of its pull request, or on main, with `fix run`,
and keeps each file that they changed as an artifact fixes-<job>. After the checks,
the fix bot of after-checks.yml takes these artifacts as data that the pull request
made: it runs nothing of them and lets through only text files that the commit has
already, by paths of plain names, outside .git and .github/workflows. It reads the
fixes of every workflow in the list of after-checks.yml of main, also one of the
project's own, and of no other. `fix collect` waits for those whose files keep fixes,
so that one commit holds them all. Then `fix commit` commits them onto the branch of a
pull request of this repository as the release app, `fix suggest` suggests them at
their lines of a pull request from a fork, which the bot after the checks never pushes
to, and `fix main` keeps one pull request from bot/self-heal with the fixes of main,
which merges itself.
A run that still runs after the wait leaves the commit to the job after its own end: a
commit now would cancel it, and use up a commit of the bot for a part of the fixes.
Docs keeps a fix of main alone, and while it passes: the first run of the accessibility
check with the line baseline = true of .github/a11y-accepted.toml, which accepts what
the website has.

Lint and the CI also run on main each week, by their schedule or by hand, as bot health
starts a schedule that GitHub missed, so that what no commit changes, such as a new rule
of a tool, repairs itself before it fails the next pull request. Only such a run that
failed starts the bot, so a quiet week takes no runner of it.

The check of the title runs no fixer, as it runs nothing of a pull request, so `fix own`
works from what the API says of a pull request from a branch of this repository, and
only there. It sets the title that the PR coach suggests when only its form differs,
because the type and the breaking mark decide the version. And it commits one draft of
the entry of the changelog from that title where the check asks for an entry: the one
new file that the bot ever adds, which it names and writes itself, in changelog.d/.

A person with write access to the repository starts the bot by the command /fix, the
first line of a comment on a pull request, with commands.yml. `fix command` takes the
fixes that the ended checks of its head kept, as data, as `fix collect` does, and `fix
commit` commits them onto its branch as the release app: also onto the branch of a fork
whose author allows edits by maintainers, then only for the files that the pull request
changes and none that decides what a workflow runs. Whether GitHub lets the app push
there isn't settled, so a refusal, and a fork that allows no edits, gets the suggestions
at their lines that a fork gets without the command, and the answer to the command says
so. Nothing of the fork runs with the token: the job checks out main alone.
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
from urllib.parse import quote

from blueprint.bot_health import started_after
from blueprint.branches import decides_what_runs
from blueprint.changelog import FRAGMENT_NAME, FRAGMENTS
from blueprint.coach import (
    ALIASES,
    BOT,
    DRAFT_HEADLINE,
    FINDING_LENGTH,
    FIX_HEADLINE,
    NAME_LENGTH,
    OWN_CHECKS,
    TYPES,
    Catalog,
    check_steps,
    clean,
    code,
    corrected_title,
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
# The events of the runs of main that keep fixes: a push, and the weekly run by its
# schedule or by hand, as bot health starts a schedule that GitHub missed.
MAIN_EVENTS = ("push", "schedule", "workflow_dispatch")
# The file whose list names the workflows that the bot reads, as main has it.
AFTER_CHECKS = ".github/workflows/after-checks.yml"
# The name of a workflow, and a line of a workflow that names an artifact of fixes.
WORKFLOW_NAME = re.compile(r"""^name:[ \t]*["']?(.+?)["']?[ \t\r]*$""", re.M)
KEEPS_FIXES = re.compile(rf"""^[ \t]+name:[ \t]*["']?{ARTIFACT}""", re.M)
# The workflow that keeps a fix only on main, and also when it passes: Docs, whose first
# run of every page accepts the violations that the website has, and turns no check red.
MAIN_WORKFLOW = "Docs"
# In a command of autofix, blueprint.py of this repository, with the Python that runs.
TOOL = "{blueprint}"
# In a command of autofix, the Python that runs the bot, for a script of the repository.
PYTHON = "{python}"
# The first line of the body of each commit of the bot, before the run of the checks.
MADE_BY = "Made by the commands of autofix in the checks of"
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
# A title of a change for users, for which the check of the title asks an entry: its
# type, its scope, its breaking mark and its words.
FOR_USERS = re.compile(r"^(feat|fix|perf)(?:\(([a-z0-9._/-]+)\))?(!)?: (.*)$")
# The scopes of updates of dependencies, which write no entry.
UPDATES = frozenset({"deps", "deps-dev"})
# The kind of change of a draft, by the type of its title or by its breaking mark.
DRAFT_KINDS = {"feat": "Features", "fix": "Bug fixes", "perf": "Performance"}
DRAFT_BREAKING = "Breaking changes"
# What a draft never holds, because it becomes a page and the notes of a release: HTML,
# a mention and a link, also a bare address, which GitHub links by itself. Such a title
# stays for a person.
NOT_WORDS = re.compile(r"[<@]|\]\(|://|\bwww\.", re.I)
# The longest name of a draft, without its end, and what GitHub takes for a title.
DRAFT_NAME_LENGTH = 80
LONGEST_TITLE = 256
# The endings of the name of a draft, where the commit has the one before already.
DRAFT_ENDINGS = ("", *(f"-{number}" for number in range(2, 10)))
# The first line of the body of the draft, before the run of the checks.
DRAFTED_AFTER = "Made from the title of the pull request after"
# The command of a person on a pull request that starts the fix bot: the first line of
# a comment, alone.
COMMAND = "/fix"
# The permissions on the repository of a person whose command the bot follows; GitHub
# names maintain as write and triage as read in this field.
WRITE_ACCESS = frozenset({"admin", "write"})


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


class NoList(Exception):
    """after-checks.yml of main names no workflow that the fix bot could go by."""


@dataclass(frozen=True)
class Watched:
    """The workflows of the fix bot, as the files of main name them: every one of the
    list of after-checks.yml, whose fixes it reads, and among them those whose files
    keep fixes, which it waits for. A long run that keeps none, such as the tests in a
    browser, holds up no commit, and one whose fixes come late starts the bot itself."""

    listed: frozenset[str]
    keeping: frozenset[str]

    def awaited(self, event: str) -> frozenset[str]:
        """Those that the bot waits for: Docs keeps nothing on a pull request."""
        if event in MAIN_EVENTS:
            return self.keeping
        return self.keeping - {MAIN_WORKFLOW}


def watched(root: Path) -> Watched:
    """The workflows of the fix bot from the files of the default branch, never those
    of a pull request, which names its runs as it likes. Without a list to go by, the
    bot stops and says so rather than take the fixes of any run."""
    listed = started_after((root / AFTER_CHECKS).read_text(encoding="utf-8"))
    if not listed:
        raise NoList(
            f"{AFTER_CHECKS} lists no workflow whose fixes the fix bot may read; "
            "the bot stops and commits nothing."
        )
    keeping = set()
    for path in (root / AFTER_CHECKS).parent.glob("*.y*ml"):
        text = path.read_text(encoding="utf-8")
        name = WORKFLOW_NAME.search(text)
        if name and name[1] in listed and KEEPS_FIXES.search(text):
            keeping.add(name[1])
    return Watched(listed, frozenset(keeping))


def runs_of(
    api: Api, repo: str, sha: str, event: str, branch: str, listed: frozenset[str]
) -> list[WorkflowRun]:
    """The newest run of each workflow of the list for a commit: of the event of a pull
    request, and of every event of main, so that a check that bot health started by
    hand and one that its schedule started end in one pull request. One event at a
    time: a commit of main is also that of the many runs after other workflows. Of main
    only its own runs: one by hand on a branch at the same commit keeps no fix, and
    would hide the failed run of main. Docs keeps a fix of main only in the run of a push."""
    latest: dict[str, WorkflowRun] = {}
    for each in MAIN_EVENTS if event in MAIN_EVENTS else (event,):
        data = api.get(
            f"repos/{repo}/actions/runs?head_sha={sha}&event={each}&per_page=100"
        ) or {"workflow_runs": []}
        for item in data["workflow_runs"]:
            if each in MAIN_EVENTS and item.get("head_branch") != branch:
                continue
            name = str(item.get("name") or "")
            if name not in listed or (
                name == MAIN_WORKFLOW and each in MAIN_EVENTS and each != "push"
            ):
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


def fixes_of(api: Api, repo: str, run_id: int) -> list[Json]:
    """The artifacts of the fixes of a run, also those that have expired."""
    data = api.get(f"repos/{repo}/actions/runs/{run_id}/artifacts?per_page=100") or {}
    return [
        item
        for item in data.get("artifacts", [])
        if str(item.get("name") or "").startswith(ARTIFACT)
    ]


def artifacts_of(api: Api, repo: str, run_id: int) -> list[str]:
    """The names of the fixes that a run kept."""
    return sorted(
        {
            str(item.get("name"))
            for item in fixes_of(api, repo, run_id)
            if not item.get("expired")
        }
    )


def failed(runs: Iterable[WorkflowRun]) -> list[int]:
    """The ids of the runs that ended by failing."""
    return [
        found.id
        for found in runs
        if found.status == "completed" and found.conclusion == "failure"
    ]


def fixes_expired(
    api: Api, repo: str, sha: str, event: str, branch: str, listed: frozenset[str]
) -> bool:
    """Whether a failed run of a commit of main kept fixes that have expired since. The
    commit may still need them, so only the next run of that check, by the week at the
    latest, can say that it doesn't."""
    return any(
        item.get("expired")
        for run_id in failed(runs_of(api, repo, sha, event, branch, listed))
        for item in fixes_of(api, repo, run_id)
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
class Collected:
    """The folders of the fixes of a commit, and the workflows that the bot waited for
    in vain, as one look at the runs found both: a fix that is here comes from a run that
    had ended then, and a run that ended later has its own job."""

    folders: list[Path]
    late: tuple[str, ...] = ()


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
    run_id: int | None,
    sha: str,
    event: str,
    branch: str,
    folder: Path,
    download: Download,
    workflows: Watched,
    wait: Callable[[float], None] = time.sleep,
) -> Collected:
    """Downloads the fixes of every workflow of the list for a commit into folder, one
    folder for each, once those that keep fixes have ended, so that one commit holds
    them all, and says which of them still ran after the wait. A pull request whose
    commit has no failed run with a fix has nothing for the bot. The commit decides that,
    not the run that started this job: GitHub keeps one pending job of a branch, so a run
    with no fix can replace the job of one with. A run that started this job and ended
    without failing counts for nothing, whatever it kept, as the bot takes no fix of it;
    one that runs again still counts. Without run_id, at the command of a person, the bot
    waits in any case: a check that still runs may keep a fix."""
    awaited = workflows.awaited(event)
    runs = runs_of(api, repo, sha, event, branch, workflows.listed)
    ended = {
        found.id
        for found in runs
        if found.status == "completed" and found.conclusion != "failure"
    }
    if (
        run_id is not None
        and event not in MAIN_EVENTS
        and not any(
            artifacts_of(api, repo, each)
            for each in dict.fromkeys((run_id, *failed(runs)))
            if each not in ended
        )
    ):
        return Collected([])
    for _ in range(WAITS):
        if all(found.status == "completed" for found in runs if found.name in awaited):
            break
        wait(WAIT)
        runs = runs_of(api, repo, sha, event, branch, workflows.listed)
    folders = []
    for found in runs:
        passed_with_fixes = (
            event in MAIN_EVENTS
            and found.name == MAIN_WORKFLOW
            and found.conclusion == "success"
        )
        if found.status != "completed" or (
            found.conclusion != "failure" and not passed_with_fixes
        ):
            continue
        for name in artifacts_of(api, repo, found.id):
            target = folder / f"{found.id}-{name}"
            download(found.id, name, target)
            folders.append(target)
    return Collected(
        folders,
        tuple(
            found.name
            for found in runs
            if found.name in awaited and found.status != "completed"
        ),
    )


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


def opted_out(pull: Json) -> bool:
    """Whether the author of a pull request wants no commit of the bot."""
    return OPT_OUT in {str(label.get("name")) for label in pull.get("labels") or []}


def from_fork(pull: Json, repo: str) -> bool:
    """Whether a pull request comes from another repository, which the bot after the checks never
    pushes to."""
    head = pull.get("head") or {}
    return str((head.get("repo") or {}).get("full_name") or "") != repo


def still_running(late: Iterable[str], sha: str) -> str:
    """The first words of the bot when it leaves its work to the job after these runs."""
    names = ", ".join(code(name, NAME_LENGTH) for name in late)
    return f"Still running after the wait for {sha[:7]}: {names}."


def plan(
    api: Api,
    repo: str,
    sha: str,
    event: str,
    branch: str,
    number: int | None,
    fixes: Fixes,
    listed: frozenset[str] = frozenset(),
    late: Sequence[str] = (),
) -> Plan:
    """Decides what the bot does with the fixes of a commit; listed names the workflows
    that it reads, and late those that it waited for in vain.

    Only a commit onto a branch waits for them. It would cancel these runs, whose fixes
    the next commit then has to find again, and the bot commits only twice in a row, so
    a part of the fixes could leave a later one without a commit. The job after the run
    that ends last takes them all, however that run ends. The pull request of main costs
    no run and takes the next fixes as well, and a suggestion comes once: both act on what
    has ended, as a weekly run of main or a run of a fork starts no job when it passes."""
    if event in MAIN_EVENTS:
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
        if fixes_expired(api, repo, sha, event, branch, listed):
            return Plan(
                "",
                f"A failed check of {sha[:7]} kept fixes that have expired, so "
                f"#{pull['number']} stays open until that check runs again.\n",
            )
        if late:
            return Plan(
                "",
                f"{still_running(late, sha)} #{pull['number']} stays open until a later "
                "job of the bot finds them ended, as they may need its fixes.\n",
            )
        return Plan(
            "close", f"{branch} needs the fixes of #{pull['number']} no more.\n"
        )
    pull = find_pull(api, repo, sha, number)
    if pull is None:
        return Plan("", f"{sha[:7]} is no longer the head of an open pull request.\n")
    number = int(pull["number"])
    if opted_out(pull):
        return Plan(
            "", f"#{number} has the label {OPT_OUT}: the bot leaves it alone.\n"
        )
    if not fixes.files:
        return Plan("", f"No command fixed what the checks of #{number} found.\n")
    if from_fork(pull, repo):
        return Plan("suggest", f"#{number} comes from a fork.\n", number)
    if late:
        return Plan(
            "",
            f"{still_running(late, sha)} The bot commits nothing yet, so that one commit "
            "holds every fix: the job after the last of them makes it.\n",
        )
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


def commit(
    api: Api,
    repo: str,
    branch: str,
    sha: str,
    fixes: Fixes,
    body: str,
    headline: str = HEADLINE,
) -> str:
    """Commits the fixes onto branch while it is still at sha, as whoever sends the
    request; GitHub signs the commit, and the push starts the checks."""
    data = graphql(
        api,
        COMMIT,
        {
            "input": {
                "branch": {"repositoryNameWithOwner": repo, "branchName": branch},
                "expectedHeadOid": sha,
                "message": {"headline": headline, "body": body},
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
    # A fork names its branch as it likes, such as with a # that would end the path.
    ref = api.get(f"repos/{repo}/git/ref/heads/{quote(branch, safe='/')}") or {}
    return str((ref.get("object") or {}).get("sha") or "")


def onto_branch(
    api: Api,
    repo: str,
    branch: str,
    sha: str,
    fixes: Fixes,
    body: str,
    headline: str = HEADLINE,
) -> str:
    """The commit that the bot made of the fixes on branch, or nothing when the branch
    moved on from sha, whose new head its checks look at again."""
    if head_of(api, repo, branch) != sha:
        return ""
    try:
        return commit(api, repo, branch, sha, fixes, body, headline)
    except GitHubError:
        if head_of(api, repo, branch) != sha:
            return ""
        raise


def moved_on(branch: str, sha: str) -> str:
    return f"{branch} moved on from {sha[:7]}; its checks fix it again.\n"


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
    body = signed_off(api, app, f"{MADE_BY} {url}")
    made = onto_branch(api, repo, branch, sha, fixes, body)
    if not made:
        return moved_on(branch, sha)
    lines = [f"The fix bot committed {made[:7]} onto {code(branch, NAME_LENGTH)}:", ""]
    return "\n".join([*lines, *listing(fixes), ""])


# ---------------------------------------------------------------- the title and the entry


def needs_entry(title: str, pull: Json, files: Iterable[Json]) -> bool:
    """Whether a pull request with this title has only its entry of the changelog
    missing: a change for users of a person, with no entry of its own. The step of the
    check of the title decides alike, and a test runs both on the same pull requests. A
    file of changelog.d/ that is no fragment fails the check by itself, and its author
    meant an entry, so the bot adds none next to it."""
    match = FOR_USERS.match(title)
    if (
        match is None
        or match[2] in UPDATES
        or (pull.get("user") or {}).get("type") == "Bot"
    ):
        return False
    folder = f"{FRAGMENTS}/"
    for item in files:
        name, status = str(item.get("filename")), str(item.get("status"))
        if status == "removed":
            continue
        if name == "CHANGELOG.md":
            return False
        if name.startswith(folder) and not name.startswith(f"{folder}."):
            own = status in ("added", "copied")
            if own or not FRAGMENT_NAME.match(name.removeprefix(folder)):
                return False
    return True


def draft_of(title: str) -> str:
    """The text of a fragment from a title of a change for users: its words below the
    heading of its kind, or nothing when they say nothing, or could be more than words.
    A test proves that the release takes in each such text."""
    match = FOR_USERS.match(title)
    if match is None:
        return ""
    words = clean(match[4], LONGEST_TITLE).rstrip(". ")
    if not words or NOT_WORDS.search(words):
        return ""
    kind = DRAFT_BREAKING if match[3] else DRAFT_KINDS[match[1]]
    entry = f"**{match[2]}:** {words}" if match[2] else words[0].upper() + words[1:]
    return f"### {kind}\n\n- {entry}.\n"


def fragment_path(branch: str, taken: Iterable[str], number: int) -> str:
    """The path of a new fragment, which the bot builds itself: the name of the branch
    without its type, as the guide for the changelog asks, in the plain letters of a
    fragment; the number of the pull request for a branch that leaves none. A fragment
    that the commit or its base branch has already is the entry of another pull request,
    so the name then takes an ending, and when it runs out of them, the bot adds nothing."""
    kind, _, rest = branch.partition("/")
    name = rest if rest and kind.lower() in (*TYPES, *ALIASES) else branch
    plain = re.sub(r"[^A-Za-z0-9._-]+", "-", name)[:DRAFT_NAME_LENGTH]
    stem = plain.strip("-._") or f"pull-{number}"
    names = {path.lower() for path in taken}
    for ending in DRAFT_ENDINGS:
        path = f"{FRAGMENTS}/{stem}{ending}.md"
        if path.lower() not in names:
            return path
    return ""


def drafted_before(api: Api, repo: str, number: int) -> bool:
    """Whether a commit of a pull request is a draft of the bot. One who deleted it
    wants none, and so the bot drafts once for each pull request and never again."""
    return any(
        str((item.get("commit") or {}).get("message") or "").partition("\n")[0]
        == DRAFT_HEADLINE
        for item in paged(api, f"repos/{repo}/pulls/{number}/commits")
    )


@dataclass(frozen=True)
class OwnFix:
    """What the bot fixes of the title and the entry of a pull request from a branch of
    this repository: nothing when title and path are empty, and text says why."""

    text: str
    number: int | None = None
    branch: str = ""
    title: str = ""
    path: str = ""
    draft: str = ""


def own_fix(api: Api, repo: str, sha: str, number: int | None) -> OwnFix:
    """Decides what the bot fixes of the title and the entry of a pull request, from
    what the API says of it now. When in doubt, it fixes nothing and the check explains."""
    pull = find_pull(api, repo, sha, number)
    if pull is None:
        return OwnFix(f"{sha[:7]} is no longer the head of an open pull request.\n")
    number = int(pull["number"])
    if opted_out(pull):
        return OwnFix(f"#{number} has the label {OPT_OUT}: the bot leaves it alone.\n")
    if from_fork(pull, repo):
        return OwnFix(
            f"#{number} comes from a fork, which the bot leaves to the PR coach.\n"
        )
    branch = str(pull["head"].get("ref") or "")
    title = str(pull.get("title") or "")
    corrected = corrected_title(title, branch)
    path = draft = ""
    # The check asks for no entry while the title is no Conventional Commit: the bot
    # looks at the title that it sets, so that one run brings both.
    if needs_entry(
        corrected or title, pull, paged(api, f"repos/{repo}/pulls/{number}/files")
    ) and not drafted_before(api, repo, number):
        draft = draft_of(corrected or title)
        if draft:
            # The base branch too: a name that only it has would be a conflict.
            base = quote(str(pull["base"]["ref"]), safe="")
            taken = {*files_of(api, repo, sha), *files_of(api, repo, base)}
            path = fragment_path(branch, taken, number)
    if not corrected and not path:
        return OwnFix(
            f"#{number} has nothing that the bot corrects or drafts by itself; the "
            "check says what it needs.\n"
        )
    lines = [f"- the title: {code(corrected)}"] if corrected else []
    if path:
        lines += [f"- a draft of the entry of the changelog: {code(path, NAME_LENGTH)}"]
    text = "\n".join([f"#{number} has what the bot fixes by itself:", "", *lines, ""])
    return OwnFix(text, number, branch, corrected, path, draft)


def fix_own(
    api: Api, repo: str, sha: str, number: int | None, app: str, url: str
) -> str:
    """Sets the corrected title of a pull request from a branch of this repository, as
    the release app, so that the check of the title runs again, and commits the draft of
    its entry as one new file. The title first: the commit starts that check anew."""
    found = own_fix(api, repo, sha, number)
    said = found.text if not found.title and not found.path else ""
    if found.title:
        api.patch(f"repos/{repo}/pulls/{found.number}", {"title": found.title})
        said += (
            f"The fix bot set the title of #{found.number} to {code(found.title)}: only "
            "its form differed, and its type and breaking mark decide the version.\n"
        )
    if found.path:
        body = signed_off(api, app, f"{DRAFTED_AFTER} {url}")
        fixes = Fixes({found.path: found.draft.encode()})
        made = onto_branch(api, repo, found.branch, sha, fixes, body, DRAFT_HEADLINE)
        if not made:
            return said + moved_on(found.branch, sha)
        said += (
            f"The fix bot committed {made[:7]}, a draft of the entry of the changelog "
            f"from the title: {code(found.path, NAME_LENGTH)}. Read it, and say in the "
            "words of a user what changes for them.\n"
        )
    return said


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


# ---------------------------------------------------------------- the command /fix


def is_command(body: str) -> bool:
    """Whether a comment is the command /fix: its first line, alone, so that a comment
    that only talks about it, such as "/fixed it" or a quote, starts nothing."""
    lines = body.strip().splitlines()
    return bool(lines) and lines[0].strip() == COMMAND


def permission_of(api: Api, repo: str, user: str) -> str:
    """The permission of a person on the repository, as GitHub answers now; none
    where GitHub knows no such person."""
    data = api.get(f"repos/{repo}/collaborators/{quote(user, safe='')}/permission")
    return str((data or {}).get("permission") or "none")


def commander(api: Api, repo: str, comment: int) -> str:
    """The person who gave the command in a comment, from the API, which says what the
    comment says now: nobody, when it is no command."""
    data = api.get(f"repos/{repo}/issues/comments/{comment}") or {}
    if not is_command(str(data.get("body") or "")):
        return ""
    return str((data.get("user") or {}).get("login") or "")


def acknowledge(api: Api, repo: str, comment: int) -> None:
    """Shows the person at once that the bot took the command, as GitHub shows a pair
    of eyes; a refusal changes nothing of the work."""
    try:
        post(
            api,
            f"repos/{repo}/issues/comments/{comment}/reactions",
            {"content": "eyes"},
        )
    except GitHubError as error:
        print(
            f"::warning::The fix bot couldn't react to the command: {error}",
            file=sys.stderr,
        )


@dataclass(frozen=True)
class Command:
    """What the fix bot does at the command of a person: commit the fixes onto the
    branch of the head in repository, suggest them, or nothing."""

    action: str
    text: str
    sha: str = ""
    repository: str = ""
    branch: str = ""


def decide_command(
    api: Api,
    repo: str,
    number: int,
    user: str,
    folder: Path,
    download: Download,
    workflows: Watched,
    wait: Callable[[float], None] = time.sleep,
) -> Command:
    """Decides what the bot does at the command /fix of user on a pull request: only a
    person with write access starts it, as GitHub answers now, and the bot takes the
    fixes that the checks of the head kept once they have ended, as the bot after the
    checks does. The label no-bot-fixes turns off the bot's own commits, not those that
    a person asks for. A fork that allows no edits by maintainers takes no commit of
    anybody but its author, so it gets the suggestions."""
    permission = permission_of(api, repo, user)
    if permission not in WRITE_ACCESS:
        return Command(
            "",
            f"Only a person with write access to this repository starts the fix bot "
            f"with `{COMMAND}`; {code(user, NAME_LENGTH)} has the permission "
            f"{code(permission, NAME_LENGTH)}.\n",
        )
    pull = api.get(f"repos/{repo}/pulls/{number}") or {}
    if pull.get("state") != "open":
        return Command("", f"#{number} is no open pull request.\n")
    head = pull.get("head") or {}
    sha = str(head.get("sha") or "")
    branch = str(head.get("ref") or "")
    repository = str((head.get("repo") or {}).get("full_name") or "")
    if not repository:
        return Command(
            "", f"The fork of #{number} is gone, so no commit can reach its branch.\n"
        )
    found = collect(
        api, repo, None, sha, "pull_request", branch, folder, download, workflows, wait
    )
    if found.late:
        return Command(
            "",
            f"{still_running(found.late, sha)} The bot commits nothing yet, so that one "
            f"commit holds every fix: say `{COMMAND}` again once they have ended.\n",
        )
    if not load(found.folders).files:
        return Command(
            "",
            f"No command fixed what the checks of {sha[:7]} found; the PR coach names "
            "what is left and how to fix it.\n",
        )
    if repository == repo:
        return Command(
            "commit",
            f"#{number} comes from a branch of this repository.\n",
            sha,
            repository,
            branch,
        )
    if not pull.get("maintainer_can_modify"):
        return Command(
            "suggest",
            f"#{number} comes from a fork whose author allows no edits by maintainers, "
            "so no bot may commit onto its branch: the bot suggests the fixes at their "
            "lines, and **Commit suggestion** takes each. With **Allow edits by "
            f"maintainers** turned on, `{COMMAND}` commits them.\n",
            sha,
            repository,
            branch,
        )
    return Command(
        "commit",
        f"#{number} comes from a fork whose author allows edits by maintainers.\n",
        sha,
        repository,
        branch,
    )


def for_fork(api: Api, repo: str, number: int, fixes: Fixes) -> Fixes:
    """The fixes that the bot commits onto the branch of a fork: those of files that the
    pull request changes, and of none that decides what a workflow runs. Whether GitHub
    holds the runs of a commit of the app on a fork for a person isn't documented, so
    such a file stays with a person, as with the approvals of the branch bot; the others
    are files that a person reviews in the pull request anyway."""
    changed = {
        str(item.get("filename"))
        for item in paged(api, f"repos/{repo}/pulls/{number}/files")
        if item.get("status") != "removed"
    }
    kept: dict[str, bytes] = {}
    left = dict(fixes.left)
    for path, data in fixes.files.items():
        if decides_what_runs(path):
            left[path] = "decides what a workflow runs, so a person commits its fix"
        elif path not in changed:
            left[path] = "no file that the pull request changes"
        else:
            kept[path] = data
    return Fixes(kept, dict(sorted(left.items())))


@dataclass(frozen=True)
class Pushed:
    """What the bot said of its commit at a command, and whether it leaves the fixes to
    the suggestions: on a fork whose branch refused the commit or takes none of them."""

    text: str
    to_suggest: bool = False


def commit_on_command(
    api: Api,
    repo: str,
    number: int,
    repository: str,
    branch: str,
    sha: str,
    fixes: Fixes,
    app: str,
    url: str,
) -> Pushed:
    """Commits the fixes onto the branch of a pull request at the command of a person,
    in repository, a fork's when it isn't repo, while the branch is still at sha. A
    refusal of the branch of a fork is no failure: GitHub may not let the app push
    there, and the suggestions follow."""
    fixes = changeable(files_of(api, repo, sha), sha, fixes)
    fork = repository != repo
    if fork:
        fixes = for_fork(api, repo, number, fixes)
    if not fixes.files:
        return Pushed(
            "\n".join(["No fix is left to commit.", *listing(fixes), ""]), fork
        )
    where = code(f"{repository}:{branch}" if fork else branch, NAME_LENGTH)
    again = Pushed(
        f"{where} moved on from {sha[:7]}, so the bot commits nothing: say "
        f"`{COMMAND}` again once the checks of its new head have ended.\n"
    )
    if head_of(api, repository, branch) != sha:
        return again
    body = signed_off(api, app, f"{MADE_BY} {sha[:7]}, at the command {url}")
    try:
        made = commit(api, repository, branch, sha, fixes, body)
    except GitHubError as error:
        if head_of(api, repository, branch) != sha:
            return again
        if not fork:
            raise
        return Pushed(
            f"GitHub refused the commit of the fix bot onto {where}: "
            f"{code(error.message, FINDING_LENGTH)}. So the fixes stay what a fork gets "
            "without the command: the bot suggests them at their lines, and **Commit "
            "suggestion** takes each.\n",
            True,
        )
    lines = [f"The fix bot committed {made[:7]} onto {where}:", "", *listing(fixes), ""]
    if fork:
        lines += [
            "The checks of the new head run once GitHub lets them, which on a fork "
            "may wait for a person to approve them.",
            "",
        ]
    return Pushed("\n".join(lines))


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
            "request holds what the commands of `autofix` in `.github/checks.toml` "
            "and `.github/checks.project.toml` made of these files, and merges itself "
            "once every check passes:",
            "",
            *listing(fixes),
            "",
            f"The checks of `{base}` that found them: {url}",
            "",
        ]
    )


def holds(api: Api, repo: str, pull: Json, sha: str, fixes: Fixes) -> bool:
    """Whether the pull request of main is the one commit that these fixes make of sha."""
    head = str((pull.get("head") or {}).get("sha") or "")
    if not head or parent_of(api, repo, head) != sha:
        return False
    files = files_of(api, repo, sha)
    fixed = {
        path: (files[path][0], blob_sha(data)) for path, data in fixes.files.items()
    }
    return files_of(api, repo, head) == files | fixed


def merge_itself(api: Api, repo: str, number: int) -> None:
    gh(api, "pr", "merge", str(number), "--repo", repo, "--auto", "--squash")


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
    # Each check of a weekly run that fails starts the bot, and the later run finds what
    # the first one made: a new commit would only start the checks of the pull request
    # again, and those of one that is stuck anew every week. It only asks for the merge
    # again when the run that made the commit failed to, or the pull request would wait
    # for a person.
    if pull is not None and holds(api, repo, pull, sha, fixes):
        if pull.get("auto_merge") is None:
            merge_itself(api, repo, int(pull["number"]))
        return f"#{pull['number']} from {BRANCH} holds these fixes of {base} already.\n"
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
    merge_itself(api, repo, number)
    return (
        f"#{number} from {BRANCH} has the fixes of {base} in {made[:7]}, and merges "
        "itself once every check passes.\n"
    )
