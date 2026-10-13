---
description: "How Paperless Unified Search asks Paperless-ngx from Nextcloud's unified search and shows only the documents a user may open, with no index of its own."
---

# Architecture

[← README](https://github.com/Dennis-Otto/paperless-unified-search) · [Security design](security.md) · [Roadmap](roadmap.md) · [Releases](releases.md)

Paperless Unified Search is a Nextcloud app in PHP. It adds a provider to Nextcloud's unified search that asks Paperless-ngx and shows the documents whose synchronized files the searching user can read. It stores no documents and no index of its own, and has no server, daemon or port of its own.

## Components

<div class="diagram-wide" markdown tabindex="0" role="region" aria-label="Diagram of the components">

```mermaid
---
config:
  flowchart:
    wrappingWidth: 400
    nodeSpacing: 30
---
flowchart TB
    accTitle: The components
    accDescr: Inside Nextcloud, the unified search calls the search provider of the app, and the administration settings call its settings page. The search provider uses the Paperless client, the file locator and the configuration; the settings page uses the Paperless client and the configuration. The Paperless client reaches /api/documents/ of Paperless-ngx through Nextcloud's HTTP client, the file locator looks into the folders of the users, and the configuration keeps its values in the app configuration and the token in the credentials manager.

    subgraph nextcloud ["Nextcloud"]
        search["Unified<br>search"]
        page["Administration<br>settings"]
        subgraph app ["Paperless Unified Search"]
            provider["Search<br>provider"]
            controller["Settings<br>page"]
            client["Paperless<br>client"]
            locator["File<br>locator"]
            config["Configuration"]
        end
        http["HTTP<br>client"]
        folders[("Folders<br>of the<br>users")]
        appconfig[("App<br>configuration")]
        credentials[("Credentials<br>manager")]
    end
    paperless[("Paperless-ngx<br>/api/documents/")]

    search --> provider
    page --> controller
    provider --> client
    provider --> locator
    provider --> config
    controller --> client
    controller --> config
    client --> http --> paperless
    locator --> folders
    config --> appconfig
    config --> credentials

    classDef app fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    class provider,controller,client,locator,config app
```

</div>

| Component | Files | What it does |
| --- | --- | --- |
| App | `lib/AppInfo/Application.php` | Registers the search provider with Nextcloud |
| Search provider | `lib/Search/PaperlessSearchProvider.php` | Answers a search of Nextcloud: asks Paperless, keeps the results with a readable file and builds each entry with its title, an excerpt and the link that opens the file in the browser, the iOS app or the Android app |
| Paperless client | `lib/Service/PaperlessApiService.php` | Sends the search term to the full-text search of Paperless through Nextcloud's HTTP client and checks the shape of the answer |
| File locator | `lib/Service/NextcloudFileLocator.php` | Finds the file of the archive account whose name carries the marker `[P<ID>]` of a document in the folders that the searching user can read |
| Configuration | `lib/Service/ConfigService.php`, `lib/Model/PublicConfig.php` | Checks and stores the URL of Paperless, the archive account and the switch *Always include Paperless in global search*; the API token goes to Nextcloud's credentials manager, is read from it once per request and is sent only to the server it was saved for; without an archive account of its own it takes the account of Paperless Sync, and it uses the ID of either as Nextcloud spells it |
| Settings page | `lib/Settings/`, `lib/Controller/SettingsController.php`, `templates/settings.php`, `js/settings.js` | The page under *Administration settings → Paperless Unified Search*, a section of its own: save after a test of the connection, and reset; Nextcloud lets only administrators call its routes and checks the CSRF token of every request |

## Data flow

<div class="diagram-wide" markdown tabindex="0" role="region" aria-label="Diagram of the data flow of a search">

```mermaid
---
config:
  sequence:
    wrap: true
    width: 120
    actorMargin: 10
---
sequenceDiagram
    accTitle: The data flow of a search
    accDescr: A user searches for invoice. The unified search passes the term, the page and the user to the search provider. Without a term, an archive account or a connection, the provider answers with no results and doesn't ask Paperless. Otherwise the Paperless client asks Paperless-ngx for at most 50 results with the token, and Paperless answers with documents 412, 389 and 371 and whether a next page exists. For every document, the file locator returns a file of the archive account with its marker, or none. The provider answers with an entry for every document with a file, and the next page.

    actor User
    participant Search as Unified search
    participant Provider as Search provider
    participant Client as Paperless client
    participant Paperless as Paperless-ngx
    participant Locator as File locator
    User->>Search: invoice
    Search->>Provider: term, page and user
    alt no term, no archive account or no connection
        Provider-->>Search: no results, without asking Paperless
    else
        Provider->>Client: term, page, at most 50 results
        Client->>Paperless: GET /api/documents/<br>?query=invoice,<br>with the token
        Paperless-->>Client: documents 412, 389, 371 and whether a next page exists
        loop every document
            Provider->>Locator: document 412, user, archive account
            Locator-->>Provider: a file of the archive account with [P412], or none
        end
        Provider-->>Search: an entry for every document with a file, and the next page
    end
```

</div>

1. A user searches in Nextcloud. Nextcloud asks the provider when the user has switched on *Search connected services*, or always when an administrator has marked Paperless as trusted.
2. The provider sends the term to the full-text search of Paperless, at most 50 results per page.
3. For every document of the answer, the file locator looks for a file of the archive account with the marker `[P<ID>]` in the folders of the searching user. A document without such a file is left out, and without an archive account Paperless isn't asked at all.
4. Each remaining document becomes an entry: its title, the date and an excerpt of the text that Paperless found, and the link that opens the file in Nextcloud. Further pages of Paperless become further pages of the search.
5. A request to Paperless that gets no answer at all, because the name of the host doesn't resolve or the connection fails or times out, is sent a second time. An answer of Paperless, whatever its status, is not. When the second try gets an answer, the client notes the first error in the history of the diagnostics.
6. A search that still fails returns no results, as an empty search does. The provider logs the kind of error and notes the failure in the history: the time, the step, asking Paperless or looking for the files, the kind and the message of the error, and how long it took. The first search that works afterwards notes when searches work again.

## Diagnostics

Users see no difference between a failed search and one without documents, and some hosters keep the log of Nextcloud from administrators. So the settings page shows the history of the problems of the search under *Search problems*.

```mermaid
---
config:
  flowchart:
    wrappingWidth: 400
    nodeSpacing: 30
---
flowchart TB
    accTitle: The history of the problems of the search
    accDescr: A second try that gets an answer notes a retried request, a failed search notes a failure, and the first search that works after a failure notes the recovery. The history keeps since when it counts, the counts, the last failure, the recovery and the latest 20 events, and the settings page shows it under Search problems. Disconnect and Clear history forget it.

    retry["A second try<br>answers"]
    search["A search<br>fails"]
    success["A search<br>works again"]
    history[("Diagnostics<br>since, counts, last failure,<br>recovery, latest 20 events")]
    page["Settings page<br>Search problems"]
    clear["Disconnect or<br>Clear history<br>forget it"]

    retry -- "retried" --> history
    search -- "failed" --> history
    success -- "recovery" --> history
    history --> page
    %% A plain dotted line keeps Clear history below the history. An arrow from it
    %% would rank it with the three causes, too wide for a phone, and Mermaid 11
    %% draws no arrowhead on "<-.-" and breaks on a label of a reversed edge.
    history -.- clear

    classDef yes fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    classDef no fill:#dc26262e,stroke:#dc2626,stroke-width:2px
    class success yes
    class search no
```

The history is one value of the app configuration of Nextcloud, loaded only when it is needed. It counts the failed searches and the requests that only their second try answered since it began, and keeps the latest 20 of these events, newest first, each with its time, its kind, its step, the kind and the message of the error and how long it took: for a failed search the whole search, for a second try the first request that got no answer. Only problems write to it, and a search that works writes only once after a failure, so searches that keep working write nothing. Two problems at the same moment may keep only one of them, which a history for diagnosis can afford.

The message loses the API token, the search term when it has at least three characters, cURL's pointer to the page of its error codes, and the query and the fragment of every URL, and is cut to 300 characters.

## Opening a result

The link of an entry depends on who searches, which the provider tells from the user agent of the request:

```mermaid
---
config:
  flowchart:
    wrappingWidth: 400
    nodeSpacing: 30
---
flowchart TB
    accTitle: The link of a result
    accDescr: The entry of document 412 carries the ID of its file and its path in the folders of the user. When the iOS app searches, its link is nextcloud://open-file with the user and the link /f/412. Otherwise its link is /f/412, which a browser opens in Nextcloud's viewer; the Android app opens the file in its own viewer by the ID and the path.

    entry["Entry of document 412,<br>with the ID and the path<br>of its file"] --> ios{"The iOS app<br>searches?"}
    ios -- "yes" --> deep["nextcloud://<br>open-file with the<br>user and /f/412"]
    ios -- "no" --> web["/f/412"]
    web -- "a browser" --> viewer["Nextcloud's<br>viewer"]
    web -- "the Android app" --> android["Its own viewer,<br>by the ID<br>and the path"]
```

Every entry carries the ID of the file and its path in the folders of the user as well; the Android app opens the file with them.

## Saving the settings

<div class="diagram-wide" markdown tabindex="0" role="region" aria-label="Diagram of saving the settings">

```mermaid
---
config:
  sequence:
    wrap: true
    width: 120
    actorMargin: 10
---
sequenceDiagram
    accTitle: Saving the settings
    accDescr: The administrator enters the URL, the token and the archive account. When the password wasn't confirmed in the last 30 minutes, Nextcloud asks for it first. The settings page sends them with the CSRF token to the settings controller, which checks the URL, the token and the archive account: a blank token keeps the stored one only for its server, the same scheme, host and port, and otherwise the answer is HTTP 400. Then the controller has the Paperless client test the connection with one request to Paperless-ngx. Only when Paperless answers does the configuration save them, the URL, the switch and the archive account in the app configuration and the token in the credentials manager. The page gets the settings back, without the token.

    actor Admin as Administrator
    participant Page as Settings page
    participant Controller as Settings controller
    participant Client as Paperless client
    participant Config as Configuration
    participant Paperless as Paperless-ngx
    Admin->>Page: URL, token, archive account
    opt the password wasn't confirmed in the last 30 minutes
        Page->>Admin: Nextcloud asks for the password
    end
    Page->>Controller: POST /apps/<br>paperless_unified_search<br>/settings, with the<br>CSRF token
    Controller->>Config: checks the URL, the token and the archive account
    Note over Controller,Config: a blank token keeps the stored one only for its server (the same scheme, host and port), else HTTP 400
    Controller->>Client: test the connection
    Client->>Paperless: GET /api/documents/<br>?page_size=1
    Paperless-->>Client: 200
    Controller->>Config: save
    Config->>Config: URL, switch and archive account to the app configuration, the token to the credentials manager
    Controller-->>Page: the settings, without the token
```

</div>

A token left blank keeps the stored one, but only for the server it was saved for ([decision 0003](decisions/0003-bind-the-token-to-its-server.md)); the page marks the token field as required as soon as the URL names another server. The archive account is stored as Nextcloud spells its ID. A stored token that the credentials manager can't decrypt counts as none, and the page says so. Saving and *Disconnect* ask for the password when it wasn't confirmed in the last 30 minutes. *Disconnect* deletes every setting, the token included, and the history of the diagnostics as well; saving keeps the history.

## Design decisions

- **Nextcloud decides who sees a file.** The app shows a document only through a file that the searching user can read in Nextcloud, and opens that file, not Paperless.
- **Paperless does the searching.** Its OCR and full-text index answer the search; the app keeps no index of its own.
- **The marker `[P<ID>]`** in the file name ties a file of the archive account to its document. [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) writes such files, and the two apps work together without depending on each other's releases.
- **The owner, not the name, decides.** Only files of the archive account count, because anyone can give a file the marker of a document ([decision 0002](decisions/0002-trust-the-archive-account.md)).
- **External by default.** Like every provider that sends search terms to another server, the app searches Paperless only when the user asks for it, unless an administrator marks Paperless as trusted.
