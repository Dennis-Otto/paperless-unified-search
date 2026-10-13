#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import sys
from urllib.parse import parse_qs, urlparse


# The line below the title of the result for "mobiletest": the date of the document and
# its excerpt, which Paperless sends marked up and escaped as HTML, as text.
SUBLINE = "2026-08-26 · Synthetic OCR mobiletest result for O'Brien & \"Sons\" <GmbH>"


def fail(message):
    raise AssertionError(message)


payload = json.load(sys.stdin)
mode = sys.argv[1]
data = payload.get("ocs", {}).get("data")

if mode == "no-results":
    entries = data.get("entries", []) if isinstance(data, dict) else []
    if entries:
        fail(f"Expected no results, got {entries!r}")
    sys.exit(0)

if mode in ("external", "trusted"):
    providers = data if isinstance(data, list) else []
    provider = next(
        (item for item in providers if item.get("id") == "paperless_unified_search_documents"),
        None,
    )
    if provider is None:
        fail(f"Paperless search provider is missing: {providers!r}")
    expected = mode == "external"
    if provider.get("isExternalProvider") is not expected:
        fail(f"Expected isExternalProvider={expected}, got {provider!r}")
    sys.exit(0)

if mode == "picker":
    # The providers of the smart picker, from /ocs/v2.php/references/providers.
    providers = data if isinstance(data, list) else []
    provider = next(
        (item for item in providers if item.get("id") == "paperless_unified_search-documents"),
        None,
    )
    if provider is None:
        fail(f"The smart picker offers no Paperless documents: {providers!r}")
    if provider.get("title") != "Paperless documents":
        fail(f"Unexpected title in the smart picker: {provider!r}")
    if provider.get("search_providers_ids") != ["paperless_unified_search_documents"]:
        fail(f"The smart picker doesn't search through the search provider: {provider!r}")
    icon = urlparse(provider.get("icon_url", ""))
    if icon.scheme not in ("http", "https") or not icon.path.endswith("/img/picker-dark.svg"):
        fail(f"The icon in the smart picker isn't the absolute URL of its dark icon: {provider!r}")
    # The path of the icon, which run.sh loads as the picker does.
    print(icon.path)
    sys.exit(0)

if mode in ("file-link", "no-file-link"):
    # A link that the smart picker inserted, resolved by /ocs/v2.php/references/resolve.
    references = data.get("references", {}) if isinstance(data, dict) else {}
    if len(references) != 1:
        fail(f"Expected one resolved link, got {payload!r}")
    link, reference = next(iter(references.items()))
    if not isinstance(reference, dict) or reference.get("richObjectType") != "file":
        fail(f"Nextcloud doesn't render {link} as a file: {reference!r}")
    rich_object = reference.get("richObject")
    if mode == "no-file-link":
        # Nextcloud shows the bare link, without the name or any other detail of the file.
        details = {"path", "size", "mimetype", "mtime", "preview-available"}
        if (
            reference.get("accessible") is not False
            or (isinstance(rich_object, dict) and (details & rich_object.keys() or rich_object.get("name") != link))
        ):
            fail(f"A user who can't open the file sees it in {link}: {reference!r}")
        sys.exit(0)
    if reference.get("accessible") is not True or not isinstance(rich_object, dict):
        fail(f"A user who can open the file doesn't see it in {link}: {reference!r}")
    if not link.endswith(f"/f/{rich_object.get('id')}"):
        fail(f"{link} renders another file: {reference!r}")
    if rich_object.get("name") != "Mobile viewer test [P123].pdf":
        fail(f"{link} renders a file of another name: {reference!r}")
    sys.exit(0)

if not isinstance(data, dict):
    fail(f"Missing OCS data object: {payload!r}")

if mode == "page":
    # A page of the search for "pagetest": "more" or "last", whether another page
    # follows, and the IDs of the documents of its entries in their order. The cursor of
    # the next page goes to standard output.
    follows = sys.argv[2] == "more"
    expected = [f"Page test {document}" for document in sys.argv[3:]]
    titles = [entry.get("title") for entry in data.get("entries", [])]
    if titles != expected:
        fail(f"Expected the entries {expected!r}, got {titles!r}")
    if data.get("isPaginated") is not follows:
        fail(f"Expected isPaginated={follows}, got {data!r}")
    print(data.get("cursor"))
    sys.exit(0)

entries = data.get("entries", [])
if len(entries) != 1:
    fail(f"Expected one accessible result, got {entries!r}")

entry = entries[0]
if entry.get("title") != "Mobile viewer test":
    fail(f"Unexpected title: {entry!r}")
if entry.get("subline") != SUBLINE:
    fail(f"Expected the subline {SUBLINE!r}, got {entry!r}")

attributes = entry.get("attributes", {})
if not str(attributes.get("fileId", "")).isdigit():
    fail(f"Missing numeric fileId: {entry!r}")
# A share of the archive account appears in the root folder of its recipient.
expected_path = (
    "/Mobile viewer test [P123].pdf"
    if mode == "shared"
    else "/Documents/Mobile viewer test [P123].pdf"
)
if attributes.get("path") != expected_path:
    fail(f"Unexpected user-relative path: {entry!r}")

resource_url = entry.get("resourceUrl", "")
if mode == "ios":
    parsed = urlparse(resource_url)
    params = parse_qs(parsed.query)
    if parsed.scheme != "nextcloud" or parsed.netloc != "open-file":
        fail(f"Unexpected iOS deep link: {resource_url}")
    if params.get("user") != ["e2e-user"]:
        fail(f"iOS deep link has the wrong user: {resource_url}")
    if not params.get("link", [""])[0].endswith(f"/f/{attributes['fileId']}"):
        fail(f"iOS deep link has the wrong web fallback: {resource_url}")
elif mode in ("browser", "android", "shared", "link"):
    if not resource_url.endswith(f"/f/{attributes['fileId']}"):
        fail(f"Unexpected web/native viewer link: {resource_url}")
    if mode == "link":
        # The link that the smart picker inserts for the result.
        print(resource_url)
else:
    fail(f"Unknown assertion mode: {mode}")
