---
description: "What Paperless Unified Search intends to do until October 2027, such as a dashboard widget and a demo in one command, and what it will not do."
---

# Roadmap

[← README](https://github.com/Dennis-Otto/paperless-unified-search) · [Compare](compare.md) · [Architecture](architecture.md) · [Security design](security.md) · [Releases](releases.md)

What Paperless Unified Search intends to do in the next twelve months, until October 2027, and what it will not do. It is a direction, not a promise. Ideas are welcome as [feature requests](https://github.com/Dennis-Otto/paperless-unified-search/issues/new?template=feature_request.yml).

## The next twelve months

The app does what it was built for: the full-text search of Paperless-ngx in the search of Nextcloud, narrowed by its date filter, with results that show the preview, the date, the correspondent and the type of each document and open the synchronized files, and in the smart picker of Talk, Text, Deck and Mail, which inserts the link to such a file, and for administrators **Try a search** in the settings, which shows why a document is hidden from a user. The year builds on that, with these features planned:

- **A dashboard widget.** The latest Paperless documents that the user can open, on Nextcloud's dashboard.
- **A demo in one command.** Real Paperless-ngx and Nextcloud with sample documents, to try the app before installing it.
- **Documentation for everyone.** Pages on installation, configuration and troubleshooting with a FAQ, and the README and the website in German.

And, as every year, keeping it working and safe:

- **Every new major version of Nextcloud.** The upstream bot raises `max-version` in `appinfo/info.xml` once the end-to-end tests pass against the new version, and the app follows the changes of Nextcloud's search, of its smart picker and of its mobile apps.
- **Changes of the Paperless-ngx API.** Contract tests against real Paperless-ngx 2.20 and 3.x run every week and with every new release of Paperless-ngx, and catch a change that breaks the search, which is fixed first, with a test that keeps it fixed. The app asks Paperless for version 9 of its API, so that a new default version of Paperless can't change what the app reads; a newer version is a deliberate step, with these tests.
- **Fixes and security.** Reported bugs and vulnerabilities come before anything new; [SECURITY.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/SECURITY.md) has the times.
- **Current dependencies and tooling,** through the update bots and the [repository blueprint](https://github.com/Dennis-Otto/repo-blueprint).

Feature requests with the most reactions are considered next, in that order: anything that breaks for users first, then what makes the search safer, then the rest.

## Not planned

- **Synchronizing documents.** That is [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync), a separate app with its own releases.
- **An index of its own.** Paperless answers every search; the app stores no documents and no text of them. The [comparison](compare.md) says why.
- **A semantic search.** Finding documents by paraphrases needs an index of vectors; [Findling or Context Chat](compare.md#when-to-choose-which) build one.
- **Opening documents in Paperless.** A result opens the file in Nextcloud, whose permissions decide who may read it.
- **Previews of its own for the links of the smart picker.** Nextcloud renders the link to a file with the permissions of whoever reads it, which an app of its own could only weaken.
