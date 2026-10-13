#!/usr/bin/env bash
# Sets up the dev container once it is created: the tools of the checks and the hook
# that runs them before every push. The blueprint keeps this file current:
# https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

composer install
# The frontend, if the project has one.
if [[ -f package.json ]]; then
  npm ci --ignore-scripts
fi
git config core.hooksPath .githooks
# Git runs a hook only when its file is executable. Where git ignores the modes of
# the files, as in a checkout made on Windows, the file may lack it on disk; it gets
# it here, which git doesn't count as a change. Elsewhere the mode in git applies.
if [[ "$(git config --type=bool core.fileMode || true)" == false ]]; then
  chmod +x .githooks/*
fi
# The tools of the website go into .venv-docs: for its preview with
# .venv-docs/bin/mkdocs serve, and for scripts/check.sh, which builds it then. They
# come last, so that the checks and their hook are ready even if they fail.
if [[ -f mkdocs.yml ]]; then
  # Python's venv, which this image lacks.
  sudo apt-get update
  sudo DEBIAN_FRONTEND=noninteractive apt-get install --yes --no-install-recommends python3-venv
  python3 -m venv .venv-docs
  .venv-docs/bin/pip install --require-hashes -r .github/docs-requirements.txt
fi
echo "Ready: bash scripts/check.sh runs the checks of the CI."
