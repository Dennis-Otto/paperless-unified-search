#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The end-to-end tests against every Nextcloud version that appinfo/info.xml supports,
# from min-version to max-version, all at the same time: run.sh for each version, with
# the current release of its image nextcloud:<version>-apache, in a Compose project and
# on a port of its own. Together they take far less time than one after the other. The
# required check "nextcloud" of .github/workflows/e2e.yml runs this script.
#
#   bash tests/e2e/run-all.sh
#   E2E_VERSIONS="33 35" bash tests/e2e/run-all.sh
#   E2E_PHONE=newest bash tests/e2e/run-all.sh
#
# flow.mjs goes through the search dialog on a laptop and on a phone, with every
# version. E2E_PHONE=newest, which e2e.yml sets for a pull request, tests the phone only
# with the newest of the versions and the laptop alone with the others, which saves each
# of them 16 to 19 seconds on a runner of GitHub; a push to main, the weekly run and a
# run started by hand keep E2E_PHONE=all, the default, and test every size with every
# version.
#
# Each version writes its log to a file of its own, which the script prints once all
# are done, in a group of the log of GitHub Actions per version. A version that fails
# fails the script, and so does one that takes longer than E2E_VERSION_TIMEOUT seconds
# (default 600, more than twice what it needs): it stops, so that its log still shows
# where it hung, long before the job of the CI times out after 20 minutes.
#
# In GitHub Actions, the script writes a table of the versions, with the release
# behind each image, into the summary of the run, and the versions that broke into the
# output "failed" of its step, such as "Nextcloud 35 (35.0.1, failed)", and their major
# versions into the output "versions", such as "35", from which the weekly run of
# .github/workflows/e2e.yml writes its issue.
# scripts/check-project.sh tests this script with tests/e2e/selftest.sh.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
VERSION_TIMEOUT="${E2E_VERSION_TIMEOUT:-600}"
PHONE="${E2E_PHONE:-all}"
LOG_DIR="$(mktemp -d)"
versions=()
pids=()
downloads=()
results=()
durations=()
releases=()
sizes=()

cleanup() {
	status=$?
	trap - EXIT
	rm -rf "${LOG_DIR}"
	exit "${status}"
}
trap cleanup EXIT

# Ctrl-C or a cancelled job: every run stops and tidies up before the script ends.
stop() {
	trap - INT TERM
	echo "Interrupted: the end-to-end tests stop and remove their containers." >&2
	kill -TERM "${pids[@]}" "${downloads[@]}" >/dev/null 2>&1 || true
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
if [[ "${PHONE}" != all && "${PHONE}" != newest ]]; then
	echo "E2E_PHONE is ${PHONE}, but must be all, for the phone with every version, or newest, for the phone with the newest version alone." >&2
	exit 1
fi
if [[ ! "${VERSION_TIMEOUT}" =~ ^[1-9][0-9]*$ ]]; then
	echo "E2E_VERSION_TIMEOUT is ${VERSION_TIMEOUT}, but must be a number of seconds, such as 600." >&2
	exit 1
fi
# timeout of GNU coreutils, on Linux and in Git Bash, stops a version that hangs with
# every process it started: first with TERM, after which run.sh writes the state of its
# containers into its log, and 30 seconds later with KILL. Without it, as on macOS, a
# version that hangs runs until the job does.
deadline=()
if timeout --version >/dev/null 2>&1; then
	deadline=(timeout --kill-after=30 "${VERSION_TIMEOUT}")
fi

# The sizes of the search dialog in flow.mjs for each version (run.sh).
newest="${versions[0]}"
for version in "${versions[@]}"; do
	if ((10#${version} > 10#${newest})); then
		newest="${version}"
	fi
done
for index in "${!versions[@]}"; do
	if [[ "${PHONE}" == newest && "${versions[index]}" != "${newest}" ]]; then
		sizes[index]="laptop"
	else
		sizes[index]="laptop phone"
	fi
done

if ((${#versions[@]} == 1)); then
	echo "The end-to-end tests run against Nextcloud ${versions[0]}."
else
	echo "The end-to-end tests run against Nextcloud ${versions[*]} at the same time."
fi
if [[ "${PHONE}" == newest ]]; then
	echo "The search dialog runs on a phone only against Nextcloud ${newest}, and on a laptop against every version (E2E_PHONE=newest)."
fi
SECONDS=0

# First the images of Nextcloud and of the mock, for every version at the same time:
# each run needs them at once, the browser only at its end. On a runner of GitHub,
# this is about ten seconds faster than downloading the browser along with them. A
# download that fails is left to the run of its version, whose log then says why.
for version in "${versions[@]}"; do
	NEXTCLOUD_IMAGE="nextcloud:${version}-apache" \
		"${DOCKER_BIN}" compose --project-name "pus_e2e_${version}" --file "${SCRIPT_DIR}/compose.yaml" \
		pull --quiet >/dev/null 2>&1 &
	downloads+=("$!")
done
wait "${downloads[@]}" || true
downloads=()
echo "The images of Nextcloud are there after ${SECONDS} s."

# The browser of the accessibility check, which every version needs at its end: one
# download for all of them, while Nextcloud sets itself up. A run that gets there
# first waits for the same download in docker run.
PLAYWRIGHT_IMAGE="$(grep -oE 'mcr\.microsoft\.com/playwright:v[^[:space:]]+' "${SCRIPT_DIR}/run.sh" | head -n 1)"
"${DOCKER_BIN}" pull --quiet "${PLAYWRIGHT_IMAGE}" >/dev/null 2>&1 &
downloads+=("$!")

for index in "${!versions[@]}"; do
	version="${versions[index]}"
	E2E_PORT="$((18000 + version))" \
		E2E_PROJECT_NAME="pus_e2e_${version}" \
		NEXTCLOUD_IMAGE="nextcloud:${version}-apache" \
		E2E_SKIP_PLAYWRIGHT_PULL=1 \
		E2E_FLOW_SIZES="${sizes[index]}" \
		"${deadline[@]}" bash "${SCRIPT_DIR}/run.sh" >"${LOG_DIR}/e2e-${version}.log" 2>&1 &
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
		status=0
		wait "${pids[index]}" || status=$?
		if ((status == 0)); then
			results[index]=passed
		elif ((${#deadline[@]} > 0 && (status == 124 || status == 137))); then
			# timeout ends with 124 after TERM, and with 137 when it had to send KILL.
			results[index]="timed out"
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
kill "${downloads[@]}" >/dev/null 2>&1 || true
wait "${downloads[@]}" || true
downloads=()

failed=()
for index in "${!versions[@]}"; do
	version="${versions[index]}"
	group "Nextcloud ${version} (${results[index]}, ${durations[index]} s)"
	cat "${LOG_DIR}/e2e-${version}.log"
	end_group
	case "${results[index]}" in
		passed) continue ;;
		failed) problem="failed against Nextcloud ${version}" ;;
		*) problem="against Nextcloud ${version} took longer than ${VERSION_TIMEOUT} s and were stopped" ;;
	esac
	failed+=("${version}")
	if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
		echo "::error title=Nextcloud ${version}::The end-to-end tests ${problem}; the group \"Nextcloud ${version} (${results[index]}, ${durations[index]} s)\" of the log shows why."
	fi
done

# The release that each floating image nextcloud:<version>-apache held, empty when
# its download failed, for the summary of the run and the output of the step.
if [[ -n "${GITHUB_STEP_SUMMARY:-}" || -n "${GITHUB_OUTPUT:-}" ]]; then
	for index in "${!versions[@]}"; do
		releases[index]="$("${DOCKER_BIN}" image inspect --format '{{range .Config.Env}}{{println .}}{{end}}' \
			"nextcloud:${versions[index]}-apache" 2>/dev/null | sed -n 's/^NEXTCLOUD_VERSION=//p' || true)"
	done
fi

# A table of the versions on the summary page of the run.
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
	{
		echo "### End-to-end tests"
		echo
		echo "| Nextcloud | Release | Result | Time | Search dialog |"
		echo "| --- | --- | --- | --- | --- |"
		for index in "${!versions[@]}"; do
			echo "| ${versions[index]} | ${releases[index]:-unknown} | ${results[index]} | ${durations[index]} s | ${sizes[index]// /, } |"
		done
	} >>"${GITHUB_STEP_SUMMARY}"
fi

# The versions that broke, with their releases and results, on one line, for the issue
# of the weekly run: "Nextcloud 34 (34.0.1, failed), Nextcloud 35 (35.0.1, timed out)",
# and nothing when every version passed; and their major versions alone, "34 35", for
# the command in the issue that runs their tests locally.
if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
	broken=""
	for index in "${!versions[@]}"; do
		if [[ "${results[index]}" != passed ]]; then
			broken+="${broken:+, }Nextcloud ${versions[index]} (${releases[index]:-unknown release}, ${results[index]})"
		fi
	done
	echo "failed=${broken}" >>"${GITHUB_OUTPUT}"
	echo "versions=${failed[*]}" >>"${GITHUB_OUTPUT}"
fi

if ((${#failed[@]} > 0)); then
	echo "The end-to-end tests failed or timed out against Nextcloud ${failed[*]}." >&2
	exit 1
fi
echo "The end-to-end tests passed against Nextcloud ${versions[*]} in ${SECONDS} s."
