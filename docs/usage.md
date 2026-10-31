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

<figure markdown="span">
  ![The search for invoice with Search connected services switched on and the date filter set to the custom range from 7/1/2026 to 7/31/2026: Paperless documents lists the home insurance renewal of Jul 21, 2026 and the internet invoice of Jul 3, 2026, but not the electricity invoice of August, and Files finds none of the files, which were added later](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/12-date-filter.png#only-light)
  ![The search for invoice with Search connected services switched on and the date filter set to the custom range from 7/1/2026 to 7/31/2026: Paperless documents lists the home insurance renewal of Jul 21, 2026 and the internet invoice of Jul 3, 2026, but not the electricity invoice of August, and Files finds none of the files, which were added later](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/12-date-filter-dark.png#only-dark)
  <figcaption>The search narrowed to July 2026 with a custom range</figcaption>
</figure>

--8<-- "README.md:what-a-result-shows"

--8<-- "README.md:smart-picker"

<figure markdown="span">
  ![The smart picker in a note of Text: under Paperless documents, the search for invoice lists the electricity invoice, the home insurance renewal and the internet invoice](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker.png#only-light)
  ![The smart picker in a note of Text: under Paperless documents, the search for invoice lists the electricity invoice, the home insurance renewal and the internet invoice](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker-dark.png#only-dark)
  <figcaption>The smart picker in a note of Text</figcaption>
</figure>

<figure markdown="span">
  ![The note of Text after picking the electricity invoice: the inserted link shows the PDF of the invoice](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/08-smart-picker-link.png#only-light)
  ![The note of Text after picking the electricity invoice: the inserted link shows the PDF of the invoice](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/08-smart-picker-link-dark.png#only-dark)
  <figcaption>The link that it inserts shows the file</figcaption>
</figure>

--8<-- "README.md:dashboard"

<figure markdown="span">
  ![The dashboard of Jamie with the widget New in Paperless: the car service, the electricity invoice, the home insurance renewal, the internet invoice and the property tax notice, newest first, each with the icon of its PDF file and a line with the day that Paperless added it, its correspondent, its type and its tags, such as Sep 3, 2026 · Example Motors · Receipt · warranty](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/11-dashboard.png#only-light)
  ![The dashboard of Jamie with the widget New in Paperless: the car service, the electricity invoice, the home insurance renewal, the internet invoice and the property tax notice, newest first, each with the icon of its PDF file and a line with the day that Paperless added it, its correspondent, its type and its tags, such as Sep 3, 2026 · Example Motors · Receipt · warranty](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/11-dashboard-dark.png#only-dark)
  <figcaption>New in Paperless on the dashboard of Nextcloud</figcaption>
</figure>

## Why the switch is off after a reload

**Search connected services** decides whether a search leaves Nextcloud. Nextcloud counts every search that sends the term to another server as one of a connected service, Paperless as well, and asks it only while the switch is on. It turns the switch off whenever the page loads anew, so that no term goes to another server without the choice of the user, again and again.

There are two ways around it, and both send the terms to Paperless with every search:

- **For yourself:** select **Always search my Paperless documents** under **Personal settings → Paperless**, as *Always search your Paperless documents* above shows. Only your own searches change, from the next page that you open.
- **For every user:** an administrator who trusts their Paperless server, as the one that already holds the documents, can select **Always include Paperless in global search** in the settings. Paperless then belongs to every search of every user, without the switch, which no longer controls it, and the personal settings show the checkbox ticked and greyed out. Every user reloads Nextcloud once after the change.

## On a phone

- **In the browser** of a phone, the search works as on a computer, and a result opens in Nextcloud's viewer.
- **The Nextcloud apps for iOS and Android** show Paperless documents in their search as well. A result opens the file in the viewer of the app, without leaving it: the iOS app gets a link `nextcloud://open-file`, and the Android app the ID and the path of the file.

<figure markdown="span" class="phones">
  ![Paperless results in the global search of Nextcloud on a phone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/04-mobile-search.png){ width="180" }
  ![The electricity invoice opened in Nextcloud's viewer on a phone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/05-mobile-viewer.png){ width="180" }
  <figcaption>On a phone, in the browser</figcaption>
</figure>

--8<-- "README.md:search-syntax"

--8<-- "README.md:scripts"
