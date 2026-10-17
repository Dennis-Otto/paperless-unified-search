### Bug fixes

- **The archive account of Paperless Sync 0.3:** with no archive account of its own, the app takes the account that Paperless Sync writes the archive with again. Paperless Sync 0.3.0 stores it in a way that the app didn't read, so the search showed no Paperless documents at all, and the settings, `occ` and the overview said that Paperless Sync names no account.
