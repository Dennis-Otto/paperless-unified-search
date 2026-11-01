# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The mock of Paperless against a real Paperless-ngx, for assert_contract.py.

The required check "nextcloud" talks only to mock_server.py, so it stays green when the
mock says what Paperless no longer says. This module sends the requests of the app to both
and compares what comes back. The two servers hold other documents, so an answer first
becomes what it says about its form: IDs, titles and dates become their types, and only
what the app could trip over is left to compare. That is the status, the media type, the
versions in the headers, the JSON keys and the types of their values, the links to other
pages, the text of an answer that isn't JSON, and the markup of an excerpt.

Paperless-ngx 2 differs from the mock, which speaks as Paperless-ngx 3, in the few points
of KNOWN; an entry that stops being true is a difference, too, so the list can't go stale.
tests/e2e/selftest.sh tests this module without Docker.
"""

import json
import re
from dataclasses import dataclass, field
from urllib.error import HTTPError
from urllib.parse import quote_plus, urlencode
from urllib.request import ProxyHandler, Request, build_opener

import mock_server

DOCUMENTS = "/api/documents/"
# The largest ID that the tables of Paperless hold, which no object has, in either server.
UNKNOWN_ID = 2**31 - 1
# A word that neither server finds.
NOTHING = "zzzznothingzzzz"
# A newer version of the API than any Paperless has, and an older one than Paperless-ngx 3 has.
NEWER_ACCEPT = "application/json; version=999"
OLDER_ACCEPT = "application/json; version=8"

DATE = re.compile(r"\d{4}-\d{2}-\d{2}")
MOMENT = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?")
VERSION = re.compile(r"(\d+)\.\d+\.\d+")
MARKUP = re.compile(r"<[^>]*>")
ENTITY = re.compile(r"&#?\w+;")

ABSENT = "absent"
NULL = "null"
# A list without elements, which says nothing about the form of its elements.
EMPTY = "no elements"

# The fields that either server may leave empty for a document of its own: that one sends
# null for them says nothing about the other, which may have a value.
NULLABLE = {"correspondent", "document_type", "note_highlights", "corrected_query"}
# The texts of an excerpt, whose markup and entities are compared besides their type.
EXCERPTS = {"highlights", "note_highlights"}
# The settings of the web interface of Paperless, which the app doesn't read.
UNCOMPARED = "settings."

# What Paperless-ngx 2 says other than the mock, which speaks as Paperless-ngx 3, by the
# line of Paperless and the point that differs. The app reads both.
KNOWN = {
    "2": {
        "header X-Version": "the mock names a version of Paperless-ngx 3",
        "header X-Api-Version": "the mock names the newest version of the API of Paperless-ngx 3",
        "results[].__search_hit__.highlights, its markup": 'Paperless-ngx 2 marks a term with `<span class="match term0">`, Paperless-ngx 3 with `<b>`',
        "results[].__search_hit__.highlights, its entities": "Paperless-ngx 2 leaves quotes as they are, Paperless-ngx 3 writes them as entities",
        "display_count": "only Paperless-ngx 3 counts the tags in the tree of tags",
    },
}


@dataclass(frozen=True)
class Archive:
    """A server, and the words and IDs that name the same things in its archive."""

    # Where the server answers.
    origin: str
    # The token of the account that the documentation recommends, which may view the
    # documents and their names, of a superuser, and of an account that may not view hidden.
    token: str
    superuser: str
    stranger: str
    # A term that finds three documents, and one whose excerpt holds an entity of each
    # character that Paperless escapes.
    several: str
    marked: str
    # A document with similar ones, and the one that stranger may not view.
    document: int
    hidden: int
    # A correspondent, a type and a tag that the account of token may view.
    correspondent: int
    document_type: int
    tag: int


# The mock as the container of the mock reaches it, with what mock_server.py holds.
MOCK = Archive(
    origin="http://127.0.0.1:8080",
    token=mock_server.TOKEN,
    superuser=mock_server.SUPERUSER_TOKEN,
    stranger=mock_server.TOKEN,
    several="invoice",
    marked="mobiletest",
    document=412,
    hidden=int(mock_server.HIDDEN_DOCUMENT_ID),
    correspondent=3,
    document_type=1,
    tag=2,
)


@dataclass(frozen=True)
class Ask:
    """A request of the app, the same to both servers but for what its braces name."""

    name: str
    path: str
    query: dict = field(default_factory=dict)
    # The token by the name of its field in Archive, or the text of a wrong one, or none.
    token: str = "token"
    # The header Accept, when it isn't the one of the app.
    accept: str = ""
    # The lines of Paperless that the app sends this request to.
    lines: tuple = ("2", "3")
    # Whether the excerpts hold the same entities: only where both servers find characters
    # that Paperless escapes, not in a request for another term.
    entities: bool = False


@dataclass(frozen=True)
class Difference:
    request: str
    point: str
    paperless: str
    mock: str


@dataclass
class Comparison:
    # The number of requests, the differences that fail the contract, and the points of
    # KNOWN that were seen, which don't.
    asked: int = 0
    differences: list = field(default_factory=list)
    known: dict = field(default_factory=dict)

    def points(self):
        """The requests of each difference, by its point and what either server says of it."""
        found = {}
        for difference in self.differences:
            found.setdefault((difference.point, difference.paperless, difference.mock), []).append(difference.request)
        return found

    def table(self):
        """The differences as a table in Markdown, or nothing without any."""
        points = self.points()
        if not points:
            return ""
        lines = [f"**The mock differs from Paperless in {len(points)} point(s):**", "", "| Point | Paperless | The mock | Requests |", "| --- | --- | --- | --- |"]
        lines += [f"| {code(point)} | {code(paperless)} | {code(mock)} | {cell(', '.join(requests))} |" for (point, paperless, mock), requests in points.items()]
        return "\n".join(lines)


def cell(text):
    """Text in a cell of a table in Markdown."""
    return text.replace("|", "\\|").replace("\n", " ")


def code(text):
    """Text as code in a cell of a table in Markdown, since it may be the markup of an excerpt."""
    return "`" + cell(text).replace("`", "'") + "`"


def requests_of_the_app(fields, recent_fields):
    """What the app sends to Paperless, from lib/Service/PaperlessApiService.php, and what
    makes Paperless refuse it. Not the points where the mock is stricter on purpose, to
    notice a mistake of the app: a request without fields and an unknown field to order by."""
    search = {"page": 1, "page_size": 25, "fields": fields}
    pages = {"query": "{several}", "page_size": 1, "fields": fields}
    within = {"created__date__gte": "2000-01-01", "created__date__lte": "2100-12-31"}
    return [
        Ask("A search", DOCUMENTS, {"query": "{several}", **search}),
        Ask("A search with escaped characters", DOCUMENTS, {"query": "{marked}", **search}, entities=True),
        Ask("A search that finds nothing", DOCUMENTS, {"query": NOTHING, **search}),
        # Paperless-ngx 2 ignores the simple mode and parses what it can of a query, so
        # the app never asks it for either.
        Ask("The simple mode", DOCUMENTS, {"text": "{several}", **search}, lines=("3",)),
        Ask("A query still being typed", DOCUMENTS, {"query": "{several} created:[2026 to", **search}, lines=("3",)),
        Ask("A search within dates", DOCUMENTS, {"query": "{several}", **within, **search}),
        # Without a term, as Paperless-ngx 2 fails a search from no date with a text of its own.
        Ask("A list from no date", DOCUMENTS, {"created__date__gte": "tomorrow", **search}),
        Ask("The first page", DOCUMENTS, {**pages, "page": 1}),
        Ask("A page in the middle", DOCUMENTS, {**pages, "page": 2}),
        Ask("The last page", DOCUMENTS, {**pages, "page": 3}),
        Ask("A page past the last", DOCUMENTS, {**pages, "page": 4}),
        Ask("The test of the connection", DOCUMENTS, {"page": 1, "page_size": 1, "fields": "id"}),
        Ask("The newest documents", DOCUMENTS, {"ordering": "-added", "page": 1, "page_size": 25, "fields": recent_fields}),
        Ask("The names of correspondents", "/api/correspondents/", {"id__in": f"{{correspondent}},{UNKNOWN_ID}", "page_size": 2}),
        Ask("The names of types", "/api/document_types/", {"id__in": f"{{document_type}},{UNKNOWN_ID}", "page_size": 2}),
        Ask("The names of tags", "/api/tags/", {"id__in": f"{{tag}},{UNKNOWN_ID}", "page_size": 2}),
        Ask("What an account may do", "/api/ui_settings/"),
        Ask("What a superuser may do", "/api/ui_settings/", token="superuser"),
        Ask("Documents similar to one", DOCUMENTS, {"more_like_id": "{document}", **search}),
        Ask("Documents similar to an unknown one", DOCUMENTS, {"more_like_id": UNKNOWN_ID, **search}),
        Ask("Documents similar to a hidden one", DOCUMENTS, {"more_like_id": "{hidden}", **search}, token="stranger"),
        # Paperless-ngx 2 answers this with an empty list; the app asks for one or the other.
        Ask("Similar documents beside a term", DOCUMENTS, {"more_like_id": "{document}", "query": "{several}", **search}, lines=("3",)),
        Ask("A version of the API newer than Paperless knows", DOCUMENTS, {"page": 1, "page_size": 1, "fields": "id"}, accept=NEWER_ACCEPT),
        Ask("A version of the API that only Paperless-ngx 2 knows", DOCUMENTS, {"page": 1, "page_size": 1, "fields": "id"}, accept=OLDER_ACCEPT, lines=("3",)),
        Ask("A wrong token", DOCUMENTS, {"page": 1, "page_size": 1, "fields": "id"}, token="wrong"),
        Ask("No token", DOCUMENTS, {"page": 1, "page_size": 1, "fields": "id"}, token=""),
    ]


def send(ask, archive, accept):
    """The status, the headers and the body of the answer of a server to a request."""
    query = {name: str(value).format(**vars(archive)) for name, value in ask.query.items()}
    headers = {"Accept": ask.accept or accept}
    if ask.token:
        token = getattr(archive, ask.token, ask.token)
        headers["Authorization"] = f"Token {token}"
    url = f"{archive.origin}{ask.path}?{urlencode(query)}"
    try:
        with build_opener(ProxyHandler({})).open(Request(url, headers=headers), timeout=60) as response:
            return response.status, response.headers, response.read()
    except HTTPError as error:
        return error.code, error.headers, error.read()


def kind(value):
    """The type of a value in JSON, with the forms of a text that the app relies on."""
    if value is None:
        return NULL
    if isinstance(value, bool):
        return "a boolean"
    if isinstance(value, int):
        return "a whole number"
    if isinstance(value, float):
        return "a decimal number"
    if DATE.fullmatch(value):
        return "a date"
    if MOMENT.fullmatch(value):
        return "a moment"
    return "a text"


def describe(value):
    """The form of a value in JSON: for each path in it, such as .results[].id, the types
    of its values, with ABSENT where an element of a list lacks what others have."""
    if isinstance(value, dict):
        found = {"": {"an object"}}
        for key, item in value.items():
            found.update({f".{key}{path}": kinds for path, kinds in describe(item).items()})
        return found
    if isinstance(value, list):
        elements = [describe(item) for item in value]
        found = {"": {"a list"}}
        if not elements:
            found["[]"] = {EMPTY}
        for path in {path for element in elements for path in element}:
            found[f"[]{path}"] = set().union(*(element.get(path, {ABSENT}) for element in elements))
        return found
    return {"": {kind(value)}}


def excerpts_in(value, key=""):
    """Each excerpt in a value in JSON that isn't empty, by the path of its field."""
    if isinstance(value, dict):
        for name, item in value.items():
            yield from excerpts_in(item, f"{key}.{name}" if key else name)
    elif isinstance(value, list):
        for item in value:
            yield from excerpts_in(item, f"{key}[]")
    elif isinstance(value, str) and key.rsplit(".", 1)[-1] in EXCERPTS:
        yield key, value


def link(value, archive):
    """A link to another page, as it would read on either server: without the address of
    the server, and without its term."""
    if not isinstance(value, str) or not value.startswith(archive.origin):
        return json.dumps(value)
    return re.sub(rf"(?<==){re.escape(quote_plus(archive.several))}(?=&|$)", "{several}", value.removeprefix(archive.origin))


def normalise(ask, archive, status, headers, body):
    """All that the two answers have to have in common, by the name of each point."""
    version = headers.get("X-Version")
    matched = VERSION.fullmatch(version or "")
    points = {
        "status": {str(status)},
        "media type": {(headers.get("Content-Type") or ABSENT).split(";")[0].strip()},
        "header X-Version": {ABSENT if version is None else matched.group(1) if matched else version},
        "header X-Api-Version": {headers.get("X-Api-Version") or ABSENT},
    }
    try:
        data = json.loads(body)
    except ValueError:
        data = None
    if not isinstance(data, dict):
        points["body"] = {body.decode("utf-8", "replace")}
        return points
    points.update({path.removeprefix("."): kinds for path, kinds in describe(data).items() if path})
    for name in ("next", "previous"):
        if name in data:
            points[name] = {link(data[name], archive)}
    for path, excerpt in excerpts_in(data):
        marks = {f"{path}, its markup": MARKUP.findall(excerpt)}
        if ask.entities:
            marks[f"{path}, its entities"] = ENTITY.findall(excerpt)
        for point, found in marks.items():
            if found:
                points.setdefault(point, set()).update(found)
    return points


def said(kinds):
    """The values of a point as a text for the table of differences."""
    return " or ".join(sorted(kinds))


def agree(ours, theirs, nullable):
    """Whether the values of a point are the same; for a field that may be empty, null
    stands for a value that this document lacks, not for another type."""
    if not nullable:
        return ours == theirs
    ours, theirs = ours - {NULL}, theirs - {NULL}
    return ours == theirs or (not ours and ABSENT not in theirs) or (not theirs and ABSENT not in ours)


def differences(ask, paperless, mock):
    """The points that two normalised answers differ in, as Difference."""
    found = []
    # What a list without elements in one answer would hold can't be compared.
    unknown = [path for points in (paperless, mock) for path, kinds in points.items() if kinds == {EMPTY}]
    for point in sorted(set(paperless) | set(mock)):
        if point.startswith(UNCOMPARED) or any(point == path or point.startswith((f"{path}.", f"{path}[")) for path in unknown):
            continue
        ours, theirs = paperless.get(point, {ABSENT}) - {EMPTY}, mock.get(point, {ABSENT}) - {EMPTY}
        if not agree(ours, theirs, point.rsplit(".", 1)[-1] in NULLABLE):
            found.append(Difference(ask.name, point, said(ours), said(theirs)))
    return found


def compare(line, paperless, mock, accept, fields, recent_fields):
    """Sends each request of the app to both servers and compares the answers."""
    comparison = Comparison()
    known = KNOWN.get(line, {})
    for ask in requests_of_the_app(fields, recent_fields):
        if line not in ask.lines:
            continue
        comparison.asked += 1
        answers = [normalise(ask, archive, *send(ask, archive, accept)) for archive in (paperless, mock)]
        for difference in differences(ask, *answers):
            if difference.point in known:
                comparison.known[difference.point] = known[difference.point]
            else:
                comparison.differences.append(difference)
    for point, reason in known.items():
        if point not in comparison.known:
            comparison.differences.append(Difference("Every request", point, "the same as the mock", f"listed in KNOWN of parity.py as different: {reason}"))
    return comparison
