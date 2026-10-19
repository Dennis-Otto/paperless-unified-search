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

- Every pull request runs CodeQL, a Gitleaks secret scan of its commits and, for changed dependencies, a review against known vulnerabilities, and one that adds an alert of CodeQL of high or critical severity can't merge. CodeQL runs after every change of `main` as well, Gitleaks every week over the whole history, and GitHub's secret scanning with push protection stops a known kind of secret before it lands and reports generic ones, such as private keys, connection strings and passwords. OpenSSF Scorecard checks the practices of the repository every week.
- Actions are pinned to commit hashes, only those of GitHub and of a list in the settings as code may run, tokens get the least permissions they need, and Renovate keeps actions, dependencies and images current, with the updates that fix a vulnerability at once.
- OSV-Scanner checks every lock file against the OSV database of known vulnerabilities: in every pull request that changes a lock file, after every change of `main` and every week.
- Harden-Runner records the network traffic of every job of the workflows, so that a connection that doesn't belong there shows.
- Releases carry SBOMs of what they ship, under their name and version, as SPDX and as CycloneDX, the licenses of their third-party components (`THIRD_PARTY_NOTICES.md`), an OpenVEX document of the advisories that the project accepts with their reasons, and the signed provenance of an isolated build (SLSA Build Level 3); their tags are signed without a key by the release workflow (gitsign), which builds no release on a tag that it didn't sign; they are immutable once published and are verified as their users can after every release and every week.

## Findings of code scanning

CodeQL and OpenSSF Scorecard report their findings in the repository's Security tab. A new alert of CodeQL of high or critical severity, or a new error, stops the merge of the pull request that adds it, which shows the alert at its lines. The Findings workflow of the [issue assistant](https://github.com/Dennis-Otto/issue-assistant#findings) dismisses the findings that `.github/findings.toml` accepts, each with its reason, and fails while any other finding is open. It names an open finding only by the number and link of its alert, which only maintainers can open; nothing about a possible vulnerability becomes a public issue.

## Verify a release

A release is immutable once it is published, and GitHub signs an attestation of it that ties its tag to its commit and to every file it carries. The release workflow also signs the build provenance of every file with [Sigstore](https://www.sigstore.dev/), using a certificate for the workflow of this repository that is valid for ten minutes. No long-lived signing key exists, so none can leak, and none is stored where the releases are downloaded. The [GitHub CLI](https://cli.github.com/) checks both signatures with the public keys of GitHub and Sigstore, which it fetches itself:

```sh
gh release verify vX.Y.Z --repo Dennis-Otto/paperless-unified-search
gh release download vX.Y.Z --repo Dennis-Otto/paperless-unified-search --pattern 'paperless_unified_search.tar.gz*'
gh attestation verify paperless_unified_search.tar.gz --repo Dennis-Otto/paperless-unified-search --signer-workflow Dennis-Otto/paperless-unified-search/.github/workflows/build-release.yml
```

The package is signed with the certificate of the app as well, which Nextcloud checks when it installs or updates the app. Its public certificate, in [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests), checks the detached signature `paperless_unified_search.tar.gz.sig` of the release:

```sh
curl -fsSLO https://raw.githubusercontent.com/nextcloud/app-certificate-requests/master/paperless_unified_search/paperless_unified_search.crt
openssl x509 -in paperless_unified_search.crt -pubkey -noout > paperless_unified_search.pub
openssl dgst -sha512 -verify paperless_unified_search.pub -signature paperless_unified_search.tar.gz.sig paperless_unified_search.tar.gz
```

The private key of the certificate is a secret of the environment `release`, which only the release workflow on `main` can use; it never reaches the App Store.

Every release describes what it ships in an SBOM, as SPDX in `paperless-unified-search.spdx.json` and as CycloneDX in `paperless-unified-search.cdx.json`: the package that you install, with every component inside it, under the name `paperless-unified-search` and the version of the release. A scanner such as [Grype](https://github.com/anchore/grype) checks it against the advisories it knows. When the project accepts an advisory in `osv-scanner.toml`, with its reason, the release carries the OpenVEX document `paperless-unified-search.openvex.json` too, which tells the scanner that the advisory doesn't affect the release; without that document, leave out `--vex`:

```sh
gh release download vX.Y.Z --repo Dennis-Otto/paperless-unified-search --pattern 'paperless-unified-search.*.json'
grype sbom:paperless-unified-search.spdx.json --vex paperless-unified-search.openvex.json
```

## Verify the website

The Docs workflow publishes the website on GitHub Pages and signs the build provenance of what it publishes with Sigstore, as the release workflow signs a release: `artifact.tar` of the artifact `github-pages` of its run, which GitHub keeps for a day. It then asks the published website for the home page of each of its languages, and for an address that leads nowhere, which must answer with 404, and fails when one doesn't. Take the artifact of the run that published the website, and check it with the GitHub CLI:

```sh
gh run download RUN_ID --repo Dennis-Otto/paperless-unified-search --name github-pages
gh attestation verify artifact.tar --repo Dennis-Otto/paperless-unified-search --signer-workflow Dennis-Otto/paperless-unified-search/.github/workflows/docs.yml
```

## Assurance case

This section argues why the way the project is changed, built and delivered meets the requirements of this policy. The software itself, what it protects, where its trust boundaries lie when it runs and which risks remain, is the subject of [docs/security.md](docs/security.md).

### Threat model

An attacker would most likely try to bring malicious code into `main`, through a pull request or through a dependency, an action or an image; to make a workflow run code that it shouldn't run, or leak a secret; to replace a release on its way to the users; or to keep a known vulnerability in a release. A report of a vulnerability is itself sensitive until its fix is released.

### Trust boundaries

1. **Outside contributors → pull requests.** Their code is untrusted. The workflows of their pull requests run only after the maintainer approves them, with a read-only token and without secrets.
2. **Pull requests → `main`.** A change reaches `main` only through a pull request that passes every required check, squashed into one signed commit; nobody can push to `main` directly, force-push it or delete it. The rules of `main` and of the release tags have no exceptions, not even for administrators, but one: only the release app may create a release tag, which the release workflow signs.
3. **`main` → secrets.** Every secret lives in an environment that only workflows on `main` can use, and the jobs that use one run no code of a pull request.
4. **Third-party code → the build.** Dependencies are pinned by version and hash, actions by commit hash and images by digest. An update waits a week after its release, unless it fixes a vulnerability, and passes the same checks as every other change. Updates of the [blueprint](https://github.com/Dennis-Otto/repo-blueprint) come only from a release that is immutable, on the blueprint's `main` and signed by its release workflow, which the blueprint bot verifies first; the workflows of the [issue assistant](https://github.com/Dennis-Otto/issue-assistant) come with them, from a release of the issue assistant that the blueprint verified the same way.
5. **The release → its users.** The release workflow builds every release from its tagged commit, once it has checked that it signed the tag itself, at the commit of the release; GitHub keeps it immutable, and its signatures let users check it, as [Verify a release](#verify-a-release) shows.
6. **Issues → the issue assistant.** Anyone can write an issue, so its text is data for an AI that only reads; a job without the AI checks the answer before anything is posted.

### Secure design principles

- **Least privilege:** tokens are read-only unless a job needs more, and then get only what it needs; each secret is in the one environment that needs it.
- **Fail-safe defaults:** a failed check, a missing secret or an open finding stops a merge or a release instead of being skipped; workflows of outside contributors wait for approval.
- **Complete mediation:** every change of `main` passes the same required checks, nobody can bypass the rules of `main`, and nobody can move or delete a release tag; only the release app creates one.
- **Separation of privilege:** the job in which the AI reads untrusted text, such as an issue, holds no token that writes, and the job that writes runs no AI; only the release workflow on `main` publishes releases.
- **Least common mechanism:** the secrets of the releases and those of the issue assistant are in separate environments, so a job that needs one never holds the other.
- **Economy of mechanism:** one script, `scripts/check.sh`, runs the checks locally and in the CI; one workflow builds, signs and publishes every release.
- **Open design:** the workflows, the settings as code and this policy are public; the protection relies on no secret about how it works.
- **Psychological acceptability:** the dev container has every tool of the checks, and the hook before every push runs the same checks as the CI, so the secure way is the easy one.

### Common weaknesses

How the [OWASP Top 10 CI/CD security risks](https://owasp.org/www-project-top-10-ci-cd-security-risks/) are countered:

| Risk | Countermeasure |
| --- | --- |
| Insufficient flow control mechanisms | pull requests only, every required check, no new alert of CodeQL of high severity, a linear history, release tags that never move and that only the release app creates |
| Inadequate identity and access management | administrator access only for the maintainers; bots act as GitHub Apps or with the token of their workflow |
| Dependency chain abuse | lock files with hashes, pins by commit hash and digest, updates only a week after their release, a weekly refresh of the lock files that waits for the maintainer, the dependency review and OSV-Scanner |
| Poisoned pipeline execution | no code of a pull request runs with a secret; actionlint and zizmor audit every workflow |
| Insufficient pipeline-based access controls | permissions per job, environments that only `main` can use, actions pinned to commit hashes and only listed actions, as settings of the repository |
| Insufficient credential hygiene | the secret scan with push protection and generic patterns, tokens of the release app that expire after an hour, no secret printed or committed |
| Insecure system configuration | the settings as code, which the settings bot applies after every change and every week, and OpenSSF Scorecard every week |
| Ungoverned usage of third-party services | only the services of the workflows in this repository, each in the jobs that need it, with the least permissions |
| Improper artifact integrity validation | immutable releases with signed provenance, verified after every release and every week; updates only to a verified release of the blueprint |
| Insufficient logging and visibility | the logs of every run, and Harden-Runner, which records the network traffic of every job |

In the code, CodeQL looks for the weaknesses of the [CWE Top 25](https://cwe.mitre.org/top25/) with its extended security queries, on every pull request and every push to `main`. CodeQL has no PHP; Psalm checks the types of the PHP code at its strictest level, and its taint analysis follows untrusted input, the parameters of a request to a controller, the term of a search and the answers of other servers, to HTML, SQL, a shell, a file, a request to another server or a redirection, in every pull request and every push to `main`, with each path in code scanning. A canary, a controller with a path to each kind of sink, proves in every run that the analysis still finds them. How the code counters the weaknesses that matter for it is part of [docs/security.md](docs/security.md).
