---
description: "Why the search asks no Paperless for 30 seconds after a request to it got no answer, so that an outage of Paperless doesn't use up the PHP workers of all of Nextcloud."
---

# Rest the search for 30 seconds after a request without answer

- Status: accepted, amends [0006](0006-retry-only-before-a-connection.md)
- Date: 2026-11-12

## Context

Nextcloud's unified search asks the provider after every pause of typing, and with *Always include Paperless in global search* or the personal switch on, every search of Nextcloud asks Paperless, also one for files or contacts. A request that gets no answer holds a PHP worker of Nextcloud meanwhile: 3 seconds and 3 more for the second try of a host that drops the packets, and 10 seconds for a Paperless that accepts the connection and hangs ([0006](0006-retry-only-before-a-connection.md): "one wait of at most 10 seconds per keystroke"). During an outage, a restart or a rebuild of the index of Paperless, a few users who type use up a small pool of PHP-FPM workers, and then all of Nextcloud answers slowly, not only the results of Paperless ([#417](https://github.com/Dennis-Otto/paperless-unified-search/issues/417)).

The lookups around the search rest already: the names of correspondents, types and tags, the users of Paperless, the tags of users and the newest documents of the dashboard each leave Paperless alone for a minute after a request without answer. The search itself did not.

## Options

1. Keep asking: every search shows the documents as soon as Paperless answers again, but every keystroke during an outage costs a worker for up to 10 seconds.
2. Shorter timeouts: a Paperless that is busy, not down, as while it consumes many documents, would fail searches that it could answer.
3. Rest after a request without answer: for a while, the search answers with no results at once, without asking Paperless, for every user.

## Decision

Option 3, for 30 seconds.

- **Only a request without answer.** A timeout, a refused or failed connection, and a name that doesn't resolve start the rest, after the second try that 0006 allows. An answer of Paperless, whatever its status, doesn't: Paperless answers, and the search costs no long wait. Neither does a failure that costs no wait, such as a certificate that Nextcloud doesn't trust or an address that it refuses, nor a failure of the files in Nextcloud, nor an error of the app itself that names no failed request, such as a Paperless that isn't configured. A later page of Paperless that gets no answer starts the rest as well; the results of the pages before stay.
- **For every user, by the server.** Nextcloud's distributed cache keeps the rest by a hash of the URL of Paperless, so one user's failed search spares the workers for everyone. Without a distributed cache, Nextcloud gives the app a cache that keeps nothing, and every search asks Paperless, as before; a cache that fails keeps no rest either.
- **30 seconds, not the minute of the lookups.** The search is what users wait for, so it asks again sooner once Paperless is back; the history of the diagnostics writes the problems of one kind at most once in 30 seconds as well.
- **Nothing to note.** A search during the rest finds nothing and writes nothing into the history of the diagnostics, which holds the failure that started it already, and notes the rest in the log at the level debug.
- **A test that Paperless answers ends it.** Every test of the connection that Paperless answers removes the rest of its URL: *Test connection and save*, `occ paperless_unified_search:setup`, `:test` and `:check`.

## Consequences

During an outage, one search per 30 seconds waits for Paperless instead of every keystroke of every user, and Nextcloud stays responsive. Searches that start while the first request still waits aren't spared, since the rest starts only when that request fails. Once Paperless is back, its documents show up to 30 seconds later, or at once after a test of the connection. A Paperless that fails some requests now and then, without answer, makes the searches of the following 30 seconds find nothing, which the history shows as the failure that started the rest.
