---
description: "The guide of Paperless Unified Search moved to pages of their own: install, configure, search, troubleshooting, FAQ and reference. This page leads to the part that you look for."
search:
  exclude: true
---

# Guide

The guide of one page is now a set of pages, each for one task. A link to a part of the guide leads to its new place on its own; the table says where each part went.

| Part of the guide | Now in |
| --- | --- |
| Quick start | [Home](index.md#quick-start) |
| Requirements | [Install](install.md#what-it-needs) |
| How it works | [Configure](configure.md#how-it-works) |
| Configuration | [Configure](configure.md#configuration) |
| Paperless in your local network | [Configure](configure.md#paperless-in-your-local-network) |
| When the test of the connection fails | [Troubleshooting](troubleshooting.md#when-the-test-of-the-connection-fails) |
| Languages | [Configure](configure.md#languages) |
| Works with any synchronization | [Configure](configure.md#works-with-any-synchronization) |
| Usage, and in Talk, Text, Deck and Mail | [Search](usage.md#usage) |
| Search like in Paperless | [Search](usage.md#search-like-in-paperless) |
| Use it from scripts and AI assistants | [Search](usage.md#use-it-from-scripts-and-ai-assistants) |
| Set up and check it with occ | [Reference](reference.md#set-up-and-check-it-with-occ) |
| Security and privacy | [Reference](reference.md#security-and-privacy) |

New are [Troubleshooting](troubleshooting.md), the [FAQ](faq.md) and the [Reference](reference.md) for administrators.

<script>
  // A link to a part of the guide, such as guide.html#usage in the App Store, opens
  // the page that has the part now.
  (() => {
    const pages = {
      "quick-start": "index.html",
      "requirements": "install.html#what-it-needs",
      "how-it-works": "configure.html",
      "configuration": "configure.html",
      "paperless-in-your-local-network": "configure.html",
      "when-the-test-of-the-connection-fails": "troubleshooting.html",
      "languages": "configure.html",
      "works-with-any-synchronization": "configure.html",
      "usage": "usage.html",
      "in-talk-text-deck-and-mail": "usage.html",
      "search-like-in-paperless": "usage.html",
      "use-it-from-scripts-and-ai-assistants": "usage.html",
      "set-up-and-check-it-with-occ": "reference.html",
      "security-and-privacy": "reference.html",
    }
    const part = decodeURIComponent(location.hash.slice(1))
    const page = Object.hasOwn(pages, part) ? pages[part] : undefined
    if (page) {
      location.replace(page.includes("#") ? page : `${page}#${part}`)
    }
  })()
</script>
