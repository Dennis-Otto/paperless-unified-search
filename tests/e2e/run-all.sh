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
#   E2E_UPGRADE=newest bash tests/e2e/run-all.sh
#
# flow.mjs goes through the search dialog on a laptop and on a phone, with every
# version. E2E_PHONE=newest, which e2e.yml sets for a pull request, tests the phone only
# with the newest of the versions and the laptop alone with the others, which saves each
# of them 16 to 19 seconds on a runner of GitHub; a push to main, the weekly run and a
# run started by hand keep E2E_PHONE=all, the default, and test every size with every
# version.
#
# Beside run.sh, upgrade.sh upgrades the last release to the checkout, at the same time,
# with each version that E2E_UPGRADE names, in the Compose project pus_upgrade_<version>
# on the port 19000 + <version>: all, the default, every version; newest, which e2e.yml
# sets for a pull request, the newest of them; none, no version. A version passes only
# when both pass; an upgrade that upgrade.sh skips, as before the first release, passes.
#
# Each version writes its log to a file of its own, and so does each upgrade, which the
# script prints once all are done, in a group of the log of GitHub Actions each. A
# version that fails fails the script, and so does one that takes longer than
# E2E_VERSION_TIMEOUT seconds (default 600, more than twice what it needs): it stops, so
# that its log still shows where it hung, long before the job of the CI times out after
# 20 minutes. The same holds for each upgrade.
#
# In GitHub Actions, the script writes a table of the versions, with the release
# behind each image and the result of each upgrade, into the summary of the run, and
# the versions that broke into the output "failed" of its step, such as "Nextcloud 35
# (35.0.1, failed)" or "Nextcloud 34 (34.0.1, upgrade failed)", and their major
# versions into the output "versions", such as "35", from which the weekly run of
# .github/workflows/e2e.yml writes its issue.
# scripts/check-project.sh tests this script with tests/e2e/selftest.sh.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
VERSION_TIMEOUT="${E2E_VERSION_TIMEOUT:-600}"
PHONE="${E2E_PHONE:-all}"
UPGRADE="${E2E_UPGRADE:-all}"
LOG_DIR="$(mktemp -d)"
versions=()
downloads=()
results=()
durations=()
releases=()
sizes=()
# The upgrade of each version: 1 when it runs, its result, its time and the release
# that it upgraded from.
upgrades=()
upgrade_results=()
upgrade_durations=()
upgrade_from=()
# The runs of run.sh and of upgrade.sh: the process, the script, the index of the
# version and the log of each.
pids=()
run_scripts=()
run_versions=()
run_logs=()

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
if [[ "${UPGRADE}" != all && "${UPGRADE}" != newest && "${UPGRADE}" != none ]]; then
	echo "E2E_UPGRADE is ${UPGRADE}, but must be all, for the upgrade with every version, newest, for the upgrade with the newest version alone, or none." >&2
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

# The sizes of the search dialog in flow.mjs for each version (run.sh), and whether
# upgrade.sh runs with it.
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
	if [[ "${UPGRADE}" == all || ("${UPGRADE}" == newest && "${versions[index]}" == "${newest}") ]]; then
		upgrades[index]=1
	else
		upgrades[index]=0
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
case "${UPGRADE}" in
	all) echo "The upgrade from the last release runs against every version as well." ;;
	newest) echo "The upgrade from the last release runs only against Nextcloud ${newest} (E2E_UPGRADE=newest)." ;;
	*) echo "The upgrade from the last release doesn't run (E2E_UPGRADE=none)." ;;
esac
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
	run_scripts+=(run.sh)
	run_versions+=("${index}")
	run_logs+=("${LOG_DIR}/e2e-${version}.log")
	if ((upgrades[index] == 1)); then
		E2E_PORT="$((19000 + version))" \
			E2E_PROJECT_NAME="pus_upgrade_${version}" \
			NEXTCLOUD_IMAGE="nextcloud:${version}-apache" \
			"${deadline[@]}" bash "${SCRIPT_DIR}/upgrade.sh" >"${LOG_DIR}/upgrade-${version}.log" 2>&1 &
		pids+=("$!")
		run_scripts+=(upgrade.sh)
		run_versions+=("${index}")
		run_logs+=("${LOG_DIR}/upgrade-${version}.log")
	fi
done

# Notes each run as it finishes, with the time it took.
pending=("${!pids[@]}")
while ((${#pending[@]} > 0)); do
	running=()
	for run in "${pending[@]}"; do
		if kill -0 "${pids[run]}" 2>/dev/null; then
			running+=("${run}")
			continue
		fi
		status=0
		wait "${pids[run]}" || status=$?
		if ((status == 0)); then
			result=passed
		elif ((${#deadline[@]} > 0 && (status == 124 || status == 137))); then
			# timeout ends with 124 after TERM, and with 137 when it had to send KILL.
			result="timed out"
		else
			result=failed
		fi
		index="${run_versions[run]}"
		if [[ "${run_scripts[run]}" == run.sh ]]; then
			results[index]="${result}"
			durations[index]="${SECONDS}"
			echo "Nextcloud ${versions[index]} ${result} after ${SECONDS} s."
			continue
		fi
		# upgrade.sh says so when it skips the upgrade, as before the first release.
		if [[ "${result}" == passed ]] && grep --fixed-strings 'The upgrade test is skipped' "${run_logs[run]}" >/dev/null; then
			result=skipped
		fi
		upgrade_results[index]="${result}"
		upgrade_durations[index]="${SECONDS}"
		upgrade_from[index]="$(sed -n 's/^The release to upgrade from: //p' "${run_logs[run]}" | head -n 1)"
		echo "The upgrade on Nextcloud ${versions[index]} ${result} after ${SECONDS} s."
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

# What broke with a version, such as "failed" or "upgrade timed out", for the output of
# the step; nothing when its tests and its upgrade passed.
verdict() {
	local index="$1" problem=""
	if [[ "${results[index]}" != passed ]]; then
		problem="${results[index]}"
	fi
	if [[ "${upgrade_results[index]:-}" == failed || "${upgrade_results[index]:-}" == "timed out" ]]; then
		problem+="${problem:+, }upgrade ${upgrade_results[index]}"
	fi
	echo "${problem}"
}

failed=()
for index in "${!versions[@]}"; do
	version="${versions[index]}"
	group "Nextcloud ${version} (${results[index]}, ${durations[index]} s)"
	cat "${LOG_DIR}/e2e-${version}.log"
	end_group
	if ((upgrades[index] == 1)); then
		group "Upgrade on Nextcloud ${version} (${upgrade_results[index]}, ${upgrade_durations[index]} s)"
		cat "${LOG_DIR}/upgrade-${version}.log"
		end_group
	fi
	if [[ -z "$(verdict "${index}")" ]]; then
		continue
	fi
	failed+=("${version}")
	if [[ "${GITHUB_ACTIONS:-}" != "true" ]]; then
		continue
	fi
	case "${results[index]}" in
		passed) ;;
		failed) echo "::error title=Nextcloud ${version}::The end-to-end tests failed against Nextcloud ${version}; the group \"Nextcloud ${version} (${results[index]}, ${durations[index]} s)\" of the log shows why." ;;
		*) echo "::error title=Nextcloud ${version}::The end-to-end tests against Nextcloud ${version} took longer than ${VERSION_TIMEOUT} s and were stopped; the group \"Nextcloud ${version} (${results[index]}, ${durations[index]} s)\" of the log shows why." ;;
	esac
	case "${upgrade_results[index]:-}" in
		failed) echo "::error title=Upgrade on Nextcloud ${version}::The upgrade from the last release failed on Nextcloud ${version}; the group \"Upgrade on Nextcloud ${version} (failed, ${upgrade_durations[index]} s)\" of the log shows why." ;;
		"timed out") echo "::error title=Upgrade on Nextcloud ${version}::The upgrade from the last release on Nextcloud ${version} took longer than ${VERSION_TIMEOUT} s and was stopped; the group \"Upgrade on Nextcloud ${version} (timed out, ${upgrade_durations[index]} s)\" of the log shows why." ;;
	esac
done

# The release that each floating image nextcloud:<version>-apache held, empty when
# its download failed, for the summary of the run and the output of the step.
if [[ -n "${GITHUB_STEP_SUMMARY:-}" || -n "${GITHUB_OUTPUT:-}" ]]; then
	for index in "${!versions[@]}"; do
		releases[index]="$("${DOCKER_BIN}" image inspect --format '{{range .Config.Env}}{{println .}}{{end}}' \
			"nextcloud:${versions[index]}-apache" 2>/dev/null | sed -n 's/^NEXTCLOUD_VERSION=//p' || true)"
	done
fi

# A table of the versions on the summary page of the run, with the result of each
# upgrade, such as "passed, from v1.1.1, 95 s".
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
	{
		echo "### End-to-end tests"
		echo
		echo "| Nextcloud | Release | Result | Time | Search dialog | Upgrade |"
		echo "| --- | --- | --- | --- | --- | --- |"
		for index in "${!versions[@]}"; do
			if ((upgrades[index] == 0)); then
				upgrade="not run"
			else
				upgrade="${upgrade_results[index]}${upgrade_from[index]:+, from ${upgrade_from[index]}}, ${upgrade_durations[index]} s"
			fi
			echo "| ${versions[index]} | ${releases[index]:-unknown} | ${results[index]} | ${durations[index]} s | ${sizes[index]// /, } | ${upgrade} |"
		done
	} >>"${GITHUB_STEP_SUMMARY}"
fi

# The versions that broke, with their releases and results, on one line, for the issue
# of the weekly run: "Nextcloud 34 (34.0.1, failed), Nextcloud 35 (35.0.1, upgrade
# failed)", and nothing when every version passed; and their major versions alone,
# "34 35", for the command in the issue that runs their tests locally.
if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
	broken=""
	for index in "${!versions[@]}"; do
		problem="$(verdict "${index}")"
		if [[ -n "${problem}" ]]; then
			broken+="${broken:+, }Nextcloud ${versions[index]} (${releases[index]:-unknown release}, ${problem})"
		fi
	done
	echo "failed=${broken}" >>"${GITHUB_OUTPUT}"
	echo "versions=${failed[*]}" >>"${GITHUB_OUTPUT}"
fi

if ((${#failed[@]} > 0)); then
	echo "The end-to-end tests failed or timed out against Nextcloud ${failed[*]}." >&2
	exit 1
fi
upgraded=()
from=""
for index in "${!versions[@]}"; do
	if [[ "${upgrade_results[index]:-}" == passed ]]; then
		upgraded+=("${versions[index]}")
		from="${from:-${upgrade_from[index]}}"
	fi
done
if ((${#upgraded[@]} > 0)); then
	echo "The end-to-end tests passed against Nextcloud ${versions[*]} in ${SECONDS} s, and so did the upgrade from ${from:-the last release} on Nextcloud ${upgraded[*]}."
else
	echo "The end-to-end tests passed against Nextcloud ${versions[*]} in ${SECONDS} s."
fi
