### Features

- **The demo in a browser:** `bash scripts/demo-gif.sh` records the demo as an animation, from its real Paperless-ngx and Nextcloud: `jamie` searches for *invoice*, and the search shows the real Paperless documents, the home insurance renewal among them, which opens in Nextcloud's viewer. Every run of the demo in the CI records it too, keeps the animation, and fails when the search in the browser doesn't show those documents.

### Bug fixes

- **The demo without IPv6:** Paperless-ngx of the demo listens on IPv4, so `bash demo/seed.sh` no longer stops at its first step where Docker has no IPv6.
