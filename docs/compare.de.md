---
description: "Wie sich Paperless Unified Search mit Full text search und Elasticsearch, Workflow OCR, der Paperless integration, Context Chat, Findling und der Weboberfläche von Paperless-ngx vergleicht und wann eine andere die bessere Wahl ist."
---

# Vergleich

[← README](https://github.com/Dennis-Otto/paperless-unified-search) · [Einrichten](configure.de.md) · [Architecture](architecture.md) · [Roadmap](roadmap.de.md)

Paperless-ngx erkennt den Text jedes Dokuments und führt einen Index seiner Wörter. Zwei Wege bringen das in die Suche von Nextcloud: den Index von Paperless fragen, wie es Paperless Unified Search tut, oder Nextcloud einen zweiten Index der synchronisierten Dateien aufbauen lassen. Diese Seite vergleicht die beiden und jede App, die einen davon geht, Stand Oktober 2026, nach den Beschreibungen der Apps im Nextcloud App Store und ihrer Dokumentation. Eine Korrektur ist als [Issue](https://github.com/Dennis-Otto/paperless-unified-search/issues) willkommen.

## Ein Index oder zwei

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/one-index-or-two.de.dark.svg">
  <img src="images/diagrams/one-index-or-two.de.svg" alt="Ein Index oder zwei: Mit Paperless Unified Search gibt es einen Index. Die Suche von Nextcloud fragt Paperless-ngx, dessen OCR- und Volltextindex die Wörter jedes Dokuments schon enthält, und jedes passende Dokument öffnet seine synchronisierte Datei in Nextcloud. Mit einer eigenen Suche in Nextcloud, etwa Full text search mit Elasticsearch, Context Chat oder Findling, gibt es zwei. Die synchronisierten Dateien werden noch einmal gelesen, Scans noch einmal erkannt, und ihre Wörter ein zweites Mal gespeichert, in einem Index mit eigenem Server oder Container, den die Suche von Nextcloud stattdessen fragt.">
</picture>

Ein Index heißt: nichts weiter zu betreiben, zu sichern oder aktuell zu halten, keine zweite Kopie des Textes der Dokumente und keine Verzögerung: Ein Dokument ist in Nextcloud zu finden, sobald Paperless es hat und seine Datei angekommen ist. Es heißt auch die Suche von Paperless selbst, mit ihrer Rangfolge, ihren Hervorhebungen und ihrer [Syntax](usage.de.md#suchen-wie-in-paperless), die den Korrespondenten, den Typ, die Tags und die Daten eines Dokuments kennt, nicht nur seine Wörter.

## Auf einen Blick

--8<-- "README.de.md:comparison"

## Die Alternativen einzeln

### Full text search mit Elasticsearch

Die Apps [Full text search](https://apps.nextcloud.com/apps/fulltextsearch), [Full text search - Files](https://apps.nextcloud.com/apps/files_fulltextsearch) und [Full text search - Elasticsearch Platform](https://apps.nextcloud.com/apps/fulltextsearch_elasticsearch) von Nextcloud indizieren den Inhalt jeder Datei in einem Elasticsearch-Server und fügen ihn der Suche von Nextcloud hinzu. Sie finden Wörter in allen Dateien von Nextcloud, nicht nur in den Dokumenten von Paperless, und beachten die Freigaben der Dateien.

Sie brauchen einen Elasticsearch-Server, mit eigenem Arbeitsspeicher, eigenen Updates und Sicherungen, und sein Index führt eine zweite Kopie des Textes jedes Dokuments. Ein Scan wird nur über seine Textebene gefunden: Die Archiv-PDFs von Paperless haben eine, ein ursprünglicher Scan hat keine, und die [Tesseract-App](https://apps.nextcloud.com/apps/files_fulltextsearch_tesseract), die solchen Text für den Index erkennt, unterstützt höchstens Nextcloud 32. Was Paperless über den Text hinaus weiß, etwa den Korrespondenten, den Typ und die Tags, steht nicht in diesem Index.

### Workflow OCR

[Workflow OCR](https://apps.nextcloud.com/apps/workflow_ocr) erkennt den Text von PDFs und Bildern in Nextcloud mit OCRmyPDF, auf dem Server installiert oder als sein Backend-Container, nach einer Regel der Abläufe von Nextcloud, und speichert ihn als Textebene in der Datei. Es hat keine eigene Suche: Eine andere, etwa Full text search, findet diesen Text. Für die Dokumente von Paperless wiederholt es die Erkennung, die Paperless schon erledigt hat.

### Die Paperless integration

Die App [Paperless](https://apps.nextcloud.com/apps/integration_paperless) von Nextcloud fügt Dateien eine Aktion hinzu, die eine Datei zu Paperless hochlädt. Sie hat keine Suche: [Ihr Issue #10](https://github.com/nextcloud/integration_paperless/issues/10) wünscht sich genau das, was Paperless Unified Search tut. Ihr neuestes Release unterstützt Nextcloud 28 bis 32. [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) lädt Dateien ebenfalls zu Paperless hoch, aus einem Eingangsordner in Nextcloud.

### Context Chat

[Context Chat](https://apps.nextcloud.com/apps/context_chat) lässt den Assistenten von Nextcloud Fragen zu den Dokumenten eines Benutzers mit einem Sprachmodell beantworten, aus einem Vektorindex ihres Textes. Er beantwortet Fragen, statt Dokumente aufzulisten, und versteht Umschreibungen. Er braucht AppAPI mit einem Deploy-Daemon, den Container Context Chat Backend, den Assistenten und einen Anbieter eines Sprachmodells, auf dem Server oder als entfernten Dienst. Er liest die Textebene eines PDFs und erkennt keine Scans.

### Findling

[Findling](https://apps.nextcloud.com/apps/findling) fügt der Suche von Nextcloud eine Volltext- und eine semantische Suche über alle Dateien hinzu, mit einer eigenen OCR für Scans und Bilder, und prüft die Berechtigungen jedes Treffers. Es braucht AppAPI und seinen Backend-Container, dessen Index den Text jeder Datei führt. Die Dokumente von Paperless werden dort ein zweites Mal erkannt und indiziert.

### Die Weboberfläche von Paperless-ngx

Die Weboberfläche von Paperless hat dieselbe Suche und viel mehr: Tags, Notizen, Workflows und Bearbeitung. Wer dort ein Dokument sieht, entscheiden die Konten und Berechtigungen von Paperless, nicht die Freigaben von Nextcloud, also braucht jeder Leser ein Konto in Paperless, und ein Dokument öffnet sich in Paperless, nicht in Nextcloud oder seinen Mobil-Apps.

## Wann welche passt

- **Paperless Unified Search**, wenn Paperless die Dokumente hält und Menschen sie dort finden sollen, wo sie schon arbeiten, in Nextcloud, mit den Freigaben von Nextcloud, die entscheiden, wer was sieht, und nichts weiter zu betreiben.
- **Findling oder Context Chat**, für eine semantische Suche, die Umschreibungen findet, oder für Dateien, die nie durch Paperless gehen, wo AppAPI und ein Backend-Container willkommen sind.
- **Full text search mit Elasticsearch**, um den Inhalt jeder Datei von Nextcloud zu durchsuchen, wo ohnehin ein Elasticsearch-Server läuft.
- **Workflow OCR**, zusammen mit einer Suche nach ihrem Text, um Scans durchsuchbar zu machen, die in Nextcloud landen und nie Paperless erreichen.
- **Die Paperless integration oder Paperless Sync**, um Dateien von Nextcloud an Paperless zu schicken.
- **Die Weboberfläche von Paperless**, um mit den Dokumenten zu arbeiten: sie zu taggen, zu bearbeiten, zu kommentieren und zu automatisieren. Paperless Unified Search findet sie; Paperless verwaltet sie.

## Gemeinsam nutzen

Paperless Unified Search fügt der Suche von Nextcloud einen weiteren Anbieter hinzu, **Paperless-Dokumente**, und verträgt sich mit den anderen: mit Findling oder Full text search für die Dateien, die nicht in Paperless liegen, und mit der Weboberfläche von Paperless für ihre Verwaltung. [Einrichten](configure.de.md#funktioniert-mit-jeder-synchronisierung) zeigt, wie jede Synchronisierung die Dateien von Paperless nach Nextcloud bringt.
