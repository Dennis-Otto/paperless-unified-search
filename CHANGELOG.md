# Changelog

All notable changes to this project are documented in this file.

## Unreleased

## [0.1.12](https://github.com/Dennis-Otto/paperless-unified-search/compare/v0.1.11...v0.1.12) (2026-10-08)

### Fixed

- **Only the files of the archive account count.** A search result now comes only from a file that belongs to the archive account, the Nextcloud account that owns the synchronized files: the user sees a document when that account shares its file with them. A file that only carries a marker such as `[P123]` in its name no longer counts. With Paperless Sync installed, its account is the archive account; otherwise enter it under *Archive account* in the settings. Without an archive account, the search shows no Paperless documents.

## 0.1.11 - 2026-10-07

### Fixed

- Saving and resetting the administration settings no longer fails with empty request URLs when Nextcloud renders the settings page before it has loaded the app ([#25](https://github.com/Dennis-Otto/paperless-unified-search/issues/25)). The app now declares its routes as attributes on the controller, which Nextcloud 29 and later register for every enabled app.

<!-- Release notes generated using configuration in .github/release.yml at 0d469eb2c8805b7ebdae19c80f23397ba17dfec7 -->

### What's Changed
### Dependencies
* chore(deps): bump anchore/sbom-action from 0.24.2 to 0.24.3 in the actions-routine group across 1 directory by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/33
### Fixes
* fix: register the settings routes as attributes by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-unified-search/pull/37
### Other changes
* ci: look after issues with the issue assistant by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-unified-search/pull/36


**Full Changelog**: https://github.com/Dennis-Otto/paperless-unified-search/compare/v0.1.10...v0.1.11

## 0.1.10 - 2026-10-05

<!-- Release notes generated using configuration in .github/release.yml at 7d763d80d0faaad37519aa317a76079479fa91d3 -->

### What's Changed
### Dependencies
* chore(deps): bump nextcloud from 33.0.9-apache to 33.0.9-apache in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/34


**Full Changelog**: https://github.com/Dennis-Otto/paperless-unified-search/compare/v0.1.9...v0.1.10

## 0.1.9 - 2026-10-05

<!-- Release notes generated using configuration in .github/release.yml at d04c960ee917418d1b90d73a7d9a80409a410f57 -->

### What's Changed
### Dependencies
* chore(deps-dev): bump vimeo/psalm from 6.18.1 to 6.19.1 in the composer-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/31


**Full Changelog**: https://github.com/Dennis-Otto/paperless-unified-search/compare/v0.1.8...v0.1.9

## 0.1.8 - 2026-09-28

<!-- Release notes generated using configuration in .github/release.yml at 998e8c0adf05b310ff805ca4b7dcdbf2f1f28164 -->

### What's Changed
### Dependencies
* chore(deps-dev): bump vimeo/psalm from 6.17.2 to 6.18.1 in the composer-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/26
* chore(deps): bump the actions-routine group with 3 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/27
* chore(deps): bump nextcloud from 33.0.9-apache to 33.0.9-apache in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/29


**Full Changelog**: https://github.com/Dennis-Otto/paperless-unified-search/compare/v0.1.7...v0.1.8

## 0.1.7 - 2026-09-21

<!-- Release notes generated using configuration in .github/release.yml at 11ffe7679b6bd5fb6374c1b4ecbc3a72fe53a98b -->

### What's Changed
### Dependencies
* chore(deps-dev): bump vimeo/psalm from 6.17.0 to 6.17.2 in the composer-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/22
* chore(deps): bump the actions-routine group with 3 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/23
### Other changes
* ci: pin Dependabot auto-merge to the inspected commit by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-unified-search/pull/21


**Full Changelog**: https://github.com/Dennis-Otto/paperless-unified-search/compare/v0.1.6...v0.1.7

## 0.1.6 - 2026-09-19

- Publish checked, signed Nextcloud maintenance releases automatically after merged Dependabot updates.
- Generate categorized release notes, with an optional maintainer introduction for manual releases.
- Require successful PR and main checks on the exact release commit, without cancelling other CI runs.
- Resume interrupted GitHub and App Store publication without duplicate versions or replacing public assets.

<!-- Release notes generated using configuration in .github/release.yml at 257c153dbcdd7d8b843010ff14a7375bf88a2367 -->

### What's Changed
### Dependencies
* chore(deps): bump anchore/sbom-action from 0.24.0 to 0.24.2 in the actions-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/12
* chore(deps): bump nextcloud from 33.0.8-apache to 33.0.8-apache in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/13
* chore(deps): bump nextcloud from 33.0.8-apache to 33.0.8-apache in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/15
* chore(deps-dev): bump the composer-routine group with 2 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/16
* chore(deps): bump the actions-routine group with 3 updates by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/17
* chore(deps): bump nextcloud from 33.0.8-apache to 33.0.9-apache in /tests/e2e in the containers-routine group by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/18
* chore(deps): bump python from 3.14-alpine to 3.14-alpine in /tests/e2e by @dependabot[bot] in https://github.com/Dennis-Otto/paperless-unified-search/pull/14
### Other changes
* fix: authenticate release PRs with GitHub App by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-unified-search/pull/11
* ci: automate protected dependency releases and recover publication by @Dennis-Otto in https://github.com/Dennis-Otto/paperless-unified-search/pull/19


**Full Changelog**: https://github.com/Dennis-Otto/paperless-unified-search/compare/v0.1.5...v0.1.6

## 0.1.5 - 2026-08-27

- Protect `main` behind required CI, E2E, secret-scan, linear-history, and pull-request rules, including release version commits.
- Add weekly grouped Dependabot updates with protected automatic squash merges for patch and minor changes while keeping major updates subject to maintainer approval.
- Add CodeQL analysis and continuous SPDX SBOM generation.
- Publish detached signatures, SBOMs, and public Sigstore provenance with releases.
- Test Nextcloud 33 and 34 in parallel and document project governance, conduct, and support.

## 0.1.4 - 2026-08-26

- Automate semantic version selection, consistency checks, signing, tagging, and publishing in a single release workflow.
- Build release archives from committed version metadata before atomically publishing the commit and tag.
- Pin GitHub Actions to current immutable Node 24-compatible revisions.
- Add reproducible Docker end-to-end coverage for the real Nextcloud search API, access filtering, trusted-service mode, and browser, iOS, and Android response contracts.
- Verify translation catalogs and production-package boundaries automatically in CI and during releases.
- Document mobile compatibility checks, contribution requirements, and the security support policy.
- Recommend the companion Paperless Sync app for native structured document synchronization.

## 0.1.3 - 2026-08-26

- Open search results inside the official Nextcloud iOS and Android apps.
- Add native Nextcloud file metadata for Android and an iOS deep link while preserving the web viewer route for browsers.
- Add regression coverage for browser, iOS, and Android result links.

## 0.1.2 - 2026-08-26

- Add professional English and German App Store descriptions.
- Add real Nextcloud screenshots for unified search, the PDF viewer, and administration settings.

## 0.1.1 - 2026-08-26

- Add an explicit administrator opt-in to include the trusted Paperless server in every global search without requiring the connected-services switch.
- Keep trusted-service mode disabled by default and document that it applies to every Nextcloud user.

## 0.1.0 - 2026-08-25

- Add Paperless-ngx OCR and full-text results to Nextcloud unified search.
- Open synchronized documents directly in Nextcloud's file viewer.
- Filter results against the current user's accessible Nextcloud files.
- Store the Paperless API token in Nextcloud's server-side credentials manager.
- Add an administrator connection test and configuration page.
- Add automated tests, static analysis, style checks, and secret scanning.
