# Notes of this project for coding agents

Paperless Unified Search: OCR-powered Paperless-ngx search, seamlessly integrated into Nextcloud

This file belongs to the project: the blueprint wrote it once, and its updates leave it alone. It adds to `AGENTS.md` what a coding agent needs to know about this project alone, such as its architecture, the commands of its own checks and what to watch for in its tests.

## Where things are

- The app is in `lib/`, its metadata, version and supported Nextcloud versions in `appinfo/info.xml`.
- `README.md` documents installation, configuration and use, `docs/` holds the pages of the website, and `CHANGELOG.md` lists the changes of every release.
- The tests are in `tests/`; `scripts/check.sh` runs every check.

## English and German documentation

- `README.de.md` is the German twin of `README.md`, and every page of the website for users and administrators has one beside it, such as `docs/faq.de.md` next to `docs/faq.md`; the pages of the project, such as `docs/architecture.md`, are in English alone, and the German website in `de/` shows them in English. Change both languages in the same pull request, with the same headings, pictures, tables and blocks of code, which stay byte for byte as in English.
- The German is informal (*du*), as the German strings of the app (`l10n/de.json`), and names the controls with their German labels, such as **Verbindung testen und speichern** and **Verbundene Dienste durchsuchen**.
- The pages of the website include the sections of the READMEs by name, such as `--8<-- "README.de.md:usage"` in `docs/usage.de.md`, so `README.de.md` marks the same sections as `README.md`; `scripts/check-project.sh` checks it, the pictures and the linked pages of both READMEs, that each German page shows the pictures of its English twin, and the versions in both languages.
- A German page links the German pages, such as `configure.de.md#konfiguration`, with their German anchors. An English page without a German twin shows in English on the German website, and its links lead to the German pages there: a link with an anchor, such as `compare.md#when-to-choose-which`, fails the strict build of the website, since the German page has German anchors. Link that page without the anchor, or translate the linking page too.
- A diagram of a German page is `docs/diagrams/<name>.de.mmd`, the twin of `<name>.mmd`.
