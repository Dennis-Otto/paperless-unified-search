---
description: "Paperless Unified Search in einer Demo mit einem Befehl ausprobieren, dann aus dem Nextcloud App Store, mit occ oder aus einem signierten Release installieren und aktuell halten."
---

# Installieren

Was die App braucht, die drei Wege, sie zu installieren, und wie sie aktuell bleibt. Sobald sie installiert ist, verbindet [Einrichten](configure.de.md) sie mit Paperless-ngx. Um sie zuerst bei der Arbeit zu sehen, ohne deine eigenen Server anzufassen, probiere die Demo aus.

## In drei Minuten ausprobieren

--8<-- "README.de.md:try-it"

## Was sie braucht

| | Braucht |
| --- | --- |
| **Nextcloud** | 33 bis 35 |
| **PHP** | 8.2 oder neuer, soweit die Version von Nextcloud es unterstützt |
| **Paperless-ngx** | Ein Paperless-ngx mit seiner REST-API; die [Kompatibilität](reference.de.md#kompatibilität) listet die Versionen, die die Tests abdecken |
| **Das Netzwerk** | Der Nextcloud-Server erreicht Paperless über HTTP oder HTTPS, verbindet sich innerhalb von 3 Sekunden und bekommt jede Antwort innerhalb von 10. Die Browser und die Apps der Benutzer sprechen nie mit Paperless |
| **Ein Paperless-Konto** | Eines, das die zu durchsuchenden Dokumente ansehen darf, mit einem API-Token, wie es [Einrichten](configure.de.md#ein-paperless-konto-für-die-suche) anlegt |
| **Das Archiv** | Jedes Dokument als Datei eines Nextcloud-Kontos, des Archiv-Kontos, mit der Kennung `[P<ID>]` im Namen, etwa die Dateien von [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) oder von [jeder anderen Synchronisierung](configure.de.md#funktioniert-mit-jeder-synchronisierung) |

Die App fügt der Datenbank von Nextcloud keine Tabelle hinzu, führt keinen Hintergrundjob aus und braucht keine Erweiterung von PHP über die von Nextcloud hinaus. Niemand braucht ein Konto in Paperless, um seine Dokumente zu finden: Die Freigaben von Nextcloud entscheiden, wer welches sieht.

## Aus dem App Store

Öffne als Administrator von Nextcloud **Apps**, suche nach *Paperless Unified Search* und wähle **Herunterladen und aktivieren**. Der App Store führt die App unter *Integration*, *Dateien* und *Suche*.

`occ` macht dasselbe auf der Befehlszeile: `app:install` lädt die App aus dem App Store herunter und aktiviert sie. Führe es als Benutzer des Webservers aus, so wie es zu deiner Installation passt:

```sh
# Nextcloud on a server of its own
sudo -u www-data php /var/www/nextcloud/occ app:install paperless_unified_search
# The Docker image of Nextcloud, whose service in compose.yaml is app
docker compose exec --user www-data app php occ app:install paperless_unified_search
# Nextcloud All-in-One
sudo docker exec --user www-data nextcloud-aio-nextcloud php occ app:install paperless_unified_search
# The snap of Nextcloud
sudo nextcloud.occ app:install paperless_unified_search
```

Der Rest dieser Dokumentation schreibt `occ` für den Befehl, der davon passt.

## Von Hand, aus einem signierten Release

Wo Nextcloud den App Store nicht erreicht, etwa hinter einer Firewall, die keine Anfrage hinauslässt, installierst du das Paket eines Releases:

1. Lade das Paket und seine Signatur vom [neuesten Release](https://github.com/Dennis-Otto/paperless-unified-search/releases/latest) herunter, im Browser oder mit der GitHub CLI:

    ```sh
    gh release download --repo Dennis-Otto/paperless-unified-search \
      --pattern 'paperless_unified_search.tar.gz*'
    ```

2. Prüfe, bevor du es entpackst, dass das Paket das ist, das der Release-Workflow gebaut und signiert hat: seine Build-Provenienz und seine Signatur mit dem Zertifikat der App, das der Nextcloud App Store ausgestellt hat.

    ```sh
    gh attestation verify paperless_unified_search.tar.gz --repo Dennis-Otto/paperless-unified-search
    curl -fsSLO https://raw.githubusercontent.com/nextcloud/app-certificate-requests/master/paperless_unified_search/paperless_unified_search.crt
    openssl x509 -in paperless_unified_search.crt -pubkey -noout > paperless_unified_search.pub
    openssl dgst -sha512 -verify paperless_unified_search.pub \
      -signature paperless_unified_search.tar.gz.sig paperless_unified_search.tar.gz
    ```

    Der letzte Befehl sagt `Verified OK`. [Verify a release](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/SECURITY.md#verify-a-release) in der Sicherheitsrichtlinie prüft auch das Release selbst und seine SBOM.

3. Entpacke es in einen Ordner für Apps, in den Nextcloud schreiben darf, `custom_apps` im Docker-Image von Nextcloud und `apps` in den meisten anderen Installationen (`apps_paths` in der `config.php` nennt sie), und übergib es dem Benutzer des Webservers:

    ```sh
    sudo tar -xzf paperless_unified_search.tar.gz -C /var/www/nextcloud/apps
    sudo chown -R www-data:www-data /var/www/nextcloud/apps/paperless_unified_search
    ```

4. Aktiviere es:

    ```sh
    occ app:enable paperless_unified_search
    ```

Das Paket trägt die Signatur jeder seiner Dateien, also besteht die Prüfung der Integrität seines Codes durch Nextcloud wie bei einer App aus dem App Store.

## Updates

Nextcloud meldet seinen Administratoren eine neue Version einer App aus dem App Store; **Apps → Aktualisierungen** oder `occ app:update paperless_unified_search` installiert sie. Ein von Hand installiertes Paket wird so aktualisiert, wie es installiert wurde: herunterladen, prüfen und an der Stelle des Ordners der alten Version entpacken. Die Einstellungen bleiben, wie sie sind.

Die [Änderungen](changelog.md) sagen, was sich in jeder Version geändert hat, und die [Ankündigungen](https://github.com/Dennis-Otto/paperless-unified-search/discussions/categories/announcements) in den Diskussionen oder *Watch → Custom → Releases* auf GitHub melden dir jedes Release.

## Weiter

- [Einrichten](configure.de.md) verbindet die App mit Paperless und teilt das Archiv mit seinen Lesern.
- [Deinstallieren](reference.de.md#deinstallieren) sagt, wie du die App wieder entfernst und was zurückbleibt.
