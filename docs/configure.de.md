---
description: "Paperless Unified Search mit Paperless-ngx verbinden: ein Paperless-Konto, das nur ansehen darf, und sein API-Token, die Einstellungen, Paperless im lokalen Netzwerk und das nur lesend geteilte Archiv."
---

# Einrichten

Drei Dinge lassen Paperless-Dokumente in der Suche von Nextcloud erscheinen: ein Paperless-Konto, das sie ansehen darf und dessen API-Token in die Einstellungen der App kommt; die Dokumente als Dateien eines Nextcloud-Kontos, des Archiv-Kontos; und Freigaben dieses Archivs für die Benutzer, die sie finden dürfen. Diese Seite richtet alle drei ein, sobald die App [installiert](install.de.md) ist.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/setting-up-the-search.de.dark.svg">
  <img src="images/diagrams/setting-up-the-search.de.svg" alt="Die Suche einrichten: Installiere die App. Lege in Paperless-ngx ein Konto an, das die Dokumente lesen darf, und ein API-Token dafür. Gib in den Verwaltungseinstellungen von Nextcloud die URL, das Token und das Archiv-Konto ein, wähle Paperless läuft in meinem lokalen Netzwerk, wenn Nextcloud Paperless unter einer solchen Adresse erreicht, und wähle Verbindung testen und speichern. Antwortet Paperless, sind die Einstellungen gespeichert; sonst wird nichts gespeichert, und die Meldung sagt, was zu korrigieren ist. Das Archiv-Konto teilt sein Archiv nur lesend mit den Benutzern, die seine Dokumente finden dürfen, und sie finden sie unter Paperless-Dokumente in der Suche von Nextcloud, mit eingeschaltetem Verbundene Dienste durchsuchen.">
</picture>

--8<-- "README.de.md:how-it-works"

## Ein Paperless-Konto für die Suche

Die Suche fragt Paperless mit dem API-Token eines Paperless-Kontos, und Paperless antwortet mit den Dokumenten, die dieses Konto ansehen darf, und keinen anderen. Das Konto setzt also die Grenze dessen, was irgendjemand in Nextcloud finden kann: Ein Dokument, das es nicht ansehen kann, erscheint nie, wer auch immer sucht. Gib der Suche ein eigenes Paperless-Konto, das nur Dokumente ansehen darf, damit ein Token, das in falsche Hände gerät, nichts ändern kann.

1. **Das Konto.** Öffne als Administrator von Paperless **Benutzer & Gruppen** (*Users & Groups*) und füge einen Benutzer hinzu, etwa `nextcloud`, der weder Superuser noch Mitarbeiter (*staff*) ist, mit der Berechtigung **Anzeigen** (*View*) für **Dokumente** und keiner anderen. Die Suche liest nichts anderes: Sie fragt nach den Dokumenten zu einem Begriff und von jedem nur nach der ID, dem Titel, dem Datum, den IDs seines Korrespondenten und seines Typs und dem Ausschnitt um den Begriff.
2. **Seine Dokumente.** Paperless lässt einen Benutzer seine eigenen Dokumente ansehen, die ohne Eigentümer und die, deren Berechtigungen dem Benutzer oder einer seiner Gruppen das Ansehen erlauben. Dokumente eines anderen Eigentümers brauchen die Berechtigung zum Ansehen für das Konto oder für eine Gruppe, in der es ist:
    - für neue Dokumente ein **Workflow** von Paperless mit dem Auslöser *Dokument hinzugefügt* (*Document Added*) und einer Aktion *Zuweisung* (*Assignment*), die dem Konto oder seiner Gruppe die Berechtigung zum Ansehen gibt;
    - für die Dokumente, die Paperless schon hat, wähle sie in der Liste der Dokumente aus und setze die Berechtigungen mit der Massenbearbeitung unter **Berechtigungen** (*Permissions*).
3. **Sein Token.** Melde dich als das Konto bei Paperless an, öffne **Mein Profil** (*My Profile*) im Menü des Kontos und lege das Token mit dem Knopf neben **API Auth Token** an. Ein Administrator von Paperless kann es auch in der Django-Verwaltung von Paperless unter `/admin/` bei *Auth Token* für das Konto anlegen.

Um zu sehen, was die Suche höchstens finden kann, melde dich als das Konto bei Paperless an und suche dort: Die Suche von Nextcloud findet dieselben Dokumente, von denen jeder Benutzer dann die sieht, deren Dateien er öffnen kann. Ein Token eines Superusers funktioniert auch, aber wer es in die Hände bekommt, kann auch jedes Dokument ändern und löschen: Ein Konto, das nur ansehen darf, ist die sicherere Wahl.

--8<-- "README.de.md:configuration"

### Wenn der Test der Verbindung fehlschlägt

**Verbindung testen und speichern** speichert nichts, bis Paperless antwortet, und die Meldung sagt, was fehlgeschlagen ist. Die [Fehlerbehebung](troubleshooting.de.md#wenn-der-test-der-verbindung-fehlschlägt) listet jede Meldung mit dem, was dagegen zu tun ist.

--8<-- "README.de.md:languages"

## Das Archiv nur lesend teilen

Das Archiv-Konto entscheidet, wer welches Dokument findet: Ein Benutzer sieht ein Paperless-Dokument, wenn das Archiv-Konto seine Datei mit ihm teilt, allein oder mit einem Ordner darum. Teile nur lesend, damit niemand eine Datei ändert, verschiebt oder löscht, die die Synchronisierung schreibt.

1. Melde dich als Archiv-Konto bei Nextcloud an, etwa als `paperless`, und öffne **Dateien**.
2. Öffne die Details des Ordners des Archivs, etwa *Paperless*, und dort den Reiter **Teilen**.
3. Gib den Benutzer oder die Gruppe ein und stelle die Freigabe auf **Nur anzeigen**.

<figure markdown="span">
  ![Der Reiter Teilen des Ordners Paperless des Archiv-Kontos in Dateien: Der Ordner ist mit Jamie Example geteilt, nur anzeigen](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-share-read-only.png#only-light)
  ![Der Reiter Teilen des Ordners Paperless des Archiv-Kontos in Dateien: Der Ordner ist mit Jamie Example geteilt, nur anzeigen](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-share-read-only-dark.png#only-dark)
  <figcaption>Das Archiv, nur lesend mit einem Benutzer geteilt</figcaption>
</figure>

- **Eine Gruppe** gibt jedem Mitglied die Dokumente auf einmal, auch denen, die später beitreten.
- **Ein Unterordner**, etwa `Paperless/ACME`, gibt jemandem nur die Dokumente darin; Paperless Sync sortiert die Dokumente in Ordner nach Korrespondent, Typ und Jahr.
- **Das Archiv-Konto** findet jedes Dokument ohne Freigabe. Ein Administrator von Nextcloud findet nur, was mit ihm geteilt ist, wie jeder Benutzer: Administrator zu sein öffnet keine Datei.

Ein Skript teilt über die OCS-API von Nextcloud, mit einem [App-Passwort](https://docs.nextcloud.com/server/latest/user_manual/en/session_management.html#managing-devices) des Archiv-Kontos: `shareType` ist `0` für einen Benutzer und `1` für eine Gruppe, und `permissions=1` ist nur lesend.

```sh
curl -u paperless -H 'OCS-APIRequest: true' \
  -d path=/Paperless -d shareType=1 -d shareWith=accounting -d permissions=1 \
  'https://cloud.example.com/ocs/v2.php/apps/files_sharing/api/v1/shares'
```

--8<-- "README.de.md:any-sync"

## Prüfen, ob es funktioniert

1. Melde dich als ein Benutzer an, mit dem das Archiv geteilt ist, oder als Archiv-Konto.
2. Öffne die Suche von Nextcloud und suche nach einem Wort aus dem Text eines Dokuments, das nicht in seinem Dateinamen steht.
3. Schalte **Verbundene Dienste durchsuchen** ein: Das Dokument erscheint unter **Paperless-Dokumente**, und ein Klick darauf öffnet seine Datei.

<figure markdown="span">
  ![Die Einstellungen nach einem erfolgreichen Test der Verbindung: die URL, das gespeicherte Token, das Archiv-Konto und die Meldung Connection successful. Settings saved.](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings.png#only-light)
  ![Die Einstellungen nach einem erfolgreichen Test der Verbindung: die URL, das gespeicherte Token, das Archiv-Konto und die Meldung Connection successful. Settings saved.](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/03-admin-settings-dark.png#only-dark)
  <figcaption>Die Einstellungen nach einem erfolgreichen Test der Verbindung</figcaption>
</figure>

Keine Paperless-Dokumente? Die [Fehlerbehebung](troubleshooting.de.md) findet die Ursache Schritt für Schritt.
