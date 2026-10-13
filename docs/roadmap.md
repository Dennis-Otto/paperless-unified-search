---
description: "What Paperless Unified Search intends to do until October 2027, such as richer results, the smart picker and a dashboard widget, and what it will not do."
---

# Roadmap

[← README](https://github.com/Dennis-Otto/paperless-unified-search) · [Compare](compare.md) · [Architecture](architecture.md) · [Security design](security.md) · [Releases](releases.md)

What Paperless Unified Search intends to do in the next twelve months, until October 2027, and what it will not do. It is a direction, not a promise. Ideas are welcome as [feature requests](https://github.com/Dennis-Otto/paperless-unified-search/issues/new?template=feature_request.yml).

## The next twelve months

The app does what it was built for: the full-text search of Paperless-ngx in the search of Nextcloud, with results that open the synchronized files. The year builds on that, with these features planned:

- **Richer results.** A preview of the document, its date in the language of the user, its correspondent and its type in each result.
- **Nextcloud's date filter.** The filter of Nextcloud's search by date narrows the Paperless results as well.
- **The smart picker.** Find a Paperless document while writing in Talk, Text, Deck or Mail, and insert a link to its file.
- **A dashboard widget.** The latest Paperless documents that the user can open, on Nextcloud's dashboard.
- **Help for administrators.** `occ` commands that set up, test and explain the search, a setup check in the overview of the administration settings, and **Try a search** in the settings, which shows why a document is hidden from a user.
- **Paperless in the local network.** Reach a Paperless server in the local network without turning off Nextcloud's protection against requests to local addresses for every app.
- **A demo in one command.** Real Paperless-ngx and Nextcloud with sample documents, to try the app before installing it.
- **Documentation for everyone.** Pages on installation, configuration and troubleshooting with a FAQ, and the README and the website in German.

And, as every year, keeping it working and safe:

- **Every new major version of Nextcloud.** The upstream bot raises `max-version` in `appinfo/info.xml` once the end-to-end tests pass against the new version, and the app follows the changes of Nextcloud's search and of its mobile apps.
- **Changes of the Paperless-ngx API.** Contract tests against real Paperless-ngx 2.20 and 3.x, planned as well, catch a change that breaks the search, which is fixed first, with a test that keeps it fixed.
- **Fixes and security.** Reported bugs and vulnerabilities come before anything new; [SECURITY.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/SECURITY.md) has the times.
- **Current dependencies and tooling,** through the update bots and the [repository blueprint](https://github.com/Dennis-Otto/repo-blueprint).

Feature requests with the most reactions are considered next, in that order: anything that breaks for users first, then what makes the search safer, then the rest.

## Not planned

- **Synchronizing documents.** That is [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync), a separate app with its own releases.
- **An index of its own.** Paperless answers every search; the app stores no documents and no text of them. The [comparison](compare.md) says why.
- **A semantic search.** Finding documents by paraphrases needs an index of vectors; [Findling or Context Chat](compare.md#when-to-choose-which) build one.
- **Opening documents in Paperless.** A result opens the file in Nextcloud, whose permissions decide who may read it.
