---
description: "Why a search that has found no result yet goes through up to 20 pages of Paperless, 500 hits, instead of the 4 of a search with results, within the same 3 seconds."
---

# Search further while a page has no result

- Status: accepted, amends [0016](0016-page-on-only-to-a-result-that-the-user-may-open.md)
- Date: 2026-11-12

## Context

Paperless ranks every document that matches a term, also those whose files the searching user can't open, and a page of results goes through at most 4 pages of 25 hits of that ranking, or 3 seconds ([0016](0016-page-on-only-to-a-result-that-the-user-may-open.md)). A search that spent that budget without a result is complete, so that its answer tells nothing of the documents of others.

A user who can open a small part of a large archive, such as a folder that the archive account shares with them, or their own documents with an archive of each owner ([0013](0013-trust-the-files-of-each-owner.md)), meets that limit with every common word: when more than 100 documents of others rank in front of their own, as for "invoice", the search shows no Paperless results at all, which reads as "I have no such document" ([#399](https://github.com/Dennis-Otto/paperless-unified-search/issues/399)).

## Options

1. **Let Paperless rank only the documents of the user's owners.** With an archive of each owner, the app knows the users of Paperless whose documents a user may open and could send `owner__id__in`, as it sends the tags of a user with tags ([0015](0015-show-a-user-with-tags-only-the-documents-with-one-of-them.md)). But the same user may open the files of the archive account that are shared with them, of any owner, which such a filter leaves out; both together take two rankings, which one offset in a sealed cursor can't page through, and the users of Paperless that the token may not view leave the filter empty.
2. **Search further while the page has no result.** While a page of results is still empty, the search goes on past the 4 pages of Paperless, up to a larger budget of its own, within the same 3 seconds; once it has a result, the budget of 4 pages holds as before.
3. **Answer that the search stopped early.** Nextcloud's search has no way to show a notice in place of results, and such an answer would tell that Paperless has more hits, of documents that the user can't open, which 0016 rules out.

## Decision

Option 2, with 20 pages, 500 hits, for a page that has no result yet. The time budget stays 3 seconds, so a search takes no longer than before, and Paperless answers a page of 25 hits with only the fields that a result shows, so the further pages cost it little; each takes one search of the file cache, as before.

- **Only while the page is empty.** The budget counts the pages that the search asked for: a search that finds its first result on its sixth page of Paperless ends after that page, as one with results ends after the fourth. A later page of results starts with the result that the page before found for it, so it is never empty at first, and the larger budget serves the first page in practice.
- **The answer stays as 0016 says.** A search that spends the larger budget without a result is complete, and nothing of the answer but its time depends on the documents of others.

## Consequences

- A user finds their own documents behind up to about 500 hits of others, or 3 seconds of Paperless, where it was about 100; behind more, a more precise term or the date filter still brings them into reach, which the FAQ tells users.
- What a user can learn of the documents of others with queries of their choice keeps its kind: the coarse bound in *Residual risks* of [Security](../security.md) moves from about 100 to about 500 hits in front of one of their documents. The reach of the search account in Paperless still bounds it.
- A search that finds nothing asks Paperless for up to 20 pages instead of 4, within the same 3 seconds: on a fast Paperless with a large archive, such a search makes more requests than before, each small.
- Option 1 stays possible as a later improvement for an archive of each owner without shares of the archive account, if 500 hits prove too few.
