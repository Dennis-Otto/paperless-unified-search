#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# Whether a change touches a file that the end-to-end tests cover, for the required
# check "nextcloud" of .github/workflows/e2e.yml: it writes run=true or run=false to
# $GITHUB_OUTPUT. A pull request or push that changes no such file, such as one of the
# documentation, of the CI or from the blueprint, passes the check without the tests.
# With SCOPE=paperless, it decides the same for the contract tests against a real
# Paperless-ngx (paperless-contract.sh), which cover only the code that talks to
# Paperless and reads its answers.
#
#   EVENT=pull_request bash tests/e2e/scope.sh
#   EVENT=push BEFORE=<commit before the push> bash tests/e2e/scope.sh
#   EVENT=pull_request SCOPE=paperless bash tests/e2e/scope.sh
#
# EVENT is the event of the run (github.event_name), BEFORE the commit that a push
# replaced (github.event.before). A pull request compares its merge commit, the
# checkout, with its first parent, the base branch; a push compares with BEFORE, which
# it fetches. Every other event, and a change it can't compare, runs the tests.
# scripts/check-project.sh tests this script with tests/e2e/selftest.sh.

set -euo pipefail

EVENT="${EVENT:-${GITHUB_EVENT_NAME:-}}"
BEFORE="${BEFORE:-}"
OUTPUT="${GITHUB_OUTPUT:-/dev/null}"
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"
case "${SCOPE:-nextcloud}" in
	nextcloud)
		# The app, its dependencies, the end-to-end tests and their workflow.
		COVERED='^(appinfo|lib|templates|js|css|img|l10n|tests/e2e)/|^composer\.(json|lock)$|^\.github/workflows/e2e\.yml$'
		TESTS="End-to-end tests"
		FILES="the app (appinfo/, lib/, templates/, js/, css/, img/, l10n/), composer.json, composer.lock, tests/e2e/"
		;;
	paperless)
		# The requests to Paperless, the reading of its answers, the contract tests, their
		# containers and their workflow.
		COVERED='^lib/(Service/PaperlessApiService\.php|Search/|Model/PaperlessVersion\.php|Model/TokenWarning\.php|Exception/)|^tests/e2e/(paperless-contract\.sh|assert_contract\.py|compose(\.paperless)?\.yaml|scope\.sh|contract/)|^\.github/workflows/e2e\.yml$'
		TESTS="Contract tests"
		FILES="lib/Service/PaperlessApiService.php, lib/Search/, lib/Model/PaperlessVersion.php, lib/Model/TokenWarning.php, lib/Exception/, the contract tests and their files in tests/e2e/"
		;;
	*)
		echo "SCOPE is nextcloud or paperless, not ${SCOPE}." >&2
		exit 2
		;;
esac

base=""
case "${EVENT}" in
	pull_request)
		# The checkout is the merge commit; its first parent is the base branch.
		base=HEAD^1
		;;
	push)
		# A new branch has no commit before the push, and one that a force push removed
		# can't be fetched: then the tests run.
		if [[ -n "${BEFORE}" && ! "${BEFORE}" =~ ^0+$ ]] &&
			git fetch --quiet --no-tags --depth=1 origin "${BEFORE}"; then
			base="${BEFORE}"
		fi
		;;
esac

run=true
# --no-renames: a file moved out of lib/ counts as a change of lib/.
if [[ -n "${base}" ]] && changed="$(git diff --name-only --no-renames "${base}" HEAD)"; then
	if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
		echo "::group::Changed files"
		echo "${changed}"
		echo "::endgroup::"
	fi
	if grep -qE "${COVERED}" <<<"${changed}"; then
		echo "A file that the ${TESTS,,} cover changed: they run."
	else
		run=false
		reason="No file that the ${TESTS,,} cover changed: neither ${FILES} nor .github/workflows/e2e.yml."
		echo "::notice title=${TESTS} skipped::${reason}"
		echo "${reason} The check passes without running them." >>"${SUMMARY}"
	fi
else
	echo "No change to compare with (${EVENT:-no event}): the ${TESTS,,} run."
fi
echo "run=${run}" >>"${OUTPUT}"
