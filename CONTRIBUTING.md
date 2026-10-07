# Contributing

Contributions are welcome through issues and pull requests.
Participation follows [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md), and project decision-making is described in [GOVERNANCE.md](GOVERNANCE.md).
Use [SUPPORT.md](SUPPORT.md) to choose the correct public support channel and [SECURITY.md](SECURITY.md) for private vulnerability reports.

All changes reach the protected `main` branch through pull requests that pass every required check.

## Requirements for changes

- Changes need tests, and the checks of `scripts/check.sh` pass.
- Every commit carries a [Developer Certificate of Origin](https://developercertificate.org/) sign-off, `Signed-off-by: Your Name <you@example.com>`, which `git commit -s` adds.
- Name the issue that a pull request fixes with `Fixes #123` in its description. The issue stays open until a release ships the fix and then closes with a link to the release.
- Do not include real credentials, production endpoints, private documents, personal metadata, or screenshots containing personal data. Use reserved example domains and clearly synthetic values in tests and documentation.

## Workflow

1. Open an issue first for anything larger than a small fix, so we can agree on the approach.
2. Create a branch from `main`.
3. Describe what changes for users under `## Unreleased` in `CHANGELOG.md`, in the words of a user; the check *changelog* asks for it in every `feat`, `fix` or `perf` pull request.
4. A change that makes or changes a decision that shapes the project, such as a supported platform or the way of releasing, records it in [`docs/decisions/`](docs/decisions/README.md).
5. Open a pull request whose title is a [Conventional Commit](https://www.conventionalcommits.org/), such as `feat(settings): add a dark mode` or `fix: keep the token secret`. Mark a breaking change with `!`. Pull requests are squashed into one commit named after the title, and the title decides the next version: `fix` a patch, `feat` a minor, `!` a major version.

## Checks

```sh
composer install
bash scripts/check.sh
```

The end-to-end tests run the app in Docker against every supported Nextcloud version and a mock of Paperless-ngx:

```sh
bash tests/e2e/run.sh
```

To run them before every push on its own, turn on the hook of the repository once:

```sh
git config core.hooksPath .githooks
```

The dev container in `.devcontainer/` has all of this set up, for VS Code and for GitHub Codespaces: open the repository in it, and `bash scripts/check.sh` runs.

## Releases

The release bot keeps a pull request titled `chore: release x.y.z` with the next version, up to date with `main` and decided anew with every merge. Its section of the changelog is the text of Unreleased; without one, it lists the pull requests. Merging it creates the release with its package, SBOM and signed provenance, and delivers it to the Nextcloud App Store. A release of dependency updates merges and publishes itself.

A line `Release-As: 2.0.0-beta.1` in the description of a pull request sets the version of the next release, for a prerelease for example. A prerelease keeps the text of Unreleased for the release that follows it.

By contributing, you agree that your contribution is licensed under the AGPL-3.0-or-later license of this project.
