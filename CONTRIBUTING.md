# Contributing

Contributions are welcome through issues and pull requests.
Participation follows [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md), and project decision-making is described in [GOVERNANCE.md](GOVERNANCE.md).
Use [SUPPORT.md](SUPPORT.md) to choose the correct public support channel and [SECURITY.md](SECURITY.md) for private vulnerability reports.

All changes reach the protected `main` branch through pull requests that pass every required check.
Coding agents find these rules in short in [AGENTS.md](AGENTS.md), with the files that belong to the blueprint and are changed there.

## Requirements for changes

- Changes need tests, and the checks of `scripts/check.sh` pass.
- Every commit carries a [Developer Certificate of Origin](https://developercertificate.org/) sign-off, `Signed-off-by: Your Name <you@example.com>`, which `git commit -s` adds.
- Name the issue that a pull request fixes with `Fixes #123` in its description. The issue stays open until a release ships the fix and then closes with a link to the release.
- Do not include real credentials, production endpoints, private documents, personal metadata, or screenshots containing personal data. Use reserved example domains and clearly synthetic values in tests and documentation.

## Tests

New functionality comes with tests in the automated test suite, in the same pull request, and so does every change of behavior. A bug fix comes with a test that fails without the fix, so that the bug can't return unnoticed. `scripts/check.sh` fails when a line of `lib/` runs in no test. A pull request without the tests it needs is not merged.

## Coding standards

- **PHP** follows the [Nextcloud coding standard](https://github.com/nextcloud/coding-standard), which builds on PSR-1 and PSR-2 and indents with tabs, as php-cs-fixer checks it, and passes Psalm at its strictest level, 1.
- **Shell scripts** pass [ShellCheck](https://www.shellcheck.net/), **workflows** pass actionlint and zizmor's audit of their security, and **Markdown** follows the rules of markdownlint in `.markdownlint.jsonc`.
- **Every text file** has LF line endings, no trailing whitespace and a line break at its end; `.editorconfig` sets up most editors for it.

`scripts/check.sh` and the Lint workflow check these standards on every pull request, which merges only when they pass. An exception to a rule is rare and is marked at its place in the code, with its reason in a comment.

## Workflow

1. Open an issue first for anything larger than a small fix, so we can agree on the approach.
2. Create a branch from `main`.
3. Describe what changes for users, in the words of a user, in a new file in `changelog.d/` named after your branch without its type, such as `changelog.d/dark-mode.md` for the branch `feat/dark-mode`, below the heading of its kind of change, such as `### Features` or `### Bug fixes`. The check *changelog* asks for it in every `feat`, `fix` or `perf` pull request, and checks it as the release takes it in. A file of its own conflicts with no other pull request; a line under `## Unreleased` in `CHANGELOG.md` works as well, but conflicts with each pull request that adds one at the same place.
4. A change that makes or changes a decision that shapes the project, such as a supported platform or the way of releasing, records it in [`docs/decisions/`](docs/decisions/README.md).
5. Open a pull request whose title is a [Conventional Commit](https://www.conventionalcommits.org/), such as `feat(settings): add a dark mode` or `fix: keep the token secret`. Mark a breaking change with `!`. Pull requests are squashed into one commit named after the title, and the title decides the next version: `fix` a patch, `feat` a minor, `!` a major version.

### The changelog

A fragment holds the entries of one pull request, each below the heading of its kind of change:

```markdown
### Features

- **Dark mode:** the settings follow the theme of the system.
```

The headings are those of the blueprint, `Breaking changes`, `Features`, `Changes`, `Bug fixes` and `Performance`, those of [Keep a Changelog](https://keepachangelog.com/), `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` and `Security`, and every other one that `CHANGELOG.md` has already. The release bot moves the entries of every fragment and of Unreleased into the section of the next release, each kind of change below one heading, and deletes the fragments in the same commit of the release pull request. `python3 .github/blueprint.py fragments` checks them as the release takes them in.

## Checks

```sh
composer install
bash scripts/check.sh
```

The end-to-end tests run the app in Docker against every supported Nextcloud version at the same time and a mock of Paperless-ngx; `bash tests/e2e/run.sh` runs them against one version:

```sh
bash tests/e2e/run-all.sh
```

To run them before every push on its own, turn on the hook of the repository once:

```sh
git config core.hooksPath .githooks
```

Git runs the hook only when its file is executable. Windows has no such mode and adds the file as a plain one, which `scripts/check.sh` reports; `git update-index --chmod=+x .githooks/pre-push` and a commit make it executable.

The dev container in `.devcontainer/` has all of this set up, for VS Code and for GitHub Codespaces: open the repository in it, and `bash scripts/check.sh` runs.

## When a check fails

A check that fails explains itself. The PR coach writes what the check found, why that matters and how to fix it, with the commands to run, into the summary of the check and as its annotation; the pull request also shows the annotations of a tool at the lines that it names. After the checks, the coach keeps one comment on the pull request up to date with a section for each check that failed, also on a pull request from a fork, and a title that is no Conventional Commit gets one that passes. The comment follows every push and says so once every check passes. On your machine, `scripts/check.sh` explains the step that stopped it in the same words.

The explanations are in `.github/checks.toml`, which the blueprint keeps current, and in `.github/checks.project.toml` for the checks of this project alone, whose explanations win. A check that you add to the required checks of `.github/repository.project.toml`, or that `scripts/check-project.sh` runs, comes with its explanation in `.github/checks.project.toml`, in the same pull request: what it found, why that matters and how to fix it, with the commands and a link to read more. `python3 .github/blueprint.py coach verify` lists every required check and every step of `scripts/check.sh` without one. The comment follows the workflows of the checks that the blueprint sets up; for a workflow of this project alone, such as one of visual tests, name it in the answer `test_workflows` of `.copier-answers.yml`, which the next update of the blueprint brings into `.github/workflows/after-checks.yml`.

## Website

MkDocs builds the website from `mkdocs.yml` and the pages in `docs/`. The check *docs* builds it strictly in every pull request, so that a broken link fails, and every change of `main` publishes it on GitHub Pages. To see it while you write, at <http://127.0.0.1:8000>:

```sh
python3 -m venv .venv-docs
.venv-docs/bin/pip install --require-hashes -r .github/docs-requirements.txt
.venv-docs/bin/mkdocs serve
```

On Windows, use `py -3` in place of `python3` and `.venv-docs/Scripts/` in place of `.venv-docs/bin/`; the dev container sets these tools up on its own. With them, `bash scripts/check.sh` builds the website strictly as well, as the check *docs* does, and without them it leaves that to the CI. The preview cards for social media come from the CI alone, because they need the Cairo library, which Windows doesn't have; where it is installed, `CI=true` before the command draws them too.

## Pages in one column

The READMEs, this guide and the pages of `docs/` have one column, so that they read well in the GitHub app and on a phone, which keep columns side by side and squeeze them until the text is a few words wide and the pictures tiny:

- A table holds tabular data, never a layout: no `<table>` that sets a picture beside text, and no `<td width="…">`.
- A picture or a GIF comes at full width below the text that it illustrates.
- A long list, such as every option or every entity, goes into `<details>` with a `<summary>` that names it, so that the page stays short.

`scripts/check.sh` fails on a `<table>` with a picture and on a cell with a width in a README, a CONTRIBUTING file or a page of `docs/`, with its file and line. `python3 .github/blueprint.py pages --fix` puts the cells of such a table one below the other, the text before its picture, and leaves out the widths; a table inside a table it leaves to you.

## Diagrams

The GitHub app, the stores of the projects and other renderers of Markdown show a `mermaid` block as code, so a diagram is a picture of its source. The source is Mermaid in `docs/diagrams/<name>.mmd`, and `bash scripts/diagrams.sh` draws it, with Docker, as `docs/images/diagrams/<name>.svg` on a white card and `<name>.dark.svg` for a dark theme. The Markdown shows them with paths from its own folder, and the website draws the source itself:

```html
<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/<name>.dark.svg">
  <img src="docs/images/diagrams/<name>.svg" alt="The title of the diagram: its description">
</picture>
```

- A diagram runs from top to bottom (`flowchart TB`), so that it keeps its size in a narrow column and on a phone, and has `accTitle` and `accDescr` for screen readers, which the `alt` of its picture repeats.
- Labels are text in the pictures: `<br>` breaks a line, and a label in `` "`…`" `` takes `**bold**` and `*italic*`.
- Colors are tints that read in the light and the dark theme, the same in every repository made from the blueprint: blue (`#526cfe`) the blueprint and its bots, orange (`#f59e0b`) you and what is yours, green (`#16a34a`) what comes out, as in `classDef bot fill:#526cfe2e,stroke:#526cfe,stroke-width:2px`.
- A diagram of a German page is `<name>.de.mmd`. A diagram in a README that has a German twin, `README.de.md`, comes in both languages.
- `bash scripts/diagrams.sh --extract` turns every `mermaid` block of the Markdown into a source and its picture. `scripts/check.sh` fails on a `mermaid` block once a page shows a picture of a diagram, and on a source or a picture without the other.
- The check *diagrams* draws the pictures anew when a pull request changes a diagram or adds a `mermaid` block. On a branch of this repository, the diagram bot commits the sources and pictures that it made, with a comment that names the pages it changed, so you need no Docker for a diagram: change its source and push. When the bot can't commit, it says so in a comment on the pull request. For a fork, the summary of the check lists the files and the command, and the run keeps them as its artifact.
- On `main`, the Diagrams workflow draws every diagram after each change of a diagram or a new `mermaid` block, and every week, and keeps one pull request from the branch `bot/diagrams` with what is left, which merges itself once every check passes; without the release app, the run warns and names the command instead. A failed run opens an issue, which the weekly run closes once the bot passes again.

## Releases

The release bot keeps a pull request titled `chore: release x.y.z` with the next version, up to date with `main` and decided anew with every merge. Its section of the changelog is the text of the fragments in `changelog.d/` and of Unreleased, and it deletes the fragments; without such text, it lists the pull requests. Merging it creates the release with its package, SBOM and signed provenance, and delivers it to the Nextcloud App Store. A release of dependency updates merges and publishes itself.

A line `Release-As: 2.0.0-beta.1` in the description of a pull request sets the version of the next release, for a prerelease for example. A prerelease leaves the fragments and the text of Unreleased to the release that follows it.

By contributing, you agree that your contribution is licensed under the AGPL-3.0-or-later license of this project.
