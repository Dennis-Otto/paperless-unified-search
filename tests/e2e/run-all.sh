#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The end-to-end tests against every Nextcloud version that appinfo/info.xml supports,
# from min-version to max-version, all at the same time: run.sh for each version, with
# the current release of its image nextcloud:<version>-apache, in a Compose project and
# on a port of its own. Together they take about as long as one version alone. The
# required check "nextcloud" of .github/workflows/e2e.yml runs this script.
#
#   bash tests/e2e/run-all.sh
#   E2E_VERSIONS="33 35" bash tests/e2e/run-all.sh
#
# Each version writes its log to a file of its own, which the script prints once all
# are done, in a group of the log of GitHub Actions per version. A version that fails
# fails the script.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
LOG_DIR="$(mktemp -d)"
versions=()
pids=()
pull=""
results=()
durations=()

cleanup() {
	status=$?
	trap - EXIT
	rm -rf "${LOG_DIR}"
	exit "${status}"
}
trap cleanup EXIT

# Ctrl-C or a cancelled job: every run tidies up its containers before the script ends.
stop() {
	trap - INT TERM
	echo "Interrupted: the end-to-end tests stop and remove their containers." >&2
	kill -TERM "${pids[@]}" ${pull:+"${pull}"} >/dev/null 2>&1 || true
	wait || true
	exit 130
}
trap stop INT TERM

# Lines that group the log in GitHub Actions, and plain headings elsewhere.
group() {
	if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
		echo "::group::$1"
	else
		printf '\n== %s\n' "$1"
	fi
}

end_group() {
	if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
		echo "::endgroup::"
	fi
}

# The major versions from min-version to max-version of appinfo/info.xml, as the
# upstream bot raises max-version: <nextcloud min-version="33" max-version="35"/>.
supported_versions() {
	local element low high version
	element="$(grep -oE '<nextcloud [^>]*>' "${REPOSITORY_ROOT}/appinfo/info.xml" | head -n 1 || true)"
	low="$(sed -nE 's/.* min-version="([0-9]+)[^"]*".*/\1/p' <<<"${element}")"
	high="$(sed -nE 's/.* max-version="([0-9]+)[^"]*".*/\1/p' <<<"${element}")"
	if [[ -z "${low}" || -z "${high}" ]] || ((low > high)); then
		echo "appinfo/info.xml names no range of Nextcloud versions: ${element:-no <nextcloud> element}" >&2
		exit 1
	fi
	for ((version = low; version <= high; version++)); do
		echo "${version}"
	done
}

if [[ -n "${E2E_VERSIONS:-}" ]]; then
	read -ra versions <<<"${E2E_VERSIONS//,/ }"
	if ((${#versions[@]} == 0)); then
		echo "E2E_VERSIONS names no Nextcloud version." >&2
		exit 1
	fi
else
	supported="$(supported_versions)"
	mapfile -t versions <<<"${supported}"
fi
for version in "${versions[@]}"; do
	if [[ ! "${version}" =~ ^[0-9]+$ ]]; then
		echo "${version} is no major version of Nextcloud, such as 33." >&2
		exit 1
	fi
done

# The browser of the accessibility check, which every version needs at its end: one
# pull for all of them, while Nextcloud sets itself up. A run that gets there first
# waits for the same download in docker run.
PLAYWRIGHT_IMAGE="$(grep -oE 'mcr\.microsoft\.com/playwright:v[^[:space:]]+' "${SCRIPT_DIR}/run.sh" | head -n 1)"
"${DOCKER_BIN}" pull --quiet "${PLAYWRIGHT_IMAGE}" >/dev/null 2>&1 &
pull=$!

if ((${#versions[@]} == 1)); then
	echo "The end-to-end tests run against Nextcloud ${versions[0]}."
else
	echo "The end-to-end tests run against Nextcloud ${versions[*]} at the same time."
fi
SECONDS=0
for version in "${versions[@]}"; do
	E2E_PORT="$((18000 + version))" \
		E2E_PROJECT_NAME="pus_e2e_${version}" \
		NEXTCLOUD_IMAGE="nextcloud:${version}-apache" \
		E2E_SKIP_PLAYWRIGHT_PULL=1 \
		bash "${SCRIPT_DIR}/run.sh" >"${LOG_DIR}/e2e-${version}.log" 2>&1 &
	pids+=("$!")
done

# Notes each version as it finishes, with the time it took.
pending=("${!versions[@]}")
while ((${#pending[@]} > 0)); do
	running=()
	for index in "${pending[@]}"; do
		if kill -0 "${pids[index]}" 2>/dev/null; then
			running+=("${index}")
			continue
		fi
		if wait "${pids[index]}"; then
			results[index]=passed
		else
			results[index]=failed
		fi
		durations[index]="${SECONDS}"
		echo "Nextcloud ${versions[index]} ${results[index]} after ${durations[index]} s."
	done
	pending=("${running[@]}")
	if ((${#pending[@]} > 0)); then
		sleep 1
	fi
done
# No version needs the browser any more, such as when every one failed before it.
kill "${pull}" >/dev/null 2>&1 || true
wait "${pull}" || true
pull=""

failed=()
for index in "${!versions[@]}"; do
	version="${versions[index]}"
	group "Nextcloud ${version} (${results[index]}, ${durations[index]} s)"
	cat "${LOG_DIR}/e2e-${version}.log"
	end_group
	if [[ "${results[index]}" == failed ]]; then
		failed+=("${version}")
		if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
			echo "::error title=Nextcloud ${version}::The end-to-end tests failed against Nextcloud ${version}; the group \"Nextcloud ${version} (failed, ${durations[index]} s)\" of the log shows why."
		fi
	fi
done

# A table of the versions on the summary page of the run, with the release that each
# floating image nextcloud:<version>-apache held.
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
	{
		echo "### End-to-end tests"
		echo
		echo "| Nextcloud | Release | Result | Time |"
		echo "| --- | --- | --- | --- |"
		for index in "${!versions[@]}"; do
			version="${versions[index]}"
			release="$("${DOCKER_BIN}" image inspect --format '{{range .Config.Env}}{{println .}}{{end}}' \
				"nextcloud:${version}-apache" 2>/dev/null | sed -n 's/^NEXTCLOUD_VERSION=//p' || true)"
			echo "| ${version} | ${release:-unknown} | ${results[index]} | ${durations[index]} s |"
		done
	} >>"${GITHUB_STEP_SUMMARY}"
fi

if ((${#failed[@]} > 0)); then
	echo "The end-to-end tests failed against Nextcloud ${failed[*]}." >&2
	exit 1
fi
echo "The end-to-end tests passed against Nextcloud ${versions[*]} in ${SECONDS} s."
