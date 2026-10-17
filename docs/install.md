---
description: "Try Paperless Unified Search in a one-command demo, then install it from the Nextcloud App Store, with occ or from a signed release, and keep it up to date."
---

# Install

What the app needs, the three ways to install it, and how it stays up to date. Once it is installed, [Configure](configure.md) connects it to Paperless-ngx. To see it at work first, without touching your own servers, try the demo.

## Try it in three minutes

--8<-- "README.md:try-it"

## What it needs

| | Needs |
| --- | --- |
| **Nextcloud** | 33 to 35 |
| **PHP** | 8.2 or newer, as the release of Nextcloud supports it |
| **Paperless-ngx** | A Paperless-ngx with its REST API; [Compatibility](reference.md#compatibility) lists the versions that the tests cover |
| **The network** | The Nextcloud server reaches Paperless over HTTP or HTTPS, connects within 3 seconds and gets every answer within 10. The browsers and the apps of the users never talk to Paperless |
| **A Paperless account** | One that may view the documents to search, with an API token, as [Configure](configure.md#a-paperless-account-for-the-search) sets it up |
| **The archive** | Every document as a file of one Nextcloud account, the archive account, with the marker `[P<ID>]` in its name, such as the files of [Paperless Sync](https://github.com/Dennis-Otto/paperless-sync) or of [any other synchronization](configure.md#works-with-any-synchronization) |

The app adds no table to the database of Nextcloud, runs no background job and needs no extension of PHP beyond those of Nextcloud. Nobody needs an account in Paperless to find its documents: Nextcloud's shares decide who sees which one.

## From the App Store

As an administrator of Nextcloud, open **Apps**, search for *Paperless Unified Search* and select **Download and enable**. The App Store lists the app under *Integration*, *Files* and *Search*.

`occ` does the same on the command line: `app:install` downloads the app from the App Store and enables it. Run it as the user of the web server, in the way that fits your installation:

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

The rest of this documentation writes `occ` for whichever of these commands fits.

## By hand, from a signed release

Where Nextcloud can't reach the App Store, as behind a firewall that lets no request out, install the package of a release:

1. Download the package and its signature from the [latest release](https://github.com/Dennis-Otto/paperless-unified-search/releases/latest), in the browser or with the GitHub CLI:

    ```sh
    gh release download --repo Dennis-Otto/paperless-unified-search \
      --pattern 'paperless_unified_search.tar.gz*'
    ```

2. Check that the package is the one that the release workflow built and signed, before you unpack it: its build provenance, and its signature with the certificate of the app that the Nextcloud App Store issued.

    ```sh
    gh attestation verify paperless_unified_search.tar.gz --repo Dennis-Otto/paperless-unified-search
    curl -fsSLO https://raw.githubusercontent.com/nextcloud/app-certificate-requests/master/paperless_unified_search/paperless_unified_search.crt
    openssl x509 -in paperless_unified_search.crt -pubkey -noout > paperless_unified_search.pub
    openssl dgst -sha512 -verify paperless_unified_search.pub \
      -signature paperless_unified_search.tar.gz.sig paperless_unified_search.tar.gz
    ```

    The last command says `Verified OK`. [Verify a release](https://github.com/Dennis-Otto/paperless-unified-search/blob/main/SECURITY.md#verify-a-release) in the security policy also checks the release itself and its SBOM.

3. Unpack it into a folder of apps that Nextcloud may write to, `custom_apps` in the Docker image of Nextcloud and `apps` in most other installations (`apps_paths` in `config.php` names them), and give it to the user of the web server:

    ```sh
    sudo tar -xzf paperless_unified_search.tar.gz -C /var/www/nextcloud/apps
    sudo chown -R www-data:www-data /var/www/nextcloud/apps/paperless_unified_search
    ```

4. Enable it:

    ```sh
    occ app:enable paperless_unified_search
    ```

The package carries the signature of every one of its files, so Nextcloud's check of the integrity of its code passes, as for an app from the App Store.

## Updates

Nextcloud tells its administrators of a new version of an app from the App Store; **Apps → Updates**, or `occ app:update paperless_unified_search`, installs it. A package installed by hand is updated the same way as it was installed: download, verify, and unpack it in place of the folder of the old version. The settings stay as they are.

The [changelog](changelog.md) says what changed in each version, and the [announcements](https://github.com/Dennis-Otto/paperless-unified-search/discussions/categories/announcements) in the discussions, or *Watch → Custom → Releases* on GitHub, tell you of every release.

## Next

- [Configure](configure.md) connects the app to Paperless and shares the archive with its readers.
- [Uninstall](reference.md#uninstall) says how to remove the app again, and what stays behind.
