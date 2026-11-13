---
description: "Why the action of Files that finds the documents similar to a file shows them in a dialog of its own, through the search of Nextcloud, and needs no build."
---

# Show the documents similar to a file of Files in a dialog of the app

- Status: accepted
- Date: 2026-11-13

## Context

`like:412` finds the documents similar to the one with the marker `[P412]` (#277), but only for whoever types it into the search, with the ID read off the name of the file. People who have one letter of a case open in Files need a way there in Files itself (#411), as Paperless Sync already offers *Open in Paperless* on the same files.

The obvious way, an action that opens the search of Nextcloud with the term, has no interface: no Nextcloud from 33 to 35 lets a script hand its search a term, neither by an event, an address nor a function. Nextcloud 33 opens a dialog from a button of the header, Nextcloud 34 from a button that looks like a field, and Nextcloud 35 searches in a field of the header with the results below it; a script would have to drive those, which change with every release. And the search finds Paperless only once its switch *Search connected services* is on, which Nextcloud turns off with every page, unless an administrator trusts Paperless or the user chose to include it in every search.

Files of Nextcloud 33 to 35 reads its actions from `@nextcloud/files` 4. The scripts of this app are plain JavaScript without a build, as are those of Paperless Sync, whose [decision 0023](https://github.com/Dennis-Otto/paperless-sync/blob/main/docs/decisions/0023-file-actions-in-files-without-a-build.md) weighs a bundle of that library against registering into its registry directly.

## Options

1. Open the search of Nextcloud by its elements: click its button or fill its field, then turn on *Search connected services*, differently in each version.
2. Ask the search provider of the app through the API of the unified search of Nextcloud, `GET /ocs/v2.php/search/providers/paperless_unified_search_documents/search?term=like:412`, and show the results in a dialog of the app.
3. Link to the page of the document in Paperless, as *Open in Paperless* of Paperless Sync does with *More like this* there.

## Decision

Option 2. `js/files.js` registers *Find similar documents in Paperless* as `registerFileAction()` of `@nextcloud/files` 4 does, into the registry of that version, for a single file whose name carries a marker, outside the trash bin; it loads as an init script, with `css/files.css`, when Files loads the scripts of other apps, for a user while the app has the address, the token and an archive account that the search needs. A click opens a modal `<dialog>` with the results of the provider, ten at a time, as the search shows them.

Option 1 rests on elements that no interface promises and that each of the three versions arranges differently, and turning on a switch for the user is a choice that isn't the script's. Option 3 needs an account of Paperless, which the users of this app usually don't have.

## Consequences

The action finds exactly what `like:412` finds in the search, through the same provider with the same rules of access and the same sealed cursors, whatever the switch: the API of the unified search asks the provider that it names, and the switch belongs to the dialog of Nextcloud alone. The dialog is a small part of the app to keep accessible, and the end-to-end tests open it in Files of every supported Nextcloud. A Files on `@nextcloud/files` 5 would read neither this registry nor that of a bundled library; the end-to-end tests of the pull request that raises `max-version` show it, and the script then takes the registry of that version.
