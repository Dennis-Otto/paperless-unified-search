### Bug fixes

- A file that the archive account, or the owner of a document, shares on its own still stands for its Paperless document when a recipient renames it to a name without the marker, such as `Invoice [P5].pdf` to `Invoice.pdf`, as it does when the new name carries another marker.
- The history of the search problems hides a search term only where the message holds it as a word of its own: a search for `time` or `error` no longer turns a timeout into "Operation …d out" or "cURL … 28". The API token still goes wherever it stands.
- The settings say which files stand for Paperless documents with the per-user archives of Paperless Sync: those of the archive account and of each document's owner, not of the archive account alone.
- The documentation says that the app registers one background job, for the notifications about new documents, which need Nextcloud's background jobs to run; that it needs Paperless-ngx 2.16 or newer; that **Disconnect** also deletes the progress of those notifications; and that every release carries SBOMs as SPDX and CycloneDX and the `THIRD_PARTY_NOTICES.md`.
