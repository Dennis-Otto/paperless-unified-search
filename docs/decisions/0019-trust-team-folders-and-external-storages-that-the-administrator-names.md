---
description: "Why a file in a Team folder or an external storage that the administrator names with occ stands for its document, for each user who may only read it there, although no account owns it."
---

# Trust the Team folders and external storages that the administrator names

- Status: accepted, amends [0002](0002-trust-the-archive-account.md)
- Date: 2026-11-12

## Context

[0002](0002-trust-the-archive-account.md) lets a file stand for a document only when an account that the app trusts owns it: the archive account, and since [0013](0013-trust-the-files-of-each-owner.md) the user whom Paperless Sync gives the owner of the document. A Team folder (the app groupfolders) and an external storage that is mounted for several users or groups have no account of their own: Nextcloud names each user who sees a file there as its owner. Such a mount can therefore never hold the archive, and an administrator who mounts the media folder of Paperless for a group, or keeps the archive in a Team folder, learns it from an empty search ([#408](https://github.com/Dennis-Otto/paperless-unified-search/issues/408)). The marker in the name still proves nothing by itself: whoever may write a file can give it the marker of any document.

## Options

1. Keep 0002 and say in the documentation that the archive can't live in such a mount.
2. Trust every file in a Team folder or an external storage of the administrator.
3. Let the administrator name the mounts that hold the archive, and count a file in one of them, as a file of the archive account that reaches a user through a share ([0009](0009-count-only-files-that-the-user-may-only-read.md)), only for a user who may neither change it nor add a file to its folder there.
4. Read the owner of the mount from the app that made it, such as the managers of a Team folder.

## Decision

Option 3. `occ paperless_unified_search:trusted-mounts` stores the trusted mounts in the app configuration `trusted_mounts`, a Team folder by the ID that `occ groupfolders:list` shows, as `team:3`, and an external storage by the mount ID that `occ files_external:list` shows, as `external:7`. A file in such a mount stands for the document whose marker it carries, any document, for each user who may only read it there, whomever Nextcloud names as its owner; the archive account's own files count as before. Only a system mount counts, one that the administrator set up: an external storage that a user adds in their personal settings could be written by that user outside Nextcloud. The name comes from the mount itself, its type `group` and the ID of its folder, or its type `external` and the ID of its mount, so a share of a file out of the mount, which is a mount of another type, doesn't count. The setting is a step of occ rather than a field of the settings page: naming a mount trusts everyone who may write it, also outside Nextcloud, the kind of choice that the administrator of the server makes.

Option 1 leaves an obvious set-up without search. Option 2 would let every user who may write a Team folder of any purpose show the title and the excerpt of any document to its readers. Option 4 depends on how each app models its permissions, and a manager of a Team folder is not the synchronization that writes it.

## Consequences

An administrator brings the archive to several users through a Team folder or a shared external storage, without rebuilding it under one account and sharing it. Whoever may write a trusted mount can show any document to its readers, so the documentation says to trust only a mount that nobody but the synchronization writes, also outside Nextcloud. A user who may write it sees none of its documents: *Try a search* names such a document *Writable in a trusted folder*, and `occ paperless_unified_search:search --explain` `writable mount`. The settings name the trusted mounts below the shares of the archive account. `occ paperless_unified_search:check` can't count the files of a trusted mount, which belong to no account, says that it leaves them out and doesn't fail without a file of the archive account then. The archive account stays required: the search names it as the account that writes the archive.

The end-to-end test mounts a real external storage; a Team folder is tested by unit tests that follow the source of groupfolders (`GroupMountPoint` is a system mount of type `group` with `getFolderId()`), because the E2E image doesn't install groupfolders yet. A test of a real Team folder is a follow-up: should groupfolders rename those methods, a Team folder would no longer be trusted, which shows no document rather than too many.
