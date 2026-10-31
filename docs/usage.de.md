---
description: "Paperless-Dokumente in Nextcloud suchen: im Browser, auf dem Smartphone, im Smart Picker von Talk, Text, Deck und Mail, mit der Suchsyntax von Paperless und aus Skripten über die API, und die neuesten auf dem Dashboard sehen."
---

# Suchen

Paperless-Dokumente erscheinen in der globalen Suche von Nextcloud für jeden Benutzer, der ihre Dateien öffnen kann: unter **Paperless-Dokumente**, nach den Dateien, jedes mit seinem Titel, seinem Datum und den Wörtern um den Begriff. Ein Klick auf eines öffnet seine Datei in Nextcloud.

<figure markdown="span">
  ![Paperless-Treffer in der globalen Suche von Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search.png#only-light)
  ![Paperless-Treffer in der globalen Suche von Nextcloud](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/01-unified-search-dark.png#only-dark)
  <figcaption>Paperless-Dokumente in der Suche, nach den Dateien</figcaption>
</figure>

--8<-- "README.de.md:usage"

<figure markdown="span">
  ![Die Suche nach invoice mit eingeschaltetem Schalter Verbundene Dienste durchsuchen und dem Datumsfilter auf dem eigenen Bereich vom 7/1/2026 bis zum 7/31/2026: Paperless-Dokumente listet die Verlängerung der Hausratversicherung vom Jul 21, 2026 und die Internetrechnung vom Jul 3, 2026, aber nicht die Stromrechnung vom August, und Dateien findet keine der Dateien, die später hinzugefügt wurden](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/12-date-filter.png#only-light)
  ![Die Suche nach invoice mit eingeschaltetem Schalter Verbundene Dienste durchsuchen und dem Datumsfilter auf dem eigenen Bereich vom 7/1/2026 bis zum 7/31/2026: Paperless-Dokumente listet die Verlängerung der Hausratversicherung vom Jul 21, 2026 und die Internetrechnung vom Jul 3, 2026, aber nicht die Stromrechnung vom August, und Dateien findet keine der Dateien, die später hinzugefügt wurden](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/12-date-filter-dark.png#only-dark)
  <figcaption>Die Suche, mit einem eigenen Bereich auf Juli 2026 eingegrenzt</figcaption>
</figure>

--8<-- "README.de.md:what-a-result-shows"

--8<-- "README.de.md:smart-picker"

<figure markdown="span">
  ![Der Smart Picker in einer Notiz von Text: Unter Paperless-Dokumente listet die Suche nach invoice die Stromrechnung, die Verlängerung der Hausratversicherung und die Internetrechnung](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker.png#only-light)
  ![Der Smart Picker in einer Notiz von Text: Unter Paperless-Dokumente listet die Suche nach invoice die Stromrechnung, die Verlängerung der Hausratversicherung und die Internetrechnung](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/07-smart-picker-dark.png#only-dark)
  <figcaption>Der Smart Picker in einer Notiz von Text</figcaption>
</figure>

<figure markdown="span">
  ![Die Notiz von Text, nachdem die Stromrechnung gewählt wurde: Der eingefügte Link zeigt das PDF der Rechnung](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/08-smart-picker-link.png#only-light)
  ![Die Notiz von Text, nachdem die Stromrechnung gewählt wurde: Der eingefügte Link zeigt das PDF der Rechnung](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/08-smart-picker-link-dark.png#only-dark)
  <figcaption>Der eingefügte Link zeigt die Datei</figcaption>
</figure>

--8<-- "README.de.md:dashboard"

<figure markdown="span">
  ![Das Dashboard von Jamie mit dem Widget Neu in Paperless: die Inspektion des Autos, die Stromrechnung, die Verlängerung der Hausratversicherung, die Internetrechnung und der Grundsteuerbescheid, die neuesten zuerst, jedes mit dem Symbol seiner PDF-Datei und einer Zeile mit dem Tag, an dem Paperless es hinzugefügt hat, seinem Korrespondenten, seinem Typ und seinen Tags, etwa Sep 3, 2026 · Example Motors · Receipt · warranty](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/11-dashboard.png#only-light)
  ![Das Dashboard von Jamie mit dem Widget Neu in Paperless: die Inspektion des Autos, die Stromrechnung, die Verlängerung der Hausratversicherung, die Internetrechnung und der Grundsteuerbescheid, die neuesten zuerst, jedes mit dem Symbol seiner PDF-Datei und einer Zeile mit dem Tag, an dem Paperless es hinzugefügt hat, seinem Korrespondenten, seinem Typ und seinen Tags, etwa Sep 3, 2026 · Example Motors · Receipt · warranty](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/11-dashboard-dark.png#only-dark)
  <figcaption>Neu in Paperless auf dem Dashboard von Nextcloud</figcaption>
</figure>

## Warum der Schalter nach dem Neuladen aus ist

**Verbundene Dienste durchsuchen** entscheidet, ob eine Suche Nextcloud verlässt. Nextcloud zählt jede Suche, die den Begriff an einen anderen Server schickt, als eine eines verbundenen Dienstes, auch Paperless, und fragt sie nur, solange der Schalter an ist. Es schaltet den Schalter aus, sobald die Seite neu lädt, damit kein Begriff ohne die Wahl des Benutzers an einen anderen Server geht, immer wieder.

Es gibt zwei Wege darum herum, und beide schicken die Begriffe mit jeder Suche an Paperless:

- **Für dich selbst:** Wähle unter **Persönliche Einstellungen → Paperless** **Meine Paperless-Dokumente immer durchsuchen**, wie *Deine Paperless-Dokumente immer durchsuchen* oben zeigt. Nur deine eigenen Suchen ändern sich, ab der nächsten Seite, die du öffnest.
- **Für jeden Benutzer:** Ein Administrator, der seinem Paperless-Server vertraut, als dem, der die Dokumente ohnehin hält, kann in den Einstellungen **Paperless immer in die globale Suche einbeziehen** wählen. Paperless gehört dann zu jeder Suche jedes Benutzers, ohne den Schalter, der es nicht mehr steuert, und die persönlichen Einstellungen zeigen das Kästchen angehakt und ausgegraut. Jeder Benutzer lädt Nextcloud nach der Änderung einmal neu.

## Auf dem Smartphone

- **Im Browser** eines Smartphones funktioniert die Suche wie auf einem Computer, und ein Treffer öffnet sich im Viewer von Nextcloud.
- **Die Nextcloud-Apps für iOS und Android** zeigen Paperless-Dokumente ebenfalls in ihrer Suche. Ein Treffer öffnet die Datei im Viewer der App, ohne sie zu verlassen: Die iOS-App bekommt einen Link `nextcloud://open-file`, die Android-App die ID und den Pfad der Datei.

<figure markdown="span" class="phones">
  ![Paperless-Treffer in der globalen Suche von Nextcloud auf einem Smartphone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/04-mobile-search.png){ width="180" }
  ![Die Stromrechnung, geöffnet im Viewer von Nextcloud auf einem Smartphone](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/05-mobile-viewer.png){ width="180" }
  <figcaption>Auf einem Smartphone, im Browser</figcaption>
</figure>

--8<-- "README.de.md:search-syntax"

--8<-- "README.de.md:scripts"
