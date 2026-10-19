"""blueprint.py create: the repository on GitHub of a new copy of the blueprint.

Copier writes the files of a project into a folder, and nothing more. create makes a
repository of it: git init on the branch main, the first commit with a sign-off, and
the repository on GitHub, which gh repo create makes and pushes to. The command line
then applies the settings as code and shows the checklist. The owner and the name
come from .copier-answers.yml, or from --repo, and the description from
.github/repository.toml.

A folder that pushes to a remote already is no new copy, and create refuses it. A
folder that git init made before, as the quick start of older releases did, is taken
as it is: its branch is renamed to main, and the commit is made only when it has none.
"""

from __future__ import annotations

import re
import shlex
import subprocess
from collections.abc import Callable, Sequence
from pathlib import Path

from blueprint.settings import BRANCH

ANSWERS = ".copier-answers.yml"
MESSAGE = "chore: start from the blueprint"
# What the command line runs after the commands of create, as the user would type it.
AFTER = (
    ["python3", ".github/blueprint.py", "settings", "apply"],
    ["python3", ".github/blueprint.py", "checklist"],
)
# The owner and the name of the repository, as Copier writes them into its answers:
# the questions take letters, digits, hyphens and the dots of owner.github.io only.
# Copier writes them with PyYAML, which quotes a value that YAML would read as a
# number, a bool or null, such as the slug '2048' or an owner of digits only.
ANSWER = re.compile(r"^(owner|project_slug): (['\"]?)([\w.-]+)\2$", re.MULTILINE)
REPO = re.compile(r"[\w.-]+/[\w.-]+")

Runner = Callable[[Sequence[str], Path], subprocess.CompletedProcess[str]]


class CreateError(Exception):
    """A folder that create can't make a repository of, or a command that failed."""


def run_command(
    arguments: Sequence[str], folder: Path
) -> subprocess.CompletedProcess[str]:
    """Run git or gh in folder; the tests replace this with a simulated git and gh."""
    return subprocess.run(  # pragma: no cover - the tests simulate git and gh
        list(arguments),
        cwd=folder,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        check=False,
    )


def answered_repo(root: Path) -> str:
    """OWNER/NAME of the repository, from the answers that Copier wrote."""
    path = root / ANSWERS
    text = path.read_text(encoding="utf-8") if path.exists() else ""
    answers = {key: value for key, _, value in ANSWER.findall(text)}
    if "owner" not in answers or "project_slug" not in answers:
        raise CreateError(
            f"{ANSWERS} names no owner and project_slug: run create in the folder "
            "that copier copy wrote, or name the repository: "
            "python3 .github/blueprint.py --repo OWNER/NAME create"
        )
    return f"{answers['owner']}/{answers['project_slug']}"


def output(run: Runner, arguments: Sequence[str], root: Path) -> str:
    """What a command printed; CreateError when it failed or isn't installed."""
    try:
        result = run(arguments, root)
    except FileNotFoundError:
        raise CreateError(
            f"{arguments[0]} is not installed; create needs Git and the GitHub CLI"
        ) from None
    if result.returncode != 0:
        problem = (result.stderr or result.stdout).strip()
        raise CreateError(f"{shlex.join(arguments)} failed: {problem}")
    return result.stdout.strip()


def commands(
    root: Path, repo: str, description: str, private: bool, run: Runner
) -> list[list[str]]:
    """The commands that make the folder at root the repository on GitHub. It only
    asks git about the folder, and refuses one that pushes to a remote already."""
    steps: list[list[str]] = []
    committed = False
    if (root / ".git").exists():
        remotes = output(run, ["git", "remote"], root).split()
        if remotes:
            raise CreateError(
                f"this folder pushes to {', '.join(remotes)} already, and create "
                "starts a new repository; for one that is on GitHub, push to it and "
                f"run {shlex.join(AFTER[0])} and {shlex.join(AFTER[1])}"
            )
        if output(run, ["git", "symbolic-ref", "--short", "HEAD"], root) != BRANCH:
            steps.append(["git", "branch", "--move", BRANCH])
        committed = (
            run(["git", "rev-parse", "--verify", "--quiet", "HEAD"], root).returncode
            == 0
        )
    else:
        steps.append(["git", "init", f"--initial-branch={BRANCH}"])
    if committed:
        if output(run, ["git", "status", "--porcelain"], root):
            raise CreateError(
                "this folder has changes that no commit holds, and create pushes "
                "the commits only: commit them, or stash them, and run create again"
            )
    else:
        steps += [
            ["git", "add", "--all"],
            ["git", "commit", "--signoff", "--message", MESSAGE],
        ]
    steps.append(
        [
            "gh",
            "repo",
            "create",
            repo,
            "--private" if private else "--public",
            "--description",
            description,
            "--source",
            ".",
            "--remote",
            "origin",
            "--push",
        ]
    )
    return steps


def create(
    root: Path,
    description: str,
    *,
    repo: str | None = None,
    private: bool = False,
    dry_run: bool = False,
    run: Runner | None = None,
) -> str | None:
    """Make the repository on GitHub of the copy at root and return its OWNER/NAME;
    with dry_run, print the commands and return None."""
    # Looked up at the call, so that a test can replace it.
    runner = run or run_command
    repo = repo or answered_repo(root)
    if not REPO.fullmatch(repo):
        raise CreateError(f"{repo} is no OWNER/NAME of a repository")
    steps = commands(root, repo, description, private, runner)
    visibility = "private" if private else "public"
    if dry_run:
        print(f"create would make {repo} on GitHub, {visibility}, with:")
        for step in [*steps, *AFTER]:
            print(f"  {shlex.join(step)}")
        print("Run it without --dry-run to do it.")
        return None
    print(f"Creating {repo} on GitHub, {visibility}:")
    for step in steps:
        print(f"  {shlex.join(step)}", flush=True)
        output(runner, step, root)
    print(f"Created https://github.com/{repo}, with the branch {BRANCH}.")
    return repo
