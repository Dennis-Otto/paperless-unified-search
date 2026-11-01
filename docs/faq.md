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

## Can I use the API token of my own Paperless account?

It works, but whoever gets hold of the token of an administrator of Paperless can change and delete every document, and the settings warn about it below the token. Give the search an account of its own that may only view documents, correspondents, document types, tags and its UI settings, as [Configure](configure.md#a-paperless-account-for-the-search) shows: the test of the connection then warns about nothing, and the account still finds every document that you allow it to view.

## Does it work in the Nextcloud apps for iOS and Android?

Yes. The apps show Paperless documents in their search, and a result opens the file in the viewer of the app. In the browser of a phone, the search works as on a computer. [On a phone](usage.md#on-a-phone) shows it.

## Why does a result open in Nextcloud, and not in Paperless?

Because there, everyone who finds the document can open it: Nextcloud's shares decide who sees the file, and nobody needs an account in Paperless. The file opens in Nextcloud's viewer and the mobile apps, ready to share, download or comment on, and the link of the smart picker shows the file to every reader who can open it.

## Why does the switch Search connected services turn itself off?

Nextcloud turns it off at every reload of the page, so that a search term never goes to another server without the choice of the user. To find your Paperless documents in every search without it, select **Always search my Paperless documents** under **Personal settings → Paperless**. [Why the switch is off after a reload](usage.md#why-the-switch-is-off-after-a-reload) has the details, and how an administrator can include Paperless in every search of every user instead.

## Can I see new documents without searching?

Yes: add **New in Paperless** to your dashboard. It lists the documents that Paperless added last and that you can open, newest first, each with the day that Paperless added it, its correspondent, its type and up to two of its tags, and opens a document's file in Files. [On the dashboard](usage.md#on-the-dashboard) has the details. Or let Nextcloud tell you: under **Personal settings → Paperless**, save searches such as `tag:unpaid` with [Notify me about new documents](usage.md#notifications-about-new-documents), or ask for every new document that you can open.

## Why did I get no notification about a new document?

A notification goes out only once the file of the document has reached the archive account and you can open it, so it comes with your synchronization. Each saved search looks at a document once, as its file arrives: a tag that it gets later brings none. Background jobs must run, as *Administration settings → Basic settings* shows, and the app Notifications of Nextcloud must be enabled. The first run after someone first asks only learns how far the IDs go, so a document of before then brings none either.

## Can I find the documents similar to one?

Yes, with the marker of its file, as Paperless does with **More like this**: search for `like:[P412]` in the global search, in the smart picker or through the API, and you see the documents most similar to that one whose files you can open, such as the other letters of a case. [Search like in Paperless](usage.md#search-like-in-paperless) has the details, and the fields of Paperless's search that users ask for most.

## How much load does it put on Paperless?

The dashboard widget asks Paperless for its newest documents at most once a minute, for all users together, given a memory cache, as Nextcloud recommends. Beyond that, Paperless gets requests only from the searches that ask it: with **Search connected services** switched on, from the smart picker or the API, from every search of a user who chose **Always search my Paperless documents**, and from every search when **Always include Paperless in global search** is on. Nextcloud's search waits until the typing pauses before it asks. For each page of results, the app asks Paperless for 25 documents, and only for the fields that a result shows, not the OCR text of each document; to fill a page whose first documents the user can't open, it asks for up to 3 more pages, within 3 seconds. Paperless 3 gets a query in its syntax that it can't parse yet, as one still being typed, once more in its simple mode. A request that couldn't connect gets a second try; one that waited in vain for a Paperless that hangs isn't sent again. While someone asks for notifications about new documents, a background job asks once every five minutes which documents are new, in a short request, and only for a document whose file has arrived once per distinct saved search.

## Does it search the documents in the inbox of Paperless?

Only those that have a file with their marker in the archive. A synchronization that writes only the documents out of the inbox keeps the others out of Nextcloud's search.

## Can it search more than one Paperless?

No. The settings hold one Paperless, for all users of the Nextcloud. Its account and the shares of the archive decide what each user finds.

## Which languages does it speak?

English, German, French, Spanish and Dutch, as the language of the account says: German with *du* or with *Sie*, French with *vous*, Spanish with *tú* and Dutch with *je*, and English in every other language. The search finds documents in every language that Paperless recognizes, since Paperless searches their text. [Languages](configure.md#languages) has the details, and [Translate](translate.md) shows how to correct a translation or add a language.

## Which versions of Nextcloud and Paperless does it support?

Nextcloud 33 to 35, and Paperless-ngx with its REST API. [Compatibility](reference.md#compatibility) says which versions the tests cover.

## Where do I ask a question, or report a bug?

In the [discussions](https://github.com/Dennis-Otto/paperless-unified-search/discussions), or with the template of the [issues](https://github.com/Dennis-Otto/paperless-unified-search/issues/new/choose). [Troubleshooting](troubleshooting.md) helps first, and says what a report needs.
