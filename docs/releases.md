---
description: "How Paperless Unified Search is released: the version from the pull request titles, a signed package with SBOM and provenance, published in the App Store."
---

# Releases

Releases follow the [repository blueprint](https://github.com/Dennis-Otto/repo-blueprint): nobody chooses a version or writes release notes by hand.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/from-merged-pull-requests-to-a-release.dark.svg">
  <img src="images/diagrams/from-merged-pull-requests-to-a-release.svg" alt="From merged pull requests to a release: The release bot keeps the pull request chore: release x.y.z for the pull requests merged into main. When the maintainer merges it, or by itself for dependency updates alone, a draft release with its tag is made. krankerl builds the package and check-package.sh checks it; it is signed with the app's certificate, checked again and gets a detached SHA-512 signature. The package, its signature, an SPDX SBOM and the signed build provenance make the published, immutable release, which goes to the Nextcloud App Store. The release verification checks it then and every week.">
</picture>

## The release pull request

Every pull request that changes something for users describes it under `## Unreleased` in `CHANGELOG.md`. The release bot (`.github/workflows/release.yml`) keeps a pull request titled `chore: release x.y.z` up to date with `main`:

- **The version** follows from the titles of the pull requests merged since the last release: `fix` makes a patch, `feat` a minor and `!` or `BREAKING CHANGE` a major version. Every merge decides it anew. A line `Release-As: x.y.z` in the description of a pull request sets it explicitly.
- **The changelog:** the text of *Unreleased* becomes the section of the release; without one, the section lists the pull requests.
- **The files of the version:** `appinfo/info.xml` (`<version>` and the screenshot URLs marked `x-release-please-version`), `version.txt` and `.release-please-manifest.json`.

The pull request needs the same required checks as every other one, the Docker end-to-end tests against every supported Nextcloud version included. A release of dependency updates alone merges itself; every other release waits for the maintainer to merge it.

## Publication

Merging the release pull request creates the release as a draft, with its tag, and then:

1. krankerl builds the package of the tagged commit; `scripts/check-package.sh` checks what it holds.
2. The package is signed with the app's certificate (`occ integrity:sign-app`) and checked again, and a detached SHA-512 signature is made and verified with the public key of the certificate.
3. The release gets the package `paperless_unified_search.tar.gz`, its signature `paperless_unified_search.tar.gz.sig`, an SPDX SBOM, and the signed build provenance of every asset (`provenance.sigstore.json` and `provenance.intoto.jsonl`).
4. The complete release is published; GitHub keeps it immutable from then on.
5. The same package is submitted to the Nextcloud App Store.
6. The release verification (`.github/workflows/verify-release.yml`) checks the release as its users can: every attestation, the SBOM, the immutability, the signed commit, and the version in the App Store. It checks the latest release every week as well.

## A new version of Nextcloud

`appinfo/info.xml` names the versions of Nextcloud that the app installs on, from `min-version` to `max-version`, and the end-to-end tests run against each of them. Every Wednesday the upstream bot (`.github/workflows/upstream.yml`) compares `max-version` with the newest release of Nextcloud. For a new major version, it opens the pull request `feat: support Nextcloud <version>`, which raises `max-version` and adds `changelog.d/nextcloud-<version>.md` for the release notes.

The READMEs, the pages of the website in English and German and the descriptions of the App Store name the versions too, and the step *Checks of this project* of `scripts/check.sh` fails while one of them names others. `scripts/versions.sh` holds every such place in one list, for the check and for the fix alike. When the step fails, the fix bot runs `bash scripts/versions.sh --write` and commits what it wrote onto the branch of the pull request; the checks run again, and the pull request merges itself once they pass, unless the repository variable `UPSTREAM_AUTOMERGE` is `off`.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/when-nextcloud-releases-a-new-version.dark.svg">
  <img src="images/diagrams/when-nextcloud-releases-a-new-version.svg" alt="When Nextcloud releases a new version: Every Wednesday the upstream bot compares max-version of appinfo/info.xml with the newest release of Nextcloud. For a new major version it opens a pull request that raises max-version and adds a fragment of the changelog. Its step Checks of this project fails, because the READMEs, the pages of the website and the descriptions of the App Store still name the old versions. The fix bot runs bash scripts/versions.sh --write, which writes the new version into each place and adds a column to each table of compatibility, and commits that onto the branch of the pull request. The checks run again, the end-to-end tests also against the new version, and once they pass the pull request merges itself.">
</picture>

A new `max-version` adds a column to each table of compatibility, with the cells of the newest column. When the oldest version of Nextcloud reaches its end of life, raise `min-version` yourself: the same command drops its column. It writes a new `min-version` of PHP the same way. What no command can fix, it names, and it ends with an error: a place that is gone, a row of a table without a cell for each column, or a description of the App Store without the line of its versions.

```bash
bash scripts/versions.sh           # check every place, as scripts/check-project.sh does
bash scripts/versions.sh --write   # write the versions of appinfo/info.xml there
```

## Secrets and recovery

The `release` environment allows only `main`. It holds `APP_PRIVATE_KEY` (the key of the app's certificate), `APPSTORE_TOKEN` and `RELEASE_AUTOMATION_PRIVATE_KEY` (the release app, whose client ID is the repository variable `RELEASE_AUTOMATION_CLIENT_ID`). Every token is short-lived and scoped to what its job needs.

If a job fails, re-run the failed jobs of the Release workflow: a draft is completed, and the App Store accepts the same version again without a second release. A published release is never replaced.

Registering the app in the App Store, needed once and again only for a new certificate, is the manual workflow *Register app in Nextcloud App Store*.
