# Contributing

Contributions are welcome through issues and pull requests.
Participation follows [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md), and project decision-making is described in [GOVERNANCE.md](GOVERNANCE.md).
Use [SUPPORT.md](SUPPORT.md) to choose the correct public support channel and [SECURITY.md](SECURITY.md) for private vulnerability reports.

Every commit must include a Developer Certificate of Origin sign-off:

```text
Signed-off-by: Your Name <your-email@example.com>
```

Use `git commit -s` to add it automatically. Pull requests verify the sign-off of every new commit.
All changes, including release version commits, reach the protected `main` branch through pull requests.

Authenticated Dependabot pull requests may contain commits from the exact GitHub-managed `dependabot[bot]` identity without a DCO trailer. This narrowly scoped automation exception does not apply to human commits, other bots, or commits added by maintainers.

Before opening a pull request, run:

```bash
composer install
composer lint
composer l10n:check
composer test
composer cs:check
composer psalm
composer version:check
bash tests/e2e/run.sh
```

Do not include real credentials, production endpoints, private documents, personal metadata, or screenshots containing personal data. Use reserved example domains and clearly synthetic values in tests and documentation.
