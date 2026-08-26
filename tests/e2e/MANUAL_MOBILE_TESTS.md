# Mobile release test matrix

Automated E2E tests validate the server response contract. Before a release that changes result links, file metadata, or search-provider behavior, also perform these checks with the current official clients.

| Client | Required behavior | Evidence |
| --- | --- | --- |
| Browser | Result shows the Paperless title and OCR snippet; selection opens `/f/{fileId}` in Nextcloud's viewer. | Record browser and Nextcloud versions. |
| Nextcloud iOS | Result is selectable; the `nextcloud://open-file` link keeps the user in the Nextcloud app and opens the synchronized file. | Record iOS and Nextcloud iOS versions. |
| Nextcloud Android | Result is selectable; `fileId` and user-relative `path` open the synchronized file inside the Nextcloud app. | Record Android and Nextcloud Android versions. |

For every client, repeat the search with a user who cannot access the synchronized file and verify that the result is absent. Test both the default connected-services mode and the administrator-enabled trusted-service mode.

Use only synthetic documents and accounts in screenshots or logs. Never attach a production API token, hostname, document title, path, or OCR content to an issue.
