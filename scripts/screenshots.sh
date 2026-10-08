#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Takes the screenshots and the animation in screenshots/, which the README, the
# website and the App Store show. Run it after a change of what users see:
#   bash scripts/screenshots.sh
#
# It needs Docker. It starts Nextcloud and the mock of Paperless of the end-to-end
# tests under a Compose project of its own and sets up a demo: the archive account
# paperless, whose folder Paperless holds synthetic documents and is shared read-only
# with jamie, and the documents that the mock finds for "invoice". Chromium in the
# image of Playwright then goes through the app as a person does
# (tests/e2e/screenshots.mjs). KEEP_SCREENSHOTS=1 leaves the demo running at
# http://127.0.0.1:18083, for jamie, paperless and e2e-admin with the password
# e2e-only-password.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Git Bash on Windows: keep the paths of the container as they are.
export MSYS_NO_PATHCONV=1
export E2E_PORT="${SCREENSHOTS_PORT:-18083}"
PROJECT_NAME=paperless_unified_search_screenshots
PASSWORD=e2e-only-password
BASE_URL="http://127.0.0.1:${E2E_PORT}"
COMPOSE=(docker compose --project-name "${PROJECT_NAME}" --file tests/e2e/compose.yaml)
# The image of Playwright of the end-to-end tests, which Renovate keeps current.
IMAGE="$(grep -oE 'mcr\.microsoft\.com/playwright:v[^[:space:]]+' tests/e2e/run.sh | head -n 1)"
TMP_DIR="$(mktemp -d)"

cleanup() {
	status=$?
	trap - EXIT
	rm -rf "${TMP_DIR}"
	if [[ "${KEEP_SCREENSHOTS:-0}" == "1" ]]; then
		echo "The demo runs at ${BASE_URL} (project ${PROJECT_NAME})."
	else
		"${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	fi
	exit "${status}"
}
trap cleanup EXIT

occ() {
	"${COMPOSE[@]}" exec -T --user www-data nextcloud php occ "$@"
}

"${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
"${COMPOSE[@]}" up --detach --wait --wait-timeout 240 >/dev/null

if ! occ status --output=json 2>/dev/null | grep --fixed-strings '"installed":true' >/dev/null; then
	occ maintenance:install --database=sqlite --admin-user=e2e-admin --admin-pass="${PASSWORD}" >/dev/null
fi
occ config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null
occ config:system:set trusted_domains 2 --value=nextcloud >/dev/null
occ config:system:set allow_local_remote_servers --type=boolean --value=true >/dev/null
occ config:system:set default_language --value=en >/dev/null
occ config:system:set default_locale --value=en_US >/dev/null
occ app:enable paperless_unified_search >/dev/null
# The first-run wizard would cover the pages, the announcements and the survey would
# add notifications.
for app in firstrunwizard nextcloud_announcements survey_client; do
	occ app:disable "${app}" >/dev/null 2>&1 || true
done
occ user:setting e2e-admin settings display_name 'Alex Admin' >/dev/null
for account in 'paperless:Paperless archive' 'jamie:Jamie Example'; do
	"${COMPOSE[@]}" exec -T --user www-data --env "OC_PASS=${PASSWORD}" nextcloud \
		php occ user:add --password-from-env --display-name="${account#*:}" "${account%%:*}" >/dev/null
done
"${COMPOSE[@]}" restart nextcloud >/dev/null
"${COMPOSE[@]}" up --detach --wait --wait-timeout 120 >/dev/null

curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'url=http://paperless-mock:8080' \
	--data-urlencode 'token=e2e-only-token' \
	--data-urlencode 'alwaysSearch=0' \
	--data-urlencode 'archiveOwner=paperless' \
	"${BASE_URL}/apps/paperless_unified_search/settings" >/dev/null

# The files of the browser reach the container through standard input, and the
# pictures leave it the same way, so that they belong to whoever runs this.
tar -C tests/e2e -cf - package.json package-lock.json screenshots.mjs \
	| docker run --rm --interactive \
		--network "${PROJECT_NAME}_default" \
		--env NPM_CONFIG_UPDATE_NOTIFIER=false \
		--env "E2E_PASSWORD=${PASSWORD}" \
		"${IMAGE}" \
		sh -c 'mkdir /tmp/browser && cd /tmp/browser && tar -xf - &&
			npm ci --ignore-scripts --no-audit --no-fund --loglevel=error >&2 &&
			SCREENSHOTS_OUT=/tmp/screenshots node screenshots.mjs >&2 &&
			tar -C /tmp/screenshots -cf - .' \
	| tar -C "${TMP_DIR}" -xf -

cp "${TMP_DIR}"/*.png "${TMP_DIR}"/*.gif screenshots/
ls -l screenshots/
