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
# The browser of the checks in Chromium. Keep its version equal to playwright-core in
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

# Checks the history of the problems of the search on the page of the administration
# settings in the file $2 in the mode $1 of assert_history.py, with the rest as the
# arguments of the mode.
assert_history() {
	mode="$1"
	file="$2"
	shift 2
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_history.py "${mode}" "$@" < "${file}"
}

# Uploads a PDF of one page with the line $3 as the file $2 of the user $1, a path of
# WebDAV with its special characters encoded.
upload_pdf() {
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/pdf.py "$3" \
		| curl --fail-with-body --silent --show-error \
			--user "$1:${PASSWORD}" \
			--upload-file - \
			"${BASE_URL}/remote.php/dav/files/$1/$2" >/dev/null
}

# The checks in Chromium, inside the network of the Compose project: axe-core checks
# the pages of the app (accessibility.mjs), which also checks what the settings page
# does, flow.mjs goes through the search dialog as a person does, and settings.mjs
# checks the messages of the settings page and Disconnect, last, as it disconnects. The
# files of the checks reach the browser through standard input, so that npm leaves
# nothing in the checkout.
browser_checks() {
	# The image has been loading since the start; a failed pull leaves it to docker run.
	if [[ -n "${PLAYWRIGHT_PULL}" ]]; then
		wait "${PLAYWRIGHT_PULL}" || true
		PLAYWRIGHT_PULL=""
	fi
	tar -C "${SCRIPT_DIR}" -cf - package.json package-lock.json ui.mjs accessibility.mjs flow.mjs settings.mjs \
		| "${DOCKER_BIN}" run --rm --interactive \
			--network "${PROJECT_NAME}_default" \
			--env NPM_CONFIG_UPDATE_NOTIFIER=false \
			--env E2E_USER=e2e-admin \
			--env E2E_SEARCH_USER=e2e-user \
			--env "E2E_PASSWORD=${PASSWORD}" \
			"${PLAYWRIGHT_IMAGE}" \
			sh -c 'mkdir /tmp/browser && cd /tmp/browser && tar -xf - && npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && node accessibility.mjs && node flow.mjs && node settings.mjs'
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

# Sets the language of the user $2, e2e-admin by default, to $1 through the provisioning
# API, which needs no occ, as the menu of the account does.
language() {
	account="${2:-e2e-admin}"
	expect_status 200 "Setting the language of ${account} to $1" \
		--user "e2e-admin:${PASSWORD}" \
		--request PUT \
		--data-urlencode 'key=language' \
		--data-urlencode "value=$1" \
		"${BASE_URL}/ocs/v2.php/cloud/users/${account}"
}

# Fetches the administration settings of the app as e2e-admin into the file $1.
admin_settings() {
	curl --fail-with-body --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--output "$1" \
		"${BASE_URL}/index.php/settings/admin/paperless_unified_search"
}

# Fails with the message $3 unless the file $2 contains the text $1.
expect_text() {
	if ! grep --fixed-strings -- "$1" "$2" >/dev/null; then
		echo "$3" >&2
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
# run-all.sh tests three versions at once. So occ does only what needs it, and the rest
# comes from the web server, which runs anyway. Nextcloud keeps its protection against
# addresses of the local network: the app reaches the mock only through its own switch.
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
# Previews of PDF files, which Nextcloud draws with Imagick once enabledPreviewProviders
# names them, so that a result links the preview of its file. The image has no
# Ghostscript, so Nextcloud answers such a preview with the icon of PDF files. A file in
# config/, which Nextcloud adds to config.php, costs no start of occ.
"${COMPOSE[@]}" exec -T --user www-data nextcloud sh -c 'cat > config/previews.config.php' <<'PHP'
<?php
$CONFIG = ['enabledPreviewProviders' => ['OC\\Preview\\PNG', 'OC\\Preview\\JPEG', 'OC\\Preview\\PDF']];
PHP
phase "Restart Nextcloud"
"${COMPOSE[@]}" restart nextcloud >/dev/null
"${COMPOSE[@]}" up --detach --wait --wait-timeout 120 >/dev/null

phase "Check the settings, the routes and the search"
# The administration settings render the form with its save and reset routes (#25).
admin_settings "${TMP_DIR}/admin-settings.html"
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

SETTINGS_URL="${BASE_URL}/apps/paperless_unified_search/settings"
# Nextcloud refuses addresses of the local network, such as the one of the mock, to
# every request that doesn't allow them, and config.php allows them to none here
# (#117). Without the switch of the app, the test of the connection fails before any
# request leaves Nextcloud, and the answer names the switch; with it, the app reaches
# the mock, for that test and for every search below.
requests="$(mock_requests)"
expect_status 400 'Saving the mock without the switch for the local network' \
	--user "e2e-admin:${PASSWORD}" \
	--request POST \
	--data-urlencode 'url=http://paperless-mock:8080' \
	--data-urlencode 'token=e2e-only-token' \
	--data-urlencode 'allowLocal=0' \
	--data-urlencode 'archiveOwner=e2e-user' \
	"${SETTINGS_URL}"
if ! grep --fixed-strings 'Paperless runs in my local network' "${TMP_DIR}/answer.json" >/dev/null; then
	echo "The refused save of an address of the local network doesn't name the switch: $(cat "${TMP_DIR}/answer.json")" >&2
	exit 1
fi
expect_text '"code":"local_address"' "${TMP_DIR}/answer.json" \
	"The refused save of an address of the local network has the wrong code: $(cat "${TMP_DIR}/answer.json")"
if [[ "$(mock_requests)" != "${requests}" ]]; then
	echo "The save without the switch for the local network reached the mock." >&2
	exit 1
fi

# Without the switch, the DNS pinning of Nextcloud, on by default, resolves the name
# itself and reports a name without a record as a refused local address. The answer
# names the misspelled host instead of the switch, which would lift the protection for
# a URL that can't work anyway (#120).
expect_status 400 'Saving a host that does not resolve, without the switch for the local network' \
	--user "e2e-admin:${PASSWORD}" \
	--request POST \
	--data-urlencode 'url=https://paperless.invalid' \
	--data-urlencode 'token=e2e-only-token' \
	--data-urlencode 'allowLocal=0' \
	--data-urlencode 'archiveOwner=e2e-user' \
	"${SETTINGS_URL}"
expect_text '"code":"unknown_host"' "${TMP_DIR}/answer.json" \
	"A host that does not resolve isn't named as one: $(cat "${TMP_DIR}/answer.json")"

curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'url=http://paperless-mock:8080' \
	--data-urlencode 'allowLocal=1' \
	--data-urlencode 'token=e2e-only-token' \
	--data-urlencode 'alwaysSearch=0' \
	--data-urlencode 'archiveOwner=e2e-user' \
	--output "${TMP_DIR}/settings.json" \
	"${SETTINGS_URL}"

if grep --fixed-strings 'e2e-only-token' "${TMP_DIR}/settings.json" >/dev/null; then
	echo "Settings response exposed the Paperless API token." >&2
	exit 1
fi

# Only administrators may change the settings (#99). Nextcloud answers a user who isn't
# one with 403 and a request without a login with 401, and the settings stay as they are.
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
		--data-urlencode 'allowLocal=1' \
		--data-urlencode 'token=e2e-only-token' \
		--data-urlencode 'archiveOwner=e2e-user' \
		"${SETTINGS_URL}"
done
received=$(($(mock_requests) - requests))
if [[ "${received}" -ne 2 ]]; then
	echo "The mock received ${received} request(s) instead of the 2 of the saves with a token." >&2
	exit 1
fi
# The test of the connection stores the version that the answer of Paperless names, which
# decides whether a query goes to the simple mode of its search (#128).
if ! grep --fixed-strings '"paperlessVersion":"3.3.0","paperlessApiVersion":"10"' "${TMP_DIR}/answer.json" >/dev/null; then
	echo "The save doesn't name the version of Paperless: $(cat "${TMP_DIR}/answer.json")" >&2
	exit 1
fi

# A failed test of the connection names its cause in the language of the administrator,
# with a code that says what failed, and keeps the settings as they are (#120). An
# account set to Deutsch (Förmlich: Sie) has the language de_DE, which the app brings as
# well: without it, Nextcloud would show the app in English there. The refused save asks
# for another archive account and the global search as well, so that a save despite the
# failure would show in the stored settings.
stored_settings() {
	local key
	for key in paperless_url archive_owner always_search; do
		printf '%s=%s\n' "${key}" "$(occ config:app:get paperless_unified_search "${key}")"
	done
}
settings_before="$(stored_settings)"
language de
expect_status 400 'Saving a token that Paperless refuses, in German' \
	--user "e2e-admin:${PASSWORD}" \
	--request POST \
	--data-urlencode 'url=http://paperless-mock:8080' \
	--data-urlencode 'allowLocal=1' \
	--data-urlencode 'token=e2e-wrong-token' \
	--data-urlencode 'alwaysSearch=1' \
	--data-urlencode 'archiveOwner=e2e-admin' \
	"${SETTINGS_URL}"
expect_text 'Paperless hat das API-Token abgelehnt (HTTP 401)' "${TMP_DIR}/answer.json" \
	"The refused token isn't named in German: $(cat "${TMP_DIR}/answer.json")"
expect_text '"code":"unauthorized"' "${TMP_DIR}/answer.json" \
	"The refused token has no code: $(cat "${TMP_DIR}/answer.json")"
admin_settings "${TMP_DIR}/admin-settings-de.html"
expect_text 'Verbindung testen und speichern' "${TMP_DIR}/admin-settings-de.html" \
	'The settings page of an administrator who speaks German is not in German.'
expect_text 'Verwende ein eigenes Paperless-Konto' "${TMP_DIR}/admin-settings-de.html" \
	'The settings page in German does not address the administrator informally.'
language de_DE
admin_settings "${TMP_DIR}/admin-settings-de-formal.html"
expect_text 'Verwenden Sie ein eigenes Paperless-Konto' "${TMP_DIR}/admin-settings-de-formal.html" \
	'The settings page of an administrator set to Deutsch (Förmlich: Sie) is not in formal German.'
language en
settings_after="$(stored_settings)"
if [[ "${settings_after}" != "${settings_before}" ]]; then
	printf 'The refused save changed the settings from\n%s\nto\n%s\n' "${settings_before}" "${settings_after}" >&2
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

# A real PDF, which the Viewer draws when the search dialog opens it.
upload_pdf e2e-user 'Documents/Mobile%20viewer%20test%20%5BP123%5D.pdf' 'Synthetic PDF fixture for Paperless Unified Search E2E.'

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
# The preview of the result: an image for the user, who can open its file, and nothing
# for anyone else.
PREVIEW="$(assert_response preview "${TMP_DIR}/browser.json")"
PREVIEW_TYPE="$(curl --fail --silent --show-error --location \
	--user "e2e-user:${PASSWORD}" \
	--output /dev/null \
	--write-out '%{content_type}' \
	"${PREVIEW}")"
if [[ "${PREVIEW_TYPE}" != image/* ]]; then
	echo "The preview of the result is no image, but ${PREVIEW_TYPE}: ${PREVIEW}" >&2
	exit 1
fi
expect_status 404 "The preview of the result for e2e-other" --user "e2e-other:${PASSWORD}" "${PREVIEW}"
# The date of the result in the language of the user.
language de e2e-user
search e2e-user 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/german.json"
assert_response german "${TMP_DIR}/german.json"
language en e2e-user
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

# Paperless 3 refuses a query of its advanced syntax that is still being typed with
# HTTP 400, and the app finds the documents of its words in the simple mode of the
# search instead; it refuses a page past the last one with HTTP 404, which finds nothing.
# Neither is a problem of the search: the history below counts only the failure and the
# retry that follow them (#128).
search e2e-user 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/typing.json" 'mobiletest created:[2026 to'
assert_response browser "${TMP_DIR}/typing.json"
search e2e-other 'Mozilla/5.0 (Android) Nextcloud-android/20260390' "${TMP_DIR}/past-the-end.json" pagetest 100
assert_response no-results "${TMP_DIR}/past-the-end.json"

# Paperless fails with HTTP 500 for e2e-error, and the connection of the first search
# for e2e-drop breaks off without an answer. The first search shows no document and
# goes into the history of the problems of the search; the second shows its document,
# from the second try of the request, which goes into the history as well and ends
# the failure. The page of the administration settings shows both, without the terms
# and the token, also where the error of the HTTP client repeats the URL (#118).
search e2e-user 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/error.json" e2e-error
assert_response no-results "${TMP_DIR}/error.json"
search e2e-user 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/drop.json" e2e-drop
assert_response browser "${TMP_DIR}/drop.json"
admin_settings "${TMP_DIR}/problems.html"
assert_history recorded "${TMP_DIR}/problems.html" e2e-error e2e-drop e2e-only-token

curl --fail-with-body --silent --show-error \
	--user "e2e-admin:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'url=http://paperless-mock:8080' \
	--data-urlencode 'allowLocal=1' \
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

phase "Check the settings page, its accessibility and the search dialog in Chromium"
# The search dialog turns on the search of connected services, which Paperless is one
# of again without trusted mode.
expect_status 200 'Turning off trusted mode' \
	--user "e2e-admin:${PASSWORD}" \
	--request POST \
	--data-urlencode 'url=http://paperless-mock:8080' \
	--data-urlencode 'allowLocal=1' \
	--data-urlencode 'token=' \
	--data-urlencode 'alwaysSearch=0' \
	--data-urlencode 'archiveOwner=e2e-user' \
	"${SETTINGS_URL}"
# The administration settings meet WCAG 2.1 AA, also with the problems of the search
# above, with the warning about an archive account that doesn't exist, with the token
# field required for another server and with the message after saving. The browser
# corrects the archive account, saves another server and then the mock again, each
# with the token (#99), and clears the history of the problems (#118). Last, it
# disconnects and saves the mock once more (#127).
# The settings route refuses an archive account that doesn't exist, so the provisioning
# API of Nextcloud stores it. occ would store it too, but Nextcloud serves the settings
# of the apps from APCu for three seconds after the save above, which occ doesn't
# clear: the first page in the browser then showed the archive account of that save.
expect_status 200 'Storing an archive account that does not exist' \
	--user "e2e-admin:${PASSWORD}" \
	--request POST \
	--data-urlencode 'value=e2e-gone' \
	"${BASE_URL}/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/paperless_unified_search/archive_owner"
browser_checks
for setting in 'paperless_url=http://paperless-mock:8080' 'archive_owner=e2e-user'; do
	if [[ "$(occ config:app:get paperless_unified_search "${setting%%=*}")" != "${setting#*=}" ]]; then
		echo "The settings page in the browser didn't store ${setting}." >&2
		exit 1
	fi
done
# Nextcloud forgot the problems that the browser cleared.
admin_settings "${TMP_DIR}/cleared.html"
assert_history cleared "${TMP_DIR}/cleared.html"

phase "Check the log of Nextcloud"
"${COMPOSE[@]}" exec -T nextcloud sh -c 'test ! -f /var/www/html/data/nextcloud.log || cat /var/www/html/data/nextcloud.log' \
	| "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_log.py

echo "Docker E2E passed in ${SECONDS} s: settings for administrators only, the token only for its server, the local network only with the switch of the app, the causes of a failed connection in German and formal German, access filtering by the archive account and its shares, the smart picker and the links it inserts, trusted mode, browser, iOS, and Android contracts, a result with the preview of its file only for who can open it, its date in English and German, its correspondent and its type, pages of results filled across pages of Paperless, an unfinished query in the simple mode of Paperless 3 and a page past the last one outside the history, a failed and a retried request to Paperless in the history of the problems of the search and its clearing, the settings page in the browser with its accessibility on a laptop and a phone, its messages and Disconnect, and the search dialog with a result that opens the Viewer on a laptop and a phone."
