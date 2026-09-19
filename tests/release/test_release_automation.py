# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Regression tests for release eligibility, protection gates and recovery."""

import copy
import importlib.util
import os
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "release_automation", ROOT / ".github/scripts/release_automation.py"
)
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)
REPO = "example/paperless"
INFO = "<info><version>0.1.0</version><screenshot>https://example/v0.1.0/screenshots/a.png</screenshot></info>"
LOG = "# Changelog\n\n## Unreleased\n\n- Handwritten change.\n\n## 0.1.0 - 2026-08-26\n\n- Original release.\n"


def pull(number=1, author="dependabot[bot]", sha="dependency", version=None):
    return {
        "number": number,
        "user": {"login": author},
        "merged_at": "today",
        "merged": True,
        "merge_commit_sha": sha,
        "base": {"ref": "main"},
        "state": "closed",
        "head": {
            "ref": f"release/v{version}" if version else "dependabot/composer/packages",
            "sha": "head",
            "repo": {"full_name": REPO},
        },
        "body": release.PR_MARKER + "\n\nRelease",
        "html_url": "https://example/pr/1",
    }


def published(version="0.1.0", body="Legacy release", draft=False):
    return {
        "id": 1,
        "tag_name": f"v{version}",
        "body": body,
        "draft": draft,
        "prerelease": False,
    }


class FakePlanGitHub:
    repository = REPO

    def __init__(self):
        self.releases = [published()]
        self.pulls = [pull()]
        self.info = INFO
        self.commits = [[{"sha": "dependency"}]]
        self.status = "ahead"

    def main(self):
        return "main-sha"

    def file(self, path, ref):
        return self.info

    def items(self, path, key=None):
        return self.releases if path.startswith("releases?") else self.pulls

    def api(self, path, **kwargs):
        if path.startswith("commits/"):
            return {"sha": "published-sha"}
        if path.startswith("compare/"):
            return [{"status": self.status, "commits": page} for page in self.commits]
        raise AssertionError(path)


class MetadataTests(unittest.TestCase):
    def test_semver_is_numeric_and_increments_reset_lower_components(self):
        self.assertGreater(
            release.version_tuple("v0.1.10"), release.version_tuple("v0.1.9")
        )
        self.assertEqual(release.next_version("0.1.9", "patch"), "0.1.10")
        self.assertEqual(release.next_version("0.1.9", "minor"), "0.2.0")
        self.assertEqual(release.next_version("0.1.9", "major"), "1.0.0")
        for bad in ("0.01.0", "v1.2", "1.2.3-beta", "1.2.3\n", "$(echo nope)"):
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                release.version_tuple(bad)

    def test_bump_preserves_manual_notes_history_and_versioned_screenshots(self):
        info = release.bump_info(INFO, "0.1.1")
        log = release.bump_changelog(
            LOG, "0.1.1", "## What's Changed\n* Updated #1", "Welcome", "2026-09-19"
        )
        release.validate_metadata(
            INFO, info, LOG, log, "0.1.1", [release.INFO, release.CHANGELOG]
        )
        self.assertIn("/v0.1.1/screenshots/", info)
        self.assertIn("## Unreleased\n\n## 0.1.1 - 2026-09-19\n\nWelcome", log)
        notes = release.release_notes(log, "0.1.1")
        self.assertIn("Handwritten change", notes)
        self.assertIn("### What's Changed", notes)
        self.assertNotIn("Original release", notes)

    def test_no_handwritten_entry_is_needed_for_dependency_release(self):
        log = release.bump_changelog(
            LOG.replace("- Handwritten change.", ""), "0.1.1", "* Bump dependency #1"
        )
        self.assertIn("Bump dependency", release.release_notes(log, "0.1.1"))

    def test_rejects_code_changes_removed_notes_and_rewritten_history(self):
        info = release.bump_info(INFO, "0.1.1")
        log = release.bump_changelog(LOG, "0.1.1", "* Dependency #1")
        cases = [
            (
                info.replace("<info>", '<info hacked="true">'),
                log,
                [release.INFO, release.CHANGELOG],
            ),
            (info, log, [release.INFO, release.CHANGELOG, "lib/Backdoor.php"]),
            (
                info,
                log.replace("Original release", "Changed history"),
                [release.INFO, release.CHANGELOG],
            ),
            (
                info,
                log.replace("- Handwritten change.", ""),
                [release.INFO, release.CHANGELOG],
            ),
            (
                info,
                log.replace("* Dependency #1", "## 9.9.9 - 2026-01-01\n\n* Fake"),
                [release.INFO, release.CHANGELOG],
            ),
        ]
        for candidate, candidate_log, files in cases:
            with self.subTest(files=files), self.assertRaises(RuntimeError):
                release.validate_metadata(
                    INFO, candidate, LOG, candidate_log, "0.1.1", files
                )


class PlanningTests(unittest.TestCase):
    def test_open_manual_minor_release_is_resumed_instead_of_creating_patch(self):
        github = FakePlanGitHub()
        pr = pull(2, "release-app[bot]", version="0.2.0")
        pr.update(state="open", merged_at=None, merged=False)
        github.pulls.append(pr)
        planned = release.plan(github, True, "patch", "")
        self.assertTrue(planned["enabled"])
        self.assertEqual(planned["version"], "0.2.0")

    def test_only_merged_same_repository_dependabot_changes_are_eligible(self):
        good = pull()
        examples = [good]
        for field in ("author", "repository", "base", "merged", "commit"):
            candidate = copy.deepcopy(good)
            if field == "author":
                candidate["user"]["login"] = "somebody"
            elif field == "repository":
                candidate["head"]["repo"] = None
            elif field == "base":
                candidate["base"]["ref"] = "other"
            elif field == "merged":
                candidate["merged_at"] = None
            else:
                candidate["merge_commit_sha"] = "already-released"
            examples.append(candidate)
        self.assertEqual(release.dependency_prs(examples, {"dependency"}, REPO), [good])

    def test_dependencies_after_first_compare_page_are_found(self):
        github = FakePlanGitHub()
        github.commits = [[{"sha": "unrelated"}], [{"sha": "dependency"}]]
        planned = release.plan(github, True, "major", "")
        self.assertTrue(planned["enabled"])
        self.assertEqual(planned["version"], "0.1.1")
        self.assertEqual(planned["dependencies"], [1])

    def test_no_update_and_unmerged_dependencies_do_not_release(self):
        github = FakePlanGitHub()
        github.commits = [[]]
        self.assertFalse(release.plan(github, True, "patch", "")["enabled"])
        github.commits = [[{"sha": "unrelated"}]]
        self.assertFalse(release.plan(github, True, "patch", "")["enabled"])

    def test_initial_release_keeps_current_version_and_requires_manual_dispatch(self):
        github = FakePlanGitHub()
        github.releases = []
        self.assertFalse(release.plan(github, True, "patch", "")["enabled"])
        planned = release.plan(github, False, "patch", "")
        self.assertTrue(planned["enabled"])
        self.assertEqual(planned["version"], "0.1.0")
        self.assertEqual(planned["target_sha"], "main-sha")

    def test_completed_release_does_not_create_duplicate_version(self):
        github = FakePlanGitHub()
        github.releases = [published("0.1.1", release.COMPLETE)]
        github.info = release.bump_info(INFO, "0.1.1")
        github.commits = [[]]
        self.assertFalse(release.plan(github, True, "patch", "")["enabled"])

    def test_resume_after_github_upload_before_appstore_does_not_bump(self):
        for draft in (False, True):
            github = FakePlanGitHub()
            github.releases += [
                published("0.1.1", "Notes\n\n" + release.PENDING, draft)
            ]
            github.info = release.bump_info(INFO, "0.1.1")
            planned = release.plan(github, True, "patch", "")
            self.assertTrue(planned["enabled"])
            self.assertEqual(planned["version"], "0.1.1")
            self.assertEqual(planned["target_sha"], "published-sha")

    def test_resume_merged_release_pr_before_tag_creation(self):
        github = FakePlanGitHub()
        github.info = release.bump_info(INFO, "0.1.1")
        github.pulls += [pull(2, "release-app[bot]", "release-sha", "0.1.1")]
        planned = release.plan(github, True, "major", "")
        self.assertEqual(planned["version"], "0.1.1")
        self.assertEqual(planned["target_sha"], "release-sha")
        self.assertEqual(planned["pr"], 2)

    def test_divergent_tags_and_unmanaged_version_bumps_are_rejected(self):
        github = FakePlanGitHub()
        github.status = "diverged"
        with self.assertRaises(RuntimeError):
            release.plan(github, True, "patch", "")
        github.status = "ahead"
        github.info = release.bump_info(INFO, "0.1.1")
        with self.assertRaises(RuntimeError):
            release.plan(github, True, "patch", "")


def workflow_run(workflow, number=7, sha="candidate", run_id=1):
    return {
        "id": run_id,
        "path": ".github/workflows/" + workflow,
        "head_sha": sha,
        "event": "pull_request" if number else "push",
        "head_branch": "main",
        "pull_requests": [{"number": number}] if number else [],
        "run_attempt": 2,
        "status": "completed",
        "conclusion": "success",
    }


class GateTests(unittest.TestCase):
    def github(self, number=7):
        expected = release.PR_CHECKS if number else release.MAIN_CHECKS
        runs = [
            workflow_run(workflow, number, run_id=index)
            for index, workflow in enumerate(expected, 1)
        ]
        jobs = {
            str(run["id"]): [
                {"name": name, "status": "completed", "conclusion": "success"}
                for name in expected[run["path"].split("/")[-1]]
            ]
            for run in runs
        }
        github = Mock()

        def items(path, key):
            if key == "workflow_runs":
                return runs
            self.assertIn("/attempts/2/jobs?", path)
            return jobs[path.split("/")[2]]

        github.items.side_effect = items
        return github, runs, jobs

    def test_checks_must_belong_to_exact_commit_and_actual_pr(self):
        github, runs, _ = self.github()
        self.assertTrue(release.checks_ready(github, "candidate", 7))
        for field, value in (
            ("event", "workflow_dispatch"),
            ("head_sha", "old"),
            ("pull_requests", [{"number": 8}]),
        ):
            original = runs[0][field]
            runs[0][field] = value
            self.assertFalse(release.checks_ready(github, "candidate", 7))
            runs[0][field] = original

    def test_latest_run_and_latest_attempt_win(self):
        github, runs, _ = self.github()
        older = dict(runs[0], id=0, conclusion="failure")
        runs.append(older)
        self.assertTrue(release.checks_ready(github, "candidate", 7))
        runs.append(dict(runs[0], id=99, conclusion="cancelled"))
        with self.assertRaisesRegex(RuntimeError, "cancelled"):
            release.checks_ready(github, "candidate", 7)

    def test_every_job_including_matrix_and_scorecard_must_pass(self):
        github, runs, jobs = self.github(None)
        self.assertTrue(release.checks_ready(github, "candidate"))
        self.assertIn("scorecard.yml", {run["path"].split("/")[-1] for run in runs})
        for conclusion in (
            "failure",
            "cancelled",
            "skipped",
            "timed_out",
            "action_required",
            "neutral",
        ):
            jobs["2"].append(
                {
                    "name": "Nextcloud additional version",
                    "status": "completed",
                    "conclusion": conclusion,
                }
            )
            with self.subTest(conclusion=conclusion), self.assertRaises(RuntimeError):
                release.checks_ready(github, "candidate")
            jobs["2"].pop()

    def test_missing_expected_job_never_passes(self):
        github, _, jobs = self.github()
        jobs["1"] = []
        with self.assertRaises(RuntimeError):
            release.checks_ready(github, "candidate", 7)

    def test_missing_or_running_workflow_waits(self):
        github, runs, _ = self.github()
        runs[0]["status"] = "in_progress"
        self.assertFalse(release.checks_ready(github, "candidate", 7))
        runs.pop(0)
        self.assertFalse(release.checks_ready(github, "candidate", 7))

    def test_merge_waits_for_aggregate_protection_including_push_checks(self):
        github = Mock()
        github.main.return_value = "base"
        github.api.return_value = {
            "head": {"sha": "candidate"},
            "state": "open",
            "mergeable_state": "blocked",
        }
        self.assertFalse(release.merge_ready(github, 7, "candidate", "base"))
        github.api.return_value["mergeable_state"] = "clean"
        self.assertTrue(release.merge_ready(github, 7, "candidate", "base"))
        github.api.return_value["head"]["sha"] = "tampered"
        with self.assertRaises(RuntimeError):
            release.merge_ready(github, 7, "candidate", "base")

    def test_main_advance_causes_revalidation(self):
        github = Mock()
        github.main.return_value = "new-base"
        github.api.return_value = {
            "head": {"sha": "candidate"},
            "state": "open",
            "mergeable_state": "behind",
        }
        self.assertTrue(release.merge_ready(github, 7, "candidate", "old-base"))


class RecoveryTests(unittest.TestCase):
    @patch.dict(os.environ, {"GH_RELEASE_APP_SLUG": "release-app"})
    def test_candidate_merge_and_main_gate_use_exact_shas(self):
        github = Mock(repository=REPO)
        pr = pull(7, "release-app[bot]", version="0.1.1")
        pr.update(state="open", merged_at=None, merged=False)
        github.main.return_value = "base"
        github.api.side_effect = lambda path, **kw: (
            pr
            if path == "pulls/7"
            else {"status": "ahead"}
            if path.startswith("compare/")
            else {"merged": True, "sha": "merged-sha"}
            if path == "pulls/7/merge"
            else None
        )
        with (
            patch.object(release, "prepare_pr", return_value=pr),
            patch.object(release, "validate_candidate") as validate,
            patch.object(release, "checks_ready", return_value=True) as checks,
            patch.object(release, "merge_ready", return_value=True),
            patch.object(
                release,
                "bot_signoff",
                return_value="Signed-off-by: bot <bot@example.org>",
            ),
        ):
            sha = release.prepare(github, {"version": "0.1.1", "target_sha": ""})
        self.assertEqual(sha, "merged-sha")
        validate.assert_called_once_with(github, "base", "head", "0.1.1")
        self.assertEqual(checks.call_args_list[0].args, (github, "head", 7))
        self.assertEqual(checks.call_args_list[1].args, (github, "merged-sha"))
        merge = next(
            call
            for call in github.api.call_args_list
            if call.args[0] == "pulls/7/merge"
        )
        self.assertEqual(merge.kwargs["data"]["sha"], "head")
        self.assertIn("Signed-off-by:", merge.kwargs["data"]["commit_message"])

    def test_failed_main_checks_prevent_preparation_from_releasing_sha(self):
        github = Mock(repository=REPO)
        github.main.return_value = "main"
        github.api.return_value = {"status": "ahead"}
        github.file.return_value = release.bump_info(INFO, "0.1.1")
        with (
            patch.object(
                release, "checks_ready", side_effect=RuntimeError("failed main CI")
            ),
            self.assertRaisesRegex(RuntimeError, "failed main CI"),
        ):
            release.prepare(github, {"version": "0.1.1", "target_sha": "merged-sha"})
        self.assertFalse(
            any(call.kwargs.get("write") for call in github.api.call_args_list)
        )

    @patch.dict(os.environ, {"GH_RELEASE_APP_SLUG": "release-app"})
    def test_owned_pr_validation_and_closed_pr_pause(self):
        pr = pull(author="release-app[bot]", version="0.1.1")
        pr.update(merged_at=None, merged=False)
        self.assertTrue(release.owned_pr(pr, "0.1.1", REPO))
        github = Mock(repository=REPO)
        github.items.return_value = [pr]
        with self.assertRaisesRegex(RuntimeError, "paused"):
            release.prepare_pr(github, {"version": "0.1.1"})
        github.api.assert_not_called()
        pr["user"]["login"] = "someone-else"
        self.assertFalse(release.owned_pr(pr, "0.1.1", REPO))

    def test_published_pending_assets_resume_without_mutation(self):
        github = Mock()
        existing = published("0.1.1", "Notes\n\n" + release.PENDING)
        github.items.return_value = [existing]
        github.api.return_value = {"sha": "release-sha"}
        self.assertIs(
            release.stage_release(github, "0.1.1", "release-sha", "new notes"), existing
        )
        self.assertEqual(github.api.call_count, 1)
        self.assertNotIn("method", github.api.call_args.kwargs)

    def test_completed_and_unmanaged_releases_are_never_overwritten(self):
        github = Mock()
        for body in ("Legacy release", release.COMPLETE):
            github.items.return_value = [published("0.1.1", body)]
            with self.assertRaises(RuntimeError):
                release.stage_release(github, "0.1.1", "sha", "new notes")
        github.api.assert_not_called()

    def test_changed_tag_is_rejected(self):
        github = Mock()
        github.items.return_value = [published("0.1.1", release.PENDING)]
        github.api.return_value = {"sha": "tampered"}
        with self.assertRaisesRegex(RuntimeError, "tag changed"):
            release.stage_release(github, "0.1.1", "expected", "notes")

    def test_completion_marker_only_after_publication(self):
        github = Mock()
        existing = published("0.1.1", "Notes\n\n" + release.PENDING, draft=True)
        github.api.return_value = existing
        with self.assertRaises(RuntimeError):
            release.complete_release(github, "0.1.1")
        existing["draft"] = False
        release.complete_release(github, "0.1.1")
        self.assertTrue(
            github.api.call_args.kwargs["data"]["body"].endswith(release.COMPLETE)
        )


class WorkflowContractTests(unittest.TestCase):
    def test_ci_workflows_cannot_cancel_each_other(self):
        for workflow in set(release.PR_CHECKS) | set(release.MAIN_CHECKS):
            text = (ROOT / ".github/workflows" / workflow).read_text()
            with self.subTest(workflow=workflow):
                self.assertNotIn("\nconcurrency:", text)

    def test_release_uses_app_identity_main_environment_and_safe_checkout(self):
        text = (ROOT / ".github/workflows/release.yml").read_text()
        self.assertIn("environment: release", text)
        self.assertIn("github.ref == 'refs/heads/main'", text)
        self.assertIn("actions/create-github-app-token@", text)
        self.assertIn("permission-pull-requests: write", text)
        self.assertNotIn("persist-credentials: true", text)
        self.assertNotIn("--admin", text)
        self.assertIn("cron: '13,43 * * * *'", text)
        self.assertLess(
            text.index("Publish release to Nextcloud App Store"),
            text.index("Mark GitHub and App Store publication complete"),
        )

    def test_metadata_pipeline_handles_real_repository_files(self):
        info, log = (
            (ROOT / release.INFO).read_text(),
            (ROOT / release.CHANGELOG).read_text(),
        )
        version = release.next_version(release.current_version(info), "patch")
        release.validate_metadata(
            info,
            release.bump_info(info, version),
            log,
            release.bump_changelog(log, version, "* Dependency update #123"),
            version,
            [release.INFO, release.CHANGELOG],
        )


if __name__ == "__main__":
    unittest.main()
