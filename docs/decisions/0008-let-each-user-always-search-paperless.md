---
description: "Why each user can choose in their personal settings to include Paperless in every search of their own, which administrators can disallow, beside the trusted-service mode for every user."
---

# Let each user include Paperless in every search of their own

- Status: accepted
- Date: 2026-10-24

## Context

Nextcloud asks a search provider that names itself external, a connected service, only while the switch *Search connected services* is on, and it turns that switch off on every page that loads, so that no term goes to another server without the choice of the user. Paperless is such a provider: the terms go to the Paperless server, also when the user looks for a file or a contact. So the Paperless results disappeared on every reload until the user found the switch again, the most common friction of the app ([#220](https://github.com/Dennis-Otto/paperless-unified-search/issues/220)).

Until now, the only way out was the trusted-service mode of the administration settings, *Always include Paperless in global search*: all or nothing, and only an administrator could choose it. A household or a team could either send every term of every user to Paperless, or ask everyone to turn the switch on again and again; a user who wanted Paperless in their own searches couldn't consent for themselves alone.

Nextcloud asks the provider whether it is external as it lists the providers of the search for the user of the request, so the answer can differ by user.

## Options

1. Keep the trusted-service mode alone. Nothing to build, and the friction stays.
2. Make the provider never external. The switch would no longer control Paperless for anyone, and every term of every user would go to Paperless without anybody's choice.
3. A choice of each user in the personal settings, stored as a preference of that user, which the provider reads for the user of the request; with an option of the administration settings, on by default, that lets users choose it.

## Decision

Option 3. The section *Paperless* of the personal settings has the checkbox *Always search my Paperless documents*, whose hint says that the terms then go to the Paperless server of the Nextcloud. It saves as it changes, as the personal settings of Nextcloud do, and counts from the next page that loads. The provider is not external while the trusted-service mode is on, for everybody, or while the user of the request chose it and the administrators let users choose; without a user, as in `occ`, only the trusted-service mode counts.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="../images/diagrams/when-a-search-asks-paperless.dark.svg">
  <img src="../images/diagrams/when-a-search-asks-paperless.svg" alt="When a search asks Paperless: As a page of Nextcloud loads, its search asks the app whether Paperless is a connected service for the user who signed in. While an administrator includes Paperless in every search of every user, it is not, and every search asks Paperless. Otherwise, while the administrators let users choose, a user who selected Always search my Paperless documents in the personal settings has every search of their own ask Paperless. Everybody else finds Paperless documents only with the switch Search connected services, which Nextcloud turns off again on every page that loads.">
</picture>

The option *Let users include Paperless in all their searches* is on by default: choosing it is the user's own consent for their own terms, the same consent that the switch gives for one page, and Paperless is the server that the administrators connected and that already holds the documents. An administrator who wants no terms to go to Paperless without the switch turns it off; the personal settings then don't offer the choice, the route that stores it refuses it, and the search ignores the stored choices, which count again once the option is on. The section shows only while the search can show Paperless documents, with Paperless connected and an archive account, as the dashboard widget does.

## Consequences

A user who wants Paperless in every search chooses it once instead of on every page, and nobody else's searches change. The terms of that user go to Paperless with every search, which the hint of the checkbox says; [the security design](../security.md) and the reference name the preference `always_search` and the option `personal_always_search`. Administrators keep both ways: the trusted-service mode for everybody, and the option to keep users from choosing. The end-to-end tests check that a user's choice changes the providers of that user alone, that the administrators can disallow it, and that a user who chose it finds Paperless after a reload without the switch.
