#!/usr/bin/env bash
# Create the annotated tag $1 at the commit of this run and push it, signed without a
# key by gitsign: Sigstore certifies the identity of the running workflow, and
# verify-release.yml checks that it is the release workflow of this repository.
# For the release bot only; it needs the permission id-token: write and, in GH_TOKEN,
# a token that may push tags.
# https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail

tag="$1"
version=0.17.1
sha256=69213a8a0813a151e5a47d0060862952ff833a845d57309dff76f7ba6600abae

gitsign="$RUNNER_TEMP/gitsign"
curl --fail --location --silent --show-error --output "$gitsign" \
  "https://github.com/sigstore/gitsign/releases/download/v$version/gitsign_${version}_linux_amd64"
echo "$sha256  $gitsign" | sha256sum --check --strict
chmod +x "$gitsign"

git config user.name "The release bot of $GITHUB_REPOSITORY"
git config user.email "noreply@github.com"
git config gpg.format x509
git config gpg.x509.program "$gitsign"
git tag --sign --message "$tag" "$tag" "$GITHUB_SHA"
"$gitsign" verify-tag "$tag" \
  --certificate-identity "https://github.com/$GITHUB_WORKFLOW_REF" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
# gh hands the token to git, so that it appears in no URL and no file.
git -c credential.helper= -c credential.helper='!gh auth git-credential' \
  push "https://github.com/$GITHUB_REPOSITORY.git" "refs/tags/$tag"
