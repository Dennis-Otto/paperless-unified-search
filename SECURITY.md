# Security policy

## Reporting a vulnerability

Please use GitHub's private vulnerability reporting for this repository:

<https://github.com/Dennis-Otto/paperless-unified-search/security/advisories/new>

Do not open a public issue containing a vulnerability, API token, password, private key, production hostname, internal address, log excerpt with credentials, or other sensitive deployment information.

## Secrets

This repository must never contain deployment secrets. Runtime credentials belong only in Nextcloud's server-side credentials manager. Local `.env` files, key files, credential exports, and local configuration variants are ignored, and every push and pull request is scanned with Gitleaks.
