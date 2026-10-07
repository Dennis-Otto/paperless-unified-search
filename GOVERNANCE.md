# Governance

Paperless Unified Search is maintained by Dennis Otto.

## Maintainers and access

| Person | Role | Access |
| --- | --- | --- |
| [@Dennis-Otto](https://github.com/Dennis-Otto) | Maintainer | Repository administration, releases and security advisories |

## Decisions

Decisions are discussed in public issues and pull requests whenever they contain no security-sensitive information. They prioritize secure defaults, compatibility with supported Nextcloud releases, backward compatibility, maintainability, and the needs of users running their own infrastructure. The maintainer has final responsibility for releases, App Store publication, repository access, security responses and project direction. Sustained contributors may be invited to help triage issues or review changes.

## Reviews

Every change reaches `main` through a pull request that passes all required checks: the tests and checks of the project, the lint of the workflows, the licenses (REUSE) and sign-offs (DCO), CodeQL, the dependency review and the secret scan.

## Automation

Bots do the routine work, each with the least permissions it needs:

- **Renovate** updates the dependencies, the actions and the images, and Dependabot the Features of the dev container; routine updates merge on their own when every check passes.
- **The release bot** keeps a pull request for the next release, from the titles of the merged pull requests. A release of dependency updates merges and publishes itself; every other release waits for the maintainer.
- **The branch bot** brings every pull request that waits for auto-merge up to date after each change of `main`, so that it merges once its checks pass again.
- **The issue assistant** analyzes new issues, keeps their labels and lifecycle, and closes fixed issues with the release that ships the fix.
- **The Findings workflow** keeps the findings of code scanning either fixed or accepted with a reason.

## Continuity

If the maintainer can no longer maintain the project, the preferred outcome is a transparent handover to a trusted active contributor, announced in the repository. Until then, the repository should be archived rather than presented as actively maintained.

## Security

Potential vulnerabilities follow [SECURITY.md](SECURITY.md) and are handled privately until a fix and a coordinated disclosure are ready.
