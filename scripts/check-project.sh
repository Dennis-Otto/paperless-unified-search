#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The checks of this app alone, which scripts/check.sh runs last: the browser and the
# scripts of the end-to-end tests, the pictures of the README and the pages of the website
# that it links, the versions that the documentation names, the descriptions of the pages
# of the website, the diagrams of the documentation, and the JavaScript of the settings
# and the translations, which have no build step, with their tests.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# The Markdown files that match the patterns, found as scripts/check.sh finds its files:
# new files that git doesn't track yet as well, but no tracked file deleted in the checkout.
markdown() {
  git ls-files --cached --others --exclude-standard "$@" | sort -u |
    while IFS= read -r file; do
      if [[ -f "$file" ]]; then printf '%s\n' "$file"; fi
    done
}

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
# the parallel run of every Nextcloud version, without Docker. They run with GIT_DIR,
# GIT_WORK_TREE and GIT_INDEX_FILE naming a repository of their own, as Git hands them to
# its hooks and as an agent sets them for a worktree in a container: the repositories
# that the tests make must leave that one alone, its commits, branches, configuration
# and checkout.
(
  # shellcheck disable=SC2046 # Git prints one name of a variable per line.
  unset $(git rev-parse --local-env-vars)
  export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
  outer="$(mktemp -d)"
  trap 'rm -rf "$outer"' EXIT
  state() {
    git -C "$outer" for-each-ref --format='%(refname) %(objectname)'
    git -C "$outer" status --porcelain=v1 --untracked-files=all
    cat "$outer/.git/config"
  }
  git init --quiet "$outer"
  echo "The repository that runs the tests." >"$outer/README.md"
  git -C "$outer" add README.md
  git -C "$outer" -c user.name=Check -c user.email=check@example.com \
    commit --quiet --message "The repository that runs the tests"
  # A change not yet committed, which a git add --all of the tests would take in.
  echo "Not yet committed." >"$outer/notes.md"
  before="$(state)"
  status=0
  GIT_DIR="$outer/.git" GIT_WORK_TREE="$outer" GIT_INDEX_FILE="$outer/.git/index" \
    bash tests/e2e/selftest.sh || status=$?
  after="$(state)"
  if [[ "$after" != "$before" ]]; then
    echo "tests/e2e/selftest.sh changed the repository that GIT_DIR names; clear the variables of Git at its start, before its first git:"
    diff <(printf '%s\n' "$before") <(printf '%s\n' "$after") || true
    exit 1
  fi
  if ((status != 0)); then
    exit "$status"
  fi
  echo "The tests left the repository that GIT_DIR names alone."
)

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

printf '\n== %s\n' "Files that the documentation links on main"
# The README and the pages of the website link files and folders of the repository on
# GitHub, from main, as the website can't link them otherwise. A pull request that adds
# one, such as the guide of the demo, links it before main has it, so the link check
# skips the demo (.lycheeignore): each of them must be in the checkout.
mapfile -t linked < <(grep -ohE 'https://github\.com/Dennis-Otto/paperless-unified-search/(blob|tree)/main/[^")#?[:space:]]+' README.md docs/*.md |
  sed -E 's#^https://github\.com/Dennis-Otto/paperless-unified-search/(blob|tree)/main/##' | sort -u)
for path in "${linked[@]}"; do
  if [[ ! -e "$path" ]]; then
    echo "The documentation links $path on main, which isn't in the repository."
    exit 1
  fi
done
echo "The ${#linked[@]} file(s) and folder(s) that the documentation links on main are in the repository."

printf '\n== %s\n' "Pages of the website that the README links"
# The README links pages of the website, which a pull request that adds a page links
# before the website has it, so the link check skips them (.lycheeignore): each of them
# must be a page of docs/, from which the website is built.
mapfile -t site_pages < <(grep -oE 'https://dennis-otto\.github\.io/paperless-unified-search/[a-z0-9-]+\.html' README.md |
  sed -E 's#^.*/([a-z0-9-]+)\.html$#docs/\1.md#' | sort -u)
for site_page in "${site_pages[@]}"; do
  if [[ ! -f "$site_page" ]]; then
    echo "README.md links a page of the website that docs/ doesn't have: write $site_page or link another page."
    exit 1
  fi
done
echo "The ${#site_pages[@]} page(s) of the website that the README links are in docs/."

printf '\n== %s\n' "Versions in the documentation"
# appinfo/info.xml decides on which versions of Nextcloud and PHP the app installs. The
# README and the pages of the website that name them say the same, so that a change of
# the versions can't leave a copy behind.
nextcloud_min="$(sed -nE 's/.*<nextcloud [^>]*min-version="([0-9]+)".*/\1/p' appinfo/info.xml)"
nextcloud_max="$(sed -nE 's/.*<nextcloud [^>]*max-version="([0-9]+)".*/\1/p' appinfo/info.xml)"
php_min="$(sed -nE 's/.*<php [^>]*min-version="([0-9.]+)".*/\1/p' appinfo/info.xml)"
if [[ -z "$nextcloud_min" || -z "$nextcloud_max" || -z "$php_min" ]]; then
  echo "appinfo/info.xml names no min-version and max-version of nextcloud, or no min-version of php."
  exit 1
fi
columns="|"
for ((version = nextcloud_min; version <= nextcloud_max; version++)); do
  columns+=" Nextcloud $version |"
done
version_problems=0
says() {
  if ! grep -qF -- "$2" "$1"; then
    echo "$1 doesn't say \"$2\": write the versions of appinfo/info.xml there."
    version_problems=$((version_problems + 1))
  fi
}
says README.md "Nextcloud $nextcloud_min to $nextcloud_max"
says README.md "PHP $php_min or newer"
says docs/install.md "| **Nextcloud** | $nextcloud_min to $nextcloud_max |"
says docs/install.md "| **PHP** | $php_min or newer"
says docs/faq.md "Nextcloud $nextcloud_min to $nextcloud_max"
says docs/reference.md "| $columns"
says docs/reference.md "**PHP:** $php_min or newer"
if ((version_problems > 0)); then
  exit 1
fi
echo "The documentation names Nextcloud $nextcloud_min to $nextcloud_max and PHP $php_min, as appinfo/info.xml does."

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
mapfile -t pages < <(markdown 'docs/*.md')
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

printf '\n== %s\n' "Diagrams"
# Every Mermaid diagram of the documentation runs from top to bottom, so that it keeps
# its size in the narrow column of a phone, and has a title and a description that
# screen readers read out instead of the picture (accTitle and accDescr). A sequence
# diagram has no direction and only needs the two.
mapfile -t documents < <(markdown '*.md')
# Without files, awk would wait for its standard input.
if ((${#documents[@]} == 0)); then
  echo "No Markdown files found; run the check from the checkout of the repository."
  exit 1
fi
if ! awk '
  FNR == 1 { inside = 0 }
  !inside && /^[ \t]*```mermaid[ \t]*$/ {
    inside = 1; start = FNR; title = 0; text = 0; diagrams++
    next
  }
  inside && /^[ \t]*```[ \t]*$/ {
    inside = 0
    if (!title) { print FILENAME ":" start ": the diagram has no accTitle: line"; problems++ }
    if (!text) { print FILENAME ":" start ": the diagram has no accDescr: line"; problems++ }
    next
  }
  inside && /^[ \t]*accTitle[ \t]*:/ { title = 1 }
  inside && /^[ \t]*accDescr[ \t]*[:{]/ { text = 1 }
  inside && /^[ \t]*(flowchart|graph|direction)[ \t]+(LR|RL)([ \t;]|$)/ {
    print FILENAME ":" FNR ": the diagram runs from left to right; let it run from top to bottom (TB)"
    problems++
  }
  END {
    if (problems > 0) { exit 1 }
    printf "The %d diagram(s) run from top to bottom, each with a title and a description.\n", diagrams
  }
' "${documents[@]}"; then
  echo "Let each diagram run from top to bottom (flowchart TB) and give it accTitle: and accDescr: lines, as the diagrams of README.md do."
  exit 1
fi

printf '\n== %s\n' "JavaScript and translations"
if ! command -v node >/dev/null 2>&1; then
  echo "Node.js is not installed here; the CI checks the JavaScript and the translations."
  exit 0
fi
# The test runner of Node.js measures the coverage against a minimum since version 22.8.
if ! node -e 'const [major, minor] = process.versions.node.split(".").map(Number); process.exit(major > 22 || (major === 22 && minor >= 8) ? 0 : 1)'; then
  echo "Node.js $(node --version) can't hold the tests of js/settings.js to their coverage; install Node.js 22.8 or newer, as the dev container has it."
  exit 1
fi
mapfile -t scripts < <(git ls-files 'js/*.js' 'l10n/*.js' 'tests/e2e/*.mjs')
for script in "${scripts[@]}"; do
  node --check "$script"
done
echo "${#scripts[@]} JavaScript file(s) parse."
# The tests of the settings page run js/settings.js against a stand-in for the page and
# every answer of Nextcloud, and each line and branch of it runs in one of them. Every
# text that the code translates has a translation in each catalog of l10n/, with its
# placeholders, and the JavaScript catalogs match the JSON ones; the tests of the check
# make sure that it notices each kind of mismatch.
mapfile -t tests < <(git ls-files --cached --others --exclude-standard 'tests/js/*.test.mjs' | sort -u)
node --test --experimental-test-coverage --test-coverage-include=js/settings.js \
  --test-coverage-lines=100 --test-coverage-branches=100 --test-coverage-functions=100 "${tests[@]}"
node scripts/check-l10n.mjs
