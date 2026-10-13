#!/usr/bin/env bash
# Create the annotated tag $1 at the commit $2, by default the commit of this run, and
# push it, signed without a key by gitsign: Sigstore certifies the identity of the
# running workflow, and verify-release.yml checks that it is the release workflow of
# this repository. A tag that GitHub has already, such as the one of an earlier run
# that failed after it pushed the tag, must be such a tag of the release workflow at
# that commit; any other fails. Sigstore and GitHub fail for a moment now and then, so
# every step that asks them tries three times.
#   bash .github/sign-tag.sh v1.2.3 [commit]
# With verify, it only checks that the tag on GitHub is one that the release workflow
# signed, at the given commit, and fails for any other tag.
#   bash .github/sign-tag.sh verify v1.2.3 commit
# For the release bot only; it needs the permission id-token: write to sign and, in
# GH_TOKEN, a token that may push tags, or read them to verify.
# https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail

version=0.17.1
sha256=69213a8a0813a151e5a47d0060862952ff833a845d57309dff76f7ba6600abae
remote="https://github.com/$GITHUB_REPOSITORY.git"
issuer=https://token.actions.githubusercontent.com
release="https://github.com/$GITHUB_REPOSITORY/.github/workflows/release.yml@refs/heads/main"

# gh hands the token to git, so that it appears in no URL and no file.
github() {
  git -c credential.helper= -c credential.helper='!gh auth git-credential' "$@"
}

# Runs the command up to three times, 10 and 20 seconds apart.
retry() {
  local attempt
  for attempt in 1 2 3; do
    if "$@"; then
      return 0
    fi
    if ((attempt == 3)); then
      return 1
    fi
    sleep $((attempt * 10))
  done
}

# Whether the tag $1 on GitHub is an annotated tag at the commit $2, signed by the
# release workflow. It runs in conditions, where bash doesn't stop at an error, so
# every step checks its own.
verify() {
  local tag="$1" commit="$2" repository target
  repository="$(mktemp -d)"
  git init --quiet "$repository"
  if ! retry github -C "$repository" fetch --quiet --depth=1 "$remote" "refs/tags/$tag:refs/tags/$tag"; then
    echo "::error::The tag $tag could not be fetched from GitHub."
    return 1
  fi
  if [[ "$(git -C "$repository" cat-file -t "refs/tags/$tag")" != tag ]]; then
    echo "::error::The tag $tag is not a signed tag; only the release workflow may create the tags of releases."
    return 1
  fi
  target="$(git -C "$repository" rev-parse "refs/tags/$tag^{commit}")"
  if [[ "$target" != "$commit" ]]; then
    echo "::error::The tag $tag points at $target, not at the commit of the release, $commit."
    return 1
  fi
  if ! (cd "$repository" && retry "$gitsign" verify-tag "$tag" \
    --certificate-identity "$release" --certificate-oidc-issuer "$issuer"); then
    echo "::error::The signature of the tag $tag isn't one of the release workflow."
    return 1
  fi
  echo "The tag $tag is signed by the release workflow, at $commit."
}

gitsign="$RUNNER_TEMP/gitsign"
curl --fail --location --silent --show-error --retry 5 --retry-all-errors --connect-timeout 15 \
  --max-time 300 --output "$gitsign" \
  "https://github.com/sigstore/gitsign/releases/download/v$version/gitsign_${version}_linux_amd64"
echo "$sha256  $gitsign" | sha256sum --check --strict
chmod +x "$gitsign"

if [[ "$1" == verify ]]; then
  if verify "$2" "$3"; then
    exit 0
  fi
  exit 1
fi

tag="$1"
commit="${2:-$GITHUB_SHA}"
if ! pushed="$(retry github ls-remote --refs "$remote" "refs/tags/$tag")"; then
  echo "::error::The tags on GitHub could not be read."
  exit 1
fi
if [[ -n "$pushed" ]]; then
  # The tag of an earlier run, which failed after it pushed the tag.
  if verify "$tag" "$commit"; then
    exit 0
  fi
  exit 1
fi

# The commit of an earlier merge isn't in the shallow checkout of a later run.
if ! git cat-file -e "$commit^{commit}" 2>/dev/null; then
  retry github fetch --quiet --depth=1 "$remote" "$commit"
fi
git config user.name "The release bot of $GITHUB_REPOSITORY"
git config user.email "noreply@github.com"
git config gpg.format x509
git config gpg.x509.program "$gitsign"
for attempt in 1 2 3; do
  if git tag --sign --message "$tag" "$tag" "$commit" &&
    "$gitsign" verify-tag "$tag" \
      --certificate-identity "https://github.com/$GITHUB_WORKFLOW_REF" \
      --certificate-oidc-issuer "$issuer"; then
    break
  fi
  git tag --delete "$tag" >/dev/null 2>&1 || true
  if ((attempt == 3)); then
    echo "::error::The tag $tag could not be signed."
    exit 1
  fi
  sleep $((attempt * 10))
done

# A push can fail after GitHub took the tag, as with "fatal error in commit_refs", so
# what counts is the tag on GitHub; every attempt pushes the same signed tag.
signed="$(git rev-parse "refs/tags/$tag")"
for attempt in 1 2 3; do
  if github push "$remote" "refs/tags/$tag"; then
    exit 0
  fi
  pushed="$(github ls-remote --refs "$remote" "refs/tags/$tag" | cut -f 1)" || pushed=""
  if [[ "$pushed" == "$signed" ]]; then
    echo "GitHub has the tag $tag, although the push failed."
    exit 0
  fi
  if [[ -n "$pushed" ]]; then
    # Another tag came first; only one of the release workflow at this commit will do.
    if verify "$tag" "$commit"; then
      exit 0
    fi
    exit 1
  fi
  if ((attempt == 3)); then
    echo "::error::The tag $tag could not be pushed."
    exit 1
  fi
  sleep $((attempt * 10))
done
