"""blueprint.py workflows register: the workflows that GitHub doesn't know.

GitHub starts a workflow only once it has registered it: gh workflow run answers 404
for one that it doesn't know, and its schedule starts no run either. A run of a
workflow registers it, and so does a push that changes its file; but the first push of
a new repository, which gh repo create --push makes, may register none
(Dennis-Otto/repo-blueprint#652). Each workflow that a push or a pull request starts
comes to be known by its first run then, and those that only a schedule, a release or
a person starts stay unknown, such as the blueprint bot, which the Rollout can't start
then.

register compares the files of .github/workflows/ on main with the workflows that
GitHub knows, and opens a pull request that adds a comment to the end of each one
that GitHub doesn't know, in one commit that GitHub signs: its merge is a push that
changes them, which registers them. Run again after the merge, it opens the pull
request that takes the comments out, so that the files are the blueprint's once more;
while one of the two is open, it names that one. Both come from the branch
bot/register-workflows, a branch of a bot, so the ownership check gives them no notice
for the files of the blueprint that they change.

create looks after its push, and waits a moment for GitHub, which registers a
workflow as its first run starts; register opens the pull request for what is left,
and the checklist names each workflow that GitHub doesn't know.
"""

from __future__ import annotations

import base64
from collections.abc import Iterable
from dataclasses import dataclass
from pathlib import Path, PurePosixPath

from blueprint import github
from blueprint.github import Api, GitHubError, Json
from blueprint.settings import BRANCH
from blueprint.update import CREATE_COMMIT, graphql, post

FOLDER = ".github/workflows"
SUFFIXES = (".yml", ".yaml")
PULL_BRANCH = "bot/register-workflows"
COMMAND = "python3 .github/blueprint.py workflows register"
ISSUE = "https://github.com/Dennis-Otto/repo-blueprint/issues/652"
REFERENCE = "Refs Dennis-Otto/repo-blueprint#652"
# The comment at the end of each workflow that the first pull request registers, and
# that the second one takes out again.
NOTE = (
    "# Changed once so that GitHub registers this workflow, which it didn't know\n"
    "# (Dennis-Otto/repo-blueprint#652); python3 .github/blueprint.py workflows\n"
    "# register takes these lines out again.\n"
)
REGISTER_TITLE = "ci: let GitHub register the workflows that it doesn't know"
UNMARK_TITLE = "ci: take the comments that registered the workflows out again"
# How often create asks GitHub after its push, and how many seconds apart: GitHub
# registers a workflow as its first run starts, a moment after the push.
LOOKS = 6
PAUSE = 10.0
# The files of .github/workflows/ on a branch, with the commit that holds them.
FILES = """
query($owner: String!, $name: String!, $branch: String!, $folder: String!) {
  repository(owner: $owner, name: $name) {
    ref(qualifiedName: $branch) {
      target {
        oid
        ... on Commit {
          file(path: $folder) {
            object {
              ... on Tree {
                entries { name type object { ... on Blob { text isTruncated } } }
              }
            }
          }
        }
      }
    }
  }
}
"""


@dataclass(frozen=True)
class Main:
    """The head of main, and the text of each workflow in it, by its path."""

    head: str
    texts: dict[str, str]


def is_workflow(name: str) -> bool:
    return name.endswith(SUFFIXES)


def local_workflows(root: Path) -> dict[str, str]:
    """The text of each workflow of the checkout at root, by its path, with the ends
    of its lines as Git keeps them."""
    folder = root / FOLDER
    if not folder.is_dir():
        return {}
    return {
        f"{FOLDER}/{path.name}": path.read_text(
            encoding="utf-8", errors="replace"
        ).replace("\r\n", "\n")
        for path in sorted(folder.iterdir())
        if path.is_file() and is_workflow(path.name)
    }


def registered(api: Api, repo: str) -> frozenset[str]:
    """The paths of the workflows that GitHub knows."""
    return frozenset(
        str(item.get("path", ""))
        for item in api.get_all(f"repos/{repo}/actions/workflows")
        if isinstance(item, dict)
    )


def unknown(paths: Iterable[str], known: frozenset[str]) -> list[str]:
    return sorted(path for path in paths if path not in known)


def names(paths: Iterable[str]) -> str:
    return ", ".join(PurePosixPath(path).name for path in paths)


def marked(texts: dict[str, str]) -> list[str]:
    """The workflows that still hold the comment of the first pull request."""
    return sorted(path for path, text in texts.items() if NOTE in text)


def wait_until_known(
    api: Api, repo: str, paths: Iterable[str], looks: int = LOOKS
) -> list[str]:
    """The workflows that GitHub doesn't know after a push, which it is asked up to
    looks times, PAUSE seconds apart, until it knows all of them."""
    wanted = sorted(paths)
    missing = unknown(wanted, registered(api, repo)) if wanted else []
    for _ in range(looks - 1):
        if not missing:
            break
        # Looked up at the call, so that a test can replace it.
        github.sleep(PAUSE)
        missing = unknown(wanted, registered(api, repo))
    return missing


def known_text(paths: Iterable[str], missing: list[str]) -> str:
    """What create says after its look."""
    count = len(list(paths))
    if not missing:
        return f"GitHub knows all {count} workflows of {FOLDER}/."
    return (
        f"GitHub hasn't registered {len(missing)} of the {count} workflows of"
        f" {FOLDER}/: {names(missing)}. It starts none of them, not even on a"
        f" schedule, until a push changes their files ({ISSUE})."
    )


def read_main(api: Api, repo: str) -> Main:
    """The head of main and its workflows, in one query, so that both are of the same
    commit."""
    owner, name = repo.split("/", 1)
    data = graphql(
        api,
        FILES,
        {
            "owner": owner,
            "name": name,
            "branch": f"refs/heads/{BRANCH}",
            "folder": FOLDER,
        },
    )
    ref = (data.get("repository") or {}).get("ref")
    if not ref:
        raise GitHubError(404, f"GitHub has no branch {BRANCH} of {repo}")
    target = ref["target"]
    tree = (target.get("file") or {}).get("object") or {}
    texts: dict[str, str] = {}
    for entry in tree.get("entries") or []:
        if entry.get("type") != "blob" or not is_workflow(str(entry.get("name"))):
            continue
        path = f"{FOLDER}/{entry['name']}"
        blob = entry.get("object") or {}
        if blob.get("isTruncated") or not isinstance(blob.get("text"), str):
            raise GitHubError(0, f"GitHub didn't answer the whole text of {path}")
        texts[path] = blob["text"]
    return Main(str(target["oid"]), texts)


def open_pull(api: Api, repo: str) -> str:
    """The address of the open pull request of the branch, or nothing."""
    owner = repo.split("/", 1)[0]
    pulls = api.get(f"repos/{repo}/pulls?state=open&head={owner}:{PULL_BRANCH}") or []
    return str(pulls[0].get("html_url", "")) if pulls else ""


def reset_branch(api: Api, repo: str, head: str) -> None:
    """The branch at head, also where an earlier pull request of it left it."""
    if api.get(f"repos/{repo}/git/ref/heads/{PULL_BRANCH}"):
        api.patch(
            f"repos/{repo}/git/refs/heads/{PULL_BRANCH}", {"sha": head, "force": True}
        )
    else:
        api.post(
            f"repos/{repo}/git/refs", {"ref": f"refs/heads/{PULL_BRANCH}", "sha": head}
        )


def signer(api: Api) -> str:
    """The author that GitHub most likely gives a commit of the signed-in account: its
    name, and its address that GitHub keeps private."""
    user: Json = api.get("user") or {}
    login = str(user.get("login", ""))
    name = str(user.get("name") or login)
    return f"{name} <{user.get('id')}+{login}@users.noreply.github.com>"


def author(api: Api, repo: str, sha: str) -> str:
    """The author of a commit, as the sign-off of the check lint compares it."""
    commit: Json = api.get(f"repos/{repo}/git/commits/{sha}") or {}
    person = commit.get("author") or {}
    return f"{person.get('name')} <{person.get('email')}>"


def commit(
    api: Api, repo: str, main: Main, texts: dict[str, str], title: str, by: str
) -> str:
    """One commit of the files on the branch at the head of main, which GitHub signs,
    with the sign-off of its author."""
    data = graphql(
        api,
        CREATE_COMMIT,
        {
            "input": {
                "branch": {"repositoryNameWithOwner": repo, "branchName": PULL_BRANCH},
                "expectedHeadOid": main.head,
                "message": {
                    "headline": title,
                    "body": f"{REFERENCE}\n\nSigned-off-by: {by}",
                },
                "fileChanges": {
                    "additions": [
                        {
                            "path": path,
                            "contents": base64.b64encode(text.encode()).decode(),
                        }
                        for path, text in sorted(texts.items())
                    ]
                },
            }
        },
    )
    return str(data["createCommitOnBranch"]["commit"]["oid"])


def propose(
    api: Api, repo: str, main: Main, texts: dict[str, str], title: str, body: str
) -> str:
    """The pull request of one signed commit that changes the files to texts; its
    address."""
    reset_branch(api, repo, main.head)
    by = signer(api)
    made = author(api, repo, commit(api, repo, main, texts, title, by))
    if made != by:
        # GitHub took another address of the account, which no API tells before:
        # the commit once more, with the sign-off of the author that it got.
        reset_branch(api, repo, main.head)
        commit(api, repo, main, texts, title, made)
    pull = post(
        api,
        f"repos/{repo}/pulls",
        {"title": title, "head": PULL_BRANCH, "base": BRANCH, "body": body},
    )
    return str(pull.get("html_url", ""))


def listed(paths: Iterable[str]) -> str:
    return "\n".join(f"- `{PurePosixPath(path).name}`" for path in paths)


def register_body(missing: list[str]) -> str:
    return (
        f"GitHub hasn't registered these workflows of `{FOLDER}/`, so it starts none"
        " of them, not even on a schedule, and `gh workflow run` answers 404 for"
        f" each:\n\n{listed(missing)}\n\nA push that changes the file of a workflow"
        " registers it. This pull request adds a comment of three lines to the end"
        f" of each. Once it has merged, `{COMMAND}` opens the pull request that takes"
        " the comments out again, so that the files are those of the blueprint once"
        f" more.\n\n{REFERENCE}\n"
    )


def unmark_body(paths: list[str]) -> str:
    return (
        "The merge of the pull request that registered these workflows changed"
        " their files, which registered them. This pull request takes its comments"
        f" out again, so that the files are those of the blueprint once more:\n\n"
        f"{listed(paths)}\n\n{REFERENCE}\n"
    )


def noted(text: str) -> str:
    return (text if text.endswith("\n") else f"{text}\n") + NOTE


def register_workflows(api: Api, repo: str) -> str:
    """blueprint.py workflows register: the next step of the two pull requests, or
    that GitHub knows every workflow; what it did, to print."""
    pull = open_pull(api, repo)
    if pull:
        return (
            f"The pull request of {PULL_BRANCH} is open: {pull}\n"
            f"Merge it, then run {COMMAND} again."
        )
    main = read_main(api, repo)
    left = marked(main.texts)
    if left:
        texts = {path: main.texts[path].replace(NOTE, "") for path in left}
        address = propose(api, repo, main, texts, UNMARK_TITLE, unmark_body(left))
        return (
            f"The comments that registered {names(left)} are still in them. The pull"
            f" request that takes them out again: {address}"
        )
    missing = unknown(main.texts, registered(api, repo))
    if not missing:
        return f"GitHub knows every workflow of {FOLDER}/ on {BRANCH} of {repo}."
    texts = {path: noted(main.texts[path]) for path in missing}
    address = propose(api, repo, main, texts, REGISTER_TITLE, register_body(missing))
    return (
        f"GitHub hasn't registered {names(missing)}. The pull request that registers"
        f" them: {address}\nOnce it has merged, run {COMMAND} again: it opens the one"
        " that takes the comments out."
    )
