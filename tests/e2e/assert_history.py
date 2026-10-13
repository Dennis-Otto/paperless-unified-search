#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# Checks the history of the problems of the search, Search problems, on the page of the
# administration settings that Nextcloud rendered, from standard input:
#
#   assert_history.py recorded SECRET...  the failure and the retry of run.sh, after which
#                                         the search worked again, and none of the secrets
#                                         anywhere on the page
#   assert_history.py cleared             no problem
#
# run.sh runs it in the container of the mock of Paperless, which has Python.

import re
import sys
from html.parser import HTMLParser

DIAGNOSTICS_ID = "paperless-unified-search-diagnostics"
VOID_ELEMENTS = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "source", "track", "wbr"}
# The address of the mock as the request that got no answer named it, without its query,
# which repeats the term.
DOCUMENTS_URL = "http://paperless-mock:8080/api/documents/"


def fail(message):
    raise AssertionError(message)


class Diagnostics(HTMLParser):
    """The paragraphs and the rows of the tables inside the element of the history."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        # The depth of the element being read inside the history, 0 outside of it.
        self.depth = 0
        self.found = False
        self.rows = []
        self.paragraphs = []
        self._row = None
        self._text = None

    def handle_starttag(self, tag, attrs):
        if self.depth == 0:
            if dict(attrs).get("id") == DIAGNOSTICS_ID:
                self.depth = 1
                self.found = True
            return
        if tag in VOID_ELEMENTS:
            return
        self.depth += 1
        if tag == "tr":
            self._row = []
        elif tag in ("th", "td", "p"):
            self._text = []

    def handle_endtag(self, tag):
        if self.depth == 0 or tag in VOID_ELEMENTS:
            return
        self.depth -= 1
        if tag in ("th", "td") and self._row is not None and self._text is not None:
            self._row.append(" ".join("".join(self._text).split()))
            self._text = None
        elif tag == "p" and self._text is not None:
            self.paragraphs.append(" ".join("".join(self._text).split()))
            self._text = None
        elif tag == "tr" and self._row is not None:
            self.rows.append(self._row)
            self._row = None

    def handle_data(self, data):
        if self.depth > 0 and self._text is not None:
            self._text.append(data)


page = sys.stdin.read()
mode = sys.argv[1]
history = Diagnostics()
history.feed(page)
history.close()
if not history.found:
    fail(f"The page has no element #{DIAGNOSTICS_ID}.")

if mode == "cleared":
    if history.rows or history.paragraphs != ["No problem recorded."]:
        fail(f"Expected no problem, got the rows {history.rows!r} and the text {history.paragraphs!r}")
    print("Search problems: no problem recorded.")
    sys.exit(0)

if mode != "recorded":
    fail(f"Unknown assertion mode: {mode}")

# The history keeps neither the API token nor the terms, not even in the message of an
# error that repeats the URL of the request.
for secret in sys.argv[2:]:
    if secret.lower() in page.lower():
        fail(f"The page of the administration settings shows {secret!r}.")

summary = {row[0]: row[1] for row in history.rows if len(row) == 2}
expected = {"Failed searches": "1", "Answered on the second try": "1"}
for name, value in expected.items():
    if summary.get(name) != value:
        fail(f"Expected {name}: {value}, got {summary!r}")
# The search that the second try answered worked after the failure.
if summary.get("Searches work again since", "No search has worked since.") == "No search has worked since.":
    fail(f"The history doesn't show that searches work again: {summary!r}")

events = [row for row in history.rows if len(row) == 5 and row[0] != "Time"]
if len(events) != 2:
    fail(f"Expected two problems, newest first, got {events!r}")
retry, failure = events
if retry[1:3] != ["Second try answered", "Asking Paperless"]:
    fail(f"The newest problem isn't the request that only its second try answered: {retry!r}")
# The message of the HTTP client names the address without its query.
if DOCUMENTS_URL not in retry[3] or "?" in retry[3]:
    fail(f"The error of the retried request doesn't name {DOCUMENTS_URL} without its query: {retry!r}")
if failure[1:4] != ["Search failed", "Asking Paperless", "PaperlessRequestException: Paperless returned HTTP 500."]:
    fail(f"The older problem isn't the search that Paperless failed with HTTP 500: {failure!r}")
for event in events:
    if not re.fullmatch(r"\d+ ms", event[4]):
        fail(f"Unexpected duration: {event!r}")

for event in events:
    print(f"Search problems: {' | '.join(event[1:])}")
