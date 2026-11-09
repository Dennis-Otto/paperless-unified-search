---
description: "Why the copies of the owners count with Paperless Sync's per-user-shared too, why the copies for the users who may only view a document don't, and why occ check counts the files of the owners."
---

# Count the copies of the owners in every mode of Paperless Sync for each user, and check them

- Status: accepted, amends [0013](0013-trust-the-files-of-each-owner.md)
- Date: 2026-11-09

## Context

[0013](0013-trust-the-files-of-each-owner.md) lets a file of the user whom Paperless Sync gives the owner of a document stand for that document, but only with Paperless Sync's ownership `per-user`. With `per-user-shared`, Paperless Sync writes the same copy for the owner, by the same rules, and copies for every user who may view the document as well, and the search showed none of them, not even the owner's; #338 only warned. `occ paperless_unified_search:check` counted the files of the archive account alone, so with either mode it could neither tell how many documents the search shows nor fail when it shows none.

The options:

1. Keep 0013 as it is: `per-user-shared` shows only the files of the archive account.
2. Count the copies of the owners with `per-user-shared` too, by the rule of 0013, and leave out the copies for the users who may only view a document.
3. Also count those copies, from the permissions of each document in Paperless.

## Decision

Option 2. The rule is that of 0013, which Dennis approved on 2026-11-08: a file of a user counts only for the documents of that user's owner, so a copy of a document that a user may only view, whose owner is someone else, counts for nothing, as a renamed file does. The trust model doesn't widen, since the same files count, by the same rules, as with `per-user`. Such a user finds the document through a file of its owner, or of the archive account, that is shared with them read-only, and the warning of #338 stays, narrowed to these copies.

`occ paperless_unified_search:check` counts the files of the owners in both modes, as the search does: it reads the owner of each document, page by page, the users of Paperless anew, and the files of the user of each owner in that user's own folders. It fails when not one document has a file of the archive account or of its owner's user, and when it can't read the owners or the users it counts the archive account alone and says so, as before. A save of the settings and the check forget the users of Paperless that the cache keeps, and a refused list of them, so that the files of the owners count at once after the account got **View** for **User**.

Option 3 needs who may view each document, through its users and groups and those of the groups, for every result, which the search account would have to be allowed to read and the search to ask for with every page: the reason that 0013 left it out holds.

## Consequences

With `per-user-shared`, each user finds their own documents in the search, on the dashboard and in notifications, and *Try a search* names their copy of a document that they may only view *Owned in Paperless by another user* (`other_owner`). The account of the token needs **View** for **User** in both modes, and the settings warn without it. `occ paperless_unified_search:check` reports the coverage of the archive account and the owners together, reading the owners of up to 100 pages of 1,000 documents, and exits with 1 when the search can show no document. The copies for the users who may only view a document stay out of the search until Paperless tells the search account reliably who may view each.
