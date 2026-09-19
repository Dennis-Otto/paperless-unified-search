# Releases and dependency maintenance

## Automatic maintenance releases

Dependabot checks Composer, GitHub Actions and Docker Compose weekly. Grouped patch and minor updates merge only after protected PR checks pass. Major dependency updates require a maintainer merge; Nextcloud major compatibility updates remain explicitly maintained. Once a Dependabot PR is merged, either type can trigger an **app patch release**. A dependency's version increment does not determine the app's semantic version increment.

The Release workflow runs on merged Dependabot PRs and at minutes 13 and 43 each hour. The scheduled reconciliation recovers events suppressed by `GITHUB_TOKEN` and interrupted publication. It compares every commit since the latest stable tag, paginates API results, and batches unpublished dependency PRs. It does nothing when no dependency changes remain. The first release always requires a manual dispatch and uses the version already in `appinfo/info.xml`.

Changes already on `main` are included in the next release, including maintenance of development dependencies and build workflows. Automatic releases do not imply new app functionality. Significant application or compatibility changes should still receive the appropriate maintainer-selected version increment.

## Manual releases and changelogs

Add user-facing notes to `CHANGELOG.md` under `Unreleased`. Run **Actions → Release → Run workflow** from `main`, choose `mode: manual` and `patch`, `minor`, or `major`. The optional `introduction` appears first. The workflow preserves handwritten notes and appends GitHub-generated categories for security, dependencies, fixes, improvements and other changes. Version PRs carrying the `release` label are excluded; `skip-changelog` can exclude administrative PRs.

Empty handwritten notes do not block dependency releases: generated PR entries and comparison links explain what changed. The resulting notes are committed to the version's changelog section, included in the signed app archive, and displayed on the GitHub release.

To process outstanding Dependabot changes immediately, dispatch the same workflow with `mode: dependencies`. Its increment is always patch.

## Protection and publication

1. A short-lived GitHub App token creates a GitHub-verified, DCO-signed-off version commit and PR on `release/vX.Y.Z`. Only the version, screenshot URLs and changelog may change. PR identity, repository, base and contents are checked before merging.
2. Real `pull_request` workflows must pass on the current candidate: PHP and JavaScript checks, DCO, Composer validation and audit, Nextcloud metadata, translations, PHPUnit, PHP style, Psalm, unsigned package checks, both Nextcloud Docker E2E jobs, Dependency Review, CodeQL, Gitleaks and SBOM generation. Manually dispatched checks cannot substitute for PR checks.
3. The workflow also waits for GitHub's aggregate branch protection result, including parallel push checks. If main advances, it brings main into the owned release branch with a DCO-signed-off merge and checks the resulting candidate again. Conflicts require resolution; closing the PR pauses publication.
4. After a SHA-guarded protected squash merge, every expected main push workflow must pass on that exact commit, including OpenSSF Scorecard. Missing, failed, skipped, cancelled or approval-gated checks block publication. The latest run and run attempt are used; older successes cannot mask a newer failure. CI workflows do not cancel one another.
5. The checked commit is packaged with the checksum-verified Krankerl builder and signed with the official Nextcloud app certificate. Archive boundaries, the Nextcloud signature and detached SHA-512 signature are checked. Each release includes the archive, detached signature, SPDX SBOM and public Sigstore provenance.
6. Assets are assembled in a draft before public exposure. Only after the complete asset set is published is the same archive submitted to the Nextcloud App Store. The workflow records completion only after the App Store accepts the version.

The `release` environment must allow only the `main` branch, not tags or arbitrary branches. It holds `APP_PRIVATE_KEY`, `APPSTORE_TOKEN` and `RELEASE_AUTOMATION_PRIVATE_KEY`. `RELEASE_AUTOMATION_CLIENT_ID` is a repository variable. The App is installed only on selected repositories; each token is scoped to the current repository, uses Contents and Pull requests write permissions, and is revoked when the job ends. Checkout never persists credentials. No branch-protection bypass is used.

## Recovery

Rerun a failed workflow, dispatch `mode: dependencies`, or let the next scheduled reconciliation resume it. The workflow reuses an existing owned version PR, merged release commit or incomplete release instead of incrementing again. A PR closed without merging must be reopened explicitly. Fix or rerun genuinely failing checks before retrying publication; they are never ignored.

Unfinished draft assets can be rebuilt. After a release becomes public, retries download and verify the original archive, detached signature, SBOM and attestations; they never replace those public assets. Retrying the Nextcloud release API updates the same app version and is safe if a previous response was lost. An invisible release-body marker distinguishes an unfinished App Store delivery from a completed release. Legacy releases without that marker are treated as completed and are never overwritten.

Release and signature-repair workflows share one serialization group. Registration of the app and replacement of its certificate remain separate, manual administrative operations.

## Regression tests

Run `python3 -m unittest discover -s tests/release -v`. These standard-library tests cover eligibility, pagination, version ordering, metadata tampering, PR ownership, exact-commit checks, cancelled/missing jobs, aggregate merge readiness, interrupted publication and immutable public assets. They run in CI alongside the existing Nextcloud test suite.
