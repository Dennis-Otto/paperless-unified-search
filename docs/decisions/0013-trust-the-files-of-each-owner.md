---
description: "Why a file of the user whom Paperless Sync gives the owner of a document in Paperless stands for that document, besides the files of the archive account, and why a file renamed after a document of another owner never does."
---

# Trust the files of the user of each owner, for that owner's documents alone

- Status: accepted, amends [0002](0002-trust-the-archive-account.md), amended by [0014](0014-count-the-copies-of-the-owners-and-check-them.md)
- Date: 2026-11-08

## Context

[0002](0002-trust-the-archive-account.md) lets only the files of the archive account stand for documents, because anyone can give a file the marker `[P<ID>]` of a document, and the app asks Paperless with one token that finds every document. Since its version 1.1.0, Paperless Sync can give each user of Nextcloud the documents that their user of Paperless owns, as files of their own ([paperless-sync#84](https://github.com/Dennis-Otto/paperless-sync/issues/84), its decisions 0025 and 0033). Then the search, the dashboard, the notifications and the smart picker show none of a user's own documents ([#342](https://github.com/Dennis-Otto/paperless-unified-search/issues/342)); since [#338](https://github.com/Dennis-Otto/paperless-unified-search/issues/338) the settings only warn about it. A user owns their own files and can rename them, so the marker of such a file proves as little as before ([#222](https://github.com/Dennis-Otto/paperless-unified-search/issues/222)).

What proves something is the owner of the document in Paperless. Paperless names the `owner` of each document, as an ID, and the usernames of its users in `/api/users/` to an account that may view users. Paperless Sync gives each owner a user of Nextcloud by rules that it keeps in the settings of Nextcloud: its table *Paperless users with another Nextcloud ID* (`user_map`), or else the user with the same ID whatever its case, only a member of its group (`user_group`), and nobody for two users of Paperless with one user of Nextcloud.

## Options

1. Keep 0002 and the warning: the archives of each user stay out of the search.
2. Trust every file of a user with a marker, as the files of the archive account: a user who renames a file after any document would see its title and excerpt.
3. Count a file of a user for a document only when Paperless Sync's rules give that user the owner of the document in Paperless, besides the files of the archive account; with the same check of the permissions as for the archive ([0009](0009-count-only-files-that-the-user-may-only-read.md)) for whoever reaches it through a share.
4. Read the records of Paperless Sync, which file it wrote for which document.
5. Also count the copies that Paperless Sync gives the users who may view a document (`per-user-shared`), from the permissions of each document in Paperless.

## Decision

Option 3, which Dennis approved on 2026-11-08, only while Paperless Sync gives each user the documents that they own (`ownership` = `per-user`) and the archive account is its target user. A file stands for a document when the archive account owns it, as before, or the user whom the rules of Paperless Sync give the owner of the document. Such a user can give a file of their own the marker of any document, but only the markers of the documents of their own owner count for it, and those are documents that the user finds in Paperless anyway. The rules are those of Paperless Sync, read from its settings, and stricter where a name can't tell: a user of Nextcloud whom two users of Paperless claim, by their names or by the table, gets neither of their documents, also when one of them has no user of Nextcloud. A file that reaches another user through a share counts as a file of the archive account does: only for a user who may only read it, by the name that its owner gave a file shared on its own.

The search, the dashboard and the notifications ask Paperless for the field `owner` of each document, and the usernames come with one request of `/api/users/` that the distributed cache of Nextcloud keeps for ten minutes, by the server and the token, page by page by their numbers, never by the link of an answer. Without them, as for an account that may not view users, or one whose request fails, the search shows only the files of the archive account, as before, and the list rests for ten minutes, or a minute after a request without answer. The settings, `occ` and the overview warn about an account without **View** for **User** while Paperless Sync gives each owner an archive, and the search account that the settings create gets that permission.

Option 2 would let any user read the title and the excerpt of every document. Option 4 would tie the app to the database of Paperless Sync, as 0002 found. Option 5 needs to know who may view each document, through its users and groups and those of the groups, for every result; with `per-user-shared`, the warning of #338 stays until Paperless tells the search account that reliably.

## Consequences

Each user finds their own documents in the search, on the dashboard and in notifications, and those that others share with them. *Try a search* and `occ paperless_unified_search:search --explain` name a file of another user than the one of the owner *Owned in Paperless by another user* (`other_owner`), and a file of the owner's user that isn't shared *Not shared*. `occ paperless_unified_search:check` counts only the files of the archive account and says so, and passes without them. A change of the table or the group of Paperless Sync counts at once, a change of a username in Paperless within ten minutes. The search account of Paperless may list the usernames of Paperless; it can't change them.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="../images/diagrams/when-a-file-of-a-user-stands-for-a-document.dark.svg">
  <img src="../images/diagrams/when-a-file-of-a-user-stands-for-a-document.svg" alt="When a file of a user stands for a document: Paperless Sync gives each user the documents that they own in Paperless. jamie can open a file with [P412] in its name. A file of the archive account stands for document 412, as before. A file of another user, sam, stands for it only when Paperless Sync gives sam the owner of document 412 in Paperless, by its table, the same ID or its group; otherwise sam could have renamed the file after a document of someone else, and it stands for no document. A file of another account stands for 412 only when jamie may only read it.">
</picture>
