---
description: "Why a page of results is followed by another only when the search found a result for it that the user may open, and why the cursor of the next page is sealed with a key of the instance."
---

# Page on only to a result that the user may open, with a sealed cursor

- Status: accepted, amended by [0017](0017-search-further-while-a-page-has-no-result.md)
- Date: 2026-11-12

## Context

Paperless can't leave out the documents whose files the searching user can't open, so the provider goes through the ranked hits of Paperless, makes an entry of each document with a file, and fills a page of results from as many pages of Paperless as it takes, within a budget of 4 pages and 3 seconds ([#115](https://github.com/Dennis-Otto/paperless-unified-search/issues/115)). The answer said that a next page follows whenever Paperless had hits left, and its cursor was the offset of the next hit in the ranking of Paperless, a number that the apps of Nextcloud send back as they got it.

Both depended on the documents that the user can't open: a page whose hits were all of such documents still had a next page, and the offset counted them. The promise of the app is that Nextcloud decides who sees a document, so nothing of the answer should depend on the documents that a user may not open, beyond what the budget can't avoid.

## Options

1. **Answer a search that spent its budget without a result as complete, and keep the cursor.** An empty answer then tells nothing, but a page with results still has a next page whenever Paperless has hits left, and its cursor still counts the documents in front of it.
2. **Seal the cursor, and keep the rule for a next page.** A user reads nothing from a cursor and can't make one, but whether a next page follows still depends on the hits of others.
3. **Page on only to a result that the user may open, and seal the cursor.** The search looks for one result more than the page holds; only a result found that way makes a next page, and the cursor of that page starts with its hit, sealed with a key of the instance, together with the user and the question of the search.

## Decision

Option 3. Whether a next page follows, and where it starts, then depends on the documents that the user may open alone, and the cursor names a page only for the search that handed it out.

- **One result ahead.** The provider fills a page with one result more than it holds, and leaves that result for the next page, whose cursor starts with its hit. Without it, the answer is complete, also after the budget, with results or without them. A later page of Paperless that fails, after results of the pages before it, still gives a next page that asks for it again: that depends on Paperless, not on the documents of others.
- **A sealed cursor.** `lib/Search/SearchCursors.php` encrypts and authenticates the offset with `ICrypto` of Nextcloud, with a key that it derives from the secret of the instance for this purpose alone, never the empty key, which Nextcloud tries as well when the secret doesn't open a text. The sealed text binds the offset to the user, the term and the span of time of the date filter. The first page needs no cursor, and Nextcloud's apps and the API ask for it with `0` too; any other cursor that the app didn't hand out for this user and question finds nothing and asks Paperless nothing.

## Consequences

- A user who can open few of the hits of a term can't page past a stretch of more than about 100 hits, or 3 seconds of Paperless, of documents that they may not open: the answer is complete there. That is the price of an answer that doesn't depend on those documents; a more precise term or the date filter brings later documents into reach. Nextcloud's web search, which offers *Load more results* only for a full page, behaves as before.
- What stays is named under *Residual risks* in [Security](../security.md): the budget itself and the time of an answer still depend on the documents in the reach of the search account of Paperless, the reach of the token, not the shares in Nextcloud. Users with tags are bounded by their tags ([0015](0015-show-a-user-with-tags-only-the-documents-with-one-of-them.md)).
- A cursor of a version before this one, which an app still holds after the update, finds nothing; the next search from the start pages again. A cursor is longer, about 240 characters, which the apps send back as they got it.
- A change of the secret of the instance ends every cursor handed out before it.
