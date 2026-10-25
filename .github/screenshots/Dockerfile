# The tools that finish the pictures of scripts/screenshots.sh, which
# scripts/screenshot-media.sh runs: ffmpeg makes the videos that the website plays in
# place of the animations and the pictures that show until they play, and oxipng
# shrinks every PNG without changing a pixel. Renovate keeps the image of Alpine
# current; ffmpeg and oxipng are those of its release.
FROM alpine:3.24@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6

RUN apk add --no-cache ffmpeg oxipng
