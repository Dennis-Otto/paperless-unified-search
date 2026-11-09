#!/usr/bin/env bash
# lychee, which checks the links and anchors of the documents and of the website, run
# as its action ran it: the arguments after the report name the documents and the
# rules, the report in Markdown goes into the log and the summary of the step too,
# and the exit code of lychee into the output exit_code of the step. It fails when
# lychee fails, or found no link at all, which means that it read no document: with
# --no-fail only for the latter, and with --may-find-none for neither of them.
#   bash .github/lychee.sh [--no-fail] [--may-find-none] report.md [lychee arguments]
# lychee comes from its release on GitHub, at the version below and checked by its
# sha256, through .github/download.sh and once in a job. The action downloaded it
# without a token, which failed for hours on 8 November 2026 (decision 0133).
# https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail

# Renovate moves the version, and pins.py of the blueprint writes its sha256.
# renovate: datasource=github-releases depName=lycheeverse/lychee extractVersion=^lychee-v(?<version>.+)$
version=0.24.2
sha256=1f4e0ef7f6554a6ed33dd7ac144fb2e1bbed98598e7af973042fc5cd43951c9a

fail=true
none=fail
while [[ "${1:-}" == --* ]]; do
  case "$1" in
    --no-fail) fail=false ;;
    --may-find-none) none=pass ;;
    *)
      echo "Unknown option $1. Usage: bash .github/lychee.sh [--no-fail] [--may-find-none] report.md [arguments]" >&2
      exit 2
      ;;
  esac
  shift
done
if (($# == 0)); then
  echo "Usage: bash .github/lychee.sh [--no-fail] [--may-find-none] report.md [arguments]" >&2
  exit 2
fi
report="$1"
shift

folder="$RUNNER_TEMP/lychee-$version"
if [[ ! -x "$folder/lychee" ]]; then
  bash "$(dirname "${BASH_SOURCE[0]}")/download.sh" lycheeverse/lychee "lychee-v$version" \
    lychee-x86_64-unknown-linux-gnu.tar.gz "$RUNNER_TEMP/lychee.tar.gz" "$sha256"
  mkdir -p "$folder"
  tar --extract --gzip --file "$RUNNER_TEMP/lychee.tar.gz" --directory "$folder" \
    --strip-components 1 lychee-x86_64-unknown-linux-gnu/lychee
  rm -f "$RUNNER_TEMP/lychee.tar.gz"
fi
# The token for the links to GitHub, which the action handed to lychee as well.
if [[ -z "${GITHUB_TOKEN:-}" && -n "${GH_TOKEN:-}" ]]; then
  export GITHUB_TOKEN="$GH_TOKEN"
fi

mkdir -p "$(dirname "$report")"
rm -f "$report"
code=0
"$folder/lychee" --mode task --format markdown --output "$report" "$@" || code=$?
if [[ ! -s "$report" ]]; then
  echo "lychee wrote no report; its log says why." > "$report"
fi
cat "$report"
cat "$report" >> "$GITHUB_STEP_SUMMARY"
echo "exit_code=$code" >> "$GITHUB_OUTPUT"
# The report of an issue links the log, as the one of the action did.
printf '\n[The log of the check](%s/%s/actions/runs/%s)\n' \
  "$GITHUB_SERVER_URL" "$GITHUB_REPOSITORY" "$GITHUB_RUN_ID" >> "$report"
if [[ "$none" == fail ]] && grep -qE 'Total +\| +0 +\|' "$report"; then
  echo "::error title=No link::lychee found no link: its arguments name no document that it could read."
  exit 1
fi
if [[ "$fail" == true ]]; then
  exit "$code"
fi
