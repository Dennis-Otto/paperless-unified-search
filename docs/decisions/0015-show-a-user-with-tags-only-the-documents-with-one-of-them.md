---
description: "Why a user whom Paperless Sync lets see only the documents with some tags meets only those, checked against the tags that Paperless sends with each document, and why their own copies of those documents stand for them."
---

# Show a user with tags only the documents with one of them, by the tags that Paperless sends

- Status: accepted, amends [0013](0013-trust-the-files-of-each-owner.md) and [0014](0014-count-the-copies-of-the-owners-and-check-them.md)
- Date: 2026-11-10

## Context

Since its version 1.9.0, Paperless Sync has the setting **Tags that a user may see** ([paperless-sync#426](https://github.com/Dennis-Otto/paperless-sync/issues/426), its decision 0038): the administrator gives a user of Nextcloud the names of some tags of Paperless, and that user then gets only the documents with at least one of them, as copies in an archive of their own; without a line, a user gets what they got before, and the target user always gets every document. Dennis asked for it so that his wife, with an account of her own, finds her documents, which carry the tag *Katja*, and only those, everywhere, while he keeps seeing everything ([#391](https://github.com/Dennis-Otto/paperless-unified-search/issues/391)).

The search knew nothing of it. It counts the files of the archive account that a user may only read ([0009](0009-count-only-files-that-the-user-may-only-read.md)) and the files of the user of each owner ([0013](0013-trust-the-files-of-each-owner.md), [0014](0014-count-the-copies-of-the-owners-and-check-them.md)), not a copy for someone else, since anyone can give a file of their own the marker `[P<ID>]` of any document. So a user with tags found none of their copies with the ownership *The target user, all of them*, and every document of a share of the archive that the administrator once made by hand, the tags notwithstanding.

## Options

1. **Count the copies of a user with tags and trust the files as before.** Their documents show, but a share of the whole archive keeps showing them every other document too, and so does a file of theirs renamed after any document.
2. **Decide by the tags that Paperless sends with each document, and count the copies of a user with tags for those documents alone.** The search asks Paperless, with the one token of the settings, for the documents with the user's tags, and checks the tags of every document that comes back against the user's line, wherever it shows a document: in the search and the smart picker, on the dashboard, in the notifications, in *Try a search* and in `occ paperless_unified_search:search`. A file of the user then stands for a document only when that document carries one of their tags, so a renamed file gains nothing that the user may not see.
3. **Narrow by the files alone.** Without shares of the archive, a user with tags would see only their copies, but one share made by hand, or one writable folder, would undo it, and the search would trust names again.

## Decision

Option 2. Paperless Unified Search reads the lines of Paperless Sync, the lazy value `user_tags` of the app `paperless_sync`, as it reads its target user and its table of the owners: Paperless Sync keeps them, so the administrator sets them in one place, and its decision 0038 makes them the contract of both apps. A line names the user ID as Nextcloud spells it and the names of the tags, separated by commas; a line that Paperless Sync would refuse to save counts for nobody.

- **A user without a line, or with a line without tags, meets every document that a file of theirs stands for, as before.** An installation without a line changes nothing, and without Paperless Sync there is no line.
- **A user with tags meets only the documents whose tags in Paperless hold one of theirs, whatever the case of the names.** The names become the IDs of every tag of Paperless with one of them, from `/api/tags/`, which the cache keeps for ten minutes, until a save of the settings or `occ paperless_unified_search:check` forgets them. The search and the dashboard ask Paperless with the filter `tags__id__in`, so that a page fills with their documents, and every document that comes back is checked again by its field `tags`, whatever Paperless made of the filter. The job of the notifications reads the tags of the documents whose files have arrived anew, in one request, since a tag may come or go after a document was added.
- **Fail closed.** While Paperless lists none of their tags, after a typo or a rename, or doesn't send its tags at all, as to an account without **View** for **Tag**, a user with tags meets no document, never every one; the log says why, and Paperless Sync names a tag that Paperless doesn't have.
- **Their own copies stand for their documents.** While the archive account is the target user of Paperless Sync, which then writes the copies, a file of a user with tags stands for a document with one of their tags, as the file of an owner does for the documents of that owner. A file of an owner with tags stands for nobody else's search for a document without one of them, which Paperless Sync gives the target user instead. With another archive account, only its files count, for the documents with the user's tags.
- **Say why.** *Try a search* and `occ paperless_unified_search:search --explain` name such a document *not tagged*, and the settings list the users with tags with their tags, which change in Paperless Sync.

## Consequences

A user with tags finds exactly the documents with one of them, in every place of the app, also through a share of the archive that shows them more, and the administrator keeps every document. Removing a tag in Paperless hides the document from that user with the next search; a renamed tag hides its documents within ten minutes, until the line names the new name. Each search of a user with tags asks Paperless for its tags at most once in ten minutes, and the job of the notifications asks for the tags of the documents whose files arrived while any user has tags. The account of the API token needs **View** for **Tag**, which the search already asks for to show the names of the tags.
