#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The end-to-end tests against the coming Nextcloud, which the weekly canary of
# .github/workflows/e2e.yml runs. The coming Nextcloud is the newest beta or release
# candidate of the next major version while Nextcloud publishes one, and otherwise the
# daily build of its master branch. Both come from download.nextcloud.com, signed with
# the release key of Nextcloud, and replace the code in the image of the newest
# release, which keeps its PHP, Apache and start script. run.sh enables the app with
# --force, as max-version of appinfo/info.xml doesn't name that version yet; that
# doesn't make the app compatible with it.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
# The newest release of Nextcloud, whose image takes the coming code.
BASE_IMAGE="${CANARY_BASE_IMAGE:-nextcloud:apache}"
IMAGE="${CANARY_IMAGE:-paperless-unified-search-e2e:coming}"
DOWNLOADS="https://download.nextcloud.com/server"
# The key that signs every package of Nextcloud, which the official image checks too.
RELEASE_KEY="28806A878AE423A28372792ED75899B9A724937A"
TMP_DIR="$(mktemp -d)"

cleanup() {
	gpgconf --kill all >/dev/null 2>&1 || true
	rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

fetch() {
	curl --fail --location --silent --show-error --retry 3 --remote-time --output "$2" "$1"
}

# This is PHP code, not a shell expression.
# shellcheck disable=SC2016
VERSION_CODE='require "/usr/src/nextcloud/version.php"; echo $OC_VersionString;'

"${DOCKER_BIN}" pull --quiet "${BASE_IMAGE}" >/dev/null
released="$("${DOCKER_BIN}" run --rm --entrypoint php "${BASE_IMAGE}" -r "${VERSION_CODE}")"
released_major="${released%%.*}"

# The newest beta or release candidate of a major version above the newest release.
fetch "${DOWNLOADS}/prereleases/" "${TMP_DIR}/prereleases.html"
prerelease="$(grep -oE 'nextcloud-[0-9]+\.0\.0(beta|rc)[0-9]+\.tar\.bz2"' "${TMP_DIR}/prereleases.html" |
	tr -d '"' | sort -u |
	sed -E 's/^nextcloud-([0-9]+)\.0\.0(beta|rc)([0-9]+)\.tar\.bz2$/\1 \2 \3 &/' |
	awk -v released="${released_major}" '$1 > released { print $1, ($2 == "rc" ? 2 : 1), $3, $4 }' |
	sort -k1,1n -k2,2n -k3,3n | tail -n 1 | cut -d ' ' -f 4 || true)"
if [[ -n "${prerelease}" ]]; then
	package="${DOWNLOADS}/prereleases/${prerelease}"
else
	package="${DOWNLOADS}/daily/latest-master.tar.bz2"
fi

mkdir "${TMP_DIR}/context"
archive="${TMP_DIR}/context/nextcloud.tar.bz2"
fetch "${package}" "${archive}"
fetch "${package}.asc" "${TMP_DIR}/nextcloud.tar.bz2.asc"
fetch https://nextcloud.com/nextcloud.asc "${TMP_DIR}/nextcloud.asc"
export GNUPGHOME="${TMP_DIR}/gnupg"
mkdir "${GNUPGHOME}"
chmod 700 "${GNUPGHOME}" 2>/dev/null || true
gpg --batch --quiet --import "${TMP_DIR}/nextcloud.asc" 2>/dev/null
if ! gpg --batch --status-fd 1 --verify "${TMP_DIR}/nextcloud.tar.bz2.asc" "${archive}" 2>/dev/null |
	grep -E "^\[GNUPG:\] VALIDSIG ${RELEASE_KEY} " >/dev/null; then
	echo "${package} carries no valid signature of the release key ${RELEASE_KEY}." >&2
	exit 1
fi
built="$(date -u -r "${archive}" +%Y-%m-%d)"

# The code of the coming Nextcloud replaces that of the image; the configuration the
# image adds for Docker stays.
"${DOCKER_BIN}" build --quiet --tag "${IMAGE}" --build-arg "BASE_IMAGE=${BASE_IMAGE}" \
	--file - "${TMP_DIR}/context" >/dev/null <<'DOCKERFILE'
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
COPY nextcloud.tar.bz2 /tmp/nextcloud.tar.bz2
RUN set -eux; \
	mv /usr/src/nextcloud/config /tmp/image-config; \
	rm -rf /usr/src/nextcloud; \
	tar -xjf /tmp/nextcloud.tar.bz2 -C /usr/src/; \
	rm -rf /tmp/nextcloud.tar.bz2 /usr/src/nextcloud/updater /tmp/image-config/config.sample.php; \
	cp /tmp/image-config/*.php /usr/src/nextcloud/config/; \
	rm -rf /tmp/image-config; \
	mkdir -p /usr/src/nextcloud/data /usr/src/nextcloud/custom_apps; \
	chmod +x /usr/src/nextcloud/occ
DOCKERFILE

version="$("${DOCKER_BIN}" run --rm --entrypoint php "${IMAGE}" -r "${VERSION_CODE}")"
label="Nextcloud ${version} (${package##*/} of ${built})"
echo "The coming Nextcloud: ${label}, in the image of Nextcloud ${released}."
if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
	echo "version=${label}" >>"${GITHUB_OUTPUT}"
fi

NEXTCLOUD_IMAGE="${IMAGE}" E2E_IGNORE_MAX_VERSION=1 bash "${SCRIPT_DIR}/run.sh"
