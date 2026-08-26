#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import sys
from urllib.parse import parse_qs, urlparse


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

if not isinstance(data, dict):
    fail(f"Missing OCS data object: {payload!r}")

entries = data.get("entries", [])
if len(entries) != 1:
    fail(f"Expected one accessible result, got {entries!r}")

entry = entries[0]
if entry.get("title") != "Mobile viewer test":
    fail(f"Unexpected title: {entry!r}")
if "Synthetic OCR mobiletest result" not in entry.get("subline", ""):
    fail(f"OCR highlight is missing: {entry!r}")

attributes = entry.get("attributes", {})
if not str(attributes.get("fileId", "")).isdigit():
    fail(f"Missing numeric fileId: {entry!r}")
if attributes.get("path") != "/Documents/Mobile viewer test [P123].pdf":
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
elif mode in ("browser", "android"):
    if not resource_url.endswith(f"/f/{attributes['fileId']}"):
        fail(f"Unexpected web/native viewer link: {resource_url}")
else:
    fail(f"Unknown assertion mode: {mode}")
