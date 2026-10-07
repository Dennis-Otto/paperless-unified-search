# Security policy

## Supported versions

Security fixes are provided for the latest release.

## Reporting a vulnerability

Please do not open a public issue, discussion or pull request for a suspected vulnerability. Use GitHub's private vulnerability reporting for this repository:

<https://github.com/Dennis-Otto/paperless-unified-search/security/advisories/new>

Include the affected release, the set-up, reproduction steps and the potential impact. Reports in English or German are welcome.

## What happens next

| Step | Target |
| --- | --- |
| Acknowledgement of the report | within 7 days |
| First assessment, including whether the report is accepted | within 14 days |
| Fix released for a confirmed vulnerability | as fast as possible, at the latest within 90 days |
| Public disclosure | when the fixed release is available, in a GitHub security advisory and the release notes |

If a fix needs longer, for example because the cause lies in an upstream project, you receive an update at least every 14 days. Reporters are credited in the advisory and the release notes unless they prefer to stay anonymous.

## Secrets

Paperless API tokens, Nextcloud credentials, private signing keys, production URLs, document metadata, personal files, and logs containing those values must never be committed to this repository.

The application stores the Paperless API token through Nextcloud's server-side credentials manager. The token is never returned by an application endpoint or embedded in browser-side code. Local `.env` files, key files, credential exports, and local configuration variants are ignored, and every push and pull request is scanned with Gitleaks.

## How the project keeps itself secure

- Every pull request and every push to `main` runs CodeQL, a Gitleaks secret scan and, for changed dependencies, a review against known vulnerabilities. OpenSSF Scorecard checks the practices of the repository every week.
- Actions are pinned to commit hashes, tokens get the least permissions they need, and Renovate keeps actions, dependencies and images current, with the updates that fix a vulnerability at once.
- OSV-Scanner checks every lock file against the OSV database of known vulnerabilities, on every pull request and every week.
- Harden-Runner records the network traffic of every job of the workflows, so that a connection that doesn't belong there shows.
- Releases carry an SBOM as SPDX and as CycloneDX, the licenses of their third-party components (`THIRD_PARTY_NOTICES.md`), an OpenVEX document of the advisories that the project accepts with their reasons, and signed build provenance, are immutable once published, and are verified as their users can after every release and every week.

## Findings of code scanning

CodeQL and OpenSSF Scorecard report their findings in the repository's Security tab. The Findings workflow of the [issue assistant](https://github.com/Dennis-Otto/issue-assistant#findings) dismisses the findings that `.github/findings.toml` accepts, each with its reason, and fails while any other finding is open. It names an open finding only by the number and link of its alert, which only maintainers can open; nothing about a possible vulnerability becomes a public issue.
