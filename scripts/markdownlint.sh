#!/usr/bin/env bash
# The Markdown of every document, as the check lint lints it: markdownlint-cli2 of the
# same version, on the files of .markdownlint-cli2.jsonc, by the rules of
# .markdownlint.jsonc. scripts/check.sh runs it, so that a pull request that passes
# the checks on your machine passes lint as well.
#
#   bash scripts/markdownlint.sh        lint the Markdown
#   bash scripts/markdownlint.sh --fix  fix what markdownlint can fix, then lint
#
# It runs with Node 22 or newer through npx, which needs the network only until its
# cache has this version, or else in the image of markdownlint-cli2 with Docker, which
# needs it only to pull the image once. Without either, and in the CI, where the check
# lint lints the Markdown and its fix bot fixes it, it says so and checks nothing.
#
# The blueprint keeps this file current: https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# The image of the check lint, by its tag and digest, which Renovate keeps
# current; npx takes the version of its tag.
image="davidanson/markdownlint-cli2:v0.24.1@sha256:caf0698eaea0268bcf35b5e2d7ba946b5899d323e765bc2862054405658cd948"
version="${image#*:v}"
version="${version%%@*}"
# The version of Node that this version needs, from its package.json.
node_needed=22

case "$*" in
  "") fix=() ;;
  --fix) fix=(--fix) ;;
  *)
    echo "Usage: bash scripts/markdownlint.sh [--fix]" >&2
    exit 2
    ;;
esac

if [[ "${GITHUB_ACTIONS:-}" == true ]]; then
  echo "The check lint lints the Markdown in the CI, and its fix bot fixes it."
  exit 0
fi

node_major="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || true)"
if [[ "$node_major" =~ ^[0-9]+$ ]] && ((node_major >= node_needed)) &&
  command -v npx >/dev/null 2>&1; then
  npx --yes --prefer-offline "markdownlint-cli2@$version" "${fix[@]}" "**/*.md"
elif docker info >/dev/null 2>&1; then
  # The image runs as the user 1000; as yours, a fix keeps the owner of each file.
  # Git Bash on Windows: the folder by its name in Windows, the paths of the
  # container as they are, and no user, which Docker Desktop doesn't need.
  user=()
  if ! here="$(pwd -W 2>/dev/null)"; then
    here="$PWD"
    user=(--user "$(id -u):$(id -g)")
  fi
  MSYS_NO_PATHCONV=1 docker run --rm --network none "${user[@]}" \
    --volume "$here:/workdir" "$image" "${fix[@]}" "**/*.md"
else
  echo "Neither Node $node_needed or newer nor Docker is here; the check lint lints the Markdown in the CI."
fi
