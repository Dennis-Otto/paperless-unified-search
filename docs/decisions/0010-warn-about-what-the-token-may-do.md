---
description: "Why every test of the connection asks Paperless what the account of the API token may do, warns about a superuser, an admin or an account that may change documents or may not view correspondents and types without refusing the token, and stores the warnings for the overview."
---

# Warn about what the account of the API token may do

- Status: accepted
- Date: 2026-10-25

## Context

The search only reads: it needs an account that may view documents, and that may view correspondents and document types for their names in the results. The documentation recommended such an account, but nothing checked it. Most administrators paste the token of their own account, a superuser, and the test of the connection said that it succeeded; whoever later got hold of the token, as from a backup of Nextcloud, could change and delete every document. The opposite mistake was silent too: an account that may not view correspondents or types showed results without their names, and nothing said why.

Paperless names what the account of a token may do in its answer to `/api/ui_settings/`, which its web interface loads after the login: the user, with `is_superuser` and `is_staff`, and the permissions of the account and its groups, such as `view_document`, without the label of their app. Paperless-ngx 2.20 and 3.x answer it alike, and only to an account that may view its UI settings; any other gets HTTP 403. The overview of the administration settings asks Paperless nothing ([decision 0007](0007-occ-takes-no-token-as-an-argument.md)).

## Options

1. Refuse a token that may do more than the search needs. The safest, but it would break the set-up of everyone who uses the token of a superuser with the next update, and an administrator may have reasons of their own.
2. Warn without refusing, after each test of the connection, in the settings, `occ` and the overview, with what to change.
3. Find the permissions by trying, such as with a request to change a document. That would change data or depend on the error of a request that Paperless may answer differently from version to version.

For the overview: ask Paperless with a cache of an hour, or show what the last test of the connection found.

## Decision

Option 2. Once Paperless has answered the test of the connection, the app asks `/api/ui_settings/` with one try and 3 seconds, and warns, never fails, about:

- a superuser, who may change and delete every document;
- an admin (`is_staff`), who may also read the logs and the status of Paperless;
- the permission to add, change or delete documents, of the account or one of its groups;
- the lack of the permission to view correspondents, or document types, whose names the results then lack.

An answer other than HTTP 200 with the user and its permissions, as to an account that may not view its UI settings, or none in time, leaves the warnings unknown: the settings warn about nothing, and `occ paperless_unified_search:check` says that it can't tell. The documentation therefore asks for the permission to view the UI settings, which shows the account nothing of the documents.

**Test connection and save**, `occ paperless_unified_search:setup`, `test` and `check` ask Paperless each time, so that the next test after a change of the account shows it at once, and store what they found. The settings page and the overview show the stored warnings, the overview after every reason that the search shows no documents, so that it still sends no request to Paperless; a cache of an hour would have added a request whenever it ran out, and shown a corrected account an hour late.

## Consequences

Since [#229](https://github.com/Dennis-Otto/paperless-unified-search/issues/229), the test also warns about a correspondent, a type or a tag of the newest documents that the account can't see. `/api/ui_settings/` can't tell, since the permission is there and Paperless applies the permissions of each object as well, so the app compares what the 25 newest documents name with the lists of their correspondents, types and tags: up to four more requests of the same kind, for an account that isn't a superuser and may view documents.

Saving and the search work as before with every token. An administrator who uses a token that may do too much, or too little, sees it below the token, in `occ` and in the overview, with what to change, and [Troubleshooting](../troubleshooting.md), under *Warnings about the API token*, explains each warning. A test of the connection sends one request more, which Paperless answers from its database. The overview shows what the account could do at the last test: a permission granted since shows at the next test, such as `occ paperless_unified_search:check` in a scheduled job. The contract tests check the answer of `/api/ui_settings/` against real Paperless-ngx 2.20 and 3.x, for a superuser, an account that may only view and one that may not view its UI settings.
