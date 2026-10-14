#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import re
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

TOKEN = "e2e-only-token"

# Terms that make the mock fail as a real Paperless or its network does, so that the
# end-to-end tests see the app record the problem in the history of its diagnostics:
# Paperless fails with HTTP 500 for the first, and the connection of the first request
# for the second breaks off without an answer, which the app sends a second time.
ERROR_TERM = "e2e-error"
DROP_TERM = "e2e-drop"

# Like Paperless, the mock answers in pages of 25 documents unless a request asks for
# another size, and refuses a page past the last one.
DEFAULT_PAGE_SIZE = 25

# Like Paperless, the mock names its version in the headers of every answer to a valid
# token, as Paperless-ngx 3.3.0 does, so that the app may send a query that the advanced
# syntax refuses to the simple mode of the search, the parameter text.
VERSION_HEADERS = {"X-Version": "3.3.0", "X-Api-Version": "10"}

# A range of dates whose end is still being typed, such as created:[2026 to, which
# Paperless 3 refuses in its advanced syntax with HTTP 400 and the errors of the query.
UNFINISHED_RANGE = re.compile(r"(\w+):\[([^\]\s]*)[^\]]*$")

# Like Paperless, the mock sends only the fields of the documents that a request names,
# and the excerpt of a search with them. Unlike Paperless, it refuses a request that names
# none, for which Paperless sends every field, the whole OCR text of every document among
# them, so that the end-to-end tests notice when the app stops naming them.
ALWAYS_SENT = {"__search_hit__"}

# The documents of the demo that scripts/screenshots.sh searches for with the term
# "invoice", for the screenshots and the animation of the README. The OCR text of the
# insurance renewal says "invoice", its title doesn't.
DEMO_DOCUMENTS = [
    {
        "id": 412,
        "title": "Electricity invoice - August 2026",
        "created": "2026-08-12",
        "correspondent": 3,
        "document_type": 1,
        "content": "Invoice E-2026-0812 for the electricity of August 2026. " * 200,
        "__search_hit__": {"highlights": "<b>Invoice</b> E-2026-0812. Total due 84.30 EUR by September 2."},
    },
    {
        "id": 389,
        "title": "Home insurance renewal",
        "created": "2026-07-21",
        "correspondent": 5,
        "document_type": 2,
        "content": "Your annual insurance invoice covers August 2026 through July 2027. " * 200,
        "__search_hit__": {"highlights": "Your annual insurance <b>invoice</b> covers August 2026 through July 2027."},
    },
    {
        "id": 371,
        "title": "Internet invoice - July 2026",
        "created": "2026-07-03",
        "correspondent": 4,
        "document_type": 1,
        "content": "Monthly fiber internet invoice. Customer reference EX-2048. " * 200,
        "__search_hit__": {"highlights": "Monthly fiber internet <b>invoice</b>. Customer reference EX-2048."},
    },
]


# The ranking of the term "pagetest": 40 documents, more than one page of the requests of
# the app, which ask for 25 at a time. run.sh shares the files of a few of them, on both
# pages, with e2e-other, so that a page of its results takes hits from two pages.
PAGE_TEST_DOCUMENTS = [
    {
        "id": document_id,
        "title": f"Page test {document_id}",
        "created": "2026-09-01",
        "__search_hit__": {"highlights": f"Synthetic <b>pagetest</b> document {document_id}"},
    }
    for document_id in range(601, 641)
]


# The documents of the term "mobiletest", which most checks of a result search for, and
# of the search for DROP_TERM once its connection broke off. Its excerpt is marked up and
# escaped as Paperless 3.3 sends it: the words of the term in <b>, and the characters of
# HTML as entities, which the app turns back into text.
MOBILE_DOCUMENTS = [
    {
        "id": 123,
        "title": "Mobile viewer test",
        "created": "2026-08-26",
        "content": "Synthetic OCR mobiletest result. " * 1000,
        "notes": [],
        "__search_hit__": {
            "highlights": "Synthetic OCR <b>mobiletest</b> result for O&#x27;Brien &amp; &quot;Sons&quot; &lt;GmbH&gt;"
        },
    },
    {
        "id": 999,
        "title": "Inaccessible document",
        "created": "2026-08-25",
        "content": "Synthetic OCR text of a document no file stands for. " * 1000,
        "notes": [],
    },
]

API_PATH = "/api/documents/"

# The page that Django answers with when Paperless fails.
SERVER_ERROR_PAGE = b"<!doctype html><html lang=\"en\"><head><title>Server Error (500)</title></head><body><h1>Server Error (500)</h1><p></p></body></html>"

_dropped_terms = set()
_dropped_terms_lock = threading.Lock()


def breaks_off(term):
    """Whether the connection of this search breaks off: only the first one for DROP_TERM."""
    with _dropped_terms_lock:
        if term != DROP_TERM or term in _dropped_terms:
            return False
        _dropped_terms.add(term)
        return True


def number(parameters, name, default):
    """The positive whole number of a parameter of the query, or None for another value."""
    value = parameters.get(name, [str(default)])[0]
    return int(value) if value.isdigit() and int(value) > 0 else None


def documents_of(term):
    """The documents that a search for this term ranks, as the demo has them."""
    if term in ("", "mobiletest", DROP_TERM):
        return MOBILE_DOCUMENTS
    if term == "invoice":
        return DEMO_DOCUMENTS
    if term == "pagetest":
        return PAGE_TEST_DOCUMENTS
    return []


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        # Only an answer to a valid token names the version, as with Paperless.
        self.versioned = False
        parsed = urlparse(self.path)
        if parsed.path == "/health":
            self._json(200, {"status": "ok"})
            return

        if parsed.path != API_PATH:
            self._json(404, {"detail": "Not found"})
            return

        if self.headers.get("Authorization") != f"Token {TOKEN}":
            self._json(401, {"detail": "Invalid token"})
            return

        self.versioned = True
        parameters = parse_qs(parsed.query)
        fields = set(parameters.get("fields", [""])[0].split(",")) - {""}
        if not fields:
            self._json(400, {"detail": "Name the fields of the documents to send."})
            return

        query = parameters.get("query", [""])[0].strip().lower()
        unfinished = UNFINISHED_RANGE.search(query)
        if unfinished:
            field, value = unfinished.groups()
            self._json(400, {"query": [f"Invalid date value '[{value}' for field '{field}'."]})
            return
        if query == ERROR_TERM:
            self._send(500, "text/html", SERVER_ERROR_PAGE)
            return
        if breaks_off(query):
            # No status line, no header: the app gets an empty reply and asks again.
            print(f"paperless-mock: broke off the connection of the first search for {query}", flush=True)
            self.close_connection = True
            return

        results = documents_of(query)
        if "text" in parameters:
            # The simple mode takes any input and searches its words; the mock ranks the
            # documents of the first word that names some.
            words = parameters["text"][0].lower().split()
            results = next((documents_of(word) for word in words if documents_of(word)), [])

        page = number(parameters, "page", 1)
        page_size = number(parameters, "page_size", DEFAULT_PAGE_SIZE)
        start = (page - 1) * page_size if page and page_size else None
        if start is None or (page > 1 and start >= len(results)):
            self._json(404, {"detail": "Invalid page."})
            return

        end = start + page_size
        self._json(
            200,
            {
                "count": len(results),
                "next": self._link(parsed.query, page + 1) if end < len(results) else None,
                "previous": self._link(parsed.query, page - 1) if page > 1 else None,
                # Like Paperless-ngx, the IDs of all documents of the list, on every page,
                # which occ paperless_unified_search:check compares with the files.
                "all": [document["id"] for document in results],
                "results": [
                    {key: value for key, value in document.items() if key in fields | ALWAYS_SENT}
                    for document in results[start:end]
                ],
            },
        )

    def _link(self, query, page):
        """The link of another page of this search, as Paperless writes it: the absolute URL
        of the request with the number of the page, and none for the first page, with the
        parameters of the query in the order of their names."""
        parameters = {name: values for name, values in parse_qs(query, keep_blank_values=True).items() if name != "page"}
        if page > 1:
            parameters["page"] = [str(page)]
        encoded = urlencode(sorted(parameters.items()), doseq=True)
        return f"http://{self.headers.get('Host')}{API_PATH}" + (f"?{encoded}" if encoded else "")

    def log_message(self, message, *args):
        print(f"paperless-mock: {message % args}", flush=True)

    def _json(self, status, payload):
        self._send(status, "application/json", json.dumps(payload).encode("utf-8"))

    def _send(self, status, content_type, body):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        if self.versioned:
            for name, value in VERSION_HEADERS.items():
                self.send_header(name, value)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
