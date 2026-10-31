---
description: "Was Paperless Unified Search bis Oktober 2027 vorhat, etwa jeder neuen Hauptversion von Nextcloud und der API von Paperless-ngx zu folgen, und was es nicht tun wird."
---

# Roadmap

[← README](https://github.com/Dennis-Otto/paperless-unified-search) · [Vergleich](compare.de.md) · [Architecture](architecture.md) · [Security design](security.md) · [Releases](releases.md)

Was Paperless Unified Search in den nächsten zwölf Monaten vorhat, bis Oktober 2027, und was es nicht tun wird. Das ist eine Richtung, kein Versprechen. Ideen sind als [Feature Requests](https://github.com/Dennis-Otto/paperless-unified-search/issues/new?template=feature_request.yml) willkommen.

## Die nächsten zwölf Monate

Die App tut, wofür sie gebaut wurde: die Volltextsuche von Paperless-ngx in der Suche von Nextcloud, eingegrenzt durch ihren Datumsfilter, mit Treffern, die die Vorschau, das Datum, den Korrespondenten, den Typ und die Tags jedes Dokuments zeigen und die synchronisierten Dateien öffnen, im Smart Picker von Talk, Text, Deck und Mail, der den Link auf eine solche Datei einfügt, und auf dem Dashboard, das die neuesten Dokumente auflistet, die ein Benutzer öffnen kann, und für Administratoren **Suche ausprobieren** in den Einstellungen, das zeigt, warum ein Dokument einem Benutzer verborgen bleibt. Eine Demo mit einem Befehl, mit echtem Paperless-ngx und Nextcloud und Beispieldokumenten, lässt jeden sie ausprobieren, bevor er sie installiert, und die README und die Website erklären alles auf Englisch und Deutsch, während die App selbst auch Französisch, Spanisch und Niederländisch spricht. Das Jahr baut darauf auf und hält sie, wie jedes Jahr, funktionsfähig und sicher:

- **Jede neue Hauptversion von Nextcloud.** Der Upstream-Bot hebt `max-version` in `appinfo/info.xml` an, sobald die End-to-End-Tests gegen die neue Version bestehen, und die App folgt den Änderungen der Suche von Nextcloud, seines Smart Pickers und seiner Mobil-Apps.
- **Änderungen der API von Paperless-ngx.** Vertragstests gegen echtes Paperless-ngx 2.20 und 3.x laufen jede Woche, mit jedem neuen Release von Paperless-ngx und in jedem Pull Request, der die App ändert und sie bestehen muss, und fangen eine Änderung, die die Suche bricht; sie wird zuerst behoben, mit einem Test, der sie behoben hält. Die App fragt Paperless nach Version 9 seiner API, damit eine neue Standardversion von Paperless nicht ändern kann, was die App liest; eine neuere Version ist ein bewusster Schritt, mit diesen Tests.
- **Fehlerbehebungen und Sicherheit.** Gemeldete Fehler und Schwachstellen kommen vor allem Neuen; [SECURITY.md](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/SECURITY.md) nennt die Fristen.
- **Aktuelle Abhängigkeiten und Werkzeuge,** über die Update-Bots und den [Repository-Blueprint](https://github.com/Dennis-Otto/repo-blueprint).

Feature Requests mit den meisten Reaktionen kommen als Nächstes an die Reihe, in dieser Reihenfolge: zuerst alles, was für Benutzer kaputtgeht, dann, was die Suche sicherer macht, dann der Rest.

## Nicht geplant

- **Dokumente synchronisieren.** Das ist [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync), eine eigene App mit eigenen Releases.
- **Ein eigener Index.** Paperless beantwortet jede Suche; die App speichert keine Dokumente und keinen Text von ihnen. Der [Vergleich](compare.de.md) sagt, warum.
- **Eine semantische Suche.** Dokumente über Umschreibungen zu finden braucht einen Index aus Vektoren; [Findling oder Context Chat](compare.de.md#wann-welche-passt) bauen einen auf.
- **Dokumente in Paperless öffnen.** Ein Treffer öffnet die Datei in Nextcloud, dessen Berechtigungen entscheiden, wer sie lesen darf.
- **Eigene Vorschauen für die Links des Smart Pickers.** Nextcloud stellt den Link auf eine Datei mit den Berechtigungen dessen dar, der ihn liest, was eine eigene App nur schwächen könnte.
