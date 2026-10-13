"""The pull request of the blueprint bot.

After `copier update` and `git add --all`, it commits what the update staged as the
release app, through GitHub's API, so that GitHub signs the commit, and keeps one pull
request for it open on the branch blueprint/update. A newer release moves that branch
on to a new commit on main, so the pull request stays the same one; a branch with
commits of anyone else stays as it is.
"""

from __future__ import annotations

import base64
import re
import shlex
import subprocess
import urllib.parse
from collections.abc import Iterator, Sequence
from dataclasses import dataclass
from pathlib import Path

from blueprint.github import Api, GitHubError, Json

BRANCH = "blueprint/update"
# The blueprint on GitHub, unless the answers name a fork of it.
SOURCE = "Dennis-Otto/repo-blueprint"
ANSWERS = ".copier-answers.yml"
# The label of the branch bot for a pull request that needs a person to merge it.
LABEL = "merge-conflict"
# The first line of the one comment on a pull request that the bot leaves alone.
NOTICE = "<!-- blueprint-bot: newer release -->"
# The lines that start and end a conflict of `copier update --conflict inline`.
MARKERS = re.compile(rb"^(?:<{7}|>{7}) ", re.MULTILINE)
# The modes of git: no file, a file, an executable file.
ABSENT = "000000"
REGULAR = "100644"
EXECUTABLE = "100755"
# Items per page of a list of GitHub.
PAGE = 100
CREATE_COMMIT = (
    "mutation($input: CreateCommitOnBranchInput!) "
    "{ createCommitOnBranch(input: $input) { commit { oid } } }"
)


def git(root: Path, *arguments: str) -> bytes:
    result = subprocess.run(
        ["git", *arguments], cwd=root, capture_output=True, check=False
    )
    if result.returncode != 0:
        message = result.stderr.decode(errors="replace").strip()
        raise OSError(f"git {arguments[0]}: {message}")
    return result.stdout


def blob(root: Path, sha: str) -> bytes:
    return git(root, "cat-file", "blob", sha)


def answered_release(text: str) -> str:
    """The release of the blueprint that a .copier-answers.yml names."""
    match = re.search(r"^_commit: *(\S+)", text, re.MULTILINE)
    return match.group(1) if match else ""


@dataclass(frozen=True)
class Change:
    """A file that the update staged, with its mode in HEAD and in the index: a new
    file has the mode 000000 before, and a deleted one after."""

    path: str
    before: str
    mode: str
    blob: str

    @property
    def deleted(self) -> bool:
        return self.mode == ABSENT


def staged_changes(root: Path) -> list[Change]:
    """Every file whose index differs from HEAD."""
    output = git(root, "diff-index", "--cached", "--no-renames", "-z", "HEAD")
    fields = output.decode().split("\0")[:-1]
    changes = []
    for meta, path in zip(fields[0::2], fields[1::2], strict=True):
        before, mode, _, sha, _ = meta.removeprefix(":").split(" ")
        changes.append(Change(path, before, mode, sha))
    return changes


@dataclass(frozen=True)
class Update:
    """What `copier update` staged in the checkout of main."""

    source: str
    base: str
    base_tree: str
    tree: str
    before: str
    after: str
    changes: tuple[Change, ...]
    # The files with conflict markers, among those the update touched: others may
    # hold such lines on purpose.
    conflicts: tuple[str, ...]

    @classmethod
    def read(cls, root: Path, source: str = SOURCE) -> Update:
        changes = staged_changes(root)
        return cls(
            source=source,
            base=git(root, "rev-parse", "HEAD").decode().strip(),
            base_tree=git(root, "rev-parse", "HEAD^{tree}").decode().strip(),
            tree=git(root, "write-tree").decode().strip(),
            before=answered_release(git(root, "show", f"HEAD:{ANSWERS}").decode()),
            after=answered_release(git(root, "show", f":{ANSWERS}").decode()),
            changes=tuple(changes),
            conflicts=tuple(
                change.path
                for change in changes
                if not change.deleted and MARKERS.search(blob(root, change.blob))
            ),
        )

    @property
    def title(self) -> str:
        return f"chore: update to the blueprint {self.after}"

    @property
    def release(self) -> str:
        return f"https://github.com/{self.source}/releases/tag/{self.after}"

    @property
    def summary(self) -> str:
        return f"The changes of the blueprint from {self.before} to {self.after}: {self.release}"


# --------------------------------------------------------------------------- GitHub


def post(api: Api, path: str, body: Json) -> dict[str, Json]:
    response = api.request("POST", path, body)
    if response.status == 404 or not isinstance(response.data, dict):
        raise GitHubError(response.status, f"no answer to POST {path}")
    return response.data


def graphql(api: Api, query: str, variables: Json) -> Json:
    """A GraphQL request whose variables are too large for the command line."""
    data = post(api, "graphql", {"query": query, "variables": variables})
    if data.get("errors") or not isinstance(data.get("data"), dict):
        raise GitHubError(0, f"GraphQL answered {data.get('errors') or data}")
    return data["data"]


def gh(api: Api, *arguments: str) -> None:
    result = api.runner(arguments, None)
    if result.returncode != 0:
        raise GitHubError(0, (result.stderr or result.stdout).strip())


def pages(api: Api, path: str) -> Iterator[Json]:
    page = 1
    while True:
        items = api.get(f"{path}?per_page={PAGE}&page={page}") or []
        yield from items
        if len(items) < PAGE:
            return
        page += 1


def open_pull(api: Api, repo: str) -> Json | None:
    owner = repo.split("/", 1)[0]
    pulls = api.get(f"repos/{repo}/pulls?state=open&head={owner}:{BRANCH}") or []
    return pulls[0] if pulls else None


def branch_head(api: Api, repo: str) -> str | None:
    ref = api.get(f"repos/{repo}/git/ref/heads/{BRANCH}")
    return str(ref["object"]["sha"]) if ref else None


def clean_merge(root: Path, commit: Json) -> bool:
    """A merge that only brings main into the branch, as the button *Update branch*
    of a pull request makes one: its tree is the one that Git merges by itself. A merge
    with a resolution or a change of its own, or one that the checkout doesn't have
    yet, is a maintainer's."""
    parents = [str(parent["sha"]) for parent in commit.get("parents") or []]
    if len(parents) != 2:
        return False
    try:
        merged = git(root, "merge-tree", "--write-tree", *parents).split()[0]
        made = git(root, "rev-parse", f"{commit['sha']}^{{tree}}").strip()
    except OSError:
        return False
    return merged == made


def strangers(api: Api, repo: str, root: Path, pull: Json, app: str) -> list[Json]:
    """The commits of the pull request that the release app didn't make: those of a
    maintainer, which the bot never overwrites. The merges of the branch bot are the
    app's, and a merge of main that changes nothing else holds nothing to keep: the
    bot makes the update on the newest main anyway."""
    commits = pages(api, f"repos/{repo}/pulls/{pull['number']}/commits")
    return [
        commit
        for commit in commits
        if (commit.get("author") or {}).get("login") != app
        and not clean_merge(root, commit)
    ]


def head_tree(api: Api, repo: str, pull: Json) -> str:
    commit = api.get(f"repos/{repo}/git/commits/{pull['head']['sha']}") or {}
    return str(commit.get("tree", {}).get("sha", ""))


# --------------------------------------------------------------------------- the commit


def commit_with_modes(api: Api, repo: str, root: Path, update: Update) -> str | None:
    """The commit of the update through the Git Data API, which carries the mode of
    every file; GitHub signs it, as it does every commit of an app without an author
    of its own. None when GitHub didn't sign it, or its tree isn't that of the index."""
    entries: list[Json] = []
    for change in update.changes:
        if change.deleted:
            entries.append(
                {
                    "path": change.path,
                    "mode": change.before,
                    "type": "blob",
                    "sha": None,
                }
            )
            continue
        entry: dict[str, Json] = {
            "path": change.path,
            "mode": change.mode,
            "type": "blob",
        }
        content = blob(root, change.blob)
        try:
            entry["content"] = content.decode("utf-8")
        except UnicodeDecodeError:
            encoded = base64.b64encode(content).decode()
            created = post(
                api,
                f"repos/{repo}/git/blobs",
                {"content": encoded, "encoding": "base64"},
            )
            entry["sha"] = created["sha"]
        entries.append(entry)
    tree = post(
        api,
        f"repos/{repo}/git/trees",
        {"base_tree": update.base_tree, "tree": entries},
    )["sha"]
    if tree != update.tree:
        print(
            f"::warning::GitHub made the tree {tree} of the update, not the {update.tree}"
            " of the checkout; the bot commits it without the modes of the files."
        )
        return None
    message = f"{update.title}\n\n{update.summary}"
    commit = post(
        api,
        f"repos/{repo}/git/commits",
        {"message": message, "tree": tree, "parents": [update.base]},
    )
    if not (commit.get("verification") or {}).get("verified"):
        print(
            "::warning::GitHub didn't sign the commit of the Git Data API; the bot"
            " commits the update without the modes of the files."
        )
        return None
    return str(commit["sha"])


def commit_on_scratch_branch(
    api: Api, repo: str, root: Path, update: Update, scratch: str
) -> str:
    """The commit of the update with createCommitOnBranch, which GitHub signs, but
    which keeps the mode of a changed file and gives a new one 100644. It needs a
    branch, so it gets one at main, which goes again right after."""
    additions = [
        {
            "path": change.path,
            "contents": base64.b64encode(blob(root, change.blob)).decode(),
        }
        for change in update.changes
        if not change.deleted
    ]
    deletions = [{"path": change.path} for change in update.changes if change.deleted]
    post(
        api,
        f"repos/{repo}/git/refs",
        {"ref": f"refs/heads/{scratch}", "sha": update.base},
    )
    try:
        data = graphql(
            api,
            CREATE_COMMIT,
            {
                "input": {
                    "branch": {"repositoryNameWithOwner": repo, "branchName": scratch},
                    "expectedHeadOid": update.base,
                    "message": {"headline": update.title, "body": update.summary},
                    "fileChanges": {"additions": additions, "deletions": deletions},
                }
            },
        )
    finally:
        api.delete(f"repos/{repo}/git/refs/heads/{scratch}")
    return str(data["createCommitOnBranch"]["commit"]["oid"])


def lost_modes(changes: Sequence[Change]) -> list[Change]:
    """The files whose mode createCommitOnBranch doesn't give them."""
    return [
        change
        for change in changes
        if not change.deleted
        and change.mode != (REGULAR if change.before == ABSENT else change.before)
    ]


def mode_command(change: Change) -> str:
    path = shlex.quote(change.path)
    if change.mode == EXECUTABLE:
        return f"git update-index --chmod=+x -- {path}"
    if change.mode == REGULAR:
        return f"git update-index --chmod=-x -- {path}"
    return f"# {path} needs the mode {change.mode}"


def make_commit(
    api: Api, repo: str, root: Path, update: Update, run_id: str
) -> tuple[str, list[Change]]:
    """The commit of the update on main, and the files whose mode it lacks."""
    commit = commit_with_modes(api, repo, root, update)
    if commit is not None:
        return commit, []
    scratch = f"{BRANCH}-{run_id}"
    commit = commit_on_scratch_branch(api, repo, root, update, scratch)
    return commit, lost_modes(update.changes)


class Outdated(Exception):
    """Main or the pull request changed while the bot worked, so that its commit,
    made on the main before, no longer fits: a new run makes it again."""


def check_main(api: Api, repo: str, update: Update) -> None:
    """Main still has the release that the update started from: an update merged
    meanwhile, such as the one of the open pull request, would conflict with the
    commit, which brings the same changes on the main before it."""
    answers = api.get(f"repos/{repo}/contents/{ANSWERS}?ref=main") or {}
    now = answered_release(base64.b64decode(answers.get("content", "")).decode())
    if now != update.before:
        raise Outdated(
            f"main moved from the blueprint's {update.before} to {now or 'none'} while"
            f" the bot worked; start it again to bring {update.after}."
        )


def move_branch(api: Api, repo: str, commit: str, exists: bool) -> None:
    """Point blueprint/update at the commit. From the update before, the branch moves
    straight to the new one: a pull request whose branch is main for a moment closes."""
    if exists:
        api.patch(
            f"repos/{repo}/git/refs/heads/{BRANCH}", {"sha": commit, "force": True}
        )
    else:
        post(
            api,
            f"repos/{repo}/git/refs",
            {"ref": f"refs/heads/{BRANCH}", "sha": commit},
        )


# --------------------------------------------------------------------------- the pull request


def describe(update: Update, modes: Sequence[Change]) -> str:
    """The description of the pull request."""
    lines = [
        f"Brings the repository from the blueprint's {update.before} to"
        f" [{update.after}]({update.release}) with `copier update`."
    ]
    if [change.path for change in update.changes] == [ANSWERS]:
        lines += [
            "",
            "The release changes no other file of this repository; merging records it"
            f" in `{ANSWERS}`, so that the next update starts from it.",
        ]
    if update.conflicts:
        lines += [
            "",
            "The update conflicts with changes of this repository in these files, so"
            " auto-merge is off: resolve the markers on this branch, then merge.",
            "",
            *(f"- `{path}`" for path in update.conflicts),
        ]
    if modes:
        lines += [
            "",
            "GitHub's API couldn't give these files their mode. Set it in a checkout"
            " of this branch, then commit and push:",
            "",
            "```sh",
            *(mode_command(change) for change in modes),
            "```",
        ]
    lines += [
        "",
        "A newer release of the blueprint moves this pull request on. Once you commit"
        " to its branch, the bot leaves it to you.",
    ]
    return "\n".join(lines) + "\n"


def leave_alone(api: Api, repo: str, pull: Json, update: Update) -> str:
    """Leave a pull request with commits of a maintainer as it is; say once, in one
    comment kept current, that a newer release is out."""
    number = pull["number"]
    answers = api.get(f"repos/{repo}/contents/{ANSWERS}?ref={pull['head']['sha']}")
    encoded = (answers or {}).get("content", "")
    if answered_release(base64.b64decode(encoded).decode()) == update.after:
        return (
            f"#{number} brings {update.after} already, with commits of its own;"
            " the bot leaves it as it is."
        )
    body = (
        f"{NOTICE}\nThe blueprint's [{update.after}]({update.release}) is out. This"
        " pull request has commits of its own, so the blueprint bot leaves its branch"
        " as it is: merge or close it, and the bot brings the rest with its next run,"
        " or at once when you start it under *Actions → Blueprint update*.\n"
    )
    comments = pages(api, f"repos/{repo}/issues/{number}/comments")
    earlier = next(
        (item for item in comments if str(item.get("body", "")).startswith(NOTICE)),
        None,
    )
    if earlier is None:
        api.post(f"repos/{repo}/issues/{number}/comments", {"body": body})
    elif earlier["body"] != body:
        api.patch(f"repos/{repo}/issues/comments/{earlier['id']}", {"body": body})
    return (
        f"#{number} has commits of its own; the bot leaves its branch as it is and"
        f" says that {update.after} is out."
    )


def write_pull(
    api: Api, repo: str, pull: Json | None, update: Update, modes: Sequence[Change]
) -> int:
    fields = {"title": update.title, "body": describe(update, modes)}
    if pull is not None:
        api.patch(f"repos/{repo}/pulls/{pull['number']}", fields)
        return int(pull["number"])
    created = post(
        api, f"repos/{repo}/pulls", {**fields, "head": BRANCH, "base": "main"}
    )
    return int(created["number"])


def hold(api: Api, repo: str, pull: Json) -> Json:
    """Turn the auto-merge of the pull request off; the answer is the pull request
    without it."""
    gh(api, "pr", "merge", str(pull["number"]), "--repo", repo, "--disable-auto")
    return {**pull, "auto_merge": None}


def settle(
    api: Api, repo: str, number: int, pull: Json | None, update: Update, automerge: bool
) -> None:
    """Conflicts turn auto-merge off, before they label the pull request: no check
    reads every file, and markers in one of them would merge. Without conflicts, a new
    pull request merges itself, and an open one gets its auto-merge back only when it
    waited for conflicts: whoever else turned it off decides."""
    merging = bool(pull and pull.get("auto_merge"))
    labels = {label["name"] for label in (pull or {}).get("labels", [])}
    if update.conflicts:
        if merging:
            hold(api, repo, pull)
        if LABEL not in labels:
            api.post(f"repos/{repo}/issues/{number}/labels", {"labels": [LABEL]})
        return
    if LABEL in labels:
        label = urllib.parse.quote(LABEL, safe="")
        api.delete(f"repos/{repo}/issues/{number}/labels/{label}")
    if automerge and not merging and (pull is None or LABEL in labels):
        gh(api, "pr", "merge", str(number), "--repo", repo, "--auto", "--squash")


def update_pull_request(
    api: Api,
    repo: str,
    root: Path,
    *,
    app: str,
    automerge: bool,
    run_id: str,
    source: str = SOURCE,
) -> str:
    """Commit what `copier update` staged in the checkout at root and bring its pull
    request to it; the answer says what happened."""
    update = Update.read(root, source)
    if not update.changes:
        return f"The blueprint's {update.after} changes nothing in this repository."
    pull = open_pull(api, repo)
    modes: list[Change] = []
    if pull is None:
        commit, modes = make_commit(api, repo, root, update, run_id)
        check_main(api, repo, update)
        move_branch(api, repo, commit, branch_head(api, repo) is not None)
        done = "Opened"
    elif strangers(api, repo, root, pull, app):
        return leave_alone(api, repo, pull, update)
    elif head_tree(api, repo, pull) == update.tree:
        done = "Kept"
    else:
        number = pull["number"]
        commit, modes = make_commit(api, repo, root, update, run_id)
        # Meanwhile the pull request may have merged or closed, the branch bot may
        # have brought its branch up to date, or a maintainer may have pushed to it.
        check_main(api, repo, update)
        head = branch_head(api, repo)
        state = (api.get(f"repos/{repo}/pulls/{number}") or {}).get("state")
        if head is None or state != "open":
            raise Outdated(
                f"#{number} closed while the bot worked; start it again to bring"
                f" {update.after}."
            )
        if head != pull["head"]["sha"] and strangers(api, repo, root, pull, app):
            return leave_alone(api, repo, pull, update)
        # New changes wait with conflicts, and with BLUEPRINT_AUTOMERGE set to off,
        # whoever switched auto-merge on for the update before. It goes before the
        # branch moves, so that nothing merges should the bot stop after the move.
        if pull.get("auto_merge") and (update.conflicts or not automerge):
            pull = hold(api, repo, pull)
        move_branch(api, repo, commit, exists=True)
        done = "Moved on"
    number = write_pull(api, repo, pull, update, modes)
    settle(api, repo, number, pull, update, automerge)
    text = f"{done} #{number}: the blueprint's {update.before} to {update.after}"
    if update.conflicts:
        text += f", with conflicts in {len(update.conflicts)} file(s), auto-merge off"
    return text + "."
