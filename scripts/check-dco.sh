#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

BASE_REF="${1:?Usage: $0 BASE_REF [HEAD_REF]}"
HEAD_REF="${2:-HEAD}"
TRUSTED_AUTOMATION="${DCO_TRUSTED_AUTOMATION:-}"
DEPENDABOT_AUTHOR='dependabot[bot] <49699333+dependabot[bot]@users.noreply.github.com>'

git rev-parse --verify "${BASE_REF}^{commit}" >/dev/null
git rev-parse --verify "${HEAD_REF}^{commit}" >/dev/null

COUNT=0
while IFS= read -r commit; do
	if [[ -z "${commit}" ]]; then
		continue
	fi
	author="$(git show --no-patch --format='%an <%ae>' "${commit}")"
	if ! git show --no-patch --format='%B' "${commit}" \
		| git interpret-trailers --parse \
		| grep --fixed-strings --line-regexp "Signed-off-by: ${author}" >/dev/null; then
		if [[ "${TRUSTED_AUTOMATION}" == 'dependabot[bot]' && "${author}" == "${DEPENDABOT_AUTHOR}" ]]; then
			echo "Accepted authenticated Dependabot commit ${commit} without a DCO trailer."
			COUNT=$((COUNT + 1))
			continue
		fi
		echo "Missing DCO sign-off for ${commit}: Signed-off-by: ${author}" >&2
		exit 1
	fi
	COUNT=$((COUNT + 1))
done < <(git rev-list --reverse "${BASE_REF}..${HEAD_REF}")

if [[ "${COUNT}" -eq 0 ]]; then
	echo "No commits require DCO verification."
else
	echo "Verified DCO sign-off for ${COUNT} commit(s)."
fi
