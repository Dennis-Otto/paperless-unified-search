#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# Packs the checkout as the App Store package of a release holds it, for upgrade.sh,
# without krankerl. krankerl package, which .github/workflows/build-release.yml runs,
# clones the repository and leaves out what each .nextcloudignore names, which it reads
# as Git reads a .gitignore: for the folder of the file and every folder below it. Here
# Git itself reads the .nextcloudignore files, over the files that it tracks, and the
# package takes their content from the checkout, so that a change not yet committed is
# in it too. A file that a .nextcloudignore leaves out by mistake, such as a class of
# lib/, is then missing from the app in Nextcloud as it would be after an update from
# the App Store. For v1.1.1, the package holds the same files as the release, but
# appinfo/signature.json, which only the signature of the release adds.
#
#   bash tests/e2e/package.sh <archive.tar.gz> [version]
#
# The archive holds the folder paperless_unified_search with the files of the app. With
# a version, such as 1.1.2, its appinfo/info.xml names that version in place of the one
# of the checkout, so that Nextcloud upgrades the app from a release of the same
# version. selftest.sh tests the script in a repository of its own.

set -euo pipefail

APP_ID="paperless_unified_search"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"

if (($# < 1 || $# > 2)) || [[ -z "$1" ]]; then
	echo "Usage: bash tests/e2e/package.sh <archive.tar.gz> [version]" >&2
	exit 2
fi
archive="$1"
version="${2:-}"
if [[ -n "${version}" && ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "${version} is no version of the app, such as 1.1.2." >&2
	exit 2
fi

STAGING="$(mktemp -d)"
trap 'rm -rf "${STAGING}"' EXIT
mkdir "${STAGING}/${APP_ID}"

# The files that a .nextcloudignore leaves out, and then every other file that Git
# tracks and the checkout still has, each name ended by a NUL, as tar reads them.
declare -A ignored=()
while IFS= read -r -d '' path; do
	ignored["${path}"]=1
done < <(git -C "${REPOSITORY_ROOT}" ls-files -z --cached --ignored --exclude-per-directory=.nextcloudignore)
count=0
while IFS= read -r -d '' path; do
	if [[ -z "${ignored["${path}"]:-}" && -f "${REPOSITORY_ROOT}/${path}" ]]; then
		printf '%s\0' "${path}"
		count=$((count + 1))
	fi
done < <(git -C "${REPOSITORY_ROOT}" ls-files -z --cached) >"${STAGING}/files"
if ((count == 0)); then
	echo "Git tracks no file of the app in ${REPOSITORY_ROOT} that the .nextcloudignore files leave in the package." >&2
	exit 1
fi
tar -C "${REPOSITORY_ROOT}" --null --files-from="${STAGING}/files" -cf - \
	| tar -C "${STAGING}/${APP_ID}" -xf -

info="${STAGING}/${APP_ID}/appinfo/info.xml"
if [[ ! -f "${info}" ]]; then
	echo "The package has no appinfo/info.xml: a .nextcloudignore leaves it out, or Git doesn't track it." >&2
	exit 1
fi
if [[ -n "${version}" ]]; then
	if [[ "$(grep --count '<version>[^<]*</version>' "${info}" || true)" != 1 ]]; then
		echo "appinfo/info.xml names the version of the app not exactly once, as <version>1.1.1</version>." >&2
		exit 1
	fi
	sed -E "s#<version>[^<]*</version>#<version>${version}</version>#" "${info}" >"${STAGING}/info.xml"
	cat "${STAGING}/info.xml" >"${info}"
fi

# Through standard output: GNU tar would take the drive of F:/pkg.tar.gz for a host.
tar -C "${STAGING}" -czf - "${APP_ID}" >"${archive}"
echo "Packed ${count} files of the checkout${version:+ as version ${version}} into ${archive}."
