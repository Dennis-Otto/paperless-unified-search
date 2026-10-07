# Roadmap

[← README](../README.md) · [Architecture](architecture.md) · [Security design](security.md) · [Releases](releases.md)

What Paperless Unified Search intends to do in the next twelve months, until October 2027, and what it will not do. It is a direction, not a promise. Ideas are welcome as [feature requests](https://github.com/Dennis-Otto/paperless-unified-search/issues/new?template=feature_request.yml).

## The next twelve months

The app does what it was built for: the full-text search of Paperless-ngx in the search of Nextcloud, with results that open the synchronized files. No larger feature is planned at the moment; the year is about keeping it working and safe:

- **Every new major version of Nextcloud.** The upstream bot raises `max-version` in `appinfo/info.xml` once the end-to-end tests pass against the new version, and the app follows the changes of Nextcloud's search and of its mobile apps.
- **Changes of the Paperless-ngx API.** A change that breaks the search is fixed first, with a test that keeps it fixed.
- **Fixes and security.** Reported bugs and vulnerabilities come before anything new; [SECURITY.md](../SECURITY.md) has the times.
- **Current dependencies and tooling,** through the update bots and the [repository blueprint](https://github.com/Dennis-Otto/repo-blueprint).

Feature requests with the most reactions are considered next, in that order: anything that breaks for users first, then what makes the search safer, then the rest.

## Not planned

- **Synchronizing documents.** That is [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync), a separate app with its own releases.
- **An index of its own.** Paperless answers every search; the app stores no documents and no text of them.
- **Opening documents in Paperless.** A result opens the file in Nextcloud, whose permissions decide who may read it.
