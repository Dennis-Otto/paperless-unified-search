# Paperless Unified Search

[![CI](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/ci.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/ci.yml)
[![Secret scan](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/secret-scan.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/secret-scan.yml)

Paperless Unified Search brings Paperless-ngx OCR and full-text search into Nextcloud's global search. Search results open the matching synchronized file directly in Nextcloud's viewer.

## Screenshots

### Paperless results in Nextcloud's global search

![Paperless OCR results in Nextcloud unified search](screenshots/01-unified-search.png)

### A search result opened in Nextcloud's PDF viewer

![A synchronized Paperless document opened in Nextcloud's PDF viewer](screenshots/02-nextcloud-pdf-viewer.png)

### Secure server-side administration

![Paperless Unified Search administration settings in Nextcloud](screenshots/03-admin-settings.png)

## How it works

1. Nextcloud forwards an enabled external-search query to Paperless-ngx.
2. Paperless returns results from its native OCR/full-text index.
3. The app maps each Paperless document ID to a synchronized Nextcloud filename containing the unique marker `[P<ID>]`, for example `[P123]`.
4. A result is returned only if the current Nextcloud user can access the matching file.
5. Selecting a result opens the synchronized file in Nextcloud, not Paperless. Browsers use Nextcloud's
   `/f/{fileId}` viewer route. The official iOS app receives its native `nextcloud://open-file` deep link,
   while Android receives the file ID and user-relative path required by its in-app viewer.

This app does not synchronize documents itself. Use it together with a synchronization process that preserves the Paperless document marker in the Nextcloud filename.

## Requirements

- Nextcloud 33 through 35
- PHP 8.2 or newer as supported by the corresponding Nextcloud release
- A reachable Paperless-ngx instance with API access
- Synchronized files whose names contain `[P<ID>]`

## Configuration

1. Create a dedicated Paperless account with the minimum read permissions required for document search.
2. Create an API token for that account.
3. In Nextcloud, open **Administration settings → Additional settings → Paperless Unified Search**.
4. Enter the Paperless base URL and API token.
5. Optionally enable **Always include Paperless in global search** to treat the configured Paperless server as trusted.
6. Select **Test connection and save**.

The configuration is global. Access control remains user-specific because the app discards every Paperless hit for which the searching Nextcloud user has no readable matching file.

By default, Nextcloud searches Paperless only after the user enables **Search connected services**. When the trusted-service option is enabled, every global search term from every Nextcloud user is sent to Paperless automatically and the connected-services switch no longer controls this provider. Reload Nextcloud after changing this option.

## Usage

Open Nextcloud's global search, enable **Search connected services**, and select **Paperless documents**. Nextcloud 32 and later disable external providers after a page reload, so this switch must be enabled again unless an administrator has enabled trusted-service mode.

Documents without a synchronized `[P<ID>]` file are intentionally omitted. This also keeps Inbox-only documents out of Nextcloud search when the synchronization process does not export them.

## Security and privacy

- The Paperless API token is stored only in Nextcloud's server-side credentials manager.
- The token is never returned to browser JavaScript or rendered into HTML.
- Search results are filtered through the current user's Nextcloud filesystem view.
- Search terms are sent server-to-server only when connected-services search or trusted-service mode is enabled.
- Administrators can explicitly trust the configured Paperless server to include it automatically in every user's global searches.
- No deployment credentials, private hostnames, internal addresses, or instance configuration belong in this repository.
- Gitleaks scans every push and pull request.

See [SECURITY.md](SECURITY.md) for reporting security issues.

## Development

Install dependencies and run all checks:

```bash
composer install
composer test
composer cs:check
composer psalm
```

The production archive intentionally excludes tests, Composer development dependencies, and repository metadata. Packaging uses [Krankerl](https://github.com/ChristophWurst/krankerl).

### Releases

Add user-visible changes to the `Unreleased` section in [CHANGELOG.md](CHANGELOG.md). To publish, run the **Release** workflow from the default branch and choose `patch`, `minor`, or `major`. The workflow then:

1. Runs the complete quality and secret checks.
2. Calculates and validates the next semantic version.
3. Updates app metadata, versioned screenshot URLs, and the changelog.
4. Builds and signs the production archive.
5. Creates a signed-off release commit and an atomic Git tag.
6. Publishes the GitHub release and updates the Nextcloud App Store release.

An interrupted run resumes the incomplete version instead of incrementing it again. `composer version:check` verifies version consistency locally and in CI.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
