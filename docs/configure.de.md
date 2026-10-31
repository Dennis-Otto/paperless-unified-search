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

Die Suche fragt Paperless mit dem API-Token eines Paperless-Kontos, und Paperless antwortet mit den Dokumenten, die dieses Konto ansehen darf, und keinen anderen. Das Konto setzt also die Grenze dessen, was irgendjemand in Nextcloud finden kann: Ein Dokument, das es nicht ansehen kann, erscheint nie, wer auch immer sucht. Gib der Suche ein eigenes Paperless-Konto, das nur Dokumente ansehen darf, damit ein Token, das in falsche Hände gerät, nichts ändern kann. Vier Schritte in Paperless richten es ein, und die Bilder zeigen sie in Paperless-ngx 3.

### Das Konto

Öffne als Administrator von Paperless **Benutzer & Gruppen** (*Users & Groups*) und füge einen Benutzer hinzu, etwa `nextcloud`, der weder **Superuser** noch **Admin** (*staff*) ist. Gib ihm unter **Berechtigungen** (*Permissions*) **Anzeigen** (*View*) für **Dokument** (*Document*), **Korrespondent** (*Correspondent*), **Dokumenttyp** (*DocumentType*) und **UI-Einstellungen** (*UISettings*) und nichts anderes, wie das Bild bei Schritt 1 der *Konfiguration* weiter unten zeigt. Die Suche liest nichts anderes: Sie fragt nach den Dokumenten zu einem Begriff und von jedem nur nach der ID, dem Titel, dem Datum, den IDs seines Korrespondenten und seines Typs und dem Ausschnitt um den Begriff; nach den Namen dieser Korrespondenten und Typen, die die Treffer zeigen; und beim Test der Verbindung danach, was das Konto darf, was Paperless nur einem Konto sagt, das seine UI-Einstellungen ansehen darf. [Was jede Berechtigung der Suche gibt](#was-jede-berechtigung-der-suche-gibt) sagt, was jede von ihnen bewirkt.

### Seine Dokumente

Paperless lässt einen Benutzer seine eigenen Dokumente ansehen, die ohne Eigentümer und die, deren Berechtigungen dem Benutzer oder einer seiner Gruppen das Ansehen erlauben. Dokumente eines anderen Eigentümers, etwa jedes Dokument, das ein Administrator hochlädt, brauchen die Berechtigung zum Ansehen für das Konto oder für eine Gruppe, in der es ist:

- **Neue Dokumente:** Öffne **Workflows** und füge einen Workflow hinzu mit dem Auslöser *Dokument hinzugefügt* (*Document Added*) und einer Aktion *Zuweisung* (*Assignment*), die das Konto oder seine Gruppe unter **Anzeigeberechtigungen zuweisen** (*Assign view permissions*) nennt. Paperless lässt das Konto dann jedes Dokument ansehen, sobald es das Dokument hinzufügt.
- **Die Dokumente, die Paperless schon hat:** Wähle sie in der Liste der Dokumente aus und gib dem Konto mit der Massenbearbeitung unter **Berechtigungen** (*Permissions*) die Berechtigung, sie anzusehen, mit eingeschaltetem **Mit bestehenden Berechtigungen zusammenführen** (*Merge with existing permissions*), das die Berechtigungen behält, die sie schon haben.

<figure markdown="span">
  ![Der Dialog Edit workflow von Paperless-ngx: der Workflow Let the Nextcloud search view new documents, eingeschaltet, mit dem Auslöser Document Added und der Aktion Assignment, deren Assign view permissions den Benutzer nextcloud nennen](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/15-paperless-workflow.png#only-light){ width="360" }
  ![Der Dialog Edit workflow von Paperless-ngx: der Workflow Let the Nextcloud search view new documents, eingeschaltet, mit dem Auslöser Document Added und der Aktion Assignment, deren Assign view permissions den Benutzer nextcloud nennen](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/15-paperless-workflow-dark.png#only-dark){ width="360" }
  <figcaption>Ein Workflow, der das Konto jedes neue Dokument ansehen lässt</figcaption>
</figure>

### Die Namen von Korrespondenten und Typen

Die Berechtigung, Korrespondenten und Dokumenttypen anzusehen, wirkt wie die für Dokumente: Das Konto sieht die Namen derer, die ihm gehören, die keinen Eigentümer haben, und derer, die es ansehen darf. Wer einen Korrespondenten oder einen Typ in der Weboberfläche von Paperless anlegt, dem gehört er. Bis das Konto sie ansehen darf, zeigen die Treffer und das Dashboard also keinen Korrespondenten und keinen Typ. Die Einstellungen warnen davor, wenn die neuesten Dokumente einen nennen, den das Konto nicht sehen kann:

- **Die Korrespondenten und Typen, die Paperless schon hat:** Öffne **Korrespondenten** (*Correspondents*), wähle alle aus, dann **Berechtigungen** (*Permissions*), füge das Konto unter **Anzeigen** (*View*), **Benutzer** (*Users*) hinzu und wähle **Bestätigen** (*Confirm*) mit eingeschaltetem **Mit bestehenden Berechtigungen zusammenführen** (*Merge with existing permissions*). Mach dann dasselbe unter **Dokumenttypen** (*Document types*).
- **Neue:** Jeder Administrator von Paperless fügt das Konto unter **Einstellungen** (*Settings*), **Berechtigungen** (*Permissions*), **Standard-Anzeigeberechtigungen** (*Default View Permissions*), **Benutzer** (*Users*) hinzu. Paperless lässt es dann jeden Korrespondenten und jeden Typ ansehen, den er in der Weboberfläche anlegt.

<figure markdown="span">
  ![Der Dialog Set permissions von Paperless-ngx für alle ausgewählten Korrespondenten: kein Eigentümer, View für den Benutzer nextcloud, kein Edit, und Merge with existing permissions eingeschaltet](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/16-paperless-names.png#only-light){ width="360" }
  ![Der Dialog Set permissions von Paperless-ngx für alle ausgewählten Korrespondenten: kein Eigentümer, View für den Benutzer nextcloud, kein Edit, und Merge with existing permissions eingeschaltet](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/16-paperless-names-dark.png#only-dark){ width="360" }
  <figcaption>Die Korrespondenten, die Paperless schon hat, zum Ansehen für das Konto</figcaption>
</figure>

### Sein Token

Melde dich als das Konto bei Paperless an, öffne **Mein Profil** (*My Profile*) im Menü des Kontos und lege das Token mit dem Knopf neben **API Auth Token** an; der Knopf davor kopiert das Token. Ein Administrator von Paperless kann es auch in der Django-Verwaltung von Paperless unter `/admin/` bei *Auth Token* für das Konto anlegen.

<figure markdown="span">
  ![Der Dialog Edit Profile des Kontos nextcloud in Paperless-ngx: sein API Auth Token, mit den Knöpfen, die es kopieren und ein neues anlegen](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/17-paperless-token.png#only-light){ width="360" }
  ![Der Dialog Edit Profile des Kontos nextcloud in Paperless-ngx: sein API Auth Token, mit den Knöpfen, die es kopieren und ein neues anlegen](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/17-paperless-token-dark.png#only-dark){ width="360" }
  <figcaption>Das API-Token des Kontos, in Mein Profil</figcaption>
</figure>

### Was jede Berechtigung der Suche gibt

| Berechtigung des Kontos | Was die Suche damit tut | Ohne sie |
| --- | --- | --- |
| **Anzeigen** für **Dokument** | Findet die Dokumente, die das Konto ansehen darf, mit den Worten um den Begriff, auch die, deren Notiz den Begriff hat, etwa `notes:transfer` | Paperless lehnt das Token ab, und der Test der Verbindung schlägt fehl |
| **Anzeigen** für **Korrespondent** | Zeigt den Korrespondenten jedes Treffers und jedes Dokuments im Dashboard, wenn das Konto diesen Korrespondenten ansehen darf | Kein Korrespondent in den Treffern und im Dashboard, und die Einstellungen warnen |
| **Anzeigen** für **Dokumenttyp** | Zeigt den Typ jedes Treffers und jedes Dokuments im Dashboard, wenn das Konto diesen Typ ansehen darf | Kein Typ in den Treffern und im Dashboard, und die Einstellungen warnen |
| **Anzeigen** für **UI-Einstellungen** | Lässt den Test der Verbindung Paperless fragen, was das Konto darf, für die Warnungen zum Token | Gar keine Warnungen, und `occ paperless_unified_search:check` sagt, dass Paperless es nicht verrät |
| **Anzeigen** für **Notiz** (*Note*) | Nichts: Die Notizen eines Dokuments passen auch ohne sie | Es fehlt nichts |
| **Hinzufügen**, **Ändern** oder **Löschen** | Nichts: Die Suche liest nur | Es fehlt nichts; die Einstellungen warnen vor jeder davon für Dokumente und davor, Benutzer hinzuzufügen oder zu ändern oder Gruppen zu ändern, da wer ein solches Token in die Hände bekommt, auch Dokumente ändern kann |

Die Vertragstests prüfen diese Tabelle jede Woche und in jedem Pull Request, der die App ändert, gegen echtes Paperless-ngx 2.20 und 3.x, zusammen mit dem Workflow und den Berechtigungen der Schritte oben.

Um zu sehen, was die Suche höchstens finden kann, melde dich als das Konto bei Paperless an und suche dort: Die Suche von Nextcloud findet dieselben Dokumente, von denen jeder Benutzer dann die sieht, deren Dateien er öffnen kann. Ein Token eines Superusers funktioniert auch, aber wer es in die Hände bekommt, kann auch jedes Dokument ändern und löschen: Ein Konto, das nur ansehen darf, ist die sicherere Wahl. Der Test der Verbindung sagt es dir: Die Einstellungen warnen unter dem Token vor einem Konto, das ein Superuser oder ein Admin ist oder Dokumente hinzufügen, ändern oder löschen darf, und vor einem, das keine Korrespondenten oder Dokumenttypen ansehen darf oder nicht die der neuesten Dokumente, die einem anderen Benutzer gehören, und die [Fehlerbehebung](troubleshooting.de.md#warnungen-zum-api-token) sagt, was bei jeder Warnung zu tun ist.

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
- **Nur was ein Benutzer nur lesen darf, zählt:** Ein Benutzer, der eine Datei des Archivs ändern oder Dateien in ihren Ordner legen darf, findet keines der Dokumente darin, denn er könnte einer Datei die Kennung eines beliebigen Dokuments geben. Unter dem Archiv-Konto zeigen die Einstellungen, mit wem es seine Dateien teilt, etwa *Nur lesend geteilt: 1 Benutzer, 2 Gruppen.*, und warnen vor jeder Freigabe, die Änderungen erlaubt, ebenso `occ paperless_unified_search:check` und die Übersicht der Verwaltungseinstellungen.

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
