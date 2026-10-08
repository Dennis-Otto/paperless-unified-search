---
hide:
  - navigation
  - toc
---

# Paperless Unified Search

A Nextcloud app that brings the OCR and full-text search of Paperless-ngx into Nextcloud's global search. A result opens the matching synchronized file directly in Nextcloud's viewer.

[Get it from the App Store](https://apps.nextcloud.com/apps/paperless_unified_search){ .md-button .md-button--primary }
[Quick start](#quick-start){ .md-button }

![Paperless documents found by their OCR text in Nextcloud's global search](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search.png)

## What it does

<div class="grid cards" markdown>

- :material-text-search:{ .lg .middle } **Paperless search in Nextcloud**

    ---

    Results come from the native OCR and full-text index of Paperless-ngx and appear under **Paperless documents** in Nextcloud's global search.

- :material-file-eye-outline:{ .lg .middle } **Opens the file in Nextcloud**

    ---

    A result opens the synchronized file in Nextcloud, not in Paperless: in the viewer of the browser, and in the viewers of the official iOS and Android apps.

- :material-account-lock-outline:{ .lg .middle } **Only files you may open**

    ---

    A result appears only if the searching user can open the matching file of the archive account: the archive account itself, or a user it shared the file with.

- :material-link-variant:{ .lg .middle } **Stable document markers**

    ---

    Documents are matched to files by the marker `[P<ID>]` in the file name, such as `[P123]`, which [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) writes. Documents without such a file stay out of the search.

- :material-toggle-switch-outline:{ .lg .middle } **Search terms under control**

    ---

    By default, search terms reach Paperless only when the user switches on **Search connected services**. Administrators can trust their Paperless server to include it in every search.

- :material-key-chain-variant:{ .lg .middle } **The token stays on the server**

    ---

    The Paperless API token is stored only in Nextcloud's server-side credentials manager and is never returned to browser JavaScript or rendered into HTML.

</div>

## Quick start

--8<-- "README.md:quick-start"

The [guide](guide.md) describes the configuration, the usage and the security and privacy of the app in detail.

## Learn more

<div class="grid cards" markdown>

- :material-book-open-variant:{ .lg .middle } **Guide**

    ---

    Installation, how the search works, requirements, configuration, usage, and security and privacy.

    [:octicons-arrow-right-24: Read the guide](guide.md)

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
