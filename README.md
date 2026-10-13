# Paperless Unified Search

[![Documentation](https://img.shields.io/badge/docs-website-blue)](https://dennis-otto.github.io/paperless-unified-search/)
[![CI](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/ci.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/ci.yml)
[![Docker E2E](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/e2e.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/e2e.yml)
[![Secret scan](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/secret-scan.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/secret-scan.yml)
[![CodeQL](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/codeql.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/codeql.yml)
[![SBOM](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/sbom.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/sbom.yml)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Dennis-Otto/paperless-unified-search/badge)](https://scorecard.dev/viewer/?uri=github.com/Dennis-Otto/paperless-unified-search)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects/14256/badge)](https://www.bestpractices.dev/projects/14256)
[![REUSE](https://api.reuse.software/badge/github.com/Dennis-Otto/paperless-unified-search)](https://api.reuse.software/info/github.com/Dennis-Otto/paperless-unified-search)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%99%A5-db61a2?logo=githubsponsors&logoColor=white)](https://github.com/sponsors/Dennis-Otto)

Paperless Unified Search brings Paperless-ngx OCR and full-text search into Nextcloud's global search, without Elasticsearch, a second index or another container: Paperless answers, Nextcloud decides who sees the file. Search results open the matching synchronized file directly in Nextcloud's viewer.

![A search for "invoice" in Nextcloud: the files whose names match, then, with Search connected services switched on, the Paperless documents whose text matches. The home insurance renewal opens in Nextcloud's viewer.](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/search-and-open.gif)

<sub>The search of files finds the two invoices by their names. Paperless also finds the home insurance renewal: its text says "invoice", its name doesn't.</sub>

<!-- --8<-- [start:two-apps] -->

Two apps, one archive: [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) writes every document of Paperless-ngx as a file of one Nextcloud account, the archive account, with the marker `[P<ID>]` in its name. Paperless Unified Search asks Paperless for the documents that match a search and shows each one to whoever can open its file.

```mermaid
---
config:
  flowchart:
    wrappingWidth: 400
    nodeSpacing: 30
---
flowchart TB
    accTitle: Two apps, one archive
    accDescr: Paperless Sync writes every document of Paperless-ngx as a file of the archive account paperless, with the marker of the document in its name, such as invoice [P412].pdf. The archive account shares its archive read-only with jamie. When jamie searches, Paperless Unified Search asks Paperless-ngx, finds the file of document 412 by its marker, and shows jamie the document because jamie can open that file; the result opens the file in Nextcloud.

    paperless[("Paperless-ngx<br>OCR and full-text index")]
    sync(["Paperless Sync"])
    search(["Paperless<br>Unified Search"])
    subgraph archive ["paperless, the archive account"]
        file["… invoice [P412].pdf"]
    end
    jamie(["jamie"])

    paperless -- "every document" --> sync
    paperless -- "the documents<br>that match" --> search
    sync -- "writes it as a file<br>with its marker" --> file
    search -. "finds the file<br>by its marker" .-> file
    file -- "shared read-only" --> jamie
    search -- "document 412,<br>opens its file" --> jamie

    classDef app fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    classDef person fill:#f59e0b2e,stroke:#f59e0b,stroke-width:2px
    class sync,search app
    class jamie,archive person
```

<!-- --8<-- [end:two-apps] -->

The [documentation website](https://dennis-otto.github.io/paperless-unified-search/) has this documentation as a guide, together with the architecture, the security design and the roadmap.

<sub>💛 If Paperless Unified Search is useful to you, you can [support its development](https://github.com/sponsors/Dennis-Otto).</sub>

[Quick start](#quick-start) · [Architecture](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/architecture.md) · [Security design](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/security.md) · [Roadmap](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/roadmap.md) · [Releases](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/releases.md) · [Changelog](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/CHANGELOG.md)

<!-- --8<-- [start:quick-start-section] -->

## Quick start

<!-- --8<-- [start:quick-start] -->

1. Install **Paperless Unified Search** from the [Nextcloud App Store](https://apps.nextcloud.com/apps/paperless_unified_search) under *Apps*, or with `occ app:install paperless_unified_search`.
2. In Paperless-ngx, create an account that may read the documents to search, and an API token for it.
3. In Nextcloud, open **Administration settings → Paperless Unified Search**, enter the Paperless URL and the token, and select **Test connection and save**. With [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) installed, the account it writes the archive with is the **Archive account**; otherwise enter the account that owns the synchronized files.
4. As the archive account, share the archive read-only with the users who may find its documents, as any folder in Nextcloud. The archive account itself finds them all.
5. Search in Nextcloud, switch on **Search connected services** and look under **Paperless documents**. A result appears for every document whose synchronized file you can open: a file of the archive account with `[P<ID>]` in its name, your own if you are that account, or one it shared with you. [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) creates such files.

```mermaid
---
config:
  flowchart:
    wrappingWidth: 400
    nodeSpacing: 30
---
flowchart TB
    accTitle: Setting up the search
    accDescr: Install the app. In Paperless-ngx, create an account that may read the documents and an API token for it. In the administration settings of Nextcloud, enter the URL, the token and the archive account, and select Test connection and save. When Paperless answers, the settings are saved; otherwise nothing is saved and the message says what to correct. The archive account shares its archive read-only with the users who may find its documents, and they find them under Paperless documents in Nextcloud's search, with Search connected services switched on.

    install["1 · Install the app"]
    token["2 · In Paperless-ngx: an account<br>that may read the documents,<br>and its API token"]
    save["3 · In the administration<br>settings: URL, token and<br>archive account, then<br><b>Test connection and save</b>"]
    answers{"Paperless<br>answers?"}
    fix["Nothing is saved;<br>the message says<br>what to correct"]
    share["4 · The archive account<br>shares its archive<br>read-only"]
    search["5 · Search with<br><b>Search connected<br>services</b> on"]
    found(["Paperless documents<br>in the results"])

    install --> token --> save --> answers
    answers -- "yes, saved" --> share --> search --> found
    answers -- "no" --> fix
    fix -.-> save

    classDef person fill:#f59e0b2e,stroke:#f59e0b,stroke-width:2px
    classDef done fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    classDef no fill:#dc26262e,stroke:#dc2626,stroke-width:2px
    class install,token,save,share,search person
    class found done
    class fix no
```

<!-- --8<-- [end:quick-start] -->

[Configuration](#configuration) and [Usage](#usage) have the details.

<!-- --8<-- [end:quick-start-section] -->

## Screenshots

Every picture but those of the phone has a dark version, which a dark theme shows.

### Paperless results in Nextcloud's global search

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search-dark.png">
  <img alt="Paperless OCR results in Nextcloud unified search" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search.png">
</picture>

### A search result opened in Nextcloud's PDF viewer

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/02-nextcloud-pdf-viewer-dark.png">
  <img alt="A synchronized Paperless document opened in Nextcloud's PDF viewer" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/02-nextcloud-pdf-viewer.png">
</picture>

### On a phone

<p>
  <img alt="Paperless results in the global search of Nextcloud on a phone" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/04-mobile-search.png" width="320">
  <img alt="The electricity invoice opened in Nextcloud's viewer on a phone" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/05-mobile-viewer.png" width="320">
</p>

Nextcloud in the browser of a phone. The Nextcloud apps for iOS and Android open a result in their own viewer.

### Paperless documents in the smart picker

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker-dark.png">
  <img alt="The smart picker in a note of Text: under Paperless documents, the search for invoice lists the electricity invoice, the home insurance renewal and the internet invoice" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker.png">
</picture>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/08-smart-picker-link-dark.png">
  <img alt="The note of Text after picking the electricity invoice: the inserted link shows the PDF of the invoice" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/08-smart-picker-link.png">
</picture>

Type `/` in a note of Text and choose *Paperless documents*: the picker finds the documents by their text and inserts the link to the file you pick, which Text shows as the file itself to everyone who can open it.

### Secure server-side administration

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings-dark.png">
  <img alt="Paperless Unified Search administration settings in Nextcloud" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings.png">
</picture>

### Problems of the search

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/06-search-problems-dark.png">
  <img alt="The history of the problems of the search in the settings: one failed search, two requests that only their second try answered, and the latest of them with their errors" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/06-search-problems.png">
</picture>

The end of the settings counts the failed searches and the requests that only their second try answered, and lists the latest of them with their errors, also where the hoster keeps the log of Nextcloud from administrators.

<!-- --8<-- [start:how-it-works] -->

## How it works

```mermaid
---
config:
  flowchart:
    wrappingWidth: 400
    nodeSpacing: 30
---
flowchart TB
    accTitle: How a search finds its documents
    accDescr: Nextcloud's search sends the term, such as invoice, to Paperless Unified Search, which searches the OCR text in Paperless-ngx. Paperless answers with the documents that match, such as document 412. The app looks for a file of the archive account with the marker [P412] in its name among the files that the searching user can open. With such a file, document 412 is a result that opens the file in Nextcloud; without one, it is left out.

    search(["Nextcloud's search"])
    app["Paperless Unified Search"]
    paperless[("Paperless-ngx")]
    file{"A file of the archive account<br>with [P412] in its name<br>that the user can open?"}
    result(["Document 412 in the results,<br>opens that file in Nextcloud"])
    left(["Left out"])

    search -- "invoice" --> app
    app -- "searches the OCR text" --> paperless
    paperless -- "document 412, …" --> file
    file -- "yes" --> result
    file -- "no" --> left

    classDef app fill:#526cfe2e,stroke:#526cfe,stroke-width:2px
    classDef yes fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    classDef no fill:#dc26262e,stroke:#dc2626,stroke-width:2px
    class app app
    class result yes
    class left no
```

1. Nextcloud forwards an enabled external-search query to Paperless-ngx.
2. Paperless returns results from its native OCR/full-text index, with only the fields that a result shows: not the whole OCR text of each document, only the excerpt around the term.
3. The app maps each Paperless document ID to a synchronized file of the archive account whose name contains the unique marker `[P<ID>]`, for example `[P123]`, with one search of Nextcloud's file cache for all documents of a page. The archive account is the one that owns the synchronized files: the account of Paperless Sync, or the one of the settings.
4. A result is returned only if the current Nextcloud user can access that file: the archive account itself, or a user it shared the file with. A file with the marker in its name that belongs to another account stands for no document.
5. Selecting a result opens the synchronized file in Nextcloud, not Paperless. Browsers use Nextcloud's
   `/f/{fileId}` viewer route. The official iOS app receives its native `nextcloud://open-file` deep link,
   while Android receives the file ID and user-relative path required by its in-app viewer.

Who sees document 412, when `paperless` is the archive account and has shared its archive with `jamie`:

```mermaid
---
config:
  flowchart:
    wrappingWidth: 400
    nodeSpacing: 30
---
flowchart TB
    accTitle: Who sees document 412
    accDescr: The archive account paperless owns the file invoice [P412].pdf and sees document 412. It shares the file read-only with jamie, who sees document 412 as well. sam has a file of sam's own named Copy [P412].pdf; it isn't a file of the archive account, so sam sees no document.

    subgraph owner ["paperless, the archive account"]
        own["… invoice [P412].pdf"]
    end
    subgraph reader ["jamie"]
        shared["… invoice [P412].pdf<br>shared read-only"]
    end
    subgraph other ["sam"]
        copy["Copy [P412].pdf<br>a file of sam's own"]
    end
    seen1(["✓ document 412"])
    seen2(["✓ document 412"])
    unseen(["✗ no document"])

    own -- "share" --> shared
    own --> seen1
    shared --> seen2
    seen2 ~~~ copy
    copy --> unseen

    classDef yes fill:#16a34a2e,stroke:#16a34a,stroke-width:2px
    classDef no fill:#dc26262e,stroke:#dc2626,stroke-width:2px
    class seen1,seen2 yes
    class unseen no
```

This app does not synchronize documents itself. For a native, configurable synchronization solution, use the companion [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) app. Both apps use the same stable `[P<ID>]` marker and are designed to work together without coupling their release cycles.

<!-- --8<-- [end:how-it-works] -->
<!-- --8<-- [start:requirements] -->

## Requirements

- Nextcloud 33 through 35
- PHP 8.2 or newer as supported by the corresponding Nextcloud release
- A reachable Paperless-ngx instance with API access
- Synchronized files whose names contain `[P<ID>]`, owned by one Nextcloud account, the archive account, and shared read-only with the users who may see them, for example those of Paperless Sync. Without them, the search shows no Paperless documents.

<!-- --8<-- [end:requirements] -->
<!-- --8<-- [start:configuration] -->

## Configuration

1. Create a dedicated Paperless account with the minimum read permissions required for document search.
2. Create an API token for that account.
3. In Nextcloud, open **Administration settings → Paperless Unified Search**.
4. Enter the Paperless base URL and API token.
5. Under **Archive account**, enter the Nextcloud account that owns the synchronized files, or leave it blank to use the account that Paperless Sync writes the archive with. The settings store the ID as Nextcloud spells it, whatever its case, and warn when the account doesn't exist.
6. Optionally enable **Always include Paperless in global search** to treat the configured Paperless server as trusted.
7. Select **Test connection and save**. Nextcloud asks for your password when you haven't confirmed it in the last 30 minutes, as for its own settings of this kind; so does **Disconnect**.

Leave the token blank to keep the stored one. The stored token goes only to the Paperless server it was saved for: when the URL names another server, with another scheme, host or port, the settings ask for the token again, so that the token never reaches a server it wasn't meant for. Another path on the same server keeps it. When Nextcloud can no longer decrypt the stored token, as after a move or a restore with another `secret` in `config.php`, the settings say so and ask for it again, and until then the search shows no Paperless documents.

The configuration is global. Access control remains user-specific because the app discards every Paperless hit for which the searching Nextcloud user can't open a matching file of the archive account. Share the archive read-only with the users who may see its documents. Without an archive account, the search shows no Paperless documents.

When Paperless documents are missing from the search, look at **Search problems** at the end of the settings. A failed search shows the users no Paperless documents and no error, and a request to Paperless that gets no answer at all, as when the connection fails, is sent a second time before the search gives up. The settings count both, the failed searches and the requests that only their second try answered, and list the latest 20 with the time, the step, asking Paperless or looking for the files in Nextcloud, the kind and the message of the error and how long it took, along with since when searches work again after the last failure. This helps where the hoster keeps the log of Nextcloud from administrators. **Clear history** and **Disconnect** forget the history; saving the settings keeps it.

By default, Nextcloud searches Paperless only after the user enables **Search connected services**, or picks **Paperless documents** in the smart picker. When the trusted-service option is enabled, every global search term from every Nextcloud user is sent to Paperless automatically and the connected-services switch no longer controls this provider. Reload Nextcloud after changing this option.

<!-- --8<-- [end:configuration] -->
<!-- --8<-- [start:usage] -->

## Usage

Open Nextcloud's global search, enable **Search connected services**, and select **Paperless documents**. Nextcloud 32 and later disable external providers after a page reload, so this switch must be enabled again unless an administrator has enabled trusted-service mode.

Documents without a synchronized `[P<ID>]` file of the archive account are intentionally omitted. This also keeps Inbox-only documents out of Nextcloud search when the synchronization process does not export them.

### In Talk, Text, Deck and Mail

Nextcloud's smart picker finds Paperless documents too, wherever you write: type `/` in Text or Collectives, or open the smart picker in a conversation of Talk, a card of Deck or a message of Mail. Choose **Paperless documents**, search, and pick a result: the picker inserts the link to its file. Everyone who can open that file sees its preview; anyone else sees only the link.

The picker searches through the same provider as Nextcloud's search, so it lists the same documents. It asks Paperless once you have chosen **Paperless documents** and typed a term, without the switch **Search connected services**: choosing it is the same consent.

<!-- --8<-- [end:usage] -->
<!-- --8<-- [start:security-and-privacy] -->

## Security and privacy

- The Paperless API token is stored only in Nextcloud's server-side credentials manager.
- The token is never returned to browser JavaScript or rendered into HTML.
- The token is sent only to the Paperless server it was saved for; a URL with another scheme, host or port needs it entered again.
- Saving the settings and disconnecting ask for the administrator's password when it wasn't confirmed in the last 30 minutes.
- Search results are filtered through the current user's Nextcloud filesystem view.
- The settings show administrators the history of the problems of the search without the API token, the search term or the query of any URL.
- Search terms are sent server-to-server only when connected-services search or trusted-service mode is enabled, or after the user chose **Paperless documents** in the smart picker.
- A link that the smart picker inserts is the link of the file in Nextcloud, which shows its preview only to readers who can open the file.
- Administrators can explicitly trust the configured Paperless server to include it automatically in every user's global searches.
- No deployment credentials, private hostnames, internal addresses, or instance configuration belong in this repository.
- Gitleaks scans every push and pull request.
- Dependency Review blocks newly introduced vulnerable or unapproved dependencies.
- Psalm analyzes the PHP code and CodeQL scans the JavaScript on every pull request.
- Every release includes an SPDX SBOM, a detached signature, and public Sigstore build provenance.
- OpenSSF Scorecard audits the repository's supply-chain security every week.

See [SECURITY.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/SECURITY.md) for reporting security issues.

<!-- --8<-- [end:security-and-privacy] -->

## Development

Install the dependencies and run every check of the CI; the dev container in `.devcontainer/` has the tools ready:

```bash
composer install
bash scripts/check.sh
```

`scripts/check.sh` checks Composer, `appinfo/info.xml` against the schema of the App Store, PHP syntax, the coding standard, Psalm, PHPUnit, the package that krankerl builds with `scripts/check-package.sh`, and, with `scripts/check-project.sh`, the JavaScript, the translations, the pictures of the README, a description of its own for every page of the website, and that every diagram runs from top to bottom with a title and a description for screen readers.

The production archive intentionally excludes tests, release tools, Composer development dependencies, screenshots, and repository metadata. Packaging uses [Krankerl](https://github.com/ChristophWurst/krankerl), and `scripts/check-package.sh` validates the finished archive.

### Docker end-to-end tests

The Docker suite starts a clean Nextcloud instance with the app mounted as a Custom App and a deterministic Paperless API mock. It exercises the real OCS search endpoint, user-specific file access, trusted-service metadata, the smart picker and the links it inserts, and browser/iOS/Android result contracts.

```bash
bash tests/e2e/run.sh       # one Nextcloud version
bash tests/e2e/run-all.sh   # every supported version at the same time, as the CI does
```

In the CI, the required check `nextcloud` runs the tests against every supported version in one job. A pull request that changes no file the tests cover, such as one that changes only the documentation, passes it in seconds without running them. Set `KEEP_E2E=1` to leave the containers running for inspection. See [the mobile test matrix](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/tests/e2e/MANUAL_MOBILE_TESTS.md) for the final checks performed with official clients.

### Screenshots

The screenshots and the animation in `screenshots/` come from a script, so that they show what users see. After a change of the interface, take them again:

```bash
bash scripts/screenshots.sh
```

It starts the Docker suite under a project of its own with a demo: the archive account `paperless`, whose synthetic documents are shared read-only with `jamie`, and a Paperless mock that finds three of them for "invoice". Chromium then searches, opens a result, links a document from the smart picker in a note of Text and saves the settings, light and dark, on a phone and as an animation. Set `KEEP_SCREENSHOTS=1` to look around in the demo afterwards.

See [CONTRIBUTING.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/CONTRIBUTING.md) before opening a pull request.

### Releases

The protected `main` branch requires the checks of the CI, the Docker end-to-end tests against every supported Nextcloud version, the dependency review, CodeQL, the secret scan, the licenses of every file (REUSE), the sign-off of every commit and a Conventional Commit title. Dependabot keeps the dependencies current; routine updates merge on their own once every check passes.

The release bot keeps a pull request for the next release. Its version follows from the titles of the merged pull requests, and what they wrote under *Unreleased* in `CHANGELOG.md` becomes its notes. Merging it publishes the release: the package, checked before and after signing with the app's certificate, its detached signature, an SPDX SBOM and signed build provenance, then the same package in the Nextcloud App Store, verified afterwards as users can verify it. See [the release guide](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/releases.md).

Project decisions and support expectations are documented in [GOVERNANCE.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/GOVERNANCE.md), [SUPPORT.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/SUPPORT.md), and [CODE_OF_CONDUCT.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/CODE_OF_CONDUCT.md).

## License

AGPL-3.0-or-later. See [LICENSE](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/LICENSE).
