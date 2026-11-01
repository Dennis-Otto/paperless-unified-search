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
| **Benutzern erlauben, Paperless in alle ihre Suchen einzubeziehen** | Lässt jeden Benutzer in seinen persönlichen Einstellungen **Meine Paperless-Dokumente immer durchsuchen** wählen; aus blendet die Wahl aus, und die Suche übergeht, was Benutzer gewählt haben | An | `personal_always_search` |
| **Benutzern Benachrichtigungen über neue Dokumente erlauben** | Lässt jeden Benutzer in seinen persönlichen Einstellungen Suchen für Benachrichtigungen speichern; aus blendet die Wahl aus, und der Job fragt Paperless nichts | An, wenn Nextcloud einen Memory-Cache hat, bis ein Administrator wählt | `new_document_alerts` |

**Verbindung trennen** löscht die URL, das Token, die Schalter, das Archiv-Konto, die Version von Paperless und den Verlauf der Probleme. **Verlauf leeren** löscht nur den Verlauf.

### Persönliche Einstellungen

Unter **Persönliche Einstellungen → Paperless** kann jeder Benutzer **Meine Paperless-Dokumente immer durchsuchen** wählen: Jede seiner Suchen fragt Paperless dann ohne **Verbundene Dienste durchsuchen**, ab der nächsten Seite, die er öffnet, und die Begriffe gehen an den Paperless-Server, auch wenn er eine Datei oder einen Kontakt sucht. Das Kästchen speichert, sobald es sich ändert. Der Abschnitt erscheint, solange Paperless mit einem Archiv-Konto verbunden ist und die Administratoren Benutzer wählen lassen; solange **Paperless immer in die globale Suche einbeziehen** an ist, zeigt er das Kästchen angehakt und ausgegraut, weil jede Suche Paperless ohnehin einbezieht.

| Einstellung | Standard | Benutzereinstellung |
| --- | --- | --- |
| **Meine Paperless-Dokumente immer durchsuchen** | Aus | `always_search` der App, nur gespeichert, solange sie gewählt ist |
| **Benachrichtige mich über neue Dokumente** | Aus | `new_document_alerts` der App, ein Lazy-Wert, nur gespeichert, solange er etwas wählt, als JSON: `everyDocument` und die gespeicherten Suchen in `terms` |

Ein Administrator liest die Wahl eines Benutzers mit `occ user:setting <user> paperless_unified_search always_search` und nimmt sie mit `occ user:setting <user> paperless_unified_search always_search --delete` zurück. Solange **Benutzern erlauben, Paperless in alle ihre Suchen einzubeziehen** aus ist, bleiben die gespeicherten Wahlen erhalten, zählen aber nicht; sie zählen wieder, sobald es an ist.

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
| `personal_always_search` | Ob Benutzer wählen dürfen, Paperless in jede ihrer Suchen einzubeziehen; fehlt er, dürfen sie es |
| `new_document_alerts` | Ob Benutzer Benachrichtigungen über neue Dokumente wählen dürfen; fehlt er, heißt das ja, wenn Nextcloud einen Memory-Cache hat |
| `new_document_alerts_progress` | Wie weit der Job der Benachrichtigungen gekommen ist, als JSON: der Hash der URL des Servers, die höchste gesehene ID, der Moment der letzten Antwort, die Dokumente, die auf ihre Dateien warten, mit ihren Titeln, und die Fehler in Folge mit dem Ende der Pause; ein Lazy-Schlüssel. Der Job löscht ihn, solange niemand Benachrichtigungen wählt |
| `paperless_version`, `paperless_api_version` | Die Versionen von Paperless und seiner API, etwa `3.3.0` und `10`, wie seine letzte Antwort sie genannt hat |
| `token_warnings` | Die Warnungen zum Konto des API-Tokens, die der letzte Test der Verbindung gefunden hat, durch Kommas getrennt: `superuser`, `staff`, `changes_documents`, `hides_correspondents`, `hides_document_types`, `hides_tags` und `hides_names_of_others`; fehlt, wenn es keine gibt oder Paperless es nicht gesagt hat. Ein Lazy-Schlüssel |
| `diagnostics` | Der Verlauf der Probleme der Suche, als JSON; ein Lazy-Schlüssel, den Nextcloud erst lädt, wenn er gelesen wird |
| `diagnostics_pending` | Ob ein Fehler im Verlauf noch auf eine Suche wartet, die funktioniert |

Das Konto von Paperless Sync ist `occ config:app:get paperless_sync target_user`.

Schlüssel, die eine ältere Version hinterlassen hat und diese nicht mehr liest, gehen mit dem nächsten Update: Nextcloud führt den Reparaturschritt *Remove the last failed search that Paperless Unified Search 0.3.0 kept* nach jedem Update der App und bei `occ maintenance:repair` aus, und er löscht `last_failure` und `last_recovery`, die der Verlauf der Probleme in 0.4.0 ersetzt hat.

Ändere die Einstellungen in den Verwaltungseinstellungen, die zuerst die Verbindung testen. `occ config:app:set` kann trotzdem einen Schalter ein- oder ausschalten, etwa `occ config:app:set paperless_unified_search always_search --value=true --type=boolean`, was das nächste Neuladen jedes Benutzers übernimmt. Ändere `paperless_url` nicht auf diesem Weg: Das gespeicherte Token folgte der URL zum neuen Server, was die Einstellungen verhindern, indem sie erneut nach dem Token fragen.

--8<-- "README.de.md:automation"

## Die Routen der Einstellungen

Die Seite der Einstellungen speichert über vier Routen der App, die nur Administratoren aufrufen dürfen, jede mit dem CSRF-Token von Nextcloud, und die persönlichen Einstellungen über zwei weitere, die jeder angemeldete Benutzer aufrufen darf. Ein Skript ruft die vier mit der Anmeldung eines Administrators auf und diese zwei mit der Anmeldung des Benutzers, dessen Wahl sie speichern, jede mit dem Header `OCS-APIRequest: true`, wie es die End-to-End-Tests tun; `/index.php` steht vor `/apps`, wo Nextcloud ohne Pretty URLs läuft.

| Route | Tut |
| --- | --- |
| `POST /apps/paperless_unified_search/settings` | Testet die Verbindung und speichert, mit den Parametern `url`, `token`, `archiveOwner`, `allowLocal`, `alwaysSearch` und `personalAlwaysSearch`; fragt nach dem Passwort wie **Verbindung testen und speichern** |
| `DELETE /apps/paperless_unified_search/settings` | **Verbindung trennen**: löscht die Einstellungen, das Token und den Verlauf; fragt nach dem Passwort |
| `DELETE /apps/paperless_unified_search/settings/diagnostics` | **Verlauf leeren** |
| `POST /apps/paperless_unified_search/settings/try` | **Suche ausprobieren**: sucht mit dem Parameter `term` als das Konto `user` und sagt für jedes Dokument, ob es angezeigt wird und warum nicht, nur mit seiner ID, seinem Titel und seinen Tags; das Protokoll hält fest, welcher Administrator als wer gesucht hat, ohne den Begriff |
| `POST /apps/paperless_unified_search/personal` | **Meine Paperless-Dokumente immer durchsuchen** des angemeldeten Benutzers, mit dem Parameter `alwaysSearch`; antwortet `{"alwaysSearch": true}` oder `false`, und HTTP 403 mit dem Code `not_allowed`, solange die Administratoren Benutzer nicht wählen lassen |
| `POST /apps/paperless_unified_search/personal/alerts` | **Benachrichtige mich über neue Dokumente** des angemeldeten Benutzers, mit den Parametern `everyDocument` und `terms`, einer Liste; antwortet die Wahl, wie der Server sie behält, ohne leere Zeilen und Wiederholungen, oder eine `message` mit dem `code` `not_allowed`, `too_many_searches` für mehr als 10 oder `search_too_long` für mehr als 500 Zeichen |

```sh
curl -u admin -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  --data-urlencode 'url=https://paperless.example.com' \
  --data-urlencode 'archiveOwner=paperless' \
  --data-urlencode "token@$HOME/.paperless-token" \
  'https://cloud.example.com/apps/paperless_unified_search/settings'
```

`token@file` übergibt das Token in einer Datei, was es aus dem Verlauf der Shell und der Liste der Prozesse heraushält. Eine gespeicherte Einstellung antwortet mit HTTP 200 und den Einstellungen als JSON: `url`, `tokenConfigured`, `alwaysSearch`, `archiveOwner`, `syncAccount`, `tokenUnreadable`, `archiveOwnerMissing`, `allowLocal`, `paperlessVersion`, `paperlessApiVersion`, `personalAlwaysSearch` und `tokenWarnings`, die [Warnungen zum Konto des Tokens](troubleshooting.de.md#warnungen-zum-api-token), wie der Schlüssel `token_warnings` sie nennt, leer, wenn es keine gibt oder Paperless es nicht gesagt hat, nie mit dem Token. Ein Speichern ohne `alwaysSearch` oder `allowLocal` setzt den Schalter auf seinen Standard, aus; ein Speichern ohne `personalAlwaysSearch` lässt ihn, wie er ist. Eine Ablehnung antwortet mit HTTP 400, oder 500, wenn etwas nach dem Test fehlgeschlagen ist, mit der `message`, die in der Sprache des Administrators zu zeigen ist, und einem `code`:

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
| **Einträge** | `GET /ocs/v2.php/apps/dashboard/api/v2/widget-items?widgets[]=paperless_unified_search-recent` mit `limit` (standardmäßig 7, bis zu 30), wie das Dashboard von Nextcloud und seine Apps sie anfragen: die neuesten Dokumente, die der Benutzer öffnen kann, jedes mit seinem Titel, einer Zeile mit dem Tag, an dem Paperless es hinzugefügt hat, seinem Korrespondenten, seinem Typ und bis zu zwei seiner Tags, dem Link und der Vorschau seiner Datei und als `sinceId` dem Moment, an dem Paperless es hinzugefügt hat; `sinceIds[paperless_unified_search-recent]` mit einem solchen Moment listet nur die Dokumente, die danach hinzugekommen sind |
| **Der Link unter der Liste** | *Mit dir geteilte Dateien*, die Ansicht von Dateien mit den Dateien, die andere mit dem Benutzer teilen; das Web-Dashboard von Nextcloud zeigt ihn, und sechs Dokumente, erst wenn die Liste voll ist; keiner für das Archiv-Konto |
| **Paperless** | Eine Anfrage nach den 25 Dokumenten, die es zuletzt hinzugefügt hat, höchstens einmal in der Minute für alle Benutzer gemeinsam, die der verteilte Cache von Nextcloud solange behält (mit einem Memory-Cache, wie Nextcloud ihn empfiehlt; ohne einen fragt jedes Dashboard Paperless); ein Paperless, das nicht antwortet, ruht ebenfalls eine Minute, und das Widget sagt *Paperless ist gerade nicht erreichbar.* |

## Die Benachrichtigungen über neue Dokumente

| | |
| --- | --- |
| **Der Job** | `OCA\PaperlessUnifiedSearch\BackgroundJob\NotifyNewDocuments`, alle fünf Minuten, soweit der Cron von Nextcloud es zulässt, ein Lauf nach dem anderen; `occ background-job:list --class='OCA\PaperlessUnifiedSearch\BackgroundJob\NotifyNewDocuments'` zeigt ihn |
| **Er läuft** | Solange **Benutzern Benachrichtigungen über neue Dokumente erlauben** an ist, Paperless verbunden ist, ein Archiv-Konto existiert und jemand Benachrichtigungen gewählt hat; sonst vergisst er seinen Fortschritt und fragt nichts |
| **Paperless** | Eine Anfrage je Lauf nach den neuen Dokumenten, und nur für ein Dokument mit einer Datei im Archiv-Konto eine Anfrage je unterschiedlicher gespeicherter Suche, wie [unten](#anfragen-an-paperless) |
| **Grenzen** | 10 gespeicherte Suchen je Benutzer, von bis zu 500 Zeichen; 100 neue Dokumente je Lauf; 200 Dokumente, die auf ihre Dateien warten, bis zu zwei Tage lang |
| **Pause nach einem Fehler** | 10, 20 und 40 Minuten, dann eine Stunde, bis Paperless wieder antwortet |
| **Die Benachrichtigung** | Eine je Benutzer und Lauf; das Subject `new_documents`, das Objekt `paperless_documents` mit der ID des neuesten Dokuments und die Parameter `count` und `documents` mit dem Titel, der ID und dem Pfad der Datei von bis zu drei |

## Anfragen an Paperless

Jede Anfrage der App an Paperless ist ein `GET` von `<URL>/api/documents/`, von `/api/correspondents/`, `/api/document_types/` und `/api/tags/` für die Namen der Korrespondenten, Typen und Tags einer Seite und, beim Test der Verbindung, seiner neuesten Dokumente oder von `/api/ui_settings/` dafür, was das Konto des Tokens darf, vom Nextcloud-Server aus:

| | |
| --- | --- |
| **Header** | `Authorization: Token …`, `Accept: application/json` und `User-Agent: Nextcloud-Paperless-Unified-Search/<version>` |
| **Das Konto des Tokens** | **Anzeigen** (*View*) für Dokumente, Korrespondenten, Dokumenttypen, Tags und UI-Einstellungen und nichts anderes. Jede Antwort enthält nur die Dokumente, Korrespondenten, Typen und Tags, die das Konto ansehen darf: die ihm gehören, die ohne Eigentümer und die, deren Berechtigungen ihm oder einer seiner Gruppen das Ansehen erlauben; eine Notiz eines Dokuments passt auch ohne die Berechtigung, Notizen anzusehen. [Was jede Berechtigung der Suche gibt](configure.de.md#was-jede-berechtigung-der-suche-gibt) sagt, was jede von ihnen bewirkt |
| **Eine Suche** | `query` mit dem Begriff, `page`, `page_size=25` und `fields=id,title,created,correspondent,document_type,tags,__search_hit__`; bei Paperless 3 eine Anfrage, die es noch nicht verarbeiten kann, noch einmal mit `text` statt `query` |
| **Der Test der Verbindung** | `page_size=1` und `fields=id`; sobald Paperless antwortet, `/api/ui_settings/` ohne Parameter, mit 3 Sekunden für die Antwort und ohne zweiten Versuch, für die Warnungen zum Konto des Tokens. Von der Antwort liest die App nur `user.is_superuser`, `user.is_staff` und `permissions`; einem Konto, das seine UI-Einstellungen nicht ansehen darf, antwortet Paperless mit HTTP 403, und die Warnungen bleiben unbekannt. Bei einem Konto, das kein Superuser ist und Dokumente ansehen darf, fragt sie dann nach den 25 neuesten mit `ordering=-added`, `page=1` und `fields=id,correspondent,document_type,tags` und nach den Namen ihrer Korrespondenten, Typen und Tags, wieder mit 3 Sekunden und ohne zweiten Versuch, für die Warnung zu denen, die einem anderen Benutzer gehören |
| **Die neuesten Dokumente des Dashboards** | `ordering=-added`, `page=1`, `page_size=25` und `fields=id,title,added,correspondent,document_type,tags`, höchstens einmal in der Minute für alle Benutzer, mit 5 Sekunden für die Antwort und ohne zweiten Versuch |
| **Die neuen Dokumente der Benachrichtigungen** | `ordering=-id`, `page=1`, `page_size=100`, `fields=id,title` und `added__date__gte` mit dem Tag vor der letzten Antwort, was die Antwort kurz hält, mit einem Versuch, alle fünf Minuten, solange jemand Benachrichtigungen gewählt hat und Paperless antwortet; der erste Lauf fragt `page_size=1` ohne den Tag |
| **Eine gespeicherte Suche der Benachrichtigungen** | `query` mit dem Begriff, auf Paperless 3 bei einer Anfrage, die es nicht parsen kann, noch einmal `text` mit ihren Wörtern, `id__in` mit den neuen Dokumenten, die Dateien haben, `page=1`, `page_size` so viele wie diese und `fields=id`, mit einem Versuch, einmal je unterschiedlicher Suche und nur, wenn es solche Dokumente gibt |
| **Zeit** | 3 Sekunden zum Verbinden, 10 Sekunden für die Antwort |
| **Ein zweiter Versuch** | Nur für eine Anfrage, die innerhalb von 3,5 Sekunden fehlgeschlagen ist, bevor sie eine Verbindung hatte, und deren Adresse Nextcloud nicht verweigert hat |
| **Weiterleitungen** | Ohne **Paperless läuft in meinem lokalen Netzwerk** bis zu 5, nie an eine Adresse des lokalen Netzwerks; Nextcloud lässt das Token bei einer Weiterleitung auf ein anderes Schema, einen anderen Host oder Port weg, was Paperless dann ablehnt, und behält es bei einer innerhalb desselben Servers. Mit dem Schalter keine |

## Kompatibilität

| | Nextcloud 33 | Nextcloud 34 | Nextcloud 35 |
| --- | --- | --- | --- |
| **Paperless-ngx 3.x** (API 9) | End-to-End-Tests | End-to-End-Tests | End-to-End-Tests |
| **Paperless-ngx 2.16 bis 2.20** (API 9) | Unit-Tests | Unit-Tests | Unit-Tests |

- **Nextcloud:** Die End-to-End-Tests führen die App in jeder Version aus, die `appinfo/info.xml` unterstützt: in ihrem festgelegten Release in jedem Pull Request, der die App ändert, und bei jeder Änderung von `main`, wobei Renovate die festgelegten Releases binnen etwa einer Woche nach einem neuen Release nachzieht, und jeden Montag in ihrem aktuellen Release, sodass ein neues Release einer unterstützten Version, das die App bricht, binnen einer Woche ein Issue öffnet, und jeden Montag auch in der kommenden Version von Nextcloud, ihrer Beta oder ihrem Release Candidate.
- **Paperless-ngx:** Die End-to-End-Tests fragen eine Nachbildung von Paperless, die so antwortet wie Paperless-ngx 3.3.0: ihre Seiten, ihre Felder, ihre Header der Version, ihre Ausschnitte und ihre Ablehnung einer Anfrage, die noch getippt wird. Die Unit-Tests prüfen die Antworten von 2.20 und 3.3, etwa dass nur Paperless 3 den einfachen Modus seiner Suche bekommt, den Paperless 2 nicht kennt. Jede Anfrage nennt Version 9 der API, die Paperless-ngx 2.16 und neuer und jedes 3.x sprechen, also kann ein neues Release von Paperless die Treffer nicht unbemerkt ändern. Vertragstests prüfen die App gegen echtes Paperless-ngx, das letzte Release von 2.20 und das neueste von 3.x: die Felder, Daten, Ausschnitte, Seiten und Fehler einer Suche, die neuesten Dokumente des Dashboards und die Namen der Korrespondenten, Dokumenttypen und Tags, die ein Treffer zeigt, so wie die App sie erfragt. Sie laufen jede Woche und, als die Pflichtprüfungen `paperless (2)` und `paperless (3)`, in jedem Pull Request, der die App ändert, also erreicht keine Änderung, die sie bricht, `main`. Ein älteres Paperless antwortet mit HTTP 406, und der Test der Verbindung sagt das. Vor Paperless-ngx 2.20.13 prüft Paperless das Dokument einer Suche nach ähnlichen Dokumenten, den Begriff `like:`, nicht: Eine ID, die es nicht kennt, beantwortet es mit dem Fehler einer gescheiterten Suche, den die App für diesen Begriff allein als leeres Ergebnis wertet, auch wenn sein Index versagt, und für eines, das sein Konto nicht ansehen darf, findet es ähnliche Dokumente unter denen, die das Konto ansehen darf.
- **PHP:** 8.2 oder neuer, soweit Nextcloud es unterstützt; die Unit-Tests laufen auf 8.3, wie es PHPUnit 12 braucht.

## Datenflüsse

| Von | An | Was | Wann |
| --- | --- | --- | --- |
| Der Browser oder eine App des Benutzers | Nextcloud | Der Suchbegriff | Immer, wenn der Benutzer sucht |
| Nextcloud | Paperless | Der Begriff, die Seite und die Felder, mit denen zu antworten ist, und das API-Token | Mit eingeschaltetem **Verbundene Dienste durchsuchen**, nach der Wahl von **Paperless-Dokumente** im Smart Picker, über die API oder immer, wenn Paperless vertraut wird |
| Paperless | Nextcloud | Von jedem Dokument, das das Konto des Tokens ansehen darf: seine ID, sein Titel, sein Datum, die IDs seines Korrespondenten, seines Typs und seiner Tags und der Ausschnitt um den Begriff; die Versionen von Paperless und seiner API | In jeder Antwort |
| Nextcloud | Paperless | Die Anfrage danach, was das Konto des Tokens darf, und nach den IDs und Namen der Korrespondenten, Typen und Tags seiner neuesten Dokumente, mit dem API-Token | Bei jedem Test der Verbindung: **Verbindung testen und speichern**, `occ paperless_unified_search:setup`, `test` und `check` |
| Paperless | Nextcloud | Der Benutzer des Tokens mit seiner ID und seinem Namen, ob er ein Superuser oder Staff ist, seine Gruppen, seine Berechtigungen und die seiner Gruppen und die Einstellungen der Weboberfläche von Paperless für ihn; die App behält nur die Warnungen, die daraus folgen, ob er ein Superuser oder Staff ist, und aus seinen Berechtigungen | In jeder Antwort an ein Konto, das seine UI-Einstellungen ansehen darf |
| Nextcloud | Der Browser oder eine App des Benutzers | Von jedem Dokument, dessen Datei der Benutzer öffnen kann: sein Titel, sein Datum, die Namen seines Korrespondenten, seines Typs und bis zu zwei seiner Tags und sein Ausschnitt und der Link, die ID und der Pfad seiner Datei | In jeder Antwort |
| Nextcloud | Paperless | Die Anfrage nach den 25 Dokumenten, die Paperless zuletzt hinzugefügt hat, mit den Feldern, die das Dashboard zeigt, und das API-Token | Höchstens einmal in der Minute, wenn das Dashboard eines Benutzers das Widget zeigt |
| Paperless | Nextcloud | Von jedem dieser Dokumente, das das Konto des Tokens ansehen darf: seine ID, sein Titel, der Moment, an dem Paperless es hinzugefügt hat, und die IDs seines Korrespondenten, seines Typs und seiner Tags | In jeder Antwort |
| Nextcloud | Der Browser oder eine App des Benutzers | Von jedem der neuesten Dokumente, dessen Datei der Benutzer öffnen kann: sein Titel, der Tag, an dem Paperless es hinzugefügt hat, sein Korrespondent, sein Typ und bis zu zwei seiner Tags und der Link und die Vorschau seiner Datei | Immer, wenn das Dashboard das Widget zeigt |
| Nextcloud | Paperless | Die gespeicherten Suchen der Benutzer, die Benachrichtigungen gewählt haben, unter den IDs der neuen Dokumente mit Dateien, und das API-Token | Einmal je unterschiedlicher Suche, wenn ein neues Dokument seine Datei hat |
| Paperless | Nextcloud | Die IDs und Titel der Dokumente, die es seit dem letzten Lauf hinzugefügt hat, und die IDs derer, die jede Suche findet | Alle fünf Minuten, solange jemand Benachrichtigungen gewählt hat |
| Nextcloud | Der Browser oder eine App des Benutzers | Die Anzahl der neuen Dokumente, die der Benutzer gewählt hat und öffnen kann, und von den neuesten drei der Titel, die ID und der Pfad der Datei | Wenn der Client seine Benachrichtigungen abruft |

<div class="diagram-wide" markdown tabindex="0" role="region" aria-label="Diagramm des Datenflusses einer Suche">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/diagrams/the-data-flow-of-a-search.de.dark.svg">
  <img src="images/diagrams/the-data-flow-of-a-search.de.svg" alt="Der Datenfluss einer Suche: Ein Benutzer sucht nach invoice. Die globale Suche gibt den Begriff, den Cursor, das Limit, den Datumsfilter und den Benutzer an den Suchanbieter. Ohne Begriff, Archiv-Konto oder Verbindung antwortet der Anbieter ohne Treffer und fragt Paperless nicht. Sonst fragt der Paperless-Client, bis die Seite mit Treffern voll ist, Paperless-ngx mit dem Token nach der Seite mit 25 Treffern, die den nächsten Treffer enthält, innerhalb der Tage des Datumsfilters und nur nach den Feldern, die ein Treffer zeigt, und Paperless antwortet mit der ID, dem Titel, dem Datum, den IDs des Korrespondenten, des Typs und der Tags und dem Ausschnitt der Dokumente 412, 389, 371 und weiterer und damit, ob es eine nächste Seite gibt. Ein Aufruf des Dateisuchers deckt alle Dokumente dieser Seite ab und liefert für jedes eine Datei des Archiv-Kontos mit seiner Kennung oder keine. Für die Dokumente mit Datei fragen die Namen Paperless über den Client nach den Korrespondenten, den Typen und den Tags, die der Cache nicht enthält, mit einer Anfrage je Art, und behalten ihre Namen zehn Minuten lang. Das wiederholt sich für höchstens 4 Seiten von Paperless oder 3 Sekunden. Der Anbieter antwortet mit einem Eintrag für jedes Dokument mit Datei, bis zum Limit, mit der Vorschau der Datei oder dem Symbol ihres Typs, dem Titel, dem Datum in der Sprache des Benutzers, dem Korrespondenten, dem Typ und dem Ausschnitt, und mit dem Cursor des ersten Treffers, den er nicht durchgegangen ist.">
</picture>

</div>

Nextcloud speichert die Einstellungen in seiner App-Konfiguration, mit den Warnungen zum Konto des Tokens, die der letzte Test gefunden hat, das Token verschlüsselt in seinem Zugangsdatenmanager und den Verlauf der letzten 20 Probleme, ohne den Begriff, das Token und die Abfrage einer URL. Sein verteilter Cache behält die Namen der Korrespondenten, Typen und Tags zehn Minuten lang und von den 25 neuesten Dokumenten ihre IDs, Titel, die Momente, an denen Paperless sie hinzugefügt hat, und die IDs ihrer Korrespondenten, Typen und Tags eine Minute lang, für alle Benutzer; vom Text der Dokumente wird nichts gespeichert. Die [Architecture](architecture.md#data-flow) erklärt jeden Schritt und das [Sicherheitsdesign](security.md), was jeden davon schützt.

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
| Warning | The notifications about new Paperless documents try again in *n* seconds (*error*) | Der Job konnte Paperless nicht fragen oder die Dateien nicht suchen und pausiert |
| Warning | *n* saved search(es) of the notifications about new Paperless documents failed (*error*) | Paperless hat die anderen beantwortet, aber diese nicht, etwa einen Platzhalter, der zu lange dauerte: Sie haben in diesem Lauf nichts gefunden, das Protokoll nennt sie nicht, und der Job macht weiter |
| Warning | A user got no notification about new Paperless documents (*error*) | Die Dateien dieses Benutzers haben nicht geantwortet, oder Nextcloud hat die Benachrichtigung abgelehnt; die anderen haben ihre bekommen |
| Info | Paperless refused *n* saved search(es) of the notifications about new documents | Diese Suchen haben in diesem Lauf nichts gefunden; das Protokoll nennt sie nicht |
| Info | Paperless added *n* or more documents between two runs of the notifications about new documents: older ones get none | Ein großer Import |
| Debug | Paperless rejected the query of a search with HTTP *status* | Eine Anfrage, die noch getippt wird, oder eine Seite nach der letzten: kein Problem |

## Deinstallieren

1. **Verbindung trennen** in den Einstellungen. Das löscht die URL, das Token, die Schalter, das Archiv-Konto, die Version von Paperless, den Fortschritt der Benachrichtigungen über neue Dokumente und den Verlauf der Probleme; nur `diagnostics_pending` bleibt, mit den Schlüsseln, die Nextcloud von jeder App führt, etwa `enabled` und `installed_version`.
2. **Die App entfernen:** `occ app:remove paperless_unified_search` deaktiviert sie und löscht ihren Code; oder *Apps → Deaktivieren* und *Entfernen* im Browser. Nextcloud behält die App-Konfiguration einer entfernten App: `occ config:list paperless_unified_search` listet, was übrig ist, und `occ config:app:delete paperless_unified_search <key>` löscht einen Schlüssel. Die Wahlen der Benutzer bleiben als ihre Benutzereinstellungen `always_search` und `new_document_alerts`, letztere mit ihren gespeicherten Suchen, die `occ user:setting <user> paperless_unified_search always_search --delete` und dasselbe mit `new_document_alerts` löschen.
3. **In Paperless** löschst du das API-Token oder das Konto der Suche: Ein Token, das Nextcloud vergessen hat, funktioniert weiter, bis auch Paperless es vergisst.

Die Dateien des Archivs und ihre Freigaben bleiben, wie sie sind: Sie gehören dem Archiv-Konto und der Synchronisierung, nicht dieser App. Ohne **Verbindung trennen** bleibt das verschlüsselte Token im Zugangsdatenmanager von Nextcloud, unter `paperless_unified_search.api-token`, bis die App wieder installiert und getrennt wird.
