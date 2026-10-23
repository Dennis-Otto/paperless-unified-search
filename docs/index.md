---
title: Paperless-ngx full-text search for Nextcloud
description: "Search the OCR full text of Paperless-ngx in Nextcloud's unified search, without Elasticsearch or a second index, and open each result in Nextcloud's viewer."
hide:
  - navigation
  - toc
---

<!-- The title of the front matter is the one that search engines and the browser show; the heading is the name of the app. -->
<!-- markdownlint-disable-next-line MD025 -->
# Paperless Unified Search

A Nextcloud app that brings the OCR and full-text search of Paperless-ngx into Nextcloud's global search, without Elasticsearch, a second index or another container: Paperless answers, Nextcloud decides who sees the file. A result opens the matching synchronized file directly in Nextcloud's viewer.

[Get it from the App Store](https://apps.nextcloud.com/apps/paperless_unified_search){ .md-button .md-button--primary }
[Quick start](#quick-start){ .md-button }

![A search for "invoice" in Nextcloud: the files whose names match, then, with Search connected services switched on, the Paperless documents whose text matches. The home insurance renewal opens in Nextcloud's viewer.](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/search-and-open.gif)

The search of files finds the two invoices by their names. Paperless also finds the home insurance renewal: its text says "invoice", its name doesn't.

--8<-- "README.md:two-apps"

Paperless Sync is the companion, not a must: [any synchronization](configure.md#works-with-any-synchronization) that writes the files with their marker into the archive account works as well, Paperless-ngx's own file names included.

## What it does

<div class="grid cards" markdown>

- :material-text-search:{ .lg .middle } **Paperless search in Nextcloud**

    ---

    Results come from the native OCR and full-text index of Paperless-ngx and appear under **Paperless documents** in Nextcloud's global search.

- :material-file-eye-outline:{ .lg .middle } **Opens the file in Nextcloud**

    ---

    A result opens the synchronized file in Nextcloud, not in Paperless: in the viewer of the browser, and in the viewers of the official iOS and Android apps.

- :material-link-plus:{ .lg .middle } **Links in Talk, Text, Deck and Mail**

    ---

    Nextcloud's smart picker finds Paperless documents wherever you write and inserts the link to the file, whose preview only readers who can open the file see.

- :material-account-lock-outline:{ .lg .middle } **Only files you may open**

    ---

    A result appears only if the searching user can open the matching file of the archive account: the archive account itself, or a user it shared the file with.

- :material-link-variant:{ .lg .middle } **Stable document markers**

    ---

    Documents are matched to files by the marker `[P<ID>]` in the file name, such as `[P123]`, which [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) writes. Documents without such a file stay out of the search.

- :material-toggle-switch-outline:{ .lg .middle } **Search terms under control**

    ---

    By default, search terms reach Paperless only when the user switches on **Search connected services**, picks **Paperless documents** in the smart picker or asks the search API for Paperless documents. Administrators can trust their Paperless server to include it in every search.

- :material-console:{ .lg .middle } **Set up and explained by occ**

    ---

    `occ` sets the app up for Ansible and Docker, tests it for monitoring, and says why a user doesn't see a document; the overview of the administration settings warns when the search can't show any. [How](reference.md#set-up-and-check-it-with-occ)

- :material-key-chain-variant:{ .lg .middle } **The token stays on the server**

    ---

    The Paperless API token is stored only in Nextcloud's server-side credentials manager and is never returned to browser JavaScript or rendered into HTML.

</div>

## How it compares

--8<-- "README.md:comparison"

[Compare it in detail](compare.md){ .md-button }

## Screenshots

Each picture follows the light or dark theme of this website. Select one to enlarge it. A script takes them from a demo of the app, and a bot takes them again whenever a change of the app changes what they show, so they show the current version.

<div class="grid" markdown>

<figure markdown="span">
  ![Paperless results in Nextcloud's global search](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search.png#only-light)
  ![Paperless results in Nextcloud's global search](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search-dark.png#only-dark)
  <figcaption>Paperless results in Nextcloud's global search</figcaption>
</figure>

<figure markdown="span">
  ![A synchronized Paperless document opened in Nextcloud's PDF viewer](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/02-nextcloud-pdf-viewer.png#only-light)
  ![A synchronized Paperless document opened in Nextcloud's PDF viewer](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/02-nextcloud-pdf-viewer-dark.png#only-dark)
  <figcaption>A result opened in Nextcloud's viewer</figcaption>
</figure>

<figure markdown="span">
  ![The search for invoice with Search connected services switched on and the date filter set to the custom range from 7/1/2026 to 7/31/2026: Paperless documents lists the home insurance renewal of Jul 21, 2026 and the internet invoice of Jul 3, 2026, but not the electricity invoice of August, and Files finds none of the files, which were added later](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/12-date-filter.png#only-light)
  ![The search for invoice with Search connected services switched on and the date filter set to the custom range from 7/1/2026 to 7/31/2026: Paperless documents lists the home insurance renewal of Jul 21, 2026 and the internet invoice of Jul 3, 2026, but not the electricity invoice of August, and Files finds none of the files, which were added later](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/12-date-filter-dark.png#only-dark)
  <figcaption>Paperless results narrowed by date</figcaption>
</figure>

<figure markdown="span">
  ![The smart picker in a note of Text: under Paperless documents, the search for invoice lists the electricity invoice, the home insurance renewal and the internet invoice](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker.png#only-light)
  ![The smart picker in a note of Text: under Paperless documents, the search for invoice lists the electricity invoice, the home insurance renewal and the internet invoice](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker-dark.png#only-dark)
  <figcaption>Paperless documents in the smart picker of Text</figcaption>
</figure>

<figure markdown="span">
  ![The dashboard of Jamie with the widget New in Paperless: the car service, the electricity invoice, the home insurance renewal, the internet invoice and the property tax notice, newest first, each with the icon of its PDF file and a line with the day that Paperless added it, its correspondent and its type, such as Sep 3, 2026 · Example Motors · Receipt](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/11-dashboard.png#only-light)
  ![The dashboard of Jamie with the widget New in Paperless: the car service, the electricity invoice, the home insurance renewal, the internet invoice and the property tax notice, newest first, each with the icon of its PDF file and a line with the day that Paperless added it, its correspondent and its type, such as Sep 3, 2026 · Example Motors · Receipt](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/11-dashboard-dark.png#only-dark)
  <figcaption>The newest documents on the dashboard</figcaption>
</figure>

<figure markdown="span">
  ![Paperless Unified Search administration settings in Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings.png#only-light)
  ![Paperless Unified Search administration settings in Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings-dark.png#only-dark)
  <figcaption>The settings, for administrators only</figcaption>
</figure>

<figure markdown="span">
  ![The tab Sharing of the folder Paperless of the archive account in Files: the folder is shared with Jamie Example, View only](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-share-read-only.png#only-light)
  ![The tab Sharing of the folder Paperless of the archive account in Files: the folder is shared with Jamie Example, View only](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-share-read-only-dark.png#only-dark)
  <figcaption>The archive shared read-only with a user</figcaption>
</figure>

<figure markdown="span">
  ![The history of the problems of the search in the settings](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/06-search-problems.png#only-light)
  ![The history of the problems of the search in the settings](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/06-search-problems-dark.png#only-dark)
  <figcaption>The problems of the search, without the log of Nextcloud</figcaption>
</figure>

<figure markdown="span">
  ![Try a search in the settings: the administrator searches for invoice as the account e2e-admin, and a table lists the three documents that Paperless ranks first with their IDs and titles, the electricity invoice shown, the insurance renewal and the internet invoice not shared with that account, above the reasons why a document does not show](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-try-a-search.png#only-light)
  ![Try a search in the settings: the administrator searches for invoice as the account e2e-admin, and a table lists the three documents that Paperless ranks first with their IDs and titles, the electricity invoice shown, the insurance renewal and the internet invoice not shared with that account, above the reasons why a document does not show](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-try-a-search-dark.png#only-dark)
  <figcaption>Try a search: why a document doesn't show to a user</figcaption>
</figure>

<figure markdown="span" class="phones">
  ![Paperless results in the global search of Nextcloud on a phone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/04-mobile-search.png){ width="120" }
  ![The electricity invoice opened in Nextcloud's viewer on a phone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/05-mobile-viewer.png){ width="120" }
  ![The settings on a phone at the problems of the search: the counts, and below them the latest problems as cards, each with the time, the event, the step, the error and how long it took](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/09-admin-settings-mobile.png){ width="120" }
  <figcaption>On a phone, in the browser</figcaption>
</figure>

</div>

## Quick start

--8<-- "README.md:quick-start"

To see it at work first, [the demo](install.md#try-it-in-three-minutes) starts a real Paperless-ngx and a real Nextcloud with the app in Docker on your machine, in one command. [Install](install.md) and [Configure](configure.md) describe each step in detail, [Troubleshooting](troubleshooting.md) helps when no Paperless documents show, and the [FAQ](faq.md) answers the common questions.

## Learn more

<div class="grid cards" markdown>

- :material-download-outline:{ .lg .middle } **Get started**

    ---

    What the app needs, its installation from the App Store or a signed release, the Paperless account and its token, the settings, the local network, and the archive shared read-only.

    [:octicons-arrow-right-24: Install](install.md) · [Configure](configure.md)

- :material-magnify:{ .lg .middle } **Search**

    ---

    The search in the browser and on a phone, the smart picker of Talk, Text, Deck and Mail, the search syntax of Paperless, and the API for scripts and AI assistants.

    [:octicons-arrow-right-24: Search](usage.md)

- :material-lifebuoy:{ .lg .middle } **Troubleshooting and FAQ**

    ---

    Why Paperless documents don't show, step by step, every message of the test of the connection, certificates, proxies, the index of Paperless, and the common questions.

    [:octicons-arrow-right-24: Troubleshooting](troubleshooting.md) · [FAQ](faq.md)

- :material-book-open-variant:{ .lg .middle } **Reference**

    ---

    Every setting and its key, occ, the routes, the versions that the tests cover, the data flows, the log, and how to uninstall the app.

    [:octicons-arrow-right-24: Reference](reference.md)

- :material-compare-horizontal:{ .lg .middle } **Compare**

    ---

    How the app compares with Full text search and Elasticsearch, Workflow OCR, the Paperless integration, Context Chat, Findling and the web UI of Paperless, and when another one is the better choice.

    [:octicons-arrow-right-24: Compare](compare.md)

- :material-sitemap-outline:{ .lg .middle } **Architecture**

    ---

    How the app adds a provider to Nextcloud's unified search that asks Paperless-ngx, without storing documents or an index of its own.

    [:octicons-arrow-right-24: Architecture](architecture.md)

- :material-shield-lock-outline:{ .lg .middle } **Security design**

    ---

    What the app protects, what it trusts, the threats with their countermeasures, and the risks that remain.

    [:octicons-arrow-right-24: Security design](security.md)

- :material-map-marker-path:{ .lg .middle } **Roadmap**

    ---

    What Paperless Unified Search intends to do in the next twelve months, and what it will not do.

    [:octicons-arrow-right-24: Roadmap](roadmap.md)

- :material-scale-balance:{ .lg .middle } **Decisions**

    ---

    The decisions that shape the project, each with its reasons.

    [:octicons-arrow-right-24: Decisions](decisions/README.md)

- :material-history:{ .lg .middle } **Releases and changelog**

    ---

    How a release is made, signed and published in the Nextcloud App Store, and the changes of each version.

    [:octicons-arrow-right-24: Releases](releases.md) · [Changelog](changelog.md)

</div>
