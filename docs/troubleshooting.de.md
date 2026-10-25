---
description: "Fehlerbehebung für Paperless Unified Search: warum Paperless-Dokumente nicht in der Suche von Nextcloud erscheinen, jede Meldung des Verbindungstests, Zertifikate, das lokale Netzwerk, Reverse Proxys, der Index von Paperless und die Probleme der Suche."
---

# Fehlerbehebung

Warum Paperless-Dokumente nicht erscheinen, und wie du es behebst. Fang dort an, wo dein Problem anfängt:

- [Keine Paperless-Dokumente in der Suche](#keine-paperless-dokumente-in-der-suche), oder nicht das, das du suchst.
- [Der Test der Verbindung schlägt fehl](#wenn-der-test-der-verbindung-fehlschlägt), wenn du die Einstellungen speicherst.
- [Eine Warnung zum API-Token](#warnungen-zum-api-token) erscheint unter dem Token.
- [Probleme der Suche](#probleme-der-suche) am Ende der Einstellungen listet fehlgeschlagene Suchen.
- [Die Suche ist langsam](#die-suche-ist-langsam).

Die Einstellungen zeigen den Benutzern keinen Fehler: Eine Suche, die fehlschlägt, zeigt ihnen keine Paperless-Dokumente, wie eine Suche, die keine findet. Deshalb führen die Einstellungen den Verlauf dieser Probleme für die Administratoren, unter **Probleme der Suche**, auch wenn der Hoster das Protokoll von Nextcloud vor ihnen verbirgt.

## Keine Paperless-Dokumente in der Suche

Sechs Fragen, eine nach der anderen, finden, warum ein Dokument für einen Benutzer nicht erscheint, hier Dokument 412 für `jamie`:

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/why-the-search-shows-no-paperless-documents.de.dark.svg">
  <img src="images/diagrams/why-the-search-shows-no-paperless-documents.de.svg" alt="Warum die Suche keine Paperless-Dokumente zeigt: jamie sucht nach Rechnung und sieht Dokument 412 nicht unter Paperless-Dokumente. Sechs Fragen, eine nach der anderen, finden die Ursache. Ist Verbundene Dienste durchsuchen eingeschaltet, oder ist Paperless in den Einstellungen vertrauenswürdig? Wenn nicht, schalte es ein, nach jedem Neuladen der Seite erneut. Gibt es ein Archiv-Konto in den Einstellungen? Wenn nicht, gib das Konto ein, dem die synchronisierten Dateien gehören. Listet Probleme der Suche in den Einstellungen fehlgeschlagene Suchen auf? Wenn ja, behebe den Fehler, den es nennt. Findet Paperless Dokument 412 zu dem Begriff, angemeldet als das Konto des API-Tokens? Wenn nicht, lass dieses Konto das Dokument ansehen, oder baue den Index von Paperless mit document_index reindex neu auf. Hat das Archiv-Konto eine Datei mit [P412] im Namen? Wenn nicht, lass die Synchronisierung sie schreiben, und scanne einen externen Speicher mit occ files:scan. Kann jamie diese Datei öffnen? Wenn nicht, teile die Datei oder ihren Ordner nur lesend mit jamie. Dann erscheint Dokument 412 unter Paperless-Dokumente.">
</picture>

1. **Verbundene Dienste durchsuchen.** Nextcloud fragt Paperless nur, solange der Benutzer es in der Suche eingeschaltet hat, und schaltet es bei jedem Neuladen der Seite aus ([warum](usage.de.md#warum-der-schalter-nach-dem-neuladen-aus-ist)). **Paperless immer in die globale Suche einbeziehen** in den Einstellungen fragt Paperless stattdessen bei jeder Suche.
2. **Das Archiv-Konto.** Ohne eines steht keine Datei für ein Dokument, und die Suche fragt Paperless gar nicht erst. Die Einstellungen nehmen das Konto von [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync), wenn das Feld leer ist, und warnen, wenn das Konto des Feldes nicht existiert. Sagen sie, dass das gespeicherte API-Token nicht entschlüsselt werden kann, etwa nach einem Umzug von Nextcloud mit einem anderen `secret` in der `config.php`, gib es erneut ein.
3. **Probleme der Suche.** Eine fehlgeschlagene Suche erscheint dort mit ihrem Fehler; [unten](#probleme-der-suche) steht, was jede Zeile bedeutet, und die Tabellen [des Tests der Verbindung](#wenn-der-test-der-verbindung-fehlschlägt) sagen, wie du die Fehler behebst, denn eine Suche schlägt aus denselben Gründen fehl wie der Test.
4. **Was Paperless findet.** Melde dich als das Konto des API-Tokens bei Paperless an und suche nach demselben Begriff. Paperless zeigt diesem Konto nur die Dokumente, die es ansehen darf: Gib ihm die Berechtigung, die anderen anzusehen, oder einer Gruppe, in der es ist, wie [Einrichten](configure.de.md#ein-paperless-konto-für-die-suche) beschreibt. Findet Paperless selbst ein Dokument nicht, dessen Text den Begriff enthält, fehlt das Dokument in seinem Index: [Bau ihn neu auf](#der-index-von-paperless).
5. **Die Datei mit der Kennung.** Die Datei muss dem Archiv-Konto gehören und die genaue Kennung des Dokuments, `[P412]`, im Namen tragen: Eine Kopie in einem anderen Konto oder `P412` ohne die Klammern steht für nichts. Eine Datei, die die Synchronisierung noch nicht geschrieben hat, etwa für ein Dokument, das noch im Posteingang von Paperless liegt, kann ebenfalls nicht erscheinen. Nextcloud findet nur die Dateien, die sein Datei-Cache kennt: Für einen externen Speicher oder Dateien, die an Nextcloud vorbei in seinen Datenordner kopiert wurden, lass `occ files:scan --path=/paperless/files/Paperless` den Ordner des Archivs scannen.
6. **Die Freigabe.** Der Benutzer muss diese Datei öffnen können: das Archiv-Konto selbst oder ein Benutzer oder eine Gruppe, mit dem oder der es die Datei oder einen Ordner darum nur lesend geteilt hat, wie [Einrichten](configure.de.md#das-archiv-nur-lesend-teilen) zeigt. Melde dich als der Benutzer an und such die Datei in *Dateien*: Wo der Benutzer sie nicht öffnen kann, kann die Suche sie nicht zeigen. Eine Freigabe mit Schreibrecht zählt nicht, denn der Benutzer könnte die Datei so benannt haben: Teile sie nur lesend.

**Suche ausprobieren** am Ende der Einstellungen beantwortet die Fragen 4 bis 6 ohne Shell: Gib den Begriff und die Benutzer-ID ein, etwa `invoice` und `jamie`, und es listet die ersten 20 Dokumente, die Paperless für den Begriff einordnet, jedes mit seiner ID, seinem Titel und ob es diesem Benutzer angezeigt wird oder warum nicht: *Mit Schreibrecht geteilt*, *Nicht mit* dem Benutzer *geteilt*, *Keine Datei des Archiv-Kontos* oder *Keine Datei mit* seiner Kennung, etwa `[P412]`. Fehlt Dokument 412 in der Liste, findet Paperless es für den Begriff nicht unter seinen ersten 20: Frage 4, oder ein genauerer Begriff. Es stellt dieselben Fragen wie [`occ paperless_unified_search:search --explain`](reference.de.md#ein-verborgenes-dokument-erklären) und zeigt nur die Titel.

<figure markdown="span">
  ![Suche ausprobieren in den Einstellungen: Der Administrator sucht als Konto e2e-admin nach invoice, und eine Tabelle listet die drei Dokumente, die Paperless zuerst einordnet, mit ihren IDs und Titeln, die Stromrechnung angezeigt, die Verlängerung der Versicherung und die Internetrechnung nicht mit diesem Konto geteilt, über den Gründen, warum ein Dokument nicht angezeigt wird](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-try-a-search.png#only-light)
  ![Suche ausprobieren in den Einstellungen: Der Administrator sucht als Konto e2e-admin nach invoice, und eine Tabelle listet die drei Dokumente, die Paperless zuerst einordnet, mit ihren IDs und Titeln, die Stromrechnung angezeigt, die Verlängerung der Versicherung und die Internetrechnung nicht mit diesem Konto geteilt, über den Gründen, warum ein Dokument nicht angezeigt wird](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/10-try-a-search-dark.png#only-dark)
  <figcaption>Suche ausprobieren in den Einstellungen</figcaption>
</figure>

Fehlt es immer noch? [Bitte um Hilfe](#um-hilfe-bitten), mit dem, was jeder dieser Schritte gezeigt hat.

### Was du siehst, und warum

| Was du siehst | Warum | Was zu tun ist |
| --- | --- | --- |
| Gar kein Abschnitt *Paperless-Dokumente* | Nextcloud zeigt einen Abschnitt nur, wenn er Treffer hat, und fragt Paperless nur mit eingeschaltetem *Verbundene Dienste durchsuchen* | Schalte es ein und folge dann den sechs Fragen oben |
| Das Archiv-Konto findet die Dokumente, andere Benutzer nicht | Das Archiv ist nicht mit ihnen geteilt | Teile es nur lesend mit ihnen oder ihrer Gruppe |
| Ein Benutzer mit einer Freigabe, die Änderungen erlaubt, findet keines ihrer Dokumente | Eine Datei, die der Benutzer ändern darf, oder eine in einem Ordner, in den er Dateien legen darf, könnte jede Kennung tragen, also steht sie für kein Dokument | Teile sie nur lesend; die Einstellungen warnen vor jeder solchen Freigabe |
| Manche Dokumente erscheinen, andere nicht | Das Konto des Tokens darf sie nicht ansehen, oder sie haben noch keine Datei mit ihrer Kennung | Fragen 4 und 5 oben |
| Treffer zeigen keinen Korrespondenten oder keinen Typ, und die Einstellungen warnen vor nichts | Das Konto des Tokens darf Korrespondenten und Dokumenttypen ansehen, aber nicht diese, die einem anderen Benutzer von Paperless gehören | Lass das Konto sie ansehen, wie [Einrichten](configure.de.md#die-namen-von-korrespondenten-und-typen) zeigt; die Treffer zeigen die Namen innerhalb von zehn Minuten |
| Paperless-Dokumente sind nach einem Umzug oder einer Wiederherstellung von Nextcloud verschwunden | Das gespeicherte API-Token lässt sich mit dem neuen `secret` der `config.php` nicht entschlüsseln | Gib das Token in den Einstellungen erneut ein |
| Paperless-Dokumente sind verschwunden, nachdem `allow_local_remote_servers` aus der `config.php` entfernt wurde | Nextcloud verweigert die Adresse von Paperless im lokalen Netzwerk | Wähle **Paperless läuft in meinem lokalen Netzwerk** und speichere |
| Eine Anfrage in der Syntax von Paperless findet nichts, während du sie noch tippst | Paperless kann sie noch nicht verarbeiten, etwa `created:[2026 to` | Tippe die Anfrage zu Ende; Paperless 3 findet solange ihre Wörter |
| Nur wenige Treffer und kein *Weitere Ergebnisse laden* | Der Benutzer kann die Dateien nur weniger der Dokumente öffnen, die Paperless zuerst einordnet | Normal: Die Suche geht für jede Seite mit Treffern bis zu 4 Seiten mit 25 Dokumenten von Paperless durch |

## Wenn der Test der Verbindung fehlschlägt

--8<-- "README.de.md:connection-test"

Vor dem Test prüfen die Einstellungen, was du eingegeben hast. Diese Meldungen nennen, was zu korrigieren ist:

| Die Meldung sagt | Was zu tun ist |
| --- | --- |
| Gib eine gültige Paperless-URL ein | Gib die ganze Adresse ein, mit `http://` oder `https://`, etwa `https://paperless.example.com` |
| Die Paperless-URL muss HTTP oder HTTPS verwenden | Beginne die URL mit `http://` oder `https://` |
| Die Paperless-URL darf keine Zugangsdaten, keine Abfrage (?…) und kein Fragment (#…) enthalten | Lass `user:password@`, ein `?` und ein `#` weg: Das Token ist der einzige Zugang |
| Gib den Host der Paperless-URL in seiner ASCII-Form (Punycode) ein | Gib einen Namen mit Buchstaben wie *ü* als Punycode ein, etwa `xn--bcher-kva.example` für `bücher.example` |
| Ein Paperless-API-Token ist erforderlich | Gib das Token ein: Es ist noch keines gespeichert |
| Gib das API-Token erneut ein: Ein gespeichertes Token wird nur an den Paperless-Server gesendet, für den es gespeichert wurde | Gib das Token erneut ein: Die URL nennt ein anderes Schema, einen anderen Host oder Port als die gespeicherte |
| Das Archiv-Konto … existiert nicht | Gib die ID eines vorhandenen Nextcloud-Kontos ein, oder lass das Feld für das Konto von Paperless Sync leer |

Die [Referenz](reference.de.md#die-routen-der-einstellungen) listet den Code, mit dem die Route der Einstellungen jede Meldung beantwortet, für Skripte, die die Einstellungen speichern.

## Warnungen zum API-Token

Der Test der Verbindung fragt Paperless auch, was das Konto des API-Tokens darf, wie **Verbindung testen und speichern**, `occ paperless_unified_search:setup`, `test` und `check` ihn ausführen. Die Einstellungen zeigen eine Warnung unter dem Token, `occ` eine Zeile mit `Warning:` oder `!`, und die Übersicht der Verwaltungseinstellungen wiederholt sie bis zum nächsten Test. Keine davon hindert die Einstellungen am Speichern oder die Suche am Funktionieren. Ändere das Konto in Paperless unter **Benutzer & Gruppen** (*Users & Groups*), oder gib das Token eines anderen ein, und teste dann die Verbindung erneut.

| Die Warnung sagt | Was zu tun ist |
| --- | --- |
| Das API-Token gehört einem Superuser von Paperless | Lege ein eigenes Konto für die Suche an, wie [Konfigurieren](configure.de.md#ein-paperless-konto-für-die-suche) zeigt, und gib sein Token ein: Wer das Token eines Superusers in die Hände bekommt, kann jedes Dokument ändern und löschen |
| Das API-Token gehört einem Admin von Paperless | Nimm dem Konto **Admin**, oder verwende das Token eines anderen: Ein Admin darf auch die Protokolle und den Status von Paperless lesen |
| Das Konto des API-Tokens darf in Paperless Dokumente hinzufügen, ändern oder löschen | Lass dem Konto von den Berechtigungen für **Dokument** (*Document*) nur **Anzeigen** (*View*), in seinen eigenen Berechtigungen und in denen seiner Gruppen, die ebenso zählen; **Anzeigen** für **Korrespondent** (*Correspondent*), **Dokumenttyp** (*DocumentType*) und **UI-Einstellungen** (*UISettings*) behält es. **Hinzufügen** (*Add*) oder **Ändern** (*Change*) für **Benutzer** (*User*) und **Ändern** für **Gruppe** (*Group*) zählen auch, denn Paperless lässt ein solches Konto sich selbst das Recht geben, Dokumente zu ändern |
| Das Konto des API-Tokens darf in Paperless keine Korrespondenten ansehen | Gib dem Konto **Anzeigen** für **Korrespondent** (*Correspondent*); bis dahin zeigen die Treffer und das Dashboard keinen Korrespondenten |
| Das Konto des API-Tokens darf in Paperless keine Dokumenttypen ansehen | Gib dem Konto **Anzeigen** für **Dokumenttyp** (*DocumentType*); bis dahin zeigen die Treffer und das Dashboard keinen Typ |
| Paperless doesn't say what the account of the API token may do (nur `occ paperless_unified_search:check`, auf Englisch) | Gib dem Konto **Anzeigen** für **UI-Einstellungen** (*UISettings*): Paperless sagt nur einem Konto, das seine UI-Einstellungen ansehen darf, was es darf, und ohne das warnen die Einstellungen vor nichts |

## Paperless in deinem lokalen Netzwerk

Läuft Paperless unter einer Adresse deines lokalen Netzwerks oder unter dem Namen seines Docker-Dienstes, etwa `http://192.168.1.20:8000` oder `http://paperless:8000`, verweigert Nextcloud die Verbindung dorthin, und der Test der Verbindung sagt das. Wähle **Paperless läuft in meinem lokalen Netzwerk** in den Einstellungen und speichere: Nextcloud erlaubt diese Adresse dann für die Anfragen dieser App an die URL der Einstellungen und für nichts anderes. [Einrichten](configure.de.md#paperless-in-deinem-lokalen-netzwerk) hat die Details und sagt, warum `allow_local_remote_servers` in der `config.php` der falsche Weg ist.

Mit dem Schalter folgt Nextcloud keiner Weiterleitung: Eine URL, die weiterleitet, etwa von `http` auf `https`, lässt den Test mit einer eigenen Meldung fehlschlagen. Gib die Adresse ein, auf die sie weiterleitet.

## Eigene Zertifikate

Nextcloud prüft das Zertifikat jedes HTTPS-Servers, mit dem es sich verbindet. Ein Paperless mit dem Zertifikat einer eigenen Zertifizierungsstelle oder mit einem selbst signierten lässt den Test mit *Nextcloud vertraut dem Zertifikat von Paperless nicht* fehlschlagen. Importiere das Zertifikat der Zertifizierungsstelle, oder das selbst signierte Zertifikat selbst, in die Zertifikate, denen Nextcloud vertraut:

```sh
occ security:certificates:import /path/to/ca.crt
occ security:certificates
```

Der zweite Befehl listet die Zertifikate, die du importiert hast. In Docker muss die Datei zuerst in den Container:

```sh
docker compose cp ca.crt app:/tmp/ca.crt
docker compose exec --user www-data app php occ security:certificates:import /tmp/ca.crt
```

Der Name in der URL muss einer sein, für den das Zertifikat ausgestellt ist: Ein Zertifikat für `paperless.example.com` schlägt für `https://192.168.1.20` fehl. Wechsle nicht auf `http`, um ein Zertifikat zu umgehen, wenn die Verbindung den Rechner verlässt, denn das Token reiste dann unverschlüsselt.

## Hinter einem Reverse Proxy

Ein Proxy vor Paperless, etwa nginx, Traefik, Caddy oder der Tunnel von Cloudflare, antwortet für Paperless, und manche seiner Antworten lassen den Test fehlschlagen:

- **Ein Pfad.** Läuft Paperless unter einem Pfad, etwa `https://example.com/paperless` mit `PAPERLESS_FORCE_SCRIPT_NAME=/paperless`, braucht die URL der Einstellungen diesen Pfad; die App hängt `/api/documents/` daran. Ohne ihn schlägt der Test mit HTTP 404 fehl.
- **Eine Anmeldung vor Paperless**, etwa die Forward-Authentifizierung von Authelia oder Authentik, antwortet mit ihrer Anmeldeseite oder einer Weiterleitung dorthin: *Diese URL antwortet, aber nicht wie Paperless-ngx*, oder, mit **Paperless läuft in meinem lokalen Netzwerk**, *Diese URL leitet an eine andere Adresse weiter*. Lass den Proxy den Pfad `/api/` ohne seine Anmeldung an Paperless weitergeben, denn das Token authentifiziert jede Anfrage, oder lass Nextcloud Paperless hinter dem Proxy erreichen, unter seiner Adresse im lokalen Netzwerk, mit **Paperless läuft in meinem lokalen Netzwerk**.
- **Basic-Authentifizierung** im Proxy, etwa `auth_basic` von nginx, beantwortet eine Anfrage, die statt seiner eigenen Anmeldung das Token trägt, mit HTTP 401: *Paperless hat das API-Token abgelehnt (HTTP 401)*, mit einem Token, das funktioniert. Lass den Proxy den Pfad `/api/` ohne sie an Paperless weitergeben.
- **Der Header `Authorization`.** Das Token reist darin, als `Authorization: Token …`. Ein Proxy, der den Header verwirft oder durch einen eigenen ersetzt, lässt Paperless ein richtiges Token mit HTTP 401 ablehnen.
- **Eine Firewall oder ein Schutz gegen Bots**, etwa die Challenge-Seite von Cloudflare, antwortet mit HTTP 403 oder einer eigenen Seite: *Paperless hat das API-Token abgelehnt (HTTP 403)* mit einem Token, das im Browser funktioniert, oder *nicht wie Paperless-ngx*. Lass die Anfragen des Nextcloud-Servers durch, deren `User-Agent` mit `Nextcloud-Paperless-Unified-Search/` beginnt.
- **Eine Weiterleitung** auf ein anderes Schema, einen anderen Host oder Port, etwa von `http` auf `https` oder auf einen anderen Namen: Nextcloud folgt ihr, lässt aber das Token weg, damit das Token nie einen Server erreicht, für den es nicht eingegeben wurde, und Paperless lehnt die Anfrage ohne es ab: *Paperless hat das API-Token abgelehnt*. Mit **Paperless läuft in meinem lokalen Netzwerk** folgt Nextcloud keiner Weiterleitung, und der Test sagt *Diese URL leitet an eine andere Adresse weiter*. So oder so: Gib die Adresse ein, auf die der Proxy weiterleitet. Eine Weiterleitung innerhalb desselben Servers, etwa auf den Pfad mit einem Schrägstrich am Ende, behält das Token.

Das Protokoll des Proxys zeigt jede Anfrage der App mit diesem `User-Agent` und der Version der App.

## Der Index von Paperless

Paperless findet Dokumente in einem Index ihrer Wörter, den es neben seiner Datenbank führt. Nach einer Wiederherstellung, einem Umzug oder einem Absturz von Paperless kann dem Index ein Dokument fehlen, oder er enthält noch die Wörter seines alten Textes: Die Suche von Paperless selbst übersieht es dann ebenfalls, und ebenso die Suche von Nextcloud. Bau ihn neu auf:

```sh
docker compose exec webserver document_index reindex
```

`webserver` ist der Dienst von Paperless in seiner `compose.yaml`; ohne Docker führst du `python3 manage.py document_index reindex` im Ordner `src` von Paperless aus. Ein großes Archiv braucht eine Weile, und Paperless findet weniger, bis es fertig ist.

## Probleme der Suche

Das Ende der Einstellungen zählt die Probleme der Suche und listet die letzten 20 davon, die neuesten zuerst. Es zeigt *Kein Problem aufgezeichnet*, bis eine Suche fehlschlägt oder eine Anfrage ihren zweiten Versuch braucht. **Verlauf leeren** und **Verbindung trennen** löschen den Verlauf; das Speichern der Einstellungen behält ihn.

| Zeile | Was sie zeigt |
| --- | --- |
| **Aufgezeichnet seit** | Wann der Verlauf begann: das erste Problem, nachdem er zuletzt geleert wurde |
| **Fehlgeschlagene Suchen** | Wie viele Suchen keine Paperless-Dokumente gezeigt haben, weil eine Anfrage fehlgeschlagen ist, auch die, die die Liste nicht mehr enthält |
| **Erst im zweiten Versuch beantwortet** | Wie viele Anfragen an Paperless fehlgeschlagen sind, bevor sie es erreicht haben, und ihre Antwort im zweiten Versuch bekommen haben: Die Benutzer haben die Dokumente gesehen, einen Moment später |
| **Letzte fehlgeschlagene Suche** | Wann zuletzt eine Suche fehlgeschlagen ist |
| **Suchen funktionieren wieder seit** | Wann die erste Suche nach dem letzten Fehler funktioniert hat, oder *Seitdem hat keine Suche funktioniert*, solange Paperless noch nicht erreichbar ist |

| Spalte | Was sie zeigt |
| --- | --- |
| **Zeitpunkt** | Wann das Problem aufgetreten ist, in der Zeitzone und im Format deines Kontos |
| **Ereignis** | *Suche fehlgeschlagen*: Der Benutzer hat keine Paperless-Dokumente gesehen. *Zweiter Versuch beantwortet*: Der erste Versuch ist fehlgeschlagen, bevor er Paperless erreicht hat, der zweite hat die Antwort bekommen |
| **Schritt** | *Anfrage an Paperless*: Die Anfrage an Paperless ist fehlgeschlagen. *Suche nach den Dateien in Nextcloud*: Paperless hat geantwortet, aber das Finden der Dateien seiner Dokumente in Nextcloud ist fehlgeschlagen |
| **Fehler** | Die Art des Fehlers und seine Meldung, etwa `ConnectException: cURL error 6: Could not resolve host`, ohne das API-Token, den Suchbegriff und die Abfrage einer URL |
| **Dauer** | Bei *Suche fehlgeschlagen*, wie lange die Suche von ihrem Beginn bis zum Fehler gedauert hat: etwa 6.000 ms, wenn keiner der beiden Versuche innerhalb von 3 Sekunden eine Verbindung bekommen hat, etwa 10.000 ms bei einem Paperless, das die Verbindung angenommen, aber nicht geantwortet hat, und die Zeit der Seiten davor, wenn eine spätere Seite von Paperless fehlgeschlagen ist. Bei *Zweiter Versuch beantwortet*, wie lange der erste Versuch gedauert hat: etwa 3.000 ms für eine Verbindung, die nicht rechtzeitig aufgebaut wurde, ein paar Millisekunden für einen Namen, der sich nicht auflösen ließ |

Eine Anfrage, die Paperless ablehnt, während sie noch getippt wird, und eine Seite nach dem letzten Treffer sind keine Probleme und erscheinen nicht. Während eines Ausfalls werden die Probleme einer Art höchstens einmal in 30 Sekunden geschrieben, die dazwischen trotzdem gezählt. Die Fehler der Spalte *Fehler* sind die [des Tests der Verbindung](#wenn-der-test-der-verbindung-fehlschlägt); zum Beispiel:

| Der Fehler sagt | Was fehlgeschlagen ist |
| --- | --- |
| `cURL error 6: Could not resolve host` | Der Name des Hosts der URL lässt sich auf dem Nextcloud-Server nicht auflösen |
| `cURL error 7: Failed to connect` | Nichts nimmt an der Adresse und dem Port Verbindungen an |
| `cURL error 28: Connection timed out` | Keine Verbindung innerhalb von 3 Sekunden, auch nicht im zweiten Versuch |
| `cURL error 28: Operation timed out` | Keine Antwort innerhalb von 10 Sekunden: Paperless ist ausgelastet, etwa während es Dokumente verarbeitet oder seinen Index neu aufbaut |
| `cURL error 60: SSL certificate problem` | Nextcloud vertraut dem Zertifikat nicht: [Importiere es](#eigene-zertifikate) |
| `Paperless returned HTTP 401` | Das Token ist nicht mehr gültig: Lege eines an und gib es ein |

## Die Suche ist langsam

Eine Seite mit Treffern wartet auf Paperless: Die App verbindet sich innerhalb von 3 Sekunden, wartet bis zu 10 Sekunden auf jede Antwort und geht innerhalb von 3 Sekunden bis zu 4 Seiten mit 25 Dokumenten von Paperless durch, um eine Seite mit Treffern zu füllen. Den größten Teil dieser Zeit durchsucht Paperless seinen Index; während es viele Dokumente verarbeitet oder seinen Index neu aufbaut, antwortet es langsamer. Eine Anfrage, die vergeblich auf Paperless gewartet hat, bekommt keinen zweiten Versuch, also kostet ein hängendes Paperless jede Suche höchstens eine Wartezeit.

Such zum Vergleich in Paperless selbst: Ist es dort auch langsam, gib Paperless mehr Arbeitsspeicher oder einen schnelleren Datenträger. Ist es dort schnell, sieh dir das Netzwerk zwischen den beiden Servern an und die *Probleme der Suche*.

## Um Hilfe bitten

Hilft nichts davon, frag in den [Diskussionen](https://github.com/Dennis-Otto/paperless-unified-search/discussions) oder melde einen Fehler mit der Vorlage der [Issues](https://github.com/Dennis-Otto/paperless-unified-search/issues/new/choose), gern auf Englisch. Nenne die Version der App, von Nextcloud und von Paperless-ngx, ob die Suche mit *Verbundene Dienste durchsuchen* oder immer läuft, welcher Schritt [der sechs Fragen](#keine-paperless-dokumente-in-der-suche) fehlgeschlagen ist, und die Zeilen der *Probleme der Suche* und des Protokolls von Nextcloud. Veröffentliche nie das API-Token, private Adressen oder den Inhalt von Dokumenten.
