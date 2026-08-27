# Changelog

All notable changes to this project are documented in this file.

## Unreleased

- Protect `main` behind required CI, E2E, secret-scan, linear-history, and pull-request rules, including release version commits.
- Add weekly grouped Dependabot updates with protected automatic squash merges for patch and minor changes while keeping major updates and releases manual.
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
