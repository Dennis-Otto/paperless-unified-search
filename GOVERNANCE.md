# Governance

Paperless Unified Search is maintained by Dennis Otto.

## Maintainers and access

| Person | Role | Access |
| --- | --- | --- |
| [@Dennis-Otto](https://github.com/Dennis-Otto) | Maintainer | Repository administration, releases and security advisories |

## Roles and responsibilities

| Role | Who | Responsibilities |
| --- | --- | --- |
| Maintainer | the people in the table above | Reads every issue and has the last word on it. Reviews the pull requests of others and merges pull requests. Answers vulnerability reports within the times of [SECURITY.md](SECURITY.md) and publishes their advisories. Merges the release pull requests that wait for a person. Keeps the secrets, the settings as code and the accepted findings current, and the documentation true to the latest release. |
| Contributor | everybody who opens a pull request | Follows [CONTRIBUTING.md](CONTRIBUTING.md): tests for every new function and every fix, the coding standards, a sign-off on every commit and an entry under *Unreleased* for every change for users. |
| Reporter | everybody who opens an issue or reports a vulnerability | Names the release, the set-up and the steps to reproduce, answers the questions in the issue, and reports a vulnerability only privately. |
| Bots | the workflows in `.github/workflows/` | Do the routine work that [Automation](#automation) describes, each with the least permissions it needs. What they don't merge or publish on their own waits for the maintainer. |

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

The owner has designated a successor through [GitHub's account successor setting](https://docs.github.com/en/account-and-profile/setting-up-and-managing-your-personal-account-on-github/managing-access-to-your-personal-repositories/maintaining-ownership-continuity-of-your-personal-accounts-repositories). Should the owner die, the successor can, once GitHub has confirmed it, transfer the repository to their own account or to an organization and carry it on there with administrator access, or archive it. With the maintainer and the successor, the bus factor of the project is 2. The app in the Nextcloud App Store and its certificate don't pass with the repository: for them the successor needs the maintainer or the App Store. These keep the project able to continue as well:

- Everything that builds, tests, releases and protects the project is in this repository: the code and its tests, the workflows, the settings as code and the documentation. Anyone can fork it under its license and carry on.
- The bots keep the dependencies current and publish releases of dependency updates, as long as every check passes.

## Security

Potential vulnerabilities follow [SECURITY.md](SECURITY.md) and are handled privately until a fix and a coordinated disclosure are ready.
