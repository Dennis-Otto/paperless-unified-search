#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The steps of seed.sh that talk to the APIs of Paperless-ngx and Nextcloud.

seed.sh runs it in the container of Paperless, for its Python, in the network of the
demo: Paperless answers there as http://127.0.0.1:8000 and Nextcloud as
http://nextcloud. It needs the standard library alone, and every step may run again.

    python3 seed.py documents   gives Paperless the synthetic letters of documents/, as PDF
                                files with their correspondent, type and tags, and waits
                                until Paperless has read and indexed them
    python3 seed.py token       prints the API token of the administrator of Paperless
    python3 seed.py connect     saves the settings of Paperless Sync, with which occ
                                paperless_sync:run writes every document into the archive
                                account, as seed.sh then runs it in the container of Nextcloud
    python3 seed.py share       shares the archive, once it is written, with the demo user

And the steps of the check of who finds what, bash demo/seed.sh --check-access:

    python3 seed.py users            adds the users of Paperless of the check, robin and
                                     sam.paperless, who sign in nowhere
    python3 seed.py owners PLAN      gives each document the owner of the plan: uploader,
                                     the administrator of Paperless, as the demo uploads
                                     them, or per-user, the owners of OWNERS
    python3 seed.py settings CASE    saves the form of the case of the fixture on standard
                                     input with the settings route of Paperless Sync, as its
                                     settings page does
    python3 seed.py stored CASE      compares what Paperless Sync stored, the answers of occ
                                     config:app:get --details after the fixture on standard
                                     input, with the fixture
    python3 seed.py expect CASE USER compares the documents that the search shows the user,
                                     the answer of occ paperless_unified_search:search
                                     --json on standard input, with those of ACCESS
    python3 seed.py share USER       shares the archive with another user of Nextcloud
    python3 seed.py unshare USER     and takes that share away again

The administrator of Paperless comes from the environment of the container,
PAPERLESS_ADMIN_USER and PAPERLESS_ADMIN_PASSWORD; seed.sh hands the accounts of Nextcloud
to the steps that need them alone: NEXTCLOUD_ADMIN_USER, NEXTCLOUD_ADMIN_PASSWORD and
ARCHIVE_USER to connect, ARCHIVE_USER, ARCHIVE_PASSWORD and DEMO_USER to share, and of the
check NEXTCLOUD_ADMIN_USER and NEXTCLOUD_ADMIN_PASSWORD to settings, ARCHIVE_USER and
ARCHIVE_PASSWORD to share USER and unshare.
"""

import base64
import json
import os
import secrets
import sys
import time
import uuid
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

PAPERLESS = "http://127.0.0.1:8000"
NEXTCLOUD = "http://nextcloud"
DOCUMENTS = Path(__file__).resolve().parent / "documents"
# Where Paperless Sync writes the documents in the files of the archive account.
BASE_PATH = "Documents/Paperless"
ARCHIVE_FOLDER = "Archive"
# The term of the search that seed.sh shows, and that waits for the index of Paperless.
TERM = "invoice"
# The longest that Paperless may take to read the documents and to index them.
DEADLINE = 300

# The check of who finds what with the settings of Paperless Sync that decide it (#405):
# the users of Paperless that own documents with per-user archives, robin by the same ID
# as in Nextcloud and sam.paperless through the table of Paperless Sync, which names sam.
ACCESS_USERS = ("robin", "sam.paperless")
OWNERS = {
    "Electricity invoice - August 2026": "robin",
    "Dentist appointment reminder": "robin",
    "Internet invoice - July 2026": "sam.paperless",
    "Laptop receipt and warranty": "sam.paperless",
    # Without an owner, Paperless Sync gives it to its target user, the archive account.
    "Home insurance renewal": None,
}
ALL = sorted(OWNERS)
HOUSEHOLD = ["Electricity invoice - August 2026", "Home insurance renewal", "Internet invoice - July 2026"]
# The documents that the search shows each user in each case of the fixture: robin, whose
# line of tags names Household, sees only the three letters with that tag, although the
# archive shared with robin holds all five; jamie, with the same share and no line, sees
# all five, and sam, without a share, none. With per-user archives, robin and sam see the
# documents of their own users of Paperless, and jamie, with whom the archive account
# shares its archive, the one without an owner. With the single archive again, jamie sees
# all five, as in the demo.
ACCESS = {
    "tags-per-user": {"robin": HOUSEHOLD, "jamie": ALL, "sam": []},
    "per-user-archives": {
        "robin": ["Dentist appointment reminder", "Electricity invoice - August 2026"],
        "sam": ["Internet invoice - July 2026", "Laptop receipt and warranty"],
        "jamie": ["Home insurance renewal"],
    },
    "single-archive": {"jamie": ALL},
}


class Failed(Exception):
    """A step of the demo that didn't work, with what to look at."""


def request(url, *, method="GET", headers=None, data=None):
    """The status and the body of an answer, also of one with an error. An answer of
    429, Too Many Requests, such as Paperless gives to more than a few logins a minute,
    comes back after the wait that it asks for, up to a minute each time."""
    attempts = 5
    while True:
        try:
            with urlopen(Request(url, data=data, method=method, headers=headers or {}), timeout=120) as response:
                return response.status, response.read()
        except HTTPError as error:
            attempts -= 1
            if error.code != 429 or attempts == 0:
                return error.code, error.read()
            wait = error.headers.get("Retry-After", "")
            time.sleep(min(int(wait), 60) if wait.isdigit() else 30)
        except URLError as error:
            raise Failed(f"{url} doesn't answer: {error.reason}") from error


def decode(status, body, what):
    try:
        return json.loads(body)
    except ValueError as error:
        raise Failed(f"{what} answered no JSON (HTTP {status}): {body[:300]!r}") from error


def letter(path):
    """The metadata and the lines of a synthetic letter: a header like that of an e-mail,
    then a blank line and the text."""
    header, _, text = path.read_text(encoding="utf-8").partition("\n\n")
    fields = dict(line.split(": ", 1) for line in header.splitlines())
    return {
        "title": fields["Title"],
        "correspondent": fields["Correspondent"],
        "type": fields["Type"],
        "created": fields["Created"],
        "tags": [tag.strip() for tag in fields.get("Tags", "").split(",") if tag.strip()],
        "lines": text.rstrip("\n").split("\n"),
    }


def letters():
    return [letter(path) for path in sorted(DOCUMENTS.glob("*.txt"))]


def pdf(lines):
    """A PDF of one A4 page with the lines of a letter: the first one, the sender, as a
    heading, the others in a font of fixed width, so that the columns of the amounts stay
    as they are in the text, and a note at the foot that the document is synthetic."""

    def string(text):
        if not text.isascii():
            raise Failed(f"The line {text!r} of a document isn't ASCII, which the fonts of the PDF encode.")
        return "(" + text.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)") + ")"

    body = [f"{string(line)} '" for line in lines[1:]]
    stream = "\n".join(
        [
            "BT /F2 18 Tf 72 760 Td",
            f"{string(lines[0])} Tj",
            "/F1 10 Tf 14 TL T*",
            *body,
            "ET",
            "BT /F3 8 Tf 72 48 Td",
            "(A synthetic document of the demo of Paperless Unified Search.) Tj",
            "ET",
        ]
    )
    font = "<< /Type /Font /Subtype /Type1 /BaseFont /{} /Encoding /WinAnsiEncoding >>"
    objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
        "/Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R >> >> /Contents 7 0 R >>",
        font.format("Courier"),
        font.format("Helvetica-Bold"),
        font.format("Helvetica"),
        f"<< /Length {len(stream)} >>\nstream\n{stream}\nendstream",
    ]
    document = "%PDF-1.4\n"
    offsets = []
    for number, content in enumerate(objects, start=1):
        offsets.append(len(document))
        document += f"{number} 0 obj\n{content}\nendobj\n"
    # The table of the places of the objects, each entry 20 bytes long.
    xref = len(document)
    document += f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n"
    document += "".join(f"{offset:010d} 00000 n \n" for offset in offsets)
    document += f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n"
    # Only ASCII, so that the places count bytes.
    return document.encode("ascii")


class Paperless:
    def __init__(self):
        self.user = os.environ["PAPERLESS_ADMIN_USER"]
        self.password = os.environ["PAPERLESS_ADMIN_PASSWORD"]
        self.token = ""

    def call(self, path, *, method="GET", query=None, data=None, content_type=None):
        # The password of the administrator, until a step needs the token itself: every
        # login for a token counts towards the few a minute that Paperless allows.
        credentials = base64.b64encode(f"{self.user}:{self.password}".encode()).decode()
        headers = {"Accept": "application/json", "Authorization": f"Token {self.token}" if self.token else f"Basic {credentials}"}
        if content_type:
            headers["Content-Type"] = content_type
        url = f"{PAPERLESS}{path}" + (f"?{urlencode(query, doseq=True)}" if query else "")
        status, body = request(url, method=method, headers=headers, data=data)
        answer = decode(status, body, f"Paperless at {path}")
        if status >= 300:
            raise Failed(f"Paperless answered {path} with HTTP {status}: {body[:300]!r}")
        return answer

    def log_in(self):
        answer = self.call(
            "/api/token/",
            method="POST",
            data=urlencode({"username": self.user, "password": self.password}).encode(),
            content_type="application/x-www-form-urlencoded",
        )
        self.token = answer["token"]
        return self.token

    def named(self, endpoint, name):
        """The ID of the correspondent, the type or the tag of the name, which it creates
        when Paperless has none: one that Paperless never assigns by itself."""
        found = self.call(f"/api/{endpoint}/", query={"name__iexact": name})["results"]
        if found:
            return found[0]["id"]
        created = self.call(
            f"/api/{endpoint}/",
            method="POST",
            data=json.dumps({"name": name, "matching_algorithm": 0}).encode(),
            content_type="application/json",
        )
        return created["id"]

    def titles(self):
        answer = self.call("/api/documents/", query={"page_size": 100, "fields": "id,title"})
        return {document["title"] for document in answer["results"]}

    def upload(self, document):
        fields = [
            ("title", document["title"]),
            ("created", document["created"]),
            ("correspondent", self.named("correspondents", document["correspondent"])),
            ("document_type", self.named("document_types", document["type"])),
        ]
        fields += [("tags", self.named("tags", tag)) for tag in document["tags"]]
        boundary = uuid.uuid4().hex
        parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode() for name, value in fields]
        parts.append(
            f'--{boundary}\r\nContent-Disposition: form-data; name="document"; filename="{document["title"]}.pdf"\r\n'
            "Content-Type: application/pdf\r\n\r\n".encode()
            + pdf(document["lines"])
            + b"\r\n"
        )
        parts.append(f"--{boundary}--\r\n".encode())
        return self.call(
            "/api/documents/post_document/",
            method="POST",
            data=b"".join(parts),
            content_type=f"multipart/form-data; boundary={boundary}",
        )

    def wait(self, tasks, documents):
        """Waits until Paperless has read every document and its index finds the term."""
        expected = sum(TERM in " ".join([document["title"], *document["lines"]]).lower() for document in documents)
        deadline = time.monotonic() + DEADLINE
        pending = dict(tasks)
        while True:
            for task, title in list(pending.items()):
                found = self.call("/api/tasks/", query={"task_id": task})
                # A list with SUCCESS and the message as result in Paperless-ngx 2, a page
                # with success and the result as result_data in Paperless-ngx 3.
                found = found.get("results", []) if isinstance(found, dict) else found
                status = str(found[0]["status"]).upper() if found else "PENDING"
                if status == "FAILURE":
                    result = str(found[0].get("result") or found[0].get("result_data") or "")
                    # A run that stopped after an upload left the document on its way, which
                    # the list of the documents shows once Paperless has read it.
                    if "duplicate" not in result.lower():
                        raise Failed(f"Paperless couldn't read {title}: {result}")
                if status in ("SUCCESS", "FAILURE"):
                    del pending[task]
            present = self.titles()
            if not pending and all(document["title"] in present for document in documents):
                count = self.call("/api/documents/", query={"query": TERM, "page_size": 1, "fields": "id"})["count"]
                if count >= expected:
                    return
            if time.monotonic() > deadline:
                missing = sorted(document["title"] for document in documents if document["title"] not in present)
                raise Failed(f"Paperless didn't read and index the documents within {DEADLINE} s; it still lacks {missing}.")
            time.sleep(2)


def documents():
    paperless = Paperless()
    wanted = letters()
    present = paperless.titles()
    tasks = {}
    for document in wanted:
        if document["title"] in present:
            print(f"Paperless has {document['title']} already.")
            continue
        tasks[paperless.upload(document)] = document["title"]
        print(f"Paperless reads {document['title']}.")
    paperless.wait(tasks, wanted)
    print(f"Paperless has read and indexed the {len(wanted)} documents of the demo.")


def token():
    print(Paperless().log_in())


class Nextcloud:
    def __init__(self, user, password):
        self.authorization = "Basic " + base64.b64encode(f"{user}:{password}".encode()).decode()

    def call(self, path, *, method="GET", form=None):
        headers = {"Accept": "application/json", "OCS-APIRequest": "true", "Authorization": self.authorization}
        data = None
        if form is not None:
            data = urlencode(form, doseq=True).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        status, body = request(f"{NEXTCLOUD}{path}", method=method, headers=headers, data=data)
        answer = decode(status, body, f"Nextcloud at {path}")
        if status >= 300:
            message = answer.get("message") or answer.get("ocs", {}).get("meta", {}).get("message") or body[:300]
            raise Failed(f"Nextcloud answered {path} with HTTP {status}: {message}")
        return answer


def connect():
    admin = Nextcloud(os.environ["NEXTCLOUD_ADMIN_USER"], os.environ["NEXTCLOUD_ADMIN_PASSWORD"])
    # The settings of Paperless Sync, as its administration settings save them, with the
    # token, which it keeps in the credentials store of Nextcloud.
    settings = {
        "paperless_url": "http://paperless:8000",
        "paperless_local_network": "1",
        "enabled": "1",
        "target_user": os.environ["ARCHIVE_USER"],
        "base_path": BASE_PATH,
        "archive_folder": ARCHIVE_FOLDER,
    }
    form = {f"settings[{key}]": value for key, value in settings.items()}
    form["token"] = Paperless().log_in()
    admin.call("/apps/paperless_sync/settings", method="POST", form=form)
    print("Paperless Sync knows Paperless and the archive account.")


SHARES = "/ocs/v2.php/apps/files_sharing/api/v1/shares"
ARCHIVE = f"/{BASE_PATH}/{ARCHIVE_FOLDER}"


def shares_of_the_archive():
    """The archive account, and its shares of the archive with users."""
    owner = Nextcloud(os.environ["ARCHIVE_USER"], os.environ["ARCHIVE_PASSWORD"])
    return owner, owner.call(f"{SHARES}?{urlencode({'path': ARCHIVE, 'format': 'json'})}")["ocs"]["data"]


def share(user=None):
    archive_user = os.environ["ARCHIVE_USER"]
    user = user or os.environ["DEMO_USER"]
    # The archive, shared read-only with the demo user, as an administrator shares it
    # with the people who may find its documents.
    owner, existing = shares_of_the_archive()
    if any(entry.get("share_with") == user for entry in existing):
        print(f"{archive_user} shares {ARCHIVE} with {user} already.")
        return
    owner.call(f"{SHARES}?format=json", method="POST", form={"path": ARCHIVE, "shareType": 0, "shareWith": user, "permissions": 1})
    print(f"{archive_user} shares {ARCHIVE} read-only with {user}.")


def unshare(user):
    owner, existing = shares_of_the_archive()
    for entry in existing:
        if entry.get("share_with") == user:
            owner.call(f"{SHARES}/{entry['id']}?format=json", method="DELETE")
    print(f"{os.environ['ARCHIVE_USER']} shares {ARCHIVE} no longer with {user}.")


def users():
    """The users of Paperless who own documents with per-user archives. Nobody signs in as
    them, so each gets a random password that nobody learns."""
    paperless = Paperless()
    existing = {user["username"] for user in paperless.call("/api/users/", query={"page_size": 1000})["results"]}
    for name in ACCESS_USERS:
        if name in existing:
            print(f"Paperless has the user {name} already.")
            continue
        paperless.call(
            "/api/users/",
            method="POST",
            data=json.dumps({"username": name, "password": secrets.token_urlsafe(24)}).encode(),
            content_type="application/json",
        )
        print(f"Paperless has the user {name}.")


def owners(plan):
    """Gives each document of the demo the owner of the plan: uploader, the administrator
    of Paperless, who uploaded them, or per-user, the owner of OWNERS."""
    if plan not in ("uploader", "per-user"):
        raise Failed(f"No plan of owners {plan!r}: uploader or per-user.")
    paperless = Paperless()
    ids = {user["username"]: user["id"] for user in paperless.call("/api/users/", query={"page_size": 1000})["results"]}
    found = paperless.call("/api/documents/", query={"page_size": 100, "fields": "id,title,owner"})["results"]
    for document in found:
        if document["title"] not in OWNERS:
            continue
        name = paperless.user if plan == "uploader" else OWNERS[document["title"]]
        owner = ids[name] if name else None
        if document.get("owner") != owner:
            paperless.call(
                f"/api/documents/{document['id']}/",
                method="PATCH",
                data=json.dumps({"owner": owner}).encode(),
                content_type="application/json",
            )
        print(f"{document['title']}: owned by {name or 'nobody'}.")


def case_of(fixture, case):
    cases = fixture["cases"]
    if case not in cases or case not in ACCESS:
        raise Failed(f"No case {case!r} in the fixture and in ACCESS: {', '.join(sorted(cases))}.")
    return cases[case]


def settings(case):
    """Saves the form of the case of the fixture as the settings page of Paperless Sync
    does, with its route, which validates and normalizes the values with the code of
    Paperless Sync, keeps every other setting and the stored token, and tests the
    connection, as the synchronization is on."""
    form = case_of(json.load(sys.stdin), case)["form"]
    admin = Nextcloud(os.environ["NEXTCLOUD_ADMIN_USER"], os.environ["NEXTCLOUD_ADMIN_PASSWORD"])
    admin.call("/apps/paperless_sync/settings", method="POST", form={f"settings[{key}]": value for key, value in form.items()})
    print(f"Paperless Sync saved the settings of {case}: " + ", ".join(f"{key} {value!r}" for key, value in form.items()) + ".")


def json_values(text):
    """Every JSON value of a text, one after the other, as the answers of several commands."""
    decoder = json.JSONDecoder()
    values = []
    index = 0
    while True:
        while index < len(text) and text[index].isspace():
            index += 1
        if index == len(text):
            return values
        try:
            value, index = decoder.raw_decode(text, index)
        except ValueError as error:
            raise Failed(f"No JSON at {text[index:index + 300]!r}") from error
        values.append(value)


def stored(case):
    """Compares what Paperless Sync stored with the fixture: each key that the search reads,
    with its type, its lazy flag and its value, as occ config:app:get --details names them."""
    fixture, *details = json_values(sys.stdin.read())
    expected = case_of(fixture, case)["stored"]
    found = {detail["key"]: detail for detail in details}
    if sorted(found) != sorted(expected):
        raise Failed(f"The settings of Paperless Sync that seed.sh read, {sorted(found)}, aren't those of the fixture, {sorted(expected)}.")
    differences = []
    for key, want in expected.items():
        detail = found[key]
        value = detail["value"]
        if want["type"] == "boolean":
            value = str(value).lower() in ("1", "true", "yes", "on")
        got = {"type": detail.get("type"), "lazy": detail.get("lazy"), "value": value}
        if got != want:
            differences.append(f"{key}: Paperless Sync stored {got}, the fixture says {want}")
    if differences:
        raise Failed(
            f"Paperless Sync {fixture['paperless_sync']} stores the settings of {case} otherwise than "
            "tests/Unit/fixtures/paperless-sync-settings.json, which the unit tests of the search read:\n  "
            + "\n  ".join(differences)
        )
    print(f"Paperless Sync stored the {len(expected)} settings of {case} as the fixture says.")


def expect(case, user):
    """Compares the documents that the search shows the user with those of ACCESS."""
    if user not in ACCESS.get(case, {}):
        raise Failed(f"ACCESS names no documents of {user} for {case}.")
    answers = json_values(sys.stdin.read())
    if len(answers) != 1 or not isinstance(answers[0], dict):
        raise Failed(f"occ paperless_unified_search:search gave no answer in JSON for {user}.")
    found = answers[0].get("documents", [])
    shown = sorted(document["title"] for document in found if document["verdict"] == "shown")
    wanted = sorted(ACCESS[case][user])
    if shown != wanted:
        verdicts = "\n  ".join(f"{document['title']}: {document['verdict']}" for document in found)
        raise Failed(f"With the settings of {case}, the search shows {user} {shown}, not {wanted}. Its verdicts:\n  {verdicts}")
    print(f"With the settings of {case}, the search shows {user} the {len(shown)} document(s) of ACCESS.")


def main():
    steps = {
        "documents": documents,
        "token": token,
        "connect": connect,
        "share": share,
        "users": users,
        "owners": owners,
        "settings": settings,
        "stored": stored,
        "expect": expect,
        "unshare": unshare,
    }
    # The number of arguments of each step: share takes a user or none.
    arguments = {"owners": (1,), "settings": (1,), "stored": (1,), "expect": (2,), "share": (0, 1), "unshare": (1,)}
    if len(sys.argv) < 2 or sys.argv[1] not in steps or len(sys.argv) - 2 not in arguments.get(sys.argv[1], (0,)):
        print(f"Usage: python3 seed.py {'|'.join(steps)} [ARGUMENTS]", file=sys.stderr)
        return 2
    try:
        steps[sys.argv[1]](*sys.argv[2:])
    except Failed as failure:
        print(failure, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
