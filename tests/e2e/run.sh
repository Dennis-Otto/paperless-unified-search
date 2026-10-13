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
# The browser of the accessibility check. Keep its version equal to playwright-core in
# package.json: Renovate updates both together, and scripts/check-project.sh compares
# them. The name stays on one line without quotes, where that check, run-all.sh and
# scripts/screenshots.sh find it.
PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27
PLAYWRIGHT_PULL=""

export E2E_PORT
# Git Bash on Windows: keep the paths in the container of the mock and the paths of the
# shares in Nextcloud as they are.
export MSYS2_ARG_CONV_EXCL="/mock/;path=/${MSYS2_ARG_CONV_EXCL:+;${MSYS2_ARG_CONV_EXCL}}"

cleanup() {
	status=$?
	trap - EXIT
	if [[ "${status}" -ne 0 ]]; then
		"${COMPOSE[@]}" ps || true
		"${COMPOSE[@]}" logs --no-color --tail 200 || true
	fi
	rm -rf "${TMP_DIR}"
	if [[ -n "${PLAYWRIGHT_PULL}" ]]; then
		kill "${PLAYWRIGHT_PULL}" >/dev/null 2>&1 || true
	fi
	if [[ "${KEEP_E2E:-0}" == "1" ]]; then
		echo "E2E environment kept at ${BASE_URL} (project ${PROJECT_NAME})."
	elif [[ "${RUNNER_ENVIRONMENT:-}" != "github-hosted" ]]; then
		# The containers hold nothing worth a graceful stop. A runner of GitHub is
		# thrown away after the job, so there the files of Nextcloud aren't deleted.
		"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 0 >/dev/null 2>&1 || true
	fi
	exit "${status}"
}
trap cleanup EXIT
# Stopped by the deadline of run-all.sh: the log shows the state of the containers.
trap 'exit 143' TERM

# A heading with the seconds since the start, so that the log shows where the time goes.
phase() {
	printf '\n== %s s: %s\n' "${SECONDS}" "$1"
}

occ() {
	"${COMPOSE[@]}" exec -T --user www-data nextcloud php occ "$@"
}

# Checks the answer of Nextcloud in the file $2 in the mode $1 of assert_response.py,
# with the rest as the arguments of the mode.
assert_response() {
	mode="$1"
	file="$2"
	shift 2
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_response.py "${mode}" "$@" < "${file}"
}

# axe-core checks the pages of the app in Chromium (accessibility.mjs), inside the
# network of the Compose project, and the script checks what the settings page does.
# The files of the check reach the browser through standard input, so that npm leaves
# nothing in the checkout.
accessibility() {
	# The image has been loading since the start; a failed pull leaves it to docker run.
	if [[ -n "${PLAYWRIGHT_PULL}" ]]; then
		wait "${PLAYWRIGHT_PULL}" || true
		PLAYWRIGHT_PULL=""
	fi
	tar -C "${SCRIPT_DIR}" -cf - package.json package-lock.json accessibility.mjs \
		| "${DOCKER_BIN}" run --rm --interactive \
			--network "${PROJECT_NAME}_default" \
			--env NPM_CONFIG_UPDATE_NOTIFIER=false \
			--env E2E_USER=e2e-admin \
			--env "E2E_PASSWORD=${PASSWORD}" \
			"${PLAYWRIGHT_IMAGE}" \
			sh -c 'mkdir /tmp/browser && cd /tmp/browser && tar -xf - && npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && node accessibility.mjs'
}

# Sends a request and expects the HTTP status $1. $2 says what the request does, for the
# message of a failure; the rest are its options of curl. The answer lands in
# ${TMP_DIR}/answer.json.
expect_status() {
	expected="$1"
	request="$2"
	shift 2
	status="$(curl --silent --show-error \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--output "${TMP_DIR}/answer.json" \
		--write-out '%{http_code}' \
		"$@")"
	if [[ "${status}" != "${expected}" ]]; then
		echo "${request} answered HTTP ${status} instead of ${expected}." >&2
		exit 1
	fi
}

# The number of requests for documents that have reached the mock of Paperless so far.
mock_requests() {
	"${COMPOSE[@]}" logs --no-color paperless-mock | grep --count --fixed-strings 'GET /api/documents/' || true
}

# Searches as the user $1 with the user agent $2 for the term $4, mobiletest by default,
# from the cursor $5, 0 by default, 5 results as Nextcloud's web search asks for them.
# The answer lands in the file $3.
search() {
	user="$1"
	user_agent="$2"
	output="$3"
	term="${4:-mobiletest}"
	cursor="${5:-0}"
	curl --fail-with-body --silent --show-error \
		--user "${user}:${PASSWORD}" \
		--user-agent "${user_agent}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--get \
		--data-urlencode "term=${term}" \
		--data-urlencode 'limit=5' \
		--data-urlencode "cursor=${cursor}" \
		--output "${output}" \
		"${BASE_URL}/ocs/v2.php/search/providers/paperless_unified_search_documents/search"
}

# Resolves the link $2 as the user $1 into $3, as Talk, Text and the other apps of the
# smart picker do to render a link that someone inserted.
resolve() {
	user="$1"
	link="$2"
	output="$3"
	curl --fail-with-body --silent --show-error \
		--user "${user}:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--get \
		--data-urlencode "reference=${link}" \
		--output "${output}" \
		"${BASE_URL}/ocs/v2.php/references/resolve"
}

cd "${REPOSITORY_ROOT}"
"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 0 >/dev/null 2>&1 || true
# The browser is needed only at the end: its image loads while Nextcloud sets itself up.
# run-all.sh loads it once for all versions and sets E2E_SKIP_PLAYWRIGHT_PULL=1.
if [[ "${E2E_SKIP_PLAYWRIGHT_PULL:-0}" != "1" ]]; then
	"${DOCKER_BIN}" pull --quiet "${PLAYWRIGHT_IMAGE}" >/dev/null 2>&1 &
	PLAYWRIGHT_PULL=$!
fi
phase "Start Nextcloud and the mock of Paperless"
"${COMPOSE[@]}" up --detach --wait --wait-timeout 240 --quiet-pull

phase "Set up Nextcloud and the app"
# Each call of occ starts PHP and Nextcloud anew, which takes a few seconds while
# run-all.sh tests three versions at once. So occ does only what needs it; the access
# to local servers comes from compose.yaml, and the rest from the web server, which
# runs anyway.
if ! curl --silent "${BASE_URL}/status.php" | grep --fixed-strings '"installed":true' >/dev/null; then
	occ maintenance:install \
		--database=sqlite \
		--admin-user=e2e-admin \
		--admin-pass="${PASSWORD}" >/dev/null
fi
# Nextcloud always trusts 127.0.0.1, where the tests reach it. The browser of the
# accessibility check reaches it by its name in the network of the project.
occ config:system:set trusted_domains 1 --value=nextcloud >/dev/null
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

# The password policy would check every new password against the public list of
# Have I Been Pwned, and no test may depend on a service outside the runner. The
# first-run wizard would cover the pages that the accessibility check opens. The web
# server turns both off itself: after occ, it would go on seeing them for up to three
# seconds, as long as it caches the settings of the apps, and check the passwords of
# the users below.
for app in password_policy firstrunwizard; do
	expect_status 200 "Turning off ${app}" \
		--user "e2e-admin:${PASSWORD}" \
		--request DELETE \
		"${BASE_URL}/ocs/v2.php/cloud/apps/${app}"
done
for user in e2e-user e2e-other; do
	expect_status 200 "Creating the user ${user}" \
		--user "e2e-admin:${PASSWORD}" \
		--data-urlencode "userid=${user}" \
		--data-urlencode "password=${PASSWORD}" \
		"${BASE_URL}/ocs/v2.php/cloud/users"
done
phase "Restart Nextcloud"
"${COMPOSE[@]}" restart nextcloud >/dev/null
"${COMPOSE[@]}" up --detach --wait --wait-timeout 120 >/dev/null

phase "Check the settings, the routes and the search"
# The administration settings render the form with its save and reset routes (#25).
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--output "${TMP_DIR}/admin-settings.html" \
	"${BASE_URL}/index.php/settings/admin/paperless_unified_search"
for attribute in data-save-url data-reset-url; do
	if ! grep --fixed-strings "${attribute}=\"/apps/paperless_unified_search/settings\"" \
		"${TMP_DIR}/admin-settings.html" >/dev/null; then
		echo "The administration settings render no ${attribute}." >&2
		exit 1
	fi
done

# The navigation of the administration settings lists the app's own section on
# every page (#25): Nextcloud 33 as links, Nextcloud 34 in the initial state
# settings-sections.
curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--output "${TMP_DIR}/admin-overview.html" \
	"${BASE_URL}/index.php/settings/admin/overview"
SECTIONS="$(grep --only-matching 'id="initial-state-settings-sections" value="[^"]*"' \
	"${TMP_DIR}/admin-overview.html" | sed 's/.*value="//; s/"$//' | base64 --decode || true)"
if ! grep --fixed-strings '/settings/admin/paperless_unified_search"' "${TMP_DIR}/admin-overview.html" >/dev/null &&
	! grep --fixed-strings '"id":"paperless_unified_search"' <<<"${SECTIONS}" >/dev/null; then
	echo "The navigation of the administration settings lists no Paperless Unified Search section." >&2
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
	--data-urlencode 'archiveOwner=e2e-user' \
	--output "${TMP_DIR}/settings.json" \
	"${BASE_URL}/apps/paperless_unified_search/settings"

if grep --fixed-strings 'e2e-only-token' "${TMP_DIR}/settings.json" >/dev/null; then
	echo "Settings response exposed the Paperless API token." >&2
	exit 1
fi

# Only administrators may change the settings (#99). Nextcloud answers a user who isn't
# one with 403 and a request without a login with 401, and the settings stay as they are.
SETTINGS_URL="${BASE_URL}/apps/paperless_unified_search/settings"
for login in e2e-user anonymous; do
	credentials=()
	expected=401
	if [[ "${login}" == e2e-user ]]; then
		credentials=(--user "e2e-user:${PASSWORD}")
		expected=403
	fi
	expect_status "${expected}" "Saving the settings as ${login}" "${credentials[@]}" \
		--request POST \
		--data-urlencode 'url=http://other-host:8080' \
		--data-urlencode 'token=' \
		"${SETTINGS_URL}"
	expect_status "${expected}" "Disconnect as ${login}" "${credentials[@]}" \
		--request DELETE \
		"${SETTINGS_URL}"
	expect_status "${expected}" "Clear history as ${login}" "${credentials[@]}" \
		--request DELETE \
		"${SETTINGS_URL}/diagnostics"
done
if [[ "$(occ config:app:get paperless_unified_search paperless_url)" != 'http://paperless-mock:8080' ]]; then
	echo "A request of a user who isn't an administrator changed the settings." >&2
	exit 1
fi

# A blank token keeps the stored one only for the server it was saved for (#99). The
# mock answers as other-host too, so a test of the connection there would reach it: the
# refused save sends it nothing, while a save with a token does, and so does the one
# that returns to the mock's own name.
requests="$(mock_requests)"
expect_status 400 'Saving another server without a token' \
	--user "e2e-admin:${PASSWORD}" \
	--request POST \
	--data-urlencode 'url=http://other-host:8080' \
	--data-urlencode 'token=' \
	--data-urlencode 'archiveOwner=e2e-user' \
	"${SETTINGS_URL}"
if ! grep --fixed-strings 'Enter the API token again' "${TMP_DIR}/answer.json" >/dev/null; then
	echo "The refused save of another server doesn't ask for the token: $(cat "${TMP_DIR}/answer.json")" >&2
	exit 1
fi
for url in http://other-host:8080 http://paperless-mock:8080; do
	expect_status 200 "Saving ${url} with a token" \
		--user "e2e-admin:${PASSWORD}" \
		--request POST \
		--data-urlencode "url=${url}" \
		--data-urlencode 'token=e2e-only-token' \
		--data-urlencode 'archiveOwner=e2e-user' \
		"${SETTINGS_URL}"
done
received=$(($(mock_requests) - requests))
if [[ "${received}" -ne 2 ]]; then
	echo "The mock received ${received} request(s) instead of the 2 of the saves with a token." >&2
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

# The smart picker offers Paperless documents with an icon that Nextcloud serves, and
# searches through the search provider (#110).
curl --fail-with-body --silent --show-error \
	--user "e2e-user:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--output "${TMP_DIR}/picker.json" \
	"${BASE_URL}/ocs/v2.php/references/providers"
ICON="$(assert_response picker "${TMP_DIR}/picker.json")"
curl --fail --silent --show-error --output /dev/null "${BASE_URL}${ICON}"

search e2e-user 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/browser.json"
assert_response browser "${TMP_DIR}/browser.json"
# The link that the smart picker inserts for this result, which Nextcloud renders as the
# preview of the file for whoever can open it, and as a bare link for anyone else.
LINK="$(assert_response link "${TMP_DIR}/browser.json")"
resolve e2e-user "${LINK}" "${TMP_DIR}/link-owner.json"
assert_response file-link "${TMP_DIR}/link-owner.json"

search e2e-user 'Mozilla/5.0 (iOS) Nextcloud-iOS/7.1.0' "${TMP_DIR}/ios.json"
assert_response ios "${TMP_DIR}/ios.json"

search e2e-user 'Mozilla/5.0 (Android) Nextcloud-android/20260390' "${TMP_DIR}/android.json"
assert_response android "${TMP_DIR}/android.json"

search e2e-other 'Mozilla/5.0 (Android) Nextcloud-android/20260390' "${TMP_DIR}/inaccessible.json"
assert_response no-results "${TMP_DIR}/inaccessible.json"
resolve e2e-other "${LINK}" "${TMP_DIR}/link-inaccessible.json"
assert_response no-file-link "${TMP_DIR}/link-inaccessible.json"

# A file whose name only carries the marker, here in the folder of e2e-other, stands for
# no document: only the files of the archive account e2e-user count.
printf '%s\n' 'Not the archived document.' \
	| curl --fail-with-body --silent --show-error \
		--user "e2e-other:${PASSWORD}" \
		--upload-file - \
		"${BASE_URL}/remote.php/dav/files/e2e-other/Copy%20%5BP123%5D.txt" >/dev/null
search e2e-other 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/forged.json"
assert_response no-results "${TMP_DIR}/forged.json"

# Shared by the archive account, its file shows the document to e2e-other as well.
curl --fail-with-body --silent --show-error \
	--user "e2e-user:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'path=/Documents/Mobile viewer test [P123].pdf' \
	--data-urlencode 'shareType=0' \
	--data-urlencode 'shareWith=e2e-other' \
	--data-urlencode 'permissions=1' \
	--output "${TMP_DIR}/share.json" \
	"${BASE_URL}/ocs/v2.php/apps/files_sharing/api/v1/shares"
search e2e-other 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/shared.json"
assert_response shared "${TMP_DIR}/shared.json"
resolve e2e-other "${LINK}" "${TMP_DIR}/link-shared.json"
assert_response file-link "${TMP_DIR}/link-shared.json"

# Paperless ranks 40 documents for "pagetest", more than one page of 25 of the requests
# of the app. The archive account shares the files of seven of them with e2e-other, in
# one folder, none of the first 19 among them, and keeps the file of document 610 to
# itself. A page of 5 results takes them from both pages of Paperless, and the next page
# goes on with the cursor of the first, without repeating or leaving out a document.
PAGES="${BASE_URL}/remote.php/dav/files/e2e-user/Pages"
expect_status 201 'Creating the folder Pages' --user "e2e-user:${PASSWORD}" --request MKCOL "${PAGES}"
for document in 620 624 626 627 630 635 640; do
	printf 'Synthetic page test document %s.\n' "${document}" \
		| curl --fail-with-body --silent --show-error \
			--user "e2e-user:${PASSWORD}" \
			--upload-file - \
			"${PAGES}/Page%20test%20%5BP${document}%5D.pdf" >/dev/null
done
printf '%s\n' 'Synthetic page test document 610.' \
	| curl --fail-with-body --silent --show-error \
		--user "e2e-user:${PASSWORD}" \
		--upload-file - \
		"${BASE_URL}/remote.php/dav/files/e2e-user/Documents/Page%20test%20%5BP610%5D.pdf" >/dev/null
expect_status 200 'Sharing the folder Pages with e2e-other' \
	--user "e2e-user:${PASSWORD}" \
	--request POST \
	--data-urlencode 'path=/Pages' \
	--data-urlencode 'shareType=0' \
	--data-urlencode 'shareWith=e2e-other' \
	--data-urlencode 'permissions=1' \
	"${BASE_URL}/ocs/v2.php/apps/files_sharing/api/v1/shares"
search e2e-other 'Mozilla/5.0 (Android) Nextcloud-android/20260390' "${TMP_DIR}/page-1.json" pagetest
cursor="$(assert_response page "${TMP_DIR}/page-1.json" more 620 624 626 627 630)"
search e2e-other 'Mozilla/5.0 (Android) Nextcloud-android/20260390' "${TMP_DIR}/page-2.json" pagetest "${cursor}"
assert_response page "${TMP_DIR}/page-2.json" last 635 640 >/dev/null

curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'url=http://paperless-mock:8080' \
	--data-urlencode 'token=' \
	--data-urlencode 'alwaysSearch=1' \
	--data-urlencode 'archiveOwner=E2E-User' \
	--output "${TMP_DIR}/settings-trusted.json" \
	"${BASE_URL}/apps/paperless_unified_search/settings"
# Nextcloud finds an account whatever the case of its ID; the settings store the ID as
# Nextcloud spells it, which the owners of the files carry (#99).
if [[ "$(occ config:app:get paperless_unified_search archive_owner)" != 'e2e-user' ]]; then
	echo "The archive account E2E-User wasn't stored as e2e-user." >&2
	exit 1
fi

curl --fail-with-body --silent --show-error \
	--user "e2e-user:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--output "${TMP_DIR}/providers-trusted.json" \
	"${BASE_URL}/ocs/v2.php/search/providers"
assert_response trusted "${TMP_DIR}/providers-trusted.json"

phase "Check the settings page and its accessibility in Chromium"
# The administration settings meet WCAG 2.1 AA, also with the warning about an archive
# account that doesn't exist, with the token field required for another server and
# with the message after saving. The browser corrects the archive account and saves
# another server and then the mock again, each with the token (#99).
occ config:app:set paperless_unified_search archive_owner --value=e2e-gone >/dev/null
accessibility
for setting in 'paperless_url=http://paperless-mock:8080' 'archive_owner=e2e-user'; do
	if [[ "$(occ config:app:get paperless_unified_search "${setting%%=*}")" != "${setting#*=}" ]]; then
		echo "The settings page in the browser didn't store ${setting}." >&2
		exit 1
	fi
done

phase "Check the log of Nextcloud"
"${COMPOSE[@]}" exec -T nextcloud sh -c 'test ! -f /var/www/html/data/nextcloud.log || cat /var/www/html/data/nextcloud.log' \
	| "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_log.py

echo "Docker E2E passed in ${SECONDS} s: settings for administrators only, the token only for its server, access filtering by the archive account and its shares, the smart picker and the links it inserts, trusted mode, browser, iOS, and Android contracts, pages of results filled across pages of Paperless, and the settings page in the browser with its accessibility."
