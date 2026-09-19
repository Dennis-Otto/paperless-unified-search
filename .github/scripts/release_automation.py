# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Protected, restartable Nextcloud releases; Python standard library only."""

from __future__ import annotations

import argparse
import base64
import json
import os
import re
import subprocess
import time
from datetime import datetime, timezone
from functools import partial
from pathlib import Path

INFO = "appinfo/info.xml"
CHANGELOG = "CHANGELOG.md"
PR_MARKER = "<!-- paperless-protected-release -->"
PENDING = "<!-- paperless-appstore:pending -->"
COMPLETE = "<!-- paperless-appstore:complete -->"
PR_CHECKS = {
    "ci.yml": {"test"},
    "e2e.yml": {"nextcloud"},
    "codeql.yml": {"codeql"},
    "secret-scan.yml": {"gitleaks"},
    "dependency-review.yml": {"dependency-review"},
    "sbom.yml": {"sbom"},
}
MAIN_CHECKS = {
    key: value for key, value in PR_CHECKS.items() if key != "dependency-review.yml"
} | {"scorecard.yml": {"Scorecard analysis"}}


class GitHub:
    def __init__(self, repository):
        self.repository = repository

    def api(
        self, path, *, method=None, data=None, pages=False, write=False, root=False
    ):
        command = ["gh", "api", path if root else f"repos/{self.repository}/{path}"]
        if method:
            command += ["--method", method]
        if pages:
            command += ["--paginate", "--slurp"]
        if data is not None:
            command += ["--input", "-"]
        environment = os.environ.copy()
        if write:
            environment["GH_TOKEN"] = environment["GH_RELEASE_TOKEN"]
        environment.pop("GH_RELEASE_TOKEN", None)
        result = subprocess.run(
            command,
            input=json.dumps(data) if data is not None else None,
            text=True,
            capture_output=True,
            check=False,
            env=environment,
        )
        if result.returncode:
            # gh does not print tokens; retain GitHub's useful validation errors.
            raise RuntimeError(
                f"GitHub request failed ({path}): {result.stderr.strip()}"
            )
        response = json.loads(result.stdout) if result.stdout.strip() else None
        if isinstance(response, dict) and response.get("errors"):
            raise RuntimeError(f"GitHub rejected request: {response['errors']}")
        return response

    def items(self, path, key=None):
        return [
            item
            for page in self.api(path, pages=True)
            for item in (page[key] if key else page)
        ]

    def file(self, path, ref):
        value = self.api(f"contents/{path}?ref={ref}")
        return base64.b64decode(value["content"]).decode()

    def main(self):
        return self.api("git/ref/heads/main")["object"]["sha"]


def version_tuple(version):
    value = version.removeprefix("v")
    if not re.fullmatch(r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)", value):
        raise ValueError(f"Expected a stable x.y.z version, got {version!r}")
    return tuple(map(int, value.split(".")))


def next_version(version, increment):
    parts = list(version_tuple(version))
    index = {"major": 0, "minor": 1, "patch": 2}[increment]
    parts[index] += 1
    parts[index + 1 :] = [0] * (2 - index)
    return ".".join(map(str, parts))


def current_version(info):
    matches = re.findall(r"<version>([^<]+)</version>", info)
    if len(matches) != 1:
        raise RuntimeError("App metadata must contain exactly one version.")
    version_tuple(matches[0])
    return matches[0]


def bump_info(info, version):
    old = current_version(info)
    if version_tuple(version) <= version_tuple(old):
        raise RuntimeError("Release version must increase.")
    return info.replace(
        f"<version>{old}</version>", f"<version>{version}</version>"
    ).replace(f"/v{old}/screenshots/", f"/v{version}/screenshots/")


def split_changelog(changelog):
    match = re.search(
        r"(?m)^## Unreleased\n(.*?)^(## \d+\.\d+\.\d+ - .*)", changelog, re.DOTALL
    )
    if not match or changelog.count("\n## Unreleased\n") != 1:
        raise RuntimeError("Expected one Unreleased section and dated release history.")
    return changelog[: match.start()], match[1].strip(), match[2]


def bump_changelog(changelog, version, generated, introduction="", date=None):
    prefix, unreleased, history = split_changelog(changelog)
    sections = [
        text.strip() for text in (introduction, unreleased, generated) if text.strip()
    ]
    if not sections:
        raise RuntimeError("A release requires meaningful release notes.")
    # GitHub's generated notes use H2; keep these inside this version's H2 section.
    notes = re.sub(r"(?m)^## ", "### ", "\n\n".join(sections))
    day = date or datetime.now(timezone.utc).date().isoformat()
    return f"{prefix}## Unreleased\n\n## {version} - {day}\n\n{notes}\n\n{history}"


def release_notes(changelog, version):
    match = re.search(
        rf"(?m)^## {re.escape(version)} - [^\n]+\n(.*?)(?=^## |\Z)",
        changelog,
        re.DOTALL,
    )
    if not match or not match[1].strip():
        raise RuntimeError(f"No changelog entry for {version}.")
    return match[1].strip()


def validate_metadata(base_info, info, base_log, log, version, files):
    if set(files) != {INFO, CHANGELOG} or info != bump_info(base_info, version):
        raise RuntimeError(
            "Release PR may change only version metadata and the changelog."
        )
    prefix, previous_notes, history = split_changelog(base_log)
    new_prefix, new_notes, new_history = split_changelog(log)
    notes = release_notes(log, version)
    if (
        prefix != new_prefix
        or new_notes
        or not new_history.endswith(history)
        or (previous_notes and previous_notes not in notes)
        or not re.match(
            rf"## {re.escape(version)} - \d{{4}}-\d{{2}}-\d{{2}}\n", new_history
        )
        or re.findall(r"(?m)^## ", new_history[: -len(history)]) != ["## "]
    ):
        raise RuntimeError("Release PR rewrites history or removes unreleased notes.")


def dependency_prs(pulls, commits, repository):
    return [
        pr
        for pr in pulls
        if pr.get("merged_at")
        and pr.get("merge_commit_sha") in commits
        and pr["user"]["login"] == "dependabot[bot]"
        and pr["base"]["ref"] == "main"
        and (pr["head"].get("repo") or {}).get("full_name") == repository
    ]


def owned_pr(pr, version, repository):
    slug = os.environ.get("GH_RELEASE_APP_SLUG")
    return (
        bool(slug)
        and pr["user"]["login"] == f"{slug}[bot]"
        and pr["head"]["ref"] == f"release/v{version}"
        and (pr["head"].get("repo") or {}).get("full_name") == repository
        and pr["base"]["ref"] == "main"
        and (pr.get("body") or "").startswith(PR_MARKER + "\n")
    )


def pending_release(releases):
    pending = [r for r in releases if (r.get("body") or "").rstrip().endswith(PENDING)]
    if len(pending) > 1:
        raise RuntimeError("Multiple incomplete releases require maintainer review.")
    return pending[0] if pending else None


def plan(github, automatic, increment, introduction):
    releases = github.items("releases?per_page=100")
    stable = [r for r in releases if not r["draft"] and not r["prerelease"]]
    latest = max(stable, key=lambda r: version_tuple(r["tag_name"]), default=None)
    base = github.main()
    current = current_version(github.file(INFO, base))
    result = {
        "enabled": True,
        "base_sha": base,
        "target_sha": "",
        "version": current,
        "previous_tag": latest["tag_name"] if latest else "",
        "dependencies": [],
        "introduction": introduction,
        "automatic": automatic,
    }
    if pending := pending_release(releases):
        if pending["prerelease"]:
            raise RuntimeError("Nextcloud production releases must be stable.")
        if latest and version_tuple(latest["tag_name"]) > version_tuple(
            pending["tag_name"]
        ):
            raise RuntimeError(
                "An older incomplete release requires maintainer review."
            )
        result.update(
            version=pending["tag_name"].removeprefix("v"),
            target_sha=github.api(f"commits/{pending['tag_name']}")["sha"],
        )
        return result
    if not latest:
        # Do not silently choose the first public release on a schedule.
        result.update(enabled=not automatic, target_sha=base)
        return result
    comparison = github.api(
        f"compare/{latest['tag_name']}...{base}?per_page=100", pages=True
    )
    if comparison[0]["status"] not in {"ahead", "identical"}:
        raise RuntimeError("Latest release must be an ancestor of main.")
    pulls = github.items("pulls?state=all&base=main&per_page=100")
    # A merged version PR can be resumed before the GitHub release even exists.
    if version_tuple(current) > version_tuple(latest["tag_name"]):
        candidates = [
            pr
            for pr in pulls
            if pr.get("merged_at")
            and pr["head"]["ref"] == f"release/v{current}"
            and (pr.get("body") or "").startswith(PR_MARKER + "\n")
        ]
        if len(candidates) != 1:
            raise RuntimeError("Unpublished version has no unique managed release PR.")
        result.update(
            target_sha=candidates[0]["merge_commit_sha"], pr=candidates[0]["number"]
        )
        return result
    if current != latest["tag_name"].removeprefix("v"):
        raise RuntimeError("Main version is older than the published release.")
    open_releases = [
        pr
        for pr in pulls
        if pr["state"] == "open"
        and (pr.get("body") or "").startswith(PR_MARKER + "\n")
        and pr["head"]["ref"].startswith("release/v")
    ]
    if open_releases:
        if len(open_releases) != 1:
            raise RuntimeError("Multiple open release PRs require maintainer review.")
        # Respect a previously selected manual minor/major version when recovering.
        result["version"] = open_releases[0]["head"]["ref"].removeprefix("release/v")
        if version_tuple(result["version"]) <= version_tuple(current):
            raise RuntimeError("Open release PR does not advance the current version.")
        return result
    commits = {commit["sha"] for page in comparison for commit in page["commits"]}
    dependencies = dependency_prs(pulls, commits, github.repository)
    result["dependencies"] = [pr["number"] for pr in dependencies]
    result["version"] = next_version(current, "patch" if automatic else increment)
    result["enabled"] = bool(commits) and (bool(dependencies) or not automatic)
    return result


def selected_runs(runs, expected, sha, number=None):
    selected = {}
    for run in runs:
        workflow = run["path"].removeprefix(".github/workflows/")
        if (
            run["head_sha"] != sha
            or workflow not in expected
            or run["event"] != ("pull_request" if number else "push")
        ):
            continue
        if number and not any(pr["number"] == number for pr in run["pull_requests"]):
            continue
        if not number and run["head_branch"] != "main":
            continue
        if run["id"] > selected.get(workflow, {}).get("id", 0):
            selected[workflow] = run
    return selected


def checks_ready(github, sha, number=None):
    expected = PR_CHECKS if number else MAIN_CHECKS
    event = "pull_request" if number else "push"
    runs = selected_runs(
        github.items(
            f"actions/runs?event={event}&head_sha={sha}&per_page=100", "workflow_runs"
        ),
        expected,
        sha,
        number,
    )
    if set(runs) != set(expected):
        return False
    for workflow, run in runs.items():
        if run["status"] != "completed":
            return False
        if run["conclusion"] != "success":
            raise RuntimeError(f"{workflow}: {run['conclusion']}; publication blocked.")
        jobs = github.items(
            f"actions/runs/{run['id']}/attempts/{run['run_attempt']}/jobs?per_page=100",
            "jobs",
        )
        if not expected[workflow].issubset({job["name"] for job in jobs}):
            raise RuntimeError(f"Expected jobs missing from {workflow}.")
        for job in jobs:
            if job["status"] != "completed":
                return False
            if job["conclusion"] != "success":
                raise RuntimeError(f"{workflow}: {job['name']} is {job['conclusion']}.")
    return True


def wait_until(predicate, description, timeout=1800):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        value = predicate()
        if value:
            return value
        time.sleep(15)
    raise TimeoutError(f"Timed out waiting for {description}; rerun to resume.")


def summary(message):
    print(message, flush=True)
    if path := os.environ.get("GITHUB_STEP_SUMMARY"):
        with Path(path).open("a") as output:
            output.write(message + "\n\n")


def bot_signoff(github):
    login = f"{os.environ['GH_RELEASE_APP_SLUG']}[bot]"
    user = github.api(f"users/{login}", root=True)
    return f"Signed-off-by: {login} <{user['id']}+{login}@users.noreply.github.com>"


def prepare_pr(github, planned):
    version = planned["version"]
    branch = f"release/v{version}"
    pulls = github.items(
        f"pulls?state=all&base=main&head={github.repository.split('/')[0]}:{branch}&per_page=100"
    )
    if pulls:
        if len(pulls) != 1 or not owned_pr(pulls[0], version, github.repository):
            raise RuntimeError("Existing release PR is not owned by this release App.")
        if pulls[0]["state"] == "closed" and not pulls[0].get("merged_at"):
            raise RuntimeError(
                "Release was paused by closing its PR. Reopen it to resume."
            )
        return pulls[0]
    base = github.main()
    info = github.file(INFO, base)
    log = github.file(CHANGELOG, base)
    candidate_info = bump_info(info, version)
    request = {
        "tag_name": f"v{version}",
        "target_commitish": base,
        "configuration_file_path": ".github/release.yml",
    }
    if planned["previous_tag"]:
        request["previous_tag_name"] = planned["previous_tag"]
    generated = github.api("releases/generate-notes", method="POST", data=request)[
        "body"
    ]
    candidate_log = bump_changelog(log, version, generated, planned["introduction"])
    refs = github.api(f"git/matching-refs/heads/{branch}")
    exact = [ref for ref in refs if ref["ref"] == f"refs/heads/{branch}"]
    if not exact:
        github.api(
            "git/refs",
            method="POST",
            write=True,
            data={"ref": f"refs/heads/{branch}", "sha": base},
        )
        head = base
    else:
        head = exact[0]["object"]["sha"]
    if head == base:
        response = github.api(
            "graphql",
            root=True,
            write=True,
            method="POST",
            data={
                "query": "mutation($input: CreateCommitOnBranchInput!) { createCommitOnBranch(input: $input) { commit { oid } } }",
                "variables": {
                    "input": {
                        "branch": {
                            "repositoryNameWithOwner": github.repository,
                            "branchName": branch,
                        },
                        "expectedHeadOid": head,
                        "message": {
                            "headline": f"chore(release): prepare v{version}",
                            "body": bot_signoff(github),
                        },
                        "fileChanges": {
                            "additions": [
                                {
                                    "path": path,
                                    "contents": base64.b64encode(
                                        content.encode()
                                    ).decode(),
                                }
                                for path, content in (
                                    (INFO, candidate_info),
                                    (CHANGELOG, candidate_log),
                                )
                            ]
                        },
                    }
                },
            },
        )
        head = response["data"]["createCommitOnBranch"]["commit"]["oid"]
    # An interrupted commit creation is safe to resume, but never trust arbitrary contents.
    validate_candidate(github, base, head, version)
    references = ", ".join(f"#{number}" for number in planned["dependencies"])
    body = (
        f"{PR_MARKER}\n\nPrepare **v{version}** with versioned screenshots and release notes.\n\n"
        f"Merged dependency updates: {references or 'manual release'}.\n\n"
        "Every PR check and the exact merged main commit must pass before signed packages "
        "are published to GitHub and the Nextcloud App Store. Close this PR to pause; reopen to resume."
    )
    pr = github.api(
        "pulls",
        method="POST",
        write=True,
        data={
            "head": branch,
            "base": "main",
            "title": f"chore(release): prepare v{version}",
            "body": body,
        },
    )
    github.api(
        f"issues/{pr['number']}/labels",
        method="POST",
        write=True,
        data={"labels": ["release"]},
    )
    return pr


def validate_candidate(github, base, head, version):
    comparison = github.api(f"compare/{base}...{head}")
    if comparison["status"] != "ahead":
        raise RuntimeError("Release branch is not based on current main.")
    validate_metadata(
        github.file(INFO, base),
        github.file(INFO, head),
        github.file(CHANGELOG, base),
        github.file(CHANGELOG, head),
        version,
        [file["filename"] for file in comparison["files"]],
    )


def merge_ready(github, number, head, base):
    pr = github.api(f"pulls/{number}")
    if pr["head"]["sha"] != head or pr["state"] != "open":
        raise RuntimeError(
            "Release candidate changed while waiting for protection checks."
        )
    if github.main() != base:
        return True  # Revalidate after bringing main into the owned release branch.
    if pr.get("mergeable") is False:
        raise RuntimeError("Release PR has conflicts; resolve them before resuming.")
    return pr.get("mergeable_state") == "clean"


def prepare(github, planned):
    version = planned["version"]
    if planned["target_sha"]:
        sha = planned["target_sha"]
        if "pr" in planned:
            pr = github.api(f"pulls/{planned['pr']}")
            if not owned_pr(pr, version, github.repository) or not pr.get("merged"):
                raise RuntimeError("Cannot resume an unowned or unmerged release PR.")
        if github.api(f"compare/{sha}...{github.main()}")["status"] not in {
            "ahead",
            "identical",
        }:
            raise RuntimeError("Release commit is no longer part of main.")
        if current_version(github.file(INFO, sha)) != version:
            raise RuntimeError("Release commit and version disagree.")
    else:
        pr = prepare_pr(github, planned)
        summary(f"Release PR: {pr['html_url']}")
        for _ in range(4):
            pr = github.api(f"pulls/{pr['number']}")
            if not owned_pr(pr, version, github.repository):
                raise RuntimeError("Release PR ownership changed.")
            if pr.get("merged"):
                sha = pr["merge_commit_sha"]
                break
            if pr["state"] != "open":
                raise RuntimeError("Release PR was closed; publication paused.")
            base, head = github.main(), pr["head"]["sha"]
            comparison = github.api(f"compare/{base}...{head}")
            if comparison["status"] != "ahead":
                # A signed-off API merge preserves DCO; update-branch has no custom message.
                github.api(
                    "merges",
                    method="POST",
                    write=True,
                    data={
                        "base": pr["head"]["ref"],
                        "head": base,
                        "commit_message": f"Merge main into release/v{version}\n\n{bot_signoff(github)}",
                    },
                )
                continue
            validate_candidate(github, base, head, version)
            wait_until(
                partial(checks_ready, github, head, pr["number"]),
                "actual release PR checks",
            )
            wait_until(
                partial(merge_ready, github, pr["number"], head, base),
                "GitHub branch protection",
            )
            if github.main() != base:
                continue
            merged = github.api(
                f"pulls/{pr['number']}/merge",
                method="PUT",
                write=True,
                data={
                    "sha": head,
                    "merge_method": "squash",
                    "commit_title": f"chore(release): prepare v{version} (#{pr['number']})",
                    "commit_message": bot_signoff(github),
                },
            )
            if not merged.get("merged"):
                raise RuntimeError("GitHub did not merge the protected release PR.")
            sha = merged["sha"]
            break
        else:
            raise RuntimeError(
                "Main kept changing; retry to refresh the release candidate."
            )
    wait_until(
        lambda: checks_ready(github, sha), "all main push checks on the release commit"
    )
    summary(f"All release checks passed on `{sha}` for v{version}.")
    return sha


def stage_release(github, version, sha, notes):
    """Create a draft first; its persistent marker survives interrupted asset uploads."""
    releases = github.items("releases?per_page=100")
    matches = [r for r in releases if r["tag_name"] == f"v{version}"]
    if matches:
        release = matches[0]
        if not (release.get("body") or "").rstrip().endswith(PENDING):
            raise RuntimeError(
                "Refusing to overwrite an already completed or unmanaged release."
            )
    else:
        refs = github.api(f"git/matching-refs/tags/v{version}")
        if any(ref["ref"] == f"refs/tags/v{version}" for ref in refs):
            if github.api(f"commits/v{version}")["sha"] != sha:
                raise RuntimeError("Existing release tag points at another commit.")
        else:
            github.api(
                "git/refs",
                method="POST",
                write=True,
                data={"ref": f"refs/tags/v{version}", "sha": sha},
            )
        release = github.api(
            "releases",
            method="POST",
            write=True,
            data={
                "tag_name": f"v{version}",
                "name": f"v{version}",
                "target_commitish": sha,
                "body": f"{notes}\n\n{PENDING}",
                "draft": True,
                "prerelease": False,
            },
        )
    if github.api(f"commits/v{version}")["sha"] != sha:
        raise RuntimeError("Release tag changed; publication blocked.")
    return release


def complete_release(github, version):
    release = github.api(f"releases/tags/v{version}")
    body = release.get("body") or ""
    if release["draft"] or not body.rstrip().endswith(PENDING):
        raise RuntimeError("Expected a published release awaiting the App Store.")
    github.api(
        f"releases/{release['id']}",
        method="PATCH",
        write=True,
        data={"body": body.rstrip()[: -len(PENDING)] + COMPLETE},
    )
    summary(f"Published v{version} to GitHub and the Nextcloud App Store.")


def output(name, value):
    with Path(os.environ["GITHUB_OUTPUT"]).open("a") as stream:
        stream.write(f"{name}={value}\n")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("plan", "prepare", "stage", "complete"))
    args = parser.parse_args()
    github = GitHub(os.environ["GITHUB_REPOSITORY"])
    if args.command == "plan":
        automatic = os.environ.get("RELEASE_MODE", "dependencies") == "dependencies"
        planned = plan(
            github,
            automatic,
            os.environ.get("INCREMENT") or "patch",
            os.environ.get("INTRODUCTION", ""),
        )
        output("plan", json.dumps(planned, separators=(",", ":")))
        output("enabled", str(planned["enabled"]).lower())
        summary(
            f"Prepare v{planned['version']}."
            if planned["enabled"]
            else "No unpublished dependency updates."
        )
    elif args.command == "prepare":
        planned = json.loads(os.environ["RELEASE_PLAN"])
        if not planned["enabled"]:
            raise RuntimeError("No release was planned.")
        sha = prepare(github, planned)
        output("target_sha", sha)
        output("version", planned["version"])
    elif args.command == "stage":
        version, sha = os.environ["VERSION"], os.environ["RELEASE_SHA"]
        release = stage_release(
            github, version, sha, release_notes(Path(CHANGELOG).read_text(), version)
        )
        output("draft", str(release["draft"]).lower())
    else:
        complete_release(github, os.environ["VERSION"])


if __name__ == "__main__":
    main()
