#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
E2E_PORT="${E2E_PORT:-18082}"
PROJECT_NAME="${E2E_PROJECT_NAME:-paperless_unified_search_e2e}"
PASSWORD="e2e-only-password"
BASE_URL="http://127.0.0.1:${E2E_PORT}"
TMP_DIR="$(mktemp -d)"
COMPOSE=("${DOCKER_BIN}" compose --project-name "${PROJECT_NAME}" --file "${SCRIPT_DIR}/compose.yaml")

export E2E_PORT

cleanup() {
	status=$?
	trap - EXIT
	if [[ "${status}" -ne 0 ]]; then
		"${COMPOSE[@]}" ps || true
		"${COMPOSE[@]}" logs --no-color --tail 200 || true
	fi
	rm -rf "${TMP_DIR}"
	if [[ "${KEEP_E2E:-0}" != "1" ]]; then
		"${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	else
		echo "E2E environment kept at ${BASE_URL} (project ${PROJECT_NAME})."
	fi
	exit "${status}"
}
trap cleanup EXIT

occ() {
	"${COMPOSE[@]}" exec -T --user www-data nextcloud php occ "$@"
}

assert_response() {
	mode="$1"
	file="$2"
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_response.py "${mode}" < "${file}"
}

# axe-core checks the pages of the app in Chromium (accessibility.mjs), inside the
# network of the Compose project. The files of the check reach the browser through
# standard input, so that npm leaves nothing in the checkout. Keep the version of the
# image of Playwright equal to playwright-core in package.json; Renovate updates both
# together, and scripts/check-project.sh compares them.
accessibility() {
	tar -C "${SCRIPT_DIR}" -cf - package.json package-lock.json accessibility.mjs \
		| "${DOCKER_BIN}" run --rm --interactive \
			--network "${PROJECT_NAME}_default" \
			--env NPM_CONFIG_UPDATE_NOTIFIER=false \
			--env E2E_USER=e2e-admin \
			--env "E2E_PASSWORD=${PASSWORD}" \
			mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27 \
			sh -c 'mkdir /tmp/browser && cd /tmp/browser && tar -xf - && npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && node accessibility.mjs'
}

search() {
	user="$1"
	user_agent="$2"
	output="$3"
	curl --fail-with-body --silent --show-error \
		--user "${user}:${PASSWORD}" \
		--user-agent "${user_agent}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--get \
		--data-urlencode 'term=mobiletest' \
		--data-urlencode 'limit=5' \
		--data-urlencode 'cursor=0' \
		--output "${output}" \
		"${BASE_URL}/ocs/v2.php/search/providers/paperless_unified_search_documents/search"
}

cd "${REPOSITORY_ROOT}"
"${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
"${COMPOSE[@]}" up --detach --wait --wait-timeout 240

if ! occ status --output=json 2>/dev/null | grep --fixed-strings '"installed":true' >/dev/null; then
	occ maintenance:install \
		--database=sqlite \
		--admin-user=e2e-admin \
		--admin-pass="${PASSWORD}" >/dev/null
fi
occ config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null
# The browser of the accessibility check reaches Nextcloud by its name in the network.
occ config:system:set trusted_domains 2 --value=nextcloud >/dev/null
occ config:system:set allow_local_remote_servers --type=boolean --value=true >/dev/null
# The coming Nextcloud of canary.sh is newer than max-version of appinfo/info.xml:
# --force enables the app there anyway, without making it compatible.
enable_options=()
if [[ "${E2E_IGNORE_MAX_VERSION:-0}" == "1" ]]; then
	enable_options+=(--force)
fi
occ app:enable "${enable_options[@]}" paperless_unified_search >/dev/null
occ router:list \
	| grep --fixed-strings 'paperless_unified_search.settings.save' \
	| grep --fixed-strings '/apps/paperless_unified_search/settings' >/dev/null

for user in e2e-user e2e-other; do
	"${COMPOSE[@]}" exec -T --user www-data --env "OC_PASS=${PASSWORD}" nextcloud \
		php occ user:add --password-from-env "${user}" >/dev/null
done
# The first-run wizard would cover the pages that the accessibility check opens.
occ app:disable firstrunwizard >/dev/null 2>&1 || true
"${COMPOSE[@]}" restart nextcloud >/dev/null
"${COMPOSE[@]}" up --detach --wait --wait-timeout 120 >/dev/null

# The administration settings render the form with its save and reset routes (#25).
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--output "${TMP_DIR}/admin-settings.html" \
	"${BASE_URL}/index.php/settings/admin/additional"
for attribute in data-save-url data-reset-url; do
	if ! grep --fixed-strings "${attribute}=\"/apps/paperless_unified_search/settings\"" \
		"${TMP_DIR}/admin-settings.html" >/dev/null; then
		echo "The administration settings render no ${attribute}." >&2
		exit 1
	fi
done

# The navigation of the administration settings lists the section with the app's
# settings, here Additional settings, on every page (#25): Nextcloud 33 as links,
# Nextcloud 34 in the initial state settings-sections.
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--output "${TMP_DIR}/admin-overview.html" \
	"${BASE_URL}/index.php/settings/admin/overview"
SECTIONS="$(grep --only-matching 'id="initial-state-settings-sections" value="[^"]*"' \
	"${TMP_DIR}/admin-overview.html" | sed 's/.*value="//; s/"$//' | base64 --decode || true)"
if ! grep --fixed-strings '/settings/admin/additional"' "${TMP_DIR}/admin-overview.html" >/dev/null &&
	! grep --fixed-strings '"id":"additional"' <<<"${SECTIONS}" >/dev/null; then
	echo "The navigation of the administration settings lists no Additional settings." >&2
	exit 1
fi

# Nextcloud loads the routes of appinfo/routes.php only for apps that are already
# loaded. The app's attribute routes exist without that, as in a PHP script (#25).
# shellcheck disable=SC2016
ROUTE="$("${COMPOSE[@]}" exec -T --user www-data nextcloud php -r '
	require "/var/www/html/lib/base.php";
	echo "route=", \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute("paperless_unified_search.settings.save"), "\n";
' 2>/dev/null | grep '^route=')"
if [[ "${ROUTE}" != *"/apps/paperless_unified_search/settings" ]]; then
	echo "The settings route is missing before the app is loaded: ${ROUTE}" >&2
	exit 1
fi

curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'url=http://paperless-mock:8080' \
	--data-urlencode 'token=e2e-only-token' \
	--data-urlencode 'alwaysSearch=0' \
	--output "${TMP_DIR}/settings.json" \
	"${BASE_URL}/apps/paperless_unified_search/settings"

if grep --fixed-strings 'e2e-only-token' "${TMP_DIR}/settings.json" >/dev/null; then
	echo "Settings response exposed the Paperless API token." >&2
	exit 1
fi

MKCOL_STATUS="$(curl --silent --output /dev/null --write-out '%{http_code}' \
	--user "e2e-user:${PASSWORD}" \
	--request MKCOL \
	"${BASE_URL}/remote.php/dav/files/e2e-user/Documents")"
if [[ "${MKCOL_STATUS}" != "201" && "${MKCOL_STATUS}" != "405" ]]; then
	echo "Unexpected WebDAV MKCOL status: ${MKCOL_STATUS}" >&2
	exit 1
fi

printf '%s\n' 'Synthetic PDF fixture for Paperless Unified Search E2E.' \
	| curl --fail-with-body --silent --show-error \
		--user "e2e-user:${PASSWORD}" \
		--upload-file - \
		"${BASE_URL}/remote.php/dav/files/e2e-user/Documents/Mobile%20viewer%20test%20%5BP123%5D.pdf" >/dev/null

curl --fail-with-body --silent --show-error \
	--user "e2e-user:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--output "${TMP_DIR}/providers-external.json" \
	"${BASE_URL}/ocs/v2.php/search/providers"
assert_response external "${TMP_DIR}/providers-external.json"

search e2e-user 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/browser.json"
assert_response browser "${TMP_DIR}/browser.json"

search e2e-user 'Mozilla/5.0 (iOS) Nextcloud-iOS/7.1.0' "${TMP_DIR}/ios.json"
assert_response ios "${TMP_DIR}/ios.json"

search e2e-user 'Mozilla/5.0 (Android) Nextcloud-android/20260390' "${TMP_DIR}/android.json"
assert_response android "${TMP_DIR}/android.json"

search e2e-other 'Mozilla/5.0 (Android) Nextcloud-android/20260390' "${TMP_DIR}/inaccessible.json"
assert_response no-results "${TMP_DIR}/inaccessible.json"

curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'url=http://paperless-mock:8080' \
	--data-urlencode 'token=' \
	--data-urlencode 'alwaysSearch=1' \
	--output "${TMP_DIR}/settings-trusted.json" \
	"${BASE_URL}/apps/paperless_unified_search/settings"

curl --fail-with-body --silent --show-error \
	--user "e2e-user:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--output "${TMP_DIR}/providers-trusted.json" \
	"${BASE_URL}/ocs/v2.php/search/providers"
assert_response trusted "${TMP_DIR}/providers-trusted.json"

# The administration settings meet WCAG 2.1 AA, also with the message after saving.
accessibility

"${COMPOSE[@]}" exec -T nextcloud sh -c 'test ! -f /var/www/html/data/nextcloud.log || cat /var/www/html/data/nextcloud.log' \
	| "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_log.py

echo "Docker E2E passed: access filtering, trusted mode, browser, iOS, and Android contracts, and accessibility."
