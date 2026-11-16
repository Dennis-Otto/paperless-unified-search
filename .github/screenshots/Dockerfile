# The tools that finish the pictures of scripts/screenshots.sh, which
# scripts/screenshot-media.sh runs: ffmpeg makes the videos that the website plays in
# place of the animations and the pictures that show until they play, and oxipng
# shrinks every PNG without changing a pixel. Renovate keeps the image of Alpine
# current; ffmpeg and oxipng are those of its release.
FROM alpine:3.24@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf

RUN apk add --no-cache ffmpeg oxipng
