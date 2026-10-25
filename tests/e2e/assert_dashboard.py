#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Checks an answer of the API of Nextcloud's dashboard about the widget of the app, for
run.sh, from standard input:

    assert_dashboard.py listed owner|reader   /api/v1/widgets offers the widget, with its
                                              title and its icon, and for a reader, whom
                                              the archive account shares files with, the
                                              link to them, which goes to standard output
    assert_dashboard.py unlisted              /api/v1/widgets doesn't offer the widget
    assert_dashboard.py translated TITLE LINK /api/v1/widgets offers the widget of a reader
                                              with this title and this text of its link,
                                              in the language of the user
    assert_dashboard.py items TITLE...        /api/v2/widget-items lists these documents,
                                              in this order, each with its file
    assert_dashboard.py empty MESSAGE         /api/v2/widget-items lists no document, and
                                              says so with the message
"""

import json
import re
import sys
from urllib.parse import parse_qs, urlparse

WIDGET = "paperless_unified_search-recent"

# What the widget shows of a document of the mock: the line below its title, with the day
# that the mock added it in the language of the user, its correspondent and its type, and
# the moment that the mock added it, as the sinceId of the item.
DOCUMENTS = {
    "Mobile viewer test": ("Aug 27, 2026 · Example Mobile · Letter", "2026-08-27T14:00:00.000000+02:00"),
}


def fail(message):
    raise AssertionError(message)


def absolute(url):
    return urlparse(url) if urlparse(url).scheme in ("http", "https") else None


payload = json.load(sys.stdin)
mode = sys.argv[1]
data = payload.get("ocs", {}).get("data")
# PHP sends an empty map as an empty list.
data = {} if data == [] else data
if not isinstance(data, dict):
    fail(f"Missing OCS data object: {payload!r}")

if mode == "unlisted":
    if WIDGET in data:
        fail(f"The dashboard offers the widget of an app that isn't set up: {data[WIDGET]!r}")
    sys.exit(0)

widget = data.get(WIDGET)
if not isinstance(widget, dict):
    fail(f"The dashboard has no widget {WIDGET}: {sorted(data)!r}")

if mode == "translated":
    buttons = widget.get("buttons") or [{}]
    if widget.get("title") != sys.argv[2] or buttons[0].get("text") != sys.argv[3]:
        fail(f"Expected the title {sys.argv[2]!r} and the link {sys.argv[3]!r} in the language of the user, got {widget!r}")
    sys.exit(0)

if mode == "listed":
    if widget.get("title") != "New in Paperless":
        fail(f"Unexpected title of the widget: {widget!r}")
    icon = absolute(widget.get("icon_url", ""))
    if icon is None or not icon.path.endswith("/img/app-dark.svg"):
        fail(f"The icon of the widget isn't the absolute URL of the dark icon of the app: {widget!r}")
    # Nextcloud draws the widget from its items, without a script of the app.
    if 2 not in widget.get("item_api_versions", []):
        fail(f"The widget has no items of version 2 of the API: {widget!r}")
    buttons = widget.get("buttons")
    if sys.argv[2] == "owner":
        if buttons != []:
            fail(f"The archive account, which owns every file, gets a link to files shared with it: {widget!r}")
        sys.exit(0)
    if (
        not isinstance(buttons, list)
        or len(buttons) != 1
        or buttons[0].get("type") != "more"
        or buttons[0].get("text") != "Files shared with you"
        or absolute(buttons[0].get("link", "")) is None
        or not buttons[0]["link"].endswith("/apps/files/sharingin")
    ):
        fail(f"The widget doesn't link the files shared with the user: {widget!r}")
    print(buttons[0]["link"])
    sys.exit(0)

items = widget.get("items")
if not isinstance(items, list):
    fail(f"The widget has no list of items: {widget!r}")

if mode == "empty":
    if items:
        fail(f"Expected no documents, got {items!r}")
    if widget.get("emptyContentMessage") != sys.argv[2]:
        fail(f"Expected the message {sys.argv[2]!r}, got {widget!r}")
    sys.exit(0)

if mode != "items":
    fail(f"Unknown assertion mode: {mode}")

titles = [item.get("title") for item in items]
if titles != sys.argv[2:]:
    fail(f"Expected the documents {sys.argv[2:]!r}, got {titles!r}")
if widget.get("emptyContentMessage") != "":
    fail(f"A widget with documents has a message for none: {widget!r}")
for item in items:
    subtitle, since_id = DOCUMENTS[item["title"]]
    if item.get("subtitle") != subtitle or item.get("sinceId") != since_id:
        fail(f"Expected the line {subtitle!r} and the sinceId {since_id!r}, got {item!r}")
    # The link of the file in Files, and its preview, both as absolute URLs, which the
    # apps for phones open as well.
    link = absolute(item.get("link", ""))
    file_id = re.fullmatch(r".*/f/(\d+)", link.path if link else "")
    if file_id is None:
        fail(f"The document doesn't link its file: {item!r}")
    icon = absolute(item.get("iconUrl", ""))
    if icon is None or not icon.path.endswith("/core/preview") or parse_qs(icon.query).get("fileId") != [file_id.group(1)]:
        fail(f"The document shows no preview of its file: {item!r}")
