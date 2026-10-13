#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

TOKEN = "e2e-only-token"

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
        "__search_hit__": {
            "highlights": "<span class=\"highlight\">Invoice</span> E-2026-0812. Total due 84.30 EUR by September 2."
        },
    },
    {
        "id": 389,
        "title": "Home insurance renewal",
        "created": "2026-07-21",
        "correspondent": 5,
        "document_type": 2,
        "content": "Your annual insurance invoice covers August 2026 through July 2027. " * 200,
        "__search_hit__": {
            "highlights": "Your annual insurance <span class=\"highlight\">invoice</span> covers August 2026 through July 2027."
        },
    },
    {
        "id": 371,
        "title": "Internet invoice - July 2026",
        "created": "2026-07-03",
        "correspondent": 4,
        "document_type": 1,
        "content": "Monthly fiber internet invoice. Customer reference EX-2048. " * 200,
        "__search_hit__": {
            "highlights": "Monthly fiber internet <span class=\"highlight\">invoice</span>. Customer reference EX-2048."
        },
    },
]


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == "/health":
            self._json(200, {"status": "ok"})
            return

        if parsed.path != "/api/documents/":
            self._json(404, {"detail": "Not found"})
            return

        if self.headers.get("Authorization") != f"Token {TOKEN}":
            self._json(401, {"detail": "Invalid token"})
            return

        parameters = parse_qs(parsed.query)
        fields = set(parameters.get("fields", [""])[0].split(",")) - {""}
        if not fields:
            self._json(400, {"detail": "Name the fields of the documents to send."})
            return

        query = parameters.get("query", [""])[0].strip().lower()
        results = []
        if query in ("", "mobiletest"):
            results = [
                {
                    "id": 123,
                    "title": "Mobile viewer test",
                    "created": "2026-08-26",
                    "content": "Synthetic OCR mobiletest result. " * 1000,
                    "notes": [],
                    "__search_hit__": {
                        "highlights": "<span class=\"highlight\">Synthetic OCR mobiletest result</span>"
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
        elif query == "invoice":
            results = DEMO_DOCUMENTS

        self._json(
            200,
            {
                "count": len(results),
                "next": None,
                "previous": None,
                "results": [
                    {key: value for key, value in document.items() if key in fields | ALWAYS_SENT}
                    for document in results
                ],
            },
        )

    def log_message(self, message, *args):
        print(f"paperless-mock: {message % args}", flush=True)

    def _json(self, status, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
