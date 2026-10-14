"""The settings of a repository as code, the checklist of what only a person can do,
and the changelog of its releases.

    python3 blueprint.py settings check     compare .github/repository.toml with GitHub
    python3 blueprint.py settings apply     make GitHub match .github/repository.toml
    python3 blueprint.py checklist          the steps outside the API, and which are done
    python3 blueprint.py changelog ...      the changelog of a release (the release bot)
    python3 blueprint.py fragments          check the fragments of the changelog in changelog.d/
    python3 blueprint.py notices ...        the licenses of the third-party components
    python3 blueprint.py update-commit ...  the pull request of an update (the blueprint bot)
    python3 blueprint.py bot-health ...     one issue per failing bot (the bot-health bot)
    python3 blueprint.py diagrams extract   the ```mermaid blocks as sources and pictures
    python3 blueprint.py diagrams ...       keep, commit and report (the diagram bots)
    python3 blueprint.py pages [--fix]      the READMEs and docs in one column, for phones
    python3 blueprint.py coach ...          how to fix a failed check, in one comment (the PR coach)

Run it in the root of a repository made from the blueprint, with the GitHub CLI `gh`
signed in as an administrator. It needs Python 3.12 and nothing else. It never reads,
prints or sets the value of a secret: it only says which secrets are missing and how
to set them. https://github.com/Dennis-Otto/repo-blueprint
"""
