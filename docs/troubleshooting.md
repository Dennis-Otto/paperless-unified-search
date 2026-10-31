---
description: "Troubleshoot Paperless Unified Search: why Paperless documents don't show in Nextcloud's search, every message of the connection test, certificates, the local network, reverse proxies, the index of Paperless and Search problems."
---

# Troubleshooting

Why Paperless documents don't show, and how to fix it. Start where your problem starts:

- [No Paperless documents in the search](#no-paperless-documents-in-the-search), or not the one you look for.
- [The test of the connection fails](#when-the-test-of-the-connection-fails) when you save the settings.
- [A warning about the API token](#warnings-about-the-api-token) shows below the token.
- [Search problems](#search-problems) at the end of the settings lists failed searches.
- [The search is slow](#the-search-is-slow).

The settings show no error to the users: a search that fails shows them no Paperless documents, as a search that finds none does. That is why the settings keep the history of these problems for the administrators, under **Search problems**, also where the hoster keeps the log of Nextcloud from them.

## No Paperless documents in the search

Six questions, one after the other, find why a document doesn't show for a user, here document 412 for `jamie`:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/why-the-search-shows-no-paperless-documents.dark.svg">
  <img src="images/diagrams/why-the-search-shows-no-paperless-documents.svg" alt="Why the search shows no Paperless documents: jamie searches for invoice and doesn't see document 412 under Paperless documents. Six questions, one after the other, find the cause. Is Search connected services switched on, or is Paperless trusted in the settings? If not, switch it on, again after every reload of the page. Is there an archive account in the settings? If not, enter the account that owns the synchronized files. Does Search problems in the settings list failed searches? If so, fix the error that it names. Does Paperless find document 412 for the term, signed in as the account of the API token? If not, let that account view the document, or rebuild the index of Paperless with document_index reindex. Does the archive account have a file with [P412] in its name? If not, let the synchronization write it, and scan an external storage with occ files:scan. Can jamie open that file? If not, share the file or its folder read-only with jamie. Then document 412 shows under Paperless documents.">
</picture>

1. **Search connected services.** Nextcloud asks Paperless only while the user has switched it on in the search, and turns it off at every reload of the page ([why](usage.md#why-the-switch-is-off-after-a-reload)). **Always include Paperless in global search** in the settings asks Paperless in every search instead.
2. **The archive account.** Without one, no file stands for a document, and the search doesn't even ask Paperless. The settings take the account of [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) when the field is blank, and warn when the account of the field doesn't exist. When they say that the stored API token can't be decrypted, as after a move of Nextcloud with another `secret` in `config.php`, enter it again.
3. **Search problems.** A search that failed shows up there with its error; [below](#search-problems) is what each line means, and the tables of [the test of the connection](#when-the-test-of-the-connection-fails) say how to fix the errors, since a search fails for the same reasons as the test.
4. **What Paperless finds.** Sign in to Paperless as the account of the API token and search for the same term. Paperless shows that account only the documents that it may view: give it the permission to view the others, or to a group that it is in, as [Configure](configure.md#a-paperless-account-for-the-search) describes. When Paperless itself doesn't find a document whose text has the term, its index misses the document: [rebuild it](#the-index-of-paperless).
5. **The file with the marker.** The file must belong to the archive account and carry the exact marker of the document, `[P412]`, in its name: a copy in another account, or `P412` without the brackets, stands for nothing. A file that the synchronization hasn't written yet, as for a document still in the inbox of Paperless, can't show either. Nextcloud finds only the files that its file cache knows: for an external storage, or files copied into the data folder of Nextcloud past it, let `occ files:scan --path=/paperless/files/Paperless` scan the folder of the archive.
6. **The share.** The user needs to be able to open that file: the archive account itself, or a user or a group with whom it shared the file or a folder around it, read-only, as [Configure](configure.md#share-the-archive-read-only) shows. Sign in as the user and look for the file in *Files*: where the user can't open it, the search can't show it. A share that allows changes doesn't count, since the user could have named the file so: share it read-only.

**Try a search** at the end of the settings answers questions 4 to 6 without a shell: enter the term and the user ID, such as `invoice` and `jamie`, and it lists the first 20 documents that Paperless ranks for the term, each with its ID, its title and whether it shows to that user, or why not: *Shared with write permission*, *Not shared with* the user, *Not a file of the archive account*, or *No file with* its marker, such as `[P412]`. When document 412 isn't in the list, Paperless doesn't find it for the term among its first 20: question 4, or a more specific term. It asks the same questions as [`occ paperless_unified_search:search --explain`](reference.md#explain-a-hidden-document) and shows the titles alone.

<figure markdown="span">
  ![Try a search in the settings: the administrator searches for invoice as the account e2e-admin, and a table lists the three documents that Paperless ranks first with their IDs and titles, the electricity invoice shown, the insurance renewal and the internet invoice not shared with that account, above the reasons why a document does not show](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-try-a-search.png#only-light)
  ![Try a search in the settings: the administrator searches for invoice as the account e2e-admin, and a table lists the three documents that Paperless ranks first with their IDs and titles, the electricity invoice shown, the insurance renewal and the internet invoice not shared with that account, above the reasons why a document does not show](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-try-a-search-dark.png#only-dark)
  <figcaption>Try a search in the settings</figcaption>
</figure>

Still missing? [Ask for help](#ask-for-help), with what each of these steps showed.

### What you see, and why

| What you see | Why | What to do |
| --- | --- | --- |
| No section *Paperless documents* at all | Nextcloud shows a section only when it has results, and asks Paperless only with *Search connected services* on | Switch it on, then follow the six questions above |
| The archive account finds the documents, other users don't | The archive isn't shared with them | Share it read-only with them or their group |
| A user with a share that allows changes finds none of its documents | A file that the user may change, or one in a folder where the user may add files, could carry any marker, so it stands for no document | Share it read-only; the settings warn about each such share |
| Some documents show, others don't | The account of the token may not view them, or they have no file with their marker yet | Questions 4 and 5 above |
| Results show no correspondent or no type, and the settings warn about nothing | The account of the token may view correspondents and document types, but not these ones, which another user of Paperless owns | Let the account view them, as [Configure](configure.md#the-names-of-correspondents-and-types) shows; the results show the names within ten minutes |
| Paperless documents vanished after a move or a restore of Nextcloud | The stored API token can't be decrypted with the new `secret` of `config.php` | Enter the token again in the settings |
| Paperless documents vanished after `allow_local_remote_servers` was removed from `config.php` | Nextcloud refuses the address of Paperless in the local network | Select **Paperless runs in my local network** and save |
| A query in the syntax of Paperless finds nothing while you still type it | Paperless can't parse it yet, such as `created:[2026 to` | Finish the query; Paperless 3 finds the words of it meanwhile |
| Only a few results, and no *Load more results* | The user can open the files of only a few of the documents that Paperless ranks first | Normal: the search goes through up to 4 pages of 25 documents of Paperless for each page of results |
| No results at all, not even from Files, when you type right after opening the search on a slow server | Nextcloud 33 and 34 search only once they know their search providers, and don't try again | Change the term. Nextcloud 35 searches by itself |

## When the test of the connection fails

--8<-- "README.md:connection-test"

Before the test, the settings check what you entered. These messages name what to correct:

| The message says | What to do |
| --- | --- |
| Enter a valid Paperless URL | Enter the whole address, with `http://` or `https://`, such as `https://paperless.example.com` |
| The Paperless URL must use HTTP or HTTPS | Start the URL with `http://` or `https://` |
| The Paperless URL must not contain credentials, a query, or a fragment | Leave out `user:password@`, a `?` and a `#`: the token is the only credential |
| Enter the host of the Paperless URL in its ASCII form | Enter a name with letters such as *ü* as punycode, such as `xn--bcher-kva.example` for `bücher.example` |
| A Paperless API token is required | Enter the token: no token is stored yet |
| Enter the API token again: a stored token is only sent to the Paperless server it was saved for | Enter the token again: the URL names another scheme, host or port than the stored one |
| The archive account … does not exist | Enter the ID of an existing Nextcloud account, or leave the field blank for the account of Paperless Sync |

[Reference](reference.md#the-routes-of-the-settings) lists the code that the settings route answers with each message, for scripts that save the settings.

## Warnings about the API token

The test of the connection also asks Paperless what the account of the API token may do, as **Test connection and save**, `occ paperless_unified_search:setup`, `test` and `check` run it. The settings show a warning below the token, `occ` a line with `Warning:` or `!`, and the overview of the administration settings repeats it until the next test. None of them keeps the settings from being saved or the search from working. Change the account in Paperless, under **Users & Groups**, or enter the token of another one, then test the connection again.

| The warning says | What to do |
| --- | --- |
| The API token belongs to a Superuser of Paperless | Add an account of its own for the search, as [Configure](configure.md#a-paperless-account-for-the-search) shows, and enter its token: whoever gets hold of the token of a superuser can change and delete every document |
| The API token belongs to an Admin of Paperless | Clear **Admin** of the account, or use the token of another one: an admin may also read the logs and the status of Paperless |
| The account of the API token may add, change or delete documents | Of the permissions for **Document**, leave the account only **View**, in its own permissions and in those of its groups, which count as well; it keeps **View** for **Correspondent**, **DocumentType** and **UISettings**. **Add** or **Change** for **User** and **Change** for **Group** count too, since Paperless lets such an account give itself the permission to change documents |
| The account of the API token may not view correspondents | Give the account **View** for **Correspondent**; until then the results and the dashboard show no correspondent |
| The account of the API token may not view document types | Give the account **View** for **DocumentType**; until then the results and the dashboard show no type |
| Paperless doesn't say what the account of the API token may do (`occ paperless_unified_search:check` alone) | Give the account **View** for **UISettings**: Paperless tells what an account may do only to one that may view its UI settings, and without it the settings warn about nothing |

## Paperless in your local network

When Paperless runs at an address of your local network or by the name of its Docker service, such as `http://192.168.1.20:8000` or `http://paperless:8000`, Nextcloud refuses to connect to it, and the test of the connection says so. Select **Paperless runs in my local network** in the settings, and save: Nextcloud then allows that address for the requests of this app to the URL of the settings, and for nothing else. [Configure](configure.md#paperless-in-your-local-network) has the details, and why `allow_local_remote_servers` in `config.php` is the wrong fix.

With the switch, Nextcloud follows no redirect: a URL that forwards, as from `http` to `https`, fails the test with its own message. Enter the address that it forwards to.

## Certificates of your own

Nextcloud checks the certificate of every HTTPS server that it connects to. A Paperless with a certificate of an authority of your own, or with a self-signed one, fails the test with *Nextcloud does not trust the certificate of Paperless*. Import the certificate of the authority, or the self-signed certificate itself, into the certificates that Nextcloud trusts:

```sh
occ security:certificates:import /path/to/ca.crt
occ security:certificates
```

The second command lists the certificates that you imported. In Docker, the file has to be inside the container first:

```sh
docker compose cp ca.crt app:/tmp/ca.crt
docker compose exec --user www-data app php occ security:certificates:import /tmp/ca.crt
```

The name in the URL has to be one that the certificate is for: a certificate for `paperless.example.com` fails for `https://192.168.1.20`. Don't switch to `http` to get around a certificate when the connection leaves the computer, since the token would then travel unencrypted.

## Behind a reverse proxy

A proxy in front of Paperless, such as nginx, Traefik, Caddy or the tunnel of Cloudflare, answers for Paperless, and some of its answers fail the test:

- **A path.** When Paperless runs under a path, such as `https://example.com/paperless` with `PAPERLESS_FORCE_SCRIPT_NAME=/paperless`, the URL of the settings needs that path; the app adds `/api/documents/` to it. Without it, the test fails with HTTP 404.
- **A login in front of Paperless**, such as the forward authentication of Authelia or Authentik, answers with its login page or a redirect to it: *This URL answers, but not like Paperless-ngx*, or, with **Paperless runs in my local network**, *This URL forwards to another address*. Let the proxy pass the path `/api/` to Paperless without its login, since the token authenticates every request, or let Nextcloud reach Paperless behind the proxy, at its address in the local network, with **Paperless runs in my local network**.
- **Basic authentication** in the proxy, such as `auth_basic` of nginx, answers HTTP 401 to a request that carries the token in place of its own login: *Paperless rejected the API token (HTTP 401)*, with a token that works. Let the proxy pass the path `/api/` to Paperless without it.
- **The header `Authorization`.** The token travels in it, as `Authorization: Token …`. A proxy that drops the header, or replaces it with one of its own, makes Paperless refuse a correct token with HTTP 401.
- **A firewall or protection against bots**, such as the challenge page of Cloudflare, answers HTTP 403 or a page of its own: *Paperless rejected the API token (HTTP 403)* with a token that works in the browser, or *not like Paperless-ngx*. Let the requests of the Nextcloud server through, whose `User-Agent` starts with `Nextcloud-Paperless-Unified-Search/`.
- **A redirect** to another scheme, host or port, such as from `http` to `https` or to another name: Nextcloud follows it, but leaves the token out, so that the token never reaches a server that it wasn't entered for, and Paperless refuses the request without it: *Paperless rejected the API token*. With **Paperless runs in my local network**, Nextcloud follows no redirect, and the test says *This URL forwards to another address*. Either way, enter the address that the proxy forwards to. A redirect within the same server, such as to the path with a slash at its end, keeps the token.

The log of the proxy shows each request of the app with that `User-Agent` and the version of the app.

## The index of Paperless

Paperless finds documents in an index of their words, which it keeps next to its database. After a restore, a move or a crash of Paperless, the index can miss documents or still hold the words of their old text: the search of Paperless itself then misses them as well, and so does the search of Nextcloud. Rebuild it:

```sh
docker compose exec webserver document_index reindex
```

`webserver` is the service of Paperless in its `compose.yaml`; without Docker, run `python3 manage.py document_index reindex` in the folder `src` of Paperless. A large archive takes a while, and Paperless finds less until it is done.

## Search problems

The end of the settings counts the problems of the search and lists the latest 20 of them, newest first. It shows *No problem recorded* until a search fails or a request needs its second try. **Clear history** and **Disconnect** forget the history; saving the settings keeps it.

| Line | What it shows |
| --- | --- |
| **Recorded since** | When the history began: the first problem after it was last cleared |
| **Failed searches** | How many searches showed no Paperless documents because a request failed, also those that the list no longer holds |
| **Answered on the second try** | How many requests to Paperless failed before they reached it and got their answer on a second try: the users saw the documents, a moment later |
| **Last failed search** | When a search last failed |
| **Searches work again since** | When the first search after the last failure worked, or *No search has worked since* while Paperless is still out of reach |

| Column | What it shows |
| --- | --- |
| **Time** | When the problem happened, in the time zone and the format of your account |
| **Event** | *Search failed*: the user saw no Paperless documents. *Second try answered*: the first try failed before it reached Paperless, the second got the answer |
| **Step** | *Asking Paperless*: the request to Paperless failed. *Looking for the files in Nextcloud*: Paperless answered, but finding the files of its documents in Nextcloud failed |
| **Error** | The kind of the error and its message, such as `ConnectException: cURL error 6: Could not resolve host`, without the API token, the search term and the query of any URL |
| **Duration** | For *Search failed*, how long the search took from its start until it failed: about 6,000 ms when neither of the two tries got a connection within 3 seconds, about 10,000 ms for a Paperless that took the connection but didn't answer, and the time of the pages before when a later page of Paperless failed. For *Second try answered*, how long the first try took: about 3,000 ms for a connection that wasn't set up in time, a few milliseconds for a name that didn't resolve |

A query that Paperless refuses while it is still being typed, and a page past the last result, are no problems and don't show up. During an outage, the problems of one kind are written at most once in 30 seconds, with those in between still counted. The errors of the column *Error* are the ones of [the test of the connection](#when-the-test-of-the-connection-fails); for example:

| The error says | What failed |
| --- | --- |
| `cURL error 6: Could not resolve host` | The name of the host of the URL doesn't resolve on the Nextcloud server |
| `cURL error 7: Failed to connect` | Nothing accepts connections at the address and the port |
| `cURL error 28: Connection timed out` | No connection within 3 seconds, on the second try as well |
| `cURL error 28: Operation timed out` | No answer within 10 seconds: Paperless is busy, as while it consumes documents or rebuilds its index |
| `cURL error 60: SSL certificate problem` | Nextcloud doesn't trust the certificate: [import it](#certificates-of-your-own) |
| `Paperless returned HTTP 401` | The token is no longer valid: create one and enter it |

## The search is slow

A page of results waits for Paperless: the app connects within 3 seconds, waits up to 10 seconds for each answer, and goes through up to 4 pages of 25 documents of Paperless within 3 seconds to fill a page of results. Most of that time is Paperless searching its index; while it consumes many documents or rebuilds its index, it answers more slowly. A request that waited in vain for Paperless gets no second try, so a Paperless that hangs costs every search at most one wait.

Search in Paperless itself to compare: when it is slow there too, give Paperless more memory or a faster disk. When it is fast there, look at the network between the two servers, and at *Search problems*.

## Ask for help

When none of this helps, ask in the [discussions](https://github.com/Dennis-Otto/paperless-unified-search/discussions), or report a bug with the template of the [issues](https://github.com/Dennis-Otto/paperless-unified-search/issues/new/choose). Name the version of the app, of Nextcloud and of Paperless-ngx, whether the search runs with *Search connected services* or always, which step of [the six questions](#no-paperless-documents-in-the-search) failed, and the lines of *Search problems* and of the log of Nextcloud. Never post the API token, private addresses or the contents of documents.
