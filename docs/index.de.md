---
title: Paperless-ngx-Volltextsuche für Nextcloud
description: "Den OCR-Volltext von Paperless-ngx in der globalen Suche von Nextcloud durchsuchen, ohne Elasticsearch oder zweiten Index, und jeden Treffer im Viewer von Nextcloud öffnen."
hide:
  - navigation
  - toc
---

<!-- The hero of the home page: the icon, the name, what the app does in one line and the two buttons, then the animation of the search, which the website plays as a video, light or dark as the website, and in its place the search on a phone on a narrow screen (stylesheets/extra.css, javascripts/hero.js). The title of the front matter is the one that search engines and the browser show; the heading is the name of the app. -->
<div class="pus-hero" markdown>

<img class="pus-hero__logo off-glb" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/img/app.svg" width="48" height="48" alt="">

<!-- markdownlint-disable-next-line MD025 -->
# Paperless Unified Search

<p class="pus-hero__tagline">Paperless-ngx-Dokumente an den Wörtern in ihnen finden, direkt in der Suche von Nextcloud.</p>

[Aus dem App Store holen](https://apps.nextcloud.com/apps/paperless_unified_search){ .md-button .md-button--primary }
[Schnellstart](#schnellstart){ .md-button }

</div>

<div class="pus-hero__media" markdown>
<div class="pus-hero__light" markdown="span">![Die Paperless-Dokumente, die eine Suche nach „invoice“ in Nextcloud findet, in einem Rahmen: die Stromrechnung, die Verlängerung der Hausratversicherung und die Internetrechnung. Dann die Suche von vorn: die Dateien, deren Namen passen, dann, mit eingeschaltetem Verbundene Dienste durchsuchen, die Paperless-Dokumente, deren Text passt. Die Verlängerung der Hausratversicherung öffnet sich im Viewer von Nextcloud.](images/search-and-open.gif)</div>
<div class="pus-hero__dark" markdown="span">![Die Paperless-Dokumente, die eine Suche nach „invoice“ in Nextcloud findet, in einem Rahmen: die Stromrechnung, die Verlängerung der Hausratversicherung und die Internetrechnung. Dann die Suche von vorn: die Dateien, deren Namen passen, dann, mit eingeschaltetem Verbundene Dienste durchsuchen, die Paperless-Dokumente, deren Text passt. Die Verlängerung der Hausratversicherung öffnet sich im Viewer von Nextcloud.](images/search-and-open-dark.gif)</div>
<div class="pus-hero__phone" markdown="span">![Paperless-Treffer in der globalen Suche von Nextcloud auf einem Smartphone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/04-mobile-search.png){ width="780" height="1688" loading="lazy" decoding="async" }</div>
</div>

<p class="pus-hero__caption">Die Suche nach Dateien findet die beiden Rechnungen an ihren Namen. Paperless findet auch die Verlängerung der Hausratversicherung: Ihr Text sagt „invoice“, ihr Name nicht.</p>

Eine Nextcloud-App, die die OCR- und Volltextsuche von Paperless-ngx in die globale Suche von Nextcloud bringt, ohne Elasticsearch, zweiten Index oder weiteren Container: Paperless antwortet, Nextcloud entscheidet, wer die Datei sieht. Ein Treffer öffnet die passende synchronisierte Datei direkt im Viewer von Nextcloud.

--8<-- "README.de.md:two-apps"

Paperless Sync ist der Begleiter, keine Pflicht: [Jede Synchronisierung](configure.de.md#funktioniert-mit-jeder-synchronisierung), die die Dateien mit ihrer Kennung in das Archiv-Konto schreibt, funktioniert ebenso, auch die eigenen Dateinamen von Paperless-ngx.

## Was sie kann

<div class="grid cards" markdown>

- :material-text-search:{ .lg .middle } **Paperless-Suche in Nextcloud**

    ---

    Die Treffer kommen aus dem eigenen OCR- und Volltextindex von Paperless-ngx und erscheinen unter **Paperless-Dokumente** in der globalen Suche von Nextcloud.

- :material-file-eye-outline:{ .lg .middle } **Öffnet die Datei in Nextcloud**

    ---

    Ein Treffer öffnet die synchronisierte Datei in Nextcloud, nicht in Paperless: im Viewer des Browsers und in den Viewern der offiziellen Apps für iOS und Android.

- :material-link-plus:{ .lg .middle } **Links in Talk, Text, Deck und Mail**

    ---

    Der Smart Picker von Nextcloud findet Paperless-Dokumente, wo immer du schreibst, und fügt den Link auf die Datei ein, deren Vorschau nur Leser sehen, die die Datei öffnen können.

- :material-account-lock-outline:{ .lg .middle } **Nur Dateien, die du öffnen darfst**

    ---

    Ein Treffer erscheint nur, wenn der suchende Benutzer die passende Datei des Archiv-Kontos öffnen kann: das Archiv-Konto selbst oder ein Benutzer, mit dem es die Datei geteilt hat.

- :material-link-variant:{ .lg .middle } **Stabile Kennungen der Dokumente**

    ---

    Dokumente werden den Dateien über die Kennung `[P<ID>]` im Dateinamen zugeordnet, etwa `[P123]`, die [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) schreibt. Dokumente ohne eine solche Datei bleiben aus der Suche heraus.

- :material-toggle-switch-outline:{ .lg .middle } **Suchbegriffe unter Kontrolle**

    ---

    Standardmäßig erreichen Suchbegriffe Paperless nur, wenn der Benutzer **Verbundene Dienste durchsuchen** einschaltet, im Smart Picker **Paperless-Dokumente** wählt oder die Such-API nach Paperless-Dokumenten fragt. Administratoren können ihrem Paperless-Server vertrauen, um ihn in jede Suche einzubeziehen.

- :material-console:{ .lg .middle } **Mit occ eingerichtet und erklärt**

    ---

    `occ` richtet die App für Ansible und Docker ein, testet sie für die Überwachung und sagt, warum ein Benutzer ein Dokument nicht sieht; die Übersicht der Verwaltungseinstellungen warnt, wenn die Suche keines zeigen kann. [Wie](reference.de.md#mit-occ-einrichten-und-prüfen)

- :material-key-chain-variant:{ .lg .middle } **Das Token bleibt auf dem Server**

    ---

    Das Paperless-API-Token wird nur im serverseitigen Zugangsdatenmanager von Nextcloud gespeichert und nie an JavaScript im Browser zurückgegeben oder in HTML ausgegeben.

</div>

## Wie sie sich vergleicht

--8<-- "README.de.md:comparison"

[Im Detail vergleichen](compare.de.md){ .md-button }

## Screenshots

Jedes Bild folgt dem hellen oder dunklen Design dieser Website. Wähle eines, um es zu vergrößern. Die Screenshots zeigen Nextcloud auf Englisch. Ein Skript nimmt sie aus einer Demo der App auf, und ein Bot nimmt sie neu auf, sobald eine Änderung der App ändert, was sie zeigen, also zeigen sie die aktuelle Version.

<div class="grid" markdown>

<figure markdown="span">
  ![Paperless-Treffer in der globalen Suche von Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search.png#only-light){ width="1600" height="900" }
  ![Paperless-Treffer in der globalen Suche von Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search-dark.png#only-dark){ width="1600" height="900" }
  <figcaption>Paperless-Treffer in der globalen Suche von Nextcloud</figcaption>
</figure>

<figure markdown="span">
  ![Ein synchronisiertes Paperless-Dokument, geöffnet im PDF-Viewer von Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/02-nextcloud-pdf-viewer.png#only-light){ width="1600" height="900" }
  ![Ein synchronisiertes Paperless-Dokument, geöffnet im PDF-Viewer von Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/02-nextcloud-pdf-viewer-dark.png#only-dark){ width="1600" height="900" }
  <figcaption>Ein Treffer, geöffnet im Viewer von Nextcloud</figcaption>
</figure>

<figure markdown="span">
  ![Die Suche nach invoice mit eingeschaltetem Schalter Verbundene Dienste durchsuchen und dem Datumsfilter auf dem eigenen Bereich vom 7/1/2026 bis zum 7/31/2026: Paperless-Dokumente listet die Verlängerung der Hausratversicherung vom Jul 21, 2026 und die Internetrechnung vom Jul 3, 2026, aber nicht die Stromrechnung vom August, und Dateien findet keine der Dateien, die später hinzugefügt wurden](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/12-date-filter.png#only-light){ width="1600" height="900" }
  ![Die Suche nach invoice mit eingeschaltetem Schalter Verbundene Dienste durchsuchen und dem Datumsfilter auf dem eigenen Bereich vom 7/1/2026 bis zum 7/31/2026: Paperless-Dokumente listet die Verlängerung der Hausratversicherung vom Jul 21, 2026 und die Internetrechnung vom Jul 3, 2026, aber nicht die Stromrechnung vom August, und Dateien findet keine der Dateien, die später hinzugefügt wurden](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/12-date-filter-dark.png#only-dark){ width="1600" height="900" }
  <figcaption>Paperless-Treffer nach Datum eingegrenzt</figcaption>
</figure>

<figure markdown="span">
  ![Der Smart Picker in einer Notiz von Text: Unter Paperless-Dokumente listet die Suche nach invoice die Stromrechnung, die Verlängerung der Hausratversicherung und die Internetrechnung](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker.png#only-light){ width="1280" height="800" }
  ![Der Smart Picker in einer Notiz von Text: Unter Paperless-Dokumente listet die Suche nach invoice die Stromrechnung, die Verlängerung der Hausratversicherung und die Internetrechnung](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker-dark.png#only-dark){ width="1280" height="800" }
  <figcaption>Paperless-Dokumente im Smart Picker von Text</figcaption>
</figure>

<figure markdown="span">
  ![Das Dashboard von Jamie mit dem Widget Neu in Paperless: die Inspektion des Autos, die Stromrechnung, die Verlängerung der Hausratversicherung, die Internetrechnung und der Grundsteuerbescheid, die neuesten zuerst, jedes mit dem Symbol seiner PDF-Datei und einer Zeile mit dem Tag, an dem Paperless es hinzugefügt hat, seinem Korrespondenten, seinem Typ und seinen Tags, etwa Sep 3, 2026 · Example Motors · Receipt · warranty](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/11-dashboard.png#only-light){ width="1280" height="720" }
  ![Das Dashboard von Jamie mit dem Widget Neu in Paperless: die Inspektion des Autos, die Stromrechnung, die Verlängerung der Hausratversicherung, die Internetrechnung und der Grundsteuerbescheid, die neuesten zuerst, jedes mit dem Symbol seiner PDF-Datei und einer Zeile mit dem Tag, an dem Paperless es hinzugefügt hat, seinem Korrespondenten, seinem Typ und seinen Tags, etwa Sep 3, 2026 · Example Motors · Receipt · warranty](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/11-dashboard-dark.png#only-dark){ width="1280" height="720" }
  <figcaption>Die neuesten Dokumente auf dem Dashboard</figcaption>
</figure>

<figure markdown="span">
  ![Die Verwaltungseinstellungen von Paperless Unified Search in Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings.png#only-light){ width="1600" height="1000" }
  ![Die Verwaltungseinstellungen von Paperless Unified Search in Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings-dark.png#only-dark){ width="1600" height="1000" }
  <figcaption>Die Einstellungen, nur für Administratoren</figcaption>
</figure>

<figure markdown="span">
  ![Der Reiter Teilen des Ordners Paperless des Archiv-Kontos in Dateien: Der Ordner ist mit Jamie Example geteilt, nur anzeigen](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-share-read-only.png#only-light){ width="1600" height="900" }
  ![Der Reiter Teilen des Ordners Paperless des Archiv-Kontos in Dateien: Der Ordner ist mit Jamie Example geteilt, nur anzeigen](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-share-read-only-dark.png#only-dark){ width="1600" height="900" }
  <figcaption>Das Archiv, nur lesend mit einem Benutzer geteilt</figcaption>
</figure>

<figure markdown="span">
  ![Der Verlauf der Probleme der Suche in den Einstellungen](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/06-search-problems.png#only-light){ width="660" height="837" }
  ![Der Verlauf der Probleme der Suche in den Einstellungen](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/06-search-problems-dark.png#only-dark){ width="660" height="837" }
  <figcaption>Die Probleme der Suche, ohne das Protokoll von Nextcloud</figcaption>
</figure>

<figure markdown="span">
  ![Suche ausprobieren in den Einstellungen: Der Administrator sucht als Konto e2e-admin nach invoice, und eine Tabelle listet die drei Dokumente, die Paperless zuerst einordnet, mit ihren IDs, Titeln und Tags, die Stromrechnung angezeigt, die Verlängerung der Versicherung und die Internetrechnung nicht mit diesem Konto geteilt, über den Gründen, warum ein Dokument nicht angezeigt wird](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-try-a-search.png#only-light){ width="660" height="878" }
  ![Suche ausprobieren in den Einstellungen: Der Administrator sucht als Konto e2e-admin nach invoice, und eine Tabelle listet die drei Dokumente, die Paperless zuerst einordnet, mit ihren IDs, Titeln und Tags, die Stromrechnung angezeigt, die Verlängerung der Versicherung und die Internetrechnung nicht mit diesem Konto geteilt, über den Gründen, warum ein Dokument nicht angezeigt wird](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-try-a-search-dark.png#only-dark){ width="660" height="878" }
  <figcaption>Suche ausprobieren: warum ein Benutzer ein Dokument nicht sieht</figcaption>
</figure>

<figure markdown="span" class="phones">
  ![Paperless-Treffer in der globalen Suche von Nextcloud auf einem Smartphone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/04-mobile-search.png){ width="120" height="260" }
  ![Die Stromrechnung, geöffnet im Viewer von Nextcloud auf einem Smartphone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/05-mobile-viewer.png){ width="120" height="260" }
  ![Die Einstellungen auf einem Smartphone bei den Problemen der Suche: die Zahlen und darunter die letzten Probleme als Karten, jede mit der Zeit, dem Ereignis, dem Schritt, dem Fehler und der Dauer](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/09-admin-settings-mobile.png){ width="120" height="260" }
  <figcaption>Auf einem Smartphone, im Browser</figcaption>
</figure>

</div>

## Schnellstart

--8<-- "README.de.md:quick-start"

Um sie zuerst bei der Arbeit zu sehen, startet [die Demo](install.de.md#in-drei-minuten-ausprobieren) mit einem Befehl ein echtes Paperless-ngx und ein echtes Nextcloud mit der App in Docker auf deinem Rechner. [Installieren](install.de.md) und [Einrichten](configure.de.md) beschreiben jeden Schritt im Detail, die [Fehlerbehebung](troubleshooting.de.md) hilft, wenn keine Paperless-Dokumente erscheinen, und die [FAQ](faq.de.md) beantwortet die häufigen Fragen.

## Mehr erfahren

<div class="grid cards" markdown>

- :material-download-outline:{ .lg .middle } **Erste Schritte**

    ---

    Was die App braucht, ihre Installation aus dem App Store oder aus einem signierten Release, das Paperless-Konto und sein Token, die Einstellungen, das lokale Netzwerk und das nur lesend geteilte Archiv.

    [:octicons-arrow-right-24: Installieren](install.de.md) · [Einrichten](configure.de.md)

- :material-magnify:{ .lg .middle } **Suchen**

    ---

    Die Suche im Browser und auf dem Smartphone, der Smart Picker von Talk, Text, Deck und Mail, die Suchsyntax von Paperless und die API für Skripte und KI-Assistenten.

    [:octicons-arrow-right-24: Suchen](usage.de.md)

- :material-lifebuoy:{ .lg .middle } **Fehlerbehebung und FAQ**

    ---

    Warum Paperless-Dokumente nicht erscheinen, Schritt für Schritt, jede Meldung des Tests der Verbindung, Zertifikate, Proxys, der Index von Paperless und die häufigen Fragen.

    [:octicons-arrow-right-24: Fehlerbehebung](troubleshooting.de.md) · [FAQ](faq.de.md)

- :material-book-open-variant:{ .lg .middle } **Referenz**

    ---

    Jede Einstellung und ihr Schlüssel, occ, die Routen, die Versionen, die die Tests abdecken, die Datenflüsse, das Protokoll und wie du die App deinstallierst.

    [:octicons-arrow-right-24: Referenz](reference.de.md)

- :material-compare-horizontal:{ .lg .middle } **Vergleich**

    ---

    Wie sich die App mit Full text search und Elasticsearch, Workflow OCR, der Paperless integration, Context Chat, Findling und der Weboberfläche von Paperless vergleicht und wann eine andere die bessere Wahl ist.

    [:octicons-arrow-right-24: Vergleich](compare.de.md)

- :material-sitemap-outline:{ .lg .middle } **Architektur**

    ---

    Wie die App der globalen Suche von Nextcloud einen Anbieter hinzufügt, der Paperless-ngx fragt, ohne Dokumente oder einen eigenen Index zu speichern. Auf Englisch.

    [:octicons-arrow-right-24: Architektur](architecture.md)

- :material-shield-lock-outline:{ .lg .middle } **Sicherheitsdesign**

    ---

    Was die App schützt, wem sie vertraut, die Bedrohungen mit ihren Gegenmaßnahmen und die Risiken, die bleiben. Auf Englisch.

    [:octicons-arrow-right-24: Sicherheitsdesign](security.md)

- :material-map-marker-path:{ .lg .middle } **Roadmap**

    ---

    Was Paperless Unified Search in den nächsten zwölf Monaten vorhat und was es nicht tun wird.

    [:octicons-arrow-right-24: Roadmap](roadmap.de.md)

- :material-scale-balance:{ .lg .middle } **Entscheidungen**

    ---

    Die Entscheidungen, die das Projekt prägen, jede mit ihren Gründen. Auf Englisch.

    [:octicons-arrow-right-24: Entscheidungen](decisions/README.md)

- :material-history:{ .lg .middle } **Releases und Änderungen**

    ---

    Wie ein Release entsteht, signiert und im Nextcloud App Store veröffentlicht wird, und die Änderungen jeder Version. Auf Englisch.

    [:octicons-arrow-right-24: Releases](releases.md) · [Änderungen](changelog.md)

</div>
