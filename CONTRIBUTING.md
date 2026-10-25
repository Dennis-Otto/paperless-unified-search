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

New functionality comes with tests in the automated test suite, in the same pull request, and so does every change of behavior. A bug fix comes with a test that fails without the fix, so that the bug can't return unnoticed. `scripts/check.sh` fails when a line of `lib/` runs in no test. The tests run in a new random order each time, each after the tests that it depends on, so each test stands on its own, without the state of another one. PHPUnit names the order as it starts, such as `Random Seed: 1734`, and `vendor/bin/phpunit --random-order-seed 1734` runs the same order again, to find the test that leaves behind what another one trips over. A test that prints, or that uses what PHPUnit deprecates, fails as well. A test that makes a Git repository of its own first clears the variables that point Git at another, such as `GIT_DIR`, which Git sets for its hooks in a worktree and an agent sets for a worktree in a container, with `unset $(git rev-parse --local-env-vars)` in a shell script; otherwise its `git init` and its commits land in the repository that runs the tests. A pull request without the tests it needs is not merged.

## Coding standards

- **PHP** follows the [Nextcloud coding standard](https://github.com/nextcloud/coding-standard), which builds on PSR-1 and PSR-2 and indents with tabs, as php-cs-fixer checks it, and passes Psalm at its strictest level, 1. It runs on the oldest PHP that `appinfo/info.xml` names, while the tools run on the PHP of `config.platform.php` in `composer.json`, which may be newer: Psalm checks the code for PHP 8.2, as `phpVersion` of `psalm.xml` says, and the step *Oldest PHP* of `scripts/check.sh` parses `appinfo/`, `lib/` and `templates/` with it and checks that the packages that the app ships install on it, where it is installed as `php8.2` or the like, as in the CI.
- **Shell scripts** pass [ShellCheck](https://www.shellcheck.net/), which the CI never skips, **workflows** pass actionlint and zizmor's audit of their security, and **Markdown** follows the rules of markdownlint in `.markdownlint.jsonc`.
- **A new action or reusable workflow** needs its pattern `owner/repo@*` in `[actions.selected]` of `.github/repository.project.toml`, also for what it runs in turn: GitHub starts only the listed ones, and `python3 .github/blueprint.py settings check` names each one that the list lacks. Its jobs start once a maintainer has run `python3 .github/blueprint.py settings apply`.
- **Every text file** has LF line endings, no trailing whitespace, a line break at its end and no conflict markers that a merge left; `.editorconfig` sets up most editors for it.

`scripts/check.sh` and the Lint workflow check these standards on every pull request, which merges only when they pass. An exception to a rule is rare and is marked at its place in the code, with its reason in a comment.

## Workflow

1. Open an issue first for anything larger than a small fix, so we can agree on the approach.
2. Create a branch from `main`.
3. Describe what changes for users, in the words of a user, in a new file in `changelog.d/` named after your branch without its type, such as `changelog.d/dark-mode.md` for the branch `feat/dark-mode`, below the heading of its kind of change, such as `### Features` or `### Bug fixes`. The check of the title, *conventional title*, asks for it in every `feat`, `fix` or `perf` pull request, and checks it as the release takes it in. A file of its own conflicts with no other pull request; a line under `## Unreleased` in `CHANGELOG.md` works as well, but conflicts with each pull request that adds one at the same place.
4. A change that makes or changes a decision that shapes the project, such as a supported platform or the way of releasing, records it in [`docs/decisions/`](docs/decisions/README.md).
5. Open a pull request whose title is a [Conventional Commit](https://www.conventionalcommits.org/), such as `feat(settings): add a dark mode` or `fix: keep the token secret`. Mark a breaking change with `!`. Pull requests are squashed into one commit named after the title, and the title decides the next version: `fix` a patch, `feat` a minor, `!` a major version, or a minor one while the version is below 1.0.0.

### The changelog

A fragment holds the entries of one pull request, each below the heading of its kind of change:

```markdown
### Features

- **Dark mode:** the settings follow the theme of the system.
```

The headings are those of the blueprint, `Breaking changes`, `Features`, `Changes`, `Bug fixes` and `Performance`, those of [Keep a Changelog](https://keepachangelog.com/), `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` and `Security`, and every other one that `CHANGELOG.md` has already. The release bot moves the entries of every fragment and of Unreleased into the section of the next release, each kind of change below one heading, and deletes the fragments in the same commit of the release pull request. `bash scripts/check.sh` checks every fragment as the release takes it in, those that wait on `main` for the next release too, with `python3 .github/blueprint.py fragments`. The check of the title, *conventional title*, takes only a new fragment as the entry of a pull request: one that `main` has already is the entry of another pull request that waits for its release, so a pull request that changes it gets a warning, and one that needs an entry adds a fragment of its own under a new name.

## Checks

```sh
composer install
bash scripts/check.sh
```

The end-to-end tests run the app in Docker against every supported Nextcloud version at the same time and a mock of Paperless-ngx; `bash tests/e2e/run.sh` runs them against one version:

```sh
bash tests/e2e/run-all.sh
```

A change of the app, `appinfo/` or `lib/`, or of the mock of Paperless, `tests/e2e/mock_server.py`, also runs the [contract tests against a real Paperless-ngx](tests/e2e/README.md#against-a-real-paperless-ngx) in the pull request, the required checks `paperless (2)` and `paperless (3)`. Run them yourself before you push, for both lines of Paperless-ngx:

```sh
PAPERLESS=3 bash tests/e2e/paperless-contract.sh
PAPERLESS=2 bash tests/e2e/paperless-contract.sh
```

To run them before every push on its own, turn on the hook of the repository once:

```sh
git config core.hooksPath .githooks
```

Git runs the hook only when its file is executable. Windows has no such mode and adds the file as a plain one, which `scripts/check.sh` reports; `git update-index --chmod=+x .githooks/pre-push` and a commit make it executable.

The dev container in `.devcontainer/` has all of this set up, for VS Code and for GitHub Codespaces: open the repository in it, and `bash scripts/check.sh` runs.

## When a check fails

A check that fails explains itself. The PR coach writes what the check found, why that matters and how to fix it, with the commands to run, into the summary of the check and as its annotation; the pull request also shows the annotations of a tool at the lines that it names. After the checks, the coach keeps one comment on the pull request up to date with a section for each check that failed, also on a pull request from a fork, and a title that is no Conventional Commit gets one that passes. The comment follows every push and says so once every check passes. On a pull request of a person, the same comment also says what the merge makes: the next version, from the title and a `BREAKING CHANGE:` or `Release-As:` line of the description; the entry that it adds to the changelog; the coverage of the tests against that of `main`, file by file; and, when it changes the website, a link to the website that the check *docs* built, with the address of each page that it changes. While the checks pass, it comments only when it has something of this to say. On your machine, `scripts/check.sh` explains the step that stopped it in the same words.

The explanations are in `.github/checks.toml`, which the blueprint keeps current, and in `.github/checks.project.toml` for the checks of this project alone, whose explanations win. A check that you add to the required checks of `.github/repository.project.toml`, or that `scripts/check-project.sh` runs, comes with its explanation in `.github/checks.project.toml`, in the same pull request: what it found, why that matters and how to fix it, with the commands and a link to read more. `python3 .github/blueprint.py coach verify` lists every required check and every step of `scripts/check.sh` without one. The comment follows the workflows of the checks that the blueprint sets up; for a workflow of this project alone, such as one of visual tests, name it in the answer `test_workflows` of `.copier-answers.yml`, which the next update of the blueprint brings into `.github/workflows/after-checks.yml`.

## Pull requests fix themselves

What a command can fix, a pull request fixes by itself. When a check fails, its job runs the commands of `autofix` from `.github/checks.toml` on the head of the pull request, such as the fix of the text files or of the format of the code, and keeps the files that they changed. After the checks, the fix bot commits them as one commit onto a branch of this repository, also one of Renovate, and the checks run again: pull the branch before you push again. On a pull request from a fork, it suggests the fixes at their lines, and **Commit suggestion** takes each. A pull request with the label `no-bot-fixes` gets no commit of the bot. On your machine, `python3 .github/blueprint.py fix run` runs the same commands, and `python3 .github/blueprint.py fix text` fixes the text files alone.

Every explanation says in `autofix` how its check fixes itself: the commands, a list in which `{blueprint}` stands for `python3 .github/blueprint.py`, or a sentence with the reason why no command can; `python3 .github/blueprint.py coach verify` lists each explanation without one. The fix bot takes the fixes of the runs of Lint and of the CI alone: a check in a workflow of this project's own names its commands in `autofix` for a person, and the bot doesn't commit them yet ([Dennis-Otto/repo-blueprint#225](https://github.com/Dennis-Otto/repo-blueprint/issues/225)). The bot runs nothing of a pull request with a token that can write, and commits only text files that the commit has already, outside `.git` and the workflows.

## Website

MkDocs builds the website from `mkdocs.yml` and the pages in `docs/`. The check *docs* builds it strictly in every pull request, so that a broken link fails; after the build, lychee checks every link and anchor of the built pages, also those written in HTML, without asking a server, `python3 .github/blueprint.py site-check site` what the hooks of the website promise, such as a sitemap in every folder of pages and the 404 page in the default language, and that no page loads more than 1,200 KB at once on a phone, and `scripts/check-site.sh`, where it exists, the website as this project alone does. Every change of `main` publishes it on GitHub Pages, signs its provenance, which `SECURITY.md` shows how to verify, and asks the published website for its pages. To see it while you write, at <http://127.0.0.1:8000>:

```sh
python3 -m venv .venv-docs
.venv-docs/bin/pip install --require-hashes -r .github/docs-requirements.txt
.venv-docs/bin/mkdocs serve
```

On Windows, use `py -3` in place of `python3` and `.venv-docs/Scripts/` in place of `.venv-docs/bin/`; the dev container sets these tools up on its own. With them, `bash scripts/check.sh` builds the website strictly as well and checks what its hooks promise, as the check *docs* does, and without them it leaves that to the CI. The preview cards for social media come from the CI alone, because they need the Cairo library, which Windows doesn't have; where it is installed, `CI=true` before the command draws them too.

Every page meets WCAG 2.2 AA, in the light and the dark theme: the stylesheet of the blueprint gives the theme the contrast that it asks for and underlines the links in the text. After each build, the check *docs* opens every page in Chrome and runs [axe-core](https://github.com/dequelabs/axe-core) on it, and names each serious or critical violation in its summary and as an annotation at its page, with a link that explains the fix. For one release of the blueprint it only reports; then it fails the check. To run it on your machine after a build, with Google Chrome, or another Chrome or Chromium named in `CHROME`:

```sh
npm ci --prefix .github/a11y --ignore-scripts
node .github/a11y/check.mjs site
```

One page the website writes by itself: *Automation*, which the hooks of the blueprint make from `.github/workflows/` at every build, in English and, on a website in German, in German too. It shows every workflow with the comment at its top and what starts it, each schedule in words, the checks that `main` requires and the environments and secrets that each one uses, so a new workflow says there what it does once its comment does. A page `docs/automation.md` of your own takes its place.

## Pages in one column

The READMEs, this guide and the pages of `docs/` have one column, so that they read well in the GitHub app and on a phone, which keep columns side by side and squeeze them until the text is a few words wide and the pictures tiny:

- A table holds tabular data, never a layout: no `<table>` that sets a picture beside text, and no `<td width="…">`.
- A picture or a GIF comes at full width below the text that it illustrates.
- A long list, such as every option or every entity, goes into `<details>` with a `<summary>` that names it, so that the page stays short.

`scripts/check.sh` fails on a `<table>` with a picture and on a cell with a width in a README, a CONTRIBUTING file or a page of `docs/`, with its file and line. `python3 .github/blueprint.py pages --fix` puts the cells of such a table one below the other, the text before its picture, and leaves out the widths; a table inside a table it leaves to you.

## Diagrams

The GitHub app, the stores of the projects and other renderers of Markdown show a `mermaid` block as code, so a diagram is a picture of its source. The source is Mermaid in `docs/diagrams/<name>.mmd`, and `bash scripts/diagrams.sh` draws it, with Docker, as `docs/images/diagrams/<name>.svg` on a white card and `<name>.dark.svg` for a dark theme. The Markdown shows them with paths from its own folder, and the website shows them too, the picture of the theme that the reader switches to:

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
- `bash scripts/diagrams.sh --extract` turns every `mermaid` block of the Markdown into a source and its picture, also one in a quote such as a note, and writes the `alt` of each picture from the `accTitle` and `accDescr` of its source. A `mermaid` block inside another block of code, such as an example, or in a comment stays as it is. `scripts/check.sh` fails on a `mermaid` block once a page shows a picture of a diagram, on a source or a picture without the other, and on an `alt` that its source doesn't say.
- The script draws only the sources that a page shows, and those that have a picture already. A source in `docs/diagrams/` that no page shows stays as it is, such as one that a script of the project draws in its own way.
- The check *diagrams* draws the pictures anew when a pull request changes a diagram or adds a `mermaid` block. On a branch of this repository, the diagram bot commits the sources and pictures that it made, with a comment that names the pages it changed, so you need no Docker for a diagram: change its source and push. When the bot can't commit, it says so in a comment on the pull request. For a fork, the summary of the check lists the files and the command, and the run keeps them as its artifact.
- On `main`, the Diagrams workflow draws every diagram after each change of a diagram or a new `mermaid` block, and every week, and keeps one pull request from the branch `bot/diagrams` with what is left, which merges itself once every check passes; without the release app, the run warns and names the command instead. A failed run opens an issue, which the weekly run closes once the bot passes again.

## Screenshots

`bash scripts/screenshots.sh` renders the pictures of this project in place, such as the social preview `.github/social-preview.png` and the screenshots of the documentation and the stores, and `.github/screenshots-paths.txt` lists the files whose change can change them, as the paths of a workflow. Both belong to this project: add a picture to the script, with a fixed clock, size and data, so that the same code renders the same pixels, and its sources to the list.

- The screenshot bot (`screenshot-bot.yml`) runs the script after a change of `main` that touches a file of the list, after every release and every week, without secrets. Each picture whose pixels stayed the same keeps its committed file; when pixels changed, one pull request from `bot/screenshots` holds the new pictures and merges itself once every check passes. Without the script, the bot renders nothing.
- To see what a change of yours changes, run the script, then `python3 .github/blueprint.py screenshots restore`, which gives every picture that looks the same its committed file back and lists the others; commit what is left, or leave it to the bot. It compares the pixels with Pillow, which `python3 -m pip install --require-hashes -r .github/screenshots-requirements.txt` installs.

## Releases

The release bot keeps a pull request titled `chore: release x.y.z` with the next version, up to date with `main` and decided anew with every merge. Its section of the changelog is the text of the fragments in `changelog.d/` and of Unreleased, and it deletes the fragments; without such text, it lists the pull requests. Merging it creates the release with its package, SBOM and signed provenance, and delivers it to the Nextcloud App Store. A release of dependency updates merges and publishes itself.

A line `Release-As: 2.0.0-beta.1` in the description of a pull request sets the version of the next release, for a prerelease for example. After the merge, the release bot writes the title and such a line, or one of `BREAKING CHANGE:`, into the end of the description as an override in an HTML comment, which release-please reads instead of the squash commit, whose parser could fail on another line of the description. A prerelease leaves the fragments and the text of Unreleased to the release that follows it.

By contributing, you agree that your contribution is licensed under the AGPL-3.0-or-later license of this project.
