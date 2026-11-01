# Answers of a real Paperless-ngx

The unit tests check the results of the search against these answers, which real Paperless-ngx servers gave, byte for byte, with a line break added at the end. They are a record of what those two versions said, and don't follow a new release: that a current Paperless still answers in this form, the contract tests check in every run, against the versions of `tests/e2e/compose.paperless.yaml`, for the app and, with `tests/e2e/parity.py`, for the mock of the end-to-end tests ([Against a real Paperless-ngx](../../e2e/README.md#against-a-real-paperless-ngx)). They were measured on 14 October 2026 with the images `ghcr.io/paperless-ngx/paperless-ngx:3.3.0` and `:2.20.13`, with Redis, SQLite and `PAPERLESS_TIME_ZONE=Europe/Berlin`.

The archive held three text documents, all created on 2026-08-12:

| ID | Title | Text | Correspondent | Type | Note |
| --- | --- | --- | --- | --- | --- |
| 1 | electricity | `Electricity invoice E-2026-0812 for August 2026.` / `O'Brien & Sons "Power" <GmbH>` / `Total due 84.30 EUR by September 2.` | 1, ACME Energy | 1, Invoice | |
| 2 | garden | `Scan of a garden plan.` / `Nothing else here.` | | | `Paid the invoice by bank transfer, O'Brien & <Partner>` |
| 3 | markup | `Plain letter number 7 about the invoice of the month.`, 120 lines of filler, then `It quotes <script>alert(1)</script> as text for the invoice.` | 2, `Harbor & Sons <Insurance>` | | |

Paperless-ngx 2.20.13 held document 1 alone, without correspondent and type. The requests named the fields of the documents as the app does, `fields=id,title,created,correspondent,document_type,__search_hit__`, and `Accept: application/json`:

| File | Paperless-ngx | Request |
| --- | --- | --- |
| `paperless-3.3.0-search.json` | 3.3.0 | `/api/documents/?query=invoice&page=1&page_size=25` |
| `paperless-3.3.0-search-script.json` | 3.3.0 | `/api/documents/?query=script&page=1&page_size=25` |
| `paperless-3.3.0-search-note.json` | 3.3.0 | `/api/documents/?query=notes:transfer&page=1&page_size=25` |
| `paperless-3.3.0-correspondents.json` | 3.3.0 | `/api/correspondents/?id__in=1,2,99&page_size=3` |
| `paperless-3.3.0-document-types.json` | 3.3.0 | `/api/document_types/?id__in=1&page_size=1` |
| `paperless-2.20.13-search.json` | 2.20.13 | `/api/documents/?query=invoice&page=1&page_size=25` |
| `paperless-2.20.13-search-api-5.json` | 2.20.13 | the same, with `Accept: application/json; version=5` |

What they show:

- Paperless marks the words of the term with `<b>` in version 3 and with `<span class="match term0">` in version 2, and escapes the text of the document as HTML around them: `&lt;GmbH&gt;`, and in version 3 the quotes as well.
- An excerpt of the notes comes in `note_highlights`, with an empty `highlights`; without one, `note_highlights` is `null` in version 3 and empty in version 2. Version 3.3.0 searches the notes only with `notes:` in the query.
- `created` is the day, `2026-08-12`, and for API versions before 9 the start of the day in the timezone of Paperless, `2026-08-12T00:00:00+02:00`.
- The lists of correspondents and types filter by `id__in`, in both versions, and leave out an ID that they don't have. Version 3.3.0 sends every field of an object, also when the request names fewer with `fields`.

The comparison of the mock with Paperless-ngx 2.20.15 and 3.3.1 showed what these requests don't: `__search_hit__` holds `score`, `highlights`, `note_highlights` and `rank`, and comes with every result of a search, named in `fields` or not, but never in a list without a search; a search adds `corrected_query` to the page, and version 3 `display_count` to the list of tags; each list holds `all`, the IDs of all its objects on every page; and a version in `Accept` that Paperless doesn't know gets HTTP 406, without `X-Version` and `X-Api-Version`, which also a wrong token gets none of.
