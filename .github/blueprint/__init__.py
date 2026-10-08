"""The settings of a repository as code, the checklist of what only a person can do,
and the changelog of its releases.

    python3 blueprint.py settings check     compare .github/repository.toml with GitHub
    python3 blueprint.py settings apply     make GitHub match .github/repository.toml
    python3 blueprint.py checklist          the steps outside the API, and which are done
    python3 blueprint.py changelog ...      the changelog of a release (the release bot)
    python3 blueprint.py unreleased ...     an entry under Unreleased (the other bots)
    python3 blueprint.py notices ...        the licenses of the third-party components

Run it in the root of a repository made from the blueprint, with the GitHub CLI `gh`
signed in as an administrator. It needs Python 3.12 and nothing else. It never reads,
prints or sets the value of a secret: it only says which secrets are missing and how
to set them. https://github.com/Dennis-Otto/repo-blueprint
"""
