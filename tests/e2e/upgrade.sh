#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The upgrade from the last release to the checkout, as an administrator's Nextcloud
# makes it when the App Store brings the next version: a change that takes effect only
# on an update, such as a renamed key of a setting, the storage of the token, a lazy
# value or a new repair step, would otherwise reach every installation untested, and
# its users would find "Paperless is not configured" or an empty search after the
# update. run.sh enables the checkout fresh and never sees it.
#
# 1. It downloads the last release of the repository that appinfo/info.xml names, the
#    asset paperless_unified_search.tar.gz of the App Store, and verifies it: its
#    signature .sig with the certificate of the app at the App Store, as Nextcloud
#    checks an update from there, and, where gh is installed, its signed provenance
#    from the isolated build, build-release.yml. A repository without a release yet
#    skips the test with a notice.
# 2. It packs the checkout as krankerl does for a release (package.sh), with the next
#    patch version when the checkout still names the version of the release, since
#    Nextcloud upgrades an app only to a newer version.
# 3. It installs the release into custom_apps of a fresh Nextcloud, with the mock of
#    Paperless of compose.yaml, sets it up with occ paperless_unified_search:setup,
#    with the archive account, the switch for the local network and the global search
#    on, and searches.
# 4. It replaces the release with the package and runs occ upgrade, which must name the
#    new version and run each repair step once, such as the one that removes the keys
#    of version 0.3.0, which it stores before.
# 5. The settings page shows the same settings, URL, archive account, switches and
#    token, the app keeps every key of its configuration, the same search finds the
#    same file with the stored token, which still goes to its own server alone, and the
#    log of Nextcloud holds no finding of assert_log.py from the upgrade on.
#
#   bash tests/e2e/upgrade.sh
#   NEXTCLOUD_IMAGE=nextcloud:35-apache bash tests/e2e/upgrade.sh
#   E2E_UPGRADE_FROM=v1.0.0 bash tests/e2e/upgrade.sh
#
# run-all.sh runs it beside run.sh, for the versions that E2E_UPGRADE names, with the
# project pus_upgrade_<version> and the port 19000 + <version>. E2E_PORT,
# E2E_PROJECT_NAME, NEXTCLOUD_IMAGE, DOCKER_BIN and KEEP_E2E=1 work as for run.sh.
# selftest.sh tests the steps before Docker with fakes of the release.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
APP_ID="paperless_unified_search"
DOCKER_BIN="${DOCKER_BIN:-docker}"
E2E_PORT="${E2E_PORT:-18182}"
PROJECT_NAME="${E2E_PROJECT_NAME:-paperless_unified_search_upgrade}"
PASSWORD="e2e-only-password"
TOKEN="e2e-only-token"
BASE_URL="http://127.0.0.1:${E2E_PORT}"
# The certificate of the app at the App Store, as build-release.yml signs the release.
CERTIFICATE_URL="https://raw.githubusercontent.com/nextcloud/app-certificate-requests/master/${APP_ID}/${APP_ID}.crt"
# The keys that the app may drop on an upgrade: the last failed search of version
# 0.3.0, which its repair step removes. Any other key of the release that the upgrade
# loses fails the test; a change that retires one on purpose, with a repair step, adds
# it here.
RETIRED_KEYS=(last_failure last_recovery)
# The repair step that removes them, by the name that occ upgrade prints.
REPAIR_STEP="Remove the last failed search that Paperless Unified Search 0.3.0 kept"
# The file of document 123 of the mock, which the search for mobiletest finds.
FILE_NAME="Mobile viewer test [P123].pdf"
TMP_DIR="$(mktemp -d)"
COMPOSE=("${DOCKER_BIN}" compose --project-name "${PROJECT_NAME}" --file "${SCRIPT_DIR}/compose.yaml")
started=0

# The checkout leaves the place of the app in custom_apps to the release and the
# package (compose.yaml).
export E2E_PORT E2E_APP_PATH=/opt/checkout
# Git Bash on Windows: keep the paths in the containers as they are.
export MSYS2_ARG_CONV_EXCL="/mock/${MSYS2_ARG_CONV_EXCL:+;${MSYS2_ARG_CONV_EXCL}}"
export MSYS2_ENV_CONV_EXCL="E2E_APP_PATH${MSYS2_ENV_CONV_EXCL:+;${MSYS2_ENV_CONV_EXCL}}"

cleanup() {
	status=$?
	trap - EXIT
	if ((started == 1)); then
		if [[ "${status}" -ne 0 ]]; then
			"${COMPOSE[@]}" ps || true
			"${COMPOSE[@]}" logs --no-color --tail 100 || true
		fi
		if [[ "${KEEP_E2E:-0}" == "1" ]]; then
			echo "Upgrade environment kept at ${BASE_URL} (project ${PROJECT_NAME})."
		elif [[ "${RUNNER_ENVIRONMENT:-}" != "github-hosted" ]]; then
			"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 0 >/dev/null 2>&1 || true
		fi
	fi
	rm -rf "${TMP_DIR}"
	exit "${status}"
}
trap cleanup EXIT
# Stopped by the deadline of run-all.sh: the log shows the state of the containers.
trap 'exit 143' TERM

phase() {
	printf '\n== %s s: %s\n' "${SECONDS}" "$1"
}

fail() {
	echo "$1" >&2
	exit 1
}

# A notice in the summary of the run of GitHub Actions, and a line elsewhere.
notice() {
	if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
		echo "::notice title=Upgrade from the last release::$1"
	fi
	echo "$1"
}

occ() {
	"${COMPOSE[@]}" exec -T --user www-data nextcloud php occ "$@"
}

# Runs the check $1 of assert_upgrade.py, with the rest as its arguments, on the file
# from standard input, in the container of the mock, which has Python.
assert_upgrade() {
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_upgrade.py "$@"
}

# Sends a request of the OCS API as e2e-admin, which must answer with HTTP 200; $1 says
# what it does, the rest are its options of curl.
ocs() {
	local what="$1" status
	shift
	status="$(curl --silent --show-error --output "${TMP_DIR}/ocs.json" --write-out '%{http_code}' \
		--user "e2e-admin:${PASSWORD}" --header 'OCS-APIRequest: true' "$@")"
	if [[ "${status}" != 200 ]]; then
		fail "${what} answered HTTP ${status}: $(cat "${TMP_DIR}/ocs.json")"
	fi
}

# The answer of the search of Nextcloud for mobiletest as e2e-user, in the file $1.
search() {
	curl --fail-with-body --silent --show-error \
		--user "e2e-user:${PASSWORD}" \
		--header 'Accept: application/json' \
		--header 'OCS-APIRequest: true' \
		--get \
		--data-urlencode 'term=mobiletest' \
		--data-urlencode 'limit=5' \
		--output "$1" \
		"${BASE_URL}/ocs/v2.php/search/providers/${APP_ID}_documents/search"
}

# The administration settings of the app as e2e-admin, in the file $1.
admin_settings() {
	curl --fail-with-body --silent --show-error \
		--user "e2e-admin:${PASSWORD}" \
		--output "$1" \
		"${BASE_URL}/index.php/settings/admin/${APP_ID}"
}

# The results of the search, which must find the file of document 123, and the
# settings that the page of the administration settings shows (assert_upgrade.py).
search_results() {
	search "${TMP_DIR}/search.json" &&
		assert_upgrade results "/Documents/${FILE_NAME}" <"${TMP_DIR}/search.json"
}
shown_settings() {
	admin_settings "${TMP_DIR}/settings.html" &&
		assert_upgrade settings <"${TMP_DIR}/settings.html"
}

# Apache caches the configuration of the apps for up to 3 seconds (run.sh), so right
# after occ it may still answer as before: without the settings that occ just saved, or
# with the page that asks for the upgrade of the app. Runs the command $2 until it
# passes, for up to 10 seconds, with its output of the last try in the file $1.
until_passes() {
	local output="$1"
	shift
	for _ in {1..10}; do
		if "$@" >"${output}" 2>"${TMP_DIR}/problem.txt"; then
			return 0
		fi
		sleep 1
	done
	cat "${TMP_DIR}/problem.txt" >&2
	return 1
}

# The number of requests from Nextcloud that the mock of Paperless has answered so far,
# from its journal (run.sh), without those of the health check of Docker.
mock_requests() {
	"${COMPOSE[@]}" exec -T paperless-mock sh -c "grep -c -v -F 'GET /health ' /tmp/requests.log || true"
}

# Whether the version $1 is newer than $2, such as 1.2.0 than 1.1.1.
newer() {
	[[ "$1" != "$2" && "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n 1)" == "$1" ]]
}

download() {
	curl --fail --silent --show-error --location --retry 3 --retry-all-errors \
		--connect-timeout 15 --max-time 300 --output "$1" "$2"
}

cd "${REPOSITORY_ROOT}"

phase "Find the release to upgrade from"
repository="$(sed -nE 's#.*<repository[^>]*>https://github\.com/([^<]+)</repository>.*#\1#p' appinfo/info.xml | head -n 1)"
repository="${repository%.git}"
if [[ ! "${repository}" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then
	fail "appinfo/info.xml names no repository on GitHub, as <repository type=\"git\">https://github.com/owner/name.git</repository>."
fi
from="${E2E_UPGRADE_FROM:-}"
if [[ -z "${from}" ]]; then
	# GitHub answers /releases/latest with a redirect to the tag of the newest release
	# that is neither a draft nor a pre-release, without the API and its rate limit.
	latest="$(curl --silent --show-error --retry 3 --retry-all-errors --connect-timeout 15 --max-time 60 \
		--output /dev/null --write-out '%{redirect_url}' "https://github.com/${repository}/releases/latest")"
	if [[ "${latest}" != */releases/tag/* ]]; then
		notice "The upgrade test is skipped: ${repository} has no release yet to upgrade from."
		exit 0
	fi
	from="${latest##*/releases/tag/}"
fi
if [[ ! "${from}" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	fail "${from} is no tag of a release, such as v1.1.1."
fi
release_version="${from#v}"
checkout_version="$(sed -nE 's#.*<version>([^<]+)</version>.*#\1#p' appinfo/info.xml | head -n 1)"
if newer "${checkout_version}" "${release_version}"; then
	package_version="${checkout_version}"
else
	IFS=. read -r major minor patch <<<"${release_version}"
	package_version="${major}.${minor}.$((patch + 1))"
fi
echo "The release to upgrade from: ${from}"
echo "The checkout has version ${checkout_version}; its package upgrades the release to ${package_version}."

phase "Download and verify the release ${from}"
release="${TMP_DIR}/release"
mkdir "${release}"
for asset in "${APP_ID}.tar.gz" "${APP_ID}.tar.gz.sig"; do
	if ! download "${release}/${asset}" "https://github.com/${repository}/releases/download/${from}/${asset}"; then
		fail "The release ${from} of ${repository} has no asset ${asset}, which build-release.yml attaches to each release."
	fi
done
if ! command -v openssl >/dev/null 2>&1; then
	fail "openssl is not installed, which verifies the signature of the release."
fi
download "${release}/app.crt" "${CERTIFICATE_URL}"
subject="$(openssl x509 -in "${release}/app.crt" -noout -subject)"
if [[ ! "${subject}" =~ CN[[:space:]]?=[[:space:]]?${APP_ID}($|,|/) ]]; then
	fail "The certificate at ${CERTIFICATE_URL} is not the one of the app ${APP_ID}: ${subject}"
fi
openssl x509 -in "${release}/app.crt" -noout -pubkey >"${release}/app.pub"
if ! openssl dgst -sha512 -verify "${release}/app.pub" -signature "${release}/${APP_ID}.tar.gz.sig" \
	"${release}/${APP_ID}.tar.gz" >/dev/null; then
	fail "The signature of ${APP_ID}.tar.gz of ${from} doesn't match the certificate of the app at the App Store."
fi
echo "The signature of ${APP_ID}.tar.gz matches the certificate of the app at the App Store."
# The signed provenance of the isolated build, from the bundle of the release, which
# gh verifies without a login; verify-release.yml checks it for each release as well.
if ! command -v gh >/dev/null 2>&1; then
	notice "gh is not installed, so the provenance of ${from} is not verified here; verify-release.yml checks it for each release."
elif ! download "${release}/provenance.sigstore.json" \
	"https://github.com/${repository}/releases/download/${from}/provenance.sigstore.json"; then
	notice "The release ${from} has no provenance.sigstore.json, as releases before the isolated build; only its signature is verified."
elif gh attestation verify "${release}/${APP_ID}.tar.gz" --repo "${repository}" \
	--signer-workflow "${repository}/.github/workflows/build-release.yml" \
	--bundle "${release}/provenance.sigstore.json" >/dev/null; then
	echo "${APP_ID}.tar.gz carries the signed provenance of build-release.yml of ${repository}."
else
	fail "${APP_ID}.tar.gz of ${from} has no valid provenance of build-release.yml of ${repository}."
fi

phase "Pack the checkout as version ${package_version}"
bash "${SCRIPT_DIR}/package.sh" "${TMP_DIR}/package.tar.gz" "${package_version}"

phase "Start Nextcloud and the mock of Paperless"
started=1
"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 0 >/dev/null 2>&1 || true
"${COMPOSE[@]}" up --detach --wait --wait-timeout 240 --quiet-pull

phase "Install Nextcloud and the release ${from}"
if ! curl --silent "${BASE_URL}/status.php" | grep --fixed-strings '"installed":true' >/dev/null; then
	occ maintenance:install --database=sqlite --admin-user=e2e-admin --admin-pass="${PASSWORD}" >/dev/null
fi
# Every record of PHP and of the app, as in run.sh, for assert_log.py at the end.
occ config:system:set log.condition --type=json --value='{"apps":["PHP","paperless_unified_search"]}' >/dev/null
# Into custom_apps, as the App Store installs an app.
"${COMPOSE[@]}" exec -T --user www-data nextcloud \
	sh -c "rm -rf custom_apps/${APP_ID} && tar -xzf - -C custom_apps" <"${release}/${APP_ID}.tar.gz"
occ app:enable "${APP_ID}" >/dev/null
# The password policy would check the password of the user against a service outside
# the runner, and the first-run wizard has no place here (run.sh).
for app in password_policy firstrunwizard; do
	ocs "Turning off ${app}" --request DELETE "${BASE_URL}/ocs/v2.php/cloud/apps/${app}"
done
ocs "Creating the user e2e-user" \
	--data-urlencode 'userid=e2e-user' \
	--data-urlencode "password=${PASSWORD}" \
	"${BASE_URL}/ocs/v2.php/cloud/users"
# The file of document 123 of the mock, in the folder of the archive account.
curl --silent --show-error --output /dev/null --user "e2e-user:${PASSWORD}" --request MKCOL \
	"${BASE_URL}/remote.php/dav/files/e2e-user/Documents"
"${COMPOSE[@]}" exec -T paperless-mock python /mock/pdf.py 'Synthetic PDF fixture for the upgrade test.' \
	| curl --fail-with-body --silent --show-error --user "e2e-user:${PASSWORD}" --upload-file - \
		"${BASE_URL}/remote.php/dav/files/e2e-user/Documents/Mobile%20viewer%20test%20%5BP123%5D.pdf" >/dev/null

phase "Set up the release ${from} and search"
# Every setting other than its default, so that one that the upgrade resets shows.
printf '%s\n' "${TOKEN}" \
	| occ "${APP_ID}:setup" --url=http://paperless-mock:8080 \
		--archive-account=e2e-user --allow-local --always-search --token-stdin \
		>"${TMP_DIR}/setup.txt"
cat "${TMP_DIR}/setup.txt"
# The last failed search that version 0.3.0 kept, as lazy values, which the repair step
# of the app removes on the upgrade.
occ config:app:set "${APP_ID}" last_failure --value='{"time":1791500000}' --type=array --lazy >/dev/null
occ config:app:set "${APP_ID}" last_recovery --value=1791500060 --type=integer --lazy >/dev/null
if ! until_passes "${TMP_DIR}/results-before.txt" search_results; then
	fail "The release ${from} finds no file for the search after its setup."
fi
if ! until_passes "${TMP_DIR}/settings-before.txt" shown_settings; then
	fail "The release ${from} shows no administration settings."
fi
for expected in url=http://paperless-mock:8080 archiveOwner=e2e-user allowLocal=on alwaysSearch=on token=stored; do
	if ! grep --fixed-strings --line-regexp --regexp="${expected}" "${TMP_DIR}/settings-before.txt" >/dev/null; then
		fail "The release ${from} doesn't show ${expected} after its setup: $(cat "${TMP_DIR}/settings-before.txt")"
	fi
done
occ config:list "${APP_ID}" >"${TMP_DIR}/config-before.json"
assert_upgrade keys <"${TMP_DIR}/config-before.json" | LC_ALL=C sort >"${TMP_DIR}/keys-before.txt"
echo "The release ${from} finds $(wc -l <"${TMP_DIR}/results-before.txt" | tr -d ' ') result(s) and shows:"
sed 's/^/  /' "${TMP_DIR}/settings-before.txt"
# From here on, the log holds what the upgrade and the checkout write.
"${COMPOSE[@]}" exec -T --user www-data nextcloud \
	sh -c 'test ! -f data/nextcloud.log || mv data/nextcloud.log data/nextcloud-release.log'

phase "Replace the release with the package of the checkout and upgrade"
"${COMPOSE[@]}" exec -T --user www-data nextcloud \
	sh -c "rm -rf custom_apps/${APP_ID} && tar -xzf - -C custom_apps" <"${TMP_DIR}/package.tar.gz"
if ! occ upgrade --verbose >"${TMP_DIR}/upgrade.txt" 2>&1; then
	cat "${TMP_DIR}/upgrade.txt" >&2
	fail "occ upgrade failed from ${from} to the checkout."
fi
cat "${TMP_DIR}/upgrade.txt"
if ! grep --fixed-strings "Updated <${APP_ID}> to ${package_version}" "${TMP_DIR}/upgrade.txt" >/dev/null; then
	fail "occ upgrade didn't update ${APP_ID} to ${package_version}."
fi
# The update of the app runs each of its repair steps once, the one above among them,
# with neither a warning nor an error, between the lines "Updating <app> ..." and
# "Updated <app> to <version>"; those of Nextcloud before and after are its own, some
# of which it runs twice.
sed -n "/^Updating <${APP_ID}> \.\.\.\$/,/^Updated <${APP_ID}> to /p" "${TMP_DIR}/upgrade.txt" >"${TMP_DIR}/app-upgrade.txt"
steps="$(grep '^Repair step: ' "${TMP_DIR}/app-upgrade.txt" | LC_ALL=C sort || true)"
if [[ "$(grep --count --fixed-strings --line-regexp "Repair step: ${REPAIR_STEP}" "${TMP_DIR}/upgrade.txt" || true)" != 1 ]]; then
	fail "occ upgrade didn't run the repair step \"${REPAIR_STEP}\" of the app exactly once."
fi
twice="$(uniq -d <<<"${steps}")"
if [[ -n "${twice}" ]]; then
	fail "occ upgrade ran these repair steps of the app more than once: ${twice}"
fi
if grep --extended-regexp '^Repair (warning|error): ' "${TMP_DIR}/app-upgrade.txt" >"${TMP_DIR}/repair-problems.txt"; then
	fail "A repair step of the app warned or failed during occ upgrade: $(cat "${TMP_DIR}/repair-problems.txt")"
fi
# The App Store resets the cache of the compiled PHP code in the process that installs
# an update (Installer.php of Nextcloud), so that PHP drops the classes of the release.
# occ runs apart from the web server, which starts new processes for it here: otherwise
# it would mix the classes of the release that it holds with the new files for up to a
# minute, opcache.revalidate_freq of the image, and fail on a method that the release
# lacks.
"${COMPOSE[@]}" exec -T nextcloud apache2ctl -k graceful >/dev/null 2>&1

phase "Check the settings, the token and the search after the upgrade"
if ! until_passes "${TMP_DIR}/settings-after.txt" shown_settings; then
	fail "After the upgrade from ${from}, the page of the administration settings shows no settings of the app."
fi
lost="$(LC_ALL=C comm -23 <(LC_ALL=C sort "${TMP_DIR}/settings-before.txt") <(LC_ALL=C sort "${TMP_DIR}/settings-after.txt"))"
if [[ -n "${lost}" ]]; then
	printf 'The upgrade from %s lost settings. Before:\n%s\nAfter:\n%s\n' "${from}" \
		"$(cat "${TMP_DIR}/settings-before.txt")" "$(cat "${TMP_DIR}/settings-after.txt")" >&2
	exit 1
fi
occ config:list "${APP_ID}" >"${TMP_DIR}/config-after.json"
assert_upgrade keys <"${TMP_DIR}/config-after.json" | LC_ALL=C sort >"${TMP_DIR}/keys-after.txt"
for key in "${RETIRED_KEYS[@]}"; do
	if grep --fixed-strings --line-regexp --regexp="${key}" "${TMP_DIR}/keys-after.txt" >/dev/null; then
		fail "The repair step left ${key} of version 0.3.0 after the upgrade."
	fi
done
vanished=()
while IFS= read -r key; do
	if [[ " ${RETIRED_KEYS[*]} " != *" ${key} "* ]]; then
		vanished+=("${key}")
	fi
done < <(LC_ALL=C comm -23 "${TMP_DIR}/keys-before.txt" "${TMP_DIR}/keys-after.txt")
if ((${#vanished[@]} > 0)); then
	fail "The upgrade from ${from} lost these keys of the configuration of the app: ${vanished[*]}. A key that the app retires on purpose needs a repair step and its place in RETIRED_KEYS of tests/e2e/upgrade.sh."
fi
if ! until_passes "${TMP_DIR}/results-after.txt" search_results; then
	fail "After the upgrade from ${from}, the search no longer finds the file that it found before."
fi
if ! cmp --silent "${TMP_DIR}/results-before.txt" "${TMP_DIR}/results-after.txt"; then
	printf 'The same search finds other files after the upgrade from %s. Before:\n%s\nAfter:\n%s\n' "${from}" \
		"$(cat "${TMP_DIR}/results-before.txt")" "$(cat "${TMP_DIR}/results-after.txt")" >&2
	exit 1
fi
# The stored token still goes to its own server alone (docs/decisions/0003): a save of
# another server without a token is refused before any request leaves Nextcloud.
requests="$(mock_requests)"
if occ "${APP_ID}:setup" --url=http://other-host:8080 >"${TMP_DIR}/other-host.txt" 2>&1; then
	fail "After the upgrade, occ saved another server with the stored token: $(cat "${TMP_DIR}/other-host.txt")"
fi
if ! grep --fixed-strings 'Pass the token with --token-stdin or --token-env=NAME.' "${TMP_DIR}/other-host.txt" >/dev/null; then
	fail "After the upgrade, occ refused another server without asking for its token: $(cat "${TMP_DIR}/other-host.txt")"
fi
if [[ "$(mock_requests)" != "${requests}" ]]; then
	fail "After the upgrade, the refused save of another server still sent a request to Paperless."
fi
echo "After the upgrade, the app shows the same settings, keeps every key, finds the same file with the stored token and keeps that token to its own server."

phase "Check the log of Nextcloud since the upgrade"
"${COMPOSE[@]}" exec -T nextcloud sh -c 'test ! -f /var/www/html/data/nextcloud.log || cat /var/www/html/data/nextcloud.log' \
	| "${COMPOSE[@]}" exec -T paperless-mock python /mock/assert_log.py

echo "The upgrade from ${from} to the checkout as ${package_version} passed in ${SECONDS} s: the verified release, set up with occ, its search, occ upgrade with each repair step once, the same settings and keys, the same search with the stored token, which still goes to its own server alone, and the log of Nextcloud."
