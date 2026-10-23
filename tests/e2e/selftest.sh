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
#   the versions that broke as an output of the step, one download of the browser, the
#   sizes of the search dialog of each version, and the exit status.
# - the job of .github/workflows/e2e.yml that writes the issue about the supported
#   Nextcloud versions, with a fake gh: it opens the issue with the versions that
#   broke, adds a further failed run to it and closes it once the tests pass again.
# - mock_server.py, with requests of its own, when Python is installed: its journal
#   holds each request right after its answer, and run.sh reads that journal.
# - that no script here pipes a command into grep --quiet, which fails the pipe now
#   and then under set -o pipefail.
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
expect "a pull request of the workflow of the tests" true "$(pull_request 'echo second >.github/workflows/e2e.yml')"
expect "a pull request of another workflow" false "$(pull_request 'echo second >.github/workflows/lint.yml')"
expect "a pull request of a path that only looks like the app" false "$(pull_request 'echo second >docs/lib/notes.md')"
expect "a pull request that moves a file out of the app" true "$(pull_request 'git mv lib/Search.php docs/Search.php')"
expect "a pull request of the README and the roadmap" false "$(pull_request 'echo second >README.md && echo second >docs/roadmap.md')"

# The contract tests against a real Paperless-ngx cover only the code that talks to
# Paperless and reads its answers, and their own files.
export SCOPE=paperless
expect "a contract run for the requests to Paperless" true "$(pull_request 'mkdir -p lib/Service && echo new >lib/Service/PaperlessApiService.php')"
expect "a contract run for the search" true "$(pull_request 'mkdir -p lib/Search && echo new >lib/Search/Provider.php')"
expect "a contract run for a document of the contract" true "$(pull_request 'mkdir -p tests/e2e/contract && echo new >tests/e2e/contract/letter.txt')"
expect "a contract run for the workflow of the tests" true "$(pull_request 'echo second >.github/workflows/e2e.yml')"
expect "no contract run for another file of the app" false "$(pull_request 'echo second >lib/Search.php')"
expect_in "the notice of a skipped contract run" "::notice title=Contract tests skipped::" "${WORK}/log"
expect "no contract run for the other end-to-end tests" false "$(pull_request 'echo second >tests/e2e/README.md')"
expect "no contract run for composer.json" false "$(pull_request 'echo second >composer.json')"
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
	# after each answer: one with documents and one to a wrong token. Python on Windows
	# ends its lines with CR LF. -B leaves the checkout without a cache of bytecode.
	"${python}" -B - "${SCRIPT_DIR}" "${WORK}/requests.log" 2>&1 <<'PYTHON' | tr -d '\r' >"${WORK}/journal" || true
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
server.shutdown()
PYTHON
	journal="$(head -n 1 "${WORK}/journal")"
	expect "the journal of the mock in its tmpfs" /tmp/requests.log "${journal}"
	expect "run.sh reads the journal of the mock" 1 "$(grep --count --fixed-strings "cat ${journal}" "${SCRIPT_DIR}/run.sh")"
	expect "the journal of the mock right after each answer" \
		"GET /api/documents/?query=mobiletest&fields=id&created__date__gte=2026-08-01 HTTP/1.1 200|GET /api/documents/?query=mobiletest&fields=id&created__date__gte=2026-08-01 HTTP/1.1 200|GET /api/documents/?query=mobiletest&fields=id HTTP/1.1 401" \
		"$(tail -n +2 "${WORK}/journal" | paste -s -d "|")"
else
	echo "Python 3.9 or newer is not installed here; the CI tests the journal of the mock."
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

if ((failures > 0)); then
	echo "${failures} of ${checks} checks of the scripts of the end-to-end tests failed." >&2
	exit 1
fi
echo "The ${checks} checks of scope.sh, run-all.sh, the issue of the weekly run, the journal of the mock and the pipes of the scripts pass."
