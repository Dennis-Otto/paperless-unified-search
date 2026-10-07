#!/usr/bin/env bash
# Sets up the dev container once it is created: the tools of the checks and the hook
# that runs them before every push. The blueprint keeps this file current:
# https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

composer install
git config core.hooksPath .githooks
echo "Ready: bash scripts/check.sh runs the checks of the CI."
