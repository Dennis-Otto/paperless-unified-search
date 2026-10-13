#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The checks of this app alone, which scripts/check.sh runs last: the browser and the
# scripts of the end-to-end tests, the pictures of the README, the descriptions of the
# pages of the website, and the JavaScript of the settings and the translations, which
# have no build step.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

printf '\n== %s\n' "Browser of the end-to-end tests"
# A version of playwright-core drives the browsers in the image of the same version.
image="$(grep -oE 'mcr\.microsoft\.com/playwright:v[0-9]+\.[0-9]+\.[0-9]+' tests/e2e/run.sh | head -n 1 || true)"
image="${image##*:v}"
package="$(grep -oE '"playwright-core": "[^"]+"' tests/e2e/package.json | cut -d '"' -f 4 || true)"
if [[ -z "$image" || "$image" != "$package" ]]; then
  echo "tests/e2e/run.sh starts the image of Playwright ${image:-?}, but tests/e2e/package.json asks for playwright-core ${package:-?}; keep them equal."
  exit 1
fi
echo "The image of Playwright and playwright-core are both at version $package."

printf '\n== %s\n' "Scripts of the end-to-end tests"
# The path filter that decides whether the required check nextcloud runs the tests, and
# the parallel run of every Nextcloud version, without Docker.
bash tests/e2e/selftest.sh

printf '\n== %s\n' "Pictures of the README"
# The README shows its pictures from main, so that the website shows them as well, and
# the link check skips them (.lycheeignore): each of them must be in the checkout.
mapfile -t pictures < <(grep -oE 'https://github\.com/Dennis-Otto/paperless-unified-search/raw/main/[^")[:space:]]+' README.md |
  sed 's#^https://github\.com/Dennis-Otto/paperless-unified-search/raw/main/##' | sort -u)
for picture in "${pictures[@]}"; do
  if [[ ! -f "$picture" ]]; then
    echo "README.md shows $picture, which isn't in the repository."
    exit 1
  fi
done
echo "The ${#pictures[@]} picture(s) of the README are in the repository."

printf '\n== %s\n' "Descriptions of the website pages"
# Search engines and shared links show the description of a page, from its front
# matter; a page without one would show the description of the whole website.
# The check reads one line, so a description written as a YAML block (">-" or "|",
# then indented lines) fails with exit status 2 instead of passing unread.
description() {
  awk 'NR == 1 && $0 != "---" { exit }
    NR > 1 && $0 == "---" { exit }
    NR > 1 && sub(/^description: */, "") {
      if ($0 ~ /^[>|]/) { exit 2 }
      gsub(/^"|"$/, ""); print; exit
    }' "$1"
}
mapfile -t pages < <(git ls-files 'docs/*.md')
descriptions=()
for page in "${pages[@]}"; do
  if ! text="$(description "$page")"; then
    echo "$page writes its description as a YAML block; write it on one line, in quotes:"
    printf -- 'description: "What the page is about, in one sentence."\n'
    exit 1
  fi
  if [[ -z "$text" ]]; then
    echo "$page has no description in its front matter; start it with:"
    printf -- '---\ndescription: "What the page is about, in one sentence."\n---\n'
    exit 1
  fi
  descriptions+=("$text")
done
twice="$(printf '%s\n' "${descriptions[@]}" | sort | uniq -d)"
if [[ -n "$twice" ]]; then
  echo "More than one page has the description: $twice"
  exit 1
fi
echo "The ${#pages[@]} page(s) of the website each have a description of their own."

printf '\n== %s\n' "JavaScript and translations"
if ! command -v node >/dev/null 2>&1; then
  echo "Node.js is not installed here; the CI checks the JavaScript and the translations."
  exit 0
fi
mapfile -t scripts < <(git ls-files 'js/*.js' 'l10n/*.js' 'tests/e2e/*.mjs')
for script in "${scripts[@]}"; do
  node --check "$script"
done
echo "${#scripts[@]} JavaScript file(s) parse."
node scripts/check-l10n.mjs
