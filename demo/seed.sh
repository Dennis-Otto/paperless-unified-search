#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# The demo of Paperless Unified Search in one command: a real Paperless-ngx and a real
# Nextcloud in Docker, on this machine alone, with five synthetic documents that Paperless
# Sync writes into the archive account paperless, which shares them read-only with jamie.
# The app is set up with occ, as on a server, and the script ends with a search as jamie,
# which shows the documents, and with the addresses and the accounts to sign in with.
#
#   bash demo/seed.sh                                     # start the demo, or again
#   docker compose -f demo/compose.yaml down --volumes    # remove it with all its data
#
# The first run generates the secret key of Paperless and the passwords of the demo into
# demo/.env, which git ignores; later runs keep them. Every step may run again: a second
# run starts what stopped, and adds what is missing. It needs Docker with Compose and
# Bash, nothing else: the steps that talk to the APIs run in the container of Paperless
# (seed.py), and those of occ in the one of Nextcloud. README.md in this folder says
# what to try.

set -euo pipefail

DEMO_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${DEMO_DIR}/.env"
DOCKER_BIN="${DOCKER_BIN:-docker}"
# The name of the Compose project in compose.yaml, which labels its volumes.
PROJECT_NAME="paperless-unified-search-demo"
COMPOSE=("${DOCKER_BIN}" compose --file "${DEMO_DIR}/compose.yaml" --env-file "${ENV_FILE}")
# The accounts of the demo; their passwords are in demo/.env.
NEXTCLOUD_ADMIN_USER="admin"
ARCHIVE_USER="paperless"
DEMO_USER="jamie"
# The search that the end of the script shows, and the documents that it finds.
TERM_OF_SEARCH="invoice"
EXPECTED_RESULTS=3
# Paperless Sync, a signed release of it, checked by its SHA-256: Renovate brings a new
# release as a pull request, which runs the demo (.github/workflows/demo.yml), so that
# a release that breaks the demo never reaches it unseen.
# renovate: datasource=github-release-attachments depName=Dennis-Otto/paperless-sync
PAPERLESS_SYNC_VERSION="v1.2.0"
PAPERLESS_SYNC_SHA256="1041313905b7d31460c78805b107504f79f56b0bc5fbe36ff6230d8e8e3e60b3"
# Git Bash on Windows: keep the paths in the containers as they are.
export MSYS2_ARG_CONV_EXCL="/demo/${MSYS2_ARG_CONV_EXCL:+;${MSYS2_ARG_CONV_EXCL}}"

phase() {
	printf '\n== %s s: %s\n' "${SECONDS}" "$1"
}

# Random hexadecimal digits, twice as many as the bytes.
secret() {
	od -An -tx1 -N"$1" /dev/urandom | tr -d ' \n'
}

# A value of demo/.env.
setting() {
	sed -n "s/^$1=//p" "${ENV_FILE}" | tail -n 1
}

occ() {
	"${COMPOSE[@]}" exec -T --user www-data nextcloud php occ "$@"
}

# Another version of this app, after a git pull or a checkout of a release, or of
# Paperless Sync, needs occ upgrade, before occ offers its other commands again.
upgrade() {
	if occ status --output=json 2>/dev/null | grep -qE '"needsDbUpgrade": ?true'; then
		occ upgrade --no-interaction
	fi
}

# seed.py in the container of Paperless, in the network of the demo.
seed() {
	"${COMPOSE[@]}" exec -T "${@:2}" paperless python3 /demo/seed.py "$1"
}

failed() {
	status=$?
	trap - EXIT
	if [[ "${status}" -ne 0 ]]; then
		"${COMPOSE[@]}" ps >&2 || true
		"${COMPOSE[@]}" logs --no-color --tail 15 >&2 || true
		cat >&2 <<-TEXT

			The demo stopped after ${SECONDS} s; the end of the logs of its containers is above.
			Run bash demo/seed.sh again to continue, or start anew without its data:
			  docker compose -f demo/compose.yaml down --volumes && bash demo/seed.sh
		TEXT
	fi
	exit "${status}"
}

if ! "${DOCKER_BIN}" compose version >/dev/null 2>&1 || ! "${DOCKER_BIN}" info >/dev/null 2>&1; then
	echo "The demo needs Docker with Compose, and Docker running: https://docs.docker.com/get-docker/" >&2
	exit 1
fi
trap failed EXIT

if [[ ! -f "${ENV_FILE}" ]]; then
	# The passwords in demo/.env will be those of new accounts: the data of an earlier
	# demo has accounts with other passwords. So this check comes before demo/.env, and
	# every run without it stops here, until the data is gone.
	if [[ -n "$("${DOCKER_BIN}" volume ls --quiet --filter "label=com.docker.compose.project=${PROJECT_NAME}")" ]]; then
		trap - EXIT
		echo "An earlier demo left its data, but not its passwords in demo/.env. Remove its data first:" >&2
		echo "  docker compose --project-name ${PROJECT_NAME} down --volumes && bash demo/seed.sh" >&2
		exit 1
	fi
	phase "Generate the secret key and the passwords of the demo into demo/.env"
	(
		umask 077
		cat >"${ENV_FILE}" <<-ENV
			# The secrets of the demo of Paperless Unified Search, which demo/seed.sh generated
			# on this machine; git ignores this file. The demo alone uses them.
			PAPERLESS_SECRET_KEY=$(secret 32)
			PAPERLESS_ADMIN_PASSWORD=$(secret 12)
			NEXTCLOUD_ADMIN_PASSWORD=$(secret 12)
			NEXTCLOUD_DATABASE_PASSWORD=$(secret 16)
			ARCHIVE_PASSWORD=$(secret 12)
			DEMO_PASSWORD=$(secret 12)
			# The ports on 127.0.0.1 where the browser reaches Nextcloud and Paperless.
			NEXTCLOUD_PORT=${NEXTCLOUD_PORT:-8180}
			PAPERLESS_PORT=${PAPERLESS_PORT:-8100}
		ENV
	)
fi

NEXTCLOUD_ADMIN_PASSWORD="$(setting NEXTCLOUD_ADMIN_PASSWORD)"
ARCHIVE_PASSWORD="$(setting ARCHIVE_PASSWORD)"
DEMO_PASSWORD="$(setting DEMO_PASSWORD)"
PAPERLESS_ADMIN_PASSWORD="$(setting PAPERLESS_ADMIN_PASSWORD)"
NEXTCLOUD_PORT="$(setting NEXTCLOUD_PORT)"
PAPERLESS_PORT="$(setting PAPERLESS_PORT)"
# Docker hands these to the commands in the containers that name them with --env, and
# to no other.
export NEXTCLOUD_ADMIN_USER NEXTCLOUD_ADMIN_PASSWORD ARCHIVE_USER ARCHIVE_PASSWORD DEMO_USER 	PAPERLESS_SYNC_VERSION PAPERLESS_SYNC_SHA256
if [[ "${GITHUB_ACTIONS:-}" == true ]]; then
	for password in "${NEXTCLOUD_ADMIN_PASSWORD}" "${ARCHIVE_PASSWORD}" "${DEMO_PASSWORD}" "${PAPERLESS_ADMIN_PASSWORD}"; do
		echo "::add-mask::${password}"
	done
fi

phase "Start Paperless-ngx, Redis and Nextcloud; the first start downloads their images"
"${COMPOSE[@]}" up --detach --wait --wait-timeout 900
upgrade

phase "Set up the accounts, the apps and the background jobs of Nextcloud"
for account in "${ARCHIVE_USER}:Paperless archive:${ARCHIVE_PASSWORD}" "${DEMO_USER}:Jamie Example:${DEMO_PASSWORD}"; do
	name="${account%%:*}"
	rest="${account#*:}"
	if ! occ user:info "${name}" >/dev/null 2>&1; then
		OC_PASS="${rest#*:}" "${COMPOSE[@]}" exec -T --user www-data --env OC_PASS nextcloud \
			php occ user:add --password-from-env --display-name="${rest%%:*}" "${name}"
	fi
done
restart=0
# Docker made custom_apps, the folder of the apps from the App Store, as the mount point of
# this app, so it belongs to root, and the web server couldn't install an app there.
"${COMPOSE[@]}" exec -T nextcloud chown www-data:www-data custom_apps
if [[ "$(occ config:app:get paperless_sync installed_version 2>/dev/null || true)" != "${PAPERLESS_SYNC_VERSION#v}" ]]; then
	# The package of the release, as an administrator installs it without the App Store:
	# downloaded and checked in the container of Nextcloud, then unpacked in place of an
	# older version. PHP, not the shell, reads the variables of the script.
	# shellcheck disable=SC2016
	"${COMPOSE[@]}" exec -T --user www-data 		--env PAPERLESS_SYNC_VERSION --env PAPERLESS_SYNC_SHA256 nextcloud php -r '
			$url = "https://github.com/Dennis-Otto/paperless-sync/releases/download/"
				. getenv("PAPERLESS_SYNC_VERSION") . "/paperless_sync.tar.gz";
			$package = file_get_contents($url);
			if ($package === false || hash("sha256", $package) !== getenv("PAPERLESS_SYNC_SHA256")) {
				fwrite(STDERR, "The package of Paperless Sync at $url is not the one with the SHA-256 in demo/seed.sh.
");
				exit(1);
			}
			file_put_contents("/tmp/paperless_sync.tar.gz", $package);'
	"${COMPOSE[@]}" exec -T --user www-data nextcloud 		sh -c 'rm -rf custom_apps/paperless_sync && tar -xzf /tmp/paperless_sync.tar.gz -C custom_apps && rm /tmp/paperless_sync.tar.gz'
	upgrade
	restart=1
fi
for app in paperless_sync paperless_unified_search; do
	if [[ "$(occ config:app:get "${app}" enabled 2>/dev/null || true)" != yes ]]; then
		occ app:enable "${app}"
		restart=1
	fi
done
occ background:cron >/dev/null
if [[ "${restart}" == 1 ]]; then
	# The web server reads the routes of an app that it enabled only after a restart.
	"${COMPOSE[@]}" restart nextcloud >/dev/null
	"${COMPOSE[@]}" up --detach --wait --wait-timeout 300 >/dev/null
fi

phase "Give Paperless the five documents of the demo"
seed documents

phase "Synchronize them into Nextcloud with Paperless Sync, and share the archive"
seed archive --env NEXTCLOUD_ADMIN_USER --env NEXTCLOUD_ADMIN_PASSWORD \
	--env ARCHIVE_USER --env ARCHIVE_PASSWORD --env DEMO_USER

phase "Connect Paperless Unified Search to Paperless with occ"
# The token goes from one container to the other through this pipe alone: never on a
# command line, where the list of processes would show it.
seed token | occ paperless_unified_search:setup --url=http://paperless:8000 \
	--archive-account="${ARCHIVE_USER}" --allow-local --always-search --token-stdin

phase "Check the set-up, and search as ${DEMO_USER}"
occ paperless_unified_search:check
occ paperless_unified_search:search "${DEMO_USER}" "${TERM_OF_SEARCH}"
shown="$(occ paperless_unified_search:search "${DEMO_USER}" "${TERM_OF_SEARCH}" --json | grep -cE '"verdict": ?"shown"' || true)"
if [[ "${shown}" -lt "${EXPECTED_RESULTS}" ]]; then
	echo "The search for ${TERM_OF_SEARCH} shows ${shown} document(s) to ${DEMO_USER}, not ${EXPECTED_RESULTS}." >&2
	exit 1
fi

if [[ "${GITHUB_ACTIONS:-}" == true ]]; then
	shown_password="(masked; it is in demo/.env)"
else
	shown_password=""
fi
cat <<TEXT

== The demo is ready after ${SECONDS} s.

Nextcloud:  http://localhost:${NEXTCLOUD_PORT}
  ${DEMO_USER}   ${shown_password:-${DEMO_PASSWORD}}   finds the documents: search for ${TERM_OF_SEARCH}, warranty or Northstar
  ${NEXTCLOUD_ADMIN_USER}   ${shown_password:-${NEXTCLOUD_ADMIN_PASSWORD}}   the administrator: Administration settings, Paperless Unified Search
Paperless:  http://localhost:${PAPERLESS_PORT}
  admin   ${shown_password:-${PAPERLESS_ADMIN_PASSWORD}}   add a document, and it is in Nextcloud within five minutes

The passwords are in demo/.env, and demo/README.md says what to try.
Remove the demo with its data:  docker compose -f demo/compose.yaml down --volumes
TEXT
