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
PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright:v1.64.0-noble@sha256:06a9939e57531807f8d5fd76ce44b53165ffb7d7501d87ab10e285c20b1e971f
PLAYWRIGHT_PULL=""
# The sizes of the search dialog in flow.mjs, every one by default.
FLOW_SIZES="${E2E_FLOW_SIZES:-laptop phone}"

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

# Checks the answer of the API of the dashboard in the file $2 in the mode $1 of
# assert_dashboard.py, with the rest as the arguments of the mode.
assert_dashboard() {
	mode="$1"
	file="$2"
	shift 2
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_dashboard.py "${mode}" "$@" < "${file}"
}

# Checks what a command of occ wrote as JSON into the file $2, in the mode $1 of
# assert_occ.py, with the rest as the arguments of the mode.
assert_occ() {
	mode="$1"
	file="$2"
	shift 2
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_occ.py "${mode}" "$@" < "${file}"
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
# nothing in the checkout. E2E_FLOW_SIZES picks the sizes of flow.mjs, such as
# "laptop", which run-all.sh sets for all but the newest version in a pull request.
browser_checks() {
	# The image has been loading since the start; a failed pull leaves it to docker run.
	if [[ -n "${PLAYWRIGHT_PULL}" ]]; then
		wait "${PLAYWRIGHT_PULL}" || true
		PLAYWRIGHT_PULL=""
	fi
	tar -C "${SCRIPT_DIR}" -cf - package.json package-lock.json ui.mjs sizes.mjs accessibility.mjs flow.mjs settings.mjs \
		| "${DOCKER_BIN}" run --rm --interactive \
			--network "${PROJECT_NAME}_default" \
			--env NPM_CONFIG_UPDATE_NOTIFIER=false \
			--env E2E_USER=e2e-admin \
			--env E2E_SEARCH_USER=e2e-user \
			--env "E2E_PASSWORD=${PASSWORD}" \
			--env "E2E_FLOW_SIZES=${FLOW_SIZES}" \
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

# The requests that the mock of Paperless has answered so far, one line each, from the
# journal that mock_server.py writes before each answer: it holds the request of an
# answer that Nextcloud just got, which the log of Docker may still lack (#94).
mock_journal() {
	"${COMPOSE[@]}" exec -T paperless-mock sh -c 'cat /tmp/requests.log'
}

# The number of requests for documents that have reached the mock of Paperless so far.
mock_requests() {
	mock_journal | grep --count --fixed-strings 'GET /api/documents/' || true
}

# Searches as the user $1 with the user agent $2 for the term $4, mobiletest by default,
# from the cursor $5, 0 by default, 5 results as Nextcloud's web search asks for them.
# Any further argument is a parameter of the search, such as since=2026-08-01T00:00:00Z
# of the date filter. The answer lands in the file $3.
search() {
	user="$1"
	user_agent="$2"
	output="$3"
	term="${4:-mobiletest}"
	cursor="${5:-0}"
	parameters=()
	for parameter in "${@:6}"; do
		parameters+=(--data-urlencode "${parameter}")
	done
	curl --fail-with-body --silent --show-error \
		--user "${user}:${PASSWORD}" \
		--user-agent "${user_agent}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--get \
		--data-urlencode "term=${term}" \
		--data-urlencode 'limit=5' \
		--data-urlencode "cursor=${cursor}" \
		"${parameters[@]}" \
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

# Asks the API of Nextcloud's dashboard as the user $1 for the widgets that it offers,
# for $2 widgets, as the apps for phones do, or for the items of the widget of the app,
# for $2 items, as its web page does. The answer lands in the file $3.
dashboard() {
	user="$1"
	output="$3"
	case "$2" in
		widgets) path='api/v1/widgets' ;;
		items) path='api/v2/widget-items?widgets%5B%5D=paperless_unified_search-recent' ;;
	esac
	curl --fail-with-body --silent --show-error \
		--user "${user}:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--output "${output}" \
		"${BASE_URL}/ocs/v2.php/apps/dashboard/${path}"
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
# Every update of the app runs its repair step, and so does maintenance:repair, which
# removes the last failed search that version 0.3.0 kept and the history replaced.
# 0.3.0 stored both keys as lazy values, which a plain read of the app config skips.
occ config:app:set paperless_unified_search last_failure --value='{"time":1791500000}' --type=array --lazy >/dev/null
occ config:app:set paperless_unified_search last_recovery --value=1791500060 --type=integer --lazy >/dev/null
occ maintenance:repair >"${TMP_DIR}/repair.log"
if ! grep --fixed-strings 'Remove the last failed search that Paperless Unified Search 0.3.0 kept' \
	"${TMP_DIR}/repair.log" >/dev/null; then
	echo "occ maintenance:repair didn't run the repair step of the app." >&2
	exit 1
fi
for key in last_failure last_recovery; do
	if occ config:app:get paperless_unified_search "${key}" >/dev/null; then
		echo "The repair step left ${key} of version 0.3.0." >&2
		exit 1
	fi
done

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
for user in e2e-user e2e-other e2e-writer; do
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

# Until the app is set up, the dashboard doesn't offer its widget (#167).
dashboard e2e-user widgets "${TMP_DIR}/widgets-unconfigured.json"
assert_dashboard unlisted "${TMP_DIR}/widgets-unconfigured.json"

# occ sets the app up as the settings page does, for Ansible, Docker and other scripts,
# with the token from the standard input instead of the command line (#136). It saves
# the settings once the mock answers, and says so without the token; the test command
# then tests them and exits with 0.
printf '%s\n' 'e2e-only-token' \
	| occ paperless_unified_search:setup --url=http://paperless-mock:8080 \
		--archive-account=e2e-user --allow-local --no-always-search --token-stdin \
		>"${TMP_DIR}/setup.txt"
expect_text 'Paperless 3.3.0 (API 10) answers at http://paperless-mock:8080. The settings are saved.' \
	"${TMP_DIR}/setup.txt" "occ didn't set the app up: $(cat "${TMP_DIR}/setup.txt")"
occ paperless_unified_search:test >"${TMP_DIR}/test.txt"
if grep --fixed-strings 'e2e-only-token' "${TMP_DIR}/setup.txt" "${TMP_DIR}/test.txt" >/dev/null; then
	echo "occ showed the Paperless API token." >&2
	exit 1
fi

# Without an archive account of its own, the app takes the one of Paperless Sync, which
# stores it as a lazy value since its version 0.3.0 (#166). Then back to e2e-user, which
# the tests below expect, without Paperless Sync.
occ config:app:set paperless_sync target_user --value=e2e-user --lazy >/dev/null
occ paperless_unified_search:setup --url=http://paperless-mock:8080 --archive-account= \
	>"${TMP_DIR}/setup-sync.txt"
expect_text 'Archive account: e2e-user, the account of Paperless Sync' "${TMP_DIR}/setup-sync.txt" \
	"occ didn't take the archive account of Paperless Sync: $(cat "${TMP_DIR}/setup-sync.txt")"
occ paperless_unified_search:setup --url=http://paperless-mock:8080 --archive-account=e2e-user >/dev/null
occ config:app:delete paperless_sync target_user >/dev/null

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
	expect_status "${expected}" "Try a search as ${login}" "${credentials[@]}" \
		--request POST \
		--data-urlencode 'term=mobiletest' \
		--data-urlencode 'user=e2e-admin' \
		"${SETTINGS_URL}/try"
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

# A user may include Paperless in every search of their own, in the personal settings,
# so that Nextcloud no longer counts it among the connected services for that user
# alone: e2e-user then finds it without the switch, e2e-admin still needs it, and
# nobody does while the administrators don't let users choose it (#220). The route
# takes only a user who signed in.
providers_of() {
	curl --fail-with-body --silent --show-error \
		--user "$1:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--output "$2" \
		"${BASE_URL}/ocs/v2.php/search/providers"
}
choose_always_search() {
	expect_status "$1" "$2" \
		--user "e2e-user:${PASSWORD}" \
		--request POST \
		--data-urlencode "alwaysSearch=$3" \
		"${PERSONAL_URL}"
}
let_users_always_search() {
	expect_status 200 "$1" \
		--user "e2e-admin:${PASSWORD}" \
		--request POST \
		--data-urlencode 'url=http://paperless-mock:8080' \
		--data-urlencode 'allowLocal=1' \
		--data-urlencode 'token=' \
		--data-urlencode 'alwaysSearch=0' \
		--data-urlencode "personalAlwaysSearch=$2" \
		--data-urlencode 'archiveOwner=e2e-user' \
		"${SETTINGS_URL}"
}
PERSONAL_URL="${BASE_URL}/apps/paperless_unified_search/personal"
expect_status 401 'Choosing to always search Paperless without a login' \
	--request POST \
	--data-urlencode 'alwaysSearch=1' \
	"${PERSONAL_URL}"
choose_always_search 200 'Choosing to always search Paperless' 1
expect_text '{"alwaysSearch":true}' "${TMP_DIR}/answer.json" \
	"The choice to always search Paperless isn't stored: $(cat "${TMP_DIR}/answer.json")"
if [[ "$(occ user:setting e2e-user paperless_unified_search always_search)" != 1 ]]; then
	echo "The choice of e2e-user isn't its user preference always_search." >&2
	exit 1
fi
providers_of e2e-user "${TMP_DIR}/providers-personal.json"
assert_response trusted "${TMP_DIR}/providers-personal.json"
providers_of e2e-admin "${TMP_DIR}/providers-personal-admin.json"
assert_response external "${TMP_DIR}/providers-personal-admin.json"
let_users_always_search 'Not letting users always search Paperless' 0
expect_text '"personalAlwaysSearch":false' "${TMP_DIR}/answer.json" \
	"The settings don't say that users may not always search Paperless: $(cat "${TMP_DIR}/answer.json")"
providers_of e2e-user "${TMP_DIR}/providers-personal-off.json"
assert_response external "${TMP_DIR}/providers-personal-off.json"
choose_always_search 403 'Choosing to always search Paperless while users may not' 0
expect_text '"code":"not_allowed"' "${TMP_DIR}/answer.json" \
	"The refused choice has no code: $(cat "${TMP_DIR}/answer.json")"
let_users_always_search 'Letting users always search Paperless again' 1
providers_of e2e-user "${TMP_DIR}/providers-personal-again.json"
assert_response trusted "${TMP_DIR}/providers-personal-again.json"
choose_always_search 200 'Taking back the choice to always search Paperless' 0
providers_of e2e-user "${TMP_DIR}/providers-personal-back.json"
assert_response external "${TMP_DIR}/providers-personal-back.json"

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

# The dashboard lists the documents that Paperless added last, newest first, which the
# user can open (#167): the mock added 999 after 123, but no file stands for it, and the
# documents of the demo are no files of the archive account here. e2e-other, whom the
# archive account shares no file with yet, sees a widget that says so, with a link to
# the files shared with it, which the archive account doesn't get. The newest documents
# are the same for every user: the dashboards of a minute cost Paperless one request.
requests="$(mock_requests)"
dashboard e2e-user widgets "${TMP_DIR}/widgets-owner.json"
assert_dashboard listed "${TMP_DIR}/widgets-owner.json" owner
dashboard e2e-user items "${TMP_DIR}/widget-owner.json"
assert_dashboard items "${TMP_DIR}/widget-owner.json" 'Mobile viewer test'
dashboard e2e-other widgets "${TMP_DIR}/widgets-reader.json"
SHARED_WITH_YOU="$(assert_dashboard listed "${TMP_DIR}/widgets-reader.json" reader)"
expect_status 200 'The files shared with e2e-other' --user "e2e-other:${PASSWORD}" "${SHARED_WITH_YOU}"
dashboard e2e-other items "${TMP_DIR}/widget-unshared.json"
assert_dashboard empty "${TMP_DIR}/widget-unshared.json" 'None of the newest Paperless documents has a file that you can open.'
dashboard e2e-user items "${TMP_DIR}/widget-owner-again.json"
assert_dashboard items "${TMP_DIR}/widget-owner-again.json" 'Mobile viewer test'
if (($(mock_requests) - requests != 1)); then
	echo "The dashboards of a minute asked Paperless $(($(mock_requests) - requests)) times for its newest documents instead of once." >&2
	exit 1
fi
# occ names the reason of each document that the search leaves out (#136): the archive
# account hasn't shared the file of 123 with e2e-other, and no file stands for 999.
occ paperless_unified_search:search e2e-other mobiletest --explain --json >"${TMP_DIR}/explain-unshared.json"
assert_occ verdicts "${TMP_DIR}/explain-unshared.json" '123=not_shared=/Documents/Mobile viewer test [P123].pdf' 999=no_file
# Try a search of the settings page gives administrators the same answer without a
# shell, with the IDs and titles alone, and refuses an account that doesn't exist.
try_search() {
	expect_status "$1" "Trying a search as $2" \
		--user "e2e-admin:${PASSWORD}" \
		--request POST \
		--data-urlencode 'term=mobiletest' \
		--data-urlencode "user=$2" \
		"${SETTINGS_URL}/try"
}
try_search 200 e2e-other
assert_occ try "${TMP_DIR}/answer.json" e2e-other 123=not_shared 999=no_file
try_search 400 nobody
expect_text '"code":"unknown_user"' "${TMP_DIR}/answer.json" \
	"Try a search accepted an account that doesn't exist: $(cat "${TMP_DIR}/answer.json")"
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
# The dashboard of e2e-other shows it at once: the cache keeps only the newest documents
# of Paperless, not what a user may open.
dashboard e2e-other items "${TMP_DIR}/widget-shared.json"
assert_dashboard items "${TMP_DIR}/widget-shared.json" 'Mobile viewer test'
occ paperless_unified_search:search e2e-other mobiletest --explain --json >"${TMP_DIR}/explain-shared.json"
assert_occ verdicts "${TMP_DIR}/explain-shared.json" '123=shown=/Mobile viewer test [P123].pdf' 999=no_file
try_search 200 e2e-other
assert_occ try "${TMP_DIR}/answer.json" e2e-other 123=shown 999=no_file
# The check of occ compares the documents of Paperless with the files of the archive
# account: the mock lists 123, 999 and the five documents of the demo, and only 123 has
# a file.
occ paperless_unified_search:check >"${TMP_DIR}/check.txt"
expect_text 'Coverage: 1 of 7 Paperless document(s) have a file of the archive account. Without one: 999, 412, 389, 371, 402, 356.' \
	"${TMP_DIR}/check.txt" "occ checked the coverage wrongly: $(cat "${TMP_DIR}/check.txt")"
resolve e2e-other "${LINK}" "${TMP_DIR}/link-shared.json"
assert_response file-link "${TMP_DIR}/link-shared.json"

# Whoever may write into a folder of the archive account can give a file of that account
# the marker of any document (#222). The archive account shares its folder Base with
# e2e-writer read-only and the folder Inbox in it with write permission, as Paperless Sync
# lays out its base folder, and e2e-writer names a file in Inbox after document 999. It
# reaches that one file through both shares: the search leaves it out for e2e-writer
# through either, occ and Try a search name the share with write permission, e2e-other
# still finds 123 in its share that allows only reading, and the check of occ and the
# overview warn about the share until it is gone.
BASE="${BASE_URL}/remote.php/dav/files/e2e-user/Base"
INBOX="${BASE}/Inbox"
expect_status 201 'Creating the folder Base' --user "e2e-user:${PASSWORD}" --request MKCOL "${BASE}"
expect_status 201 'Creating the folder Base/Inbox' --user "e2e-user:${PASSWORD}" --request MKCOL "${INBOX}"
share_id() {
	"${COMPOSE[@]}" exec -T paperless-mock python -c 'import json, sys; print(json.load(sys.stdin)["ocs"]["data"]["id"])' <"${TMP_DIR}/answer.json"
}
expect_status 200 'Sharing the folder Base with e2e-writer read-only' 	--user "e2e-user:${PASSWORD}" 	--request POST 	--data-urlencode 'path=/Base' 	--data-urlencode 'shareType=0' 	--data-urlencode 'shareWith=e2e-writer' 	--data-urlencode 'permissions=1' 	"${BASE_URL}/ocs/v2.php/apps/files_sharing/api/v1/shares"
BASE_SHARE="$(share_id)"
expect_status 200 'Sharing the folder Base/Inbox with e2e-writer with write permission' 	--user "e2e-user:${PASSWORD}" 	--request POST 	--data-urlencode 'path=/Base/Inbox' 	--data-urlencode 'shareType=0' 	--data-urlencode 'shareWith=e2e-writer' 	--data-urlencode 'permissions=15' 	"${BASE_URL}/ocs/v2.php/apps/files_sharing/api/v1/shares"
WRITER_SHARE="$(share_id)"
printf '%s
' 'Not document 999.' 	| curl --fail-with-body --silent --show-error 		--user "e2e-writer:${PASSWORD}" 		--upload-file - 		"${BASE_URL}/remote.php/dav/files/e2e-writer/Inbox/Forged%20%5BP999%5D.txt" >/dev/null
search e2e-writer 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/writable.json"
assert_response no-results "${TMP_DIR}/writable.json"
occ paperless_unified_search:search e2e-writer mobiletest --explain --json >"${TMP_DIR}/explain-writable.json"
assert_occ verdicts "${TMP_DIR}/explain-writable.json" '123=not_shared=/Documents/Mobile viewer test [P123].pdf' '999=writable'
try_search 200 e2e-writer
assert_occ try "${TMP_DIR}/answer.json" e2e-writer 123=not_shared 999=writable
search e2e-other 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/still-shared.json"
assert_response shared "${TMP_DIR}/still-shared.json"
occ paperless_unified_search:check >"${TMP_DIR}/check-writable.txt"
assert_occ shares "${TMP_DIR}/check-writable.txt" '! Shared: 2 users.' 	'/Base/Inbox is shared with the user e2e-writer with write permission, so the search shows e2e-writer none of the documents in it. Share it read-only.'
occ setupchecks --output=json >"${TMP_DIR}/setupchecks-writable.json" || true
assert_occ setupcheck "${TMP_DIR}/setupchecks-writable.json" warning 'The archive account e2e-user shares /Base/Inbox with write permission'
for share in "${WRITER_SHARE}" "${BASE_SHARE}"; do
	expect_status 200 "Removing the share ${share} with e2e-writer" 		--user "e2e-user:${PASSWORD}" 		--request DELETE 		"${BASE_URL}/ocs/v2.php/apps/files_sharing/api/v1/shares/${share}"
done
expect_status 204 'Deleting the forged file' --user "e2e-user:${PASSWORD}" --request DELETE "${INBOX}/Forged%20%5BP999%5D.txt"
occ paperless_unified_search:check >"${TMP_DIR}/check-read-only.txt"
assert_occ shares "${TMP_DIR}/check-read-only.txt" '✓ Shared read-only: 1 user.'
occ setupchecks --output=json >"${TMP_DIR}/setupchecks-read-only.json" || true
assert_occ setupcheck "${TMP_DIR}/setupchecks-read-only.json" success 'with the archive account e2e-user. Shared read-only: 1 user.'

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

# The date filter of Nextcloud's search narrows the documents by their date in Paperless,
# which the mock filters as Paperless does (#153). The web search sends each bound of the
# span as a moment in UTC, whose day in the timezone of the user is the day that the user
# picked: in Berlin, 22:00 UTC on August 26 is already August 27, after the document of
# August 26, and 22:30 UTC on August 25 is August 26, its day. In UTC, each of the two
# searches would get the answer of the other.
occ user:setting e2e-user core timezone Europe/Berlin
search e2e-user 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/since.json" mobiletest 0 'since=2026-08-26T22:00:00Z'
assert_response no-results "${TMP_DIR}/since.json"
search e2e-user 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Safari/605.1.15' "${TMP_DIR}/until.json" mobiletest 0 'since=2026-08-01T00:00:00Z' 'until=2026-08-25T22:30:00Z'
assert_response browser "${TMP_DIR}/until.json"
occ user:setting e2e-user core timezone --delete
# Searched in a variable, not in a pipe: grep --quiet stops at the first match, and the
# command that writes into the pipe would fail the pipe with SIGPIPE (#94).
journal="$(mock_journal)"
if ! grep --quiet --fixed-strings 'created__date__gte=2026-08-01&created__date__lte=2026-08-26' <<<"${journal}"; then
	echo 'The date filter of the search reached Paperless without created__date__gte=2026-08-01 and created__date__lte=2026-08-26.' >&2
	exit 1
fi

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
# The overview of the administration settings warns that the search shows no Paperless
# documents, with a link to the settings of the app (#136).
occ setupchecks --output=json >"${TMP_DIR}/setupchecks.json" || true
assert_occ setupcheck "${TMP_DIR}/setupchecks.json" warning 'The archive account e2e-gone does not exist'
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

echo "Docker E2E passed in ${SECONDS} s: the set-up, the repair step that removes the last failure of version 0.3.0, the test, the explained search and the check of occ, the warning of the overview, settings for administrators only, the token only for its server, the local network only with the switch of the app, the causes of a failed connection in German and formal German, access filtering by the archive account and its shares, the smart picker and the links it inserts, the dashboard widget with the newest documents that a user can open at one request to Paperless a minute, trusted mode, the choice of a user to include Paperless in every search of their own, which the administrators can disallow, browser, iOS, and Android contracts, a result with the preview of its file only for who can open it, its date in English and German, its correspondent and its type, pages of results filled across pages of Paperless, the date filter in the timezone of the user, an unfinished query in the simple mode of Paperless 3 and a page past the last one outside the history, a failed and a retried request to Paperless in the history of the problems of the search and its clearing, Try a search for administrators only with the IDs, titles and verdicts alone, the settings page in the browser with its accessibility on a laptop and a phone, its messages and Disconnect, and the search dialog with a result that opens the Viewer on a laptop and a phone."
