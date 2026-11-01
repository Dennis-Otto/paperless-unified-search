#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The images of Nextcloud that a pull request and a push test, in nextcloud-images.txt:
# one line nextcloud:<release>-apache@sha256:<digest> for each major version from
# min-version to max-version of appinfo/info.xml, which run-all.sh reads. Renovate moves
# a line to the next release of its major version. This script does what Renovate can't:
# it adds the line of a version that has none, such as after the upstream bot raised
# max-version, and removes the line of a version that appinfo/info.xml no longer names.
#
#   bash tests/e2e/pin-images.sh --check   checks the file, without Docker or the network
#   bash tests/e2e/pin-images.sh           adds and removes those lines
#
# The check and the fix read the file in one place below, so they can't disagree. A file
# without a finding stays as it is and the fix asks nothing of Docker, so every other pin
# stays with Renovate. scripts/check-project.sh runs the check, and the fix bot the fix
# when that step fails, so that the pull request of the upstream bot fixes itself.
#
# The fix reads the release behind the floating tag nextcloud:<version>-apache from the
# configuration of its image, and then the digest of the tag of that release, with
# docker buildx imagetools inspect, which downloads no image. A floating tag that isn't
# there yet, as for a new major version that Docker Hub doesn't have yet, fails the fix
# for that version; the others are written all the same.
# scripts/check-project.sh tests this script with tests/e2e/selftest.sh.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
PINS="${SCRIPT_DIR}/nextcloud-images.txt"
SHOWN="tests/e2e/nextcloud-images.txt"
PIN='^nextcloud:([1-9][0-9]*)\.[0-9]+\.[0-9]+-apache@sha256:[a-f0-9]{64}$'

mode="write"
case "$*" in
	"") ;;
	--check) mode="check" ;;
	*)
		echo "Usage: bash tests/e2e/pin-images.sh [--check]" >&2
		exit 2
		;;
esac

element="$(grep -oE '<nextcloud [^>]*>' "${REPOSITORY_ROOT}/appinfo/info.xml" | head -n 1 || true)"
low="$(sed -nE 's/.* min-version="([0-9]+)[^"]*".*/\1/p' <<<"${element}")"
high="$(sed -nE 's/.* max-version="([0-9]+)[^"]*".*/\1/p' <<<"${element}")"
if [[ -z "${low}" || -z "${high}" ]] || ((low > high)); then
	echo "appinfo/info.xml names no range of Nextcloud versions: ${element:-no <nextcloud> element}" >&2
	exit 1
fi

# The comments stay. The pin of each version stands at the index of that version, and
# whatever else is wrong with the file is a finding, which the fix leaves out.
comments=()
pins=()
findings=()
if [[ -f "${PINS}" ]]; then
	number=0
	while IFS= read -r line || [[ -n "${line}" ]]; do
		number=$((number + 1))
		if [[ -z "${line}" || "${line}" == \#* ]]; then
			comments+=("${line}")
		elif [[ ! "${line}" =~ ${PIN} ]]; then
			findings+=("Line ${number}, \"${line}\", is no pin, such as nextcloud:${high}.0.1-apache@sha256:<its 64 hexadecimal digits>.")
		else
			version="${BASH_REMATCH[1]}"
			if ((version < low || version > high)); then
				findings+=("Line ${number} pins Nextcloud ${version}, which appinfo/info.xml no longer supports; it names ${low} to ${high}.")
			elif [[ -n "${pins[version]:-}" ]]; then
				findings+=("Line ${number} pins Nextcloud ${version} a second time.")
			else
				pins[version]="${line}"
			fi
		fi
	done <"${PINS}"
fi
for ((version = low; version <= high; version++)); do
	if [[ -z "${pins[version]:-}" ]]; then
		findings+=("Nextcloud ${version}, which appinfo/info.xml supports, has no pin.")
	fi
done

if ((${#findings[@]} == 0)); then
	echo "${SHOWN} pins one image for each of Nextcloud ${low} to ${high}, as appinfo/info.xml names them."
	exit 0
fi
if [[ "${mode}" == check ]]; then
	for finding in "${findings[@]}"; do
		echo "${SHOWN}: ${finding}"
	done
	echo "Write them there with: bash tests/e2e/pin-images.sh"
	exit 1
fi

# The pin of a major version, from its floating tag.
resolve() {
	local version="$1" floating="nextcloud:$1-apache" environment release digest pin
	environment="$("${DOCKER_BIN}" buildx imagetools inspect "${floating}" \
		--format '{{range (index .Image "linux/amd64").Config.Env}}{{println .}}{{end}}')" || return 1
	release="$(sed -n 's/^NEXTCLOUD_VERSION=//p' <<<"${environment}")"
	if [[ ! "${release}" =~ ^${version}\.[0-9]+\.[0-9]+$ ]]; then
		echo "The image ${floating} names the release \"${release}\", which is no release of Nextcloud ${version}." >&2
		return 1
	fi
	digest="$("${DOCKER_BIN}" buildx imagetools inspect "nextcloud:${release}-apache" --format '{{.Manifest.Digest}}')" || return 1
	pin="nextcloud:${release}-apache@${digest}"
	if [[ ! "${pin}" =~ ${PIN} ]]; then
		echo "The tag nextcloud:${release}-apache has no digest to pin: ${digest:-nothing}." >&2
		return 1
	fi
	echo "${pin}"
}

status=0
for ((version = low; version <= high; version++)); do
	if [[ -n "${pins[version]:-}" ]]; then
		continue
	fi
	if pin="$(resolve "${version}")"; then
		pins[version]="${pin}"
		echo "Pinned Nextcloud ${version}: ${pin}"
	else
		echo "Nextcloud ${version} stays without a pin: Docker couldn't resolve its image nextcloud:${version}-apache. The end-to-end tests run against that floating tag until it has one; run this script again." >&2
		status=1
	fi
done
{
	if ((${#comments[@]} > 0)); then
		printf '%s\n' "${comments[@]}"
	fi
	if ((${#pins[@]} > 0)); then
		printf '%s\n' "${pins[@]}"
	fi
} >"${PINS}"
echo "Wrote ${SHOWN} for Nextcloud ${low} to ${high}."
exit "${status}"
