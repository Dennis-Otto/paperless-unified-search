---
description: "Why a request to Paperless gets a second try only when it failed before it had a connection, and why a query that Paperless refuses while the user still types finds nothing instead of counting as a failed search."
---

# Try a request again only before it has a connection, and count no refused query as a problem

- Status: accepted
- Date: 2026-10-13

## Context

Nextcloud's unified search asks every provider while the user types, after each pause of a keystroke. Two things made that costly ([#128](https://github.com/Dennis-Otto/paperless-unified-search/issues/128)).

Every request to Paperless that got no answer got a second try, also one that had a connection and then waited 10 seconds for an answer in vain. A Paperless that accepts connections but hangs, as while it consumes many documents or builds its index anew, then held a PHP worker of Nextcloud for 20 seconds per keystroke and got every request twice, just when it could least afford it. Nextcloud's refusal of a local address got a second try as well, which it refuses again without fail.

Every answer of Paperless other than 2xx counted as a failed search in the history of the diagnostics. But Paperless 3 answers HTTP 400 to a query in its advanced syntax that it can't parse, such as `created:[2026 to` while the user still types the range, and HTTP 404 to a page past the last one, which any user can ask for with the cursor of the OCS search API. Typing filled the history of the administrators with outages that weren't, pushed real problems out of its 20 events, and wrote the configuration of Nextcloud once per keystroke; and the user saw nothing for the unfinished query.

## Options

1. Keep both: simple, but a hanging Paperless costs twice the wait and twice the load, and the history can't tell typing from an outage.
2. Give no request a second try. A connection that fails for a moment, as after a restart of a container or a lost packet, then fails the search, although a second try a moment later works.
3. Try a request again only when it failed before it had a connection, and take an answer that refuses the query or the page for no results.

## Decision

Option 3.

- **A second try only before a connection.** A request that fails within the 3 seconds to connect and half a second to spare never reached Paperless, as when the name doesn't resolve or the connection is refused or not set up in time, and gets one more try. One that fails later had a connection and waited for Paperless, and gets none; neither does an address that Nextcloud refuses as one of the local network. The client measures the time with Nextcloud's clock, `ITimeFactory`, which the tests replace.
- **A refused query is no problem.** Paperless's HTTP 400 with the errors of the field `query`, and HTTP 404 for a page after the first, are answers to what the user typed: the search finds nothing for them, logs them at the level debug, and neither writes the history nor ends a failure in it. Any other status, 400 without the errors of the query and 404 for the first page among them, which a wrong URL or a proxy answers, still fails the search, as do 401, 403 and 5xx.
- **The simple mode of Paperless 3 for an unfinished query.** Paperless 3 also searches with the parameter `text`, which takes any input and finds the documents of its words. On HTTP 400 for the query, a Paperless 3 or later is asked once more with the same term in `text`. The client knows the version from the headers `X-Version` and `X-Api-Version` that Paperless sends to every request with a valid token. Paperless 2 never gets `text`: it ignores the parameter and would list all documents.
- **The history writes less.** Problems of one kind write the history at most once in 30 seconds, and the problems in between only count, in Nextcloud's distributed cache, until the next write takes them along. A search that works reads the lazy history only while a value that isn't lazy says that a failure waits for its recovery.

## Consequences

A hanging Paperless now costs one wait of at most 10 seconds per keystroke and one request, and a failure that passes within a moment still doesn't fail the search. A search that is still being typed shows the documents of its words on Paperless 3 and nothing on Paperless 2, and the history shows only real problems. A search that Paperless refuses for another reason than the query, such as an error page of a proxy with HTTP 400, still counts. The version of Paperless is stored in the app configuration with the settings, and a search stores it again only when it changed, as after an update of Paperless. Without a distributed cache, every problem writes the history, as before.
