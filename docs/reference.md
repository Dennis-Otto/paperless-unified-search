---
description: "Reference of Paperless Unified Search for administrators: every setting and its app config key, occ, the routes of the settings and the search, compatibility, data flows, the log, and uninstalling."
---

# Reference

Everything the app knows of, to look up: the settings, the keys in which Nextcloud keeps them, the routes, the versions that the tests cover, where data goes, and what stays when the app goes.

## Settings

The settings are under **Administration settings → Paperless Unified Search**, for administrators only, and hold for every user of the Nextcloud. **Test connection and save** stores them only once Paperless answers; it and **Disconnect** ask for the password when it wasn't confirmed in the last 30 minutes.

| Setting | What it does | Default | App config key |
| --- | --- | --- | --- |
| **Paperless URL** | The address of Paperless, with a path when Paperless runs under one, such as `https://paperless.example.com` | — | `paperless_url` |
| **Paperless runs in my local network** | Lets Nextcloud reach this URL, and no other, at an address of the local network, without redirects ([details](configure.md#paperless-in-your-local-network)) | Off | `allow_local` |
| **Paperless API token** | The token of the Paperless account of the search; blank keeps the stored one, for the same server | — | None: in Nextcloud's credentials manager, as `paperless_unified_search.api-token` |
| **Archive account** | The Nextcloud account whose files stand for the documents; blank takes the account of Paperless Sync | The account of Paperless Sync | `archive_owner` |
| **Always include Paperless in global search** | Asks Paperless in every search of every user, without **Search connected services**; reload Nextcloud after a change | Off | `always_search` |

**Disconnect** deletes the URL, the token, the switches, the archive account, the version of Paperless and the history of the problems. **Clear history** deletes only the history.

## App configuration

Nextcloud keeps the settings in its app configuration, which `occ` reads:

```sh
occ config:list paperless_unified_search
occ config:app:get paperless_unified_search paperless_url
```

| Key | Holds |
| --- | --- |
| `paperless_url` | The URL of Paperless, as the settings stored it: without a slash at its end, with the host in its ASCII form |
| `allow_local` | Whether Paperless runs in the local network |
| `archive_owner` | The ID of the archive account, as Nextcloud spells it; missing when the settings take the account of Paperless Sync |
| `always_search` | Whether every search asks Paperless |
| `paperless_version`, `paperless_api_version` | The versions of Paperless and of its API, such as `3.3.0` and `10`, as its last answer named them |
| `diagnostics` | The history of the problems of the search, as JSON; a lazy key, which Nextcloud loads only when it is read |
| `diagnostics_pending` | Whether a failure in the history still waits for a search that works |

The account of Paperless Sync is `occ config:app:get paperless_sync target_user`.

Keys that an older version left and this one no longer reads go with the next update: Nextcloud runs the repair step *Remove the last failed search that Paperless Unified Search 0.3.0 kept* after every update of the app and on `occ maintenance:repair`, and it deletes `last_failure` and `last_recovery`, which the history of the problems replaced in 0.4.0.

Change the settings in the administration settings, which test the connection first. `occ config:app:set` can still turn a switch on or off, such as `occ config:app:set paperless_unified_search always_search --value=true --type=boolean`, which every user's next reload takes. Don't change `paperless_url` that way: the stored token would follow the URL to the new server, which the settings prevent by asking for the token again.

--8<-- "README.md:automation"

## The routes of the settings

The settings page saves through four routes of the app, which only administrators may call, each with Nextcloud's CSRF token. A script calls them with the login of an administrator and the header `OCS-APIRequest: true`, as the end-to-end tests do; `/index.php` goes before `/apps` where Nextcloud runs without pretty URLs.

| Route | Does |
| --- | --- |
| `POST /apps/paperless_unified_search/settings` | Tests the connection and saves, with the parameters `url`, `token`, `archiveOwner`, `allowLocal` and `alwaysSearch`; asks for the password like **Test connection and save** |
| `DELETE /apps/paperless_unified_search/settings` | **Disconnect**: deletes the settings, the token and the history; asks for the password |
| `DELETE /apps/paperless_unified_search/settings/diagnostics` | **Clear history** |
| `POST /apps/paperless_unified_search/settings/try` | **Try a search**: searches with the parameter `term` as the account `user` and says for each document whether it shows and why not, with its ID and title alone; the log records which administrator tried a search as whom, without the term |

```sh
curl -u admin -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  --data-urlencode 'url=https://paperless.example.com' \
  --data-urlencode 'archiveOwner=paperless' \
  --data-urlencode "token@$HOME/.paperless-token" \
  'https://cloud.example.com/apps/paperless_unified_search/settings'
```

`token@file` hands the token over in a file, which keeps it out of the history of the shell and the list of processes. A saved setting answers HTTP 200 with the settings as JSON: `url`, `tokenConfigured`, `alwaysSearch`, `archiveOwner`, `syncAccount`, `tokenUnreadable`, `archiveOwnerMissing`, `allowLocal`, `paperlessVersion` and `paperlessApiVersion`, never the token. A refusal answers HTTP 400, or 500 when something after the test failed, with the `message` to show in the language of the administrator and a `code`:

| Code | Means |
| --- | --- |
| `invalid_url`, `unsupported_scheme`, `url_extras`, `host_not_ascii` | The URL isn't one, isn't HTTP or HTTPS, has credentials, a query or a fragment, or a host that isn't in its ASCII form |
| `token_required`, `token_for_other_server` | No token is stored, or the stored one is for another server |
| `unknown_archive_account` | The archive account doesn't exist |
| `unauthorized` | Paperless refused the token, with HTTP 401 or 403 |
| `not_found` | HTTP 404: the path of the URL is wrong |
| `redirect` | The URL forwards, and with the local network Nextcloud follows no redirect |
| `http` | Another status, such as an error of Paperless with 5xx |
| `unsupported_version` | HTTP 406: Paperless is older than 2.16 and doesn't speak version 9 of its API; updating Paperless-ngx fixes it |
| `not_paperless` | The answer isn't a list of documents of Paperless-ngx |
| `local_address` | Nextcloud refused the address of the local network |
| `tls`, `tls_handshake` | Nextcloud doesn't trust the certificate, or couldn't set up an encrypted connection |
| `unknown_host`, `timeout`, `unreachable` | The host doesn't resolve, Paperless didn't answer in time, or the connection failed |
| `internal` | Something after the test failed, such as the database; the log of Nextcloud has it |

[Troubleshooting](troubleshooting.md#when-the-test-of-the-connection-fails) says what to do about each.

## The search and the smart picker

| | ID | Where |
| --- | --- | --- |
| The search provider | `paperless_unified_search_documents` | **Paperless documents** in Nextcloud's search, and `GET /ocs/v2.php/search/providers/paperless_unified_search_documents/search?term=…` in its OCS API, with `limit` (5 by default, up to 50) and `cursor` |
| The provider of the smart picker | `paperless_unified_search-documents` | **Paperless documents** in the smart picker of Talk, Text, Deck and Mail |

[Use it from scripts and AI assistants](usage.md#use-it-from-scripts-and-ai-assistants) shows the answer of the API.

## The dashboard widget

| | |
| --- | --- |
| **ID** | `paperless_unified_search-recent`, *New in Paperless*, offered once the settings name a Paperless and an archive account that exists |
| **Items** | `GET /ocs/v2.php/apps/dashboard/api/v2/widget-items?widgets[]=paperless_unified_search-recent` with `limit` (7 by default, up to 30), as Nextcloud's dashboard and its apps ask for them: the newest documents that the user can open, each with its title, a line with the day that Paperless added it, its correspondent and its type, the link and the preview of its file, and as `sinceId` the moment that Paperless added it; `sinceIds[paperless_unified_search-recent]` with such a moment lists only the documents added after it |
| **The link below the list** | *Files shared with you*, the view of Files with the files that others share with the user; Nextcloud's web dashboard shows it, and six documents, only once the list is full; none for the archive account |
| **Paperless** | One request for the 25 documents that it added last, at most once a minute for all users together, which Nextcloud's distributed cache keeps meanwhile (with a memory cache, as Nextcloud recommends; without one, every dashboard asks Paperless); a Paperless that doesn't answer rests for a minute as well, and the widget says *Paperless can't be reached right now.* |

## Requests to Paperless

Every request of the app to Paperless is one `GET` of `<URL>/api/documents/`, or of `/api/correspondents/` and `/api/document_types/` for the names of the correspondents and types of a page, from the Nextcloud server:

| | |
| --- | --- |
| **Headers** | `Authorization: Token …`, `Accept: application/json` and `User-Agent: Nextcloud-Paperless-Unified-Search/<version>` |
| **A search** | `query` with the term, `page`, `page_size=25` and `fields=id,title,created,correspondent,document_type,__search_hit__`; on Paperless 3, a query that it can't parse yet once more with `text` in place of `query` |
| **The test of the connection** | `page_size=1` and `fields=id` |
| **The newest documents of the dashboard** | `ordering=-added`, `page=1`, `page_size=25` and `fields=id,title,added,correspondent,document_type`, at most once a minute for all users, with 5 seconds for the answer and no second try |
| **Time** | 3 seconds to connect, 10 seconds for the answer |
| **A second try** | Only for a request that failed within 3.5 seconds, before it had a connection, and whose address Nextcloud didn't refuse |
| **Redirects** | Without **Paperless runs in my local network**, up to 5, never to an address of the local network; Nextcloud leaves the token out of a redirect to another scheme, host or port, which Paperless then refuses, and keeps it for one within the same server. With the switch, none |

## Compatibility

| | Nextcloud 33 | Nextcloud 34 | Nextcloud 35 |
| --- | --- | --- | --- |
| **Paperless-ngx 3.x** (API 9) | End-to-end tests | End-to-end tests | End-to-end tests |
| **Paperless-ngx 2.16 to 2.20** (API 9) | Unit tests | Unit tests | Unit tests |

- **Nextcloud:** the end-to-end tests run the app in the current release of each version that `appinfo/info.xml` supports, in every pull request that changes the app and on every change of `main`, and every Monday also in the coming version of Nextcloud, its beta or release candidate.
- **Paperless-ngx:** the end-to-end tests ask a stand-in of Paperless that answers as Paperless-ngx 3.3.0 does: its pages, its fields, its headers of the version, its excerpts and its refusal of a query that is still being typed. The unit tests check the answers of 2.20 and 3.3, such as that only Paperless 3 gets the simple mode of its search, which Paperless 2 doesn't know. Every request names version 9 of the API, which Paperless-ngx 2.16 and newer and every 3.x speak, so a new release of Paperless can't change the results unnoticed; contract tests check the app every week against real Paperless-ngx, the last release of 2.20 and the newest of 3.x. An older Paperless answers HTTP 406, and the test of the connection says so.
- **PHP:** 8.2 or newer, as Nextcloud supports it; the unit tests run on 8.2.

## Data flows

| From | To | What | When |
| --- | --- | --- | --- |
| The browser or an app of the user | Nextcloud | The search term | Whenever the user searches |
| Nextcloud | Paperless | The term, the page and the fields to answer with, and the API token | With **Search connected services** on, after choosing **Paperless documents** in the smart picker, through the API, or always when Paperless is trusted |
| Paperless | Nextcloud | Of each document that the account of the token may view: its ID, title, date, the IDs of its correspondent and its type, and the excerpt around the term; the versions of Paperless and of its API | In every answer |
| Nextcloud | The browser or an app of the user | Of each document whose file the user can open: its title, date and excerpt, and the link, the ID and the path of its file | In every answer |
| Nextcloud | Paperless | The request for the 25 documents that Paperless added last, with the fields that the dashboard shows, and the API token | At most once a minute, when the dashboard of a user shows the widget |
| Paperless | Nextcloud | Of each of these documents that the account of the token may view: its ID, title, the moment that Paperless added it and the IDs of its correspondent and its type | In every answer |
| Nextcloud | The browser or an app of the user | Of each of the newest documents whose file the user can open: its title, the day that Paperless added it, its correspondent and its type, and the link and the preview of its file | Whenever the dashboard shows the widget |

<div class="diagram-wide" markdown tabindex="0" role="region" aria-label="Diagram of the data flow of a search">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-data-flow-of-a-search.dark.svg">
  <img src="images/diagrams/the-data-flow-of-a-search.svg" alt="The data flow of a search: A user searches for invoice. The unified search passes the term, the cursor, the limit, the date filter and the user to the search provider. Without a term, an archive account or a connection, the provider answers with no results and doesn't ask Paperless. Otherwise, until the page of results is full, the Paperless client asks Paperless-ngx with the token for the page of 25 hits that holds the next hit, within the days of the date filter, and only for the fields that a result shows, and Paperless answers with the ID, the title, the date, the IDs of the correspondent and the type and the excerpt of documents 412, 389, 371 and more, and whether a next page exists. One call of the file locator covers all documents of that page and returns for each a file of the archive account with its marker, or none. For the documents with a file, the names ask Paperless, through the client, for the correspondents and the types that the cache doesn't hold, with one request per kind, and keep their names for ten minutes. This repeats for at most 4 pages of Paperless or 3 seconds. The provider answers with an entry for every document with a file, up to the limit, with the preview of the file or the icon of its type, the title, the date in the language of the user, the correspondent, the type and the excerpt, and the cursor of the first hit that it didn't go through.">
</picture>

</div>

Nextcloud stores the settings in its app configuration, the token encrypted in its credentials manager, and the history of the latest 20 problems, without the term, the token and the query of any URL. Its distributed cache keeps the names of correspondents and types for ten minutes, and of the 25 newest documents their IDs, titles, the moments that Paperless added them and the IDs of their correspondents and types for a minute, for all users; nothing of the text of the documents is stored. [Architecture](architecture.md#data-flow) explains every step, and the [security design](security.md) what protects each of them.

--8<-- "README.md:security-and-privacy"

## The log of Nextcloud

The app writes to the log of Nextcloud with `app` set to `paperless_unified_search`, never with the term or the token:

| Level | Message | Means |
| --- | --- | --- |
| Warning | The test of the connection to Paperless failed: *reason* (*error*) | **Test connection and save** failed; the reason is the `code` of the routes above |
| Error | The settings of Paperless Unified Search could not be saved (*error*) | Something after the test failed, such as the database |
| Warning | Paperless unified search failed (*error*) | A search failed; *Search problems* has it too |
| Warning | Paperless unified search left out *n* result(s) it could not show (*error*) | Results whose files failed to answer were left out |
| Warning | Paperless unified search skipped *n* file(s) it could not read (*error*) | Files of the archive that Nextcloud couldn't read |
| Warning | Paperless unified search looks for the files of each document on its own … | A version of Nextcloud whose query of the file cache the app doesn't know yet: the search still works, with one query for each document instead of one for each page |
| Warning | The stored Paperless API token can't be decrypted … | The `secret` of `config.php` changed: enter the token again |
| Debug | Paperless rejected the query of a search with HTTP *status* | A query still being typed, or a page past the last one: no problem |

## Uninstall

1. **Disconnect** in the settings. It deletes the URL, the token, the switches, the archive account, the version of Paperless and the history of the problems; only `diagnostics_pending` stays, with the keys that Nextcloud keeps of every app, such as `enabled` and `installed_version`.
2. **Remove the app:** `occ app:remove paperless_unified_search` disables it and deletes its code; or *Apps → Disable* and *Remove* in the browser. Nextcloud keeps the app configuration of a removed app: `occ config:list paperless_unified_search` lists what is left, and `occ config:app:delete paperless_unified_search <key>` deletes a key.
3. **In Paperless**, delete the API token, or the account of the search: a token that Nextcloud forgot still works until Paperless forgets it too.

The files of the archive and their shares stay as they are: they belong to the archive account and to the synchronization, not to this app. Without **Disconnect**, the encrypted token stays in the credentials manager of Nextcloud, under `paperless_unified_search.api-token`, until the app is installed again and disconnected.
