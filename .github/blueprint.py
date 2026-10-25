#!/usr/bin/env python3
"""The settings as code, the checklist and the changelog of a repository; the code of
each task is a module of the package blueprint/ next to this file.

    python3 blueprint.py settings check     compare .github/repository.toml with GitHub
    python3 blueprint.py settings apply     make GitHub match .github/repository.toml
    python3 blueprint.py settings actions   whether each action of the workflows may run
    python3 blueprint.py checklist          the steps outside the API, and which are done
    python3 blueprint.py create [--private] the repository on GitHub of a new copy, set up
    python3 blueprint.py doctor             whether create can run here, and what fixes it
    python3 blueprint.py changelog ...      the changelog of a release (the release bot)
    python3 blueprint.py fragments [--fix]  check the fragments of the changelog in changelog.d/
    python3 blueprint.py notices ...        the licenses of the third-party components
    python3 blueprint.py update-commit ...  the pull request of an update (the blueprint bot)
    python3 blueprint.py bot-health ...     one issue per failing bot (the bot-health bot)
    python3 blueprint.py diagrams extract   the ```mermaid blocks as sources and pictures
    python3 blueprint.py diagrams ...       keep, commit and report (the diagram bots)
    python3 blueprint.py pages [--fix]      the READMEs and docs in one column, for phones
    python3 blueprint.py coach ...          how to fix a failed check, in one comment (the PR coach)
    python3 blueprint.py fix run            fix what a command can fix of the checks (the fix bot)
"""

import sys

if __name__ == "__main__":
    # Before the import: like the single file it was, the tool leaves no __pycache__
    # in the repository.
    sys.dont_write_bytecode = True
    # Before the import too: the package needs tomllib of Python 3.11, and an older
    # Python would stop with a traceback before the preflight of create could say so.
    if sys.version_info < (3, 11):  # noqa: UP036 - it runs before the requirement
        major, minor = sys.version_info[:2]
        sys.stderr.write(
            # Only ASCII: an old Python may write to a console that has no other.
            f"Python {major}.{minor}: the tools of the new repository need Python "
            "3.12 or later\n    install Python 3.12 or later: "
            "https://www.python.org/downloads/ (on Windows, py -3 runs it)\n"
        )
        sys.exit(1)
    # import blueprint finds the package next to this file: a package wins over a module.
    from blueprint.cli import main

    sys.exit(main())
