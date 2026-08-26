#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

TOKEN = "e2e-only-token"


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

        query = parse_qs(parsed.query).get("query", [""])[0].strip().lower()
        results = []
        if query in ("", "mobiletest"):
            results = [
                {
                    "id": 123,
                    "title": "Mobile viewer test",
                    "created": "2026-08-26",
                    "__search_hit__": {
                        "highlights": "<span class=\"highlight\">Synthetic OCR mobiletest result</span>"
                    },
                },
                {
                    "id": 999,
                    "title": "Inaccessible document",
                    "created": "2026-08-25",
                },
            ]

        self._json(
            200,
            {
                "count": len(results),
                "next": None,
                "previous": None,
                "results": results,
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
