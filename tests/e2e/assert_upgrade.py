#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# What upgrade.sh compares before and after the upgrade from the last release, from
# standard input, as stable lines on standard output:
#
#   assert_upgrade.py results [path] < answer.json   the results of a search of Nextcloud
#   assert_upgrade.py settings < settings.html       the administration settings of the app
#   assert_upgrade.py keys < config.json             the keys of occ config:list
#
# - results: a line "<fileId> <path> <title>" for each result of the answer of the OCS
#   search, sorted; it fails without a result, and without one at the path given.
# - settings: a line "<field>=<value>" for each setting that the page of the
#   administration settings shows, as the app reads it: the URL, the archive account,
#   each switch "on" or "off", and whether a token is stored. A setting that the app no
#   longer finds after an update, such as one read from a key that it renamed, shows
#   here as empty or off.
# - keys: the keys that the app keeps in the configuration of the apps, one per line,
#   sorted, without their values.
#
# upgrade.sh runs it in the container of the mock of Paperless, which has Python, and
# selftest.sh tests it with answers of its own.

import json
import sys
from html.parser import HTMLParser

APP_ID = "paperless_unified_search"
# The fields of the form of the administration settings, by their name, and the element
# of the app, whose data-token-configured says whether a token is stored.
TEXT_FIELDS = ("url", "archiveOwner")
SWITCHES = ("allowLocal", "alwaysSearch", "personalAlwaysSearch")
SETTINGS_ID = "paperless-unified-search-settings"


def fail(message):
    print(message, file=sys.stderr)
    sys.exit(1)


def results(answer, path=None):
    data = answer.get("ocs", {}).get("data") if isinstance(answer, dict) else None
    entries = data.get("entries", []) if isinstance(data, dict) else []
    if not entries:
        fail(f"The search found nothing: {json.dumps(answer)[:500]}")
    lines = []
    for entry in entries:
        attributes = entry.get("attributes") or {}
        lines.append(f"{attributes.get('fileId', '?')} {attributes.get('path', '?')} {entry.get('title', '?')}")
    if path is not None and not any(
        (entry.get("attributes") or {}).get("path") == path for entry in entries
    ):
        fail(f"No result of the search is the file {path}: {lines!r}")
    return sorted(lines)


class SettingsPage(HTMLParser):
    """The settings that the form of the administration settings shows."""

    def __init__(self):
        super().__init__()
        self.found = {}

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if attributes.get("id") == SETTINGS_ID:
            configured = attributes.get("data-token-configured")
            self.found["token"] = "stored" if configured == "true" else "missing"
        if tag != "input":
            return
        name = attributes.get("name")
        if name in TEXT_FIELDS:
            self.found[name] = attributes.get("value") or ""
        elif name in SWITCHES:
            self.found[name] = "on" if "checked" in attributes else "off"


def settings(page):
    parser = SettingsPage()
    parser.feed(page)
    if "token" not in parser.found or "url" not in parser.found:
        fail(f"The page shows no administration settings of the app: {page[:500]!r}")
    return [f"{field}={value}" for field, value in sorted(parser.found.items())]


def keys(config):
    values = config.get("apps", {}).get(APP_ID) if isinstance(config, dict) else None
    if not isinstance(values, dict) or not values:
        fail(f"occ config:list names no key of the app: {json.dumps(config)[:500]}")
    return sorted(values)


def main(arguments):
    mode = arguments[0] if arguments else ""
    if mode == "results" and len(arguments) <= 2:
        lines = results(json.load(sys.stdin), *arguments[1:])
    elif mode == "settings" and len(arguments) == 1:
        lines = settings(sys.stdin.read())
    elif mode == "keys" and len(arguments) == 1:
        lines = keys(json.load(sys.stdin))
    else:
        fail("Usage: assert_upgrade.py results [path] | settings | keys, with the answer on standard input")
    print("\n".join(lines))


if __name__ == "__main__":
    main(sys.argv[1:])
