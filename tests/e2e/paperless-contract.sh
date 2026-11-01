#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The contract tests against a real Paperless-ngx, which the job "paperless" of
# .github/workflows/e2e.yml runs for each line of Paperless every week: the other tests
# answer with the mock of Paperless, which says only what we think Paperless says. This
# script starts the Paperless of the line, Redis, Nextcloud with the app and the mock of
# compose.yaml, whose container runs assert_contract.py in the network of the project:
# it gives Paperless three synthetic text files of contract/documents/ and checks, with
# the version of the API and the fields that the app asks for, what the app relies on,
# first directly and then through Nextcloud, last what each permission of the account of
# the token gives the search, as the table of docs/configure.md says, and writes what it
# found to the summary of the job. It takes about three minutes, most of it the start of Paperless.
#
#   PAPERLESS=3 bash tests/e2e/paperless-contract.sh   # the newest Paperless-ngx (default)
#   PAPERLESS=2 bash tests/e2e/paperless-contract.sh   # the last release of Paperless-ngx 2
#
# KEEP_E2E=1 keeps the containers, as run.sh does.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
PAPERLESS="${PAPERLESS:-3}"
if [[ "${PAPERLESS}" != 2 && "${PAPERLESS}" != 3 ]]; then
	echo "PAPERLESS is the line of Paperless-ngx to test, 2 or 3, not ${PAPERLESS}." >&2
	exit 2
fi
PROJECT_NAME="${E2E_PROJECT_NAME:-paperless_unified_search_contract_${PAPERLESS}}"
PASSWORD="e2e-only-password"
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"
COMPOSE=("${DOCKER_BIN}" compose --project-name "${PROJECT_NAME}"
	--file "${SCRIPT_DIR}/compose.yaml" --file "${SCRIPT_DIR}/compose.paperless.yaml"
	--profile "paperless-${PAPERLESS}")

# Another port than the one of run.sh, so that both can run on one machine at once.
export E2E_PORT="${E2E_PORT:-1809${PAPERLESS}}"
# A secret key of Paperless for this run alone.
PAPERLESS_SECRET_KEY="$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n')"
export PAPERLESS_SECRET_KEY
# Git Bash on Windows: keep the paths in the container of the mock as they are.
export MSYS2_ARG_CONV_EXCL="/mock/${MSYS2_ARG_CONV_EXCL:+;${MSYS2_ARG_CONV_EXCL}}"

# The version of the API, the fields of a result, those of the newest documents of the
# dashboard that the app asks for and the words that it takes for a refused document, from
# its code, so that the contract checks what the app sends and reads.
SERVICE="${REPOSITORY_ROOT}/lib/Service/PaperlessApiService.php"
ACCEPT="$(sed -n "s/^[[:space:]]*private const ACCEPT = '\([^']*\)';.*/\1/p" "${SERVICE}")"
FIELDS="$(sed -n "s/^[[:space:]]*private const SEARCH_FIELDS = '\([^']*\)';.*/\1/p" "${SERVICE}")"
RECENT_FIELDS="$(sed -n "s/^[[:space:]]*private const RECENT_FIELDS = '\([^']*\)';.*/\1/p" "${SERVICE}")"
UNKNOWN_DOCUMENT="$(sed -n "s/^[[:space:]]*private const UNKNOWN_DOCUMENT = '\([^']*\)';.*/\1/p" "${SERVICE}")"
HIDDEN_DOCUMENT="$(sed -n "s/^[[:space:]]*private const HIDDEN_DOCUMENT = '\([^']*\)';.*/\1/p" "${SERVICE}")"
if [[ -z "${ACCEPT}" || -z "${FIELDS}" || -z "${RECENT_FIELDS}" || -z "${UNKNOWN_DOCUMENT}" || -z "${HIDDEN_DOCUMENT}" ]]; then
	echo "${SERVICE} has no constant ACCEPT, SEARCH_FIELDS, RECENT_FIELDS, UNKNOWN_DOCUMENT or HIDDEN_DOCUMENT, which the contract reads." >&2
	exit 1
fi

cleanup() {
	status=$?
	trap - EXIT
	if [[ "${status}" -ne 0 ]]; then
		"${COMPOSE[@]}" ps || true
		"${COMPOSE[@]}" logs --no-color --tail 100 || true
	fi
	if [[ "${KEEP_E2E:-0}" == "1" ]]; then
		echo "Contract environment kept (project ${PROJECT_NAME})."
	elif [[ "${RUNNER_ENVIRONMENT:-}" != "github-hosted" ]]; then
		"${COMPOSE[@]}" down --volumes --remove-orphans --timeout 0 >/dev/null 2>&1 || true
	fi
	exit "${status}"
}
trap cleanup EXIT

phase() {
	printf '\n== %s s: %s\n' "${SECONDS}" "$1"
}

occ() {
	"${COMPOSE[@]}" exec -T --user www-data nextcloud php occ "$@"
}

phase "Start Paperless-ngx ${PAPERLESS}, Nextcloud and the mock"
"${COMPOSE[@]}" up --detach --wait --wait-timeout 300 --quiet-pull

phase "Set up Nextcloud and the app"
if ! "${COMPOSE[@]}" exec -T nextcloud curl --silent http://127.0.0.1/status.php |
	grep --fixed-strings '"installed":true' >/dev/null; then
	occ maintenance:install \
		--database=sqlite \
		--admin-user=e2e-admin \
		--admin-pass="${PASSWORD}" >/dev/null
fi
# The assertions reach Nextcloud by its name in the network of the project.
occ config:system:set trusted_domains 1 --value=nextcloud >/dev/null
occ app:enable paperless_unified_search >/dev/null

phase "Check the contract with Paperless-ngx ${PAPERLESS}"
"${COMPOSE[@]}" exec -T \
	--env "ACCEPT=${ACCEPT}" \
	--env "FIELDS=${FIELDS}" \
	--env "RECENT_FIELDS=${RECENT_FIELDS}" \
	--env "UNKNOWN_DOCUMENT=${UNKNOWN_DOCUMENT}" \
	--env "HIDDEN_DOCUMENT=${HIDDEN_DOCUMENT}" \
	--env "LINE=${PAPERLESS}" \
	paperless-mock python /mock/assert_contract.py | tee -a "${SUMMARY}"
phase "The contract holds"
