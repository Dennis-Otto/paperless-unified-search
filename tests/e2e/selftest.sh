#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The tests of the scripts around the end-to-end tests, in a few seconds and without
# Docker; scripts/check-project.sh runs them.
#
# - scope.sh, which decides whether the required checks "nextcloud", "paperless (2)"
#   and "paperless (3)" run their tests at all: in throwaway Git repositories, with real
#   merge commits and pushes. A mistake there would let every pull request pass the
#   check without the tests, and the pull request that makes it would still run them,
#   as it changes tests/e2e/.
# - run-all.sh, with a fake docker and a fake run.sh: a version that passes, one that
#   fails and one that hangs, each in a group of its own, the table of the summary,
#   the versions that broke as an output of the step, one download of the browser, the
#   sizes of the search dialog of each version, and the exit status; and with a fake
#   upgrade.sh, the upgrade from the last release beside the tests of the versions that
#   E2E_UPGRADE names, one that passes, fails, hangs or is skipped.
# - package.sh, in a repository of its own: the files that Git tracks, without what a
#   .nextcloudignore leaves out, in its folder and below it, with the content of the
#   checkout and the version given.
# - upgrade.sh up to the start of Nextcloud, with fakes of curl, gh and Docker and a
#   release that openssl signs: the last release, its signature and provenance, a
#   certificate of another app, a release that its signature doesn't match, one without
#   its signature or provenance, and the notice without any release, before Docker.
# - assert_upgrade.py, with answers of its own, when Python is installed: the results
#   of a search, the settings of the page of the administration settings and the keys
#   of the configuration of the app, which upgrade.sh compares before and after.
# - the job of .github/workflows/e2e.yml that writes the issue about the supported
#   Nextcloud versions, with a fake gh: it opens the issue with the versions that
#   broke, adds a further failed run to it and closes it once the tests pass again.
# - mock_server.py, with requests of its own, when Python is installed: its journal
#   holds each request right after its answer, and run.sh reads that journal; and it
#   answers /api/ui_settings/ for the token of a view-only account and of a superuser
#   as Paperless does.
# - assert_log.py, with records of the log of Nextcloud of its own, when Python is
#   installed: it fails on an error of the app, also one that Nextcloud logs for core
#   with a trace through lib/, on a deprecation, notice or warning of PHP in a file of
#   the app and on a warning of the app that no test provokes, and passes the rest.
# - that no script here pipes a command into grep --quiet, which fails the pipe now
#   and then under set -o pipefail.
# - scripts/screenshots.sh --demo, with a fake docker: it records the animation of the
#   running demo of demo/seed.sh as jamie, with the password of demo/.env in the
#   environment of the browser, never on a command line, and leaves the demo and every
#   other picture alone; without the demo it says how to start it.
# - scripts/screenshot-media.sh, with a fake docker: it makes the video and the first
#   frame beside each animation and shrinks each PNG, in the image of
#   .github/screenshots/, with the same bytes in every run; it says so when that image
#   can't be built.
# - demo/seed.sh, with a fake docker that plays the containers of the demo: Paperless
#   Sync runs with occ, which waits until the archive is written, and only then the
#   archive is shared; it waits for a run of the background jobs that is working, gives
#   up after five minutes, and stops at a run with errors.
#
#   bash tests/e2e/selftest.sh

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
failures=0
checks=0

# Git of these tests alone, without the configuration of the machine, such as signed
# commits or hooks.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
# Nor the repository that runs them: Git hands GIT_DIR to its hooks, such as the one
# before a push in a worktree, and an agent sets GIT_DIR and GIT_WORK_TREE for a worktree
# in a container. Every git below would follow them, so that git init rewrote the
# configuration of that repository and the commits of the tests landed on its branch.
# shellcheck disable=SC2046 # Git prints one name of a variable per line.
unset $(git rev-parse --local-env-vars)
export GIT_AUTHOR_NAME=E2E GIT_AUTHOR_EMAIL=e2e@example.com
export GIT_COMMITTER_NAME=E2E GIT_COMMITTER_EMAIL=e2e@example.com
# Not the variables of the run of GitHub Actions that may run these tests.
unset GITHUB_ACTIONS GITHUB_EVENT_NAME GITHUB_OUTPUT GITHUB_STEP_SUMMARY

expect() {
	local what="$1" expected="$2" actual="$3"
	checks=$((checks + 1))
	if [[ "${actual}" != "${expected}" ]]; then
		echo "FAIL: ${what}: expected ${expected}, got ${actual:-nothing}" >&2
		failures=$((failures + 1))
	fi
}

expect_in() {
	local what="$1" text="$2" file="$3"
	checks=$((checks + 1))
	if ! grep --fixed-strings --quiet -- "${text}" "${file}"; then
		echo "FAIL: ${what}: no line with \"${text}\" in:" >&2
		sed 's/^/  | /' "${file}" >&2
		failures=$((failures + 1))
	fi
}

## scope.sh

upstream="${WORK}/upstream"
clone="${WORK}/clone"
git init --quiet --initial-branch=main "${upstream}"
for file in lib/Search.php docs/roadmap.md docs/lib/notes.md tests/e2e/README.md README.md \
	composer.json .github/workflows/e2e.yml .github/workflows/lint.yml; do
	mkdir -p "${upstream}/$(dirname "${file}")"
	echo "first" >"${upstream}/${file}"
done
git -C "${upstream}" add --all
git -C "${upstream}" commit --quiet --message "The base branch"
git clone --quiet "${upstream}" "${clone}"
main="$(git -C "${clone}" rev-parse HEAD)"

# Runs scope.sh in the clone and prints what it decided: true, false or error.
scope() {
	: >"${WORK}/output"
	: >"${WORK}/summary"
	if ! (cd "${clone}" && EVENT="$1" BEFORE="${2-}" GITHUB_OUTPUT="${WORK}/output" \
		GITHUB_STEP_SUMMARY="${WORK}/summary" bash "${SCRIPT_DIR}/scope.sh" >"${WORK}/log" 2>&1); then
		echo error
		return
	fi
	sed -n 's/^run=//p' "${WORK}/output"
}

# A pull request that runs the given command on a branch of the base branch: the
# checkout of GitHub is the merge commit of the branch into the base branch.
pull_request() {
	git -C "${clone}" checkout --quiet -B change "${main}"
	(cd "${clone}" && eval "$1")
	git -C "${clone}" add --all
	git -C "${clone}" commit --quiet --message "The change"
	git -C "${clone}" checkout --quiet --detach "${main}"
	git -C "${clone}" merge --quiet --no-ff --no-edit change
	scope pull_request
}

expect "a pull request of the documentation only" false "$(pull_request 'echo second >docs/roadmap.md')"
expect_in "the notice of a skipped run" "::notice title=End-to-end tests skipped::" "${WORK}/log"
expect_in "the summary of a skipped run" "The check passes without running them." "${WORK}/summary"
expect "a pull request of the app" true "$(pull_request 'echo second >lib/Search.php')"
expect "a pull request of a new file of the app" true "$(pull_request 'mkdir -p templates && echo new >templates/settings.php')"
expect "a pull request of the end-to-end tests" true "$(pull_request 'echo second >tests/e2e/README.md')"
expect "a pull request of composer.json" true "$(pull_request 'echo second >composer.json')"
expect "a pull request of a new composer.lock" true "$(pull_request 'echo new >composer.lock')"
expect "a pull request of the files that the package leaves out" true "$(pull_request 'echo new >.nextcloudignore')"
expect "a pull request of the rules of krankerl" true "$(pull_request 'echo new >krankerl.toml')"
expect "a pull request of a .nextcloudignore in another folder" false "$(pull_request 'mkdir -p docs && echo new >docs/.nextcloudignore')"
expect "a pull request of the workflow of the tests" true "$(pull_request 'echo second >.github/workflows/e2e.yml')"
expect "a pull request of another workflow" false "$(pull_request 'echo second >.github/workflows/lint.yml')"
expect "a pull request of a path that only looks like the app" false "$(pull_request 'echo second >docs/lib/notes.md')"
expect "a pull request that moves a file out of the app" true "$(pull_request 'git mv lib/Search.php docs/Search.php')"
expect "a pull request of the README and the roadmap" false "$(pull_request 'echo second >README.md && echo second >docs/roadmap.md')"

# The contract tests against a real Paperless-ngx cover the code of the app, which
# talks to Paperless and reads its answers in many places, the mock of Paperless, in
# whose container they run, and their own files.
export SCOPE=paperless
expect "a contract run for the requests to Paperless" true "$(pull_request 'mkdir -p lib/Service && echo new >lib/Service/PaperlessApiService.php')"
expect "a contract run for the search" true "$(pull_request 'mkdir -p lib/Search && echo new >lib/Search/Provider.php')"
expect "a contract run for the reading of what the token may do" true "$(pull_request 'mkdir -p lib/Model && echo new >lib/Model/TokenWarning.php')"
expect "a contract run for the names of correspondents, types and tags" true "$(pull_request 'mkdir -p lib/Service && echo new >lib/Service/PaperlessNames.php')"
expect "a contract run for the newest documents of the dashboard" true "$(pull_request 'mkdir -p lib/Service && echo new >lib/Service/RecentDocuments.php')"
expect "a contract run for the test of the connection in the settings" true "$(pull_request 'mkdir -p lib/Controller && echo new >lib/Controller/SettingsController.php')"
expect "a contract run for the commands of occ" true "$(pull_request 'mkdir -p lib/Command && echo new >lib/Command/Check.php')"
expect "a contract run for another file of the app" true "$(pull_request 'echo second >lib/Search.php')"
expect "a contract run for the metadata of the app" true "$(pull_request 'mkdir -p appinfo && echo new >appinfo/info.xml')"
expect "a contract run for the mock of Paperless" true "$(pull_request 'mkdir -p tests/e2e && echo new >tests/e2e/mock_server.py')"
expect "a contract run for a document of the contract" true "$(pull_request 'mkdir -p tests/e2e/contract && echo new >tests/e2e/contract/letter.txt')"
expect "a contract run for the workflow of the tests" true "$(pull_request 'echo second >.github/workflows/e2e.yml')"
expect "a contract run that moves a file out of the app" true "$(pull_request 'git mv lib/Search.php docs/Search.php')"
expect "no contract run for the pages of the app" false "$(pull_request 'mkdir -p templates && echo new >templates/settings.php')"
expect_in "the notice of a skipped contract run" "::notice title=Contract tests skipped::" "${WORK}/log"
expect_in "the summary of a skipped contract run" "The check passes without running them." "${WORK}/summary"
expect "no contract run for the other end-to-end tests" false "$(pull_request 'echo second >tests/e2e/README.md')"
expect "no contract run for composer.json" false "$(pull_request 'echo second >composer.json')"
expect "no contract run for the documentation" false "$(pull_request 'echo second >docs/roadmap.md')"
expect "no contract run for a path that only looks like the app" false "$(pull_request 'echo second >docs/lib/notes.md')"
expect "no contract run for another workflow" false "$(pull_request 'echo second >.github/workflows/lint.yml')"
export SCOPE=unknown
expect "an unknown scope" error "$(pull_request 'echo second >lib/Search.php')"
unset SCOPE

# A push to main: the commit before it is one the remote has.
git -C "${clone}" checkout --quiet --detach "${main}"
echo second >"${clone}/docs/roadmap.md"
git -C "${clone}" commit --quiet --all --message "The documentation"
echo third >"${clone}/README.md"
git -C "${clone}" commit --quiet --all --message "The README"
expect "a push of two commits of the documentation" false "$(scope push "${main}")"
echo second >"${clone}/lib/Search.php"
git -C "${clone}" commit --quiet --all --message "The app"
expect "a push with a commit of the app" true "$(scope push "${main}")"
expect "a push that creates a branch" true "$(scope push 0000000000000000000000000000000000000000)"
expect "a push after a commit that a force push removed" true "$(scope push 1111111111111111111111111111111111111111)"
expect "a push without the commit before it" true "$(scope push "")"
expect "the weekly run" true "$(scope schedule "${main}")"
expect "a run started by hand" true "$(scope workflow_dispatch "${main}")"

## run-all.sh

fake="${WORK}/repository"
mkdir -p "${fake}/appinfo" "${fake}/tests/e2e" "${fake}/bin"
cp "${SCRIPT_DIR}/run-all.sh" "${fake}/tests/e2e/run-all.sh"
cat >"${fake}/appinfo/info.xml" <<'XML'
<info><dependencies><nextcloud min-version="33" max-version="35"/></dependencies></info>
XML
# run-all.sh finds the image of the browser in run.sh.
cat >"${fake}/tests/e2e/run.sh" <<'SCRIPT'
# A fake of run.sh, with PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright:v1.0.0-noble
case "${NEXTCLOUD_IMAGE}" in
	nextcloud:33-apache) echo "Log of 33 on port ${E2E_PORT}, project ${E2E_PROJECT_NAME}, sizes ${E2E_FLOW_SIZES}" ;;
	nextcloud:34-apache) echo "Log of 34, sizes ${E2E_FLOW_SIZES}" && exit 1 ;;
	nextcloud:36-apache) echo "Log of 36, sizes ${E2E_FLOW_SIZES}" ;;
	*)
		trap 'echo "Log of 35: stopped" && exit 143' TERM
		echo "Log of 35: hangs"
		sleep 60 &
		wait
		;;
esac
SCRIPT
# A fake of upgrade.sh, which passes from the release v9.8.7, or fails, hangs or skips
# for the versions of FAKE_UPGRADE_FAIL, FAKE_UPGRADE_HANG and FAKE_UPGRADE_SKIP.
cat >"${fake}/tests/e2e/upgrade.sh" <<'SCRIPT'
version="${NEXTCLOUD_IMAGE#nextcloud:}"
version="${version%-apache}"
echo "Upgrade log of ${version} on port ${E2E_PORT}, project ${E2E_PROJECT_NAME}"
if [[ " ${FAKE_UPGRADE_SKIP:-} " == *" ${version} "* ]]; then
	echo "The upgrade test is skipped: o/r has no release yet to upgrade from."
	exit 0
fi
echo "The release to upgrade from: v9.8.7"
if [[ " ${FAKE_UPGRADE_FAIL:-} " == *" ${version} "* ]]; then
	exit 1
fi
if [[ " ${FAKE_UPGRADE_HANG:-} " == *" ${version} "* ]]; then
	trap 'echo "Upgrade log of ${version}: stopped" && exit 143' TERM
	sleep 60 &
	wait
fi
SCRIPT
cat >"${fake}/bin/docker" <<'SCRIPT'
#!/usr/bin/env bash
echo "$*" >>"${FAKE_DOCKER_LOG}"
if [[ "$1 $2" == "image inspect" ]]; then
	image="${*: -1}"
	version="${image#nextcloud:}"
	version="${version%-apache}"
	# As when the download of the image of a version failed.
	if [[ " ${FAKE_NO_IMAGE:-} " == *" ${version} "* ]]; then
		echo "Error: No such image: ${image}" >&2
		exit 1
	fi
	echo "NEXTCLOUD_VERSION=${version}.0.1"
fi
SCRIPT
chmod +x "${fake}/bin/docker"

# Runs run-all.sh as in the CI and prints its exit status.
run_all() {
	local status=0
	: >"${WORK}/docker.log"
	: >"${WORK}/summary"
	: >"${WORK}/output"
	(cd "${fake}" && GITHUB_ACTIONS=true GITHUB_STEP_SUMMARY="${WORK}/summary" GITHUB_OUTPUT="${WORK}/output" \
		DOCKER_BIN="${fake}/bin/docker" FAKE_DOCKER_LOG="${WORK}/docker.log" \
		E2E_VERSION_TIMEOUT=2 "$@" bash tests/e2e/run-all.sh >"${WORK}/log" 2>&1) || status=$?
	echo "${status}"
}

if timeout --version >/dev/null 2>&1; then
	expect "run-all.sh with a version that fails and one that hangs" 1 "$(run_all env)"
	expect_in "the group of the version that hangs" "::group::Nextcloud 35 (timed out, " "${WORK}/log"
	expect_in "the log of the version that hangs" "Log of 35: stopped" "${WORK}/log"
	expect_in "the error of the version that hangs" "::error title=Nextcloud 35::The end-to-end tests against Nextcloud 35 took longer than 2 s" "${WORK}/log"
	expect_in "the summary of the version that hangs" "| 35 | 35.0.1 | timed out |" "${WORK}/summary"
	expect "the versions that broke" "failed=Nextcloud 34 (34.0.1, failed), Nextcloud 35 (35.0.1, timed out)|versions=34 35" \
		"$(paste -s -d "|" "${WORK}/output")"
else
	echo "timeout of GNU coreutils is not installed here; the CI tests a version that hangs."
	expect "run-all.sh with a version that fails" 1 "$(run_all env E2E_VERSIONS="33 34")"
	expect "the versions that broke" "failed=Nextcloud 34 (34.0.1, failed)|versions=34" "$(paste -s -d "|" "${WORK}/output")"
fi
expect_in "the group of the version that passes" "::group::Nextcloud 33 (passed, " "${WORK}/log"
expect_in "the log of the version that passes" "Log of 33 on port 18033, project pus_e2e_33, sizes laptop phone" "${WORK}/log"
expect_in "the sizes of the search dialog without E2E_PHONE" " s | laptop, phone |" "${WORK}/summary"
expect "the sizes of every version without E2E_PHONE" 0 "$(grep --count --fixed-strings '| laptop |' "${WORK}/summary" || true)"
expect_in "the group of the version that fails" "::group::Nextcloud 34 (failed, " "${WORK}/log"
expect_in "the error of the version that fails" "::error title=Nextcloud 34::The end-to-end tests failed against Nextcloud 34" "${WORK}/log"
expect_in "the summary of the version that passes" "| 33 | 33.0.1 | passed |" "${WORK}/summary"
expect_in "the summary of the version that fails" "| 34 | 34.0.1 | failed |" "${WORK}/summary"
expect "the downloads of the browser" 1 "$(grep --count --fixed-strings 'pull --quiet mcr.microsoft.com/playwright:v1.0.0-noble' "${WORK}/docker.log")"
expect "the download of the browser after those of Nextcloud" "pull --quiet mcr.microsoft.com/playwright:v1.0.0-noble" \
	"$(grep --fixed-strings 'pull --quiet' "${WORK}/docker.log" | tail -n 1)"

expect "run-all.sh with one version that passes" 0 "$(run_all env E2E_VERSIONS=33)"
expect_in "the result of one version" "The end-to-end tests passed against Nextcloud 33 in " "${WORK}/log"
expect "the versions that broke when none did" "failed=|versions=" "$(paste -s -d "|" "${WORK}/output")"
# A pull request: the phone only with the newest version, whatever the order of the versions.
expect "run-all.sh with the phone of the newest version" 1 "$(run_all env E2E_VERSIONS="34 33" E2E_PHONE=newest)"
expect_in "the sizes of an older version with E2E_PHONE=newest" "Log of 33 on port 18033, project pus_e2e_33, sizes laptop" "${WORK}/log"
expect "the phone of an older version with E2E_PHONE=newest" 0 "$(grep --count --fixed-strings 'Log of 33 on port 18033, project pus_e2e_33, sizes laptop phone' "${WORK}/log" || true)"
expect_in "the sizes of the newest version with E2E_PHONE=newest" "Log of 34, sizes laptop phone" "${WORK}/log"
expect_in "the note about the phone" "The search dialog runs on a phone only against Nextcloud 34" "${WORK}/log"
expect_in "the summary of an older version with E2E_PHONE=newest" " s | laptop |" "${WORK}/summary"
expect_in "the summary of the newest version with E2E_PHONE=newest" " s | laptop, phone |" "${WORK}/summary"
expect "run-all.sh with the phone of the only version" 0 "$(run_all env E2E_VERSIONS=33 E2E_PHONE=newest)"
expect_in "the sizes of the only version with E2E_PHONE=newest" "sizes laptop phone" "${WORK}/log"
expect "run-all.sh with the phone of every version" 0 "$(run_all env E2E_VERSIONS=33 E2E_PHONE=all)"
expect_in "the sizes with E2E_PHONE=all" "sizes laptop phone" "${WORK}/log"
expect "run-all.sh with an E2E_PHONE that isn't one" 1 "$(run_all env E2E_VERSIONS=33 E2E_PHONE=oldest)"
expect_in "the message about an E2E_PHONE that isn't one" "E2E_PHONE is oldest, but must be all" "${WORK}/log"
# The required check sets E2E_PHONE=newest for a pull request alone.
expect "E2E_PHONE of the required check" 1 \
	"$(grep --count --fixed-strings "E2E_PHONE: \${{ github.event_name == 'pull_request' && 'newest' || 'all' }}" "${SCRIPT_DIR}/../../.github/workflows/e2e.yml")"
expect "run-all.sh with a version whose image is missing" 1 "$(run_all env E2E_VERSIONS="33 34" FAKE_NO_IMAGE=34)"
expect_in "the summary of a version whose image is missing" "| 34 | unknown | failed |" "${WORK}/summary"
expect "the version that broke, whose image is missing" "failed=Nextcloud 34 (unknown release, failed)|versions=34" \
	"$(paste -s -d "|" "${WORK}/output")"
expect "run-all.sh with a version that isn't one" 1 "$(run_all env E2E_VERSIONS="33,next")"
expect_in "the message about a version that isn't one" "next is no major version of Nextcloud" "${WORK}/log"
expect "the versions that broke when the tests didn't start" "" "$(cat "${WORK}/output")"
expect "run-all.sh with a deadline that isn't one" 1 "$(run_all env E2E_VERSIONS=33 E2E_VERSION_TIMEOUT=0)"

# The upgrade from the last release (upgrade.sh), beside the tests of each version: with
# every version by default, in a project and on a port of its own, in a group of its own.
expect "run-all.sh with the upgrade of every version" 0 "$(run_all env E2E_VERSIONS="33 36")"
expect_in "the upgrade of a version" "Upgrade log of 33 on port 19033, project pus_upgrade_33" "${WORK}/log"
expect_in "the upgrade of the other version" "Upgrade log of 36 on port 19036, project pus_upgrade_36" "${WORK}/log"
expect_in "the group of an upgrade" "::group::Upgrade on Nextcloud 33 (passed, " "${WORK}/log"
expect_in "the summary of an upgrade" "| 33 | 33.0.1 | passed | " "${WORK}/summary"
expect_in "the upgrade in the summary" " s | laptop, phone | passed, from v9.8.7, " "${WORK}/summary"
expect_in "the result of the upgrade" "and so did the upgrade from v9.8.7 on Nextcloud 33 36." "${WORK}/log"
# A pull request: the upgrade only with the newest version.
expect "run-all.sh with the upgrade of the newest version" 0 "$(run_all env E2E_VERSIONS="36 33" E2E_UPGRADE=newest)"
expect_in "the upgrade of the newest version with E2E_UPGRADE=newest" "Upgrade log of 36 on port 19036" "${WORK}/log"
expect "the upgrade of an older version with E2E_UPGRADE=newest" 0 "$(grep --count --fixed-strings 'Upgrade log of 33' "${WORK}/log" || true)"
expect_in "the note about the upgrade" "The upgrade from the last release runs only against Nextcloud 36 (E2E_UPGRADE=newest)." "${WORK}/log"
expect_in "the summary of a version without the upgrade" "| 33 | 33.0.1 | passed | " "${WORK}/summary"
expect_in "the upgrade that doesn't run in the summary" " | not run |" "${WORK}/summary"
expect "run-all.sh without the upgrade" 0 "$(run_all env E2E_VERSIONS=33 E2E_UPGRADE=none)"
expect "the upgrades with E2E_UPGRADE=none" 0 "$(grep --count --fixed-strings 'Upgrade log of' "${WORK}/log" || true)"
expect_in "the result without the upgrade" "The end-to-end tests passed against Nextcloud 33 in " "${WORK}/log"
expect "run-all.sh with an E2E_UPGRADE that isn't one" 1 "$(run_all env E2E_VERSIONS=33 E2E_UPGRADE=oldest)"
expect_in "the message about an E2E_UPGRADE that isn't one" "E2E_UPGRADE is oldest, but must be all" "${WORK}/log"
# A failed upgrade fails its version, also when its tests pass.
expect "run-all.sh with an upgrade that fails" 1 "$(run_all env E2E_VERSIONS="33 36" FAKE_UPGRADE_FAIL=33)"
expect_in "the error of the upgrade that fails" "::error title=Upgrade on Nextcloud 33::The upgrade from the last release failed on Nextcloud 33" "${WORK}/log"
expect_in "the summary of the upgrade that fails" " | failed, from v9.8.7, " "${WORK}/summary"
expect "the version whose upgrade broke" "failed=Nextcloud 33 (33.0.1, upgrade failed)|versions=33" "$(paste -s -d "|" "${WORK}/output")"
# Before the first release, upgrade.sh skips the upgrade, which passes.
expect "run-all.sh with an upgrade that upgrade.sh skips" 0 "$(run_all env E2E_VERSIONS=33 FAKE_UPGRADE_SKIP=33)"
expect_in "the summary of the upgrade that upgrade.sh skips" " | skipped, " "${WORK}/summary"
expect "the result of the upgrade that upgrade.sh skips" 0 "$(grep --count --fixed-strings 'and so did the upgrade' "${WORK}/log" || true)"
if timeout --version >/dev/null 2>&1; then
	expect "run-all.sh with an upgrade that hangs" 1 "$(run_all env E2E_VERSIONS="33 34" FAKE_UPGRADE_HANG=33)"
	expect_in "the log of the upgrade that hangs" "Upgrade log of 33: stopped" "${WORK}/log"
	expect_in "the error of the upgrade that hangs" "::error title=Upgrade on Nextcloud 33::The upgrade from the last release on Nextcloud 33 took longer than 2 s" "${WORK}/log"
	expect "the versions whose tests or upgrade broke" "failed=Nextcloud 33 (33.0.1, upgrade timed out), Nextcloud 34 (34.0.1, failed)|versions=33 34" \
		"$(paste -s -d "|" "${WORK}/output")"
fi
# The required check sets E2E_UPGRADE=newest for a pull request alone.
expect "E2E_UPGRADE of the required check" 1 \
	"$(grep --count --fixed-strings "E2E_UPGRADE: \${{ github.event_name == 'pull_request' && 'newest' || 'all' }}" "${SCRIPT_DIR}/../../.github/workflows/e2e.yml")"

## package.sh and upgrade.sh up to Docker

# package.sh packs the files that Git tracks, without what a .nextcloudignore leaves
# out, in its folder and below it, as krankerl does, with the content of the checkout,
# and the version that upgrade.sh gives it.
app="${WORK}/app"
mkdir -p "${app}/appinfo" "${app}/lib" "${app}/docs" "${app}/demo" "${app}/tests/e2e"
cp "${SCRIPT_DIR}/package.sh" "${SCRIPT_DIR}/upgrade.sh" "${app}/tests/e2e/"
cat >"${app}/appinfo/info.xml" <<'XML'
<info>
	<id>paperless_unified_search</id>
	<version>1.0.0</version>
	<repository type="git">https://github.com/o/r.git</repository>
</info>
XML
printf '<?php\n' >"${app}/lib/Kept.php"
printf '<?php\n' >"${app}/lib/Left.php"
printf 'Committed.\n' >"${app}/lib/changed.txt"
printf 'Deleted.\n' >"${app}/lib/deleted.txt"
printf '# The documentation.\n' >"${app}/docs/index.md"
printf '*\n' >"${app}/demo/.nextcloudignore"
printf 'services: {}\n' >"${app}/demo/compose.yaml"
printf '/docs\n/tests\n/lib/Left.php\n.nextcloudignore\n' >"${app}/.nextcloudignore"
git init --quiet "${app}"
git -C "${app}" add --all
git -C "${app}" commit --quiet --message "The app"
printf 'Not yet committed.\n' >"${app}/lib/changed.txt"
rm "${app}/lib/deleted.txt"
printf 'Untracked.\n' >"${app}/lib/untracked.txt"
package_status=0
bash "${app}/tests/e2e/package.sh" "${WORK}/package.tar.gz" 1.0.1 >"${WORK}/package.log" 2>&1 || package_status=$?
expect "package.sh" 0 "${package_status}"
expect "the files of the package" "paperless_unified_search/appinfo/info.xml paperless_unified_search/lib/Kept.php paperless_unified_search/lib/changed.txt" \
	"$(tar -tzf "${WORK}/package.tar.gz" | grep --invert-match '/$' | LC_ALL=C sort | paste -s -d ' ')"
expect "a change not yet committed in the package" "Not yet committed." \
	"$(tar -xzOf "${WORK}/package.tar.gz" paperless_unified_search/lib/changed.txt)"
tar -xzOf "${WORK}/package.tar.gz" paperless_unified_search/appinfo/info.xml >"${WORK}/info.xml"
expect_in "the version of the package" "<version>1.0.1</version>" "${WORK}/info.xml"
expect_in "the files that package.sh names" "Packed 3 files of the checkout as version 1.0.1 into " "${WORK}/package.log"
package_status=0
bash "${app}/tests/e2e/package.sh" "${WORK}/package.tar.gz" next >"${WORK}/package.log" 2>&1 || package_status=$?
expect "package.sh with a version that isn't one" 2 "${package_status}"
expect_in "the message about a version that isn't one" "next is no version of the app" "${WORK}/package.log"

# upgrade.sh up to Docker, with fakes of curl and gh in front of PATH: a release whose
# signature openssl makes with a certificate of its own, served for every URL by the end
# of the URL, and a fake Docker that stops it at the start of Nextcloud.
if command -v openssl >/dev/null 2>&1; then
	release="${WORK}/release"
	fakes="${WORK}/upgrade-bin"
	mkdir -p "${release}" "${fakes}"
	cat >"${fakes}/curl" <<'SCRIPT'
#!/usr/bin/env bash
url="${*: -1}"
output=""
redirect=0
while (($# > 0)); do
	case "$1" in
		--output) output="$2" && shift ;;
		--write-out) redirect=1 && shift ;;
	esac
	shift
done
echo "curl ${url}" >>"${FAKE_DOCKER_LOG}"
if ((redirect == 1)); then
	printf '%s %s' "${FAKE_LATEST_STATUS:-302}" "${FAKE_LATEST:-}"
	exit 0
fi
if [[ ! -f "${FAKE_RELEASE}/${url##*/}" ]]; then
	echo "curl: (22) The requested URL returned error: 404" >&2
	exit 22
fi
cp "${FAKE_RELEASE}/${url##*/}" "${output}"
SCRIPT
	cat >"${fakes}/gh" <<'SCRIPT'
#!/usr/bin/env bash
echo "gh $*" >>"${FAKE_DOCKER_LOG}"
exit "${FAKE_GH_STATUS:-0}"
SCRIPT
	cat >"${fakes}/docker" <<'SCRIPT'
#!/usr/bin/env bash
echo "docker $*" >>"${FAKE_DOCKER_LOG}"
if [[ " $* " == *" up "* ]]; then
	echo "The fake Docker starts no Nextcloud." >&2
	exit 1
fi
SCRIPT
	chmod +x "${fakes}/curl" "${fakes}/gh" "${fakes}/docker"
	# The certificate of the app, and one of another app; Git Bash would take /CN= for a path.
	certificate() {
		MSYS2_ARG_CONV_EXCL='/CN=' openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$1" \
			-keyout "${WORK}/$1.key" -out "${WORK}/$1.crt" >/dev/null 2>&1
	}
	certificate paperless_unified_search
	certificate another_app
	# The release, which supports Nextcloud 33 to 35.
	mkdir -p "${WORK}/packed/paperless_unified_search/appinfo"
	printf '<info>\n\t<dependencies>\n\t\t<nextcloud min-version="33" max-version="35"/>\n\t</dependencies>\n</info>\n' \
		>"${WORK}/packed/paperless_unified_search/appinfo/info.xml"
	tar -C "${WORK}/packed" -czf - paperless_unified_search >"${release}/paperless_unified_search.tar.gz"
	openssl dgst -sha512 -sign "${WORK}/paperless_unified_search.key" \
		-out "${release}/paperless_unified_search.tar.gz.sig" "${release}/paperless_unified_search.tar.gz"
	cp "${WORK}/paperless_unified_search.crt" "${release}/paperless_unified_search.crt"
	printf '{}\n' >"${release}/provenance.sigstore.json"
	# Runs upgrade.sh with the fakes and prints its exit status.
	upgrade() {
		local status=0
		: >"${WORK}/docker.log"
		(cd "${app}" && PATH="${fakes}:${PATH}" DOCKER_BIN="${fakes}/docker" FAKE_DOCKER_LOG="${WORK}/docker.log" \
			FAKE_RELEASE="${release}" FAKE_LATEST="https://github.com/o/r/releases/tag/v1.0.0" \
			NEXTCLOUD_IMAGE=nextcloud:35-apache "$@" bash tests/e2e/upgrade.sh >"${WORK}/log" 2>&1) || status=$?
		echo "${status}"
	}

	expect "upgrade.sh up to Docker" 1 "$(upgrade env)"
	expect_in "the release that upgrade.sh upgrades from" "The release to upgrade from: v1.0.0" "${WORK}/log"
	expect_in "the next patch version, as the checkout names the version of the release" "its package upgrades the release to 1.0.1." "${WORK}/log"
	expect_in "the signature of the release" "The signature of paperless_unified_search.tar.gz matches the certificate of the app at the App Store." "${WORK}/log"
	expect_in "the provenance of the release" "/paperless_unified_search.tar.gz --repo o/r --signer-workflow o/r/.github/workflows/build-release.yml --bundle " "${WORK}/docker.log"
	expect_in "the package of the checkout" "Packed 3 files of the checkout as version 1.0.1 into " "${WORK}/log"
	expect_in "the start of Nextcloud after the checks" "docker compose --project-name paperless_unified_search_upgrade --file ${app}/tests/e2e/compose.yaml up " "${WORK}/docker.log"
	expect "upgrade.sh with a checkout newer than the release" 1 "$(upgrade env E2E_UPGRADE_FROM=v0.9.0)"
	expect_in "the version of the checkout, newer than the release" "its package upgrades the release to 1.0.0." "${WORK}/log"
	expect "the lookup of the last release with E2E_UPGRADE_FROM" 0 "$(grep --count --fixed-strings 'releases/latest' "${WORK}/docker.log" || true)"
	expect "upgrade.sh without a release" 0 "$(upgrade env FAKE_LATEST=https://github.com/o/r/releases)"
	expect_in "the notice without a release" "The upgrade test is skipped: o/r has no release yet to upgrade from." "${WORK}/log"
	expect "Docker without a release" 0 "$(grep --count '^docker ' "${WORK}/docker.log" || true)"
	# A lookup that GitHub refuses, such as with a 429, fails rather than skip the test.
	expect "upgrade.sh when GitHub refuses the lookup of the last release" 1 "$(upgrade env FAKE_LATEST_STATUS=429 FAKE_LATEST=)"
	expect_in "the message about the refused lookup" "GitHub answered the request for the last release of o/r with HTTP 429, not with a redirect" "${WORK}/log"
	expect "upgrade.sh with a redirect elsewhere" 1 "$(upgrade env FAKE_LATEST=https://github.com/login)"
	expect "Docker when GitHub refuses the lookup" 0 "$(grep --count '^docker ' "${WORK}/docker.log" || true)"
	# A Nextcloud that the release doesn't support, as the pull request of the upstream
	# bot for a new version meets: app:enable would refuse the release, so the test skips.
	expect "upgrade.sh on a Nextcloud newer than the release supports" 0 "$(upgrade env NEXTCLOUD_IMAGE=nextcloud:36-apache)"
	expect_in "the notice about a newer Nextcloud" "The upgrade test is skipped: v1.0.0 supports Nextcloud 33 to 35, not 36." "${WORK}/log"
	expect "Docker on a Nextcloud newer than the release supports" 0 "$(grep --count '^docker ' "${WORK}/docker.log" || true)"
	expect "upgrade.sh on a Nextcloud older than the release supports" 0 "$(upgrade env NEXTCLOUD_IMAGE=nextcloud:32.0.1-apache)"
	expect_in "the notice about an older Nextcloud" "supports Nextcloud 33 to 35, not 32." "${WORK}/log"
	expect "upgrade.sh with an image without a version" 1 "$(upgrade env NEXTCLOUD_IMAGE=nextcloud:apache)"
	expect_in "the message about an image without a version" "The image nextcloud:apache names no major version of Nextcloud" "${WORK}/log"
	# Without NEXTCLOUD_IMAGE, the image of compose.yaml, pinned by its digest.
	# shellcheck disable=SC2016 # the text of compose.yaml, with its variable unexpanded
	printf 'services:\n  nextcloud:\n    image: ${NEXTCLOUD_IMAGE:-nextcloud:34.0.2-apache@sha256:0123}\n' >"${app}/tests/e2e/compose.yaml"
	expect "upgrade.sh with the image of compose.yaml" 1 "$(upgrade env NEXTCLOUD_IMAGE=)"
	expect_in "the major version of the image of compose.yaml" "v1.0.0 supports Nextcloud 33 to 35, 34 among them." "${WORK}/log"
	rm "${app}/tests/e2e/compose.yaml"
	expect "upgrade.sh with a tag that isn't one" 1 "$(upgrade env E2E_UPGRADE_FROM=latest)"
	expect_in "the message about a tag that isn't one" "latest is no tag of a release, such as v1.1.1." "${WORK}/log"
	expect "upgrade.sh with the provenance refused" 1 "$(upgrade env FAKE_GH_STATUS=1)"
	expect_in "the message about the provenance" "paperless_unified_search.tar.gz of v1.0.0 has no valid provenance of build-release.yml of o/r." "${WORK}/log"
	expect "Docker with the provenance refused" 0 "$(grep --count '^docker ' "${WORK}/docker.log" || true)"
	mv "${release}/provenance.sigstore.json" "${WORK}/provenance.sigstore.json"
	expect "upgrade.sh with a release without provenance" 1 "$(upgrade env)"
	expect_in "the notice about a release without provenance" "The release v1.0.0 has no provenance.sigstore.json" "${WORK}/log"
	mv "${WORK}/provenance.sigstore.json" "${release}/provenance.sigstore.json"
	cp "${WORK}/another_app.crt" "${release}/paperless_unified_search.crt"
	expect "upgrade.sh with the certificate of another app" 1 "$(upgrade env)"
	expect_in "the message about the certificate of another app" "is not the one of the app paperless_unified_search" "${WORK}/log"
	cp "${WORK}/paperless_unified_search.crt" "${release}/paperless_unified_search.crt"
	printf 'Changed.\n' >>"${release}/paperless_unified_search.tar.gz"
	expect "upgrade.sh with a release that its signature doesn't match" 1 "$(upgrade env)"
	expect_in "the message about the signature" "The signature of paperless_unified_search.tar.gz of v1.0.0 doesn't match the certificate of the app at the App Store." "${WORK}/log"
	expect "Docker with a release that its signature doesn't match" 0 "$(grep --count '^docker ' "${WORK}/docker.log" || true)"
	rm "${release}/paperless_unified_search.tar.gz.sig"
	expect "upgrade.sh with a release without its signature" 1 "$(upgrade env)"
	expect_in "the message about the missing signature" "The release v1.0.0 of o/r has no asset paperless_unified_search.tar.gz.sig" "${WORK}/log"
else
	echo "openssl is not installed here; the CI tests upgrade.sh up to Docker."
fi

## The issue about the supported Nextcloud versions

# The script of the step "Open, update or close the issue" of the job of e2e.yml that
# the argument names, as GitHub runs it: the lines of the block of its run:, without
# the indentation of the block.
workflow_script() {
	awk -v job="  $1:" '
		$0 == job { inside = 1; next }
		inside && /^  [^ #]/ { exit }
		inside && !block && /^ +run: \|$/ { block = 1; next }
		block {
			if ($0 ~ /^ *$/) { print ""; next }
			match($0, /^ */)
			if (!indent) { indent = RLENGTH }
			if (RLENGTH < indent) { exit }
			print substr($0, indent + 1)
		}
	' "${SCRIPT_DIR}/../../.github/workflows/e2e.yml"
}

workflow_script nextcloud-issue >"${WORK}/issue.sh"
expect "the script of the job nextcloud-issue of e2e.yml" true \
	"$(grep --quiet --fixed-strings 'gh issue create' "${WORK}/issue.sh" && echo true || echo false)"
mkdir -p "${WORK}/runner"
cat >"${fake}/bin/gh" <<'SCRIPT'
#!/usr/bin/env bash
# A fake of the GitHub CLI: it notes each call and lists FAKE_ISSUE as the open issue.
echo "$*" >>"${FAKE_GH_LOG}"
if [[ "$1 $2" == "issue list" ]]; then
	echo "${FAKE_ISSUE:-}"
fi
SCRIPT
chmod +x "${fake}/bin/gh"

# Runs the script of the job with a fake gh, with the environment that the job gives
# it and the result of the check nextcloud as the arguments, and prints its exit status.
report() {
	local status=0
	: >"${WORK}/gh.log"
	rm -f "${WORK}/runner/issue.md"
	(PATH="${fake}/bin:${PATH}" FAKE_GH_LOG="${WORK}/gh.log" RUNNER_TEMP="${WORK}/runner" \
		GH_TOKEN=fake GH_REPO=Dennis-Otto/paperless-unified-search \
		TITLE="A supported Nextcloud release breaks the app" RUN_URL=https://github.com/run/7 FAILED="" VERSIONS="" \
		"$@" bash "${WORK}/issue.sh" >"${WORK}/log" 2>&1) || status=$?
	echo "${status}"
}

broken="Nextcloud 34 (34.0.1, failed), Nextcloud 35 (35.0.1, timed out)"
expect "a failed run without an open issue" 0 "$(report env RESULT=failure FAILED="${broken}" VERSIONS="34 35")"
expect_in "the search for the open issue" \
	"issue list --state open --search in:title \"A supported Nextcloud release breaks the app\"" "${WORK}/gh.log"
expect_in "the new issue" "issue create --title A supported Nextcloud release breaks the app --body-file ${WORK}/runner/issue.md" \
	"${WORK}/gh.log"
expect_in "the run and the versions that broke in the new issue" \
	"The weekly end-to-end tests fail against ${broken}: https://github.com/run/7" "${WORK}/runner/issue.md"
expect_in "the command for the versions in the new issue" 'E2E_VERSIONS="34 35" bash tests/e2e/run-all.sh' "${WORK}/runner/issue.md"
expect_in "the paragraph after the command in the new issue" "The job adds every further failed run here" "${WORK}/runner/issue.md"
expect "a failed run with an open issue" 0 "$(report env RESULT=failure FAILED="${broken}" FAKE_ISSUE=12)"
expect "the comment on the open issue" \
	"issue comment 12 --body The end-to-end tests still fail against ${broken}: https://github.com/run/7" \
	"$(tail -n 1 "${WORK}/gh.log")"
expect "a run that ran out of time" 0 "$(report env RESULT=cancelled)"
expect_in "the new issue about a run that ran out of time" \
	"The weekly end-to-end tests run out of time or are cancelled before they end: https://github.com/run/7" \
	"${WORK}/runner/issue.md"
expect "a run that stopped before the tests" 0 "$(report env RESULT=failure)"
expect_in "the new issue about a run that stopped before the tests" \
	"The weekly end-to-end tests stop before they test a version of Nextcloud: https://github.com/run/7" \
	"${WORK}/runner/issue.md"
expect_in "the command without versions in the new issue" "\`bash tests/e2e/run-all.sh\` runs the same tests locally" "${WORK}/runner/issue.md"
expect "a run that passes with an open issue" 0 "$(report env RESULT=success FAKE_ISSUE=12)"
expect "the issue closed by a run that passes" \
	"issue close 12 --reason completed --comment The end-to-end tests pass again against every supported Nextcloud version: https://github.com/run/7" \
	"$(tail -n 1 "${WORK}/gh.log")"
expect "a run that passes without an open issue" 0 "$(report env RESULT=success)"
expect "the calls of gh of a run that passes without an open issue" 1 "$(wc -l <"${WORK}/gh.log" | tr -d ' ')"

## The journal of the mock

# run.sh checks which requests reached the mock right after the answer of Nextcloud,
# such as the date filter of a search. Docker copies the output of a container into its
# log a moment later, and the mock writes each request into its journal before its
# answer leaves, so the journal already holds the request that the answer is for (#94).
python=""
for candidate in python3 python py; do
	if "${candidate}" -c 'import sys; sys.exit(sys.version_info < (3, 9))' >/dev/null 2>&1; then
		python="${candidate}"
		break
	fi
done
if [[ -n "${python}" ]]; then
	# Prints the file that the mock writes its journal to, and then the journal right
	# after each answer: one with documents and one to a wrong token; then what the mock
	# says of the account of each of its tokens. Python on Windows ends its lines with
	# CR LF. -B leaves the checkout without a cache of bytecode.
	"${python}" -B - "${SCRIPT_DIR}" "${WORK}/requests.log" 2>&1 <<'PYTHON' | tr -d '\r' >"${WORK}/journal" || true
import json
import sys
import threading
import urllib.error
import urllib.request
from http.server import ThreadingHTTPServer

sys.path.insert(0, sys.argv[1])
import mock_server  # noqa: E402

print(mock_server.REQUESTS)
mock_server.REQUESTS = sys.argv[2]
open(mock_server.REQUESTS, "w").close()


class Quiet(mock_server.Handler):
    def log_message(self, message, *args):
        pass


server = ThreadingHTTPServer(("127.0.0.1", 0), Quiet)
threading.Thread(target=server.serve_forever, daemon=True).start()
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
for path, token in (
    ("/api/documents/?query=mobiletest&fields=id&created__date__gte=2026-08-01", mock_server.TOKEN),
    ("/api/documents/?query=mobiletest&fields=id", "wrong"),
):
    request = urllib.request.Request(
        f"http://127.0.0.1:{server.server_port}{path}", headers={"Authorization": f"Token {token}"}
    )
    try:
        opener.open(request).read()
    except urllib.error.HTTPError as error:
        error.read()
    with open(mock_server.REQUESTS, encoding="utf-8") as journal:
        print(journal.read(), end="")
for token in (mock_server.TOKEN, mock_server.SUPERUSER_TOKEN):
    request = urllib.request.Request(
        f"http://127.0.0.1:{server.server_port}/api/ui_settings/", headers={"Authorization": f"Token {token}"}
    )
    answer = json.loads(opener.open(request).read())
    print(f"account superuser={answer['user']['is_superuser']} staff={answer['user']['is_staff']} {','.join(answer['permissions'])}")
server.shutdown()
PYTHON
	journal="$(head -n 1 "${WORK}/journal")"
	expect "the journal of the mock in its tmpfs" /tmp/requests.log "${journal}"
	expect "run.sh reads the journal of the mock" 1 "$(grep --count --fixed-strings "cat ${journal}" "${SCRIPT_DIR}/run.sh")"
	expect "the journal of the mock right after each answer" \
		"GET /api/documents/?query=mobiletest&fields=id&created__date__gte=2026-08-01 HTTP/1.1 200|GET /api/documents/?query=mobiletest&fields=id&created__date__gte=2026-08-01 HTTP/1.1 200|GET /api/documents/?query=mobiletest&fields=id HTTP/1.1 401" \
		"$(tail -n +2 "${WORK}/journal" | grep --invert-match '^account ' | paste -s -d "|")"
	expect "the accounts of the tokens of the mock, as /api/ui_settings/ names them" \
		"account superuser=False staff=False view_document,view_correspondent,view_documenttype,view_tag,view_uisettings|account superuser=True staff=True add_document,change_document,delete_document,view_document,view_correspondent,view_documenttype,view_tag,view_uisettings" \
		"$(grep '^account ' "${WORK}/journal" | paste -s -d "|")"
else
	echo "Python 3.9 or newer is not installed here; the CI tests the journal of the mock."
fi

## The check of the log of Nextcloud

# run.sh sets log.condition of Nextcloud to {"apps":["PHP","paperless_unified_search"]},
# so that Nextcloud logs every record of PHP and of the app at debug and everything else
# at its default level, warning, and pipes the log into assert_log.py at its end, which fails on an error of the app, a deprecation, notice or warning of PHP in
# a file of the app, and a warning of the app that no test provokes. A record of the app
# is one that the app wrote or whose message, exception or data names a file of the app
# or its namespace: Nextcloud logs an uncaught exception for the app index, core or PHP,
# with the trace through lib/ (#237). The records below are as Nextcloud writes them.
# Runs assert_log.py on the records given, one per line, prints its exit status, and
# keeps its output in ${WORK}/log-check.
log_check() {
	local status=0
	printf '%s\n' "$@" | "${python}" -B "${SCRIPT_DIR}/assert_log.py" 2>&1 | tr -d '\r' >"${WORK}/log-check" || status=$?
	echo "${status}"
}
if [[ -n "${python}" ]]; then
	app_dir=/var/www/html/custom_apps/paperless_unified_search
	# What passes: an error of core whose arguments, but no frame, name a file of the app,
	# a deprecation of PHP in core, a warning of core during a request to a route of the
	# app, an info and a debug message of the app, the two warnings that run.sh provokes,
	# one of them the warning of the cache of AppConfig that core writes for the app, and
	# lines that hold no record.
	fine=(
		'{"level":3,"app":"core","message":"The database went away","method":"GET","url":"/index.php/apps/files","exception":{"Exception":"RuntimeException","Message":"The database went away","Code":0,"File":"/var/www/html/lib/private/DB/Connection.php","Line":12,"Trace":[{"file":"/var/www/html/lib/private/AppFramework/App.php","line":3,"function":"run","args":["'"${app_dir}"'/appinfo/info.xml"]}]}}'
		'{"level":0,"app":"PHP","message":"Constant E_STRICT is deprecated at /var/www/html/lib/base.php#12"}'
		'{"level":2,"app":"core","message":"Login failed: e2e-other","url":"/index.php/apps/paperless_unified_search/settings"}'
		'{"level":1,"app":"paperless_unified_search","message":"e2e-admin tried a search as e2e-other"}'
		'{"level":0,"app":"paperless_unified_search","message":"Paperless rejected the query of a search with HTTP 400"}'
		'{"level":2,"app":"paperless_unified_search","message":"Paperless unified search failed (OCA\\PaperlessUnifiedSearch\\Exception\\PaperlessRequestException)"}'
		'{"level":2,"app":"paperless_unified_search","message":"Value type is set to zero (0) in database. This is fine only during the upgrade process from 28 to 29.","data":{"app":"paperless_unified_search","key":"archive_owner"},"method":"POST","url":"/apps/paperless_unified_search/settings"}'
		''
		'PHP Warning: not a record'
		'[]'
	)
	expect "the check of a log without a finding" 0 "$(log_check "${fine[@]}")"
	expect_in "the levels of the whole log" "The log of Nextcloud holds 7 records: 2 debug, 1 info, 3 warning, 1 error, 0 fatal." "${WORK}/log-check"
	expect_in "the levels of the app" "Of them, 4 records of the app: 1 debug, 1 info, 2 warning, 0 error, 0 fatal." "${WORK}/log-check"
	expect_in "the lines that hold no record" "Lines of the log that hold no record in JSON, which the check leaves out: 2." "${WORK}/log-check"
	expect_in "the count of an expected warning" "Seen 1 time: the expected warning /Paperless unified search failed \(OCA" "${WORK}/log-check"
	expect_in "the count of the warning of the cache of AppConfig" "Seen 1 time: the expected warning /Value type is set to zero" "${WORK}/log-check"

	# Each finding fails the check, among the records that pass, with its kind, its place
	# in the files of the app and its request: kind|place|record.
	# shellcheck disable=SC2016 # $term and $token are parameters of PHP in the messages.
	findings=(
		'an error of the app, for the app '"'"'index'"'"'|at lib/Search/PaperlessSearchProvider.php:42, during GET /ocs/v2.php/search/providers/paperless_unified_search/search|{"level":3,"app":"index","message":"Argument #1 ($term) must be of type string, null given","method":"GET","url":"/ocs/v2.php/search/providers/paperless_unified_search/search","exception":{"Exception":"TypeError","Message":"Argument #1 ($term) must be of type string, null given","Code":0,"File":"/var/www/html/lib/private/Search/SearchComposer.php","Line":20,"Trace":[{"file":"/var/www/html/lib/private/Search/SearchComposer.php","line":20,"function":"search","class":"OC\\Search\\SearchComposer","type":"->","args":[]},{"file":"'"${app_dir}"'/lib/Search/PaperlessSearchProvider.php","line":42,"function":"names","class":"OCA\\PaperlessUnifiedSearch\\Search\\PaperlessSearchProvider","type":"->","args":["secret argument"]}]}}'
		'a deprecation of PHP in a file of the app, for the app '"'"'PHP'"'"'|at lib/Service/ConfigService.php:77, during occ paperless_unified_search:check|{"level":0,"app":"PHP","method":"","url":"--","occ_command":["occ","paperless_unified_search:check"],"message":"Implicitly marking parameter $token as nullable is deprecated at '"${app_dir}"'/lib/Service/ConfigService.php#77"}'
		'a notice of PHP in a file of the app|at lib/Command/Check.php:9|{"level":1,"app":"PHP","message":"Only variables should be assigned by reference at '"${app_dir}"'/lib/Command/Check.php#9"}'
		'a warning of PHP in a file of the app|at lib/Dashboard/RecentDocumentsWidget.php:5|{"level":2,"app":"PHP","message":"Undefined array key \"id\" at '"${app_dir}"'/lib/Dashboard/RecentDocumentsWidget.php#5"}'
		'a warning of the app that no test expects, for the app '"'"'paperless_unified_search'"'"'|at no file of the app, during GET /ocs/v2.php/apps/dashboard/api/v2/widget-items|{"level":2,"app":"paperless_unified_search","method":"GET","url":"/ocs/v2.php/apps/dashboard/api/v2/widget-items","message":"The dashboard could not show the newest Paperless documents (RuntimeException)"}'
		'a warning of the app that no test expects, for the app '"'"'paperless_unified_search'"'"'|at no file of the app|{"level":2,"app":"paperless_unified_search","message":"Paperless unified search failed (TypeError)"}'
		'an error of the app, for the app '"'"'paperless_unified_search'"'"'|at lib/Controller/SettingsController.php:9|{"level":3,"app":"paperless_unified_search","message":"The settings of Paperless Unified Search could not be saved","exception":{"Exception":"RuntimeException","Message":"Saving failed","File":"/var/www/html/lib/private/AppConfig.php","Line":1,"Trace":[],"Previous":{"Exception":"TypeError","Message":"Wrong type","File":"'"${app_dir}"'/lib/Controller/SettingsController.php","Line":9,"Trace":[]}}}'
		'a fatal error of the app, for the app '"'"'PHP'"'"'|at lib/Service/PaperlessClient.php:5|{"level":4,"app":"PHP","message":"Allowed memory size exhausted at '"${app_dir}"'/lib/Service/PaperlessClient.php#5"}'
		'an error of the app, for the app '"'"'core'"'"'|at no file of the app|{"level":3,"app":"core","message":"Could not load a search provider","data":{"class":"OCA\\PaperlessUnifiedSearch\\Search\\PaperlessSearchProvider"}}'
		'a record of the app at the level '"'"'3'"'"', which Nextcloud never writes|at no file of the app, during no request that the record names|{"level":"3","app":"paperless_unified_search","message":"A level as text"}'
	)
	for sample in "${findings[@]}"; do
		IFS='|' read -r kind where record <<<"${sample}"
		expect "the check of a log with ${kind}" 1 "$(log_check "${fine[@]}" "${record}")"
		expect_in "the finding ${kind}" "- ${kind}" "${WORK}/log-check"
		expect_in "the place of ${kind}" "  ${where}" "${WORK}/log-check"
		expect_in "the headline of ${kind}" "holds 1 record of the app that no test expects:" "${WORK}/log-check"
	done
	expect "the arguments of a trace in a finding" 0 \
		"$(log_check "${fine[@]}" "${findings[0]##*|}" >/dev/null; grep --count --fixed-strings 'secret argument' "${WORK}/log-check" || true)"
	expect_in "a frame of the trace in a finding" "${app_dir}/lib/Search/PaperlessSearchProvider.php:42 OCA\\\\PaperlessUnifiedSearch\\\\Search\\\\PaperlessSearchProvider->names" "${WORK}/log-check"
	expect "the check of an empty log" 0 "$(log_check)"
	expect_in "the levels of an empty log" "The log of Nextcloud holds 0 records: 0 debug, 0 info, 0 warning, 0 error, 0 fatal." "${WORK}/log-check"
else
	echo "Python 3.9 or newer is not installed here; the CI tests the check of the log of Nextcloud."
fi

## What upgrade.sh compares before and after the upgrade

# assert_upgrade.py turns the answer of the search, the page of the administration
# settings and the keys of occ config:list into lines that upgrade.sh compares; the
# answers below are as Nextcloud writes them.
if [[ -n "${python}" ]]; then
	# Runs assert_upgrade.py on the text $1, with the rest as its mode and arguments,
	# prints its exit status, and keeps its output in ${WORK}/upgrade-check. Git Bash
	# would take the paths of the files in Nextcloud for paths of Windows.
	upgrade_check() {
		local status=0 text="$1"
		shift
		printf '%s' "${text}" | MSYS2_ARG_CONV_EXCL='/Documents/;/Elsewhere' \
			"${python}" -B "${SCRIPT_DIR}/assert_upgrade.py" "$@" 2>&1 | tr -d '\r' >"${WORK}/upgrade-check" || status=$?
		echo "${status}"
	}
	answer='{"ocs":{"data":{"entries":[{"title":"Mobile viewer test","attributes":{"fileId":"42","path":"/Documents/Mobile viewer test [P123].pdf"}},{"title":"Other","attributes":{"fileId":"7","path":"/Other [P999].pdf"}}]}}}'
	expect "the results of a search" 0 "$(upgrade_check "${answer}" results '/Documents/Mobile viewer test [P123].pdf')"
	expect "the results of a search at another path" 1 "$(upgrade_check "${answer}" results /Elsewhere.pdf)"
	expect "the results of a search, sorted" "42 /Documents/Mobile viewer test [P123].pdf Mobile viewer test|7 /Other [P999].pdf Other" \
		"$(upgrade_check "${answer}" results >/dev/null; paste -s -d '|' "${WORK}/upgrade-check")"
	expect "a search without results" 1 "$(upgrade_check '{"ocs":{"data":{"entries":[]}}}' results)"
	expect_in "the message of a search without results" "The search found nothing" "${WORK}/upgrade-check"
	page='<div id="paperless-unified-search-settings" data-token-configured="true"><form>
<input id="paperless-unified-search-url" name="url" type="url" value="http://paperless-mock:8080" required>
<input name="allowLocal" type="checkbox" checked>
<input name="token" type="password" value="">
<input name="archiveOwner" type="text" value="e2e-user">
<input name="alwaysSearch" type="checkbox">
<input name="personalAlwaysSearch" type="checkbox"
	checked></form></div>'
	expect "the settings of the page" 0 "$(upgrade_check "${page}" settings)"
	expect "the settings that the page shows" "allowLocal=on|alwaysSearch=off|archiveOwner=e2e-user|personalAlwaysSearch=on|token=stored|url=http://paperless-mock:8080" \
		"$(paste -s -d '|' "${WORK}/upgrade-check")"
	expect "the settings without a URL or a token" "token=missing|url=" \
		"$(upgrade_check '<div id="paperless-unified-search-settings" data-token-configured="false"><input name="url" value=""></div>' settings >/dev/null; paste -s -d '|' "${WORK}/upgrade-check")"
	expect "a page without the settings of the app" 1 "$(upgrade_check '<html><body>Update needed</body></html>' settings)"
	expect "the keys of the app" "always_search|paperless_url" \
		"$(upgrade_check '{"system":{},"apps":{"paperless_unified_search":{"paperless_url":"http://x","always_search":"1"}}}' keys >/dev/null; paste -s -d '|' "${WORK}/upgrade-check")"
	expect "occ config:list without the app" 1 "$(upgrade_check '{"apps":{}}' keys)"
	expect "assert_upgrade.py without a mode" 1 "$(upgrade_check '{}')"
else
	echo "Python 3.9 or newer is not installed here; the CI tests what upgrade.sh compares."
fi

## Pipes into grep --quiet

# grep --quiet stops reading at its first match, and the command that writes into the
# pipe dies of SIGPIPE when it writes the rest. With set -o pipefail, as in each of these
# scripts, the pipe then fails although grep found the text: the logs of Docker piped so
# into grep failed the check of the date filter now and then (#94). Read the output into
# a variable first and search that, as with grep --quiet ... <<<"${output}".
# The samples build their pipe with printf, so that this guard does not find itself.
# A single | only: the || of "cmd || grep --quiet ..." runs grep after cmd, not on its output.
pipe_into_quiet_grep='(^|[^|])\|[[:space:]]*grep[^|]*[[:space:]](--quiet|--silent|-[[:alpha:]]*q[[:alpha:]]*)([[:space:]]|$)'
expect "the guard finds a pipe into grep --quiet" 1 \
	"$(printf 'docker compose logs %s grep --quiet x\n' '|' | grep --count --extended-regexp "${pipe_into_quiet_grep}")"
expect "the guard finds a pipe into grep -Fq" 1 \
	"$(printf 'cat f%sgrep -Fq x\n' '|' | grep --count --extended-regexp "${pipe_into_quiet_grep}")"
expect "the guard ignores grep --quiet after ||" 0 \
	"$(printf '%s\n' 'true || grep --quiet x f' '[[ -n x ]] || grep -Fxq x f' | grep --count --extended-regexp "${pipe_into_quiet_grep}" || true)"
grep --line-number --extended-regexp "${pipe_into_quiet_grep}" \
	"${SCRIPT_DIR}"/*.sh | grep --invert-match --extended-regexp '^[^:]+:[0-9]+:[[:space:]]*#' >"${WORK}/pipes" || true
expect "the pipes into grep --quiet in the scripts of the end-to-end tests" "" "$(cat "${WORK}/pipes")"

# run.sh hands the sizes of the search dialog to flow.mjs in the container of the browser,
# with sizes.mjs, which tests/js/sizes.test.mjs tests.
# shellcheck disable=SC2016 # the text of run.sh, with its variable unexpanded
expect "run.sh passes E2E_FLOW_SIZES into the container of the browser" 1 \
	"$(grep --count --fixed-strings -- '--env "E2E_FLOW_SIZES=${FLOW_SIZES}"' "${SCRIPT_DIR}/run.sh")"
expect "run.sh copies sizes.mjs into the container of the browser" 1 \
	"$(grep --count --extended-regexp '^[[:space:]]*tar .* sizes\.mjs .*flow\.mjs' "${SCRIPT_DIR}/run.sh")"

## The animation of the demo: scripts/screenshots.sh --demo

# In a copy of the repository with the script alone, a fake docker and a fake social
# preview: the script records from the demo of demo/seed.sh, which must run already, and
# must neither stop that demo nor change any other picture.
shots="${WORK}/shots"
mkdir -p "${shots}/scripts" "${shots}/tests/e2e" "${shots}/screenshots" "${shots}/demo" "${shots}/bin"
cp "${SCRIPT_DIR}/../../scripts/screenshots.sh" "${shots}/scripts/screenshots.sh"
# shellcheck disable=SC2016 # the fake expands it, not this script
echo 'echo "social preview" >>"${FAKE_DOCKER_LOG}"' >"${shots}/scripts/social-preview.sh"
# The script finds the image of the browser in run.sh.
echo 'PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright:v1.0.0-noble' >"${shots}/tests/e2e/run.sh"
for file in package.json package-lock.json ui.mjs demo.mjs screenshots.mjs; do
	echo "${file}" >"${shots}/tests/e2e/${file}"
done
echo "the committed picture" >"${shots}/screenshots/01-unified-search.png"
cat >"${shots}/bin/docker" <<'SCRIPT'
#!/usr/bin/env bash
# A fake of Docker: it notes each call, lists FAKE_RUNNING as the running services of
# Compose and, as the browser, notes its environment and the files that it gets, and
# answers with an animation.
echo "$*" >>"${FAKE_DOCKER_LOG}"
case "$1" in
	compose)
		if [[ " $* " == *" ps "* ]]; then
			printf '%s\n' ${FAKE_RUNNING:-}
		fi
		;;
	run)
		echo "browser environment: E2E_PASSWORD=${E2E_PASSWORD:-}" >>"${FAKE_DOCKER_LOG}"
		echo "browser files: $(tar -tf - | sort | paste -s -d ' ')" >>"${FAKE_DOCKER_LOG}"
		out="$(mktemp -d)"
		printf 'GIF89a of the demo' >"${out}/demo-search-and-open.gif"
		tar -C "${out}" -cf - .
		rm -rf "${out}"
		;;
esac
SCRIPT
chmod +x "${shots}/bin/docker"

# Runs the script in the copy with the arguments and prints its exit status.
screenshots() {
	local status=0
	: >"${WORK}/docker.log"
	(PATH="${shots}/bin:${PATH}" FAKE_DOCKER_LOG="${WORK}/docker.log" \
		bash "${shots}/scripts/screenshots.sh" "$@" >"${WORK}/log" 2>&1) || status=$?
	echo "${status}"
}

expect "screenshots.sh with an unknown argument" 2 "$(FAKE_RUNNING=nextcloud screenshots --demos)"
expect_in "the usage of screenshots.sh" "Usage: bash scripts/screenshots.sh [--demo]" "${WORK}/log"
expect "screenshots.sh --demo before demo/seed.sh" 1 "$(FAKE_RUNNING=nextcloud screenshots --demo)"
expect_in "the way to a demo that isn't set up" "The demo isn't set up: start it with bash demo/seed.sh" "${WORK}/log"
cat >"${shots}/demo/.env" <<'ENV'
NEXTCLOUD_ADMIN_PASSWORD=fake-admin-password
DEMO_PASSWORD=fake-demo-password
ENV
expect "screenshots.sh --demo while the demo doesn't run" 1 "$(FAKE_RUNNING="postgres paperless" screenshots --demo)"
expect_in "the way to a demo that doesn't run" "The Nextcloud of the demo doesn't run: start the demo with bash demo/seed.sh" "${WORK}/log"
expect "the browser of a demo that doesn't run" 0 "$(grep --count '^run ' "${WORK}/docker.log" || true)"
expect "screenshots.sh --demo" 0 "$(FAKE_RUNNING="postgres nextcloud paperless" screenshots --demo)"
expect "the animation of the demo" "GIF89a of the demo" "$(cat "${shots}/screenshots/demo-search-and-open.gif")"
expect "the other pictures after screenshots.sh --demo" "the committed picture" "$(cat "${shots}/screenshots/01-unified-search.png")"
expect_in "the browser in the network of the demo" "--network paperless-unified-search-demo_default" "${WORK}/docker.log"
expect_in "the mode of the browser" "--env SCREENSHOTS_DEMO=1" "${WORK}/docker.log"
expect_in "the password of jamie, from demo/.env, in the environment of the browser" \
	"browser environment: E2E_PASSWORD=fake-demo-password" "${WORK}/docker.log"
expect "the password of jamie on a command line" 0 \
	"$(grep --invert-match '^browser environment: ' "${WORK}/docker.log" | grep --count --fixed-strings fake-demo-password || true)"
expect_in "the files of the browser" "browser files: demo.mjs package-lock.json package.json screenshots.mjs ui.mjs" "${WORK}/docker.log"
expect "the demo after screenshots.sh --demo" 0 "$(grep --count --extended-regexp '^compose .*(down|stop|rm)( |$)' "${WORK}/docker.log" || true)"
expect "the social preview after screenshots.sh --demo" 0 "$(grep --count --fixed-strings 'social preview' "${WORK}/docker.log" || true)"
expect "the videos after screenshots.sh --demo" 0 "$(grep --count '^build ' "${WORK}/docker.log" || true)"
echo 'NEXTCLOUD_ADMIN_PASSWORD=fake-admin-password' >"${shots}/demo/.env"
expect "screenshots.sh --demo without the password of jamie" 1 "$(FAKE_RUNNING=nextcloud screenshots --demo)"
expect_in "the way to the password of jamie" "demo/.env holds no DEMO_PASSWORD, the password of jamie" "${WORK}/log"

## The videos of the animations and the PNGs at their smallest: scripts/screenshot-media.sh

# In a copy of the repository with the script alone and a fake docker, which builds the
# image of .github/screenshots/ and, as the container, makes NAME.mp4 and NAME-poster.webp of
# each NAME.gif that it gets on standard input, shrinks each PNG and answers with all of
# them on standard output.
media="${WORK}/media"
mkdir -p "${media}/scripts" "${media}/bin" "${media}/pictures"
cp "${SCRIPT_DIR}/../../scripts/screenshot-media.sh" "${media}/scripts/screenshot-media.sh"
cat >"${media}/bin/docker" <<'SCRIPT'
#!/usr/bin/env bash
# A fake of Docker: it notes each call with the command of the container, fails to
# build with FAKE_BUILD_FAILS, and finishes the pictures as the container does.
echo "$*" >>"${FAKE_DOCKER_LOG}"
case "$1" in
	build)
		if [[ -n "${FAKE_BUILD_FAILS:-}" ]]; then
			echo "ERROR: failed to build" >&2
			exit 1
		fi
		echo "sha256:fake-media-image"
		;;
	run)
		out="$(mktemp -d)"
		tar -C "${out}" -xf -
		for gif in "${out}"/*.gif; do
			[[ -e "${gif}" ]] || continue
			echo "video of $(cat "${gif}")" >"${gif%.gif}.mp4"
			echo "first frame of $(cat "${gif}")" >"${gif%.gif}-poster.webp"
		done
		for png in "${out}"/*.png; do
			[[ -e "${png}" ]] || continue
			echo "smaller $(cat "${png}")" >"${png}"
		done
		tar -C "${out}" -cf - .
		rm -rf "${out}"
		;;
esac
SCRIPT
chmod +x "${media}/bin/docker"

# Runs the script in the copy with the arguments, from the folder of these tests, and
# prints its exit status.
finish() {
	local status=0
	: >"${WORK}/docker.log"
	(cd "${WORK}" && PATH="${media}/bin:${PATH}" FAKE_DOCKER_LOG="${WORK}/docker.log" \
		bash "${media}/scripts/screenshot-media.sh" "$@" >"${WORK}/log" 2>&1) || status=$?
	echo "${status}"
}

expect "screenshot-media.sh without a folder" 2 "$(finish)"
expect_in "the usage of screenshot-media.sh" "Usage: bash scripts/screenshot-media.sh FOLDER" "${WORK}/log"
expect "screenshot-media.sh with a folder that isn't there" 2 "$(finish media/none)"
echo "the search" >"${media}/pictures/search-and-open.gif"
echo "the settings" >"${media}/pictures/03-admin-settings.png"
expect "screenshot-media.sh when the image can't be built" 1 "$(FAKE_BUILD_FAILS=1 finish media/pictures)"
expect_in "the reason when the image can't be built" "Building the image of .github/screenshots/Dockerfile failed." "${WORK}/log"
expect "the container when the image can't be built" 0 "$(grep --count '^run ' "${WORK}/docker.log" || true)"
expect "the PNG when the image can't be built" "the settings" "$(cat "${media}/pictures/03-admin-settings.png")"
# A folder named from another one than the root of the repository, as screenshots.sh
# names one of mktemp.
expect "screenshot-media.sh" 0 "$(finish media/pictures)"
expect_in "the image of .github/screenshots" "build --quiet .github/screenshots" "${WORK}/docker.log"
expect_in "the container of that image" "run --rm --interactive sha256:fake-media-image" "${WORK}/docker.log"
expect "the video beside the animation" "video of the search" "$(cat "${media}/pictures/search-and-open.mp4")"
expect "the first frame beside the animation" "first frame of the search" "$(cat "${media}/pictures/search-and-open-poster.webp")"
expect "the animation itself" "the search" "$(cat "${media}/pictures/search-and-open.gif")"
expect "the smaller PNG" "smaller the settings" "$(cat "${media}/pictures/03-admin-settings.png")"
# The same bytes in every run: one thread, and no version of the encoder in the file.
expect_in "the video with one thread" "-threads 1" "${WORK}/docker.log"
expect_in "the video without the version of its encoder" "-fflags +bitexact -flags:v +bitexact -map_metadata -1" "${WORK}/docker.log"
expect_in "the PNGs without a changed pixel" "oxipng --quiet --opt 4 --strip safe" "${WORK}/docker.log"

## The demo: demo/seed.sh

# In a copy of the demo, with a fake docker that plays its containers, up to the search
# as jamie: Paperless Sync must have written the archive before it can be shared, as
# Nextcloud answers 404 for a folder that doesn't exist yet. The button "Synchronize now"
# of Paperless Sync and its route, POST /sync/run, only queue a run since v0.4.0, for the
# background jobs up to five minutes later, which stopped the demo right here.
seeding="${WORK}/seeding"
mkdir -p "${seeding}/demo" "${seeding}/bin" "${seeding}/state"
cp "${SCRIPT_DIR}/../../demo/seed.sh" "${seeding}/demo/seed.sh"
cat >"${seeding}/bin/docker" <<'SCRIPT'
#!/usr/bin/env bash
# A fake of Docker and of the containers of the demo. It notes each call. occ
# paperless_sync:run needs the settings of the step connect of seed.py and ends with the
# next status in the file sync-status, and with the last one again and again; its status 0 or 1
# leaves the archive, which the step share of seed.py needs.
echo "$*" >>"${FAKE_DOCKER_LOG}"
case " $* " in
	*" occ paperless_sync:run "*)
		[[ -f "${FAKE_STATE}/connected" ]] || exit 2
		read -r status rest <"${FAKE_STATE}/sync-status"
		[[ -z "${rest}" ]] || echo "${rest}" >"${FAKE_STATE}/sync-status"
		if [[ "${status}" -le 1 ]]; then
			touch "${FAKE_STATE}/archive"
		fi
		exit "${status}"
		;;
	*"/seed.py connect "*) touch "${FAKE_STATE}/connected" ;;
	*"/seed.py share "*)
		[[ -f "${FAKE_STATE}/archive" ]] && exit 0
		echo "Nextcloud answered the shares with HTTP 404: Wrong path, file/folder does not exist" >&2
		exit 1
		;;
	*"/seed.py documents "*) ;;
	*"/seed.py token "*) echo "fake-token" ;;
	*"/seed.py "*)
		echo "Usage: python3 seed.py documents|token|connect|share" >&2
		exit 2
		;;
	*" config:app:get paperless_sync installed_version "*) echo "${PAPERLESS_SYNC_VERSION#v}" ;;
	*" config:app:get "*" enabled "*) echo "yes" ;;
	*" --token-stdin "*) cat >/dev/null ;;
	*" paperless_unified_search:search "*" --json "*) printf '"verdict": "shown"\n"verdict": "shown"\n"verdict": "shown"\n' ;;
esac
SCRIPT
chmod +x "${seeding}/bin/docker"
# The waits of the script take no time here.
printf '#!/usr/bin/env bash\n' >"${seeding}/bin/sleep"
chmod +x "${seeding}/bin/sleep"

# Runs the script in the copy, with a new demo and the given statuses of the runs of Paperless
# Sync, and prints its exit status.
seeded() {
	local status=0
	: >"${WORK}/docker.log"
	rm -f "${seeding}/state/"*
	echo "$1" >"${seeding}/state/sync-status"
	(PATH="${seeding}/bin:${PATH}" FAKE_DOCKER_LOG="${WORK}/docker.log" FAKE_STATE="${seeding}/state" \
		bash "${seeding}/demo/seed.sh" >"${WORK}/log" 2>&1) || status=$?
	echo "${status}"
}

expect "seed.sh" 0 "$(seeded 0)"
expect_in "the end of the demo" "The demo is ready after" "${WORK}/log"
expect "the steps of Paperless Sync, one after the other" "connect run share token" \
	"$(sed -n -E 's/.*(seed\.py (connect|share|token)|occ paperless_sync:(run)).*/\2\3/p' "${WORK}/docker.log" | paste -s -d ' ')"
expect "the accounts in the environment of the step connect, and not more" 1 \
	"$(grep --count -- '--env NEXTCLOUD_ADMIN_USER --env NEXTCLOUD_ADMIN_PASSWORD --env ARCHIVE_USER paperless python3 /demo/seed.py connect' "${WORK}/docker.log" || true)"
expect "the accounts in the environment of the step share, and not more" 1 \
	"$(grep --count -- '--env ARCHIVE_USER --env ARCHIVE_PASSWORD --env DEMO_USER paperless python3 /demo/seed.py share' "${WORK}/docker.log" || true)"
expect "seed.py and the route that only queues a run" 0 \
	"$(grep --count 'sync/run' "${SCRIPT_DIR}/../../demo/seed.py" || true)"

# A run of the background jobs may just be working: occ says so with the status 3.
expect "seed.sh while the background jobs run Paperless Sync" 0 "$(seeded "3 3 0")"
expect "the runs until the one of the background jobs has ended" 3 "$(grep --count 'occ paperless_sync:run' "${WORK}/docker.log" || true)"
expect_in "the end of the demo after the wait" "The demo is ready after" "${WORK}/log"

expect "seed.sh when the background jobs never end" 3 "$(seeded 3)"
expect "the runs before it gives up" 60 "$(grep --count 'occ paperless_sync:run' "${WORK}/docker.log" || true)"
expect_in "the way out when the background jobs never end" "occ paperless_sync:status" "${WORK}/log"
expect_in "the end of the logs when the background jobs never end" "The demo stopped after" "${WORK}/log"
expect "the share of an archive that isn't complete" 0 "$(grep --count 'seed.py share' "${WORK}/docker.log" || true)"

expect "seed.sh when a document fails" 1 "$(seeded 1)"
expect_in "the end of the logs when a document fails" "The demo stopped after" "${WORK}/log"
expect "the share after a run with errors" 0 "$(grep --count 'seed.py share' "${WORK}/docker.log" || true)"

expect "seed.sh when the run fails" 2 "$(seeded 2)"
expect "the share after a failed run" 0 "$(grep --count 'seed.py share' "${WORK}/docker.log" || true)"

if ((failures > 0)); then
	echo "${failures} of ${checks} checks of the scripts of the end-to-end tests failed." >&2
	exit 1
fi
echo "The ${checks} checks of scope.sh, run-all.sh, package.sh, upgrade.sh up to Docker, the issue of the weekly run, the journal of the mock, the check of the log of Nextcloud, what the upgrade compares, the pipes of the scripts, the animation of the demo, the videos of the animations and the steps of the demo pass."
