---
description: "Questions about Paperless Unified Search answered: without Paperless Sync, what it stores, who sees which document, the mobile apps, why results open in Nextcloud, the load on Paperless and the languages."
---

# FAQ

## Does it work without Paperless Sync?

Yes. The search needs each document of Paperless as a file of the archive account with the marker `[P<ID>]` in its name, and any synchronization that writes such files works: the file names of Paperless itself with `{{ doc_pk }}`, rclone, the desktop client of Nextcloud or an external storage. [Works with any synchronization](configure.md#works-with-any-synchronization) shows each of them. [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) is the companion app that does it inside Nextcloud, with folders by correspondent, type and year.

## Does it need Elasticsearch, or the app Full text search?

No. Paperless has already recognized the text of every document and keeps an index of its words; the app asks that index in every search. Nothing needs to be installed beside the app, no second index to build or keep current, and no second OCR. [Compare](compare.md) sets it beside the apps that do keep an index.

## Does it store my documents, their text or an index?

No. The app stores its settings, the API token, encrypted in Nextcloud's credentials manager, the version of Paperless, and the history of the latest 20 problems of the search, without the search terms and the token. The documents stay in Paperless and their files where the synchronization writes them. [Data flows](reference.md#data-flows) lists what goes where.

## Who sees which document?

A user sees a Paperless document when they can open its file in Nextcloud: the archive account sees every one, any other user those whose files, or a folder around them, the archive account shared with them: the shares that you already know are the permissions. The Paperless account of the API token sets the limit: a document that it may not view never shows to anyone. Nobody needs an account in Paperless.

## Do administrators of Nextcloud see every document?

No, only those whose files they can open, as any user. Being an administrator opens no file of another account.

## Do the search terms go to Paperless?

Only when the user asks for it: with **Search connected services** switched on, after choosing **Paperless documents** in the smart picker, or when a script asks for this search through the API. An administrator can also trust Paperless with **Always include Paperless in global search**; then every term of every search goes to Paperless. The terms travel from the Nextcloud server to Paperless, never from the browser, and the app logs none of them.

## Does it work in the Nextcloud apps for iOS and Android?

Yes. The apps show Paperless documents in their search, and a result opens the file in the viewer of the app. In the browser of a phone, the search works as on a computer. [On a phone](usage.md#on-a-phone) shows it.

## Why does a result open in Nextcloud, and not in Paperless?

Because there, everyone who finds the document can open it: Nextcloud's shares decide who sees the file, and nobody needs an account in Paperless. The file opens in Nextcloud's viewer and the mobile apps, ready to share, download or comment on, and the link of the smart picker shows the file to every reader who can open it.

## Why does the switch Search connected services turn itself off?

Nextcloud turns it off at every reload of the page, so that a search term never goes to another server without the choice of the user. [Why the switch is off after a reload](usage.md#why-the-switch-is-off-after-a-reload) has the details, and how an administrator can include Paperless in every search instead.

## How much load does it put on Paperless?

One request for each search, while the switch is on: Nextcloud's search waits until the typing pauses before it asks. A request asks for 25 documents and only for the fields that a result shows, not the OCR text of each document. To fill a page of results whose first documents the user can't open, the app asks for up to 3 more pages, within 3 seconds. A request that waited in vain for a Paperless that hangs isn't sent again. Without the switch, Paperless gets no request at all.

## Does it search the documents in the inbox of Paperless?

Only those that have a file with their marker in the archive. A synchronization that writes only the documents out of the inbox keeps the others out of Nextcloud's search.

## Can it search more than one Paperless?

No. The settings hold one Paperless, for all users of the Nextcloud. Its account and the shares of the archive decide what each user finds.

## Which languages does it speak?

The settings speak English and German, German with *du* or with *Sie*, as the language of the account says. The search finds documents in every language that Paperless recognizes, since Paperless searches their text. [Languages](configure.md#languages) has the details.

## Which versions of Nextcloud and Paperless does it support?

Nextcloud 33 to 35, and Paperless-ngx with its REST API. [Compatibility](reference.md#compatibility) says which versions the tests cover.

## Where do I ask a question, or report a bug?

In the [discussions](https://github.com/Dennis-Otto/paperless-unified-search/discussions), or with the template of the [issues](https://github.com/Dennis-Otto/paperless-unified-search/issues/new/choose). [Troubleshooting](troubleshooting.md) helps first, and says what a report needs.
