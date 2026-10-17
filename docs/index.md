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

Each picture follows the light or dark theme of this website. Select one to enlarge it.

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
  ![Paperless Unified Search administration settings in Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings.png#only-light)
  ![Paperless Unified Search administration settings in Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings-dark.png#only-dark)
  <figcaption>The settings, for administrators only</figcaption>
</figure>

<figure markdown="span">
  ![The history of the problems of the search in the settings](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/06-search-problems.png#only-light)
  ![The history of the problems of the search in the settings](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/06-search-problems-dark.png#only-dark)
  <figcaption>The problems of the search, without the log of Nextcloud</figcaption>
</figure>

<figure markdown="span">
  ![Paperless results in the global search of Nextcloud on a phone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/04-mobile-search.png){ width="180" }
  ![The electricity invoice opened in Nextcloud's viewer on a phone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/05-mobile-viewer.png){ width="180" }
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
