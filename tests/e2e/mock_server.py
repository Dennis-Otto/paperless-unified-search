#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The mock of Paperless-ngx for the end-to-end tests, which says what we think Paperless says.

assert_contract.py sends the requests of the app to it and to a real Paperless-ngx, and
fails when their answers differ in form (parity.py). So where a comment says "like
Paperless", the contract tests vouch for it; where it says "unlike Paperless", the mock is
stricter on purpose, and parity.py leaves that request out.
"""

import json
import operator
import re
import threading
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

TOKEN = "e2e-only-token"

# The token of a superuser, which the mock accepts as well, so that the end-to-end tests see
# the app warn about it: in occ, in the settings and in the overview.
SUPERUSER_TOKEN = "e2e-superuser-token"

# Like Paperless, the mock answers /api/ui_settings/ with the user of the token and the
# permissions of its account, as Paperless-ngx 3.3.1 does without the settings of its UI:
# TOKEN belongs to an account that may view documents, correspondents, document types, tags
# and its UI settings, the account that the documentation recommends, and SUPERUSER_TOKEN to
# a superuser, for whom Paperless names every permission of Django.
UI_SETTINGS_PATH = "/api/ui_settings/"
ACCOUNTS = {
    TOKEN: {
        "user": {"id": 3, "username": "nextcloud", "is_staff": False, "is_superuser": False, "groups": []},
        "settings": {},
        "permissions": ["view_document", "view_correspondent", "view_documenttype", "view_tag", "view_uisettings"],
    },
    SUPERUSER_TOKEN: {
        "user": {"id": 1, "username": "admin", "is_staff": True, "is_superuser": True, "groups": []},
        "settings": {},
        "permissions": [
            "add_document", "change_document", "delete_document", "view_document",
            "view_correspondent", "view_documenttype", "view_tag", "view_uisettings",
        ],
    },
}

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
OLDEST_API_VERSION = 9
NEWEST_API_VERSION = 10
VERSION_HEADERS = {"X-Version": "3.3.0", "X-Api-Version": str(NEWEST_API_VERSION)}

# Like Paperless-ngx 3, the mock refuses another version of its API with HTTP 406, before it
# looks at the token and therefore without naming its own.
ACCEPTED_VERSION = re.compile(r"\bversion=([^;,\s]*)")

# A range of dates whose end is still being typed, such as created:[2026 to, which
# Paperless 3 refuses in its advanced syntax with HTTP 400 and the errors of the query.
UNFINISHED_RANGE = re.compile(r"(\w+):\[([^\]\s]*)[^\]]*$")

# Like Paperless, the mock narrows the documents by their date with these filters, each a
# day as YYYY-MM-DD, which the app sends for the date filter of Nextcloud's search, and
# refuses another value with HTTP 400.
DATE_FILTERS = {"created__date__gte": operator.ge, "created__date__lte": operator.le}

# Like Paperless, the mock orders a list by the field that ordering names, the newest
# first with a minus, as the dashboard of the app asks for the documents that Paperless
# added last: ordering=-added. Unlike Paperless, which ignores a field that it can't order
# by, the mock refuses one with HTTP 400, so that the end-to-end tests notice a wrong one.
ORDERING_FIELDS = {"added", "created", "id", "title"}

# Like Paperless, the mock sends only the fields of the documents that a request names, and
# of a search the excerpt with them, named or not, but of a list without a search never. It
# sends a field that a document has no value for, as null or an empty list. Unlike
# Paperless, it refuses a request that names none, for which Paperless sends every field,
# the whole OCR text of every document among them, so that the end-to-end tests notice when
# the app stops naming them.
NO_VALUE = {"correspondent": None, "document_type": None, "tags": []}

# The documents of the demo that scripts/screenshots.sh searches for with the term
# "invoice", for the screenshots and the animation of the README. The OCR text of the
# insurance renewal says "invoice", its title doesn't.
DEMO_DOCUMENTS = [
    {
        "id": 412,
        "title": "Electricity invoice - August 2026",
        "created": "2026-08-12",
        "added": "2026-08-13T09:12:45.118731+02:00",
        "correspondent": 3,
        "document_type": 1,
        "tags": [1, 2, 3],
        "content": "Invoice E-2026-0812 for the electricity of August 2026. " * 200,
        "__search_hit__": {"highlights": "<b>Invoice</b> E-2026-0812. Total due 84.30 EUR by September 2."},
    },
    {
        "id": 389,
        "title": "Home insurance renewal",
        "created": "2026-07-21",
        "added": "2026-07-22T10:04:12.502311+02:00",
        "correspondent": 5,
        "document_type": 2,
        "tags": [4, 3, 5, 6],
        "content": "Your annual insurance invoice covers August 2026 through July 2027. " * 200,
        "__search_hit__": {"highlights": "Your annual insurance <b>invoice</b> covers August 2026 through July 2027."},
    },
    {
        "id": 371,
        "title": "Internet invoice - July 2026",
        "created": "2026-07-03",
        "added": "2026-07-04T08:41:57.990120+02:00",
        "correspondent": 4,
        "document_type": 1,
        "tags": [4],
        "content": "Monthly fiber internet invoice. Customer reference EX-2048. " * 200,
        "__search_hit__": {"highlights": "Monthly fiber internet <b>invoice</b>. Customer reference EX-2048."},
    },
]

# The other documents of the archive of the demo, which no search of the screenshots
# finds, but the dashboard lists among the newest.
DEMO_ARCHIVE_ONLY = [
    {
        "id": 402,
        "title": "Car service",
        "created": "2026-09-02",
        "added": "2026-09-03T08:27:31.204417+02:00",
        "correspondent": 6,
        "document_type": 4,
        "tags": [1, 7],
        "content": "Annual inspection, oil and filter, wiper blades. Total paid 218.40 EUR. " * 200,
    },
    {
        "id": 356,
        "title": "Property tax notice 2026",
        "created": "2026-05-14",
        "added": "2026-05-15T17:03:09.660152+02:00",
        "correspondent": 8,
        "document_type": 5,
        "tags": [2, 3, 5],
        "content": "Property tax and waste collection for 2026. Amount due 698.40 EUR. " * 200,
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
        "added": "2026-09-02T10:00:00.000000+02:00",
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
        "added": "2026-08-27T14:00:00.000000+02:00",
        "correspondent": 7,
        "document_type": 3,
        "tags": [1, 4],
        "content": "Synthetic OCR mobiletest result. " * 1000,
        "notes": [],
        "__search_hit__": {
            "highlights": "Synthetic OCR <b>mobiletest</b> result for O&#x27;Brien &amp; &quot;Sons&quot; &lt;GmbH&gt;",
            "note_highlights": None,
        },
    },
    {
        "id": 999,
        "title": "Inaccessible document",
        "created": "2026-08-25",
        "added": "2026-08-28T09:30:00.000000+02:00",
        "correspondent": 9,
        "content": "Synthetic OCR text of a document no file stands for. " * 1000,
        "notes": [],
        "__search_hit__": {"highlights": "Synthetic OCR <b>mobiletest</b> text of a document no file stands for"},
    },
]

# Like Paperless, the mock finds the documents similar to one for the parameter
# more_like_id, instead of a term: here the others of its family, without the document
# itself, and as Paperless-ngx 3 sends them without an excerpt. Like Paperless, it refuses
# an ID with HTTP 403 and only these words, not in JSON: one that it doesn't have, and one
# of a document that the account of the token may not view, here HIDDEN_DOCUMENT_ID. And
# like Paperless-ngx 3, it refuses more_like_id beside a term.
SIMILAR_FAMILIES = [MOBILE_DOCUMENTS, DEMO_DOCUMENTS + DEMO_ARCHIVE_ONLY, PAGE_TEST_DOCUMENTS]
HIDDEN_DOCUMENT_ID = "777"
UNKNOWN_DOCUMENT = b"Invalid more_like_id"
HIDDEN_DOCUMENT = b"Insufficient permissions."
ONE_SEARCH_ONLY = "Specify only one of text, title_search, query, or more_like_id."

# Every document of the mock that a list without a search holds, as Paperless lists all
# documents that the token may view: those of the term "mobiletest" and the archive of
# the demo. The dashboard asks for the newest of them, and occ for the IDs of them all.
ARCHIVE = MOBILE_DOCUMENTS + DEMO_DOCUMENTS + DEMO_ARCHIVE_ONLY

# The names of the correspondents, the types and the tags of the documents above, which
# the app asks for by their IDs, as Paperless lists them: /api/correspondents/?id__in=3,5.
# Tag 1 is the tag of the inbox, which Paperless gives every new document, and which no
# result shows.
LISTS = {
    "/api/correspondents/": {
        3: "Northstar Energy",
        4: "Fiberlink",
        5: "Harbor Mutual",
        6: "Example Motors",
        7: "Example Mobile",
        8: "City of Sampletown",
        9: "Hidden correspondent",
    },
    "/api/document_types/": {1: "Bill", 2: "Insurance", 3: "Letter", 4: "Receipt", 5: "Tax"},
    "/api/tags/": {1: "Inbox", 2: "unpaid", 3: "tax 2026", 4: "paid", 5: "house", 6: "renewal", 7: "warranty"},
}
INBOX_TAG = 1
TAGS_PATH = "/api/tags/"

API_PATH = "/api/documents/"

# The page that Django answers with when Paperless fails.
SERVER_ERROR_PAGE = b"<!doctype html><html lang=\"en\"><head><title>Server Error (500)</title></head><body><h1>Server Error (500)</h1><p></p></body></html>"

# The journal of the requests that the mock answers, one line each with the status of
# its answer, such as GET /api/documents/?query=mobiletest&page=1 HTTP/1.1 200, in the
# tmpfs of compose.yaml. run.sh reads it to check what reached Paperless, such as the
# date filter of a search. The mock writes the line before its answer leaves, so the
# journal holds the request of every answer that Nextcloud got, which the log of Docker
# gets only a moment later (#94).
REQUESTS = "/tmp/requests.log"
_requests_lock = threading.Lock()

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
    """The documents that a search for this term ranks, as the demo has them, and those of
    a list without a search for no term."""
    if term == "":
        return ARCHIVE
    if term in ("mobiletest", DROP_TERM):
        return MOBILE_DOCUMENTS
    if term == "invoice":
        return DEMO_DOCUMENTS
    if term == "pagetest":
        return PAGE_TEST_DOCUMENTS
    return []


def similar_to(identifier):
    """The documents similar to the one with this ID, or None for an ID that the mock
    doesn't have."""
    for family in SIMILAR_FAMILIES:
        if any(str(document["id"]) == identifier for document in family):
            return [
                {**document, "__search_hit__": {"score": 0.0, "highlights": ""}}
                for document in family
                if str(document["id"]) != identifier
            ]
    return None


def unknown_version(accept):
    """Whether the header Accept asks for a version of the API that the mock doesn't know."""
    asked = ACCEPTED_VERSION.search(accept)
    return asked is not None and not (asked[1].isdigit() and OLDEST_API_VERSION <= int(asked[1]) <= NEWEST_API_VERSION)


def sent(document, fields, rank):
    """A document as an answer sends it: the fields that the request names and, as the
    place in the ranking of a search if it is one, the excerpt, with all that Paperless
    sends beside it."""
    answer = {name: document.get(name, NO_VALUE.get(name)) for name in fields if name in {*document, *NO_VALUE}}
    answer.pop("__search_hit__", None)
    if rank is not None:
        answer["__search_hit__"] = {"score": 1.0, "note_highlights": None, **document.get("__search_hit__", {"highlights": ""}), "rank": rank}
    return answer


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        # Only an answer to a valid token names the version, as with Paperless.
        self.versioned = False
        parsed = urlparse(self.path)
        if parsed.path == "/health":
            self._json(200, {"status": "ok"})
            return

        if parsed.path not in (API_PATH, UI_SETTINGS_PATH) and parsed.path not in LISTS:
            self._json(404, {"detail": "Not found"})
            return

        if unknown_version(self.headers.get("Accept", "")):
            self._json(406, {"detail": 'Invalid version in "Accept" header.'})
            return

        authorization = self.headers.get("Authorization", "")
        token = authorization.removeprefix("Token ")
        if not authorization.startswith("Token ") or token not in ACCOUNTS:
            self._json(401, {"detail": "Invalid token"})
            return

        self.versioned = True
        if parsed.path == UI_SETTINGS_PATH:
            self._json(200, ACCOUNTS[token])
            return

        parameters = parse_qs(parsed.query)
        if parsed.path in LISTS:
            self._list(LISTS[parsed.path], parameters, tags=parsed.path == TAGS_PATH)
            return

        fields = set(parameters.get("fields", [""])[0].split(",")) - {""}
        if not fields:
            self._json(400, {"detail": "Name the fields of the documents to send."})
            return

        similar = parameters.get("more_like_id", [None])[0]
        if similar is not None and ("query" in parameters or "text" in parameters):
            self._json(400, {"detail": ONE_SEARCH_ONLY})
            return
        if similar == HIDDEN_DOCUMENT_ID:
            self._send(403, "text/html; charset=utf-8", HIDDEN_DOCUMENT)
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

        searching = similar is not None or "query" in parameters or "text" in parameters
        results = documents_of(query)
        if "text" in parameters:
            # The simple mode takes any input and searches its words; the mock ranks the
            # documents of the first word that names some.
            words = parameters["text"][0].lower().split()
            results = next((documents_of(word) for word in words if documents_of(word)), [])
        if similar is not None:
            results = similar_to(similar)
            if results is None:
                self._send(403, "text/html; charset=utf-8", UNKNOWN_DOCUMENT)
                return

        for name, keeps in DATE_FILTERS.items():
            if name not in parameters:
                continue
            try:
                day = datetime.strptime(parameters[name][0], "%Y-%m-%d").date()
            except ValueError:
                self._json(400, {name: ["Enter a valid date."]})
                return
            results = [
                document
                for document in results
                if keeps(datetime.strptime(document["created"], "%Y-%m-%d").date(), day)
            ]

        ordering = parameters.get("ordering", [""])[0]
        if ordering:
            field = ordering.removeprefix("-")
            if field not in ORDERING_FIELDS:
                self._json(400, {"ordering": [f"Unknown field to order by: {ordering}."]})
                return
            results = sorted(results, key=lambda document: document.get(field, ""), reverse=ordering.startswith("-"))

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
                    sent(document, fields, rank if searching else None)
                    for rank, document in enumerate(results[start:end], start=start + 1)
                ],
                # Like Paperless-ngx, a word that a search corrected, which the app doesn't read.
                **({"corrected_query": None} if searching else {}),
            },
        )

    def _list(self, names, parameters, *, tags=False):
        """A page of a list of Paperless, such as its correspondents, with every field of an
        object as Paperless sends them, filtered by the IDs of id__in; a tag also with its
        colors, whether it is the tag of the inbox, and its place in the tree of tags, and
        the list of tags with how many it holds there."""
        wanted = {int(value) for value in parameters.get("id__in", [""])[0].split(",") if value.isdigit()}
        results = [
            {
                "id": object_id,
                "slug": name.lower().replace(" ", "-"),
                "name": name,
                **({"color": "#a6cee3", "text_color": "#000000"} if tags else {}),
                "match": "",
                "matching_algorithm": 1,
                "is_insensitive": True,
                **({"is_inbox_tag": object_id == INBOX_TAG} if tags else {}),
                "document_count": 1,
                "owner": 2,
                "user_can_change": True,
                **({"parent": None, "children": []} if tags else {}),
            }
            for object_id, name in sorted(names.items())
            if object_id in wanted
        ]
        self._json(
            200,
            {
                "count": len(results),
                "next": None,
                "previous": None,
                "all": [result["id"] for result in results],
                "results": results,
                **({"display_count": len(results)} if tags else {}),
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

    def log_request(self, code="-", size="-"):
        """Writes the request into the journal, before send_response sends its status
        line, and logs it then as every server of http.server does."""
        with _requests_lock, open(REQUESTS, "a", encoding="utf-8") as journal:
            journal.write(f"{self.requestline} {code}\n")
        super().log_request(code, size)

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
    # An empty journal from the start, which run.sh can read before the first request.
    open(REQUESTS, "w", encoding="utf-8").close()
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
