#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

APP_ID="paperless_unified_search"
ARCHIVE="${1:-build/artifacts/${APP_ID}.tar.gz}"
MODE="${2:-unsigned}"

if [[ "${MODE}" != "unsigned" && "${MODE}" != "signed" ]]; then
	echo "Usage: $0 [archive.tar.gz] [unsigned|signed]" >&2
	exit 2
fi

if [[ ! -s "${ARCHIVE}" ]]; then
	echo "Package does not exist or is empty: ${ARCHIVE}" >&2
	exit 1
fi

PACKAGE_ROOT="$(mktemp -d)"
ENTRY_LIST="${PACKAGE_ROOT}/entries.txt"
trap 'rm -rf "${PACKAGE_ROOT}"' EXIT

tar --list --gzip --file "${ARCHIVE}" > "${ENTRY_LIST}"

while IFS= read -r entry; do
	if [[ -z "${entry}" || "${entry}" == /* || "/${entry}/" == *"/../"* ]]; then
		echo "Unsafe package entry: ${entry}" >&2
		exit 1
	fi

	case "${entry}" in
		"${APP_ID}/"|"${APP_ID}/CHANGELOG.md"|"${APP_ID}/LICENSE"|"${APP_ID}/README.md")
			;;
		"${APP_ID}/appinfo/"*|"${APP_ID}/css/"*|"${APP_ID}/img/"*|"${APP_ID}/js/"*|"${APP_ID}/l10n/"*|"${APP_ID}/lib/"*|"${APP_ID}/templates/"*)
			;;
		*)
			echo "Unexpected production-package entry: ${entry}" >&2
			exit 1
			;;
	esac
done < "${ENTRY_LIST}"

if tar --list --verbose --gzip --file "${ARCHIVE}" | awk '$1 ~ /^[lh]/ { found = 1 } END { exit found ? 0 : 1 }'; then
	echo "Production package must not contain symbolic or hard links." >&2
	exit 1
fi

tar --extract --gzip --file "${ARCHIVE}" --directory "${PACKAGE_ROOT}"
APP_ROOT="${PACKAGE_ROOT}/${APP_ID}"

for required in appinfo/info.xml appinfo/routes.php img/app.svg; do
	if [[ ! -s "${APP_ROOT}/${required}" ]]; then
		echo "Required package file is missing: ${required}" >&2
		exit 1
	fi
done

cmp appinfo/info.xml "${APP_ROOT}/appinfo/info.xml"

if [[ "${MODE}" == "signed" ]]; then
	# PHP receives its own argv at runtime.
	# shellcheck disable=SC2016
	php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "${APP_ROOT}/appinfo/signature.json"
elif [[ -e "${APP_ROOT}/appinfo/signature.json" ]]; then
	echo "Unsigned package unexpectedly contains appinfo/signature.json." >&2
	exit 1
fi

find "${APP_ROOT}" -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check "${APP_ROOT}/js/settings.js"
node --check "${APP_ROOT}/l10n/de.js"

FILE_COUNT="$(find "${APP_ROOT}" -type f | wc -l | tr -d ' ')"
echo "Validated ${MODE} production package with ${FILE_COUNT} files: ${ARCHIVE}"
