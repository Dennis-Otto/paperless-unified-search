#!/usr/bin/env python3
"""The settings as code, the checklist and the changelog of a repository; the code of
each task is a module of the package blueprint/ next to this file.

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
"""

import sys

if __name__ == "__main__":
    # Before the import: like the single file it was, the tool leaves no __pycache__
    # in the repository.
    sys.dont_write_bytecode = True
    # import blueprint finds the package next to this file: a package wins over a module.
    from blueprint.cli import main

    sys.exit(main())
