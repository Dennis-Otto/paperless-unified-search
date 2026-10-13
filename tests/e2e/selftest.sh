#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The tests of the scripts around the end-to-end tests, in a few seconds and without
# Docker; scripts/check-project.sh runs them.
#
# - scope.sh, which decides whether the required check "nextcloud" runs the tests at
#   all: in throwaway Git repositories, with real merge commits and pushes. A mistake
#   there would let every pull request pass the check without the tests, and the pull
#   request that makes it would still run them, as it changes tests/e2e/.
# - run-all.sh, with a fake docker and a fake run.sh: a version that passes, one that
#   fails and one that hangs, each in a group of its own, the table of the summary,
#   one download of the browser, and the exit status.
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
expect "a pull request of the workflow of the tests" true "$(pull_request 'echo second >.github/workflows/e2e.yml')"
expect "a pull request of another workflow" false "$(pull_request 'echo second >.github/workflows/lint.yml')"
expect "a pull request of a path that only looks like the app" false "$(pull_request 'echo second >docs/lib/notes.md')"
expect "a pull request that moves a file out of the app" true "$(pull_request 'git mv lib/Search.php docs/Search.php')"
expect "a pull request of the README and the roadmap" false "$(pull_request 'echo second >README.md && echo second >docs/roadmap.md')"

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
	nextcloud:33-apache) echo "Log of 33 on port ${E2E_PORT}, project ${E2E_PROJECT_NAME}" ;;
	nextcloud:34-apache) echo "Log of 34" && exit 1 ;;
	*)
		trap 'echo "Log of 35: stopped" && exit 143' TERM
		echo "Log of 35: hangs"
		sleep 60 &
		wait
		;;
esac
SCRIPT
cat >"${fake}/bin/docker" <<'SCRIPT'
#!/usr/bin/env bash
echo "$*" >>"${FAKE_DOCKER_LOG}"
if [[ "$1 $2" == "image inspect" ]]; then
	image="${*: -1}"
	version="${image#nextcloud:}"
	echo "NEXTCLOUD_VERSION=${version%-apache}.0.1"
fi
SCRIPT
chmod +x "${fake}/bin/docker"

# Runs run-all.sh as in the CI and prints its exit status.
run_all() {
	local status=0
	: >"${WORK}/docker.log"
	: >"${WORK}/summary"
	(cd "${fake}" && GITHUB_ACTIONS=true GITHUB_STEP_SUMMARY="${WORK}/summary" \
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
else
	echo "timeout of GNU coreutils is not installed here; the CI tests a version that hangs."
	expect "run-all.sh with a version that fails" 1 "$(run_all env E2E_VERSIONS="33 34")"
fi
expect_in "the group of the version that passes" "::group::Nextcloud 33 (passed, " "${WORK}/log"
expect_in "the log of the version that passes" "Log of 33 on port 18033, project pus_e2e_33" "${WORK}/log"
expect_in "the group of the version that fails" "::group::Nextcloud 34 (failed, " "${WORK}/log"
expect_in "the error of the version that fails" "::error title=Nextcloud 34::The end-to-end tests failed against Nextcloud 34" "${WORK}/log"
expect_in "the summary of the version that passes" "| 33 | 33.0.1 | passed |" "${WORK}/summary"
expect_in "the summary of the version that fails" "| 34 | 34.0.1 | failed |" "${WORK}/summary"
expect "the downloads of the browser" 1 "$(grep --count --fixed-strings 'pull --quiet mcr.microsoft.com/playwright:v1.0.0-noble' "${WORK}/docker.log")"
expect "the download of the browser after those of Nextcloud" "pull --quiet mcr.microsoft.com/playwright:v1.0.0-noble" \
	"$(grep --fixed-strings 'pull --quiet' "${WORK}/docker.log" | tail -n 1)"

expect "run-all.sh with one version that passes" 0 "$(run_all env E2E_VERSIONS=33)"
expect_in "the result of one version" "The end-to-end tests passed against Nextcloud 33 in " "${WORK}/log"
expect "run-all.sh with a version that isn't one" 1 "$(run_all env E2E_VERSIONS="33,next")"
expect_in "the message about a version that isn't one" "next is no major version of Nextcloud" "${WORK}/log"
expect "run-all.sh with a deadline that isn't one" 1 "$(run_all env E2E_VERSIONS=33 E2E_VERSION_TIMEOUT=0)"

if ((failures > 0)); then
	echo "${failures} of ${checks} checks of the scripts of the end-to-end tests failed." >&2
	exit 1
fi
echo "The ${checks} checks of scope.sh and run-all.sh pass."
