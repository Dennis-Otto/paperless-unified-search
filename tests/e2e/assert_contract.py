#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The contract between the app and a real Paperless-ngx, for paperless-contract.sh.

It runs in the container of the mock, in the network of the Compose project, where
Paperless answers as http://paperless:8000 and Nextcloud as http://nextcloud. It gets a
token of the API, gives Paperless the synthetic documents of contract/documents/ and
waits until it has read them, and then checks what the app relies on: first directly,
with the version of the API and the fields that the app asks for, then through Nextcloud
with the app set up for this Paperless. It prints a report in Markdown, which
paperless-contract.sh adds to the summary of the job, and fails with the first broken
promise.

    ACCEPT='application/json; version=9' FIELDS=id,title,… LINE=3 python assert_contract.py

ACCEPT and FIELDS come from lib/Service/PaperlessApiService.php, so that the contract
checks what the app sends; LINE is the line of Paperless-ngx, 2 or 3.
"""

import base64
import html
import json
import os
import re
import sys
import time
import uuid
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen

PAPERLESS = "http://paperless:8000"
NEXTCLOUD = "http://nextcloud"
# The accounts of the tests alone, from compose.paperless.yaml and compose.yaml.
PAPERLESS_USER = ("contract-admin", "contract-only-password")
NEXTCLOUD_USER = ("e2e-admin", "e2e-only-password")
SEARCH_PROVIDER = "paperless_unified_search_documents"

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
# A range of dates that is still being typed, which Paperless 3 can't parse yet.
UNFINISHED = "invoice created:[2026 to"
# The longest that Paperless may take to read the documents and to index them.
DEADLINE = 240

# The fields of a document that a result of the app shows.
SHOWN = {"id", "title", "created", "__search_hit__"}
DATE = re.compile(r"\d{4}-\d{2}-\d{2}")
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
    def __init__(self, accept, fields):
        self.accept = accept
        self.fields = fields
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

    def log_in(self):
        answer = request(
            f"{PAPERLESS}/api/token/",
            method="POST",
            headers={"Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded"},
            data=urlencode({"username": PAPERLESS_USER[0], "password": PAPERLESS_USER[1]}).encode(),
        )
        token = answer.json("/api/token/").get("token")
        expect(answer.status == 200 and isinstance(token, str) and token, f"/api/token/ gave no token (HTTP {answer.status}).")
        self.token = token

    def titles(self):
        answer = self.documents("The list of the documents", page_size=100, fields="id,title,created")
        return {result["title"]: result for result in answer.json("The list of the documents")["results"]}

    def upload(self, name, title, created):
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


def check_nextcloud(paperless, line, ids, version, api_version, shown, report):
    """The same Paperless through the app in Nextcloud."""
    answer = nextcloud(
        "/apps/paperless_unified_search/settings",
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        data=urlencode({"url": PAPERLESS, "token": paperless.token, "allowLocal": "1", "archiveOwner": NEXTCLOUD_USER[0]}).encode(),
    )
    saved = answer.json("The settings")
    expect(answer.status == 200, f"The test of the connection failed (HTTP {answer.status}): {saved!r}")
    expect(
        (saved.get("paperlessVersion"), saved.get("paperlessApiVersion")) == (version, api_version),
        f"The settings name Paperless {saved.get('paperlessVersion')!r} with API {saved.get('paperlessApiVersion')!r}, not {version!r} with {api_version!r}.",
    )
    report.passed("The test of the connection in the settings", f"Paperless {code(version)}, API {code(api_version)}")

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

    titles = [entry.get("title") for entry in search_nextcloud(UNFINISHED)]
    if line == "2":
        # Paperless 2 parses what it can of the query; the app only must not fail.
        report.passed("A query that is still being typed, in Nextcloud", f"no error, {len(titles)} result(s)")
    else:
        # Paperless 3 refuses it, and the app finds the documents of its plain words in the simple mode.
        expect("Contract invoice" in titles, f"Nextcloud's search for {UNFINISHED!r} shows {titles}, not the documents of its words in the simple mode of Paperless.")
        report.passed("A query that is still being typed, in Nextcloud", f"the simple mode finds {code(', '.join(titles))}")


def main():
    accept = os.environ.get("ACCEPT", "")
    fields = [field for field in os.environ.get("FIELDS", "").split(",") if field]
    line = os.environ.get("LINE", "")
    if not accept or not fields or line not in ("2", "3"):
        print("ACCEPT, FIELDS and LINE (2 or 3) are missing; paperless-contract.sh sets them.", file=sys.stderr)
        return 2

    report = Report()
    paperless = Paperless(accept, fields)
    status = 0
    heading = f"### The contract with Paperless-ngx {line}"
    try:
        paperless.log_in()
        ids = paperless.seed()
        report.passed("Documents", f"{len(ids)} synthetic text files, read and indexed")
        version, api_version, shown = check_paperless(paperless, line, report)
        heading = f"### The contract with Paperless-ngx {version} (newest API version {api_version})"
        check_nextcloud(paperless, line, ids, version, api_version, shown, report)
    except Broken as broken:
        status = 1
        report.failed(broken)
        print(f"The contract is broken: {broken}", file=sys.stderr)
    print(f"{heading}\n\nThe app asks for {code(accept)}.\n\n{report.table()}\n")
    return status


if __name__ == "__main__":
    sys.exit(main())
