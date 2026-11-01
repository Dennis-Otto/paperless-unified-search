#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The checks of this app alone, which scripts/check.sh runs last: the browser, the pinned
# images of Nextcloud and the scripts of the end-to-end tests, the pictures of the READMEs and of the website, the
# social preview and the files that tell the screenshot bot and the visual check when to
# render, the pages of the website that the READMEs link, the German pages beside the
# English ones, the screenshots of the website, the versions that the documentation names, the descriptions of the pages of
# the website, the diagrams of the documentation, the explanations of the checks for the PR coach, and the JavaScript of
# the settings and the translations, which have no build step, with their tests.
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

printf '\n== %s\n' "Pinned images of Nextcloud"
# A pull request and a push test the images of tests/e2e/nextcloud-images.txt: one for each
# version that appinfo/info.xml supports, so that a new max-version reaches the tests with
# a pin as well. Renovate only moves the lines that are there, and tests/e2e/pin-images.sh
# adds the others, as the fix bot does on the pull request of the upstream bot; its tests
# are in tests/e2e/selftest.sh below.
bash tests/e2e/pin-images.sh --check

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

printf '\n== %s\n' "Pictures of the READMEs and the website"
# The READMEs and the pages of the website show their screenshots from main, so that the
# website shows them as well, and the link check skips them (.lycheeignore): each of them
# must be in the checkout. A fragment, such as #only-dark, which tells the website in
# which theme to show a picture, and a query are no part of its path.
raw='https://github.com/Dennis-Otto/paperless-unified-search/raw/main/'
# The pictures that the documents given as arguments, or standard input, show from main.
pictures_from_main() {
  { grep -ohE "${raw//./\\.}[^\")[:space:]]+" "$@" || true; } | sed -E "s#^$raw##; s/[?#].*$//" | sort -u
}
# The check itself: a fragment and a query leave the path.
probe="$(printf '%s\n' "<img src=\"${raw}screenshots/a-dark.png#only-dark\">" "![A](${raw}screenshots/b.png?raw=1)" "${raw}screenshots/a-dark.png" | pictures_from_main -)"
if [[ "$probe" != $'screenshots/a-dark.png\nscreenshots/b.png' ]]; then
  echo "The check of the pictures reads \"$probe\" from a picture with a fragment and one with a query, not their paths: fix the check."
  exit 1
fi
mapfile -t showing < <(markdown README.md README.de.md 'docs/*.md')
shown_pictures=0
missing_pictures=0
for document in "${showing[@]}"; do
  while IFS= read -r picture; do
    shown_pictures=$((shown_pictures + 1))
    if [[ ! -f "$picture" ]]; then
      echo "$document shows $picture from main, which isn't in the repository: correct the path of the picture, or add the picture to scripts/screenshots.sh and run it."
      missing_pictures=$((missing_pictures + 1))
    fi
  done < <(pictures_from_main "$document")
done
if ((missing_pictures > 0)); then
  exit 1
fi
echo "The ${#showing[@]} README(s) and page(s) of the website show ${shown_pictures} picture(s) from main, each in the repository."

printf '\n== %s\n' "Social preview and the screenshot bot"
# GitHub, chats and social networks show .github/social-preview.png for a link to the
# repository, and it shows a screenshot. scripts/screenshots.sh renders it after the
# screenshots, so that each run of the screenshot bot renews it with them. The bot
# renders after a change of a file of .github/screenshots-paths.txt, the sources of the
# social preview among them, and the visual check of pull requests
# (.github/workflows/visual.yml) renders for a change of each of them as well.
if ! grep -qE '^bash scripts/social-preview\.sh' scripts/screenshots.sh; then
  echo "scripts/screenshots.sh doesn't render the social preview: end it with bash scripts/social-preview.sh, so that the picture renews with the screenshot that it shows."
  exit 1
fi
mapfile -t previewed < <(grep -oE 'screenshots/[A-Za-z0-9._-]+\.png' .github/social-preview.html | sort -u)
if ((${#previewed[@]} == 0)); then
  echo ".github/social-preview.html shows no screenshot of screenshots/; show the first one, as the social preview did, or change this check."
  exit 1
fi
for picture in "${previewed[@]}"; do
  if [[ ! -f "$picture" ]]; then
    echo ".github/social-preview.html shows $picture, which isn't in the repository: show a screenshot that scripts/screenshots.sh takes."
    exit 1
  fi
done
# The patterns of the list, without comments and empty lines, and the paths of the pull
# requests of the visual check, up to its next trigger.
mapfile -t watched < <(sed -E 's/#.*$//; s/[[:space:]]+$//; /^$/d' .github/screenshots-paths.txt)
visual_paths="$(awk '/^  pull_request:/ { inside = 1; next } inside && /^  [a-z_]+:/ { exit }
  inside && /^      - / { sub(/^      - /, ""); gsub(/"/, ""); print }' .github/workflows/visual.yml)"
for source in .github/social-preview.html .github/social-preview/Dockerfile scripts/social-preview.sh; do
  if ! printf '%s\n' "${watched[@]}" | grep -qxF -- "$source"; then
    echo ".github/screenshots-paths.txt doesn't list $source, a source of the social preview: add it, so that the bot renders the pictures after its change."
    exit 1
  fi
done
for pattern in "${watched[@]}"; do
  if ! grep -qxF -- "$pattern" <<<"$visual_paths"; then
    echo "The visual check (.github/workflows/visual.yml) doesn't run for a change of $pattern, which .github/screenshots-paths.txt lists: add it to the paths of its pull requests."
    exit 1
  fi
done
echo "scripts/screenshots.sh renders the social preview with ${previewed[*]}, and the visual check runs for each of the ${#watched[@]} pattern(s) of .github/screenshots-paths.txt."

printf '\n== %s\n' "Files that the documentation links on main"
# The README and the pages of the website link files and folders of the repository on
# GitHub, from main, as the website can't link them otherwise. A pull request that adds
# one, such as the guide of the demo, links it before main has it, so the link check
# skips the demo (.lycheeignore): each of them must be in the checkout.
mapfile -t linked < <(grep -ohE 'https://github\.com/Dennis-Otto/paperless-unified-search/(blob|tree)/main/[^")#?[:space:]]+' README.md README.de.md docs/*.md |
  sed -E 's#^https://github\.com/Dennis-Otto/paperless-unified-search/(blob|tree)/main/##' | sort -u)
for path in "${linked[@]}"; do
  if [[ ! -e "$path" ]]; then
    echo "The documentation links $path on main, which isn't in the repository, and the link check skips it (.lycheeignore): correct the path of the link, or add the file."
    exit 1
  fi
done
echo "The ${#linked[@]} file(s) and folder(s) that the documentation links on main are in the repository."

printf '\n== %s\n' "Pages of the website that the READMEs link"
# The READMEs link pages of the website, which a pull request that adds a page links
# before the website has it, so the link check skips them (.lycheeignore): each of them
# must be a page of docs/, from which the website is built, and a page of the German
# website in de/, such as de/faq.html, a German page, such as docs/faq.de.md.
mapfile -t site_pages < <(grep -ohE 'https://dennis-otto\.github\.io/paperless-unified-search/(de/)?[a-z0-9-]+\.html' README.md README.de.md |
  sed -E 's#^.*/de/([a-z0-9-]+)\.html$#docs/\1.de.md#; s#^.*/([a-z0-9-]+)\.html$#docs/\1.md#' | sort -u)
for site_page in "${site_pages[@]}"; do
  if [[ ! -f "$site_page" ]]; then
    echo "A README links a page of the website that docs/ doesn't have: write $site_page or link another page."
    exit 1
  fi
done
echo "The ${#site_pages[@]} page(s) of the website that the READMEs link are in docs/."

printf '\n== %s\n' "German pages"
# The German pages of the website include the sections of README.de.md by the names of
# those of README.md, such as --8<-- "README.de.md:usage", so both READMEs mark the same
# sections in the same order. Each German page, such as docs/faq.de.md, is the twin of
# an English one, and the READMEs link each other.
sections() {
  grep -oE -- '--8<-- \[(start|end):[a-z0-9-]+\]' "$1" || true
}
if [[ "$(sections README.md)" != "$(sections README.de.md)" ]]; then
  echo "README.md and README.de.md mark other sections, or in another order:"
  diff <(sections README.md) <(sections README.de.md) || true
  echo "Mark each section of README.md in README.de.md too, with the same name and at the same place."
  exit 1
fi
mapfile -t german < <(markdown 'docs/*.de.md')
for page in "${german[@]}"; do
  if [[ ! -f "${page%.de.md}.md" ]]; then
    echo "$page has no English twin ${page%.de.md}.md, whose German version it would be."
    exit 1
  fi
done
if ! grep -qF '](README.de.md)' README.md || ! grep -qF '](README.md)' README.de.md; then
  echo "README.md and README.de.md link each other at their top, as [Deutsch](README.de.md) and [English](README.md)."
  exit 1
fi
echo "README.de.md marks the $(sections README.md | wc -l | tr -d ' ') section marker(s) of README.md, and the ${#german[@]} German page(s) each have their English one."

printf '\n== %s\n' "Pictures of the German pages"
# A German page shows the pictures of its English twin, in the same order, and
# README.de.md those of README.md: the screenshots, the GIF and the diagrams, from
# Markdown and from HTML. A German picture of its own, such as NAME.de.svg of the diagram
# NAME.de.mmd, takes the place of NAME.svg. So a screenshot that reaches the English
# website, often in a pull request of its own after the one that took it, as the website
# loads it from main, reaches the German website with it.
pictures() {
  { grep -oE '!\[([^][]|\[[^]]*\])*\]\([^)[:space:]]+|(src|srcset)="[^"]+"' "$1" || true; } |
    sed -E 's/^!\[([^][]|\[[^]]*\])*\]\(//; s/^(src|srcset)="//; s/"$//; s/[?#].*$//; s/\.de((\.dark)?\.[a-z]+)$/\1/'
}
shown=0
for page in README.de.md "${german[@]}"; do
  english="${page%.de.md}.md"
  if [[ "$(pictures "$english")" != "$(pictures "$page")" ]]; then
    echo "$page doesn't show the pictures of $english, or in another order (< $english, > $page):"
    diff <(pictures "$english") <(pictures "$page") || true
    echo "Show each picture of $english in $page too, at the same place, with its alt text and caption in German."
    exit 1
  fi
  shown=$((shown + $(pictures "$page" | grep -c . || true)))
done
echo "The $((${#german[@]} + 1)) German page(s), README.de.md among them, show the $shown picture(s) of their English twins."

printf '\n== %s\n' "Screenshots of the website"
# Each screenshot of README.md is on the website too: on a page of docs/, or in a section
# of README.md that a page includes, such as --8<-- "README.md:usage". The website loads
# the screenshots from main, so a pull request that adds one can't show it there before
# main has it: such a screenshot passes, and the check names the follow-up it needs.
# Four of these follow-ups were forgotten before this check (#82, #135, #145, #174).
screenshots_of() {
  { grep -ohE "${raw//./\\.}screenshots/[^\")?#[:space:]]+" "$@" || true; } | sed "s#^$raw##" | sort -u
}
# The screenshots that the English pages given as arguments show, with the sections of
# README.md that they include.
website_screenshots() {
  local page
  for page in "$@"; do
    screenshots_of "$page"
    { grep -oE -- '--8<-- "README\.md:[a-z0-9-]+"' "$page" || true; } | sed -E 's/.*:([a-z0-9-]+)"$/\1/' |
      while IFS= read -r section; do
        awk -v start="--8<-- [start:$section]" -v end="--8<-- [end:$section]" \
          'index($0, end) { inside = 0 } inside { print } index($0, start) { inside = 1 }' README.md |
          screenshots_of -
      done
  done | sort -u
}
# The screenshots of README.md that the pages given as arguments leave out.
missing_screenshots() {
  comm -23 <(screenshots_of README.md) <(website_screenshots "$@")
}
mapfile -t english < <(markdown 'docs/*.md' | grep -v '\.de\.md$')
# The check itself: it finds a screenshot that a page leaves out, here the settings on a
# phone, without the line of docs/index.md that shows them in the gallery. It finds that
# one besides those that the pages leave out already, such as a new screenshot of
# README.md that waits for main.
probe="$(mktemp)"
grep -v 'screenshots/09-admin-settings-mobile\.png' docs/index.md >"$probe"
others=()
for page in "${english[@]}"; do
  if [[ "$page" != docs/index.md ]]; then others+=("$page"); fi
done
found="$(comm -13 <(missing_screenshots "${english[@]}") <(missing_screenshots "$probe" "${others[@]}"))"
rm -f "$probe"
if [[ "$found" != "screenshots/09-admin-settings-mobile.png" ]]; then
  echo "Without the settings on a phone in docs/index.md, the check of the screenshots finds \"$found\" missing, not screenshots/09-admin-settings-mobile.png: fix the check."
  exit 1
fi
mapfile -t missing < <(missing_screenshots "${english[@]}")
if ((${#missing[@]} > 0)) && ! git rev-parse -q --verify origin/main >/dev/null; then
  git fetch -q --depth=1 origin +refs/heads/main:refs/remotes/origin/main || {
    echo "No page of the website shows ${missing[*]}, and main can't be fetched to tell whether it has them yet."
    exit 1
  }
fi
waiting=0
for screenshot in "${missing[@]}"; do
  if git cat-file -e "origin/main:$screenshot" 2>/dev/null; then
    echo "README.md shows $screenshot, which main has, but no page of the website shows it."
    echo "Show it on the page of docs/ about its topic and on the German twin of that page, or in a section of README.md that such a page includes."
    exit 1
  fi
  echo "README.md shows $screenshot, which main doesn't have yet, so the website can't show it: after the merge, show it on the website in a pull request of its own, in English and German."
  waiting=$((waiting + 1))
done
echo "The website shows the $(($(screenshots_of README.md | wc -l) - waiting)) screenshot(s) of README.md that main has; $waiting wait for main."

printf '\n== %s\n' "Versions in the documentation"
# appinfo/info.xml decides on which versions of Nextcloud and PHP the app installs. The
# READMEs and the pages of the website that name them, in English and German, with the
# columns of their tables of compatibility, say the same, and so do the descriptions of
# the App Store in appinfo/info.xml in every language, so that a change of the versions
# can't leave a copy behind. scripts/versions.sh checks every place, and its --write
# writes the versions there, as the fix bot does on the pull request of the upstream bot.
bash scripts/versions.sh
# Its tests, against a copy of the documentation: a run that is in sync changes nothing;
# a new max-version, as the upstream bot writes it, reaches every place and adds a
# column with the cells of the newest one; a new min-version drops the oldest column; a
# new PHP changes its places alone; and what no command can fix fails both the check
# and the fix.
(
  work="$(mktemp -d)"
  trap 'rm -rf "$work"' EXIT
  copy="$work/copy"
  mkdir -p "$copy/scripts" "$copy/appinfo" "$copy/docs"
  cp scripts/versions.sh "$copy/scripts/"
  cp appinfo/info.xml "$copy/appinfo/"
  cp README.md README.de.md "$copy/"
  cp docs/*.md "$copy/docs/"
  status=0
  versions() {
    status=0
    bash "$copy/scripts/versions.sh" "$@" >"$work/output" 2>&1 || status=$?
  }
  fail() {
    echo "scripts/versions.sh $1. Its output:"
    cat "$work/output"
    exit 1
  }
  ends() {
    if ((status != $1)); then
      fail "ended with $status, not $1, $2"
    fi
  }
  says() {
    if ! grep -qxF -- "$2" "$copy/$1"; then
      fail "left no line \"$2\" in $1"
    fi
  }
  printed() {
    if ! grep -qF -- "$1" "$work/output"; then
      fail "didn't say \"$1\""
    fi
  }
  # The min-version and max-version of nextcloud and the min-version of php of the copy.
  set_versions() {
    sed -E -e "s/(<nextcloud [^>]*min-version=\")[0-9]+/\\1$1/" \
      -e "s/(<nextcloud [^>]*max-version=\")[0-9]+/\\1$2/" \
      -e "s/(<php [^>]*min-version=\")[0-9.]+/\\1$3/" \
      "$copy/appinfo/info.xml" >"$work/info.xml"
    mv "$work/info.xml" "$copy/appinfo/info.xml"
  }
  snapshot() {
    rm -rf "$work/before"
    cp -R "$copy" "$work/before"
  }
  unchanged() {
    if ! diff -r "$work/before" "$copy" >"$work/diff"; then
      cat "$work/diff" >>"$work/output"
      fail "changed files $1"
    fi
  }
  # From Nextcloud 33 to 35 and PHP 8.2, with a table of compatibility of its own, which
  # has an alignment and an empty cell, and a line that is no place, as it goes on after
  # the versions.
  set_versions 33 35 8.2
  echo "- Nextcloud 30 to 31, which an old release supported" >>"$copy/README.md"
  printf '%s\n' "" "| | Nextcloud 33 | Nextcloud 34 | Nextcloud 35 |" "| :--- | :---: | :---: | :---: |" \
    "| **A** | a33 | a34 | a35 |" "| **B** | | b34 | b35 |" >>"$copy/docs/reference.md"
  versions --write
  ends 0 "writing Nextcloud 33 to 35"
  snapshot
  versions --write
  ends 0 "writing the same versions again"
  unchanged "that named the versions already"
  versions
  ends 0 "checking what it wrote"

  # The upstream bot raises max-version.
  set_versions 33 36 8.2
  versions
  ends 1 "checking a max-version of 36 that only appinfo/info.xml names"
  printed "  new: | | Nextcloud 33 | Nextcloud 34 | Nextcloud 35 | Nextcloud 36 |"
  printed "bash scripts/versions.sh --write"
  versions --write
  ends 0 "writing a max-version of 36"
  says README.md "- Nextcloud 33 to 36"
  says README.md "- Nextcloud 30 to 31, which an old release supported"
  says README.de.md "- Nextcloud 33 bis 36"
  says docs/install.md "| **Nextcloud** | 33 to 36 |"
  says docs/install.de.md "| **Nextcloud** | 33 bis 36 |"
  says appinfo/info.xml "- Nextcloud 33 à 36"
  says appinfo/info.xml "- Nextcloud 33 tot 36"
  says docs/reference.md "| | Nextcloud 33 | Nextcloud 34 | Nextcloud 35 | Nextcloud 36 |"
  says docs/reference.de.md "| | Nextcloud 33 | Nextcloud 34 | Nextcloud 35 | Nextcloud 36 |"
  says docs/reference.de.md "| --- | --- | --- | --- | --- |"
  says docs/reference.md "| :--- | :---: | :---: | :---: | :---: |"
  says docs/reference.md "| **A** | a33 | a34 | a35 | a35 |"
  says docs/reference.md "| **B** | | b34 | b35 | b35 |"
  if ! grep -qE '^Nextcloud 33 bis 36 ' "$copy/docs/faq.de.md"; then
    fail "left docs/faq.de.md without \"Nextcloud 33 bis 36\""
  fi
  versions
  ends 0 "checking a max-version of 36 after writing it"
  snapshot
  versions --write
  ends 0 "writing a max-version of 36 again"
  unchanged "that it had written already"

  # Nextcloud 33 reaches its end.
  set_versions 34 36 8.2
  versions --write
  ends 0 "writing a min-version of 34"
  says README.de.md "- Nextcloud 34 bis 36"
  says docs/reference.md "| | Nextcloud 34 | Nextcloud 35 | Nextcloud 36 |"
  says docs/reference.de.md "| | Nextcloud 34 | Nextcloud 35 | Nextcloud 36 |"
  says docs/reference.md "| :--- | :---: | :---: | :---: |"
  says docs/reference.md "| **A** | a34 | a35 | a35 |"
  says docs/reference.md "| **B** | b34 | b35 | b35 |"

  # A new PHP changes its six places and nothing else, such as the PHP of the unit tests.
  set_versions 34 36 8.4
  snapshot
  versions --write
  ends 0 "writing PHP 8.4"
  diff -r "$work/before" "$copy" | grep '^>' >"$work/diff" || true
  php_places="$(grep -cE '8\.4 (or newer|oder neuer)' "$work/diff" || true)"
  if [[ "$(wc -l <"$work/diff" | tr -d ' ')" != 6 || "$php_places" != 6 ]]; then
    cat "$work/diff" >>"$work/output"
    fail "changed other lines than the six places of PHP, or missed one"
  fi

  # What no command can fix: a place that is gone, a row of a table without a cell for
  # each version and a description of the App Store without its versions.
  grep -v '^Nextcloud [0-9]* to ' "$copy/docs/faq.md" >"$work/faq.md"
  mv "$work/faq.md" "$copy/docs/faq.md"
  grep -v '^- Nextcloud [0-9]* tot ' "$copy/appinfo/info.xml" >"$work/info.xml"
  mv "$work/info.xml" "$copy/appinfo/info.xml"
  printf '%s\n' "| **C** | c34 |" >>"$copy/docs/reference.md"
  descriptions="$(grep -c '<description' "$copy/appinfo/info.xml")"
  for mode in check --write; do
    if [[ "$mode" == check ]]; then
      versions
    else
      versions "$mode"
    fi
    ends 1 "with $mode where a place is gone"
    printed "docs/faq.md has no line that says \"Nextcloud 34 to 36\""
    printed "has 2 cell(s) in its row of the table of compatibility, and the header 4"
    printed "appinfo/info.xml has $descriptions description(s) for the App Store, but $((descriptions - 1)) line(s)"
  done
  versions --force
  ends 2 "with an argument that it doesn't know"
  echo "scripts/versions.sh checks a new max-version, min-version and PHP and writes them into each place, and changes nothing that is in sync."
)

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

printf '\n== %s\n' "Explanations of the checks"
# When a check fails, the PR coach explains it on the pull request, also on one from a
# fork: what it found, why that matters and how to fix it. So every check that the
# rules of main require, of the blueprint and of this app, such as nextcloud of the
# end-to-end tests, and every step of scripts/check.sh has its explanation, in
# .github/checks.toml or .github/checks.project.toml, and each explanation says in
# autofix how its check fixes itself, or why no command can. blueprint.py needs Python
# 3.12 or newer, as in the steps of scripts/check.sh that run it.
python=""
for candidate in python3 python py; do
  if "$candidate" -c 'import sys; sys.exit(sys.version_info < (3, 12))' >/dev/null 2>&1; then
    python="$candidate"
    break
  fi
done
if [[ -n "$python" ]]; then
  if ! "$python" .github/blueprint.py coach verify; then
    echo "Explain each check and step above in .github/checks.project.toml, with its autofix, as its other explanations do."
    exit 1
  fi
elif [[ -n "${CI:-}" ]]; then
  echo "Checking the explanations of the checks needs Python 3.12 or newer, which this runner lacks."
  exit 1
else
  echo "Python 3.12 or newer is not installed here; the CI checks the explanations of the checks."
fi

printf '\n== %s\n' "JavaScript and translations"
if ! command -v node >/dev/null 2>&1; then
  echo "Node.js is not installed here; the CI checks the JavaScript and the translations."
  exit 0
fi
# The test runner of Node.js measures the coverage against a minimum since version 22.8.
if ! node -e 'const [major, minor] = process.versions.node.split(".").map(Number); process.exit(major > 22 || (major === 22 && minor >= 8) ? 0 : 1)'; then
  echo "Node.js $(node --version) can't hold the tests of js/ to their coverage; install Node.js 22.8 or newer, as the dev container has it."
  exit 1
fi
mapfile -t scripts < <(git ls-files 'js/*.js' 'l10n/*.js' 'tests/e2e/*.mjs')
for script in "${scripts[@]}"; do
  node --check "$script"
done
echo "${#scripts[@]} JavaScript file(s) parse."
# The tests of the settings pages run js/settings.js and js/personal.js against a
# stand-in for their page and every answer of Nextcloud, and each line and branch of
# them runs in one of them. Every
# text that the code translates has a translation in each catalog of l10n/, with its
# placeholders, and the JavaScript catalogs match the JSON ones; the tests of the check
# make sure that it notices each kind of mismatch.
mapfile -t tests < <(git ls-files --cached --others --exclude-standard 'tests/js/*.test.mjs' | sort -u)
node --test --experimental-test-coverage --test-coverage-include='js/*.js' \
  --test-coverage-lines=100 --test-coverage-branches=100 --test-coverage-functions=100 "${tests[@]}"
node scripts/check-l10n.mjs
