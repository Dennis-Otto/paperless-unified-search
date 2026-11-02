# The tools that finish the pictures of scripts/screenshots.sh, which
# scripts/screenshot-media.sh runs: ffmpeg makes the videos that the website plays in
# place of the animations and the pictures that show until they play, and oxipng
# shrinks every PNG without changing a pixel. Renovate keeps the image of Alpine
# current; ffmpeg and oxipng are those of its release.
FROM alpine:3.24@sha256:be2656061b536c1883c4034790e21d0a09c5aaaf51968b89368a3c0371ea2c9a

RUN apk add --no-cache ffmpeg oxipng
