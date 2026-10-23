#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Records the animation of the demo, screenshots/demo.gif, for the README and the guide
# of the demo (demo/README.md): the real Paperless-ngx and the real Nextcloud of
# demo/seed.sh, in which jamie searches for "invoice" and opens the home insurance
# renewal. Run it after a change of what the demo shows:
#   bash scripts/demo-gif.sh
# The Demo workflow (.github/workflows/demo.yml) records it in each of its runs and
# keeps it as the artifact demo-gif.
#
# It needs Docker. It runs bash demo/seed.sh first, which starts the demo, or finds it
# running and adds what is missing, then Chromium in the image of Playwright, in the
# network of the demo, goes through it as jamie, with the password of demo/.env
# (tests/e2e/demo-recording.mjs). The demo keeps running afterwards, as after seed.sh;
#   docker compose -f demo/compose.yaml down --volumes
# removes it with its data.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Git Bash on Windows: keep the paths of the container as they are.
export MSYS_NO_PATHCONV=1
DOCKER_BIN="${DOCKER_BIN:-docker}"
# The Compose project of demo/compose.yaml, whose network the browser joins, and the
# name of Nextcloud in it, which the demo trusts.
NETWORK="paperless-unified-search-demo_default"
BASE_URL="http://nextcloud"
# The image of Playwright of the end-to-end tests, which Renovate keeps current.
IMAGE="$(grep -oE 'mcr\.microsoft\.com/playwright:v[^[:space:]]+' tests/e2e/run.sh | head -n 1)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

bash demo/seed.sh

# The password of jamie, which seed.sh generated into demo/.env. Docker hands it to the
# browser with --env by its name, never on a command line.
E2E_PASSWORD="$(sed -n 's/^DEMO_PASSWORD=//p' demo/.env | tail -n 1)"
if [[ -z "${E2E_PASSWORD}" ]]; then
	echo "demo/.env holds no DEMO_PASSWORD, the password of jamie; remove the demo and run bash scripts/demo-gif.sh again." >&2
	exit 1
fi
export E2E_PASSWORD

printf '\n== %s s: %s\n' "${SECONDS}" "Record the search as jamie"
# The files of the browser reach the container through standard input, and the
# animation leaves it the same way, so that it belongs to whoever runs this. The shell of
# the container, not this one, reads its variables.
# shellcheck disable=SC2016
tar -C tests/e2e -cf - package.json package-lock.json ui.mjs recording.mjs demo-recording.mjs \
	| "${DOCKER_BIN}" run --rm --interactive \
		--network "${NETWORK}" \
		--env NPM_CONFIG_UPDATE_NOTIFIER=false \
		--env "E2E_BASE_URL=${BASE_URL}" \
		--env E2E_PASSWORD \
		"${IMAGE}" \
		sh -c 'mkdir /tmp/browser && cd /tmp/browser && tar -xf - &&
			npm ci --ignore-scripts --no-audit --no-fund --loglevel=error >&2 &&
			out="$(mktemp -d)" && RECORDING_OUT="${out}" node demo-recording.mjs >&2 &&
			tar -C "${out}" -cf - .' \
	| tar -C "${TMP_DIR}" -xf -

if [[ ! -s "${TMP_DIR}/demo.gif" ]]; then
	echo "The browser recorded no demo.gif; its log is above." >&2
	exit 1
fi
cp "${TMP_DIR}/demo.gif" screenshots/demo.gif
ls -l screenshots/demo.gif
