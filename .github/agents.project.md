# Notes of this project for coding agents

Paperless Unified Search: OCR-powered Paperless-ngx search, seamlessly integrated into Nextcloud

This file belongs to the project: the blueprint wrote it once, and its updates leave it alone. It adds to `AGENTS.md` what a coding agent needs to know about this project alone, such as its architecture, the commands of its own checks and what to watch for in its tests.

## Where things are

- The app is in `lib/`, its metadata, version and supported Nextcloud versions in `appinfo/info.xml`.
- `README.md` documents installation, configuration and use, `docs/` holds the pages of the website, and `CHANGELOG.md` lists the changes of every release.
- The tests are in `tests/`; `scripts/check.sh` runs every check.
