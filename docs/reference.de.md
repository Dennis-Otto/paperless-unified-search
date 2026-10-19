---
description: "Referenz von Paperless Unified Search für Administratoren: jede Einstellung und ihr Schlüssel in der App-Konfiguration, occ, die Routen der Einstellungen und der Suche, Kompatibilität, Datenflüsse, das Protokoll und die Deinstallation."
---

# Referenz

Alles, was die App kennt, zum Nachschlagen: die Einstellungen, die Schlüssel, unter denen Nextcloud sie führt, die Routen, die Versionen, die die Tests abdecken, wohin Daten gehen und was bleibt, wenn die App geht.

## Einstellungen

Die Einstellungen liegen unter **Verwaltungseinstellungen → Paperless Unified Search**, nur für Administratoren, und gelten für jeden Benutzer der Nextcloud. **Verbindung testen und speichern** speichert sie erst, wenn Paperless antwortet; es und **Verbindung trennen** fragen nach dem Passwort, wenn es in den letzten 30 Minuten nicht bestätigt wurde.

| Einstellung | Was sie tut | Standard | Schlüssel der App-Konfiguration |
| --- | --- | --- | --- |
| **Paperless-URL** | Die Adresse von Paperless, mit einem Pfad, wenn Paperless unter einem läuft, etwa `https://paperless.example.com` | — | `paperless_url` |
| **Paperless läuft in meinem lokalen Netzwerk** | Lässt Nextcloud diese URL, und keine andere, unter einer Adresse des lokalen Netzwerks erreichen, ohne Weiterleitungen ([Details](configure.de.md#paperless-in-deinem-lokalen-netzwerk)) | Aus | `allow_local` |
| **Paperless-API-Token** | Das Token des Paperless-Kontos der Suche; leer behält das gespeicherte, für denselben Server | — | Keiner: im Zugangsdatenmanager von Nextcloud, als `paperless_unified_search.api-token` |
| **Archiv-Konto** | Das Nextcloud-Konto, dessen Dateien für die Dokumente stehen; leer nimmt das Konto von Paperless Sync | Das Konto von Paperless Sync | `archive_owner` |
| **Paperless immer in die globale Suche einbeziehen** | Fragt Paperless bei jeder Suche jedes Benutzers, ohne **Verbundene Dienste durchsuchen**; lade Nextcloud nach einer Änderung neu | Aus | `always_search` |

**Verbindung trennen** löscht die URL, das Token, die Schalter, das Archiv-Konto, die Version von Paperless und den Verlauf der Probleme. **Verlauf leeren** löscht nur den Verlauf.

## Konfiguration der App

Nextcloud führt die Einstellungen in seiner App-Konfiguration, die `occ` liest:

```sh
occ config:list paperless_unified_search
occ config:app:get paperless_unified_search paperless_url
```

| Schlüssel | Enthält |
| --- | --- |
| `paperless_url` | Die URL von Paperless, wie die Einstellungen sie gespeichert haben: ohne Schrägstrich am Ende, mit dem Host in seiner ASCII-Form |
| `allow_local` | Ob Paperless im lokalen Netzwerk läuft |
| `archive_owner` | Die ID des Archiv-Kontos, so geschrieben wie in Nextcloud; fehlt, wenn die Einstellungen das Konto von Paperless Sync nehmen |
| `always_search` | Ob jede Suche Paperless fragt |
| `paperless_version`, `paperless_api_version` | Die Versionen von Paperless und seiner API, etwa `3.3.0` und `10`, wie seine letzte Antwort sie genannt hat |
| `diagnostics` | Der Verlauf der Probleme der Suche, als JSON; ein Lazy-Schlüssel, den Nextcloud erst lädt, wenn er gelesen wird |
| `diagnostics_pending` | Ob ein Fehler im Verlauf noch auf eine Suche wartet, die funktioniert |

Das Konto von Paperless Sync ist `occ config:app:get paperless_sync target_user`.

Schlüssel, die eine ältere Version hinterlassen hat und diese nicht mehr liest, gehen mit dem nächsten Update: Nextcloud führt den Reparaturschritt *Remove the last failed search that Paperless Unified Search 0.3.0 kept* nach jedem Update der App und bei `occ maintenance:repair` aus, und er löscht `last_failure` und `last_recovery`, die der Verlauf der Probleme in 0.4.0 ersetzt hat.

Ändere die Einstellungen in den Verwaltungseinstellungen, die zuerst die Verbindung testen. `occ config:app:set` kann trotzdem einen Schalter ein- oder ausschalten, etwa `occ config:app:set paperless_unified_search always_search --value=true --type=boolean`, was das nächste Neuladen jedes Benutzers übernimmt. Ändere `paperless_url` nicht auf diesem Weg: Das gespeicherte Token folgte der URL zum neuen Server, was die Einstellungen verhindern, indem sie erneut nach dem Token fragen.

--8<-- "README.de.md:automation"

## Die Routen der Einstellungen

Die Seite der Einstellungen speichert über vier Routen der App, die nur Administratoren aufrufen dürfen, jede mit dem CSRF-Token von Nextcloud. Ein Skript ruft sie mit der Anmeldung eines Administrators und dem Header `OCS-APIRequest: true` auf, wie es die End-to-End-Tests tun; `/index.php` steht vor `/apps`, wo Nextcloud ohne Pretty URLs läuft.

| Route | Tut |
| --- | --- |
| `POST /apps/paperless_unified_search/settings` | Testet die Verbindung und speichert, mit den Parametern `url`, `token`, `archiveOwner`, `allowLocal` und `alwaysSearch`; fragt nach dem Passwort wie **Verbindung testen und speichern** |
| `DELETE /apps/paperless_unified_search/settings` | **Verbindung trennen**: löscht die Einstellungen, das Token und den Verlauf; fragt nach dem Passwort |
| `DELETE /apps/paperless_unified_search/settings/diagnostics` | **Verlauf leeren** |
| `POST /apps/paperless_unified_search/settings/try` | **Suche ausprobieren**: sucht mit dem Parameter `term` als das Konto `user` und sagt für jedes Dokument, ob es angezeigt wird und warum nicht, nur mit seiner ID und seinem Titel; das Protokoll hält fest, welcher Administrator als wer gesucht hat, ohne den Begriff |

```sh
curl -u admin -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  --data-urlencode 'url=https://paperless.example.com' \
  --data-urlencode 'archiveOwner=paperless' \
  --data-urlencode "token@$HOME/.paperless-token" \
  'https://cloud.example.com/apps/paperless_unified_search/settings'
```

`token@file` übergibt das Token in einer Datei, was es aus dem Verlauf der Shell und der Liste der Prozesse heraushält. Eine gespeicherte Einstellung antwortet mit HTTP 200 und den Einstellungen als JSON: `url`, `tokenConfigured`, `alwaysSearch`, `archiveOwner`, `syncAccount`, `tokenUnreadable`, `archiveOwnerMissing`, `allowLocal`, `paperlessVersion` und `paperlessApiVersion`, nie mit dem Token. Eine Ablehnung antwortet mit HTTP 400, oder 500, wenn etwas nach dem Test fehlgeschlagen ist, mit der `message`, die in der Sprache des Administrators zu zeigen ist, und einem `code`:

| Code | Bedeutet |
| --- | --- |
| `invalid_url`, `unsupported_scheme`, `url_extras`, `host_not_ascii` | Die URL ist keine, ist nicht HTTP oder HTTPS, hat Zugangsdaten, eine Abfrage oder ein Fragment, oder einen Host, der nicht in seiner ASCII-Form ist |
| `token_required`, `token_for_other_server` | Es ist kein Token gespeichert, oder das gespeicherte ist für einen anderen Server |
| `unknown_archive_account` | Das Archiv-Konto existiert nicht |
| `unauthorized` | Paperless hat das Token abgelehnt, mit HTTP 401 oder 403 |
| `not_found` | HTTP 404: Der Pfad der URL ist falsch |
| `redirect` | Die URL leitet weiter, und mit dem lokalen Netzwerk folgt Nextcloud keiner Weiterleitung |
| `http` | Ein anderer Status, etwa ein Fehler von Paperless mit 5xx |
| `unsupported_version` | HTTP 406: Paperless ist älter als 2.16 und spricht Version 9 seiner API nicht; ein Update von Paperless-ngx behebt es |
| `not_paperless` | Die Antwort ist keine Liste von Dokumenten von Paperless-ngx |
| `local_address` | Nextcloud hat die Adresse des lokalen Netzwerks verweigert |
| `tls`, `tls_handshake` | Nextcloud vertraut dem Zertifikat nicht oder konnte keine verschlüsselte Verbindung aufbauen |
| `unknown_host`, `timeout`, `unreachable` | Der Host lässt sich nicht auflösen, Paperless hat nicht rechtzeitig geantwortet, oder die Verbindung ist fehlgeschlagen |
| `internal` | Etwas nach dem Test ist fehlgeschlagen, etwa die Datenbank; das Protokoll von Nextcloud enthält es |

Die [Fehlerbehebung](troubleshooting.de.md#wenn-der-test-der-verbindung-fehlschlägt) sagt, was gegen jeden zu tun ist.

## Die Suche und der Smart Picker

| | ID | Wo |
| --- | --- | --- |
| Der Suchanbieter | `paperless_unified_search_documents` | **Paperless-Dokumente** in der Suche von Nextcloud und `GET /ocs/v2.php/search/providers/paperless_unified_search_documents/search?term=…` in seiner OCS-API, mit `limit` (standardmäßig 5, bis zu 50) und `cursor` |
| Der Anbieter des Smart Pickers | `paperless_unified_search-documents` | **Paperless-Dokumente** im Smart Picker von Talk, Text, Deck und Mail |

[Aus Skripten und KI-Assistenten nutzen](usage.de.md#aus-skripten-und-ki-assistenten-nutzen) zeigt die Antwort der API.

## Das Widget des Dashboards

| | |
| --- | --- |
| **ID** | `paperless_unified_search-recent`, *Neu in Paperless*, angeboten, sobald die Einstellungen ein Paperless und ein Archiv-Konto nennen, das existiert |
| **Einträge** | `GET /ocs/v2.php/apps/dashboard/api/v2/widget-items?widgets[]=paperless_unified_search-recent` mit `limit` (standardmäßig 7, bis zu 30), wie das Dashboard von Nextcloud und seine Apps sie anfragen: die neuesten Dokumente, die der Benutzer öffnen kann, jedes mit seinem Titel, einer Zeile mit dem Tag, an dem Paperless es hinzugefügt hat, seinem Korrespondenten und seinem Typ, dem Link und der Vorschau seiner Datei und als `sinceId` dem Moment, an dem Paperless es hinzugefügt hat; `sinceIds[paperless_unified_search-recent]` mit einem solchen Moment listet nur die Dokumente, die danach hinzugekommen sind |
| **Der Link unter der Liste** | *Mit dir geteilte Dateien*, die Ansicht von Dateien mit den Dateien, die andere mit dem Benutzer teilen; das Web-Dashboard von Nextcloud zeigt ihn, und sechs Dokumente, erst wenn die Liste voll ist; keiner für das Archiv-Konto |
| **Paperless** | Eine Anfrage nach den 25 Dokumenten, die es zuletzt hinzugefügt hat, höchstens einmal in der Minute für alle Benutzer gemeinsam, die der verteilte Cache von Nextcloud solange behält (mit einem Memory-Cache, wie Nextcloud ihn empfiehlt; ohne einen fragt jedes Dashboard Paperless); ein Paperless, das nicht antwortet, ruht ebenfalls eine Minute, und das Widget sagt *Paperless ist gerade nicht erreichbar.* |

## Anfragen an Paperless

Jede Anfrage der App an Paperless ist ein `GET` von `<URL>/api/documents/`, oder von `/api/correspondents/` und `/api/document_types/` für die Namen der Korrespondenten und Typen einer Seite, vom Nextcloud-Server aus:

| | |
| --- | --- |
| **Header** | `Authorization: Token …`, `Accept: application/json` und `User-Agent: Nextcloud-Paperless-Unified-Search/<version>` |
| **Eine Suche** | `query` mit dem Begriff, `page`, `page_size=25` und `fields=id,title,created,correspondent,document_type,__search_hit__`; bei Paperless 3 eine Anfrage, die es noch nicht verarbeiten kann, noch einmal mit `text` statt `query` |
| **Der Test der Verbindung** | `page_size=1` und `fields=id` |
| **Die neuesten Dokumente des Dashboards** | `ordering=-added`, `page=1`, `page_size=25` und `fields=id,title,added,correspondent,document_type`, höchstens einmal in der Minute für alle Benutzer, mit 5 Sekunden für die Antwort und ohne zweiten Versuch |
| **Zeit** | 3 Sekunden zum Verbinden, 10 Sekunden für die Antwort |
| **Ein zweiter Versuch** | Nur für eine Anfrage, die innerhalb von 3,5 Sekunden fehlgeschlagen ist, bevor sie eine Verbindung hatte, und deren Adresse Nextcloud nicht verweigert hat |
| **Weiterleitungen** | Ohne **Paperless läuft in meinem lokalen Netzwerk** bis zu 5, nie an eine Adresse des lokalen Netzwerks; Nextcloud lässt das Token bei einer Weiterleitung auf ein anderes Schema, einen anderen Host oder Port weg, was Paperless dann ablehnt, und behält es bei einer innerhalb desselben Servers. Mit dem Schalter keine |

## Kompatibilität

| | Nextcloud 33 | Nextcloud 34 | Nextcloud 35 |
| --- | --- | --- | --- |
| **Paperless-ngx 3.x** (API 9) | End-to-End-Tests | End-to-End-Tests | End-to-End-Tests |
| **Paperless-ngx 2.16 bis 2.20** (API 9) | Unit-Tests | Unit-Tests | Unit-Tests |

- **Nextcloud:** Die End-to-End-Tests führen die App im aktuellen Release jeder Version aus, die `appinfo/info.xml` unterstützt, in jedem Pull Request, der die App ändert, und bei jeder Änderung von `main`, und jeden Montag auch in der kommenden Version von Nextcloud, ihrer Beta oder ihrem Release Candidate.
- **Paperless-ngx:** Die End-to-End-Tests fragen eine Nachbildung von Paperless, die so antwortet wie Paperless-ngx 3.3.0: ihre Seiten, ihre Felder, ihre Header der Version, ihre Ausschnitte und ihre Ablehnung einer Anfrage, die noch getippt wird. Die Unit-Tests prüfen die Antworten von 2.20 und 3.3, etwa dass nur Paperless 3 den einfachen Modus seiner Suche bekommt, den Paperless 2 nicht kennt. Jede Anfrage nennt Version 9 der API, die Paperless-ngx 2.16 und neuer und jedes 3.x sprechen, also kann ein neues Release von Paperless die Treffer nicht unbemerkt ändern; Vertragstests prüfen die App jede Woche gegen echtes Paperless-ngx, das letzte Release von 2.20 und das neueste von 3.x. Ein älteres Paperless antwortet mit HTTP 406, und der Test der Verbindung sagt das.
- **PHP:** 8.2 oder neuer, soweit Nextcloud es unterstützt; die Unit-Tests laufen auf 8.3, wie es PHPUnit 12 braucht.

## Datenflüsse

| Von | An | Was | Wann |
| --- | --- | --- | --- |
| Der Browser oder eine App des Benutzers | Nextcloud | Der Suchbegriff | Immer, wenn der Benutzer sucht |
| Nextcloud | Paperless | Der Begriff, die Seite und die Felder, mit denen zu antworten ist, und das API-Token | Mit eingeschaltetem **Verbundene Dienste durchsuchen**, nach der Wahl von **Paperless-Dokumente** im Smart Picker, über die API oder immer, wenn Paperless vertraut wird |
| Paperless | Nextcloud | Von jedem Dokument, das das Konto des Tokens ansehen darf: seine ID, sein Titel, sein Datum, die IDs seines Korrespondenten und seines Typs und der Ausschnitt um den Begriff; die Versionen von Paperless und seiner API | In jeder Antwort |
| Nextcloud | Der Browser oder eine App des Benutzers | Von jedem Dokument, dessen Datei der Benutzer öffnen kann: sein Titel, sein Datum und sein Ausschnitt und der Link, die ID und der Pfad seiner Datei | In jeder Antwort |
| Nextcloud | Paperless | Die Anfrage nach den 25 Dokumenten, die Paperless zuletzt hinzugefügt hat, mit den Feldern, die das Dashboard zeigt, und das API-Token | Höchstens einmal in der Minute, wenn das Dashboard eines Benutzers das Widget zeigt |
| Paperless | Nextcloud | Von jedem dieser Dokumente, das das Konto des Tokens ansehen darf: seine ID, sein Titel, der Moment, an dem Paperless es hinzugefügt hat, und die IDs seines Korrespondenten und seines Typs | In jeder Antwort |
| Nextcloud | Der Browser oder eine App des Benutzers | Von jedem der neuesten Dokumente, dessen Datei der Benutzer öffnen kann: sein Titel, der Tag, an dem Paperless es hinzugefügt hat, sein Korrespondent und sein Typ und der Link und die Vorschau seiner Datei | Immer, wenn das Dashboard das Widget zeigt |

<div class="diagram-wide" markdown tabindex="0" role="region" aria-label="Diagramm des Datenflusses einer Suche">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-data-flow-of-a-search.de.dark.svg">
  <img src="images/diagrams/the-data-flow-of-a-search.de.svg" alt="Der Datenfluss einer Suche: Ein Benutzer sucht nach invoice. Die globale Suche gibt den Begriff, den Cursor, das Limit, den Datumsfilter und den Benutzer an den Suchanbieter. Ohne Begriff, Archiv-Konto oder Verbindung antwortet der Anbieter ohne Treffer und fragt Paperless nicht. Sonst fragt der Paperless-Client, bis die Seite mit Treffern voll ist, Paperless-ngx mit dem Token nach der Seite mit 25 Treffern, die den nächsten Treffer enthält, innerhalb der Tage des Datumsfilters und nur nach den Feldern, die ein Treffer zeigt, und Paperless antwortet mit der ID, dem Titel, dem Datum, den IDs des Korrespondenten und des Typs und dem Ausschnitt der Dokumente 412, 389, 371 und weiterer und damit, ob es eine nächste Seite gibt. Ein Aufruf des Dateisuchers deckt alle Dokumente dieser Seite ab und liefert für jedes eine Datei des Archiv-Kontos mit seiner Kennung oder keine. Für die Dokumente mit Datei fragen die Namen Paperless über den Client nach den Korrespondenten und den Typen, die der Cache nicht enthält, mit einer Anfrage je Art, und behalten ihre Namen zehn Minuten lang. Das wiederholt sich für höchstens 4 Seiten von Paperless oder 3 Sekunden. Der Anbieter antwortet mit einem Eintrag für jedes Dokument mit Datei, bis zum Limit, mit der Vorschau der Datei oder dem Symbol ihres Typs, dem Titel, dem Datum in der Sprache des Benutzers, dem Korrespondenten, dem Typ und dem Ausschnitt, und mit dem Cursor des ersten Treffers, den er nicht durchgegangen ist.">
</picture>

</div>

Nextcloud speichert die Einstellungen in seiner App-Konfiguration, das Token verschlüsselt in seinem Zugangsdatenmanager und den Verlauf der letzten 20 Probleme, ohne den Begriff, das Token und die Abfrage einer URL. Sein verteilter Cache behält die Namen der Korrespondenten und Typen zehn Minuten lang und von den 25 neuesten Dokumenten ihre IDs, Titel, die Momente, an denen Paperless sie hinzugefügt hat, und die IDs ihrer Korrespondenten und Typen eine Minute lang, für alle Benutzer; vom Text der Dokumente wird nichts gespeichert. Die [Architecture](architecture.md#data-flow) erklärt jeden Schritt und das [Sicherheitsdesign](security.md), was jeden davon schützt.

--8<-- "README.de.md:security-and-privacy"

## Das Protokoll von Nextcloud

Die App schreibt in das Protokoll von Nextcloud mit `app` auf `paperless_unified_search`, nie mit dem Begriff oder dem Token. Die Meldungen sind auf Englisch:

| Stufe | Meldung | Bedeutet |
| --- | --- | --- |
| Warning | The test of the connection to Paperless failed: *reason* (*error*) | **Verbindung testen und speichern** ist fehlgeschlagen; der Grund ist der `code` der Routen oben |
| Error | The settings of Paperless Unified Search could not be saved (*error*) | Etwas nach dem Test ist fehlgeschlagen, etwa die Datenbank |
| Warning | Paperless unified search failed (*error*) | Eine Suche ist fehlgeschlagen; *Probleme der Suche* enthält sie auch |
| Warning | Paperless unified search left out *n* result(s) it could not show (*error*) | Treffer, deren Dateien nicht geantwortet haben, wurden weggelassen |
| Warning | Paperless unified search skipped *n* file(s) it could not read (*error*) | Dateien des Archivs, die Nextcloud nicht lesen konnte |
| Warning | Paperless unified search looks for the files of each document on its own … | Eine Version von Nextcloud, deren Abfrage des Datei-Caches die App noch nicht kennt: Die Suche funktioniert trotzdem, mit einer Abfrage je Dokument statt einer je Seite |
| Warning | The stored Paperless API token can't be decrypted … | Das `secret` der `config.php` hat sich geändert: Gib das Token erneut ein |
| Debug | Paperless rejected the query of a search with HTTP *status* | Eine Anfrage, die noch getippt wird, oder eine Seite nach der letzten: kein Problem |

## Deinstallieren

1. **Verbindung trennen** in den Einstellungen. Das löscht die URL, das Token, die Schalter, das Archiv-Konto, die Version von Paperless und den Verlauf der Probleme; nur `diagnostics_pending` bleibt, mit den Schlüsseln, die Nextcloud von jeder App führt, etwa `enabled` und `installed_version`.
2. **Die App entfernen:** `occ app:remove paperless_unified_search` deaktiviert sie und löscht ihren Code; oder *Apps → Deaktivieren* und *Entfernen* im Browser. Nextcloud behält die App-Konfiguration einer entfernten App: `occ config:list paperless_unified_search` listet, was übrig ist, und `occ config:app:delete paperless_unified_search <key>` löscht einen Schlüssel.
3. **In Paperless** löschst du das API-Token oder das Konto der Suche: Ein Token, das Nextcloud vergessen hat, funktioniert weiter, bis auch Paperless es vergisst.

Die Dateien des Archivs und ihre Freigaben bleiben, wie sie sind: Sie gehören dem Archiv-Konto und der Synchronisierung, nicht dieser App. Ohne **Verbindung trennen** bleibt das verschlüsselte Token im Zugangsdatenmanager von Nextcloud, unter `paperless_unified_search.api-token`, bis die App wieder installiert und getrennt wird.
