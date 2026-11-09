# Governance

Paperless Unified Search is maintained by Dennis Otto.

## Maintainers and access

| Person | Role | Access |
| --- | --- | --- |
| [@Dennis-Otto](https://github.com/Dennis-Otto) | Maintainer | Repository administration, releases and security advisories |

Nobody gets access on their own. GitHub gives a new collaborator no access until a maintainer invites them by hand and they accept, in an organization with the role that the invitation names, and the settings as code give no person any access. Nobody needs access to propose a change: a pull request from a fork is enough.

Before a person gets escalated permissions, such as the right to push, to merge or to administer the repository, the secrets of its environments, its apps or its security advisories, the maintainers review them: a record of contributions to the project that the maintainers reviewed, an identity that the maintainers can trace, two-factor authentication on their account on GitHub and verified signatures on their commits. A person gets the least access that their task needs, recorded in a public issue or pull request of the project, and loses it when the task ends; the secrets that they could use are replaced then, as [SECURITY.md](SECURITY.md#secrets-and-credentials) says.

## Roles and responsibilities

| Role | Who | Responsibilities |
| --- | --- | --- |
| Maintainer | the people in the table above | Reads every issue and has the last word on it. Reviews the pull requests of others and merges pull requests. Answers vulnerability reports within the times of [SECURITY.md](SECURITY.md) and publishes their advisories. Merges the release pull requests that wait for a person. Keeps the secrets, the settings as code and the accepted findings current, and the documentation true to the latest release. |
| Contributor | everybody who opens a pull request | Follows [CONTRIBUTING.md](CONTRIBUTING.md): tests for every new function and every fix, the coding standards, a sign-off and a verified signature on every commit and an entry in the changelog for every change for users, a fragment in `changelog.d/` or a line under *Unreleased*. |
| Reporter | everybody who opens an issue or reports a vulnerability | Names the release, the set-up and the steps to reproduce, answers the questions in the issue, and reports a vulnerability only privately. |
| Bots | the workflows in `.github/workflows/` | Do the routine work that [Automation](#automation) describes, each with the least permissions it needs. What they don't merge or publish on their own waits for the maintainer. |

## Decisions

Decisions are discussed in public issues and pull requests whenever they contain no security-sensitive information. They prioritize secure defaults, compatibility with supported Nextcloud releases, backward compatibility, maintainability, and the needs of users running their own infrastructure. The maintainer has final responsibility for releases, App Store publication, repository access, security responses and project direction. Sustained contributors may be invited to help triage issues or review changes.

## Reviews

Every change reaches `main` through a pull request that passes all required checks: the tests and checks of the project, the lint of the workflows, the licenses (REUSE) and sign-offs (DCO), CodeQL, the dependency review and the secret scan.

- **How a pull request is reviewed.** The required checks run first, on every pull request and against the latest `main`; the PR coach explains each failure, and the fix bot fixes what a command can. Then the maintainer reads the whole change of every pull request of another person and asks for changes in a review or merges it. The pull requests of the maintainers pass the same checks, and the update pull requests of the bots merge on their own once every check passes, as [Automation](#automation) says.
- **What the review checks.** That the change does what its title and description say, and nothing else; that tests cover each change of behavior, and that a bug fix comes with a test that fails without it; that the documentation and the changelog say what changes for users; that no secret, no personal data and no binary file that nobody can review comes with it; that a workflow keeps the least permissions and puts no untrusted input into a script; and that a new dependency is needed, as [SECURITY.md](SECURITY.md#dependencies) says.
- **What is acceptable.** A pull request merges once every required check passes on the latest `main`, every commit carries a sign-off and a verified signature, it meets the requirements of [CONTRIBUTING.md](CONTRIBUTING.md#requirements-for-changes), and the maintainer accepts it by merging it. What no check can judge, such as the design of a feature, the maintainer decides in the pull request.

## Automation

Bots do the routine work, each with the least permissions it needs:

- **Renovate** updates the dependencies, the actions and the images, and Dependabot the Features of the dev container; routine updates merge on their own when every check passes.
- **The release bot** keeps a pull request for the next release, from the titles of the merged pull requests. A release of dependency updates merges and publishes itself, once a day or at once with the fix of a vulnerability; every other release waits for the maintainer.
- **The branch bot** brings the pull requests that wait for auto-merge up to date with `main` one at a time, in the order in which their auto-merge was turned on, so that each merges once its checks pass again.
- **The issue assistant** analyzes new issues, keeps their labels and lifecycle, and closes fixed issues with the release that ships the fix.
- **The Findings workflow** keeps the findings of code scanning either fixed or accepted with a reason.

## Continuity

If the maintainer can no longer maintain the project, the preferred outcome is a transparent handover to a trusted active contributor, announced in the repository. Until then, the repository should be archived rather than presented as actively maintained.

The owner has designated a successor through [GitHub's account successor setting](https://docs.github.com/en/account-and-profile/setting-up-and-managing-your-personal-account-on-github/managing-access-to-your-personal-repositories/maintaining-ownership-continuity-of-your-personal-accounts-repositories). Should the owner die, the successor can, once GitHub has confirmed it, transfer the repository to their own account or to an organization and carry it on there with administrator access, or archive it. With the maintainer and the successor, the bus factor of the project is 2. The app in the Nextcloud App Store and its certificate don't pass with the repository: for them the successor needs the maintainer or the App Store. These keep the project able to continue as well:

- Everything that builds, tests, releases and protects the project is in this repository: the code and its tests, the workflows, the settings as code and the documentation. Anyone can fork it under its license and carry on.
- The bots keep the dependencies current and publish releases of dependency updates, as long as every check passes.

## Security

Potential vulnerabilities follow [SECURITY.md](SECURITY.md) and are handled privately until a fix and a coordinated disclosure are ready.
