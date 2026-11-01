#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Takes the screenshots in screenshots/, which the README, the website and the App
# Store show, and the animations of the search and of the set-up in docs/images/, light
# and dark, with the videos that the website plays in their place
# (scripts/screenshot-media.sh), and renders
# the social preview from the first screenshot (scripts/social-preview.sh). Run it
# after a change of what users see:
#   bash scripts/screenshots.sh
# The screenshot bot (.github/workflows/screenshot-bot.yml) runs it on main after a
# change of a file of .github/screenshots-paths.txt, after each release and every week,
# and the visual check (.github/workflows/visual.yml) for a pull request; both keep a
# picture whose pixels stayed the same, so the clock, the times of the files and the
# data of the demo are fixed (tests/e2e/screenshots.mjs).
#
# It needs Docker. It starts Nextcloud and the mock of Paperless of the end-to-end
# tests under a Compose project of its own and sets up a demo: the archive account
# paperless, whose folder Paperless holds synthetic documents and is shared read-only
# with jamie, and the documents that the mock finds for "invoice". Chromium in the
# image of Playwright then goes through the app as a person does
# (tests/e2e/screenshots.mjs). Beside them runs the newest Paperless-ngx of the contract
# tests (tests/e2e/compose.paperless.yaml), in which Chromium sets up the account of the
# search as docs/configure.md does, for the pictures of that set-up. KEEP_SCREENSHOTS=1
# leaves the demo running at http://127.0.0.1:18083, for jamie, paperless and e2e-admin
# with the password e2e-only-password. SCREENSHOTS_PORT and SCREENSHOTS_PROJECT_NAME
# choose another port and Compose project, for a second checkout that takes them at the
# same time.
#
# With --demo, it records one more animation, screenshots/demo-search-and-open.gif,
# from the real Paperless-ngx and Nextcloud of the demo of the README instead, which
# must run already (bash demo/seed.sh): jamie, with the password of demo/.env, opens
# the archive, searches for "invoice" and opens the home insurance renewal, which only
# Paperless finds by its text. It leaves the other pictures and the demo as they are,
# but for what jamie does at a first sign-in, closing the welcome wizard and going
# online, and times of their own for the two templates of jamie that the search finds,
# so that every run records the same pixels (tests/e2e/demo.mjs).
#   bash demo/seed.sh && bash scripts/screenshots.sh --demo
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

case "${1:-}" in
	"") DEMO=0 ;;
	--demo) DEMO=1 ;;
	*)
		echo "Usage: bash scripts/screenshots.sh [--demo]" >&2
		echo "  --demo  record screenshots/demo-search-and-open.gif from the running demo of bash demo/seed.sh" >&2
		exit 2
		;;
esac

# Git Bash on Windows: keep the paths of the container as they are.
export MSYS_NO_PATHCONV=1
# The image of Playwright of the end-to-end tests, which Renovate keeps current.
IMAGE="$(grep -oE 'mcr\.microsoft\.com/playwright:v[^[:space:]]+' tests/e2e/run.sh | head -n 1)"
TMP_DIR="$(mktemp -d)"

# Runs screenshots.mjs in the image of Playwright, in the Docker network that the first
# argument names, with the options of docker run that follow, such as --env. The files
# of the browser reach the container through standard input, and the pictures leave it
# the same way, into TMP_DIR, so that they belong to whoever runs this.
browse() {
	local network="$1"
	shift
	tar -C tests/e2e -cf - package.json package-lock.json ui.mjs demo.mjs gif-frames.mjs screenshots.mjs \
		| docker run --rm --interactive \
			--network "${network}" \
			--env NPM_CONFIG_UPDATE_NOTIFIER=false \
			"$@" \
			"${IMAGE}" \
			sh -c 'mkdir /tmp/browser && cd /tmp/browser && tar -xf - &&
				npm ci --ignore-scripts --no-audit --no-fund --loglevel=error >&2 &&
				out="$(mktemp -d)" && SCREENSHOTS_OUT="${out}" node screenshots.mjs >&2 &&
				tar -C "${out}" -cf - .' \
		| tar -C "${TMP_DIR}" -xf -
}

if [[ "${DEMO}" == 1 ]]; then
	trap 'rm -rf "${TMP_DIR}"' EXIT
	# The demo of demo/seed.sh, with the name of its Compose project in demo/compose.yaml,
	# which names its network too.
	DEMO_COMPOSE=(docker compose --file demo/compose.yaml --env-file demo/.env)
	if [[ ! -f demo/.env ]]; then
		echo "The demo isn't set up: start it with bash demo/seed.sh, then run bash scripts/screenshots.sh --demo again." >&2
		exit 1
	fi
	running="$("${DEMO_COMPOSE[@]}" ps --status running --services 2>/dev/null || true)"
	if ! grep -qxF nextcloud <<<"${running}"; then
		echo "The Nextcloud of the demo doesn't run: start the demo with bash demo/seed.sh, then run bash scripts/screenshots.sh --demo again." >&2
		exit 1
	fi
	# The password of jamie reaches the container in the environment, never on a command
	# line, where the list of processes would show it.
	E2E_PASSWORD="$(sed -n 's/^DEMO_PASSWORD=//p' demo/.env | tail -n 1)"
	if [[ -z "${E2E_PASSWORD}" ]]; then
		echo "demo/.env holds no DEMO_PASSWORD, the password of jamie: run bash demo/seed.sh, which writes it, or remove the demo and demo/.env to start anew." >&2
		exit 1
	fi
	export E2E_PASSWORD
	browse paperless-unified-search-demo_default --env E2E_PASSWORD --env SCREENSHOTS_DEMO=1
	cp "${TMP_DIR}/demo-search-and-open.gif" screenshots/
	ls -l screenshots/demo-search-and-open.gif
	exit 0
fi

export E2E_PORT="${SCREENSHOTS_PORT:-18083}"
PROJECT_NAME="${SCREENSHOTS_PROJECT_NAME:-paperless_unified_search_screenshots}"
PASSWORD=e2e-only-password
BASE_URL="http://127.0.0.1:${E2E_PORT}"
COMPOSE=(docker compose --project-name "${PROJECT_NAME}" --file tests/e2e/compose.yaml
	--file tests/e2e/compose.paperless.yaml --profile paperless-3)
# A secret key of Paperless for this run alone, as the contract tests make it.
PAPERLESS_SECRET_KEY="$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n')"
export PAPERLESS_SECRET_KEY

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
"${COMPOSE[@]}" up --detach --wait --wait-timeout 300 --quiet-pull >/dev/null

if ! occ status --output=json 2>/dev/null | grep --fixed-strings '"installed":true' >/dev/null; then
	occ maintenance:install --database=sqlite --admin-user=e2e-admin --admin-pass="${PASSWORD}" >/dev/null
fi
occ config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null
occ config:system:set trusted_domains 2 --value=nextcloud >/dev/null
occ config:system:set default_language --value=en >/dev/null
occ config:system:set default_locale --value=en_US >/dev/null
occ app:enable paperless_unified_search >/dev/null
# The first-run wizard would cover the pages, the announcements and the survey would
# add notifications, and the status of a user would show on the avatars only once the
# browser has told Nextcloud that the user is online, sooner in one run than another.
for app in firstrunwizard nextcloud_announcements survey_client user_status; do
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
	--data-urlencode 'allowLocal=1' \
	--data-urlencode 'token=e2e-only-token' \
	--data-urlencode 'alwaysSearch=0' \
	--data-urlencode 'archiveOwner=paperless' \
	"${BASE_URL}/apps/paperless_unified_search/settings" >/dev/null

# A history of the problems of the search, of a connection that failed for a while,
# for the picture of Search problems.
occ config:app:set paperless_unified_search diagnostics --type=array --lazy \
	--value='{"since":1791475200,"failed":1,"retried":2,"lastFailureAt":1791496720,"recoveredAt":1791496750,"events":[{"time":1791496872,"kind":"retried","step":"paperless","error":"ConnectException","message":"cURL error 28: Connection timed out after 3001 milliseconds for https://paperless.example.com/api/documents/","durationMs":3002},{"time":1791496720,"kind":"failed","step":"paperless","error":"ConnectException","message":"cURL error 7: Failed to connect to paperless.example.com port 443 after 3001 ms: Timeout was reached for https://paperless.example.com/api/documents/","durationMs":6108},{"time":1791496205,"kind":"retried","step":"paperless","error":"ConnectException","message":"cURL error 6: Could not resolve host: paperless.example.com for https://paperless.example.com/api/documents/","durationMs":1004}]}' >/dev/null

browse "${PROJECT_NAME}_default" --env "E2E_PASSWORD=${PASSWORD}"

# The notification about new documents. Jamie asks for every new document, the first run
# of the job learns how far the IDs go, then the mock takes in two documents, the archive
# account writes their files into the folder that it shares with jamie, and the next run
# notifies jamie. The job runs here, as occ, so a second pass of the browser takes the
# picture.
ALERTS_JOB="$(occ background-job:list --class='OCA\PaperlessUnifiedSearch\BackgroundJob\NotifyNewDocuments' --output=json |
	"${COMPOSE[@]}" exec -T paperless-mock python -c 'import json, sys; print(json.load(sys.stdin)[0]["id"])')"
curl --fail-with-body --silent --show-error \
	--user "jamie:${PASSWORD}" \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--request POST \
	--data-urlencode 'everyDocument=1' \
	"${BASE_URL}/apps/paperless_unified_search/personal/alerts" >/dev/null
occ background-job:execute "${ALERTS_JOB}" --force-execute >/dev/null
for document in '1001:Tax assessment 2025' '1002:Water bill - October 2026'; do
	id="${document%%:*}"
	title="${document#*:}"
	"${COMPOSE[@]}" exec -T paperless-mock python -c 'import json, sys, urllib.request; urllib.request.urlopen(urllib.request.Request("http://localhost:8080/e2e/documents", data=json.dumps({"id": int(sys.argv[1]), "title": sys.argv[2]}).encode(), method="POST"))' "${id}" "${title}"
	"${COMPOSE[@]}" exec -T paperless-mock python /mock/pdf.py "${title}" |
		curl --fail-with-body --silent --show-error \
			--user "paperless:${PASSWORD}" \
			--upload-file - \
			"${BASE_URL}/remote.php/dav/files/paperless/Paperless/${title// /%20}%20%5BP${id}%5D.pdf" >/dev/null
done
occ background-job:execute "${ALERTS_JOB}" --force-execute >/dev/null
browse "${PROJECT_NAME}_default" --env "E2E_PASSWORD=${PASSWORD}" --env SCREENSHOTS_NOTIFICATION=1

# The videos of the animations with their first frames, and every PNG at its smallest.
bash scripts/screenshot-media.sh "${TMP_DIR}"

# The animations of the README and of the website, light and dark, are files of the
# website, so that the website plays their videos: the website loads the screenshots
# from main, and no video of main.
cp "${TMP_DIR}"/*.png screenshots/
cp "${TMP_DIR}"/*.gif "${TMP_DIR}"/*.mp4 "${TMP_DIR}"/*.webp docs/images/
ls -l screenshots/ docs/images/

# The social preview shows the first screenshot.
bash scripts/social-preview.sh
