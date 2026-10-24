---
description: "Fragen zu Paperless Unified Search beantwortet: ohne Paperless Sync, was es speichert, wer welches Dokument sieht, die Mobil-Apps, warum Treffer sich in Nextcloud öffnen, die Last auf Paperless und die Sprachen."
---

# FAQ

## Funktioniert es ohne Paperless Sync?

Ja. Die Suche braucht jedes Dokument von Paperless als Datei des Archiv-Kontos mit der Kennung `[P<ID>]` im Namen, und jede Synchronisierung, die solche Dateien schreibt, funktioniert: die Dateinamen von Paperless selbst mit `{{ doc_pk }}`, rclone, der Desktop-Client von Nextcloud oder ein externer Speicher. [Funktioniert mit jeder Synchronisierung](configure.de.md#funktioniert-mit-jeder-synchronisierung) zeigt jeden davon. [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) ist die Begleit-App, die es innerhalb von Nextcloud erledigt, mit Ordnern nach Korrespondent, Typ und Jahr.

## Braucht es Elasticsearch oder die App Full text search?

Nein. Paperless hat den Text jedes Dokuments bereits erkannt und führt einen Index seiner Wörter; die App fragt diesen Index bei jeder Suche. Neben der App muss nichts installiert werden, kein zweiter Index aufgebaut oder aktuell gehalten werden, und es gibt keine zweite OCR. Der [Vergleich](compare.de.md) stellt sie neben die Apps, die einen Index führen.

## Speichert es meine Dokumente, ihren Text oder einen Index?

Nein. Die App speichert ihre Einstellungen, das API-Token verschlüsselt im Zugangsdatenmanager von Nextcloud, die Version von Paperless und den Verlauf der letzten 20 Probleme der Suche, ohne die Suchbegriffe und das Token. Die Dokumente bleiben in Paperless und ihre Dateien dort, wo die Synchronisierung sie schreibt. Die [Datenflüsse](reference.de.md#datenflüsse) listen, was wohin geht.

## Wer sieht welches Dokument?

Ein Benutzer sieht ein Paperless-Dokument, wenn er seine Datei in Nextcloud öffnen kann: Das Archiv-Konto sieht jedes, jeder andere Benutzer die, deren Dateien, oder einen Ordner darum, das Archiv-Konto mit ihm geteilt hat: Die Freigaben, die du schon kennst, sind die Berechtigungen. Das Paperless-Konto des API-Tokens setzt die Grenze: Ein Dokument, das es nicht ansehen darf, erscheint nie für irgendwen. Niemand braucht ein Konto in Paperless.

## Sehen Administratoren von Nextcloud jedes Dokument?

Nein, nur die, deren Dateien sie öffnen können, wie jeder Benutzer. Administrator zu sein öffnet keine Datei eines anderen Kontos.

## Gehen die Suchbegriffe an Paperless?

Nur wenn der Benutzer es will: mit eingeschaltetem **Verbundene Dienste durchsuchen**, nachdem er im Smart Picker **Paperless-Dokumente** gewählt hat, oder wenn ein Skript diese Suche über die API anfragt. Ein Administrator kann Paperless auch mit **Paperless immer in die globale Suche einbeziehen** vertrauen; dann geht jeder Begriff jeder Suche an Paperless. Die Begriffe reisen vom Nextcloud-Server zu Paperless, nie vom Browser, und die App protokolliert keinen davon.

## Funktioniert es in den Nextcloud-Apps für iOS und Android?

Ja. Die Apps zeigen Paperless-Dokumente in ihrer Suche, und ein Treffer öffnet die Datei im Viewer der App. Im Browser eines Smartphones funktioniert die Suche wie auf einem Computer. [Auf dem Smartphone](usage.de.md#auf-dem-smartphone) zeigt es.

## Warum öffnet sich ein Treffer in Nextcloud und nicht in Paperless?

Weil dort jeder, der das Dokument findet, es öffnen kann: Die Freigaben von Nextcloud entscheiden, wer die Datei sieht, und niemand braucht ein Konto in Paperless. Die Datei öffnet sich im Viewer von Nextcloud und in den Mobil-Apps, bereit zum Teilen, Herunterladen oder Kommentieren, und der Link des Smart Pickers zeigt die Datei jedem Leser, der sie öffnen kann.

## Kann ich neue Dokumente sehen, ohne zu suchen?

Ja: Füge **Neu in Paperless** zu deinem Dashboard hinzu. Es listet die Dokumente, die Paperless zuletzt hinzugefügt hat und die du öffnen kannst, die neuesten zuerst, jedes mit dem Tag, an dem Paperless es hinzugefügt hat, seinem Korrespondenten und seinem Typ, und öffnet die Datei eines Dokuments in Dateien. [Auf dem Dashboard](usage.de.md#auf-dem-dashboard) hat die Details.

## Warum schaltet sich der Schalter Verbundene Dienste durchsuchen von selbst aus?

Nextcloud schaltet ihn bei jedem Neuladen der Seite aus, damit ein Suchbegriff nie ohne die Wahl des Benutzers an einen anderen Server geht. Um deine Paperless-Dokumente ohne ihn in jeder Suche zu finden, wähle unter **Persönliche Einstellungen → Paperless** **Meine Paperless-Dokumente immer durchsuchen**. [Warum der Schalter nach dem Neuladen aus ist](usage.de.md#warum-der-schalter-nach-dem-neuladen-aus-ist) hat die Details und sagt, wie ein Administrator Paperless stattdessen in jede Suche jedes Benutzers einbezieht.

## Wie viel Last bringt es auf Paperless?

Das Widget des Dashboards fragt Paperless höchstens einmal in der Minute nach seinen neuesten Dokumenten, für alle Benutzer gemeinsam, sofern es einen Memory-Cache gibt, wie Nextcloud ihn empfiehlt. Darüber hinaus bekommt Paperless nur Anfragen von den Suchen, die es fragen: mit eingeschaltetem **Verbundene Dienste durchsuchen**, aus dem Smart Picker oder der API, von jeder Suche eines Benutzers, der **Meine Paperless-Dokumente immer durchsuchen** gewählt hat, und von jeder Suche, wenn **Paperless immer in die globale Suche einbeziehen** an ist. Die Suche von Nextcloud wartet, bis das Tippen pausiert, bevor sie fragt. Für jede Seite mit Treffern fragt die App Paperless nach 25 Dokumenten und nur nach den Feldern, die ein Treffer zeigt, nicht nach dem OCR-Text jedes Dokuments; um eine Seite zu füllen, deren erste Dokumente der Benutzer nicht öffnen kann, fragt sie innerhalb von 3 Sekunden nach bis zu 3 weiteren Seiten. Eine Anfrage in seiner Syntax, die Paperless 3 noch nicht verarbeiten kann, etwa eine, die noch getippt wird, bekommt es noch einmal in seinem einfachen Modus. Eine Anfrage, die sich nicht verbinden konnte, bekommt einen zweiten Versuch; eine, die vergeblich auf ein hängendes Paperless gewartet hat, wird nicht erneut gesendet.

## Durchsucht es die Dokumente im Posteingang von Paperless?

Nur die, die eine Datei mit ihrer Kennung im Archiv haben. Eine Synchronisierung, die nur die Dokumente außerhalb des Posteingangs schreibt, hält die anderen aus der Suche von Nextcloud heraus.

## Kann es mehr als ein Paperless durchsuchen?

Nein. Die Einstellungen halten ein Paperless, für alle Benutzer der Nextcloud. Sein Konto und die Freigaben des Archivs entscheiden, was jeder Benutzer findet.

## Welche Sprachen spricht es?

Die Einstellungen sprechen Englisch und Deutsch, Deutsch mit *du* oder mit *Sie*, wie es die Sprache des Kontos sagt. Die Suche findet Dokumente in jeder Sprache, die Paperless erkennt, denn Paperless durchsucht ihren Text. [Sprachen](configure.de.md#sprachen) hat die Details, und diese Website gibt es auf Englisch und, bis auf die Seiten des Projekts, auf Deutsch.

## Welche Versionen von Nextcloud und Paperless unterstützt es?

Nextcloud 33 bis 35 und Paperless-ngx mit seiner REST-API. Die [Kompatibilität](reference.de.md#kompatibilität) sagt, welche Versionen die Tests abdecken.

## Wo stelle ich eine Frage oder melde einen Fehler?

In den [Diskussionen](https://github.com/Dennis-Otto/paperless-unified-search/discussions) oder mit der Vorlage der [Issues](https://github.com/Dennis-Otto/paperless-unified-search/issues/new/choose), gern auf Englisch. Die [Fehlerbehebung](troubleshooting.de.md) hilft zuerst und sagt, was eine Meldung braucht.
