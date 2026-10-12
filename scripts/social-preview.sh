#!/usr/bin/env bash
# Renders .github/social-preview.html into .github/social-preview.png: the 1280×640
# image that GitHub, chats and social networks show for links to the repository.
# After a change, upload it under Settings → General → Social preview.
#
# It needs Docker: the headless Chromium of Playwright's image renders the page.
# The blueprint keeps this file current: https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# The image comes from a Dockerfile, so that Renovate keeps it current.
image="$(sed -n 's/^FROM //p' .github/social-preview/Dockerfile)"
# Git Bash on Windows: keep the container's paths and mount the Windows path.
export MSYS_NO_PATHCONV=1
root="$(pwd -W 2>/dev/null || pwd)"
png="$(mktemp)"
log="$(mktemp)"
trap 'rm -f "$png" "$log"' EXIT

# The image leaves the container through stdout, so it belongs to whoever runs this.
if docker run --rm --volume "$root:/work:ro" "$image" sh -c '
  /ms-playwright/chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell \
    --no-sandbox --hide-scrollbars --force-device-scale-factor=1 --window-size=1280,640 \
    --virtual-time-budget=10000 --screenshot=/tmp/social-preview.png \
    file:///work/.github/social-preview.html >&2 && cat /tmp/social-preview.png' >"$png" 2>"$log" &&
  [[ -s "$png" ]]; then
  cp "$png" .github/social-preview.png
  echo "Rendered .github/social-preview.png; upload it under Settings → General → Social preview."
else
  cat "$log" >&2
  echo "Rendering .github/social-preview.html failed." >&2
  exit 1
fi
