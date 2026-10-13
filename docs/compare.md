---
description: "How Paperless Unified Search compares with Full text search and Elasticsearch, Workflow OCR, the Paperless integration, Context Chat, Findling and the web UI of Paperless-ngx, and when another one is the better choice."
---

# Compare

[← README](https://github.com/Dennis-Otto/paperless-unified-search) · [Guide](guide.md) · [Architecture](architecture.md) · [Roadmap](roadmap.md)

Paperless-ngx recognizes the text of every document and keeps an index of its words. Two ways bring that into Nextcloud's search: ask the index of Paperless, as Paperless Unified Search does, or let Nextcloud build a second index of the synchronized files. This page compares the two, and every app that does one of them, as of October 2026, after the descriptions of the apps in the Nextcloud App Store and their documentation. A correction is welcome as an [issue](https://github.com/Dennis-Otto/paperless-unified-search/issues).

## One index or two

```mermaid
---
config:
  flowchart:
    wrappingWidth: 400
    nodeSpacing: 30
---
flowchart TB
    accTitle: One index or two
    accDescr: With Paperless Unified Search there is one index. Nextcloud's search asks Paperless-ngx, whose OCR and full-text index already holds the words of every document, and each document that matches opens its synchronized file in Nextcloud. With a search of its own in Nextcloud, such as Full text search with Elasticsearch, Context Chat or Findling, there are two. The synchronized files are read again, scans are recognized again, and their words are stored a second time, in an index with a server or a container of its own, which Nextcloud's search asks instead.

    subgraph one ["Paperless Unified Search: one index"]
        direction TB
        search1(["Nextcloud's search"])
        paperless1[("Paperless-ngx<br>OCR and full-text index")]
        files1["The synchronized files,<br>opened in Nextcloud"]
        search1 -- "asks" --> paperless1
        paperless1 -- "the documents<br>that match" --> files1
    end
    subgraph two ["A search of its own: two indexes"]
        direction TB
        paperless2[("Paperless-ngx<br>OCR and full-text index")]
        files2["The synchronized files"]
        index2[("A second index,<br>in a server or a container<br>of its own")]
        search2(["Nextcloud's search"])
        paperless2 -- "synchronized" --> files2
        files2 -- "read again,<br>scans recognized again" --> index2
        search2 -- "asks" --> index2
    end
    one ~~~ two

    classDef app fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    classDef yes fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    classDef no fill:#dc26262e,stroke:#dc2626,stroke-width:2px
    class search1,search2 app
    class one yes
    class index2 no
```

One index means nothing more to run, back up or keep current, no second copy of the text of the documents, and no delay: a document is found in Nextcloud as soon as Paperless has it and its file has arrived. It also means the search of Paperless itself, with its ranking, its highlights and its [syntax](guide.md#search-like-in-paperless), which knows the correspondent, the type, the tags and the dates of a document, not only its words.

## At a glance

--8<-- "README.md:comparison"

## The alternatives one by one

### Full text search with Elasticsearch

Nextcloud's apps [Full text search](https://apps.nextcloud.com/apps/fulltextsearch), [Full text search - Files](https://apps.nextcloud.com/apps/files_fulltextsearch) and [Full text search - Elasticsearch Platform](https://apps.nextcloud.com/apps/fulltextsearch_elasticsearch) index the content of every file in an Elasticsearch server and add it to Nextcloud's search. They find words in all files of Nextcloud, not only in the documents of Paperless, and respect the shares of the files.

They need an Elasticsearch server, with its own memory, updates and backups, and its index keeps a second copy of the text of every document. A scan is found only by its text layer: the archive PDFs of Paperless have one, an original scan has none, and the [Tesseract app](https://apps.nextcloud.com/apps/files_fulltextsearch_tesseract), which recognizes such text for the index, supports Nextcloud 32 at most. What Paperless knows beyond the text, such as the correspondent, the type and the tags, isn't in that index.

### Workflow OCR

[Workflow OCR](https://apps.nextcloud.com/apps/workflow_ocr) recognizes the text of PDFs and images in Nextcloud with OCRmyPDF, installed on the server or as its backend container, by a rule of Nextcloud's flows, and saves it in the file as a text layer. It has no search of its own: another one, such as Full text search, finds that text. For the documents of Paperless, it repeats the recognition that Paperless has already done.

### The Paperless integration

The app [Paperless](https://apps.nextcloud.com/apps/integration_paperless) of Nextcloud adds an action to Files that uploads a file to Paperless. It has no search: [its issue #10](https://github.com/nextcloud/integration_paperless/issues/10) asks for exactly what Paperless Unified Search does. Its latest release supports Nextcloud 28 to 32. [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) also uploads files to Paperless, from an inbox folder in Nextcloud.

### Context Chat

[Context Chat](https://apps.nextcloud.com/apps/context_chat) lets Nextcloud's Assistant answer questions about the documents of a user with a language model, from a vector index of their text. It answers questions rather than listing documents, and understands paraphrases. It needs AppAPI with a deploy daemon, the Context Chat Backend container, the Assistant and a provider of a language model, on the server or a remote service. It reads the text layer of a PDF and recognizes no scans.

### Findling

[Findling](https://apps.nextcloud.com/apps/findling) adds a full-text and a semantic search of all files to Nextcloud's search, with an OCR of its own for scans and images, and checks the permissions of every result. It needs AppAPI and its backend container, whose index keeps the text of every file. The documents of Paperless are recognized and indexed a second time there.

### The web UI of Paperless-ngx

The web UI of Paperless has the same search and much more: tags, notes, workflows and editing. Who sees a document there is decided by the accounts and permissions of Paperless, not by the shares of Nextcloud, so every reader needs an account in Paperless, and a document opens in Paperless, not in Nextcloud or its mobile apps.

## When to choose which

- **Paperless Unified Search**, when Paperless holds the documents and people should find them where they already work, in Nextcloud, with the shares of Nextcloud deciding who sees what and nothing more to run.
- **Findling or Context Chat**, for a semantic search that finds paraphrases, or for files that never pass through Paperless, where AppAPI and a backend container are welcome.
- **Full text search with Elasticsearch**, to search the content of every file of Nextcloud, where an Elasticsearch server runs anyway.
- **Workflow OCR**, together with a search for their text, to make scans searchable that land in Nextcloud and never reach Paperless.
- **The Paperless integration or Paperless Sync**, to send files from Nextcloud to Paperless.
- **The web UI of Paperless**, to work with the documents: to tag, edit, annotate and automate them. Paperless Unified Search finds them; Paperless manages them.

## Use them together

Paperless Unified Search adds one more provider to Nextcloud's search, **Paperless documents**, and gets along with the others: with Findling or Full text search for the files that aren't in Paperless, and with the web UI of Paperless for its administration. The [guide](guide.md#works-with-any-synchronization) shows how any synchronization brings the files of Paperless into Nextcloud.
