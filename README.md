# Paperless Unified Search

[![CI](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/ci.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/ci.yml)
[![Secret scan](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/secret-scan.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/secret-scan.yml)

Paperless Unified Search brings Paperless-ngx OCR and full-text search into Nextcloud's global search. Search results open the matching synchronized file directly in Nextcloud's viewer.

## How it works

1. Nextcloud forwards an enabled external-search query to Paperless-ngx.
2. Paperless returns results from its native OCR/full-text index.
3. The app maps each Paperless document ID to a synchronized Nextcloud filename containing the unique marker `[P<ID>]`, for example `[P123]`.
4. A result is returned only if the current Nextcloud user can access the matching file.
5. Selecting the result opens Nextcloud's `/f/{fileId}` viewer route, not Paperless.

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
5. Select **Test connection and save**.

The configuration is global. Access control remains user-specific because the app discards every Paperless hit for which the searching Nextcloud user has no readable matching file.

## Usage

Open Nextcloud's global search, enable external providers, and select **Paperless documents**. Nextcloud 32 and later disable external providers by default for each user, so this toggle must be enabled once before Paperless results appear.

Documents without a synchronized `[P<ID>]` file are intentionally omitted. This also keeps Inbox-only documents out of Nextcloud search when the synchronization process does not export them.

## Security and privacy

- The Paperless API token is stored only in Nextcloud's server-side credentials manager.
- The token is never returned to browser JavaScript or rendered into HTML.
- Search results are filtered through the current user's Nextcloud filesystem view.
- Search terms are sent server-to-server to the configured Paperless instance only when external search is enabled.
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

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
