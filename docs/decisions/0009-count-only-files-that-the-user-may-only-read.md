---
description: "Why a file of the archive account stands for a document only for a user who may only read it, and why the app shows administrators how the archive is shared."
---

# Count only the files that the user may only read

- Status: accepted
- Date: 2026-10-25

## Context

[0002](0002-trust-the-archive-account.md) lets only the files of the archive account stand for documents, and left one way around it: whoever may write into a folder of the archive account, through a share with write permission, can give a file of that account the marker of any document, such as `Copy [P999].pdf`, and see the title and the excerpt of document 999, which the account of the API token finds in Paperless. A file that a user uploads into such a share, or renames there, belongs to the archive account. The only defence was a sentence of the documentation, *Share the archive read-only*, and nothing showed an administrator whether the archive was.

## Options

1. Keep the advice and leave the shares to the administrators.
2. For a user other than the archive account, count a file only when the user may neither change it nor add files to its folder of the archive account, as Nextcloud's permissions of that user's view of the file say; and show administrators every share of the archive that allows changes.
3. Count a file only when no share of its folders allows changes to anyone, from the list of the shares of every folder above it.
4. Check the content of every file against the document, by its checksum in Paperless.

## Decision

Option 2. The permissions come with the file that the search of the file cache finds, and the folder of a file is asked once for all of its files, so a search costs at most one more lookup per folder. A file shared on its own lies in a folder of the user, where a file that the user adds is the user's own, so only a folder of the archive account counts. The user may reach one file through several shares, such as the base folder read-only and the inbox in it with write permission: the rule judges the file by its ID, so a file that the user may write through any share counts through none. A file shared on its own is the root of its share, whose name every recipient may change, even one who may only read it, so its markers come from the name that the archive account gave it. The archive account writes its own files and finds them all.

Every way to the documents goes through the file locator, so the rule holds for the search, the smart picker, the dashboard widget and the OCS API alike. `occ paperless_unified_search:search --explain` and **Try a search** name such a file *writable*. The settings, `occ paperless_unified_search:check` and the setup check of the overview list the shares of the archive account's files and folders: with how many users, groups, teams and other recipients the archive is shared, how many public links it has, and a warning for each share that allows its recipients to create or change files. Deleting a file gives no file a marker, so a share that allows only that warns of nothing. The list reads the database of Nextcloud and changes nothing, also not the expired shares that Nextcloud would delete as it lists them.

Option 3 would ask for the shares of every folder above every file of every search, and option 4 would read every file and ask Paperless once more for each result, as [0002](0002-trust-the-archive-account.md) found.

## Consequences

A user with a share that allows changes no longer finds the documents in it, and the settings, `check` and the overview say which share it is and that sharing it read-only corrects it. The rule judges the searching user: a file that someone with write permission or a public link that allows uploads put into a folder that others may only read still counts for them, which the warnings name, and a file that stays behind once a share is read-only again counts as well, so a synchronization that removes files it didn't write, or a look into the archive, takes care of those. The same holds for a file that leaves the folder with write permission for one that its writer may only read, as an inbox file that Paperless refuses and Paperless Sync moves, with its name, into its error folder.
