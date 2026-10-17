---
description: "Search Paperless documents in Nextcloud: in the browser, on a phone, in the smart picker of Talk, Text, Deck and Mail, with the search syntax of Paperless, and from scripts through the API, and see the newest ones on the dashboard."
---

# Search

Paperless documents show in the global search of Nextcloud for every user who can open their files: under **Paperless documents**, after the files, each with its title, its date and the words around the term. Selecting one opens its file in Nextcloud.

<figure markdown="span">
  ![Paperless results in Nextcloud's global search](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search.png#only-light)
  ![Paperless results in Nextcloud's global search](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search-dark.png#only-dark)
  <figcaption>Paperless documents in the search, after the files</figcaption>
</figure>

--8<-- "README.md:usage"

## Why the switch is off after a reload

**Search connected services** decides whether a search leaves Nextcloud. Nextcloud counts every search that sends the term to another server as one of a connected service, Paperless as well, and asks it only while the switch is on. It turns the switch off whenever the page loads anew, so that no term goes to another server without the choice of the user, again and again.

An administrator who trusts their Paperless server, as the one that already holds the documents, can select **Always include Paperless in global search** in the settings. Paperless then belongs to every search of every user, without the switch, which no longer controls it. Every user reloads Nextcloud once after the change.

## On a phone

- **In the browser** of a phone, the search works as on a computer, and a result opens in Nextcloud's viewer.
- **The Nextcloud apps for iOS and Android** show Paperless documents in their search as well. A result opens the file in the viewer of the app, without leaving it: the iOS app gets a link `nextcloud://open-file`, and the Android app the ID and the path of the file.

<figure markdown="span">
  ![Paperless results in the global search of Nextcloud on a phone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/04-mobile-search.png){ width="180" }
  ![The electricity invoice opened in Nextcloud's viewer on a phone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/05-mobile-viewer.png){ width="180" }
  <figcaption>On a phone, in the browser</figcaption>
</figure>

--8<-- "README.md:search-syntax"

--8<-- "README.md:scripts"
