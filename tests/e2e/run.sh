#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
E2E_PORT="${E2E_PORT:-18082}"
PROJECT_NAME="paperless_unified_search_e2e"
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
occ config:system:set allow_local_remote_servers --type=boolean --value=true >/dev/null
occ app:enable paperless_unified_search >/dev/null
occ router:list \
	| grep --fixed-strings 'paperless_unified_search.settings.save' \
	| grep --fixed-strings '/apps/paperless_unified_search/settings' >/dev/null

for user in e2e-user e2e-other; do
	"${COMPOSE[@]}" exec -T --user www-data --env "OC_PASS=${PASSWORD}" nextcloud \
		php occ user:add --password-from-env "${user}" >/dev/null
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

"${COMPOSE[@]}" exec -T nextcloud sh -c 'test ! -f /var/www/html/data/nextcloud.log || cat /var/www/html/data/nextcloud.log' \
	| "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_log.py

echo "Docker E2E passed: access filtering, trusted mode, browser, iOS, and Android contracts."
