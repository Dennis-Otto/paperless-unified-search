#!/usr/bin/env bash
# Download the asset $3 of the release $2 of the repository $1 on GitHub into the file
# $4, and check it by the sha256 $5, or without one by the digest that GitHub keeps of
# the asset, so that nothing runs a download that isn't the asset.
#   bash .github/download.sh lycheeverse/lychee lychee-v0.24.2 \
#     lychee-x86_64-unknown-linux-gnu.tar.gz "$RUNNER_TEMP/lychee.tar.gz" 1f4e0ef7…
# gh downloads it through the API, with the token in GH_TOKEN: on 8 November 2026,
# GitHub's downloads without a token, from the page of a release, timed out for hours
# and failed the checks of every repository, while those through the API kept working
# (decision 0133). After an error of the network or of GitHub, or when a call stalls
# for DOWNLOAD_TIMEOUT seconds (300 by default), it tries again, five times in all, 10,
# 20, 40 and 80 seconds apart; when the release or the asset doesn't exist, or the
# token is refused, it stops at once.
# https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail

if (($# < 4 || $# > 5)); then
  echo "Usage: bash .github/download.sh owner/repository tag asset file [sha256]" >&2
  exit 2
fi
repository="$1"
tag="$2"
asset="$3"
file="$4"
sha256="${5:-}"
# The names go into a pattern of gh and a filter of jq as they are.
for name in "$repository" "$tag" "$asset"; do
  if [[ ! "$name" =~ ^[A-Za-z0-9._/+-]+$ ]]; then
    echo "::error title=Download::$name is no name of a repository, a release or an asset."
    exit 2
  fi
done
what="$asset of $repository $tag"

# What gh says when the release or the asset isn't there or the token is wrong, which
# no second try changes.
lasting='release not found|no assets match|HTTP 40[14]|HTTP 422|GH_TOKEN'
errors="$(mktemp)"
trap 'rm -f "$errors"' EXIT

# gh waits for an answer without a limit, and GitHub's outage of 8 November 2026
# began as timeouts: a call that stalls ends, and is tried again like an error.
limit="${DOWNLOAD_TIMEOUT:-300}"

# Runs the command of gh, and again after an error, with its errors in the log.
tried() {
  local wait status
  for wait in 10 20 40 80 0; do
    status=0
    timeout --kill-after=10 "$limit" "$@" 2>"$errors" || status=$?
    if ((status == 0)); then
      return 0
    fi
    if ((status == 124 || status == 137)); then
      echo "GitHub didn't answer in $limit seconds." >>"$errors"
    fi
    cat "$errors" >&2
    if grep -qE "$lasting" "$errors"; then
      echo "::error title=Download::$what can't be downloaded: $(head -n 1 "$errors")" >&2
      return 1
    fi
    if ((wait == 0)); then
      echo "::error title=GitHub fails::$what can't be downloaded: GitHub failed five times in" \
        "two and a half minutes, an outage of GitHub and not a problem of this change" \
        "(https://www.githubstatus.com). Run the job again once GitHub works." >&2
      return 1
    fi
    echo "GitHub failed; $what is asked for again in $wait seconds." >&2
    sleep "$wait"
  done
}

if [[ -z "$sha256" ]]; then
  digest="$(tried gh api --header "X-GitHub-Api-Version: 2022-11-28" "repos/$repository/releases/tags/$tag" \
    --jq ".assets[] | select(.name == \"$asset\") | .digest // \"\"")"
  if [[ ! "$digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
    echo "::error title=Download::GitHub keeps no sha256 of $what, so it can't be checked: pin its sha256."
    exit 1
  fi
  sha256="${digest#sha256:}"
fi
mkdir -p "$(dirname "$file")"
tried gh release download "$tag" --repo "$repository" --pattern "$asset" --output "$file" --clobber
if ! echo "$sha256  $file" | sha256sum --check --strict --status; then
  rm -f "$file"
  echo "::error title=Download::The download of $what doesn't have the sha256 $sha256, so it isn't the asset that was pinned, and nothing runs it."
  exit 1
fi
echo "$what is downloaded and has its sha256."
