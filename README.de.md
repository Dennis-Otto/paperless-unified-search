<div align="center">

<img src="img/app.svg" width="64" height="64" alt="">

# Paperless Unified Search

**Paperless-ngx-Dokumente an den Wörtern in ihnen finden, direkt in der Suche von Nextcloud.**

[![Neuestes Release](https://img.shields.io/github/v/release/Dennis-Otto/paperless-unified-search?sort=semver&label=release)](https://github.com/Dennis-Otto/paperless-unified-search/releases/latest)
[![Nextcloud App Store](https://img.shields.io/badge/Nextcloud_App_Store-install-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/apps/paperless_unified_search)
[![Unterstützte Versionen von Nextcloud](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2FDennis-Otto%2Fpaperless-unified-search%2Fmain%2Fappinfo%2Finfo.xml&query=concat%28%2F%2Fdependencies%2Fnextcloud%2F%40min-version%2C%22%E2%80%93%22%2C%2F%2Fdependencies%2Fnextcloud%2F%40max-version%29&label=Nextcloud&logo=nextcloud&logoColor=white&color=0082c9)](#voraussetzungen)
[![CI](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/ci.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/ci.yml)
[![Lizenz: AGPL-3.0-or-later](https://img.shields.io/badge/license-AGPL--3.0--or--later-blue)](LICENSE)

[English](README.md) · **Deutsch**

</div>

Paperless Unified Search bringt die OCR- und Volltextsuche von Paperless-ngx in die globale Suche von Nextcloud, ohne Elasticsearch, zweiten Index oder weiteren Container: Paperless antwortet, Nextcloud entscheidet, wer die Datei sieht. Treffer öffnen die passende synchronisierte Datei direkt im Viewer von Nextcloud.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/search-and-open-dark.gif">
  <img src="docs/images/search-and-open.gif" alt="Die Paperless-Dokumente, die eine Suche nach „invoice“ in Nextcloud findet, in einem Rahmen: die Stromrechnung, die Verlängerung der Hausratversicherung und die Internetrechnung. Dann die Suche von vorn: die Dateien, deren Namen passen, dann, mit eingeschaltetem Verbundene Dienste durchsuchen, die Paperless-Dokumente, deren Text passt. Die Verlängerung der Hausratversicherung öffnet sich im Viewer von Nextcloud.">
</picture>

<sub>Die Suche nach Dateien findet die beiden Rechnungen an ihren Namen. Paperless findet auch die Verlängerung der Hausratversicherung: Ihr Text sagt „invoice“, ihr Name nicht.</sub>

<!-- --8<-- [start:two-apps] -->

Zwei Apps, ein Archiv: [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) schreibt jedes Dokument von Paperless-ngx als Datei eines Nextcloud-Kontos, des Archiv-Kontos, mit der Kennung `[P<ID>]` im Namen. Paperless Unified Search fragt Paperless nach den Dokumenten, die zu einer Suche passen, und zeigt jedes allen, die seine Datei öffnen können.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/two-apps-one-archive.de.dark.svg">
  <img src="docs/images/diagrams/two-apps-one-archive.de.svg" alt="Zwei Apps, ein Archiv: Paperless Sync schreibt jedes Dokument von Paperless-ngx als Datei des Archiv-Kontos paperless, mit der Kennung des Dokuments im Namen, etwa Rechnung [P412].pdf. Das Archiv-Konto teilt sein Archiv nur lesend mit jamie. Sucht jamie, fragt Paperless Unified Search Paperless-ngx, findet die Datei von Dokument 412 an ihrer Kennung und zeigt jamie das Dokument, weil jamie diese Datei öffnen kann; der Treffer öffnet die Datei in Nextcloud.">
</picture>

<!-- --8<-- [end:two-apps] -->

Paperless Sync ist der Begleiter, keine Pflicht: [Jede Synchronisierung](#funktioniert-mit-jeder-synchronisierung), die die Dateien mit ihrer Kennung in das Archiv-Konto schreibt, funktioniert ebenso, auch die eigenen Dateinamen von Paperless-ngx.

Die [Website der Dokumentation](https://dennis-otto.github.io/paperless-unified-search/de/index.html) zeigt diese Dokumentation auf Seiten zum Installieren, Einrichten und Verwenden der App, mit einer Anleitung zur Fehlerbehebung, einer FAQ und einer Referenz für Administratoren, dazu einen Vergleich mit den Alternativen, die Architektur, das Sicherheitsdesign und die Roadmap. Die Architektur, das Sicherheitsdesign, die Entscheidungen, die Releases und das Changelog gibt es nur auf Englisch.

<sub>💛 Gefällt sie dir? [Bewerte sie im App Store](https://apps.nextcloud.com/apps/paperless_unified_search) oder [unterstütze ihre Entwicklung](https://github.com/sponsors/Dennis-Otto).</sub>

[Warum](#warum-paperless-unified-search) · [Ausprobieren](#in-drei-minuten-ausprobieren) · [Schnellstart](#schnellstart) · [Fehlerbehebung](https://dennis-otto.github.io/paperless-unified-search/de/troubleshooting.html) · [FAQ](https://dennis-otto.github.io/paperless-unified-search/de/faq.html) · [Referenz](https://dennis-otto.github.io/paperless-unified-search/de/reference.html) · [Vergleich](docs/compare.de.md) · [Architektur](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/architecture.md) · [Sicherheitsdesign](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/security.md) · [Roadmap](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/roadmap.md) · [Releases](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/releases.md) · [Änderungen](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/CHANGELOG.md)

## Warum Paperless Unified Search

<!-- --8<-- [start:why] -->

Paperless-ngx hat den Text jedes Dokuments bereits erkannt und führt einen Index seiner Wörter. Paperless Unified Search fragt diesen Index bei jeder Suche in Nextcloud, statt einen weiteren aufzubauen:

- **Kein zweiter Index, keine zweite OCR.** Kein Elasticsearch, kein zusätzlicher Container und keine Kopie des Textes in Nextcloud. Was Paperless findet, findet die Suche von Nextcloud, sobald die Datei des Dokuments in Nextcloud liegt.
- **Nextcloud entscheidet, wer was sieht.** Ein Treffer erscheint nur für jemanden, der seine Datei in Nextcloud öffnen kann, also sind die Freigaben, die du schon hast, die Berechtigungen. Niemand braucht ein Konto in Paperless.
- **Treffer öffnen sich dort, wo gearbeitet wird.** Im Viewer von Nextcloud im Browser und in den Nextcloud-Apps für iOS und Android, bereit zum Teilen, Herunterladen oder Kommentieren.
- **Neue Dokumente kommen zu dir.** Das Dashboard listet die Dokumente, die Paperless zuletzt hinzugefügt hat, für alle, die sie öffnen können, ohne Suche.
- **Die Suche von Paperless, keine Kopie ihrer Wörter.** Ihre Rangfolge, ihre Hervorhebungen und ihre Suchsyntax, etwa `correspondent:acme` oder `created:[2024 to 2025]`, die auch den Korrespondenten, den Typ und die Tags eines Dokuments betrachten.

<!-- --8<-- [start:comparison] -->

Die Tabelle stellt sie neben die anderen Wege, Paperless-Dokumente aus Nextcloud heraus zu finden, Stand Oktober 2026. Jeder Weg außer der Weboberfläche von Paperless braucht die Dokumente als Dateien in Nextcloud, etwa die von Paperless Sync.

| | Durchsucht den OCR-Text | Die Freigaben von Nextcloud entscheiden, wer einen Treffer sieht | Öffnet im Viewer und in den Mobil-Apps von Nextcloud | Zusätzliche Infrastruktur | Führt einen zweiten Index |
| --- | --- | --- | --- | --- | --- |
| **Paperless Unified Search** | **Ja, im Index von Paperless** | **Ja** | **Ja** | **Keine** | **Nein** |
| [Full text search](https://apps.nextcloud.com/apps/fulltextsearch) mit Elasticsearch | Nur in Dateien mit Textebene | Ja | Ja | Ein Elasticsearch-Server | Ja |
| [Workflow OCR](https://apps.nextcloud.com/apps/workflow_ocr) | Keine eigene Suche: fügt eine Textebene für eine andere Suche hinzu | — | — | OCRmyPDF oder sein Backend-Container | Nein, aber die Suche, die es speist |
| [Paperless integration](https://apps.nextcloud.com/apps/integration_paperless) | Keine Suche: schickt Dateien an Paperless | — | — | Keine | Nein |
| [Context Chat](https://apps.nextcloud.com/apps/context_chat) | Nur in Dateien mit Textebene, als Antworten auf Fragen | Ja | Seine Antworten verlinken die Dateien | AppAPI, sein Backend-Container und ein Sprachmodell | Ja, aus Vektoren |
| [Findling](https://apps.nextcloud.com/apps/findling) | Ja, mit eigener OCR | Ja | Ja | AppAPI und sein Backend-Container | Ja, aus Wörtern und aus Vektoren |
| Die Weboberfläche von Paperless-ngx | Ja | Nein: Konten und Berechtigungen von Paperless | Nein: Dokumente öffnen sich in Paperless | Keine | Nein |

<!-- --8<-- [end:comparison] -->
<!-- --8<-- [end:why] -->

Der [Vergleich](docs/compare.de.md) erklärt jede davon und wann eine andere die bessere Wahl ist.

## In drei Minuten ausprobieren

<!-- --8<-- [start:try-it] -->

Ein Befehl startet ein echtes Paperless-ngx und ein echtes Nextcloud in Docker auf deinem eigenen Rechner, mit fünf synthetischen Dokumenten, eingerichtetem [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) und dieser App, und endet mit einer Suche, die die Dokumente findet. Dein eigenes Nextcloud und Paperless bleiben unberührt:

```sh
git clone https://github.com/Dennis-Otto/paperless-unified-search.git
cd paperless-unified-search
bash demo/seed.sh
```

Er braucht Docker mit Compose und Bash, unter Windows Git Bash, und erzeugt die Passwörter der Demo auf deinem Rechner. Beide Weboberflächen lauschen nur auf 127.0.0.1. Sobald das Skript die Konten ausgibt, melde dich als `jamie` unter <http://localhost:8180> an und suche nach *invoice*: Die Verlängerung der Hausratversicherung ist unter den Treffern, weil ihr Text „invoice“ sagt, ihr Titel aber nicht. [Die Anleitung der Demo](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/demo/README.md) sagt, was du noch ausprobieren kannst und wie du sie wieder entfernst.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/what-the-demo-sets-up.de.dark.svg">
  <img src="docs/images/diagrams/what-the-demo-sets-up.de.svg" alt="Was die Demo einrichtet: Du startest bash demo/seed.sh. Das Skript erzeugt den geheimen Schlüssel von Paperless und die Passwörter der Demo in demo/.env und startet Paperless-ngx mit Redis und Nextcloud mit seinen Hintergrundjobs in Docker, nur auf 127.0.0.1. Paperless liest fünf synthetische Briefe ein. Paperless Sync, ein festgelegtes, signiertes Release, schreibt jeden in die Dateien des Archiv-Kontos paperless, das das Archiv nur lesend mit jamie teilt. occ paperless_unified_search:setup verbindet die App mit Paperless, mit dem API-Token über eine Pipe. Eine Suche als jamie nach invoice zeigt drei Paperless-Dokumente, und das Skript nennt die Adressen und die Konten zum Anmelden.">
</picture>

![Die Demo bei der Arbeit, als jamie: das Archiv, das Paperless Sync in Nextcloud geschrieben hat, eine Suche nach „invoice“ und unter den Dateien die Paperless-Dokumente, die beiden Rechnungen und die Verlängerung der Hausratversicherung, deren Text „invoice“ sagt. Die Verlängerung öffnet sich im Viewer von Nextcloud.](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/demo-search-and-open.gif)

<sub>Aufgenommen aus dem echten Paperless-ngx und Nextcloud der Demo, mit `bash scripts/screenshots.sh --demo`.</sub>

<!-- --8<-- [end:try-it] -->

<!-- --8<-- [start:quick-start-section] -->

## Schnellstart

<!-- --8<-- [start:quick-start] -->

1. Installiere **Paperless Unified Search** aus dem [Nextcloud App Store](https://apps.nextcloud.com/apps/paperless_unified_search) unter *Apps* oder mit `occ app:install paperless_unified_search`.
2. Lege in Paperless-ngx ein Konto an, das die zu durchsuchenden Dokumente lesen darf, und ein API-Token dafür.
3. Öffne in Nextcloud **Verwaltungseinstellungen → Paperless Unified Search**, gib die Paperless-URL und das Token ein und wähle **Verbindung testen und speichern**. Erreicht Nextcloud Paperless unter einer Adresse deines lokalen Netzwerks oder über den Namen seines Docker-Dienstes, etwa `http://192.168.1.20:8000` oder `http://paperless:8000`, wähle auch **Paperless läuft in meinem lokalen Netzwerk**. Ist [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) installiert, ist das Konto, mit dem es das Archiv schreibt, das **Archiv-Konto**; sonst gib das Konto ein, dem die synchronisierten Dateien gehören.
4. Teile als Archiv-Konto das Archiv nur lesend mit den Benutzern, die seine Dokumente finden dürfen, wie jeden Ordner in Nextcloud. Das Archiv-Konto selbst findet sie alle.
5. Suche in Nextcloud, schalte **Verbundene Dienste durchsuchen** ein und sieh unter **Paperless-Dokumente** nach. Ein Treffer erscheint für jedes Dokument, dessen synchronisierte Datei du öffnen kannst: eine Datei des Archiv-Kontos mit `[P<ID>]` im Namen, deine eigene, wenn du dieses Konto bist, oder eine, die es mit dir geteilt hat. [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) erzeugt solche Dateien.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/setting-up-the-search.de.dark.svg">
  <img src="docs/images/diagrams/setting-up-the-search.de.svg" alt="Die Suche einrichten: Installiere die App. Lege in Paperless-ngx ein Konto an, das die Dokumente lesen darf, und ein API-Token dafür. Gib in den Verwaltungseinstellungen von Nextcloud die URL, das Token und das Archiv-Konto ein, wähle Paperless läuft in meinem lokalen Netzwerk, wenn Nextcloud Paperless unter einer solchen Adresse erreicht, und wähle Verbindung testen und speichern. Antwortet Paperless, sind die Einstellungen gespeichert; sonst wird nichts gespeichert, und die Meldung sagt, was zu korrigieren ist. Das Archiv-Konto teilt sein Archiv nur lesend mit den Benutzern, die seine Dokumente finden dürfen, und sie finden sie unter Paperless-Dokumente in der Suche von Nextcloud, mit eingeschaltetem Verbundene Dienste durchsuchen.">
</picture>

<!-- --8<-- [end:quick-start] -->

[Konfiguration](#konfiguration) und [Verwendung](#verwendung) haben die Details, und [occ](#mit-occ-einrichten-und-prüfen) richtet die App ohne Browser ein.

<!-- --8<-- [end:quick-start-section] -->

Keine Paperless-Dokumente in der Suche, oder ein Test der Verbindung, der fehlschlägt? Die [Fehlerbehebung](https://dennis-otto.github.io/paperless-unified-search/de/troubleshooting.html) findet die Ursache Schritt für Schritt, und die [FAQ](https://dennis-otto.github.io/paperless-unified-search/de/faq.html) beantwortet die häufigen Fragen.

## Screenshots

Jeder Screenshot unten außer denen des Smartphones hat eine dunkle Fassung, die ein dunkles Design zeigt; die Animation oben gibt es nur hell. Die Screenshots zeigen Nextcloud auf Englisch.

### Paperless-Treffer in der globalen Suche von Nextcloud

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search-dark.png">
  <img alt="Paperless-OCR-Treffer in der globalen Suche von Nextcloud" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search.png">
</picture>

### Ein Treffer im PDF-Viewer von Nextcloud

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/02-nextcloud-pdf-viewer-dark.png">
  <img alt="Ein synchronisiertes Paperless-Dokument, geöffnet im PDF-Viewer von Nextcloud" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/02-nextcloud-pdf-viewer.png">
</picture>

### Paperless-Treffer nach Datum eingegrenzt

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/12-date-filter-dark.png">
  <img alt="Die Suche nach invoice mit eingeschaltetem Schalter Verbundene Dienste durchsuchen und dem Datumsfilter auf dem eigenen Bereich vom 7/1/2026 bis zum 7/31/2026: Paperless-Dokumente listet die Verlängerung der Hausratversicherung vom Jul 21, 2026 und die Internetrechnung vom Jul 3, 2026, aber nicht die Stromrechnung vom August, und Dateien findet keine der Dateien, die später hinzugefügt wurden" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/12-date-filter.png">
</picture>

Ein eigener Bereich im Datumsfilter behält die Dokumente, die Paperless darin datiert, hier Juli 2026, in der gefilterten Liste von Nextcloud. *Dateien* filtert nach dem Tag, an dem sich eine Datei zuletzt geändert hat, und findet darum keine der Dateien der Demo, die später hinzugefügt wurden. [Treffer nach Datum eingrenzen](#treffer-nach-datum-eingrenzen) erklärt den Filter.

### Auf dem Smartphone

<p>
  <img alt="Paperless-Treffer in der globalen Suche von Nextcloud auf einem Smartphone" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/04-mobile-search.png" width="320">
  <img alt="Die Stromrechnung, geöffnet im Viewer von Nextcloud auf einem Smartphone" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/05-mobile-viewer.png" width="320">
</p>

Nextcloud im Browser eines Smartphones. Die Nextcloud-Apps für iOS und Android öffnen einen Treffer in ihrem eigenen Viewer.

### Paperless-Dokumente im Smart Picker

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker-dark.png">
  <img alt="Der Smart Picker in einer Notiz von Text: Unter Paperless-Dokumente listet die Suche nach invoice die Stromrechnung, die Verlängerung der Hausratversicherung und die Internetrechnung" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker.png">
</picture>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/08-smart-picker-link-dark.png">
  <img alt="Die Notiz von Text, nachdem die Stromrechnung gewählt wurde: Der eingefügte Link zeigt das PDF der Rechnung" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/08-smart-picker-link.png">
</picture>

Tippe `/` in einer Notiz von Text und wähle *Paperless-Dokumente*: Der Picker findet die Dokumente an ihrem Text und fügt den Link auf die gewählte Datei ein, den Text allen, die sie öffnen können, als die Datei selbst zeigt.

### Die neuesten Dokumente auf dem Dashboard

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/11-dashboard-dark.png">
  <img alt="Das Dashboard von Jamie mit dem Widget Neu in Paperless: die Inspektion des Autos, die Stromrechnung, die Verlängerung der Hausratversicherung, die Internetrechnung und der Grundsteuerbescheid, die neuesten zuerst, jedes mit dem Symbol seiner PDF-Datei und einer Zeile mit dem Tag, an dem Paperless es hinzugefügt hat, seinem Korrespondenten, seinem Typ und seinen Tags, etwa Sep 3, 2026 · Example Motors · Receipt · warranty" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/11-dashboard.png">
</picture>

*Neu in Paperless* auf dem Dashboard von Nextcloud listet die Dokumente, die Paperless zuletzt hinzugefügt hat und die du öffnen kannst, ohne Suche.

### Paperless in jeder deiner Suchen

Mit **Meine Paperless-Dokumente immer durchsuchen** in den persönlichen Einstellungen bezieht jede deiner Suchen Paperless ohne den Schalter *Verbundene Dienste durchsuchen* ein, den Nextcloud auf jeder Seite, die lädt, ausschaltet.

### Das Archiv, nur lesend geteilt

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-share-read-only-dark.png">
  <img alt="Der Reiter Teilen des Ordners Paperless des Archiv-Kontos in Dateien: Der Ordner ist mit Jamie Example geteilt, nur anzeigen" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-share-read-only.png">
</picture>

Das Archiv-Konto teilt seinen Ordner *Paperless* mit *Nur anzeigen*, und Jamie findet seine Dokumente. Die Freigaben entscheiden, wer welches Dokument sieht: Niemand braucht ein Konto in Paperless.

### Sichere Verwaltung auf dem Server

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings-dark.png">
  <img alt="Die Verwaltungseinstellungen von Paperless Unified Search in Nextcloud" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings.png">
</picture>

Jede Meldung der Einstellungen sagt in einem Satz deiner Sprache, was passiert ist, in der Farbe und mit dem Symbol eines Erfolgs oder eines Fehlers, und *Verbindung trennen* ist nur aktiv, solange etwas zum Trennen gespeichert ist.

### Probleme der Suche

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/06-search-problems-dark.png">
  <img alt="Der Verlauf der Probleme der Suche in den Einstellungen: eine fehlgeschlagene Suche, zwei Anfragen, die erst ihr zweiter Versuch beantwortet hat, und die letzten davon mit ihren Fehlern" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/06-search-problems.png">
</picture>

Das Ende der Einstellungen zählt die fehlgeschlagenen Suchen und die Anfragen, die erst ihr zweiter Versuch beantwortet hat, und listet die letzten davon mit ihren Fehlern auf, auch wenn der Hoster das Protokoll von Nextcloud vor Administratoren verbirgt.

<p>
  <img alt="Die Einstellungen auf einem Smartphone bei den Problemen der Suche: die Zahlen und darunter die letzten Probleme als Karten, jede mit der Zeit, dem Ereignis, dem Schritt, dem Fehler und der Dauer" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/09-admin-settings-mobile.png" width="320">
</p>

Auf einem Smartphone passen die Einstellungen auf den Bildschirm, und jedes Problem der Suche ist eine Karte, deren Zeilen nennen, was sie zeigen.

### Suche ausprobieren

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-try-a-search-dark.png">
  <img alt="Suche ausprobieren in den Einstellungen: Der Administrator sucht als Konto e2e-admin nach invoice, und eine Tabelle listet die drei Dokumente, die Paperless zuerst einordnet, mit ihren IDs, Titeln und Tags, die Stromrechnung angezeigt, die Verlängerung der Versicherung und die Internetrechnung nicht mit diesem Konto geteilt, über den Gründen, warum ein Dokument nicht angezeigt wird" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-try-a-search.png">
</picture>

*Suche ausprobieren* am Ende der Einstellungen beantwortet die häufigste Frage, „Warum sehe ich dieses Dokument nicht?“, ohne Shell: Es durchsucht Paperless als ein Benutzer und nennt für jedes Dokument, ob es ihm angezeigt wird und wenn nicht, warum.

<!-- --8<-- [start:how-it-works] -->

## So funktioniert es

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/how-a-search-finds-its-documents.de.dark.svg">
  <img src="docs/images/diagrams/how-a-search-finds-its-documents.de.svg" alt="Wie eine Suche ihre Dokumente findet: Die Suche von Nextcloud schickt den Begriff, etwa Rechnung, an Paperless Unified Search, das den OCR-Text in Paperless-ngx durchsucht. Paperless antwortet mit den passenden Dokumenten, etwa Dokument 412. Die App sucht unter den Dateien, die der suchende Benutzer öffnen kann, eine Datei des Archiv-Kontos mit der Kennung [P412] im Namen. Mit einer solchen Datei ist Dokument 412 ein Treffer, der die Datei in Nextcloud öffnet; ohne sie bleibt es weg.">
</picture>

1. Nextcloud leitet eine Suchanfrage, für die die externe Suche eingeschaltet ist, an Paperless-ngx weiter.
2. Paperless liefert Treffer aus seinem eigenen OCR- und Volltextindex, nur mit den Feldern, die ein Treffer zeigt: nicht den ganzen OCR-Text jedes Dokuments, nur den Ausschnitt um den Begriff.
3. Die App ordnet jede ID eines Paperless-Dokuments einer synchronisierten Datei des Archiv-Kontos zu, deren Name die eindeutige Kennung `[P<ID>]` enthält, zum Beispiel `[P123]`, mit einer Suche im Datei-Cache von Nextcloud für alle Dokumente einer Seite. Das Archiv-Konto ist das, dem die synchronisierten Dateien gehören: das Konto von Paperless Sync oder das der Einstellungen.
4. Ein Treffer wird nur geliefert, wenn der aktuelle Nextcloud-Benutzer auf diese Datei zugreifen kann: das Archiv-Konto selbst oder ein Benutzer, mit dem es die Datei nur lesend geteilt hat. Eine Datei mit der Kennung im Namen, die einem anderen Konto gehört, steht für kein Dokument, und ebenso wenig eine Datei des Archiv-Kontos, die der Benutzer ändern darf, oder eine in einem Ordner, in den er Dateien legen darf: Über eine Freigabe mit Schreibrecht könnte er ihr die Kennung gegeben haben.
5. Eine Seite mit Treffern enthält so viele Treffer, wie Nextcloud anfordert, solange Paperless weitere hat: Stehen Dokumente, die der Benutzer nicht öffnen kann, vorne in der Rangfolge, fragt die App Paperless nach seinen nächsten Seiten, bis zu vier Seiten mit 25 Treffern je Suche, damit Paperless-Treffer trotzdem erscheinen und **Weitere Ergebnisse laden** funktioniert.
6. Jeder Treffer zeigt die Vorschau seiner Datei oder das Symbol ihres Typs und unter seinem Titel das Datum des Dokuments in der Sprache des Benutzers, seinen Korrespondenten, seinen Typ, bis zu zwei seiner Tags und die Wörter um den Begriff, wie [Was ein Treffer zeigt](https://dennis-otto.github.io/paperless-unified-search/de/usage.html#was-ein-treffer-zeigt) beschreibt.
7. Ein Klick auf einen Treffer öffnet die synchronisierte Datei in Nextcloud, nicht in Paperless. Browser verwenden die
   Viewer-Route `/f/{fileId}` von Nextcloud. Die offizielle iOS-App bekommt ihren nativen Deep Link `nextcloud://open-file`,
   die Android-App die Datei-ID und den Pfad relativ zum Benutzer, die ihr Viewer in der App braucht.

Wer Dokument 412 sieht, wenn `paperless` das Archiv-Konto ist und sein Archiv nur lesend mit `jamie` und mit Schreibrecht mit `alex` geteilt hat:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/who-sees-document-412.de.dark.svg">
  <img src="docs/images/diagrams/who-sees-document-412.de.svg" alt="Wer Dokument 412 sieht: Dem Archiv-Konto paperless gehört die Datei Rechnung [P412].pdf, und es sieht Dokument 412. Es teilt die Datei nur lesend mit jamie, und jamie sieht Dokument 412 ebenfalls. Es teilt die Datei mit alex mit Schreibrecht, also könnte alex sie so benannt haben und sieht kein Dokument. sam hat eine eigene Datei namens Kopie [P412].pdf; sie ist keine Datei des Archiv-Kontos, also sieht sam kein Dokument.">
</picture>

Diese App synchronisiert selbst keine Dokumente. Das macht die Begleit-App [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync), nativ und mit eigenen Einstellungen, und [jede andere Synchronisierung](#funktioniert-mit-jeder-synchronisierung), die die Kennung in die Dateinamen schreibt, funktioniert ebenso. Beide Apps verwenden dieselbe stabile Kennung `[P<ID>]` und sind darauf ausgelegt zusammenzuarbeiten, ohne ihre Releases aneinander zu koppeln.

<!-- --8<-- [end:how-it-works] -->
<!-- --8<-- [start:any-sync] -->

## Funktioniert mit jeder Synchronisierung

Die Suche braucht eines von einer Synchronisierung: jedes Dokument von Paperless-ngx als Datei des Archiv-Kontos in Nextcloud, mit der Kennung `[P<ID>]` des Dokuments im Namen der Datei. [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) schreibt solche Dateien, mit Ordnern nach Korrespondent, Typ und Jahr, und das kann jeder andere Weg auch:

1. **Lass Paperless die Kennung in seine Dateinamen schreiben.** Das [Format der Dateinamen](https://docs.paperless-ngx.com/advanced_usage/#file-name-handling) von Paperless hat den Platzhalter `{{ doc_pk }}`, die ID des Dokuments. In der Umgebung von Paperless, etwa seiner `docker-compose.env`:

    ```text
    PAPERLESS_FILENAME_FORMAT={{ created_year }}/{{ correspondent }}/{{ title }} [P{{ doc_pk }}]
    ```

    Paperless speichert Dokument 412 dann als `2026/ACME/Invoice [P412].pdf`. Sein Befehl [`document_renamer`](https://docs.paperless-ngx.com/administration/#renamer) benennt die Dokumente um, die es schon hat, und jeder [Speicherpfad](https://docs.paperless-ngx.com/advanced_usage/#storage-paths) von Paperless braucht die Kennung ebenfalls.

2. **Kopiere die Dateien in das Archiv-Konto, in eine Richtung, von Paperless nach Nextcloud.** Der Ordner `media/documents/archive` von Paperless enthält die PDFs mit ihrem erkannten Text, `media/documents/originals` die Dateien, wie sie eingegangen sind.

    - **rclone** oder ein anderer WebDAV-Client, mit einem Timer oder in einem Container neben Paperless, mit einem [App-Passwort](https://docs.nextcloud.com/server/latest/user_manual/en/session_management.html#managing-devices) des Archiv-Kontos `paperless`:

        ```sh
        rclone config create nextcloud webdav vendor=nextcloud \
          url=https://cloud.example.com/remote.php/dav/files/paperless/ \
          user=paperless pass=app-password --obscure
        rclone sync /path/to/paperless/media/documents/archive nextcloud:Paperless
        ```

    - **Der Desktop-Client von Nextcloud**, angemeldet als Archiv-Konto, auf einer Kopie des Ordners statt auf dem Ordner selbst: Er synchronisiert in beide Richtungen, und Paperless verliert eine Datei aus den Augen, die in seinem Medienordner verschoben oder gelöscht wird.
    - **Ein externer Speicher** mit den Dateien, nur für das Archiv-Konto eingebunden und von dort geteilt. Eine Einbindung lässt sich erst teilen, wenn in ihren Optionen **Teilen aktivieren** eingeschaltet ist, hinter dem Zahnrad des Speichers unter *Externer Speicher* in den Verwaltungseinstellungen. Die Suche von Nextcloud findet nur die Dateien, die es gescannt hat, also lass einen Timer `occ files:scan` für den Speicher ausführen. Auf einem Speicher, der für alle Benutzer eingebunden ist, gehören jedem Benutzer die Dateien, die er dort sieht, und eine solche Datei steht für kein Dokument.

3. **Teile das Archiv nur lesend** mit den Benutzern, die seine Dokumente finden dürfen, und gib das Archiv-Konto in den Einstellungen ein, wie in der [Konfiguration](#konfiguration).

<!-- --8<-- [end:any-sync] -->
<!-- --8<-- [start:requirements] -->

## Voraussetzungen

- Nextcloud 33 bis 35
- PHP 8.2 oder neuer, soweit die jeweilige Version von Nextcloud es unterstützt
- Ein erreichbares Paperless-ngx 2.16 oder neuer, 3.x eingeschlossen, mit Zugriff auf die API. Die App fragt nach Version 9 seiner API, die Paperless-ngx 2.16 und neuer und 3.x sprechen; Vertragstests prüfen die App jede Woche gegen echtes Paperless-ngx, das letzte Release von 2.20 und das neueste Release von 3.x; die [Kompatibilität](https://dennis-otto.github.io/paperless-unified-search/de/reference.html#kompatibilität) sagt es im Detail.
- Synchronisierte Dateien, deren Namen `[P<ID>]` enthalten, die einem Nextcloud-Konto gehören, dem Archiv-Konto, und nur lesend mit den Benutzern geteilt sind, die sie sehen dürfen, zum Beispiel die von Paperless Sync oder von [jeder anderen Synchronisierung](#funktioniert-mit-jeder-synchronisierung). Ohne sie zeigt die Suche keine Paperless-Dokumente.

<!-- --8<-- [end:requirements] -->
<!-- --8<-- [start:configuration] -->

## Konfiguration

1. Lege ein eigenes Paperless-Konto an, weder **Superuser** noch **Admin**, mit nur den Rechten zum Ansehen, die die Suche braucht: **Anzeigen** (*View*) für **Dokument** (*Document*), für **Korrespondent** (*Correspondent*), **Dokumenttyp** (*DocumentType*) und **Tag** (*Tag*), deren Namen die Treffer zeigen, und für **UI-Einstellungen** (*UISettings*), aus denen der Test der Verbindung erfährt, was das Konto darf. Lass es die Dokumente, Korrespondenten, Typen und Tags ansehen, die einem anderen Benutzer gehören, etwa einem Administrator, der sie anlegt; [Ein Paperless-Konto für die Suche](https://dennis-otto.github.io/paperless-unified-search/de/configure.html#ein-paperless-konto-für-die-suche) zeigt jeden Schritt in Paperless, mit einem Workflow, der es für jedes neue Dokument erledigt.

    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/14-paperless-account-dark.png">
      <img alt="Der Dialog Edit user account von Paperless-ngx für den Benutzer nextcloud: aktiv, weder Admin noch Superuser, in keiner Gruppe, und in der Tabelle der Berechtigungen nur View für Document, Correspondent, DocumentType, Tag und UISettings" src="https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/14-paperless-account.png" width="360">
    </picture>

2. Lege ein API-Token für dieses Konto an, in **Mein Profil** (*My Profile*) von Paperless, angemeldet als das Konto.
3. Öffne in Nextcloud **Verwaltungseinstellungen → Paperless Unified Search**.
4. Gib die Basis-URL von Paperless und das API-Token ein.
5. Erreicht Nextcloud Paperless unter einer Adresse deines lokalen Netzwerks oder über den Namen seines Docker-Dienstes, etwa `http://192.168.1.20:8000` oder `http://paperless:8000`, wähle **Paperless läuft in meinem lokalen Netzwerk** ([unten](#paperless-in-deinem-lokalen-netzwerk)).
6. Gib unter **Archiv-Konto** das Nextcloud-Konto ein, dem die synchronisierten Dateien gehören, oder lass das Feld leer, um das Konto zu verwenden, mit dem Paperless Sync das Archiv schreibt. Die Einstellungen speichern die ID so, wie Nextcloud sie schreibt, gleich in welcher Groß- und Kleinschreibung, und warnen, wenn das Konto nicht existiert.
7. Schalte optional **Paperless immer in die globale Suche einbeziehen** ein, um den eingerichteten Paperless-Server als vertrauenswürdig zu behandeln.
8. Lass **Benutzern erlauben, Paperless in alle ihre Suchen einzubeziehen** an, damit jeder Benutzer Paperless in seinen persönlichen Einstellungen in jede seiner Suchen einbeziehen kann, oder schalte es aus.
9. Wähle **Verbindung testen und speichern**. Nextcloud fragt nach deinem Passwort, wenn du es in den letzten 30 Minuten nicht bestätigt hast, wie bei seinen eigenen Einstellungen dieser Art; ebenso **Verbindung trennen**.

Lass das Token leer, um das gespeicherte zu behalten. Das gespeicherte Token geht nur an den Paperless-Server, für den es gespeichert wurde: Nennt die URL einen anderen Server, mit anderem Schema, Host oder Port, fragen die Einstellungen erneut nach dem Token, damit das Token nie einen Server erreicht, für den es nicht gedacht war. Ein anderer Pfad auf demselben Server behält es. Kann Nextcloud das gespeicherte Token nicht mehr entschlüsseln, wie nach einem Umzug oder einer Wiederherstellung mit einem anderen `secret` in der `config.php`, sagen die Einstellungen das und fragen erneut danach, und bis dahin zeigt die Suche keine Paperless-Dokumente.

Der Test der Verbindung fragt Paperless auch, was das Konto des Tokens darf. Die Einstellungen speichern das Token in jedem Fall und warnen darunter, wenn das Konto ein Superuser oder ein Admin von Paperless ist oder Dokumente hinzufügen, ändern oder löschen darf, denn wer ein solches Token in die Hände bekommt, darf das auch, und wenn es keine Korrespondenten, Dokumenttypen oder Tags ansehen darf oder nicht die der neuesten Dokumente, die einem anderen Benutzer gehören, deren Namen den Treffern dann fehlen. Paperless sagt das nur einem Konto, das seine UI-Einstellungen ansehen darf; bei jedem anderen warnen die Einstellungen vor nichts. `occ paperless_unified_search:check` und die Übersicht der Verwaltungseinstellungen wiederholen die Warnungen bis zum nächsten Test, und die [Fehlerbehebung](https://dennis-otto.github.io/paperless-unified-search/de/troubleshooting.html) sagt, was bei jeder zu tun ist.

Die Konfiguration gilt global. Die Zugriffssteuerung bleibt benutzerbezogen, weil die App jeden Paperless-Treffer verwirft, für den der suchende Nextcloud-Benutzer keine passende Datei des Archiv-Kontos öffnen kann. Teile das Archiv nur lesend mit den Benutzern, die seine Dokumente sehen dürfen. Ohne Archiv-Konto zeigt die Suche keine Paperless-Dokumente. Unter dem Archiv-Konto zeigen die Einstellungen, mit wem es seine Dateien teilt, etwa *Nur lesend geteilt: 1 Benutzer, 2 Gruppen.*, und warnen vor jeder Freigabe, die Änderungen erlaubt: Ihre Empfänger finden die Dokumente darin erst, wenn sie nur lesend ist.

Fehlen Paperless-Dokumente in der Suche, sieh unter **Probleme der Suche** am Ende der Einstellungen nach, und für ein Dokument, das Paperless findet, das ein Benutzer aber nicht sieht, verwende **Suche ausprobieren** darunter: Gib den Begriff und die Benutzer-ID ein, und es listet die ersten 20 Dokumente, die Paperless für den Begriff einordnet, jedes mit seiner ID, seinem Titel, seinen Tags und ob es diesem Benutzer angezeigt wird oder warum nicht: *Mit Schreibrecht geteilt*, *Nicht geteilt mit* dem Benutzer, *Keine Datei des Archiv-Kontos* oder *Keine Datei mit* seiner Kennung, etwa `[P412]`. Es stellt dieselben Fragen wie [`occ paperless_unified_search:search --explain`](https://dennis-otto.github.io/paperless-unified-search/de/reference.html#ein-verborgenes-dokument-erklären), für Administratoren einer verwalteten Instanz ohne Shell, und antwortet nur mit den Titeln und Tags, ohne den Inhalt oder die Dateien der Dokumente. Eine fehlgeschlagene Suche zeigt den Benutzern keine Paperless-Dokumente und keinen Fehler, und eine Anfrage, die fehlschlägt, bevor sie Paperless erreicht, etwa wenn die Verbindung fehlschlägt, wird ein zweites Mal gesendet, bevor die Suche aufgibt; eine, die auf ein hängendes Paperless gewartet hat, nicht, damit eine Suche nie zweimal wartet. Die Einstellungen zählen beides, die fehlgeschlagenen Suchen und die Anfragen, die erst ihr zweiter Versuch beantwortet hat, und listen die letzten 20 mit der Zeit, dem Schritt, Anfrage an Paperless oder Suche nach den Dateien in Nextcloud, der Art und der Meldung des Fehlers und der Dauer, dazu, seit wann Suchen nach dem letzten Fehler wieder funktionieren. Eine Anfrage, die Paperless ablehnt, während sie noch getippt wird, ist kein Problem und erscheint nicht, und während eines Ausfalls, auch eines, der kommt und geht, werden die Probleme einer Art höchstens einmal in 30 Sekunden geschrieben, die dazwischen trotzdem gezählt. Das hilft, wo der Hoster das Protokoll von Nextcloud vor Administratoren verbirgt. **Verlauf leeren** und **Verbindung trennen** löschen den Verlauf; das Speichern der Einstellungen behält ihn.

Standardmäßig fragt die Suche von Nextcloud im Browser Paperless erst, nachdem der Benutzer **Verbundene Dienste durchsuchen** eingeschaltet hat, und der Smart Picker erst, nachdem der Benutzer darin **Paperless-Dokumente** gewählt hat. Ist die Option für den vertrauenswürdigen Dienst eingeschaltet, wird jeder Begriff jeder globalen Suche jedes Nextcloud-Benutzers automatisch an Paperless gesendet, und der Schalter für verbundene Dienste steuert diesen Anbieter nicht mehr. Lade Nextcloud nach einer Änderung dieser Option neu. Ein Benutzer kann dieselbe Wahl in den persönlichen Einstellungen für seine eigenen Suchen allein treffen, solange **Benutzern erlauben, Paperless in alle ihre Suchen einzubeziehen** an ist ([Verwendung](https://dennis-otto.github.io/paperless-unified-search/de/usage.html#deine-paperless-dokumente-immer-durchsuchen)).

### Paperless in deinem lokalen Netzwerk

Nextcloud verweigert Verbindungen zu Adressen des lokalen Netzwerks, etwa `192.168.1.20`, `paperless.local` oder dem Namen eines Docker-Dienstes, damit keine URL, die jemand eingibt, die Dienste hinter seiner Firewall erreichen kann. Die meisten Paperless-Server laufen genau unter einer solchen Adresse. Wähle **Paperless läuft in meinem lokalen Netzwerk**, und Nextcloud erlaubt die Adresse für die Anfragen dieser App an die Paperless-URL der Einstellungen und für nichts anderes: Diese Anfragen folgen keiner Weiterleitung, und jede andere App behält den Schutz. Ohne den Schalter schlägt **Verbindung testen und speichern** mit einer Meldung fehl, die ihn nennt. Gib die Adresse ein, unter der Paperless selbst antwortet, etwa `https://paperless.example.com` statt `http://paperless.example.com`, wenn ein Proxy `http` auf `https` umleitet: Mit dem Schalter folgt Nextcloud keiner Weiterleitung, und eine Weiterleitung lässt den Test der Verbindung fehlschlagen.

Setze für Paperless nicht `allow_local_remote_servers` in der `config.php`: Das hebt den Schutz für jede App und jede URL auf, die Benutzer eingeben können, etwa Linkvorschauen, Webhooks und die Server föderierter Freigaben.

Hast du `allow_local_remote_servers` früher für Paperless in der `config.php` gesetzt, wähle zuerst **Paperless läuft in meinem lokalen Netzwerk** und speichere, und entferne es erst dann aus der `config.php`. Sonst zeigt die Suche keine Paperless-Dokumente, bis der Schalter gespeichert ist.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/which-requests-reach-an-address-of-the-local-network.de.dark.svg">
  <img src="docs/images/diagrams/which-requests-reach-an-address-of-the-local-network.de.svg" alt="Welche Anfragen eine Adresse des lokalen Netzwerks erreichen: Eine Anfrage dieser App an die Paperless-URL der Einstellungen erreicht eine Adresse des lokalen Netzwerks, wenn Paperless läuft in meinem lokalen Netzwerk gewählt ist, und folgt dann keiner Weiterleitung. Ohne den Schalter verweigert Nextcloud die Adresse, bevor eine Anfrage den Server verlässt, und das Speichern sagt, dass der Schalter zu wählen ist. Anfragen anderer Apps behalten den Schutz von Nextcloud, außer allow_local_remote_servers in der config.php hebt ihn für alle auf.">
</picture>

Die URL darf einen Docker-Dienst mit Unterstrich nennen, etwa `http://paperless_ngx:8000`, eine IPv6-Adresse in eckigen Klammern, etwa `http://[fd00::5]:8000`, oder einen internationalisierten Namen, den die Einstellungen in der ASCII-Form speichern, die DNS kennt, etwa `https://xn--bcher-kva.example` für `https://bücher.example`.

<!-- --8<-- [end:configuration] -->

### Wenn der Test der Verbindung fehlschlägt

<!-- --8<-- [start:connection-test] -->

**Verbindung testen und speichern** speichert nichts, bis Paperless mit einer Liste von Dokumenten antwortet, und wenn es das nicht tut, sagt die Meldung, was fehlgeschlagen ist und was zu korrigieren ist, in der Sprache deines Kontos. Das Protokoll von Nextcloud bekommt eine Warnung mit dem Fehler selbst, etwa `cURL error 6: Could not resolve host`, ohne das Token und ohne die Abfrage der URL.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/what-the-test-of-the-connection-checks.de.dark.svg">
  <img src="docs/images/diagrams/what-the-test-of-the-connection-checks.de.svg" alt="Was der Test der Verbindung prüft: Verbindung testen und speichern fragt Paperless nach einem Dokument. Zuerst muss Nextcloud Paperless unter der URL erreichen: seinen Host finden, sich mit seiner Adresse verbinden dürfen, sich innerhalb von 3 Sekunden verbinden, seinem Zertifikat vertrauen und innerhalb von 10 Sekunden eine Antwort bekommen; sonst nennt die Meldung, was davon fehlgeschlagen ist. Dann muss Paperless mit einem Status 2xx antworten; ein abgelehntes Token, ein falscher Pfad, eine Weiterleitung oder ein Fehler des Servers haben je eine eigene Meldung. Zuletzt muss die Antwort eine Liste von Dokumenten von Paperless-ngx sein. Erst dann werden die Einstellungen gespeichert. Zuletzt sagt Paperless, was das Konto des Tokens darf, wenn es seine UI-Einstellungen ansehen darf, und die Einstellungen warnen unter dem Token vor einem Superuser, einem Admin, einem Konto, das Dokumente ändern darf, oder einem, das keine Korrespondenten, Dokumenttypen oder Tags ansehen darf oder nicht die der neuesten Dokumente, die einem anderen Benutzer gehören.">
</picture>

| Die Meldung sagt | Was fehlgeschlagen ist | Was zu tun ist |
| --- | --- | --- |
| Paperless hat das API-Token abgelehnt (HTTP 401 oder 403) | Paperless kennt das Token nicht, oder sein Konto darf keine Dokumente ansehen. Oder die URL leitet auf ein anderes Schema, einen anderen Host oder Port um, etwa von `http` auf `https`, und Nextcloud lässt das Token bei der Weiterleitung weg | Kopiere das Token erneut aus dem Profil des Paperless-Kontos und gib dem Konto die Berechtigung, Dokumente anzusehen. Gib die Adresse ein, auf die die URL weiterleitet |
| Diese URL antwortet, aber nicht mit der API von Paperless (HTTP 404) | Der Pfad der URL oder ein Proxy vor Paperless | Gib die Adresse ein, die Paperless im Browser öffnet, mit dem Pfad, wenn Paperless unter einem läuft, etwa `https://example.com/paperless` |
| Diese URL leitet an eine andere Adresse weiter | Die URL leitet um, und mit **Paperless läuft in meinem lokalen Netzwerk** folgt Nextcloud keiner Weiterleitung | Gib die Adresse ein, auf die sie weiterleitet, etwa die mit `https` |
| Paperless ist älter als 2.16 | Paperless hat mit HTTP 406 geantwortet: Es spricht Version 9 seiner API nicht, nach der die App fragt | Aktualisiere Paperless-ngx auf 2.16 oder neuer |
| Paperless hat mit einem Fehler geantwortet | Paperless selbst ist fehlgeschlagen, mit einem Status 5xx | Sieh in das Protokoll von Paperless, oder versuche es später erneut |
| Diese URL antwortet, aber nicht wie Paperless-ngx | Ein anderer Dienst oder die Anmeldeseite eines Proxys | Prüfe die Adresse und ihren Pfad |
| Nextcloud verbindet sich nicht mit Adressen des lokalen Netzwerks | Paperless läuft unter einer Adresse des lokalen Netzwerks | Wähle **Paperless läuft in meinem lokalen Netzwerk**, wie [Paperless in deinem lokalen Netzwerk](#paperless-in-deinem-lokalen-netzwerk) beschreibt |
| Nextcloud vertraut dem Zertifikat von Paperless nicht | Ein Zertifikat deiner eigenen Zertifizierungsstelle, ein selbst signiertes oder eines für einen anderen Namen | Importiere das Zertifikat der Zertifizierungsstelle mit `occ security:certificates:import`, oder verwende den Namen, für den das Zertifikat ausgestellt ist |
| Nextcloud konnte keine verschlüsselte Verbindung aufbauen | Meist `https` an einem Port, der einfaches HTTP spricht | Gib die URL mit `http` ein, oder den Port von HTTPS |
| Nextcloud konnte den Host dieser URL nicht finden | Der Name lässt sich nicht auflösen, wie ein Docker-Dienst außerhalb des Netzwerks von Nextcloud | Prüfe seine Schreibweise, und bring Nextcloud und Paperless in ein gemeinsames Docker-Netzwerk |
| Paperless hat nicht rechtzeitig geantwortet | Keine Verbindung innerhalb von 3 Sekunden oder keine Antwort innerhalb von 10 | Prüfe, ob Paperless läuft und nicht ausgelastet ist und ob keine Firewall die Anfragen verwirft |
| Nextcloud konnte Paperless unter dieser URL nicht erreichen | Die Verbindung wurde abgelehnt, wie an einem falschen Port | Prüfe die Adresse und den Port und ob Paperless läuft |
| Die Einstellungen konnten nicht gespeichert werden | Etwas nach dem Test, etwa die Datenbank | Sieh in das Protokoll von Nextcloud |

Die Antwort der Route der Einstellungen trägt neben der Meldung einen `code`, etwa `unauthorized`, `not_found` oder `unknown_host`, für Skripte, die die Einstellungen speichern.

Kann Nextcloud selbst die Anfrage nicht annehmen, sagt die Seite das in einem Satz, statt die Seite des Fehlers zu zeigen:

| Die Meldung sagt | Was passiert ist | Was zu tun ist |
| --- | --- | --- |
| Nextcloud ist im Wartungsmodus | Nextcloud wird aktualisiert oder gewartet und beantwortet jede Anfrage mit HTTP 503 und einem Header, der das sagt | Versuche es in ein paar Minuten noch einmal |
| Deine Sitzung ist abgelaufen | Die Sitzung deiner Anmeldung ist beendet, oder ihr Anfrage-Token ist nicht mehr gültig (HTTP 401 oder 412) | Lade die Seite neu und versuche es noch einmal |
| Nextcloud ist nicht erreichbar | Der Browser hat seine Verbindung zu Nextcloud verloren | Prüfe deine Verbindung und versuche es noch einmal |
| Die Anfrage ist fehlgeschlagen (HTTP 502) | Ein Proxy vor Nextcloud hat mit einem eigenen Fehler geantwortet, etwa 502, 503 oder 504, oder Nextcloud hat ein Problem mit seiner eigenen Einrichtung gefunden, etwa einen Datenordner, in den es nicht schreiben kann. Ohne Status ist die Seite selbst auf einen Fehler gestoßen | Prüfe, ob Nextcloud läuft, sein Protokoll und das Protokoll des Proxys, und lade die Seite neu |
| Bestätige dein Passwort, um die Einstellungen zu ändern | Der Dialog, der nach deinem Nextcloud-Passwort fragt, wurde geschlossen oder hat das Passwort nicht bestätigt | Wähle erneut **Verbindung testen und speichern** oder **Verbindung trennen** und bestätige mit deinem Nextcloud-Passwort |
| Nextcloud hat unerwartet geantwortet | Etwas vor Nextcloud, etwa die Anmeldeseite eines Proxys, hat statt der App geantwortet | Lade die Seite neu und versuche es noch einmal |

<!-- --8<-- [end:connection-test] -->
<!-- --8<-- [start:languages] -->

### Sprachen

Die App spricht Englisch, Deutsch, Französisch, Spanisch und Niederländisch, mit jedem Konto in seiner Sprache: die Suche und ihre Treffer, das Widget im Dashboard, die persönlichen und die Verwaltungseinstellungen mit ihren Meldungen, die Warnungen der Übersicht und ihr Eintrag im App Store. Deutsch sagt *du* zu Konten mit der Sprache *Deutsch (Persönlich: Du)* und *Sie* zu Konten mit der Sprache *Deutsch (Förmlich: Sie)*; Französisch sagt *vous*, Spanisch *tú* und Niederländisch *je*, wie Nextcloud in diesen Sprachen. In jeder anderen Sprache spricht die App Englisch.

Die französischen, spanischen und niederländischen Texte sind mit Hilfe einer KI entstanden, mit den Wörtern der Übersetzungen von Nextcloud selbst, etwa *Rechercher dans les services connectés* für **Verbundene Dienste durchsuchen**, und warten darauf, dass Muttersprachler sie lesen. [Übersetzen](https://dennis-otto.github.io/paperless-unified-search/de/translate.html) zeigt, wie du einen Text korrigierst oder eine Sprache hinzufügst.

<!-- --8<-- [end:languages] -->
<!-- --8<-- [start:usage] -->

## Verwendung

Öffne die globale Suche von Nextcloud, schalte **Verbundene Dienste durchsuchen** ein und wähle **Paperless-Dokumente**. Nextcloud 32 und neuer schalten den Schalter auf jeder Seite, die lädt, wieder aus, damit kein Begriff ohne deine Wahl an einen anderen Server geht. Um ihn zu überspringen, wähle in deinen persönlichen Einstellungen **Meine Paperless-Dokumente immer durchsuchen**, wie [unten](#deine-paperless-dokumente-immer-durchsuchen); ein Administrator kann Paperless stattdessen mit dem Modus für den vertrauenswürdigen Dienst in die Suche jedes Benutzers einbeziehen.

Dokumente ohne synchronisierte Datei `[P<ID>]` des Archiv-Kontos bleiben absichtlich weg. Das hält auch Dokumente, die nur im Posteingang liegen, aus der Suche von Nextcloud heraus, wenn die Synchronisierung sie nicht exportiert.

### Deine Paperless-Dokumente immer durchsuchen

Wähle unter **Persönliche Einstellungen → Paperless** **Meine Paperless-Dokumente immer durchsuchen**, und jede deiner Suchen fragt Paperless ohne den Schalter, ab der nächsten Seite, die du öffnest. Deine Suchbegriffe gehen dann mit jeder Suche an den Paperless-Server deiner Nextcloud, auch wenn du eine Datei oder einen Kontakt suchst; die Suchen aller anderen bleiben, wie sie sind. Entferne den Haken, und der Schalter entscheidet wieder.

Der Abschnitt erscheint, sobald Paperless mit einem Archiv-Konto verbunden ist. Administratoren können in den Verwaltungseinstellungen **Benutzern erlauben, Paperless in alle ihre Suchen einzubeziehen** ausschalten: Der Abschnitt verschwindet dann, und die Suche übergeht, was Benutzer gewählt haben, bis es wieder an ist. Solange **Paperless immer in die globale Suche einbeziehen** an ist, bezieht jede Suche Paperless ohnehin ein, und der Abschnitt zeigt das Kästchen angehakt und ausgegraut.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/when-a-search-asks-paperless.de.dark.svg">
  <img src="docs/images/diagrams/when-a-search-asks-paperless.de.svg" alt="Wann eine Suche Paperless fragt: Während eine Seite von Nextcloud lädt, fragt ihre Suche die App, ob Paperless für den angemeldeten Benutzer ein verbundener Dienst ist. Solange ein Administrator Paperless in jede Suche aller Benutzer einbezieht, ist es keiner, und jede Suche fragt Paperless. Sonst fragt, solange die Administratoren Benutzer wählen lassen, jede Suche eines Benutzers Paperless, der in seinen persönlichen Einstellungen Meine Paperless-Dokumente immer durchsuchen gewählt hat. Alle anderen finden Paperless-Dokumente nur mit dem Schalter Verbundene Dienste durchsuchen, den Nextcloud auf jeder Seite, die lädt, wieder ausschaltet.">
</picture>

### Treffer nach Datum eingrenzen

Wähle im Datumsfilter der Suche von Nextcloud einen Zeitraum, etwa **Letzte 30 Tage**, **Dieses Jahr** oder einen eigenen Bereich, und die Paperless-Treffer zeigen nur Dokumente, deren Datum in Paperless darin liegt, das Datum, das Paperless für das Dokument zeigt, nicht den Tag, an dem es hinzugefügt oder synchronisiert wurde. Der Zeitraum zählt in ganzen Tagen in deiner Zeitzone: *Letzte 30 Tage*, am 30. September in Berlin gewählt, findet die Dokumente vom 1. bis zum 30. September. Paperless filtert die Dokumente selbst, also findet auch ein enger Zeitraum die passenden Dokumente weiter unten in der Rangfolge, und die Treffer bleiben in der gefilterten Liste von Nextcloud statt unter den teilweisen Treffern.

<!-- --8<-- [end:usage] -->
<!-- --8<-- [start:what-a-result-shows] -->

### Was ein Treffer zeigt

Jeder Treffer zeigt die Vorschau seiner Datei, wie es die Treffer von *Dateien* tun, sonst das Symbol des Dateityps. Unter seinem Titel nennt eine Zeile das Datum des Dokuments in deiner Sprache, etwa *Aug 12, 2026* auf Englisch und *12.08.2026* auf Deutsch, seinen Korrespondenten, seinen Typ und bis zu zwei seiner Tags, etwa *Bill · unpaid · tax 2026*, und dann die Wörter um den Begriff, die Paperless gefunden hat. Zwei Rechnungen desselben Korrespondenten verraten so auf einen Blick, welche *paid* ist. Hat nur eine Notiz des Dokuments gepasst, wie bei `notes:transfer`, zeigt die Zeile *Notiz:* und die Wörter der Notiz.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/what-a-result-shows.de.dark.svg">
  <img src="docs/images/diagrams/what-a-result-shows.de.svg" alt="Was ein Treffer zeigt: Die Suche von Paperless-ngx schickt den Titel von Dokument 412, sein Datum 2026-08-12, die IDs seines Korrespondenten und seines Typs, 3 und 1, seiner Tags, 1, 2 und 3, und die Wörter um den Begriff. Paperless nennt Korrespondent 3 Northstar Energy, Typ 1 Bill und die Tags 2 und 3 unpaid und tax 2026; Tag 1 ist der Posteingang, den kein Treffer zeigt. Das kostet eine Anfrage je Art für alle Dokumente der Seite, die die App zehn Minuten lang behält, und ein Treffer zeigt bis zu zwei Tags, dann +1 für jeden weiteren. Die Datei des Dokuments in Nextcloud liefert die Vorschau, wenn Nextcloud Vorschauen ihres Typs zeichnet, sonst das Symbol des Typs, wie die Suche nach Dateien sie zeigt. Der Treffer zeigt dieses Bild, den Titel Electricity invoice - August 2026 und darunter 12.08.2026 · Northstar Energy · Bill · unpaid · tax 2026 — Invoice E-2026-0812. Total due 84.30 EUR by September 2, mit dem Datum in der Sprache des Benutzers, etwa Aug 12, 2026 auf Englisch.">
</picture>

- **Vorschau.** Nextcloud zeichnet Vorschauen von Bildern und Office-Dokumenten, von PDF-Dateien aber erst, wenn ein Administrator sie einschaltet, wie seine [Dokumentation der Vorschauen](https://docs.nextcloud.com/server/latest/admin_manual/configuration_files/previews_configuration.html) beschreibt. Bis dahin zeigt ein Treffer das Symbol von PDF-Dateien, wie es die Treffer von *Dateien* tun.
- **Korrespondent, Typ und Tags.** Paperless schickt sie als Zahlen. Die App fragt Paperless mit einer Anfrage für alle Korrespondenten, einer für alle Typen und einer für alle Tags einer Seite nach ihren Namen und behält die Namen zehn Minuten lang, also erscheint ein in Paperless geänderter Name innerhalb von zehn Minuten in den Treffern. Darf das Konto des API-Tokens keine Korrespondenten, Typen oder Tags ansehen, oder nicht die, die einem anderen Benutzer gehören, oder antwortet Paperless nicht rechtzeitig, erscheinen die Treffer ohne sie, und die Suche funktioniert wie zuvor; die Einstellungen, `occ paperless_unified_search:check` und die Übersicht warnen vor einem solchen Konto, bei denen, die einem anderen Benutzer gehören, wenn die neuesten Dokumente einen davon nennen.
- **Bis zu zwei Tags.** Ein Treffer zeigt die ersten zwei Tags seines Dokuments in der Reihenfolge, in der Paperless sie schickt, und dann, wie viele es noch hat, etwa *unpaid · tax 2026 · +2* für ein Dokument mit vier. Den Tag des Posteingangs zeigt er nie: Paperless gibt ihn jedem neuen Dokument, bis jemand es angesehen hat, und er sagt nichts über das Dokument.
- **Text.** Die Wörter um den Begriff kommen so, wie Paperless sie auszeichnet, und der Treffer zeigt sie als einfachen Text. Text eines Dokuments in spitzen Klammern, etwa `<GmbH>`, erscheint mit einem Leerzeichen nach `<`, als `< GmbH>`, damit kein Teil eines Treffers je als HTML wirken kann.

<!-- --8<-- [end:what-a-result-shows] -->
<!-- --8<-- [start:smart-picker] -->

### In Talk, Text, Deck und Mail

Der Smart Picker von Nextcloud findet Paperless-Dokumente ebenfalls, wo immer du schreibst: Tippe `/` in Text oder Collectives, oder öffne den Smart Picker in einer Unterhaltung von Talk, einer Karte von Deck oder einer Nachricht von Mail. Wähle **Paperless-Dokumente**, suche und wähle einen Treffer: Der Picker fügt den Link auf seine Datei ein. Alle, die diese Datei öffnen können, sehen ihre Vorschau; alle anderen sehen nur den Link.

Der Picker sucht über denselben Anbieter wie die Suche von Nextcloud, also listet er dieselben Dokumente. Er fragt Paperless, sobald du **Paperless-Dokumente** gewählt und einen Begriff getippt hast, ohne den Schalter **Verbundene Dienste durchsuchen**: Die Wahl ist dieselbe Zustimmung.

<!-- --8<-- [end:smart-picker] -->
<!-- --8<-- [start:dashboard] -->

### Auf dem Dashboard

Füge **Neu in Paperless** mit **Anpassen** am Ende des Dashboards zu deinem Dashboard hinzu. Das Widget listet die neuesten Dokumente, die Paperless hinzugefügt hat und die du öffnen kannst, die neuesten zuerst, ohne Suche: jedes mit der Vorschau seiner Datei und unter seinem Titel dem Tag, an dem Paperless es hinzugefügt hat, in deiner Sprache, seinem Korrespondenten, seinem Typ und bis zu zwei seiner Tags, wie ein Treffer der Suche sie zeigt, etwa *03.09.2026 · Example Motors · Receipt · warranty*. Ein Klick auf eines öffnet seine Datei in Dateien. Sobald die Liste voll ist, zeigt das Dashboard von Nextcloud sechs Dokumente und darunter **Mit dir geteilte Dateien**, das alle Dateien öffnet, die andere mit dir teilen; mit weniger Dokumenten zeigt es alle und keinen Link, und das Archiv-Konto, dem die Dateien gehören, bekommt gar keinen solchen Link. Die Apps und die anderen Clients der API des Dashboards bekommen bis zu sieben Dokumente und den Link. Die Dashboard-API von Nextcloud stellt das Widget auch anderen Clients bereit, etwa den Widgets der Android-App auf dem Startbildschirm eines Smartphones.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/the-newest-documents-on-the-dashboard.de.dark.svg">
  <img src="docs/images/diagrams/the-newest-documents-on-the-dashboard.de.svg" alt="Die neuesten Dokumente auf dem Dashboard: Das Dashboard von jamie fragt das Widget Neu in Paperless nach seinen Einträgen. Die App nimmt die 25 Dokumente, die Paperless-ngx zuletzt hinzugefügt hat, aus dem verteilten Cache von Nextcloud, oder fragt sonst Paperless danach, die neuesten zuerst, und behält sie eine Minute lang für jeden Benutzer, denn jede Anfrage trägt das Token der Einstellungen. Davon sieht jamie die Dokumente, deren Datei des Archiv-Kontos jamie öffnen kann, gefunden an ihren Kennungen mit einer Suche im Datei-Cache, höchstens sieben, mit den Namen ihrer Korrespondenten, Typen und Tags. Jeder Eintrag zeigt die Vorschau seiner Datei, seinen Titel und den Tag, an dem Paperless ihn hinzugefügt hat, seinen Korrespondenten, seinen Typ und bis zu zwei seiner Tags, etwa 03.09.2026 · Example Motors · Receipt · warranty, und öffnet die Datei in Dateien.">
</picture>

- **Wer was sieht.** Dieselbe Regel wie in der Suche: Ein Dokument erscheint für alle, die seine Datei des Archiv-Kontos öffnen können. Das Widget betrachtet die 25 Dokumente, die Paperless zuletzt hinzugefügt hat; kannst du keines davon öffnen, sagt es das.
- **Wie neu.** Die neuesten Dokumente sind für jeden Benutzer dieselben, denn jede Anfrage an Paperless trägt das Token der Einstellungen, also fragt die App Paperless höchstens einmal in der Minute danach, für alle Benutzer gemeinsam, und behält sie solange im verteilten Cache von Nextcloud. Das braucht einen Memory-Cache, wie Nextcloud ihn empfiehlt: Ohne einen fragt jedes Dashboard, das das Widget zeigt, Paperless und wartet während eines Ausfalls darauf. Ein Dokument, das Paperless hinzufügt, erscheint innerhalb einer Minute, eine Datei, die mit dir geteilt wird, sofort.
- **Wenn Paperless nicht antwortet**, innerhalb von fünf Sekunden, sagt das Widget *Paperless ist gerade nicht erreichbar.*, und die App fragt eine Minute später erneut. Ein solcher Fehler kommt in das Protokoll von Nextcloud, nicht in den Verlauf der Probleme der Suche.
- **Nur wo die Suche funktioniert.** Das Dashboard bietet das Widget an, sobald die Einstellungen ein Paperless und ein Archiv-Konto nennen, das existiert.

<!-- --8<-- [end:dashboard] -->
<!-- --8<-- [start:search-syntax] -->

## Suchen wie in Paperless

Nextcloud gibt die Wörter einer Suche so an Paperless-ngx weiter, wie sie getippt werden, also funktioniert seine [Suchsyntax](https://docs.paperless-ngx.com/usage/#document-searches) auch in Nextcloud. Ohne sie findet Paperless die Dokumente, die jedes Wort enthalten, im Text, im Titel, im Korrespondenten, im Typ oder in den Tags:

| Suche nach | Findet |
| --- | --- |
| `invoice 2025` | Dokumente mit beiden Wörtern, in beliebiger Reihenfolge |
| `"home insurance"` | Die genaue Wortfolge |
| `insur*` | Wörter, die mit `insur` beginnen |
| `invoice NOT draft` | Rechnungen ohne das Wort *draft*; die Operatoren werden großgeschrieben |
| `correspondent:acme` | Dokumente des Korrespondenten ACME |
| `type:invoice tag:unpaid` | Unbezahlte Rechnungen, nach ihrem Typ und ihrem Tag |
| `created:[2024 to 2025]` | Dokumente, die von 2024 bis 2025 erstellt wurden |
| `added:yesterday` | Dokumente, die Paperless gestern erhalten hat |
| `title:invoice` | Dokumente mit dem Wort im Titel; `content:` sucht nur in ihrem Text |
| `asn:1234` | Das Dokument mit dieser Archivseriennummer |
| `notes:callback` | Dokumente mit dem Wort in einer Notiz |
| `path:binders` | Dokumente mit diesem Speicherpfad, nach seinem Namen |
| `custom_fields:gold` | Dokumente mit dem Wort im Wert eines benutzerdefinierten Felds |
| `original_filename:scan-0412.pdf` | Das Dokument dieser Datei, wie Paperless sie erhalten hat, nach ihrem ganzen Namen |
| `like:[P412]` | Dokumente, die dem mit der Kennung `[P412]` im Dateinamen ähneln |

`like:` findet von einem Brief aus die übrigen Briefe eines Vorgangs: Paperless sucht die Dokumente, die diesem am ähnlichsten sind, wie **More like this** in seiner Weboberfläche. Der Begriff ist `like:` mit der ID des Dokuments allein, in der Kennung seiner Datei oder ohne sie, etwa `like:[P412]`, `like:P412` oder `like:412`, in Groß- oder Kleinbuchstaben; mit einem weiteren Wort oder etwas anderem nach dem Doppelpunkt, etwa `like:abc` oder `like:[P012]`, ist es eine Suche wie jede andere. Es funktioniert überall, wo die Suche funktioniert, in der globalen Suche, im Smart Picker und in der API, mit dem Datumsfilter und auf jeder Seite der Treffer, und lässt das Dokument selbst aus. Eine ID, die Paperless nicht kennt, oder die eines Dokuments, das sein Konto nicht ansehen darf, findet nichts. Paperless 3 schickt für ein ähnliches Dokument keinen Textauszug, also nennt sein Treffer stattdessen seine Datei.

Paperless-ngx 2 kennt auch `owner:jamie`, das Konto, dem ein Dokument gehört; Paperless-ngx 3 hat dieses Feld nicht. [Die Vertragstests](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/tests/e2e/README.md#against-a-real-paperless-ngx) prüfen jede Zeile dieser Tabelle gegen beide.

Ein Feld oder ein Zitat, das noch getippt wird, etwa `tag:` allein, findet womöglich nichts; die Treffer kommen, sobald die Suche vollständig ist. Paperless 3 lehnt eine Anfrage ab, die es noch nicht verarbeiten kann, etwa `created:[2026 to`, während der Bereich noch getippt wird; die Suche findet dann die Dokumente ihrer Wörter in der einfachen Suche von Paperless, bis die Anfrage vollständig ist. Weder das noch eine Seite nach dem letzten Treffer zählt als Problem der Suche. Jeder Treffer ist trotzdem ein Dokument, dessen Datei der suchende Benutzer öffnen kann.

<!-- --8<-- [end:search-syntax] -->
<!-- --8<-- [start:scripts] -->

## Aus Skripten und KI-Assistenten nutzen

Die Suche ist auch eine API: Die OCS-Such-API von Nextcloud antwortet mit denselben Treffern, die seine Suche zeigt, für den Benutzer, der fragt, und die Dateien, die dieser Benutzer öffnen kann. Ein Skript, eine Automatisierung oder ein KI-Assistent, der HTTP-Anfragen senden kann, verwendet sie mit einem [App-Passwort](https://docs.nextcloud.com/server/latest/user_manual/en/session_management.html#managing-devices) eines Benutzers:

```sh
curl -u jamie -H 'OCS-APIRequest: true' \
  'https://cloud.example.com/ocs/v2.php/search/providers/paperless_unified_search_documents/search?term=invoice&format=json'
```

curl fragt nach dem App-Passwort, was es aus dem Verlauf der Shell heraushält; ein Skript übergibt es in einer Datei mit `--netrc-file`. Die Antwort, gekürzt:

```json
{
  "ocs": {
    "meta": { "status": "ok", "statuscode": 200, "message": "OK" },
    "data": {
      "name": "Paperless documents",
      "isPaginated": false,
      "entries": [
        {
          "thumbnailUrl": "https://cloud.example.com/core/preview?x=64&y=64&fileId=1234&mimeFallback=1",
          "title": "Home insurance renewal",
          "subline": "Mar 2, 2026 · Harbor Mutual · Insurance · paid — … the premium is due with this invoice …",
          "resourceUrl": "https://cloud.example.com/f/1234",
          "icon": "",
          "rounded": false,
          "attributes": { "fileId": "1234", "path": "/Archive/2026/Home insurance renewal [P412].pdf" }
        }
      ],
      "cursor": null
    }
  }
}
```

Jeder Eintrag nennt das Dokument, sein Datum in der Sprache des Benutzers, seinen Korrespondenten, seinen Typ und bis zu zwei seiner Tags mit einer passenden Zeile seines Textes, das Bild seiner Datei in `thumbnailUrl` als absolute URL, das seine Vorschau ist, wenn Nextcloud Vorschauen seines Typs zeichnet, und sonst das Symbol dieses Typs, den Link, der seine Datei öffnet, und die ID und den Pfad der Datei für diesen Benutzer. `icon` bleibt leer: Der Smart Picker von Nextcloud würde es statt des Bildes zeigen, als Namen einer CSS-Klasse. `limit` legt fest, wie viele Treffer eine Seite enthält, standardmäßig 5 und bis zu 50. Um eine Seite zu füllen, geht die App bis zu 4 Seiten mit 25 Dokumenten durch, die Paperless findet, höchstens 3 Sekunden lang. Eine Seite kann trotzdem weniger Treffer enthalten, sogar keinen, wenn der Benutzer die Dateien nur weniger der Dokumente öffnen kann, die Paperless findet, also frag weiter mit `cursor` auf dem `cursor` der Antwort, solange `isPaginated` `true` ist. Schick den `cursor` so zurück, wie du ihn bekommen hast: Er zählt die Dokumente von Paperless, die die Seiten davor durchgegangen sind. `since` und `until` grenzen die Treffer auf die Dokumente ein, die in einem Zeitraum datiert sind, wie es der Datumsfilter der Suche tut, jeweils ein Zeitpunkt wie `2026-08-01T00:00:00Z`, dessen Tag in der Zeitzone des Benutzers zählt, also findet `&since=2026-08-01T00:00:00Z&until=2026-08-31T23:59:59Z` die Dokumente vom August 2026 für einen Benutzer in UTC. Der Schalter **Verbundene Dienste durchsuchen** gehört zur Suche im Browser; die API fragt Paperless ohne ihn.

Gib einem Assistenten das App-Passwort eines Benutzers, der nur sieht, was er lesen darf, und widerrufe das App-Passwort in den persönlichen Sicherheitseinstellungen, sobald der Assistent es nicht mehr braucht.

<!-- --8<-- [end:scripts] -->
<!-- --8<-- [start:automation] -->

## Mit occ einrichten und prüfen

Vier Befehle von `occ` erledigen ohne Browser, was die Einstellungen tun, und mehr: Sie richten die App für Ansible, Docker und andere Skripte ein, testen sie für die Überwachung und sagen, warum ein Benutzer ein Dokument nicht findet.

| Befehl | Was er tut | Exit-Code |
| --- | --- | --- |
| `paperless_unified_search:setup` | Testet die Verbindung mit der URL und dem Token und speichert dann die Einstellungen, wie es **Verbindung testen und speichern** tut | 0, sobald gespeichert, 1, wenn Paperless nicht antwortet oder eine Einstellung falsch ist, 2 bei einem falschen Aufruf, etwa einer fehlenden `--url` oder einer leeren Quelle des Tokens |
| `paperless_unified_search:test` | Testet die Verbindung mit den gespeicherten Einstellungen, nennt die Version von Paperless und warnt vor einem Token, das mehr darf, als die Suche braucht, oder weniger, als die Treffer zeigen | 0, wenn Paperless antwortet, sonst 1 |
| `paperless_unified_search:search <user> <term>` | Sucht als der Benutzer und sagt für jedes Dokument, ob es ihm angezeigt wird, mit `--explain` auch, warum nicht | 0, sobald Paperless geantwortet hat |
| `paperless_unified_search:check` | Testet die Verbindung, was das Konto des API-Tokens darf, und das Archiv-Konto, zählt die Dokumente von Paperless, die eine Datei haben, und listet, wie das Archiv geteilt ist | 0, wenn die Suche Dokumente zeigen kann, sonst 1 |

### Die App einrichten

Das Token kommt nie auf die Befehlszeile, wo der Verlauf der Shell und die Liste der Prozesse es anderen zeigen würden: `setup` liest es mit `--token-stdin` von der Standardeingabe oder mit `--token-env=NAME` aus einer Umgebungsvariablen. Eine Option, die du weglässt, behält ihren gespeicherten Wert, und ohne Token bleibt das gespeicherte, aber nur für den Server, für den es gespeichert wurde, wie auf der Seite der Einstellungen.

| Option | Setzt |
| --- | --- |
| `--url=URL` | Die Adresse von Paperless, etwa `https://paperless.example.com`; erforderlich |
| `--token-stdin`, `--token-env=NAME` | Das API-Token, aus der ersten Zeile der Standardeingabe oder aus der Umgebungsvariablen `NAME` |
| `--archive-account=ID` | Das Konto, dem die synchronisierten Dateien gehören; leer für das Konto von Paperless Sync |
| `--allow-local`, `--no-allow-local` | **Paperless läuft in meinem lokalen Netzwerk** |
| `--always-search`, `--no-always-search` | **Paperless immer in die globale Suche einbeziehen** |
| `--personal-always-search`, `--no-personal-always-search` | **Benutzern erlauben, Paperless in alle ihre Suchen einzubeziehen** |

Auf einem Server, als Benutzer des Webservers:

```sh
printf '%s\n' "$PAPERLESS_TOKEN" | sudo -u www-data php occ paperless_unified_search:setup \
  --url=https://paperless.example.com --archive-account=paperless --token-stdin
```

Mit Docker Compose und dem Image von Nextcloud, aus einer Datei, die nur du lesen kannst:

```sh
docker compose exec -T --user www-data nextcloud php occ paperless_unified_search:setup \
  --url=http://paperless:8000 --archive-account=paperless --allow-local --token-stdin \
  < paperless-token.txt
```

Mit Ansible, aus seinem Vault und ohne das Token in seiner Ausgabe:

```yaml
- name: Connect Paperless Unified Search to Paperless-ngx
  ansible.builtin.command:
    argv: [php, occ, paperless_unified_search:setup, --url=https://paperless.example.com,
           --archive-account=paperless, --token-stdin]
    chdir: /var/www/nextcloud
    stdin: "{{ vault_paperless_token }}"
  become: true
  become_user: www-data
  no_log: true
```

### Ein verborgenes Dokument erklären

Die Suche lässt jedes Dokument ohne Spur weg, das keine Datei des Archiv-Kontos hat, die der suchende Benutzer öffnen kann: Zwölf Dokumente, die `jamie` nicht öffnen darf, sehen genauso aus wie keines. `search` listet die Dokumente, die Paperless zuerst einordnet, standardmäßig 10 und mit `--limit` bis zu 50, und ob jedes dem Benutzer angezeigt wird, und `--explain` nennt den Grund für jedes, das nicht angezeigt wird:

```console
$ occ paperless_unified_search:search jamie invoice --limit=6 --explain
+-----+-----------------------------------+------------+--------------------------------------------------------------------------------+
| ID  | Title                             | Verdict    | File                                                                           |
+-----+-----------------------------------+------------+--------------------------------------------------------------------------------+
| 412 | Electricity invoice - August 2026 | shown      | /Paperless/2026/Electricity invoice - August 2026 [P412].pdf                   |
| 389 | Home insurance renewal            | not shared | /Private/Home insurance renewal [P389].pdf of paperless, not shared with jamie |
| 371 | Internet invoice - July 2026      | shown      | /Paperless/2026/Internet invoice - July 2026 [P371].pdf                        |
| 356 | Water bill 2026                   | no file    |                                                                                |
| 344 | Old invoice                       | not owned  | /Scans/Old invoice [P344].pdf of jamie                                         |
| 330 | Parking receipt                   | writable   | /Inbox/Parking receipt [P330].pdf of paperless, jamie may write it             |
+-----+-----------------------------------+------------+--------------------------------------------------------------------------------+
Paperless found 12 document(s) for "invoice"; 2 of the 6 above show to jamie.
Writable (330): jamie may change the file of the archive account paperless, or add files to its folder, so anyone with that share could have given it the marker of the document. Share it read-only with jamie.
Not shared (389): the archive account paperless has the file, but hasn't shared it with jamie. Share the file, or a folder that holds it, read-only with jamie.
Not owned (344): jamie sees a file with the marker of the document, but only the files of the archive account paperless stand for documents.
No file (356): no file of the archive account paperless has the marker of the document, such as [P356], in its name. The synchronization hasn't written it yet, or names files without it.
```

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/why-a-document-does-not-show.de.dark.svg">
  <img src="docs/images/diagrams/why-a-document-does-not-show.de.svg" alt="Warum ein Dokument nicht angezeigt wird: Paperless findet Dokument 389, wenn jamie sucht, und occ paperless_unified_search:search jamie invoice --explain stellt nacheinander vier Fragen. Sieht jamie eine Datei des Archiv-Kontos mit [P389] im Namen? Dann wird das Dokument angezeigt, außer jamie darf diese Datei ändern oder Dateien in ihren Ordner legen: Dann ist sie mit Schreibrecht geteilt, denn jamie könnte sie so benannt haben, und sie nur lesend mit jamie zu teilen, zeigt sie an. Sonst: Hat das Archiv-Konto eine solche Datei in seinen eigenen Ordnern? Dann ist sie nicht mit jamie geteilt, und die Datei oder ihren Ordner nur lesend mit jamie zu teilen, zeigt sie an. Sonst: Sieht jamie eine Datei mit der Kennung eines anderen Kontos? Dann gehört sie nicht dem Archiv-Konto, denn nur Dateien des Archiv-Kontos stehen für Dokumente. Sonst gibt es keine Datei, und die Synchronisierung muss erst eine mit der Kennung im Namen schreiben.">
</picture>

Ohne `--explain` entscheidet `search` so wie die Suche selbst, mit einer Suche im Datei-Cache, und nennt jedes Dokument, das nicht angezeigt wird, `hidden`; mit der Option folgt für jede weitere Frage eine Suche im Datei-Cache, für die Dokumente, die noch nicht erklärt sind. `--json` schreibt die Dokumente für Skripte, jedes mit seinen Feldern `id`, `title`, `verdict`, `path` und `owner`.

### Die ganze Einrichtung prüfen

`check` testet die Verbindung, was das Konto des API-Tokens darf, und das Archiv-Konto und vergleicht die Dokumente, die das Konto des API-Tokens in Paperless sieht, mit den Dateien des Archiv-Kontos, deren Namen ihre Kennungen tragen:

```console
$ occ paperless_unified_search:check
✓ Paperless 3.3.0 (API 10) answers at https://paperless.example.com.
✓ The account of the API token may change no document, and may view correspondents, document types and tags.
✓ The archive account is paperless, the account of Paperless Sync.
! Coverage: 1,180 of 1,234 Paperless document(s) have a file of the archive account. Without one, among others: 17, 18, 19, 20, 23, 31, 40, 41, 42, 57.
! Shared: 2 users, 2 groups.
! /Paperless/Inbox is shared with the user sam with write permission, so the search shows sam none of the documents in it. Share it read-only.
```

Dokumente ohne Datei sind kein Fehler, solange die Suche andere zeigen kann: Die Synchronisierung hat sie vielleicht noch nicht geschrieben oder lässt manche absichtlich weg. Hat kein einziges Dokument eine Datei, etwa bei einem Archiv-Konto, dem keine der synchronisierten Dateien gehört, endet `check` mit 1 und sagt, was zu korrigieren ist. `search --explain` mit einem Begriff eines solchen Dokuments sagt, warum es nicht angezeigt wird. Ein Konto des Tokens, das Dokumente ändern oder keine Korrespondenten, Dokumenttypen oder Tags ansehen darf, bekommt eine Zeile mit `!` und was zu ändern ist, ebenso eines, von dem Paperless nicht sagt, was es darf, ohne das Recht, seine UI-Einstellungen anzusehen; keines von beiden lässt die Prüfung fehlschlagen.

Zuletzt listet `check`, mit wem das Archiv-Konto seine Dateien teilt: die Benutzer, Gruppen und Teams, jeden einmal, andere Empfänger wie Unterhaltungen von Talk, und die öffentlichen Links. Jede Freigabe, die Änderungen erlaubt, bekommt eine Warnung mit ihrem Pfad und ihrem Empfänger, denn wer in das Archiv schreiben darf, könnte einer Datei die Kennung eines beliebigen Dokuments geben, und die Suche zeigt ihm keines der Dokumente darin. `check` endet trotzdem mit 0, denn die Suche zeigt den anderen weiter Dokumente.

### In der Übersicht der Verwaltung

**Verwaltungseinstellungen → Übersicht** prüft die App unter den anderen Prüfungen der Einrichtung von Nextcloud, ebenso `occ setupchecks`. Sie warnt, wenn die Suche aus einem Grund, den ein Administrator beheben kann, keine Paperless-Dokumente zeigt, und verlinkt die Einstellungen der App: ein Token, das Nextcloud nicht mehr entschlüsseln kann, kein Archiv-Konto oder eines, das nicht existiert, oder eine fehlgeschlagene Suche, nach der keine mehr funktioniert hat. Danach warnt sie, wenn das Archiv-Konto Dateien mit Schreibrecht teilt, und wenn der letzte Test der Verbindung ergab, dass das Konto des Tokens Dokumente ändern oder keine Korrespondenten, Dokumenttypen oder Tags ansehen darf. Sonst nennt sie das Paperless, mit dem die App verbunden ist, mit der Version seiner letzten Antwort, und wie das Archiv geteilt ist. Sie liest nur, was die App gespeichert hat, und die Freigaben in der Datenbank von Nextcloud, und sendet keine Anfrage an Paperless, also öffnet sich die Übersicht so schnell wie ohne sie.

<!-- --8<-- [end:automation] -->
<!-- --8<-- [start:security-and-privacy] -->

## Sicherheit und Datenschutz

- Das Paperless-API-Token wird nur im serverseitigen Zugangsdatenmanager von Nextcloud gespeichert.
- Das Token wird nie an JavaScript im Browser zurückgegeben und nie in HTML ausgegeben.
- Das Token wird nur an den Paperless-Server gesendet, für den es gespeichert wurde; eine URL mit anderem Schema, Host oder Port braucht es erneut.
- Das Speichern der Einstellungen und das Trennen der Verbindung fragen nach dem Passwort des Administrators, wenn es in den letzten 30 Minuten nicht bestätigt wurde.
- `occ` nimmt das Token von seiner Standardeingabe oder aus einer Umgebungsvariablen, nie von der Befehlszeile, wo der Verlauf der Shell und die Liste der Prozesse es zeigen würden.
- Der Schutz von Nextcloud vor Adressen des lokalen Netzwerks bleibt für jede App eingeschaltet. **Paperless läuft in meinem lokalen Netzwerk** hebt ihn nur für die Anfragen dieser App an die Paperless-URL der Einstellungen auf, die keiner Weiterleitung folgen.
- Suchtreffer werden über die Sicht des aktuellen Benutzers auf das Dateisystem von Nextcloud gefiltert.
- Eine Datei des Archiv-Kontos steht nur für einen Benutzer, der sie nur lesen darf, für ein Dokument: Eine, die der Benutzer ändern darf, oder eine in einem Ordner des Archiv-Kontos, in den er Dateien legen darf, könnte die Kennung eines beliebigen Dokuments tragen. Die Einstellungen, `occ paperless_unified_search:check` und die Übersicht warnen vor jeder Freigabe des Archivs, die Änderungen erlaubt.
- Die Einstellungen zeigen Administratoren den Verlauf der Probleme der Suche ohne das API-Token, den Suchbegriff und die Abfrage einer URL.
- **Suche ausprobieren** zeigt Administratoren, welche Dokumente ein Benutzer zu einem Begriff findet, nur mit ihren IDs, Titeln und Tags, ohne ihren Inhalt, einen Ausschnitt oder ihre Dateien; das Audit-Protokoll der App *Auditing / Logging* (`admin_audit`) hält fest, welcher Administrator als welcher Benutzer gesucht hat, gleich bei welcher Protokollstufe, ebenso das Protokoll von Nextcloud auf der Stufe info, ohne den Begriff.
- Suchbegriffe werden nur von Server zu Server gesendet, wenn die Suche in verbundenen Diensten oder der Modus für den vertrauenswürdigen Dienst eingeschaltet ist, wenn der Benutzer in den persönlichen Einstellungen **Meine Paperless-Dokumente immer durchsuchen** gewählt hat, nachdem der Benutzer im Smart Picker **Paperless-Dokumente** gewählt hat, oder wenn ein Client diese Suche über ihre ID in der API anfragt.
- Ein Link, den der Smart Picker einfügt, ist der Link der Datei in Nextcloud, der seine Vorschau nur Lesern zeigt, die die Datei öffnen können.
- Das Widget des Dashboards zeigt einem Benutzer nur die neuesten Dokumente, deren Dateien er öffnen kann. Der verteilte Cache von Nextcloud behält die IDs, Titel, Korrespondenten, Typen und Tags der 25 neuesten Dokumente eine Minute lang, die für jeden Benutzer dieselben sind, und nie ihren Text.
- Administratoren können dem eingerichteten Paperless-Server ausdrücklich vertrauen, um ihn automatisch in die globalen Suchen jedes Benutzers einzubeziehen.
- Keine Zugangsdaten für den Betrieb, privaten Hostnamen, internen Adressen oder Konfiguration einer Instanz gehören in dieses Repository.
- Gitleaks durchsucht jeden Push und jeden Pull Request.
- Dependency Review blockiert neu hinzugefügte verwundbare oder nicht freigegebene Abhängigkeiten.
- Psalm analysiert den PHP-Code und CodeQL das JavaScript in jedem Pull Request.
- Jedes Release enthält eine SPDX-SBOM, eine abgetrennte Signatur und öffentliche Build-Provenienz von Sigstore.
- OpenSSF Scorecard prüft die Sicherheit der Lieferkette des Repositorys jede Woche.

Sicherheitsprobleme meldest du so, wie [SECURITY.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/SECURITY.md) es beschreibt.

<!-- --8<-- [end:security-and-privacy] -->

**Vertrauen:**
[![Docker E2E](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/e2e.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/e2e.yml)
[![Secret scan](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/secret-scan.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/secret-scan.yml)
[![CodeQL](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/codeql.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/codeql.yml)
[![SBOM](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/sbom.yml/badge.svg)](https://github.com/Dennis-Otto/paperless-unified-search/actions/workflows/sbom.yml)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Dennis-Otto/paperless-unified-search/badge)](https://scorecard.dev/viewer/?uri=github.com/Dennis-Otto/paperless-unified-search)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects/14256/badge)](https://www.bestpractices.dev/projects/14256)
[![REUSE](https://api.reuse.software/badge/github.com/Dennis-Otto/paperless-unified-search)](https://api.reuse.software/info/github.com/Dennis-Otto/paperless-unified-search)

## Entwicklung

Installiere die Abhängigkeiten und führe jede Prüfung der CI aus; der Dev-Container in `.devcontainer/` hat die Werkzeuge bereit:

```bash
composer install
bash scripts/check.sh
```

`scripts/check.sh` prüft Composer, `appinfo/info.xml` gegen das Schema des App Stores, die PHP-Syntax, den Coding-Standard, Psalm, PHPUnit, das Paket, das krankerl mit `scripts/check-package.sh` baut, und mit `scripts/check-project.sh` das JavaScript mit den Tests der Seite der Einstellungen, jede Zeile und jeden Zweig davon, die Übersetzungen, die Bilder der READMEs, eine eigene Beschreibung für jede Seite der Website und dass jedes Diagramm von oben nach unten verläuft, mit einem Titel und einer Beschreibung für Screenreader.

Schlägt eine Prüfung fehl, erklärt sie sich selbst: was sie gefunden hat, warum das wichtig ist und wie es sich beheben lässt, mit den Befehlen dafür, in der Zusammenfassung der Prüfung und in einem Kommentar am Pull Request, auch an einem Pull Request aus einem Fork. Die Erklärungen der Prüfungen nur dieser App stehen in [`.github/checks.project.toml`](.github/checks.project.toml): die End-to-End-Tests `nextcloud`, `paperless (2)`, `paperless (3)` und `coming Nextcloud`, die Demo und der Schritt von `scripts/check.sh`, der `scripts/check-project.sh` ausführt. `scripts/check-project.sh` schlägt fehl, solange einer erforderlichen Prüfung oder einem Schritt die Erklärung fehlt. Was ein Befehl beheben kann, behebt ein Pull Request selbst: Einen JavaScript-Katalog in `l10n/`, der hinter seinem JSON-Katalog zurückliegt, schreibt `node scripts/check-l10n.mjs --write` neu, und `bash scripts/versions.sh --write` schreibt die Versionen von Nextcloud und PHP aus `appinfo/info.xml` in die READMEs, die Seiten der Website und die Beschreibungen im App Store, mit einer Spalte für jede Version in den Tabellen der Kompatibilität. So behebt der Pull Request des Upstream-Bots für ein neues Nextcloud, der nur `max-version` anhebt, seine Dokumentation selbst; die [Anleitung für Releases](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/releases.md) zeigt, wie.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/when-a-check-of-the-app-fails.de.dark.svg">
  <img src="docs/images/diagrams/when-a-check-of-the-app-fails.de.svg" alt="Wenn eine Prüfung der App fehlschlägt: Ein Pull Request führt die Prüfungen nur dieser App aus, etwa nextcloud, die End-to-End-Tests, oder den Schritt Checks of this project von scripts/check.sh. Schlägt eine fehl, schreibt ihr letzter Schritt ihre Erklärung aus .github/checks.project.toml in die Zusammenfassung der Prüfung und als ihre Anmerkung: was sie gefunden hat, warum das wichtig ist und wie es sich beheben lässt. Nach den Prüfungen hält der PR-Coach einen Kommentar am Pull Request mit dieser Erklärung aktuell, auch an einem Pull Request aus einem Fork. Wo ein Befehl behebt, was die Prüfung gefunden hat, legt der Fix-Bot die Korrektur als Commit auf einen Branch dieses Repositorys und schlägt sie an einem Fork vor; sonst sagt die Erklärung der Person, was zu tun ist, und der nächste Push führt die Prüfungen erneut aus.">
</picture>

Das Archiv für den Betrieb lässt Tests, Werkzeuge für Releases, die Entwicklungsabhängigkeiten von Composer, Screenshots und die Metadaten des Repositorys absichtlich weg. Gepackt wird mit [Krankerl](https://github.com/ChristophWurst/krankerl), und `scripts/check-package.sh` prüft das fertige Archiv.

### Mutationstests

Jede Zeile von `lib/` läuft in einem Unit-Test, aber eine Zeile, die läuft, ist damit noch nicht geprüft. Jede Woche lässt der Workflow Mutation [Infection](https://infection.github.io/) den Code in kleinen Schritten ändern, einen Mutanten nach dem anderen, und die Unit-Tests gegen jede Änderung laufen. Die Tests bemerken jeden Mutanten außer denen, die nichts an dem ändern, was die App tut, 34 von 2.187 im Oktober 2026, die [`infection.json5`](infection.json5) mit dem Grund aufführt. Also ist die Punktzahl, das *covered code MSI*, 100 %, und ein Mutant, der entkommt, ist eine Lücke in den Tests oder eine Änderung ohne Unterschied, die dort zu erklären ist.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/the-mutation-tests.de.dark.svg">
  <img src="docs/images/diagrams/the-mutation-tests.de.svg" alt="Die Mutationstests: Infection ändert den Code von lib/ jedes Mal an einer kleinen Stelle, ein Mutant, etwa ein Vergleich, der seine Grenze einschließt, oder ein Aufruf, der wegfällt, und führt die Unit-Tests aus, die die geänderte Zeile abdecken. Schlägt ein Test fehl, ist der Mutant getötet. Bestehen alle Tests, ist der Mutant entkommen. Macht die Änderung einen Unterschied, fehlt ein Test, und ein neuer Test oder eine schärfere Zusicherung tötet den Mutanten. Macht sie keinen, ist es ein äquivalenter Mutant, den infection.json5 mit dem Grund aufführt. Die Punktzahl zählt die getöteten Mutanten unter allen, die nicht aufgeführt sind, und bleibt bei 100 %.">
</picture>

Um sie selbst auszuführen, mit PHP 8.3 oder neuer und pcov oder Xdebug und [dem PHAR von Infection](https://github.com/infection/infection/releases):

```bash
composer install
php infection.phar --threads=max
```

Während du an einer Datei arbeitest, ändert `--filter` nur diese, etwa `--filter=lib/Command/Search.php`, und prüft ihre Mutanten in Sekunden. Ein Mutant, der entkommt, bekommt einen Test dessen, was Nutzer oder Administratoren bemerken würden, etwa ein Limit von genau 50 oder ein Korrespondent und ein Dokumenttyp mit derselben ID, die jeweils ihren eigenen Namen behalten; nur eine Änderung ohne Unterschied kommt in `infection.json5`, über ihre Zeile und mit dem Grund.

Die Unit-Tests lassen die App gegen Nachbildungen von Nextcloud laufen, die sich wie Nextcloud verhalten: [`MemoryAppConfig`](tests/Unit/MemoryAppConfig.php) für die Konfiguration der Apps, die wie Nextcloud den Standardwert für einen Lazy-Wert liefert, nach dem ein Lesen ohne das Lazy-Flag fragt, und [`MemoryCache`](tests/Unit/MemoryCache.php) für den verteilten Cache.

### Docker-End-to-End-Tests

Die Docker-Suite startet eine saubere Nextcloud-Instanz mit der App als Custom App und einem deterministischen Mock der Paperless-API. Sie prüft den echten OCS-Endpunkt der Suche, den benutzerbezogenen Zugriff auf Dateien, die Metadaten des vertrauenswürdigen Dienstes, den Smart Picker und die Links, die er einfügt, und die Verträge der Treffer für Browser, iOS und Android. Sie richtet die App über occ ein und prüft die erklärte Suche, *Suche ausprobieren* der Einstellungen, das nur Administratoren aufrufen dürfen, die Prüfung von occ und die Warnung der Übersicht. Sie lässt Paperless fehlschlagen und eine Verbindung abbrechen und prüft den Verlauf der *Probleme der Suche*, den die Einstellungen dann zeigen, ohne den Begriff oder das Token. In Chromium prüft sie die Barrierefreiheit der Einstellungen auf einem Laptop und einem Smartphone, ihre Meldung für jede Antwort, auch wenn Nextcloud im Wartungsmodus oder nicht erreichbar ist, und *Verbindung trennen*, und geht den Dialog der Suche auf einem Laptop und einem Smartphone durch, vom Begriff bis zur Datei im Viewer. Zum Schluss schlägt sie bei jedem Fehler der App im Log von Nextcloud fehl, auch bei einer nicht abgefangenen Ausnahme mit einem Stacktrace durch `lib/`, bei jeder Deprecation, jedem Hinweis und jeder Warnung von PHP in einer Datei der App, da die Images von Nextcloud ein neueres PHP als die Unit-Tests nutzen, und bei jeder Warnung der App, die kein Test hervorruft; [das Log von Nextcloud](tests/e2e/README.md#the-log-of-nextcloud) erklärt jeden Fund. Ein Pull-Request geht den Dialog der Suche auf einem Smartphone nur mit der neuesten unterstützten Nextcloud durch, was die erforderliche Prüfung schneller macht; ein Push auf `main`, der wöchentliche Lauf und ein von Hand gestarteter Lauf prüfen jede Größe mit jeder Version. Neben den Tests jeder Version installiert [das Upgrade vom letzten Release](tests/e2e/README.md#the-upgrade-from-the-last-release) das letzte Release des Pakets für den App Store, nachdem seine Signatur und seine Provenienz geprüft sind, richtet es mit occ ein und aktualisiert es mit `occ upgrade` auf den Checkout, gepackt wie ein Release: Die Einstellungsseite muss dieselben Einstellungen zeigen, dieselbe Suche muss dieselbe Datei mit dem gespeicherten Token finden, das weiterhin nur an seinen eigenen Server geht, und jeder Reparaturschritt der App muss einmal laufen, damit kein Update eine Installation zurücksetzt. Ein Pull Request führt es mit der neuesten unterstützten Nextcloud aus, alles andere mit jeder Version.

```bash
bash tests/e2e/run.sh       # one Nextcloud version
bash tests/e2e/run-all.sh   # every supported version at the same time, as the CI does
bash tests/e2e/upgrade.sh   # the upgrade from the last release to the checkout
```

Die [Vertragstests](tests/e2e/README.md#against-a-real-paperless-ngx) lassen die App statt gegen den Mock gegen ein echtes Paperless-ngx 2.20 und 3.x laufen und prüfen, dass die Felder, Daten, Ausschnitte, Seiten und Fehler von Paperless, die neuesten Dokumente des Dashboards und die Namen der Korrespondenten, Dokumenttypen und Tags so bleiben, wie die App sie liest:

```bash
PAPERLESS=3 bash tests/e2e/paperless-contract.sh   # or PAPERLESS=2
```

In der CI führt die Pflichtprüfung `nextcloud` die Tests gegen jede unterstützte Version in einem Job aus. Ein Pull Request, der keine Datei ändert, die die Tests abdecken, etwa einer, der nur die Dokumentation ändert, besteht sie in Sekunden, ohne sie auszuführen. Die Vertragstests sind die Pflichtprüfungen `paperless (2)` und `paperless (3)` daneben: Sie laufen in jedem Pull Request, der die App oder den Mock von Paperless ändert, und bestehen sonst in Sekunden. Jeden Montag läuft die Prüfung `nextcloud` auch ohne eine Änderung der App, gegen das neueste Release hinter jedem Image von Nextcloud; bricht das die App, öffnet sie das Issue *A supported Nextcloud release breaks the app* mit den Versionen, die fehlschlagen, und schließt es, sobald sie wieder bestehen. Setze `KEEP_E2E=1`, um die Container zur Untersuchung laufen zu lassen. Die [Matrix der mobilen Tests](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/tests/e2e/MANUAL_MOBILE_TESTS.md) beschreibt die letzten Prüfungen mit den offiziellen Clients.

### Screenshots

Die Screenshots in `screenshots/`, die Animation der Suche in `docs/images/`, hell und dunkel, und die Social Preview `.github/social-preview.png`, die GitHub und Chats zu einem Link auf das Repository zeigen, kommen aus einem Skript, damit sie zeigen, was Benutzer sehen:

```bash
bash scripts/screenshots.sh
```

Es startet die Docker-Suite unter einem eigenen Projekt mit einer Demo: dem Archiv-Konto `paperless`, dessen synthetische Dokumente nur lesend mit `jamie` geteilt sind, und einem Mock von Paperless, der drei davon für „invoice“ findet. Chromium sucht dann, öffnet einen Treffer, grenzt die Suche mit dem Datumsfilter auf Juli 2026 ein, verlinkt ein Dokument aus dem Smart Picker in einer Notiz von Text, speichert die Einstellungen und probiert darin eine Suche aus, hell und dunkel, auf einem Smartphone und als Animation, die mit ihren Paperless-Treffern in einem Rahmen beginnt. `scripts/screenshot-media.sh` macht dann im Image von `.github/screenshots/Dockerfile` neben jeder Animation das Video, das die Website an ihrer Stelle in einem Viertel ihrer Größe abspielt, mit seinem ersten Bild, das bis zum Abspielen zu sehen ist, und verkleinert jedes PNG um etwa ein Viertel, ohne ein Pixel zu ändern. Jeder Schritt prüft, was sein Bild zeigt: Die nach Datum eingegrenzte Suche schlägt etwa fehl, wenn ein Paperless-Treffer außerhalb des Juli erscheint oder die Treffer unter *Teilübereinstimmungen* landen. Die Uhr des Browsers steht in jedem Lauf auf demselben Morgen, und die Dateien der Demo wurden zwei Tage davor geändert, also ergeben zwei Läufe dieselben Pixel. Setze `KEEP_SCREENSHOTS=1`, um dich danach in der Demo umzusehen.

Die Animation von [In drei Minuten ausprobieren](#in-drei-minuten-ausprobieren) kommt dagegen aus dem echten Paperless-ngx und Nextcloud der Demo. Während die Demo läuft, meldet sich `bash scripts/screenshots.sh --demo` als `jamie` mit dem Passwort aus `demo/.env` an, sucht nach *invoice*, öffnet die Verlängerung der Hausratversicherung und schreibt nur `screenshots/demo-search-and-open.gif`; die Demo und die anderen Bilder bleiben, wie sie sind. Auch hier ergeben zwei frische Demos dieselben Pixel: Die Uhr beginnt zwei Tage nach der letzten Änderung des Archivs, die Suche startet erst, wenn der ganze Begriff getippt ist, die Benachrichtigungen sind gelesen, und Paperless ordnet die Dokumente allein nach ihrem Text. Der wöchentliche Lauf der Demo nimmt sie ebenfalls auf, vergleicht sie nach ihren Pixeln und hält, wenn sie sich geändert haben, einen Pull Request, *docs: update the animation of the demo*, der sich selbst mergt, sobald jede Prüfung besteht.

```bash
bash demo/seed.sh
bash scripts/screenshots.sh --demo
```

Selbst ausführen musst du es selten. Für einen Pull Request, der eine Datei von `.github/screenshots-paths.txt` ändert, etwa das CSS oder ein Template, nimmt die visuelle Prüfung die Bilder auf und kommentiert die, die anders aussehen: vorher, nachher und die geänderten Pixel in Rot. Nach dem Merge, nach jedem Release und jede Woche nimmt der Screenshot-Bot sie auf `main` auf und hält einen Pull Request, *docs: update the screenshots*, der sich selbst mergt, sobald jede Prüfung besteht.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/diagrams/renewing-the-pictures.de.dark.svg">
  <img src="docs/images/diagrams/renewing-the-pictures.de.svg" alt="Wie die Bilder neu entstehen: Ein Pull Request, der eine Datei von screenshots-paths.txt ändert, etwa das CSS oder ein Template, startet die visuelle Prüfung. scripts/screenshots.sh nimmt jeden Screenshot und die Social Preview aus der Demo auf, mit derselben Uhr und denselben Daten in jedem Lauf, und der Vergleich des Screenshot-Bots behält jedes Bild, dessen Pixel gleich geblieben sind. Haben sich Pixel geändert, nennt ein Kommentar am Pull Request die Bilder, mit einem Artefakt, das jedes vorher und nachher zeigt und die geänderten Pixel in Rot. Nach dem Merge, nach jedem Release, jede Woche und auf Wunsch nimmt der Screenshot-Bot die Bilder ebenso auf main auf. Haben sich Pixel geändert, hält er einen Pull Request, docs: update the screenshots, der sich selbst mergt, sobald jede Prüfung besteht; ist die Social Preview darunter, bittet er, sie in den Einstellungen des Repositorys hochzuladen.">
</picture>

Lies [CONTRIBUTING.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/CONTRIBUTING.md), bevor du einen Pull Request öffnest.

### Releases

Der geschützte Branch `main` verlangt die Prüfungen der CI, die Docker-End-to-End-Tests gegen jede unterstützte Version von Nextcloud, das Dependency Review, CodeQL, den Secret Scan, die Lizenzen jeder Datei (REUSE), die Abzeichnung jedes Commits und einen Titel als Conventional Commit. Dependabot hält die Abhängigkeiten aktuell; Routine-Updates mergen von selbst, sobald jede Prüfung besteht.

Der Release-Bot führt einen Pull Request für das nächste Release. Seine Version folgt aus den Titeln der gemergten Pull Requests, und was sie unter *Unreleased* in `CHANGELOG.md` geschrieben haben, wird zu seinen Notizen. Sein Merge veröffentlicht das Release: das Paket, vor und nach dem Signieren mit dem Zertifikat der App geprüft, seine abgetrennte Signatur, eine SPDX-SBOM und signierte Build-Provenienz, dann dasselbe Paket im Nextcloud App Store, danach so geprüft, wie Benutzer es prüfen können. Siehe [die Anleitung der Releases](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/docs/releases.md).

Die Entscheidungen des Projekts und was du von der Unterstützung erwarten kannst, beschreiben [GOVERNANCE.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/GOVERNANCE.md), [SUPPORT.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/SUPPORT.md) und [CODE_OF_CONDUCT.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/CODE_OF_CONDUCT.md).

## Lizenz

AGPL-3.0-or-later. Siehe [LICENSE](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/LICENSE).
