# Instructions for coding agents

Paperless Unified Search: OCR-powered Paperless-ngx search, seamlessly integrated into Nextcloud

These are the rules for coding agents, such as Claude Code, Codex, GitHub Copilot and Cursor, that change this repository; people find the same rules in [CONTRIBUTING.md](CONTRIBUTING.md). The blueprint keeps this file current, so change it in the blueprint, not here. The notes of this project, such as where its parts are, are in [.github/agents.project.md](.github/agents.project.md): read them as well.

## Commands

Set up the tools once, then run every check before every push, the same ones the CI runs:

```sh
composer install
bash scripts/check.sh
```

On Windows, run them in Git Bash. While you work, the quicker steps of the checks:

- `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text`: the tests, with every line of `lib/` covered.
- `vendor/bin/phpunit --filter name`: one test.
- `vendor/bin/phpunit --random-order-seed SEED`: the tests in the order of a run that failed; they run in a new random order each time, and PHPUnit names its seed as it starts.
- `vendor/bin/php-cs-fixer fix`: the format of the code, in the coding standard of Nextcloud.
- `vendor/bin/psalm --threads=1 --no-cache`, and with `--taint-analysis`: the types at the strictest level, and untrusted input, such as a parameter of a request to a controller or the answer of another server, that reaches HTML, SQL, a shell, a file, a request to another server or a header. A function that makes input safe for a sink says so with `@psalm-taint-escape` and a comment on why; `.github/psalm/` belongs to the blueprint.

`python3 .github/blueprint.py fix run` makes every fix that a command makes for a step of `scripts/check.sh`, as the fix bot makes it in a pull request.

Turn on the hook of the repository once with `git config core.hooksPath .githooks`, and it runs the checks before every push.

## Changes

- **Tests come with every change of behavior,** in the same pull request, and a bug fix comes with a test that fails without the fix. `scripts/check.sh` fails when a line of `lib/` runs in no test.
- **A test that makes a Git repository of its own** first clears the variables that point Git at another, such as `GIT_DIR`, which Git sets for its hooks in a worktree and an agent sets for a worktree in a container; otherwise its `git init` and its commits land in the repository that runs the tests. A shell script clears them with `unset $(git rev-parse --local-env-vars)`, a test in Python runs git with an environment without them.
- **The coding standards** of [CONTRIBUTING.md](CONTRIBUTING.md#coding-standards) apply; an exception to a rule is rare and has its reason in a comment at its place.
- **The documentation changes with the code:** `README.md` and the pages in `docs/`, and with an English page its German one, such as `README.de.md`, where the project has one.
- **Diagrams** are Mermaid sources in `docs/diagrams/<name>.mmd`, from top to bottom (`flowchart TB`), with `accTitle` and `accDescr`. `bash scripts/diagrams.sh` draws each, with Docker, as `docs/images/diagrams/<name>.svg` and `<name>.dark.svg`, and the Markdown shows them with a `<picture>`, whose `alt` repeats the title and the description. Never write a `mermaid` block: the GitHub app shows it as code, and `scripts/check.sh` fails on it once a page shows a picture of a diagram. Without Docker, change the source and push, and the diagram bot draws the pictures on the branch; a German page takes `<name>.de.mmd`. [CONTRIBUTING.md](CONTRIBUTING.md#diagrams) has the rest.
- **A change for users** (`feat`, `fix`, `perf`) gets an entry in the words of a user, in a new file `changelog.d/<branch>.md` named after the branch without its type, such as `changelog.d/dark-mode.md` for the branch `feat/dark-mode`, below the heading of its kind of change, such as `### Features` or `### Bug fixes`. The check of the title, *conventional title*, asks for it, and `python3 .github/blueprint.py fragments` checks it as the release takes it in; with `--fix`, it renames a heading that surely means a kind of change, such as `### Fixes` to `### Fixed`. A file of its own conflicts with no other pull request, an entry under `## Unreleased` in `CHANGELOG.md` with every one that adds one at the same place, so write the fragment. The release bot moves the fragments into the section of the next release and writes the version, so leave the released sections alone.
- **A decision that shapes the project,** such as a supported platform or the way of releasing, gets a record in [`docs/decisions/`](docs/decisions/README.md), with the next free number.

## Commits and pull requests

- Sign off every commit with `git commit -s`, for the [Developer Certificate of Origin](https://developercertificate.org/); the check *lint* fails without it.
- The title of a pull request is a [Conventional Commit](https://www.conventionalcommits.org/), such as `feat(settings): add a dark mode`. Pull requests are squashed into one commit named after the title, and the title decides the next version: `fix` and `perf` a patch, `feat` a minor, `!` after the type, such as `feat!:`, a major version (a minor one before 1.0.0). `docs`, `test`, `refactor`, `ci`, `build` and `chore` make no release by themselves.
- The description follows the pull request template and names the issue that it fixes with `Fixes #123`. The issue stays open until a release ships the fix, and a bot closes it then.
- Every change reaches `main` through a pull request that passes every required check.

## Never

- Never weaken, skip or switch off a check, a test or a security control to get a pull request green: no lower coverage, no deleted or skipped test, no `--no-verify`, no exception to a rule without its reason. Fix the cause.
- Never commit tokens, keys, passwords or personal data, not even in a test or an example.
- Never change a file of the blueprint here; the next section says which files they are.

## The files of this project and those of the blueprint

Paperless Unified Search is made from [Repo Blueprint](https://github.com/Dennis-Otto/repo-blueprint), and the blueprint bot brings every release of the blueprint as a pull request that runs `copier update`.

- **The files of the blueprint** are all that the blueprint renders, other than those listed below: the workflows in `.github/workflows/`, the settings tool `.github/blueprint.py` with `.github/blueprint/` and `.github/repository.toml`, the other files in `.github/`, `.devcontainer/`, `.githooks/`, `scripts/check.sh`, `scripts/diagrams.sh`, the community files such as `CONTRIBUTING.md`, `SECURITY.md` and `LICENSE`, the configurations in the root such as `.editorconfig` and `.gitignore`, this file and `CLAUDE.md`. Change them in the blueprint, in its `template/` or in the file of the blueprint that the template includes, never here: a change here conflicts with the next update, and the other repositories miss it. The answers in `.copier-answers.yml` change only with `copier update --data name=value`. A file that says in its first lines that the project changes it here, such as `.vale.ini`, is the only exception. When in doubt, look for the file in `template/` of the blueprint, at the release that `_commit` in `.copier-answers.yml` names.
- **A pull request that changes a file of the blueprint** gets a notice from the check *lint* and in the report of the PR coach, with the label `blueprint-file`; `.github/blueprint/owned.txt` names each such file. With the repository variable `BLUEPRINT_OWNERSHIP` set to `fail`, the check fails on it.
- **The files of this project** are listed below: an update never overwrites them, so change them here. So is every file that the blueprint doesn't render, such as a workflow or a script of this project alone.

The project adds its own beside the files of the blueprint:

- Checks of this project alone go into `scripts/check-project.sh`, which `scripts/check.sh` runs last, and those of its website into `scripts/check-site.sh`, which the check *docs* runs with the folder of the built website, `site`.
- Explanations of the checks of this project alone, which the PR coach shows when one fails, go into `.github/checks.project.toml`: what the check found, why that matters and how to fix it, and in `autofix` the commands that fix it, which the fix bot runs, or the reason why no command can. The step *Explanations of the checks* of `scripts/check.sh` fails on each required check and each step of `scripts/check.sh` without one, and on each explanation without its `autofix`, which `python3 .github/blueprint.py coach verify` lists. A workflow of this project that reports on a pull request adds a part to the comment of the PR coach instead of a comment of its own: it keeps the artifact `pr-report-<name>` with `report.md` for the head, and `[report.<name>]` in the same file gives the part its title.
- Settings of this repository alone, such as the required checks of its end-to-end tests, an environment with its secrets or the pattern of an action that only its own workflows run, go into `.github/repository.project.toml`, never into `.github/repository.toml`.
- Workflows of this project alone are workflow files of their own in `.github/workflows/`. Every night, the bot-health bot opens an issue for the latest run of each that fails on the default branch, closes it once one passes, and starts one whose schedule GitHub missed: a run of its schedule or one started by hand counts, a push doesn't. A workflow that keeps an issue of its own about its failures, such as end-to-end tests whose weekly run against the coming release reports itself, says so in a comment line of its own, `# bot-health: own-issue`; the bot then opens and closes none for it but still starts its missed schedule. Add the line only when the workflow reports every failure on the default branch itself, those of a push included: the bot reports none of its runs then.
- Notes for coding agents go into `.github/agents.project.md`, those for the issue assistant into `.github/issue-assistant/project.md`.
- Text about the releases of this project goes between the two marked lines of `## Releases` in `CONTRIBUTING.md`, the only lines of that file that belong to the project: every update keeps them, and a change of any other line of it can conflict with the next update.

The files of this project, as `_skip_if_exists` in the `copier.yml` of the blueprint lists them; each pattern is a path from the root of the repository, and a folder holds everything inside it:

- **Documentation and website:** `/README.md`, `/CHANGELOG.md`, `/mkdocs.yml`, `/docs/index.md`, `/docs/security.md`, `/docs/releases.md`, `/docs/decisions`
- **Version, which the release bot writes:** `/version.txt`, `/.release-please-manifest.json`
- **Code and tests:** `/src`, `/lib`, `/app`, `/appinfo`, `/img`, `/custom_components`, `/extension`, `/store`, `/tests`, `/test`, `/fuzz`, `/*.py`, `/action.yml`, `/hacs.json`, `/Dockerfile`
- **Dependency manifests and lock files, which Renovate keeps current:** `/pyproject.toml`, `/requirements-dev.in`, `/requirements-dev.txt`, `/requirements-test.in`, `/requirements-test.txt`, `/requirements-fuzz.in`, `/requirements-fuzz.txt`, `/composer.json`, `/composer.lock`, `/package.json`, `/package-lock.json`
- **Issue and discussion forms:** `.github/ISSUE_TEMPLATE/bug_report.yml`, `.github/ISSUE_TEMPLATE/feature_request.yml`, `.github/ISSUE_TEMPLATE/config.yml`, `.github/DISCUSSION_TEMPLATE/q-a.yml`, `.github/DISCUSSION_TEMPLATE/ideas.yml`
- **Settings, labels and bots:** `.github/repository.project.toml`, `.github/checks.project.toml`, `.github/labels.toml`, `.github/labeler.yml`, `.github/renovate.json5`, `.github/renovate-accepted.json5`, `/osv-scanner.toml`, `.github/findings.toml`, `.github/dependency-review.yml`, `.github/workflows/action-test.yml`, `.github/issue-assistant/project.md`, `.github/agents.project.md`
- **Lint, links and the pictures:** `/.markdownlint.jsonc`, `.github/cspell-words.txt`, `/.lycheeignore`, `.github/social-preview.html`, `/scripts/screenshots.sh`, `.github/screenshots-paths.txt`
- **The assets that the release builds of its own:** `/scripts/release-assets.sh`

## Project notes

See [.github/agents.project.md](.github/agents.project.md).
