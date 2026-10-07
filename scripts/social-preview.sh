#!/usr/bin/env bash
# Renders .github/social-preview.html into .github/social-preview.png: the 1280×640
# image that GitHub, chats and social networks show for links to the repository.
# After a change, upload it under Settings → General → Social preview.
#
# It needs Docker: the headless Chromium of Playwright's image renders the page.
# The blueprint keeps this file current: https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

image="mcr.microsoft.com/playwright/python:v1.63.0-noble@sha256:72bd171a9ffc2b4b59532aaa6210e21014d07093120dc25528870c0b840da1f0"
# Git Bash on Windows: keep the container's paths and mount the Windows path.
export MSYS_NO_PATHCONV=1
root="$(pwd -W 2>/dev/null || pwd)"

docker run --rm --user "$(id -u):$(id -g)" --env HOME=/tmp --volume "$root:/work" "$image" sh -c '
  exec /ms-playwright/chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell \
    --no-sandbox --hide-scrollbars --force-device-scale-factor=1 --window-size=1280,640 \
    --virtual-time-budget=10000 --screenshot=/work/.github/social-preview.png \
    file:///work/.github/social-preview.html' 2>/dev/null
echo "Rendered .github/social-preview.png; upload it under Settings → General → Social preview."
