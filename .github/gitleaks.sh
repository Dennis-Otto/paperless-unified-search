#!/usr/bin/env bash
# gitleaks, which looks for secrets in the commits of a pull request, from its base to
# its head, or without them in the whole history of every branch. Each finding becomes
# an annotation at its line and a row of the summary, with links to the line and the
# commit and the fingerprint that .gitleaksignore takes; gitleaks redacts the secret
# in its report and its log. It fails on a finding and when gitleaks fails itself.
#   bash .github/gitleaks.sh [base head]
# A pull request that changes gitleaks or its rules, in .github/gitleaks.sh,
# .gitleaks.toml or .gitleaksignore, has the whole history of its head scanned, so that
# a new rule fails the pull request that brings it rather than the weekly scan after it;
# the branches of others stay out of it, so that only its own history decides.
# gitleaks comes from its release on GitHub, at the version below and checked by its
# sha256, through .github/download.sh and once in a job, with the token in GH_TOKEN,
# which gitleaks itself doesn't get. Its action downloaded it without a token, which
# failed for hours on 8 November 2026 (decision 0139).
# https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail

# Renovate moves the version, and pins.py of the blueprint writes its sha256.
# renovate: datasource=github-releases depName=gitleaks/gitleaks extractVersion=^v(?<version>.+)$
version=8.30.1
sha256=551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb

usage="Usage: bash .github/gitleaks.sh [base head], two commits by their full hash"
if (($# != 0 && $# != 2)); then
  echo "$usage" >&2
  exit 2
fi
# The commits go into the options of git log, so only a hash may.
for commit in "$@"; do
  if [[ ! "$commit" =~ ^[0-9a-f]{40}([0-9a-f]{24})?$ ]]; then
    echo "$commit is no commit. $usage" >&2
    exit 2
  fi
done
range=()
what="the whole history"
if (($# == 2)); then
  rules="$(git diff --name-only "$1...$2" -- .github/gitleaks.sh .gitleaks.toml .gitleaksignore)"
  if [[ -n "$rules" ]]; then
    range=(--log-opts "$2")
    what="the whole history of the pull request, since it changes gitleaks or its rules"
  else
    range=(--log-opts "$1..$2")
    what="the commits of the pull request"
  fi
fi

folder="$RUNNER_TEMP/gitleaks-$version"
if [[ ! -x "$folder/gitleaks" ]]; then
  bash "$(dirname "${BASH_SOURCE[0]}")/download.sh" gitleaks/gitleaks "v$version" \
    "gitleaks_${version}_linux_x64.tar.gz" "$RUNNER_TEMP/gitleaks.tar.gz" "$sha256"
  mkdir -p "$folder"
  tar --extract --gzip --file "$RUNNER_TEMP/gitleaks.tar.gz" --directory "$folder" gitleaks
  rm -f "$RUNNER_TEMP/gitleaks.tar.gz"
fi

echo "gitleaks $version scans $what."
report="$RUNNER_TEMP/gitleaks.json"
rm -f "$report"
code=0
env -u GH_TOKEN -u GITHUB_TOKEN "$folder/gitleaks" git --redact --verbose --no-banner \
  --exit-code 2 --report-format json --report-path "$report" "${range[@]}" . || code=$?

if ((code == 0)); then
  echo "gitleaks $version found no secret in $what." >> "$GITHUB_STEP_SUMMARY"
  exit 0
fi
if ((code != 2)) || [[ ! -s "$report" ]]; then
  echo "::error title=gitleaks failed::gitleaks $version ended with exit code $code before the end of its scan, which is no finding of a secret: its log says why."
  echo "gitleaks $version ended with exit code $code before the end of its scan; its log says why." >> "$GITHUB_STEP_SUMMARY"
  exit 1
fi

# An annotation for each finding, whose values a workflow command takes escaped.
jq -r '
  def value: gsub("%"; "%25") | gsub("\r"; "%0D") | gsub("\n"; "%0A");
  def property: value | gsub(":"; "%3A") | gsub(","; "%2C");
  .[]
  | "::error file=\(.File | property),line=\(.StartLine),endLine=\(.EndLine),"
    + "title=\("gitleaks: \(.RuleID)" | property)::"
    + ("\(.Description) in commit \(.Commit[:7]). Revoke the secret, or add its"
      + " fingerprint to .gitleaksignore if it is none: \(.Fingerprint)" | value)
' "$report"
# The summary as a table of HTML, which Markdown leaves as it is, also for a file name
# such as __init__.py.
found="$(jq length "$report")"
secrets=secrets
((found != 1)) || secrets=secret
{
  echo "## gitleaks found $found $secrets in $what"
  echo
  echo "<table>"
  echo "<tr><th>Rule</th><th>Line</th><th>Commit</th><th>Fingerprint for <code>.gitleaksignore</code></th></tr>"
  jq -r --arg url "$GITHUB_SERVER_URL/$GITHUB_REPOSITORY" '
    def path: split("/") | map(@uri) | join("/");
    .[]
    | "<tr><td>\(.RuleID | @html): \(.Description | @html)</td>"
      + "<td><a href=\"\("\($url)/blob/\(.Commit)/\(.File | path)#L\(.StartLine)" | @html)\">"
      + "\(.File | @html):\(.StartLine)</a></td>"
      + "<td><a href=\"\("\($url)/commit/\(.Commit)" | @html)\">\(.Commit[:7])</a></td>"
      + "<td><code>\(.Fingerprint | @html)</code></td></tr>"
  ' "$report"
  echo "</table>"
  echo
} >> "$GITHUB_STEP_SUMMARY"
exit 1
