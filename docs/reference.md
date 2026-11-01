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
| **Let users include Paperless in all their searches** | Lets each user choose **Always search my Paperless documents** in their personal settings; off hides the choice, and the search ignores what users chose | On | `personal_always_search` |
| **Let users ask for notifications about new documents** | Lets each user save searches for notifications in their personal settings; off hides the choice, and the job asks Paperless nothing | On where Nextcloud has a memory cache, until an administrator chooses | `new_document_alerts` |

**Disconnect** deletes the URL, the token, the switches, the archive account, the version of Paperless and the history of the problems. **Clear history** deletes only the history.

### Personal settings

Under **Personal settings → Paperless**, each user can select **Always search my Paperless documents**: every search of their own then asks Paperless without **Search connected services**, from the next page that they open, and the terms go to the Paperless server even when they look for a file or a contact. The checkbox saves as it changes. The section shows while Paperless is connected with an archive account and the administrators let users choose; while **Always include Paperless in global search** is on, it shows the checkbox ticked and greyed out, as every search includes Paperless anyway.

| Setting | Default | User preference |
| --- | --- | --- |
| **Always search my Paperless documents** | Off | `always_search` of the app, stored only while it is chosen |
| **Notify me about new documents** | Off | `new_document_alerts` of the app, a lazy value, stored only while it asks for something, as JSON: `everyDocument`, and the saved searches in `terms` |

An administrator reads the choice of a user with `occ user:setting <user> paperless_unified_search always_search` and takes it back with `occ user:setting <user> paperless_unified_search always_search --delete`. While **Let users include Paperless in all their searches** is off, the stored choices stay but count for nothing; they count again once it is on.

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
| `personal_always_search` | Whether users may choose to include Paperless in every search of their own; missing means yes |
| `new_document_alerts` | Whether users may ask for notifications about new documents; missing means yes where Nextcloud has a memory cache |
| `new_document_alerts_progress` | How far the job of the notifications has come, as JSON: the hash of the URL of the server, the highest ID seen, the moment of the last answer, the documents that wait for their files with their titles, and the failures in a row with the end of the rest; a lazy key. The job deletes it while nobody asks for notifications |
| `paperless_version`, `paperless_api_version` | The versions of Paperless and of its API, such as `3.3.0` and `10`, as its last answer named them |
| `token_warnings` | The warnings about the account of the API token that the last test of the connection found, separated by commas: `superuser`, `staff`, `changes_documents`, `hides_correspondents`, `hides_document_types`, `hides_tags` and `hides_names_of_others`; missing when there are none, or Paperless didn't say. A lazy key |
| `diagnostics` | The history of the problems of the search, as JSON; a lazy key, which Nextcloud loads only when it is read |
| `diagnostics_pending` | Whether a failure in the history still waits for a search that works |

The account of Paperless Sync is `occ config:app:get paperless_sync target_user`.

Keys that an older version left and this one no longer reads go with the next update: Nextcloud runs the repair step *Remove the last failed search that Paperless Unified Search 0.3.0 kept* after every update of the app and on `occ maintenance:repair`, and it deletes `last_failure` and `last_recovery`, which the history of the problems replaced in 0.4.0.

Change the settings in the administration settings, which test the connection first. `occ config:app:set` can still turn a switch on or off, such as `occ config:app:set paperless_unified_search always_search --value=true --type=boolean`, which every user's next reload takes. Don't change `paperless_url` that way: the stored token would follow the URL to the new server, which the settings prevent by asking for the token again.

--8<-- "README.md:automation"

## The routes of the settings

The settings page saves through four routes of the app, which only administrators may call, each with Nextcloud's CSRF token, and the personal settings through two more that every user who signed in may call. A script calls the four with the login of an administrator, and these two with the login of the user whose choice they store, each with the header `OCS-APIRequest: true`, as the end-to-end tests do; `/index.php` goes before `/apps` where Nextcloud runs without pretty URLs.

| Route | Does |
| --- | --- |
| `POST /apps/paperless_unified_search/settings` | Tests the connection and saves, with the parameters `url`, `token`, `archiveOwner`, `allowLocal`, `alwaysSearch` and `personalAlwaysSearch`; asks for the password like **Test connection and save** |
| `DELETE /apps/paperless_unified_search/settings` | **Disconnect**: deletes the settings, the token and the history; asks for the password |
| `DELETE /apps/paperless_unified_search/settings/diagnostics` | **Clear history** |
| `POST /apps/paperless_unified_search/settings/try` | **Try a search**: searches with the parameter `term` as the account `user` and says for each document whether it shows and why not, with its ID, title and tags alone; the log records which administrator tried a search as whom, without the term |
| `POST /apps/paperless_unified_search/personal` | **Always search my Paperless documents** of the user who signed in, with the parameter `alwaysSearch`; answers `{"alwaysSearch": true}` or `false`, and HTTP 403 with the code `not_allowed` while the administrators don't let users choose |
| `POST /apps/paperless_unified_search/personal/alerts` | **Notify me about new documents** of the user who signed in, with the parameters `everyDocument` and `terms`, a list; answers the choice as the server keeps it, without blank lines and repeats, or a `message` with the `code` `not_allowed`, `too_many_searches` for more than 10, or `search_too_long` for more than 500 characters |

```sh
curl -u admin -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  --data-urlencode 'url=https://paperless.example.com' \
  --data-urlencode 'archiveOwner=paperless' \
  --data-urlencode "token@$HOME/.paperless-token" \
  'https://cloud.example.com/apps/paperless_unified_search/settings'
```

`token@file` hands the token over in a file, which keeps it out of the history of the shell and the list of processes. A saved setting answers HTTP 200 with the settings as JSON: `url`, `tokenConfigured`, `alwaysSearch`, `archiveOwner`, `syncAccount`, `tokenUnreadable`, `archiveOwnerMissing`, `allowLocal`, `paperlessVersion`, `paperlessApiVersion`, `personalAlwaysSearch` and `tokenWarnings`, the [warnings about the account of the token](troubleshooting.md#warnings-about-the-api-token) as the key `token_warnings` names them, empty when there are none or Paperless didn't say, never the token. A save without `alwaysSearch` or `allowLocal` sets the switch to its default, off; a save without `personalAlwaysSearch` keeps it as it is. A refusal answers HTTP 400, or 500 when something after the test failed, with the `message` to show in the language of the administrator and a `code`:

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
| **Items** | `GET /ocs/v2.php/apps/dashboard/api/v2/widget-items?widgets[]=paperless_unified_search-recent` with `limit` (7 by default, up to 30), as Nextcloud's dashboard and its apps ask for them: the newest documents that the user can open, each with its title, a line with the day that Paperless added it, its correspondent, its type and up to two of its tags, the link and the preview of its file, and as `sinceId` the moment that Paperless added it; `sinceIds[paperless_unified_search-recent]` with such a moment lists only the documents added after it |
| **The link below the list** | *Files shared with you*, the view of Files with the files that others share with the user; Nextcloud's web dashboard shows it, and six documents, only once the list is full; none for the archive account |
| **Paperless** | One request for the 25 documents that it added last, at most once a minute for all users together, which Nextcloud's distributed cache keeps meanwhile (with a memory cache, as Nextcloud recommends; without one, every dashboard asks Paperless); a Paperless that doesn't answer rests for a minute as well, and the widget says *Paperless can't be reached right now.* |

## The notifications about new documents

| | |
| --- | --- |
| **The job** | `OCA\PaperlessUnifiedSearch\BackgroundJob\NotifyNewDocuments`, every five minutes as Nextcloud's cron allows, one run at a time; `occ background-job:list --class='OCA\PaperlessUnifiedSearch\BackgroundJob\NotifyNewDocuments'` shows it |
| **It runs** | While **Let users ask for notifications about new documents** is on, Paperless is connected, an archive account exists and someone asks for notifications; otherwise it forgets its progress and asks nothing |
| **Paperless** | One request per run for the new documents, and only for a document with a file in the archive account one request per distinct saved search, as [below](#requests-to-paperless) |
| **Limits** | 10 saved searches per user, of up to 500 characters; 100 new documents per run; 200 documents waiting for their files, for up to two days |
| **Rest after a failure** | 10, 20 and 40 minutes, then an hour until Paperless answers again |
| **The notification** | One per user and run; the subject `new_documents`, the object `paperless_documents` with the ID of the newest document, and the parameters `count` and `documents` with the title, the ID and the path of the file of up to three |

## Requests to Paperless

Every request of the app to Paperless is one `GET` of `<URL>/api/documents/`, of `/api/correspondents/`, `/api/document_types/` and `/api/tags/` for the names of the correspondents, types and tags of a page and, in a test of the connection, of its newest documents, or of `/api/ui_settings/` for what the account of the token may do, from the Nextcloud server:

| | |
| --- | --- |
| **Headers** | `Authorization: Token …`, `Accept: application/json` and `User-Agent: Nextcloud-Paperless-Unified-Search/<version>` |
| **The account of the token** | **View** for documents, correspondents, document types, tags and UI settings, and nothing else. Every answer holds only the documents, correspondents, types and tags that the account may view: those that it owns, those without an owner, and those whose permissions let it or one of its groups view them; a note of a document matches without the permission to view notes. [What each permission gives the search](configure.md#what-each-permission-gives-the-search) says what each of them does |
| **A search** | `query` with the term, `page`, `page_size=25` and `fields=id,title,created,correspondent,document_type,tags,__search_hit__`; on Paperless 3, a query that it can't parse yet once more with `text` in place of `query` |
| **The test of the connection** | `page_size=1` and `fields=id`; once Paperless answers, `/api/ui_settings/` without parameters, with 3 seconds for the answer and no second try, for the warnings about the account of the token. Of its answer, the app reads only `user.is_superuser`, `user.is_staff` and `permissions`; Paperless answers HTTP 403 to an account that may not view its UI settings, and the warnings stay unknown. Of an account that isn't a superuser and may view documents, it then asks for the 25 newest with `ordering=-added`, `page=1` and `fields=id,correspondent,document_type,tags`, and for the names of their correspondents, types and tags, again with 3 seconds and no second try, for the warning about those that another user owns |
| **The newest documents of the dashboard** | `ordering=-added`, `page=1`, `page_size=25` and `fields=id,title,added,correspondent,document_type,tags`, at most once a minute for all users, with 5 seconds for the answer and no second try |
| **The new documents of the notifications** | `ordering=-id`, `page=1`, `page_size=100`, `fields=id,title` and `added__date__gte` with the day before the last answer, which keeps the answer short, in one try, every five minutes while someone asks for notifications and Paperless answers; the first run asks for `page_size=1` without the day |
| **A saved search of the notifications** | `query` with the term, on Paperless 3 for a query that it can't parse once more `text` with its words, `id__in` with the new documents that have files, `page=1`, `page_size` as many as those and `fields=id`, in one try, once per distinct search and only when such documents exist |
| **Time** | 3 seconds to connect, 10 seconds for the answer |
| **A second try** | Only for a request that failed within 3.5 seconds, before it had a connection, and whose address Nextcloud didn't refuse |
| **Redirects** | Without **Paperless runs in my local network**, up to 5, never to an address of the local network; Nextcloud leaves the token out of a redirect to another scheme, host or port, which Paperless then refuses, and keeps it for one within the same server. With the switch, none |

## Compatibility

| | Nextcloud 33 | Nextcloud 34 | Nextcloud 35 |
| --- | --- | --- | --- |
| **Paperless-ngx 3.x** (API 9) | End-to-end tests | End-to-end tests | End-to-end tests |
| **Paperless-ngx 2.16 to 2.20** (API 9) | Unit tests | Unit tests | Unit tests |

- **Nextcloud:** the end-to-end tests run the app in each version that `appinfo/info.xml` supports: in its pinned release in every pull request that changes the app and on every change of `main`, where Renovate moves the pins within about a week of a new release, and in its current release every Monday, so that a new release of a supported version that breaks the app opens an issue within a week, and every Monday also in the coming version of Nextcloud, its beta or release candidate.
- **Paperless-ngx:** the end-to-end tests ask a stand-in of Paperless that answers as Paperless-ngx 3.3.0 does: its pages, its fields, its headers of the version, its excerpts and its refusal of a query that is still being typed. The unit tests check the answers of 2.20 and 3.3, such as that only Paperless 3 gets the simple mode of its search, which Paperless 2 doesn't know. Every request names version 9 of the API, which Paperless-ngx 2.16 and newer and every 3.x speak, so a new release of Paperless can't change the results unnoticed. Contract tests check the app against real Paperless-ngx, the last release of 2.20 and the newest of 3.x: the fields, dates, excerpts, pages and errors of a search, the newest documents of the dashboard, and the names of the correspondents, document types and tags that a result shows, as the app asks for them. They run every week and, as the required checks `paperless (2)` and `paperless (3)`, in every pull request that changes the app, so no change that breaks them reaches `main`. An older Paperless answers HTTP 406, and the test of the connection says so. Before Paperless-ngx 2.20.13, Paperless doesn't check the document of a search for similar documents, the term `like:`: it answers an ID that it doesn't have with the error of a failed search, which the app counts as finding nothing, for that term alone, also when its index fails, and it finds similar documents for one that its account may not view, of those that the account may view.
- **PHP:** 8.2 or newer, as Nextcloud supports it; the unit tests run on 8.3, as PHPUnit 12 needs it.

## Data flows

| From | To | What | When |
| --- | --- | --- | --- |
| The browser or an app of the user | Nextcloud | The search term | Whenever the user searches |
| Nextcloud | Paperless | The term, the page and the fields to answer with, and the API token | With **Search connected services** on, after choosing **Paperless documents** in the smart picker, through the API, or always when Paperless is trusted |
| Paperless | Nextcloud | Of each document that the account of the token may view: its ID, title, date, the IDs of its correspondent, its type and its tags, and the excerpt around the term; the versions of Paperless and of its API | In every answer |
| Nextcloud | Paperless | The request for what the account of the token may do, and for the IDs and names of the correspondents, types and tags of its newest documents, with the API token | With every test of the connection: **Test connection and save**, `occ paperless_unified_search:setup`, `test` and `check` |
| Paperless | Nextcloud | The user of the token with its ID and name, whether it is a superuser or staff, its groups, its permissions and those of its groups, and the settings of the web interface of Paperless for it; the app keeps only the warnings that follow from whether it is a superuser or staff and from its permissions | In every answer to an account that may view its UI settings |
| Nextcloud | The browser or an app of the user | Of each document whose file the user can open: its title, date, the names of its correspondent, its type and up to two of its tags, and its excerpt, and the link, the ID and the path of its file | In every answer |
| Nextcloud | Paperless | The request for the 25 documents that Paperless added last, with the fields that the dashboard shows, and the API token | At most once a minute, when the dashboard of a user shows the widget |
| Paperless | Nextcloud | Of each of these documents that the account of the token may view: its ID, title, the moment that Paperless added it and the IDs of its correspondent, its type and its tags | In every answer |
| Nextcloud | The browser or an app of the user | Of each of the newest documents whose file the user can open: its title, the day that Paperless added it, its correspondent, its type and up to two of its tags, and the link and the preview of its file | Whenever the dashboard shows the widget |
| Nextcloud | Paperless | The saved searches of the users who asked for notifications, among the IDs of the new documents with files, and the API token | Once per distinct search, when a new document has its file |
| Paperless | Nextcloud | The IDs and titles of the documents that it added since the last run, and the IDs of those that each search finds | Every five minutes while someone asks for notifications |
| Nextcloud | The browser or an app of the user | The number of the new documents that the user asked for and can open, and of the newest three the title, the ID and the path of the file | As the client fetches its notifications |

<div class="diagram-wide" markdown tabindex="0" role="region" aria-label="Diagram of the data flow of a search">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-data-flow-of-a-search.dark.svg">
  <img src="images/diagrams/the-data-flow-of-a-search.svg" alt="The data flow of a search: A user searches for invoice. The unified search passes the term, the cursor, the limit, the date filter and the user to the search provider. Without a term, an archive account or a connection, the provider answers with no results and doesn't ask Paperless. Otherwise, until the page of results is full, the Paperless client asks Paperless-ngx with the token for the page of 25 hits that holds the next hit, within the days of the date filter, and only for the fields that a result shows, and Paperless answers with the ID, the title, the date, the IDs of the correspondent, the type and the tags and the excerpt of documents 412, 389, 371 and more, and whether a next page exists. One call of the file locator covers all documents of that page and returns for each a file of the archive account with its marker, or none. For the documents with a file, the names ask Paperless, through the client, for the correspondents, the types and the tags that the cache doesn't hold, with one request per kind, and keep their names for ten minutes. This repeats for at most 4 pages of Paperless or 3 seconds. The provider answers with an entry for every document with a file, up to the limit, with the preview of the file or the icon of its type, the title, the date in the language of the user, the correspondent, the type and the excerpt, and the cursor of the first hit that it didn't go through.">
</picture>

</div>

Nextcloud stores the settings in its app configuration, with the warnings about the account of the token that the last test found, the token encrypted in its credentials manager, and the history of the latest 20 problems, without the term, the token and the query of any URL. Its distributed cache keeps the names of correspondents, types and tags for ten minutes, and of the 25 newest documents their IDs, titles, the moments that Paperless added them and the IDs of their correspondents, types and tags for a minute, for all users; nothing of the text of the documents is stored. [Architecture](architecture.md#data-flow) explains every step, and the [security design](security.md) what protects each of them.

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
| Warning | The notifications about new Paperless documents try again in *n* seconds (*error*) | The job couldn't ask Paperless or look for the files, and rests |
| Warning | *n* saved search(es) of the notifications about new Paperless documents failed (*error*) | Paperless answered the others but not these, such as a wildcard that took too long: they found nothing in this run, the log doesn't name them, and the job goes on |
| Warning | A user got no notification about new Paperless documents (*error*) | The files of that user failed to answer, or Nextcloud refused the notification; the others got theirs |
| Info | Paperless refused *n* saved search(es) of the notifications about new documents | Those searches found nothing in this run; the log doesn't name them |
| Info | Paperless added *n* or more documents between two runs of the notifications about new documents: older ones get none | A large import |
| Debug | Paperless rejected the query of a search with HTTP *status* | A query still being typed, or a page past the last one: no problem |

## Uninstall

1. **Disconnect** in the settings. It deletes the URL, the token, the switches, the archive account, the version of Paperless, the progress of the notifications about new documents and the history of the problems; only `diagnostics_pending` stays, with the keys that Nextcloud keeps of every app, such as `enabled` and `installed_version`.
2. **Remove the app:** `occ app:remove paperless_unified_search` disables it and deletes its code; or *Apps → Disable* and *Remove* in the browser. Nextcloud keeps the app configuration of a removed app: `occ config:list paperless_unified_search` lists what is left, and `occ config:app:delete paperless_unified_search <key>` deletes a key. The choices of the users stay as their preferences `always_search` and `new_document_alerts`, the latter with their saved searches, which `occ user:setting <user> paperless_unified_search always_search --delete` and the same with `new_document_alerts` delete.
3. **In Paperless**, delete the API token, or the account of the search: a token that Nextcloud forgot still works until Paperless forgets it too.

The files of the archive and their shares stay as they are: they belong to the archive account and to the synchronization, not to this app. Without **Disconnect**, the encrypted token stays in the credentials manager of Nextcloud, under `paperless_unified_search.api-token`, until the app is installed again and disconnected.
