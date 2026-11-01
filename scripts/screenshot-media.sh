#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Finishes the pictures that scripts/screenshots.sh took, in the folder that it names,
# in place. Beside each animation NAME.gif it makes NAME.mp4, the video that the website
# plays in its place, and NAME-poster.webp, its first frame,
# which the website shows until the video plays and, for a reader who asks for less
# motion, in its place. Named NAME.webp, the hooks of the blueprint would make it the
# poster of the video, which a browser loads also for the video of the other theme;
# docs/javascripts/hero.js gives it to the video that shows. It shrinks each PNG
# without changing a pixel. ffmpeg and oxipng run in the
# image of .github/screenshots/Dockerfile, and the same pictures make the same bytes in
# every run, so the screenshot bot renews a video only when its animation changed.
#   bash scripts/screenshot-media.sh FOLDER
set -euo pipefail

if [[ $# -ne 1 || ! -d "$1" ]]; then
	echo "Usage: bash scripts/screenshot-media.sh FOLDER" >&2
	exit 2
fi
folder="$(cd "$1" && pwd)"
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Git Bash on Windows: keep the paths of the container as they are.
export MSYS_NO_PATHCONV=1
if ! image="$(docker build --quiet .github/screenshots)"; then
	echo "Building the image of .github/screenshots/Dockerfile failed." >&2
	exit 1
fi

# The pictures reach the container through standard input and leave it the same way,
# so that they belong to whoever runs this. H.264 in yuv420p plays in every browser;
# one thread and no version of the encoder in the file keep its bytes the same.
tar -C "${folder}" -cf - . |
	docker run --rm --interactive "${image}" sh -c '
		set -e
		mkdir /tmp/pictures && cd /tmp/pictures && tar -xf -
		for gif in *.gif; do
			[ -e "$gif" ] || continue
			name="${gif%.gif}"
			ffmpeg -loglevel error -i "$gif" -c:v libx264 -preset veryslow -crf 28 \
				-pix_fmt yuv420p -threads 1 -an -movflags +faststart \
				-fflags +bitexact -flags:v +bitexact -map_metadata -1 "$name.mp4" >&2
			ffmpeg -loglevel error -i "$gif" -frames:v 1 -c:v libwebp -quality 80 "$name-poster.webp" >&2
		done
		set -- *.png
		if [ -e "$1" ]; then
			oxipng --quiet --opt 4 --strip safe -- "$@" >&2
		fi
		tar -cf - .' |
	tar -C "${folder}" -xf -
