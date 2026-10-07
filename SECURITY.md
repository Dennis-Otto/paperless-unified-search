# Security policy

## Supported versions

Security fixes are provided for the latest released version of Paperless Unified Search.

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability. Use GitHub's private vulnerability reporting for this repository:

<https://github.com/Dennis-Otto/paperless-unified-search/security/advisories/new>

Include the affected version, configuration, reproduction steps, and potential impact. Reports will be acknowledged as soon as practical.

## Findings of code scanning

CodeQL and OpenSSF Scorecard report their findings in the repository's Security tab. The Findings workflow of the [issue assistant](https://github.com/Dennis-Otto/issue-assistant#findings) dismisses the findings that `.github/findings.toml` accepts, each with its reason, and fails while any other finding is open. It names an open finding only by the number and link of its alert, which only maintainers can open; nothing about a possible vulnerability becomes a public issue.

## Secrets

Paperless API tokens, Nextcloud credentials, private signing keys, production URLs, document metadata, personal files, and logs containing those values must never be committed to this repository.

The application stores the Paperless API token through Nextcloud's server-side credentials manager. The token is never returned by an application endpoint or embedded in browser-side code. Local `.env` files, key files, credential exports, and local configuration variants are ignored, and every push and pull request is scanned with Gitleaks.
