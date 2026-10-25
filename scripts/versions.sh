#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The versions of Nextcloud and PHP that appinfo/info.xml supports, at every place that
# names them: the READMEs and the pages of the website in English and German, the
# columns of their tables of compatibility, and the descriptions of the App Store in
# appinfo/info.xml in every language.
#
#   bash scripts/versions.sh           checks each place, as scripts/check-project.sh does
#   bash scripts/versions.sh --write   writes the versions of appinfo/info.xml there
#
# The check and the fix go through the one list of places below, so they can't disagree:
# the check fails exactly where --write would change a line. The fix bot runs --write
# when the step "Checks of this project" fails, so that the pull request of the upstream
# bot, which raises only max-version, writes the new version into the documentation
# itself. A new maximum adds a column to each table of compatibility with the cells of
# the newest one, and a new minimum drops the oldest columns. What no command can fix,
# such as a place that is gone, it names, and it ends with 1 then.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

mode="check"
case "$*" in
  "") ;;
  --write) mode="write" ;;
  *)
    echo "Usage: bash scripts/versions.sh [--write]" >&2
    exit 2
    ;;
esac

# Each place: a file, and the text that names the versions there, with {min} and {max}
# for min-version and max-version of nextcloud in appinfo/info.xml, {php} for the
# min-version of php, and {word} for a word that stays as it is, such as "to" in another
# language. A ^ at the start holds the text to the start of a line, a $ at the end to
# its end. Every text that matches must name the versions of appinfo/info.xml, and each
# place must have at least one. {table} is a table of compatibility, whose header,
# "| | Nextcloud 33 | Nextcloud 34 |", has a column for each version.
places='
README.md             ^- Nextcloud {min} to {max}$
README.md             ^- PHP {php} or newer
README.de.md          ^- Nextcloud {min} bis {max}$
README.de.md          ^- PHP {php} oder neuer
docs/install.md       ^| **Nextcloud** | {min} to {max} |$
docs/install.md       ^| **PHP** | {php} or newer
docs/install.de.md    ^| **Nextcloud** | {min} bis {max} |$
docs/install.de.md    ^| **PHP** | {php} oder neuer
docs/faq.md           ^Nextcloud {min} to {max}
docs/faq.de.md        ^Nextcloud {min} bis {max}
docs/reference.md     {table}
docs/reference.md     ^- **PHP:** {php} or newer
docs/reference.de.md  {table}
docs/reference.de.md  ^- **PHP:** {php} oder neuer
appinfo/info.xml      ^- Nextcloud {min} {word} {max}$
'


# The places through every file of them, in one run of a POSIX awk, such as mawk of the
# runners of Ubuntu, the gawk of Git for Windows and the awk of macOS. It writes each
# file with the versions of appinfo/info.xml into outdir/<number>, lists the number and
# the name of each file that this changes on its output, each line that changes into the
# file changes, and what no command can fix into the file problems.
program='
function add(t, k, s) {
  count[t]++
  kind[t, count[t]] = k
  text[t, count[t]] = s
}

# The place t, "^| **PHP** | {php} or newer": the texts and the placeholders between
# them, and whether it holds to the start and to the end of a line.
function parse(t,    rest, at) {
  rest = template[t]
  table[t] = rest == "{table}"
  if (table[t]) return
  first[t] = substr(rest, 1, 1) == "^"
  if (first[t]) rest = substr(rest, 2)
  last[t] = substr(rest, length(rest)) == "$"
  if (last[t]) rest = substr(rest, 1, length(rest) - 1)
  while ((at = index(rest, "{")) > 0) {
    if (at > 1) add(t, "text", substr(rest, 1, at - 1))
    rest = substr(rest, at + 1)
    at = index(rest, "}")
    add(t, substr(rest, 1, at - 1), "")
    if (!(kind[t, count[t]] in pattern)) {
      printf "scripts/versions.sh: the place \"%s\" of %s names {%s}, which is none of {min}, {max}, {php} and {word}.\n", template[t], name[owner[t]], kind[t, count[t]] >> problems
      broken = 1
    }
    rest = substr(rest, at + 1)
  }
  if (rest != "") add(t, "text", rest)
}

# The text of place t as it is meant to read.
function shown(t,    i, out) {
  out = ""
  for (i = 1; i <= count[t]; i++) {
    if (kind[t, i] == "text") out = out text[t, i]
    else if (kind[t, i] == "word") out = out "<to>"
    else out = out value[kind[t, i]]
  }
  return out
}

# The length of the text of place t at position p of s, or 0 where it does not match
# there; then written holds the text with the versions of appinfo/info.xml.
function match_at(s, p, t,    i, q, k, part) {
  q = p
  written = ""
  for (i = 1; i <= count[t]; i++) {
    k = kind[t, i]
    if (k == "text") {
      part = text[t, i]
      if (substr(s, q, length(part)) != part) return 0
      written = written part
      q += length(part)
    } else {
      if (!match(substr(s, q), pattern[k])) return 0
      written = written (k == "word" ? substr(s, q, RLENGTH) : value[k])
      q += RLENGTH
    }
  }
  if (last[t] && q != length(s) + 1) return 0
  return q - p
}

# The line s with every text of place t written anew.
function rewrite(s, t,    out, p, n) {
  out = ""
  for (p = 1; p <= length(s); p++) {
    n = match_at(s, p, t)
    if (n > 0) {
      hits[t]++
      out = out written
      p += n - 1
    } else {
      out = out substr(s, p, 1)
    }
    if (first[t]) return out substr(s, p + 1)
  }
  return out
}

# The cells of a row of a table, without the blanks around them, in cell[1..n]; n, or
# 0 for a line that is no row.
function cells(s,    n, i) {
  if (s !~ /^\|.*\|$/) return 0
  n = split(substr(s, 2, length(s) - 2), cell, "|")
  for (i = 1; i <= n; i++) gsub(/^ +| +$/, "", cell[i])
  return n
}

function cell_text(c) {
  return c == "" ? " |" : " " c " |"
}

# Whether s is the header of a table of compatibility. Then columns holds its number of
# cells, and source[v] the column whose cells version v takes: its own, or else that of
# the newest version before it, or else the oldest column.
function header(s,    n, i, v, best) {
  n = cells(s)
  if (n < 2 || cell[1] != "") return 0
  for (i = 2; i <= n; i++) {
    if (cell[i] !~ /^Nextcloud [0-9]+$/) return 0
    old[i] = substr(cell[i], 11) + 0
  }
  for (v = min; v <= max; v++) {
    best = 0
    for (i = 2; i <= n; i++)
      if (old[i] <= v && (best == 0 || old[i] > old[best])) best = i
    if (best == 0)
      for (i = 2; i <= n; i++)
        if (best == 0 || old[i] < old[best]) best = i
    source[v] = best
  }
  columns = n
  return 1
}

# A row below the header of a table of compatibility, with one cell for each version.
function row(s, number,    n, v, out) {
  n = cells(s)
  if (n != columns) {
    printf "%s:%d has %d cell(s) in its row of the table of compatibility, and the header %d: give it one cell for each column of the header.\n", name[f], number, n, columns >> problems
    return s
  }
  out = "|" cell_text(cell[1])
  for (v = min; v <= max; v++) out = out cell_text(cell[source[v]])
  return out
}

# Line number of file f with the versions of appinfo/info.xml.
function written_line(s, number,    t, v) {
  if (columns && substr(s, 1, 1) == "|") {
    s = row(s, number)
  } else {
    columns = 0
    if (tables && header(s)) {
      hits[tables]++
      s = "| |"
      for (v = min; v <= max; v++) s = s " Nextcloud " v " |"
    }
  }
  for (t = 1; t <= places; t++)
    if (owner[t] == f && !table[t]) s = rewrite(s, t)
  return s
}

# File f through its places.
function through(    t, line, number, status, out, new, differs) {
  tables = 0
  for (t = 1; t <= places; t++)
    if (owner[t] == f && table[t]) tables = t
  columns = 0
  number = 0
  differs = 0
  out = outdir "/" f
  printf "" > out
  while ((status = (getline line < name[f])) > 0) {
    number++
    new = written_line(line, number)
    if (new != line) {
      differs = 1
      printf "%s:%d\n  old: %s\n  new: %s\n", name[f], number, line, new >> changes
    }
    print new > out
  }
  close(out)
  if (status < 0) {
    printf "%s is missing, which names the versions of appinfo/info.xml: write it back, or remove its places from scripts/versions.sh.\n", name[f] >> problems
    return
  }
  close(name[f])
  if (differs) printf "%d\t%s\n", f, name[f]
  for (t = 1; t <= places; t++) {
    if (owner[t] != f || hits[t]) continue
    if (table[t]) {
      printf "%s has no table of compatibility, whose header names a column for each version, such as \"| | Nextcloud %d | Nextcloud %d |\": write it back, or change its place in scripts/versions.sh.\n", name[f], min, max >> problems
    } else {
      printf "%s has no line that says \"%s\": write it back where the versions belong, or change its place in scripts/versions.sh.\n", name[f], shown(t) >> problems
    }
  }
}

BEGIN {
  pattern["min"] = "^[0-9]+"
  pattern["max"] = "^[0-9]+"
  pattern["php"] = "^[0-9]+(\\.[0-9]+)*"
  pattern["word"] = "^[^ ]+"
  min += 0
  max += 0
  value["min"] = min
  value["max"] = max
  value["php"] = php
  lines = split(ENVIRON["VERSIONS_PLACES"], listed, "\n")
  for (i = 1; i <= lines; i++) {
    line = listed[i]
    sub(/^ +/, "", line)
    if (line == "") continue
    at = index(line, " ")
    file = substr(line, 1, at - 1)
    if (!(file in number_of)) {
      files++
      name[files] = file
      number_of[file] = files
    }
    places++
    owner[places] = number_of[file]
    template[places] = substr(line, at + 1)
    sub(/^ +/, "", template[places])
    parse(places)
  }
  if (broken) exit 2
  for (f = 1; f <= files; f++) through()
}
'

nextcloud_min="$(sed -nE 's/.*<nextcloud [^>]*min-version="([0-9]+)".*/\1/p' appinfo/info.xml)"
nextcloud_max="$(sed -nE 's/.*<nextcloud [^>]*max-version="([0-9]+)".*/\1/p' appinfo/info.xml)"
php_min="$(sed -nE 's/.*<php [^>]*min-version="([0-9.]+)".*/\1/p' appinfo/info.xml)"
if [[ -z "$nextcloud_min" || -z "$nextcloud_max" || -z "$php_min" ]]; then
  echo "appinfo/info.xml names no min-version and max-version of nextcloud, or no min-version of php."
  exit 1
fi
if ((nextcloud_min > nextcloud_max)); then
  echo "appinfo/info.xml names a min-version of nextcloud, $nextcloud_min, above its max-version, $nextcloud_max."
  exit 1
fi
versions="Nextcloud $nextcloud_min to $nextcloud_max and PHP $php_min"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
: >"$work/changes"
: >"$work/problems"
status=0
VERSIONS_PLACES="$places" LC_ALL=C awk -v min="$nextcloud_min" -v max="$nextcloud_max" \
  -v php="$php_min" -v outdir="$work" -v changes="$work/changes" -v problems="$work/problems" \
  "$program" >"$work/changed" || status=$?
if ((status != 0)); then
  cat "$work/problems"
  exit "$status"
fi
changed=0
while IFS=$'\t' read -r number file; do
  changed=$((changed + 1))
  if [[ "$mode" == write ]]; then
    cat "$work/$number" >"$file"
  fi
done <"$work/changed"

# Every description of the App Store, in whatever language, names the versions in one
# line "- Nextcloud <min> <word> <max>", such as "to", "bis", "a", "à" or "tot"; the
# place above writes them, and a description without the line needs a person, who
# writes it in its language.
descriptions="$(grep -c '<description' appinfo/info.xml || true)"
named="$(grep -cE '^- Nextcloud [0-9]+ [^ ]+ [0-9]+$' appinfo/info.xml || true)"
if [[ "$descriptions" != "$named" ]]; then
  echo "appinfo/info.xml has $descriptions description(s) for the App Store, but $named line(s) \"- Nextcloud $nextcloud_min <to> $nextcloud_max\": write one into each description, in its language." >>"$work/problems"
fi

if ((changed > 0)); then
  if [[ "$mode" == write ]]; then
    echo "Wrote $versions, as appinfo/info.xml names them, into $changed file(s):"
  else
    echo "These lines name other versions than appinfo/info.xml, $versions:"
  fi
  cat "$work/changes"
fi
if [[ -s "$work/problems" ]]; then
  cat "$work/problems"
  exit 1
fi
if [[ "$mode" == check ]] && ((changed > 0)); then
  echo "Write them there with: bash scripts/versions.sh --write"
  exit 1
fi
echo "The documentation and the descriptions of the App Store in every language name $versions, as appinfo/info.xml does."
