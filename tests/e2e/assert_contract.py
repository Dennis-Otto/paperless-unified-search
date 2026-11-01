#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The contract between the app and a real Paperless-ngx, for paperless-contract.sh.

It runs in the container of the mock, in the network of the Compose project, where
Paperless answers as http://paperless:8000 and Nextcloud as http://nextcloud. It gets a
token of the API, gives Paperless the synthetic documents of contract/documents/ and
waits until it has read them, and then checks what the app relies on: first directly,
with the version of the API and the fields that the app asks for, and what
/api/ui_settings/ says of the account of a token, of a superuser and of an account that
may only view, then through Nextcloud with the app set up for this Paperless, first with
the token of the account that may only view and then with that of the superuser. Last, it
gives the invoice a correspondent, a type and two tags, one of them the tag of the inbox,
checks their names as the app asks for them with id__in, and what each permission of the account of the token gives the search, as the
table of docs/configure.md says, directly and in the results of Nextcloud, where the names
show, and every row of the table of the search syntax of README.md. Last, it sends the
requests of the app to this Paperless and to the mock, which runs beside it, and compares
the form of their answers (parity.py), since the required check "nextcloud" believes the
mock. It prints a report in Markdown, which paperless-contract.sh adds to the summary of the
job, and fails with the first broken promise or with every difference of the mock.

    ACCEPT='application/json; version=9' FIELDS=id,title,… RECENT_FIELDS=id,title,added,… UNKNOWN_DOCUMENT=… HIDDEN_DOCUMENT=… LINE=3 python assert_contract.py

ACCEPT, FIELDS, RECENT_FIELDS, UNKNOWN_DOCUMENT and HIDDEN_DOCUMENT come from
lib/Service/PaperlessApiService.php, so that the contract checks what the app sends and
reads; LINE is the line of Paperless-ngx, 2 or 3.
"""

import base64
import html
import json
import os
import re
import secrets
import sys
import time
import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen

import parity

PAPERLESS = "http://paperless:8000"
NEXTCLOUD = "http://nextcloud"
# The accounts of the tests alone, from compose.paperless.yaml and compose.yaml.
PAPERLESS_USER = ("contract-admin", "contract-only-password")
NEXTCLOUD_USER = ("e2e-admin", "e2e-only-password")
SEARCH_PROVIDER = "paperless_unified_search_documents"
DASHBOARD_WIDGET = "paperless_unified_search-recent"

DOCUMENTS = Path(__file__).resolve().parent / "contract" / "documents"
# The title and the date of each synthetic document, by its file.
SEED = {
    "contract-invoice.txt": ("Contract invoice", "2026-03-14"),
    "contract-insurance.txt": ("Contract insurance", "2025-08-01"),
    "contract-letter.txt": ("Contract letter", "2024-11-20"),
}
# The term that finds the invoice and the insurance, and one only in the invoice, next to
# characters that Paperless escapes in its excerpt.
TERM = "invoice"
RARE_TERM = "electricity"
# The term that finds all documents of SEED, as many as the mock finds for its own term.
EVERY_TERM = "contract"
# A range of dates that is still being typed, which Paperless 3 can't parse yet.
UNFINISHED = "invoice created:[2026 to"
# The longest that Paperless may take to read the documents and to index them.
DEADLINE = 240

# The permissions of the account that the documentation recommends for the token: to view
# documents, the names of correspondents, types and tags that the results show, and its UI
# settings, without which Paperless doesn't say what the account may do. And those that
# change documents, which the app warns about (lib/Model/TokenWarning.php).
VIEWER_PERMISSIONS = ["view_document", "view_correspondent", "view_documenttype", "view_tag", "view_uisettings"]
CHANGING_DOCUMENTS = {"add_document", "change_document", "delete_document"}

# The correspondent, the type and the tag of the invoice, which the superuser adds and
# therefore owns, as an administrator of Paperless does in its web interface, and a tag of
# the inbox, which Paperless gives every new document and which no result shows; a note of
# the invoice with a word of its own, which only a search of the notes finds.
CORRESPONDENT = "Contract Energy"
DOCUMENT_TYPE = "Contract bill"
TAG_NAME = "Contract unpaid"
INBOX_TAG = "Contract inbox"
# A second correspondent, type and tag, which no document names: a list filtered by
# id__in leaves them out unless the app asks for them. And an ID that no object of
# Paperless has, the largest that its tables hold, which a list leaves out as well.
OTHER_NAMES = {"correspondents": "Contract Water", "document_types": "Contract reminder", "tags": "Contract paid"}
UNKNOWN_ID = 2**31 - 1
NOTE_TERM = "remittance"
NOTE = f"Paid by {NOTE_TERM} on the due date."
# A document that a workflow lets the account view as Paperless adds it, as step 2 of
# docs/configure.md sets it up; it isn't one of SEED, and the check deletes it again.
RECEIPT = ("contract-receipt.txt", "Contract receipt", "2026-04-02")
# The types of a trigger and an action of a workflow in the API of Paperless-ngx:
# Document Added, and Assignment.
DOCUMENT_ADDED = 2
ASSIGNMENT = 1

# What only the search syntax of README.md looks for: a single word as a tag, which
# Paperless-ngx 2 keeps whole, and removes again, the archive serial number, the storage
# path and the custom field of the letter, by the value of the field.
SYNTAX_TAG = "overdue"
SYNTAX_ASN = 4711
SYNTAX_PATH = "binders"
SYNTAX_FIELD = "Contract policy"
SYNTAX_VALUE = "gold8812"

# The fields of a document that a result of the app shows.
SHOWN = {"id", "title", "created", "__search_hit__"}
DATE = re.compile(r"\d{4}-\d{2}-\d{2}")
# The fields of a document that the dashboard shows, and the moment that Paperless added
# it as RecentDocumentsWidget reads it, such as 2026-08-27T14:00:00.123456+02:00.
RECENT = {"id", "title", "added"}
MOMENT = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?")
# What the app makes of an excerpt of Paperless: its text without the markup, entities
# decoded, on one line (PaperlessSearchProvider).
TAG = re.compile(r"<[^>]*>")
SPACE = re.compile(r"\s+")
# Markup or an entity of HTML that must not reach a result in Nextcloud.
MARKUP = re.compile(r"<\s*/?\s*(b|span|em|strong|mark)\b|&(amp|lt|gt|quot|#\d+);", re.IGNORECASE)


class Broken(Exception):
    """A promise of the contract that this Paperless or Nextcloud breaks."""


class Answer:
    def __init__(self, status, headers, body):
        self.status = status
        self.headers = headers
        self.body = body

    def json(self, what):
        try:
            return json.loads(self.body)
        except ValueError as error:
            raise Broken(f"{what} answered no JSON (HTTP {self.status}): {self.body[:300]!r}") from error


def request(url, *, method="GET", headers=None, data=None):
    try:
        with urlopen(Request(url, data=data, method=method, headers=headers or {}), timeout=60) as response:
            return Answer(response.status, response.headers, response.read())
    except HTTPError as error:
        return Answer(error.code, error.headers, error.read())


def expect(condition, message):
    if not condition:
        raise Broken(message)


class Report:
    """The checks, as the rows of a table in Markdown: those that passed, and the first that failed."""

    def __init__(self):
        self.rows = []
        # What the table doesn't hold, in Markdown: the differences of the mock.
        self.details = ""

    def passed(self, check, detail):
        self.rows.append((check, f"passed: {detail}"))

    def failed(self, broken):
        self.rows.append(("**The contract is broken**", str(broken).replace("|", "\\|").replace("\n", " ")))

    def table(self):
        lines = ["| Check | Result |", "| --- | --- |"]
        lines += [f"| {check} | {result} |" for check, result in self.rows]
        return "\n".join(lines)


def code(text):
    """Text as code in a cell of a table in Markdown."""
    return "`" + text.replace("`", "'").replace("|", "\\|").replace("\n", " ") + "`"


def excerpt(highlights):
    """The excerpt that the app shows of the highlights of Paperless."""
    return SPACE.sub(" ", html.unescape(TAG.sub("", highlights))).strip()


class Paperless:
    def __init__(self, accept, fields, recent_fields):
        self.accept = accept
        self.fields = fields
        self.recent_fields = recent_fields
        self.token = ""

    def get(self, path, *, accept=None, **query):
        headers = {"Accept": accept or self.accept}
        if self.token:
            headers["Authorization"] = f"Token {self.token}"
        return request(f"{PAPERLESS}{path}?{urlencode(query)}", headers=headers)

    def documents(self, what, expected_status=200, **query):
        answer = self.get("/api/documents/", **query)
        expect(answer.status == expected_status, f"{what}: Paperless answered HTTP {answer.status} instead of {expected_status}: {answer.body[:300]!r}")
        return answer

    def search(self, what, **query):
        """The page of a search with the fields of the app."""
        return self.documents(what, page=1, page_size=25, fields=",".join(self.fields), **query)

    def newest(self, what):
        """The newest documents, as the dashboard of the app asks for them."""
        return self.documents(what, ordering="-added", page=1, page_size=25, fields=",".join(self.recent_fields))

    def log_in(self):
        self.token = self.token_of(*PAPERLESS_USER)

    @staticmethod
    def token_of(username, password):
        """The API token of an account, as its profile in Paperless creates it."""
        answer = request(
            f"{PAPERLESS}/api/token/",
            method="POST",
            headers={"Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded"},
            data=urlencode({"username": username, "password": password}).encode(),
        )
        token = answer.json("/api/token/").get("token")
        expect(answer.status == 200 and isinstance(token, str) and token, f"/api/token/ gave no token for {username} (HTTP {answer.status}).")
        return token

    def account(self, permissions):
        """The ID and the token of a new account that is neither superuser nor staff, with these
        permissions, as an administrator of Paperless adds it under Users & Groups; a name
        of its own in each run, so that a kept Paperless takes it as well."""
        username = f"contract-viewer-{secrets.token_hex(4)}"
        password = secrets.token_urlsafe(18)
        answer = request(
            f"{PAPERLESS}/api/users/",
            method="POST",
            headers={"Accept": self.accept, "Authorization": f"Token {self.token}", "Content-Type": "application/json"},
            data=json.dumps({"username": username, "password": password, "is_active": True, "is_staff": False, "is_superuser": False, "user_permissions": permissions}).encode(),
        )
        expect(answer.status == 201, f"Paperless didn't add the account {username} (HTTP {answer.status}): {answer.body[:300]!r}")
        return answer.json("/api/users/")["id"], self.token_of(username, password)

    def as_account(self, token, path, **query):
        """A GET with the token of another account than the superuser."""
        return request(f"{PAPERLESS}{path}?{urlencode(query)}", headers={"Accept": self.accept, "Authorization": f"Token {token}"})

    def send(self, path, body, *, method="POST"):
        """A request with JSON as the superuser, as the web interface of Paperless sends it."""
        return request(
            f"{PAPERLESS}{path}",
            method=method,
            headers={"Accept": self.accept, "Authorization": f"Token {self.token}", "Content-Type": "application/json"},
            data=None if body is None else json.dumps(body).encode(),
        )

    def named(self, kind, name, **fields):
        """The ID of the correspondent, the type, the tag or another object of this name,
        which the superuser adds once with these other fields, such as is_inbox_tag, and
        therefore owns, as Paperless makes whoever adds one its owner."""
        found = self.get(f"/api/{kind}/", name__iexact=name).json(f"/api/{kind}/").get("results", [])
        if found:
            return found[0]["id"]
        answer = self.send(f"/api/{kind}/", {"name": name, **fields})
        expect(answer.status == 201, f"Paperless didn't add {name} to /api/{kind}/ (HTTP {answer.status}): {answer.body[:300]!r}")
        return answer.json(f"/api/{kind}/")["id"]

    def bulk_edit(self, documents, method, parameters):
        answer = self.send("/api/documents/bulk_edit/", {"documents": documents, "method": method, "parameters": parameters})
        expect(answer.status == 200, f"The bulk editor of Paperless refused {method} (HTTP {answer.status}): {answer.body[:300]!r}")

    def let_view(self, users, *, documents=None, objects=None):
        """Lets these users view the documents, or the objects of a kind such as
        correspondents, besides those who may already, as Permissions of the bulk editor
        of Paperless does."""
        permissions = {"view": {"users": users, "groups": []}, "change": {"users": [], "groups": []}}
        if documents is not None:
            self.bulk_edit(documents, "set_permissions", {"set_permissions": permissions, "merge": True})
            return
        kind, ids = objects
        answer = self.send("/api/bulk_edit_objects/", {"objects": ids, "object_type": kind, "operation": "set_permissions", "permissions": permissions, "merge": True})
        expect(answer.status == 200, f"Paperless didn't let {users} view the {kind} {ids} (HTTP {answer.status}): {answer.body[:300]!r}")

    def ui_settings(self, token):
        """The answer of /api/ui_settings/ to a token, which the app asks what the account may do."""
        return request(f"{PAPERLESS}/api/ui_settings/", headers={"Accept": self.accept, "Authorization": f"Token {token}"})

    def titles(self):
        answer = self.documents("The list of the documents", page_size=100, fields="id,title,created")
        return {result["title"]: result for result in answer.json("The list of the documents")["results"]}

    def upload(self, name, title, created):
        """Gives Paperless a document of contract/documents/, as its upload in the web interface."""
        boundary = uuid.uuid4().hex
        parts = []
        for field, value in (("title", title), ("created", created)):
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"\r\n\r\n{value}\r\n'.encode())
        parts.append(
            f'--{boundary}\r\nContent-Disposition: form-data; name="document"; filename="{name}"\r\n'
            "Content-Type: text/plain\r\n\r\n".encode()
            + (DOCUMENTS / name).read_bytes()
            + b"\r\n"
        )
        parts.append(f"--{boundary}--\r\n".encode())
        answer = request(
            f"{PAPERLESS}/api/documents/post_document/",
            method="POST",
            headers={
                "Accept": self.accept,
                "Authorization": f"Token {self.token}",
                "Content-Type": f"multipart/form-data; boundary={boundary}",
            },
            data=b"".join(parts),
        )
        expect(answer.status == 200, f"Paperless refused the document {name} (HTTP {answer.status}): {answer.body[:300]!r}")

    def seed(self):
        """The ID of each synthetic document by its title, once Paperless has read and indexed them all."""
        present = self.titles()
        for name, (title, created) in SEED.items():
            if title not in present:
                self.upload(name, title, created)
        deadline = time.monotonic() + DEADLINE
        while True:
            present = self.titles()
            if all(title in present for title, _ in SEED.values()):
                found = self.search("The search that waits for the index", query=TERM).json("The search")
                if found.get("count") == 2:
                    return {title: present[title]["id"] for title, _ in SEED.values()}
            expect(time.monotonic() < deadline, f"Paperless didn't read and index the documents within {DEADLINE} s; it has {sorted(present)}.")
            time.sleep(3)


def check_paperless(paperless, line, report):
    """The answers of Paperless that the app relies on, as the app asks for them."""
    missing = sorted(SHOWN - set(paperless.fields))
    expect(not missing, f"The app doesn't ask Paperless for the fields {missing}, which a result shows.")
    first = paperless.search("A search", query=TERM)
    version = first.headers.get("X-Version", "")
    api_version = first.headers.get("X-Api-Version", "")
    expect(version.split(".")[0] == line, f"Paperless names the version {version!r}, which isn't one of Paperless-ngx {line}.")
    expect(api_version.isdigit() and int(api_version) >= 9, f"Paperless names the API version {api_version!r}.")
    report.passed("The version headers that the settings show", f"X-Version {code(version)}, X-Api-Version {code(api_version)}")

    data = first.json("A search")
    expect(isinstance(data.get("count"), int) and data["count"] == 2, f"The search for {TERM!r} counts {data.get('count')!r} documents instead of 2.")
    expect(data.get("next") is None or isinstance(data.get("next"), str), f"next is neither a link nor null: {data.get('next')!r}")
    results = data.get("results")
    expect(isinstance(results, list) and len(results) == 2, f"The search for {TERM!r} has the results {results!r}.")
    for result in results:
        expect(set(result) == set(paperless.fields), f"Paperless ignored the fields {paperless.fields}: a result has {sorted(result)}.")
        expect(isinstance(result["id"], int), f"id isn't a number: {result['id']!r}")
        expect(isinstance(result["title"], str) and result["title"], f"title isn't a text: {result['title']!r}")
        expect(isinstance(result["created"], str) and DATE.fullmatch(result["created"]), f"created isn't a date YYYY-MM-DD: {result['created']!r}")
        for field in ("correspondent", "document_type"):
            expect(result[field] is None or isinstance(result[field], int), f"{field} is neither an ID nor null: {result[field]!r}")
        expect(isinstance(result["tags"], list) and all(isinstance(tag, int) for tag in result["tags"]), f"tags is no list of IDs: {result['tags']!r}")
        hit = result["__search_hit__"]
        expect(isinstance(hit, dict) and isinstance(hit.get("highlights"), str), f"__search_hit__ has no highlights as text: {hit!r}")
    created = {result["title"]: result["created"] for result in results}
    expected = {title: date for title, date in SEED.values() if title in created}
    expect(created == expected, f"The dates of the documents are {created}, not {expected}.")
    report.passed(f"The fields of a result ({code(','.join(paperless.fields))})", "only these, of their types, with the excerpt and the date as YYYY-MM-DD")

    page = paperless.documents("A search of one per page", page=1, page_size=1, fields="id", query=TERM).json("A search")
    expect(isinstance(page.get("next"), str) and "page=2" in page["next"], f"The first of two pages has no link to the next one: {page.get('next')!r}")
    last = paperless.documents("The last page", page=2, page_size=1, fields="id", query=TERM).json("The last page")
    expect(last.get("next") is None and len(last.get("results", [])) == 1, f"The last page isn't the last: {last!r}")
    past = paperless.documents("A page past the last", 404, page=3, page_size=1, fields="id", query=TERM)
    report.passed("Pages of a search", f"next links the next page and is null on the last, a page past it answers HTTP {past.status}")

    rare = paperless.search("A search for a rare word", query=RARE_TERM).json("A search")
    expect(rare.get("count") == 1, f"The search for {RARE_TERM!r} counts {rare.get('count')!r} documents instead of 1.")
    highlights = rare["results"][0]["__search_hit__"]["highlights"]
    shown = excerpt(highlights)
    expect(TAG.search(highlights), f"Paperless marks no term in the excerpt: {highlights!r}")
    expect(RARE_TERM in shown.lower(), f"The excerpt that the app shows lacks the term: {shown!r} of {highlights!r}")
    expect(not MARKUP.search(shown), f"The excerpt that the app shows keeps markup or an entity: {shown!r} of {highlights!r}")
    report.passed("The excerpt", f"Paperless sends {code(highlights[:120])}, the app shows {code(shown[:120])}")

    dates = paperless.documents(
        "A search within dates", fields="id,created", created__date__gte="2026-01-01", created__date__lte="2026-12-31"
    ).json("A search within dates")
    expect([result["created"] for result in dates.get("results", [])] == ["2026-03-14"], f"created__date__gte and __lte find {dates.get('results')!r}.")
    report.passed("A filter by date", "created__date__gte and created__date__lte")

    # The app sends the dates together with the full-text query; the term alone finds the
    # invoice and the insurance, the year 2026 keeps only the invoice.
    within = {"created__date__gte": "2026-01-01", "created__date__lte": "2026-12-31"}
    filtered = paperless.search("A full-text search within dates", query=TERM, **within).json("A full-text search within dates")
    titles = [result.get("title") for result in filtered.get("results", [])]
    expect(titles == ["Contract invoice"], f"The full-text search for {TERM!r} within 2026 finds {titles}.")
    report.passed("A filter by date with the full-text search", f"{code('query=')} with created__date__gte and created__date__lte")

    missing = sorted(RECENT - set(paperless.recent_fields))
    expect(not missing, f"The app doesn't ask Paperless for the fields {missing}, which the dashboard shows.")
    newest = paperless.newest("The newest documents").json("The newest documents").get("results")
    expect(isinstance(newest, list) and len(newest) == len(SEED), f"The newest documents are {newest!r}, not the {len(SEED)} of the contract.")
    for result in newest:
        expect(set(result) == set(paperless.recent_fields), f"Paperless ignored the fields {paperless.recent_fields}: a document has {sorted(result)}.")
        expect(isinstance(result["id"], int) and isinstance(result["title"], str), f"A newest document has no ID or title: {result!r}")
        expect(isinstance(result["added"], str) and MOMENT.fullmatch(result["added"]), f"added isn't a moment with its time: {result['added']!r}")
    moments = [datetime.fromisoformat(result["added"]) for result in newest]
    expect(moments == sorted(moments, reverse=True), f"ordering=-added doesn't list the newest first: {[result['added'] for result in newest]}")
    report.passed(f"The newest documents of the dashboard ({code('ordering=-added')}, {code(','.join(paperless.recent_fields))})", f"newest first, added as {code(newest[0]['added'])}")

    answer = paperless.get("/api/documents/", page=1, page_size=25, fields=",".join(paperless.fields), query=UNFINISHED)
    if line == "2":
        # Paperless 2 parses what it can, and never gets the simple mode, which it ignores.
        expect(answer.status == 200, f"Paperless 2 answers the unfinished query {UNFINISHED!r} with HTTP {answer.status}.")
        report.passed("A query that is still being typed", f"Paperless 2 answers HTTP 200 for {code(UNFINISHED)}")
    else:
        refused = answer.json("An unfinished query")
        expect(answer.status == 400 and "query" in refused, f"Paperless 3 answers the unfinished query {UNFINISHED!r} with HTTP {answer.status}, not 400 with the errors of query: {refused!r}")
        simple = paperless.search("The simple mode", text=TERM).json("The simple mode")
        titles = sorted(result.get("title") for result in simple.get("results", []))
        expect(titles == ["Contract insurance", "Contract invoice"], f"The simple mode finds {titles} for {TERM!r}.")
        simple = paperless.search("The simple mode within dates", text=TERM, **within).json("The simple mode within dates")
        titles = [result.get("title") for result in simple.get("results", [])]
        expect(titles == ["Contract invoice"], f"The simple mode for {TERM!r} within 2026 finds {titles}.")
        report.passed("A query that is still being typed", f"HTTP 400 with the errors of {code('query')}, and the simple mode {code('text=')} finds the documents")
    return version, api_version, shown


def check_privileges(paperless, report):
    """What /api/ui_settings/ says of the account of a token, from which the app warns
    about a token that may change documents or may not view what the results show."""
    answer = paperless.ui_settings(paperless.token)
    expect(answer.status == 200, f"/api/ui_settings/ answered the superuser with HTTP {answer.status}: {answer.body[:300]!r}")
    data = answer.json("/api/ui_settings/")
    user, permissions = data.get("user"), data.get("permissions")
    expect(isinstance(user, dict) and user.get("is_superuser") is True and isinstance(user.get("is_staff"), bool), f"/api/ui_settings/ names no superuser: {user!r}")
    expect(isinstance(permissions, list) and all(isinstance(permission, str) for permission in permissions), f"/api/ui_settings/ names no list of permissions: {permissions!r}")
    expect(CHANGING_DOCUMENTS <= set(permissions), f"The permissions of the superuser lack {sorted(CHANGING_DOCUMENTS - set(permissions))}: {sorted(permissions)}")
    report.passed("What the account of a superuser may do (/api/ui_settings/)", f"{code('is_superuser')} true, {len(permissions)} permissions without the label of their app, such as {code('change_document')}")

    viewer_id, viewer = paperless.account(VIEWER_PERMISSIONS)
    data = paperless.ui_settings(viewer).json("/api/ui_settings/ of the viewer")
    user, permissions = data.get("user", {}), set(data.get("permissions", []))
    expect(user.get("is_superuser") is False and user.get("is_staff") is False, f"The account that may only view is a superuser or staff: {user!r}")
    expect(set(VIEWER_PERMISSIONS) <= permissions and not permissions & CHANGING_DOCUMENTS, f"The account that may only view has the permissions {sorted(permissions)}.")
    report.passed("What an account that may only view may do", f"{code(', '.join(sorted(permissions)))}")

    # Without the permission to view its UI settings, Paperless doesn't say: the app then
    # warns about nothing, and occ says that it can't tell.
    stranger = paperless.account(["view_document"])[1]
    refused = paperless.ui_settings(stranger)
    expect(refused.status == 403, f"/api/ui_settings/ answered an account without view_uisettings with HTTP {refused.status}, not 403: {refused.body[:300]!r}")
    report.passed("An account that may not view its UI settings", f"HTTP {refused.status}")
    return viewer_id, viewer, stranger


def check_similar(paperless, ids, viewer, unknown, hidden, report):
    """The documents similar to one, as the app asks for them for a term such as
    like:[P412]: the ID in more_like_id in place of the term, with the fields, the pages and
    the filters by date of a search, and without the document itself. The invoice shares
    the word invoice with the insurance. A document that Paperless doesn't have, and one
    that the account of the token may not view, it refuses in the words that the app takes
    for no problem of the search; check_permissions sees that it refuses an account that
    may view no documents at all in others."""
    invoice, insurance = ids["Contract invoice"], ids["Contract insurance"]
    what = "Documents similar to the invoice"
    data = paperless.search(what, more_like_id=invoice).json(what)
    results = data.get("results")
    expect(isinstance(results, list), f"{what} are no list: {data!r}")
    found = [result.get("id") for result in results]
    expect(insurance in found, f"{what} are {found}, without the insurance {insurance}, which shares a word with it.")
    expect(invoice not in found, f"{what} are {found}, with the invoice {invoice} itself.")
    expect(data.get("next") is None, f"{len(found)} documents don't fit on the first page: next is {data.get('next')!r}")
    for result in results:
        expect(set(result) == set(paperless.fields), f"Paperless ignored the fields {paperless.fields}: a similar document has {sorted(result)}.")
        hit = result["__search_hit__"]
        expect(isinstance(hit, dict) and isinstance(hit.get("highlights"), str), f"__search_hit__ of a similar document has no highlights as text: {hit!r}")
    report.passed(f"Documents similar to one ({code('more_like_id')})", f"the invoice {invoice} finds {found}: only the fields of a result, and never the document itself")

    within = {"created__date__gte": "2025-01-01", "created__date__lte": "2025-12-31"}
    found = [result["id"] for result in paperless.search(f"{what} within dates", more_like_id=invoice, **within).json(what)["results"]]
    expect(found == [insurance], f"{what} within 2025 are {found}, not the insurance {insurance} alone.")
    within = {"created__date__gte": "2026-01-01", "created__date__lte": "2026-12-31"}
    found = paperless.search(f"{what} within dates", more_like_id=invoice, **within).json(what)["results"]
    expect(found == [], f"{what} within 2026, the year of the invoice alone, are {found}.")
    past = paperless.documents(f"A page past the last of {what}", 404, page=2, page_size=25, fields="id", more_like_id=invoice)
    report.passed("Documents similar to one, as any search", f"{code('created__date__gte')} and {code('created__date__lte')} narrow them, a page past the last answers HTTP {past.status}")

    query = {"page": 1, "page_size": 25, "fields": ",".join(paperless.fields)}
    answer = paperless.get("/api/documents/", more_like_id=UNKNOWN_ID, **query)
    expect((answer.status, answer.body.decode()) == (403, unknown), f"Documents similar to one that Paperless doesn't have get HTTP {answer.status} and {answer.body[:300]!r}, not HTTP 403 and {unknown!r}, which the app takes for no problem of the search.")
    answer = paperless.as_account(viewer, "/api/documents/", more_like_id=invoice, **query)
    expect((answer.status, answer.body.decode()) == (403, hidden), f"Documents similar to one that the account may not view get HTTP {answer.status} and {answer.body[:300]!r}, not HTTP 403 and {hidden!r}, which the app takes for no problem of the search.")
    report.passed("A document that Paperless refuses", f"HTTP 403 and {code(unknown)} for an unknown ID, {code(hidden)} for one that the account may not view")


def nextcloud(path, *, method="GET", data=None, headers=None):
    credentials = base64.b64encode(":".join(NEXTCLOUD_USER).encode()).decode()
    return request(
        f"{NEXTCLOUD}{path}",
        method=method,
        data=data,
        headers={"Authorization": f"Basic {credentials}", "OCS-APIRequest": "true", "Accept": "application/json", **(headers or {})},
    )


def search_nextcloud(term):
    answer = nextcloud(f"/ocs/v2.php/search/providers/{SEARCH_PROVIDER}/search?{urlencode({'term': term, 'limit': 5, 'cursor': 0})}")
    expect(answer.status == 200, f"Nextcloud's search for {term!r} answered HTTP {answer.status}: {answer.body[:300]!r}")
    entries = answer.json("Nextcloud's search").get("ocs", {}).get("data", {}).get("entries")
    expect(isinstance(entries, list), f"Nextcloud's search for {term!r} has no entries: {answer.body[:300]!r}")
    return entries


def save_settings(token):
    """The settings of the app saved with this token, as the settings page saves them."""
    answer = nextcloud(
        "/apps/paperless_unified_search/settings",
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        data=urlencode({"url": PAPERLESS, "token": token, "allowLocal": "1", "archiveOwner": NEXTCLOUD_USER[0]}).encode(),
    )
    saved = answer.json("The settings")
    expect(answer.status == 200, f"The test of the connection failed (HTTP {answer.status}): {saved!r}")
    return saved


def check_nextcloud(paperless, line, ids, version, api_version, shown, viewer, report):
    """The same Paperless through the app in Nextcloud."""
    saved = save_settings(viewer)
    expect(saved.get("tokenWarnings") == [], f"The settings warn about the token of an account that may only view: {saved.get('tokenWarnings')!r}")
    report.passed("The token of an account that may only view, in the settings", "saved without a warning")

    saved = save_settings(paperless.token)
    expect(
        (saved.get("paperlessVersion"), saved.get("paperlessApiVersion")) == (version, api_version),
        f"The settings name Paperless {saved.get('paperlessVersion')!r} with API {saved.get('paperlessApiVersion')!r}, not {version!r} with {api_version!r}.",
    )
    report.passed("The test of the connection in the settings", f"Paperless {code(version)}, API {code(api_version)}")
    expect(saved.get("tokenWarnings") == ["superuser"], f"The settings don't warn about the token of the superuser: {saved.get('tokenWarnings')!r}")
    report.passed("The token of a superuser, in the settings", f"saved with the warning {code('superuser')}")

    folder = f"/remote.php/dav/files/{NEXTCLOUD_USER[0]}/Paperless"
    answer = nextcloud(folder, method="MKCOL")
    expect(answer.status in (201, 405), f"Nextcloud couldn't create the folder of the archive (HTTP {answer.status}).")
    for name, (title, _) in SEED.items():
        file = quote(f"{title} [P{ids[title]}].txt")
        answer = nextcloud(f"{folder}/{file}", method="PUT", data=(DOCUMENTS / name).read_bytes())
        expect(answer.status in (201, 204), f"Nextcloud didn't store {file} (HTTP {answer.status}).")

    titles = sorted(entry.get("title") for entry in search_nextcloud(TERM))
    expect(titles == ["Contract insurance", "Contract invoice"], f"Nextcloud's search for {TERM!r} shows {titles}.")
    entries = search_nextcloud(RARE_TERM)
    expect([entry.get("title") for entry in entries] == ["Contract invoice"], f"Nextcloud's search for {RARE_TERM!r} shows {entries!r}.")
    subline = entries[0].get("subline", "")
    beginning = shown[:60]
    expect(beginning in subline, f"The result shows {subline!r}, without the excerpt {beginning!r}.")
    expect(not MARKUP.search(subline), f"The result shows markup or an entity of HTML: {subline!r}")
    report.passed("Results in Nextcloud's search", f"{code(subline[:120])}")

    # A term like:[P…] finds the documents similar to that one, in whichever way it is
    # written, and one for an ID that Paperless doesn't have finds nothing.
    invoice = ids["Contract invoice"]
    for term in (f"like:[P{invoice}]", f"like:P{invoice}", f"like:{invoice}", f"LIKE:[p{invoice}]"):
        titles = [entry.get("title") for entry in search_nextcloud(term)]
        expect("Contract insurance" in titles and "Contract invoice" not in titles, f"Nextcloud's search for {term!r} shows {titles}, not the insurance without the invoice.")
    titles = [entry.get("title") for entry in search_nextcloud(f"like:{UNKNOWN_ID}")]
    expect(titles == [], f"Nextcloud's search for documents similar to one that Paperless doesn't have shows {titles}.")
    report.passed("Documents similar to one, in Nextcloud's search", f"{code(f'like:[P{invoice}]')} and its other forms show the insurance without the invoice, an unknown ID nothing")

    # The dashboard lists the same documents, newest first, each with the file that the
    # archive account has of it.
    newest = [result["title"] for result in paperless.newest("The newest documents").json("The newest documents")["results"]]
    answer = nextcloud(f"/ocs/v2.php/apps/dashboard/api/v2/widget-items?{urlencode({'widgets[]': DASHBOARD_WIDGET})}")
    expect(answer.status == 200, f"Nextcloud's dashboard answered HTTP {answer.status}: {answer.body[:300]!r}")
    items = answer.json("Nextcloud's dashboard").get("ocs", {}).get("data", {}).get(DASHBOARD_WIDGET, {}).get("items")
    titles = [item.get("title") for item in items] if isinstance(items, list) else items
    expect(titles == newest, f"The widget of the dashboard shows {titles!r}, not the newest documents {newest!r}.")
    expect(all(item.get("subtitle") for item in items), f"An item of the dashboard has no line with its date: {items!r}")
    report.passed("The widget of Nextcloud's dashboard", f"{code(' / '.join(titles))}, the first {code(items[0]['subtitle'])}")

    titles = [entry.get("title") for entry in search_nextcloud(UNFINISHED)]
    if line == "2":
        # Paperless 2 parses what it can of the query; the app only must not fail.
        report.passed("A query that is still being typed, in Nextcloud", f"no error, {len(titles)} result(s)")
    else:
        # Paperless 3 refuses it, and the app finds the documents of its plain words in the simple mode.
        expect("Contract invoice" in titles, f"Nextcloud's search for {UNFINISHED!r} shows {titles}, not the documents of its words in the simple mode of Paperless.")
        report.passed("A query that is still being typed, in Nextcloud", f"the simple mode finds {code(', '.join(titles))}")


def hits(paperless, token, query):
    """The results of a search with the fields of the app, for the account of a token."""
    answer = paperless.as_account(token, "/api/documents/", page=1, page_size=25, fields=",".join(paperless.fields), query=query)
    expect(answer.status == 200, f"A search for {query!r} with the token of an account answered HTTP {answer.status}: {answer.body[:300]!r}")
    return answer.json("A search").get("results", [])


def until(what, found):
    """The first answer of found() that isn't empty, within the deadline: Paperless takes
    a change of the permissions of a document into the index of its search a moment later."""
    deadline = time.monotonic() + DEADLINE
    while True:
        answer = found()
        if answer:
            return answer
        expect(time.monotonic() < deadline, f"{what} within {DEADLINE} s.")
        time.sleep(2)


def names(paperless, token, path, ids):
    """The names of these correspondents, types or tags that the account of a token may
    view, by their ID, or the status of a refusal. It asks as PaperlessApiService::findNames
    does, with id__in and as many on a page as it asks for, and checks the answer as the
    app reads it: one page of results, each with its id as a number and its name as a
    text, a tag with is_inbox_tag as a boolean, and none that it didn't ask for. As in the
    app, the tag of the inbox has no name."""
    query = {"id__in": ",".join(map(str, ids)), "page_size": len(ids)}
    asked = f"/api/{path}/?id__in={query['id__in']}&page_size={len(ids)}"
    answer = paperless.as_account(token, f"/api/{path}/", **query)
    if answer.status != 200:
        return answer.status
    data = answer.json(asked)
    results = data.get("results") if isinstance(data, dict) else None
    expect(isinstance(results, list), f"{asked} answered without a list results, from which the app reads the names: {answer.body[:300]!r}")
    expect(data.get("next") is None, f"{asked} doesn't fit on the one page that the app reads: next is {data.get('next')!r}")
    found = {}
    for result in results:
        expect(
            isinstance(result, dict) and isinstance(result.get("id"), int) and isinstance(result.get("name"), str),
            f"{asked} sent an object without its id as a number and its name as a text, which the app reads, so a result shows no name: {result!r}",
        )
        if path == "tags":
            expect(isinstance(result.get("is_inbox_tag"), bool), f"{asked} sent a tag without is_inbox_tag as a boolean, so the app would show the tag of the inbox: {result!r}")
            if result["is_inbox_tag"]:
                continue
        found[result["id"]] = result["name"]
    expect(set(found) <= set(ids), f"{asked} sent the IDs {sorted(found)}, also those that it wasn't asked for.")
    return found


def check_names(paperless, named, inbox, report):
    """The names of the correspondents, the types and the tags of documents that a result
    shows next to its date: Paperless sends only their IDs with the documents of a search,
    and the app asks for their names with one request per kind (lib/Service/PaperlessNames.php).
    named holds the ID and the name of the correspondent, the type and the tag of the
    invoice, by the path of their list; a second object of each kind, which nothing asks
    for, an ID that Paperless doesn't know, and the tag of the inbox, whose ID is inbox,
    stay out of the answer."""
    for path, (known, name) in named.items():
        other = paperless.named(path, OTHER_NAMES[path])
        found = names(paperless, paperless.token, path, [known, UNKNOWN_ID])
        expect(found == {known: name}, f"/api/{path}/ names {found!r} for the IDs {known} and {UNKNOWN_ID}, not {name!r} alone.")
        found = names(paperless, paperless.token, path, [other, known])
        expected = {other: OTHER_NAMES[path], known: name}
        expect(found == expected, f"/api/{path}/ names {found!r} for the IDs {other} and {known}, not {expected!r}.")
    tag, name = named["tags"]
    found = names(paperless, paperless.token, "tags", [inbox, tag])
    expect(found == {tag: name}, f"/api/tags/ names {found!r} for the tag of the inbox {inbox} and the tag {tag}, not {name!r} alone.")
    report.passed(
        f"The names of correspondents, document types and tags ({code('id__in')} with {code('page_size')}, as the app asks)",
        f"one page with {code('id')} and {code('name')} of each object asked for, without the others and an ID that Paperless doesn't know; {code('is_inbox_tag')} true for the tag of the inbox alone",
    )


def check_permissions(paperless, ids, viewer_id, viewer, report):
    """What each permission of the account of the token gives the search, as the table
    "What each permission gives the search" of docs/configure.md says: View for documents
    finds the documents that the account may view, View for correspondents and document
    types shows the names of those that it may view, the settings warn about those that
    the newest documents name and that it can't see, a note matches without View for
    notes, and a workflow with the trigger Document Added and an action Assignment lets
    the account view each new document. The table and this check change together."""
    invoice = ids["Contract invoice"]
    correspondent = paperless.named("correspondents", CORRESPONDENT)
    document_type = paperless.named("document_types", DOCUMENT_TYPE)
    tag = paperless.named("tags", TAG_NAME)
    inbox = paperless.named("tags", INBOX_TAG, is_inbox_tag=True)
    paperless.bulk_edit([invoice], "set_correspondent", {"correspondent": correspondent})
    paperless.bulk_edit([invoice], "set_document_type", {"document_type": document_type})
    paperless.bulk_edit([invoice], "modify_tags", {"add_tags": [inbox, tag], "remove_tags": []})
    named = {"correspondents": (correspondent, CORRESPONDENT), "document_types": (document_type, DOCUMENT_TYPE), "tags": (tag, TAG_NAME)}
    check_names(paperless, named, inbox, report)
    notes = paperless.get(f"/api/documents/{invoice}/notes/").json("The notes of the invoice")
    if not any(note.get("note") == NOTE for note in notes):
        answer = paperless.send(f"/api/documents/{invoice}/notes/", {"note": NOTE})
        expect(answer.status == 200, f"Paperless didn't add the note to the invoice (HTTP {answer.status}): {answer.body[:300]!r}")

    documents_only_id, documents_only = paperless.account(["view_document"])
    expect(hits(paperless, viewer, RARE_TERM) == [], f"An account finds the invoice of the superuser for {RARE_TERM!r} before it may view it.")
    paperless.let_view([viewer_id, documents_only_id], documents=sorted(ids.values()))
    for token in (viewer, documents_only):
        found = until(f"The account found no invoice for {RARE_TERM!r} once it may view it", lambda token=token: hits(paperless, token, RARE_TERM))
        expect([result["id"] for result in found] == [invoice], f"The account finds {found!r} for {RARE_TERM!r}, not the invoice {invoice}.")
        expect((found[0]["correspondent"], found[0]["document_type"]) == (correspondent, document_type), f"The invoice names the correspondent and the type {found[0]!r} for the account.")
        expect(sorted(found[0]["tags"]) == sorted([inbox, tag]), f"The invoice names the tags {found[0]['tags']!r} for the account, not {sorted([inbox, tag])}.")
    without_documents = paperless.account(["view_uisettings"])[1]
    refused = paperless.as_account(without_documents, "/api/documents/", page_size=1, fields="id")
    expect(refused.status == 403, f"/api/documents/ answered an account without the permission to view documents with HTTP {refused.status}, not 403, which fails the test of the connection.")
    # Not in the words of a document that it refuses, which the app takes for no problem of
    # the search: this account has to show up as one.
    refused = paperless.as_account(without_documents, "/api/documents/", page_size=1, fields="id", more_like_id=invoice)
    what = "The question of an account without the permission to view documents for similar ones"
    expect(refused.status == 403 and "detail" in refused.json(what), f"{what} got HTTP {refused.status} and {refused.body[:300]!r}, not HTTP 403 and the field detail.")
    report.passed("View for documents", "HTTP 403 without it, also for similar ones in the field detail; with it, a document of another owner only once the account may view it, as Permissions of the bulk editor lets it")

    for path in ("correspondents", "document_types", "tags"):
        refused = names(paperless, documents_only, path, [correspondent, document_type, tag])
        expect(refused == 403, f"/api/{path}/ answered an account without the permission to view them with {refused!r}, not HTTP 403.")
    owned = (
        names(paperless, viewer, "correspondents", [correspondent]),
        names(paperless, viewer, "document_types", [document_type]),
        names(paperless, viewer, "tags", [tag]),
    )
    expect(owned == ({}, {}, {}), f"An account names the correspondent, the type and the tag of the superuser before it may view them: {owned!r}")
    saved = save_settings(viewer)
    expect(
        saved.get("tokenWarnings") == ["hides_names_of_others"],
        f"The settings don't warn about the correspondent, the type and the tag that the account can't see: {saved.get('tokenWarnings')!r}",
    )
    report.passed("A correspondent, a type and a tag of another owner, in the settings", f"saved with the warning {code('hides_names_of_others')}, until the account may view them")
    paperless.let_view([viewer_id], objects=("correspondents", [correspondent]))
    paperless.let_view([viewer_id], objects=("document_types", [document_type]))
    paperless.let_view([viewer_id], objects=("tags", [tag, inbox]))
    shown = (
        names(paperless, viewer, "correspondents", [correspondent]),
        names(paperless, viewer, "document_types", [document_type]),
        names(paperless, viewer, "tags", [inbox, tag]),
    )
    expected = ({correspondent: CORRESPONDENT}, {document_type: DOCUMENT_TYPE}, {tag: TAG_NAME})
    expect(shown == expected, f"The account names {shown!r} once it may view the correspondent, the type and the tags, not {expected!r}.")
    report.passed(
        "View for correspondents, document types and tags",
        "HTTP 403 without it; with it, the names of those that the account may view, so of another owner only once Permissions of the bulk editor lets it",
    )

    notes = until(f"The account found no note for notes:{NOTE_TERM}", lambda: hits(paperless, viewer, f"notes:{NOTE_TERM}"))
    highlights = notes[0]["__search_hit__"].get("note_highlights") or ""
    expect(NOTE_TERM in excerpt(highlights), f"The search of the notes sends {highlights!r} to an account without the permission to view notes.")
    report.passed("A note without View for notes", f"{code('notes:' + NOTE_TERM)} sends {code(highlights[:80])}")

    saved = save_settings(viewer)
    expect(saved.get("tokenWarnings") == [], f"The settings warn about the token of the account: {saved.get('tokenWarnings')!r}")
    sublines = [entry.get("subline", "") for entry in search_nextcloud(RARE_TERM)]
    expect(
        len(sublines) == 1 and all(name in sublines[0] for name in (CORRESPONDENT, DOCUMENT_TYPE, TAG_NAME)),
        f"Nextcloud's result for {RARE_TERM!r} with the token of the account shows {sublines!r}, without {CORRESPONDENT}, {DOCUMENT_TYPE} and {TAG_NAME}.",
    )
    expect(INBOX_TAG not in sublines[0], f"Nextcloud's result for {RARE_TERM!r} shows the tag of the inbox: {sublines[0]!r}")
    report.passed("The names in Nextcloud's results, with the token of the account", f"{code(sublines[0][:80])}")

    check_workflow(paperless, viewer_id, viewer, report)


def check_workflow(paperless, viewer_id, viewer, report):
    """A workflow with the trigger Document Added and an action Assignment that lets the
    account view each new document, as step 2 of docs/configure.md sets it up. It
    removes the workflow and the new document again, so that a kept Paperless passes the
    contract once more."""
    name, title, created = RECEIPT
    answer = paperless.send(
        "/api/workflows/",
        {
            "name": f"Contract: the search may view new documents ({viewer_id})",
            "order": 0,
            "enabled": True,
            "triggers": [{"type": DOCUMENT_ADDED}],
            "actions": [{"type": ASSIGNMENT, "assign_view_users": [viewer_id], "assign_view_groups": []}],
        },
    )
    expect(answer.status == 201, f"Paperless didn't add the workflow (HTTP {answer.status}): {answer.body[:300]!r}")
    workflow = answer.json("/api/workflows/")["id"]
    receipt = None
    try:
        paperless.upload(name, title, created)
        receipt = until(f"Paperless didn't add {name}", lambda: paperless.titles().get(title))["id"]
        seen = paperless.as_account(viewer, "/api/documents/", id=receipt, fields="id").json("A new document")
        expect(seen.get("count") == 1, f"The workflow didn't let the account view the new document {receipt}: {seen!r}")
        report.passed("A workflow Document Added with an action Assignment", "the account may view each new document as Paperless adds it")
    finally:
        if receipt is not None:
            # Into the trash, and out of it for good.
            paperless.send(f"/api/documents/{receipt}/", None, method="DELETE")
            paperless.send("/api/trash/", {"documents": [receipt], "action": "empty"})
        paperless.send(f"/api/workflows/{workflow}/", None, method="DELETE")


def check_syntax(paperless, ids, line, report):
    """Every row of the table of "Search like in Paperless" in README.md, for the search
    syntax that the app hands to Paperless, with words of the synthetic documents in place
    of its examples: what the row promises finds the documents that it says, and no others.
    The table and this check change together. It removes the tag again, which the settings
    would warn about as one that another account can't see, so that a kept Paperless passes
    the contract once more."""
    invoice, letter = ids["Contract invoice"], ids["Contract letter"]
    invoice_title, insurance_title, letter_title = "Contract invoice", "Contract insurance", "Contract letter"
    tag = paperless.named("tags", SYNTAX_TAG)
    paperless.bulk_edit([invoice], "modify_tags", {"add_tags": [tag], "remove_tags": []})
    paperless.bulk_edit([letter], "set_storage_path", {"storage_path": paperless.named("storage_paths", SYNTAX_PATH, path="{{ title }}")})
    field = paperless.named("custom_fields", SYNTAX_FIELD, data_type="string")
    answer = paperless.send(f"/api/documents/{letter}/", {"archive_serial_number": SYNTAX_ASN, "custom_fields": [{"field": field, "value": SYNTAX_VALUE}]}, method="PATCH")
    expect(answer.status == 200, f"Paperless didn't take the archive serial number and the custom field of the letter (HTTP {answer.status}): {answer.body[:300]!r}")

    def titles(query):
        what = f"The search for {query}"
        return sorted(result["title"] for result in paperless.search(what, query=query).json(what)["results"])

    def seeks(query, expected):
        found = until(f"Paperless found nothing for {query!r}", lambda: titles(query))
        expect(found == sorted(expected), f"The search for {query!r} finds {found}, not {sorted(expected)}, as README.md says.")

    try:
        rows = [
            ("invoice 2025", [insurance_title]),
            ('"annual insurance"', [insurance_title]),
            ("insur*", [insurance_title]),
            ("invoice NOT electricity", [insurance_title]),
            ("correspondent:energy", [invoice_title]),
            (f"type:bill tag:{SYNTAX_TAG}", [invoice_title]),
            ("created:[2024 to 2025]", [insurance_title, letter_title]),
            ("title:letter", [letter_title]),
            ("content:garden", [letter_title]),
            (f"asn:{SYNTAX_ASN}", [letter_title]),
            (f"notes:{NOTE_TERM}", [invoice_title]),
            (f"path:{SYNTAX_PATH}", [letter_title]),
            (f"custom_fields:{SYNTAX_VALUE}", [letter_title]),
            ("original_filename:contract-letter.txt", [letter_title]),
        ]
        for query, expected in rows:
            seeks(query, expected)
    finally:
        paperless.bulk_edit([invoice], "modify_tags", {"add_tags": [], "remove_tags": [tag]})
    report.passed("The rows of the table of the search syntax", ", ".join(code(query) for query, _ in rows))

    # Near midnight in UTC, what the contract added today is from yesterday by now, and a
    # kept Paperless keeps the documents that it added days ago, which neither finds.
    today = datetime.now(timezone.utc).date()
    what = "The newest documents, with the moment that Paperless added them"
    recent = {
        result["title"]
        for result in paperless.newest(what).json(what)["results"]
        if today - datetime.fromisoformat(result["added"]).astimezone(timezone.utc).date() <= timedelta(days=1)
    }
    expected = {title for title, _ in SEED.values()} & recent
    added = set(titles("added:today")) | set(titles("added:yesterday"))
    expect(expected <= added, f"added:today and added:yesterday find {sorted(added)}, not {sorted(expected)}, which the contract added then.")
    report.passed("The moment that Paperless added a document", f"{code('added:today')} and {code('added:yesterday')} find the documents that the contract added then")

    # The field of the owner, which the table leaves out and its text names: only Paperless-ngx 2 has it.
    if line == "2":
        seeks("owner:admin", [title for title, _ in SEED.values()])
    else:
        found = titles("owner:admin")
        expect(found == [], f"Paperless-ngx 3 finds {found} for owner:admin; README.md says that it has no such field.")
    report.passed("The owner of a document", f"{code('owner:')} finds the documents of an account in Paperless-ngx 2 alone" if line == "2" else f"{code('owner:')} is no field of Paperless-ngx 3")


def check_parity(paperless, ids, line, viewer, stranger, report):
    """The mock against this Paperless: the requests of the app to both, whose answers have
    to have the same form (parity.py). The required check "nextcloud" believes the mock, so
    every difference is one that it can't see. By now the account may view the documents of
    the contract, and their names; stranger may view none of them."""
    until(f"The account didn't find all documents for {EVERY_TERM!r}", lambda: len(hits(paperless, viewer, EVERY_TERM)) == len(SEED))
    invoice = ids["Contract invoice"]
    archive = parity.Archive(
        origin=PAPERLESS,
        token=viewer,
        superuser=paperless.token,
        stranger=stranger,
        several=EVERY_TERM,
        marked=RARE_TERM,
        document=invoice,
        hidden=invoice,
        correspondent=paperless.named("correspondents", CORRESPONDENT),
        document_type=paperless.named("document_types", DOCUMENT_TYPE),
        tag=paperless.named("tags", TAG_NAME),
    )
    comparison = parity.compare(line, archive, parity.MOCK, paperless.accept, ",".join(paperless.fields), ",".join(paperless.recent_fields))
    report.details = comparison.table()
    expect(not comparison.differences, f"{len(comparison.points())} point(s) of {comparison.asked} requests of the app differ between this Paperless and the mock, which the required check nextcloud believes. The table below names them.")
    known = "; ".join(comparison.known.values())
    report.passed("The mock answers as this Paperless does", f"{comparison.asked} requests of the app: the same status, media type, versions, keys and types, links and markup" + (f", but where {known}" if known else ""))


def main():
    accept = os.environ.get("ACCEPT", "")
    fields = [field for field in os.environ.get("FIELDS", "").split(",") if field]
    recent_fields = [field for field in os.environ.get("RECENT_FIELDS", "").split(",") if field]
    unknown = os.environ.get("UNKNOWN_DOCUMENT", "")
    hidden = os.environ.get("HIDDEN_DOCUMENT", "")
    line = os.environ.get("LINE", "")
    if not accept or not fields or not recent_fields or not unknown or not hidden or line not in ("2", "3"):
        print("ACCEPT, FIELDS, RECENT_FIELDS, UNKNOWN_DOCUMENT, HIDDEN_DOCUMENT and LINE (2 or 3) are missing; paperless-contract.sh sets them.", file=sys.stderr)
        return 2

    report = Report()
    paperless = Paperless(accept, fields, recent_fields)
    status = 0
    heading = f"### The contract with Paperless-ngx {line}"
    try:
        paperless.log_in()
        ids = paperless.seed()
        report.passed("Documents", f"{len(ids)} synthetic text files, read and indexed")
        version, api_version, shown = check_paperless(paperless, line, report)
        heading = f"### The contract with Paperless-ngx {version} (newest API version {api_version})"
        viewer_id, viewer, stranger = check_privileges(paperless, report)
        check_similar(paperless, ids, viewer, unknown, hidden, report)
        check_nextcloud(paperless, line, ids, version, api_version, shown, viewer, report)
        check_permissions(paperless, ids, viewer_id, viewer, report)
        check_syntax(paperless, ids, line, report)
        check_parity(paperless, ids, line, viewer, stranger, report)
    except Broken as broken:
        status = 1
        report.failed(broken)
        print(f"The contract is broken: {broken}", file=sys.stderr)
    print(f"{heading}\n\nThe app asks for {code(accept)}.\n\n{report.table()}\n")
    if report.details:
        print(f"{report.details}\n")
    return status


if __name__ == "__main__":
    sys.exit(main())
