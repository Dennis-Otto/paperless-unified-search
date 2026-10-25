#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Checks what the commands of occ wrote, from standard input, in the mode of the first
argument:

  verdicts ID=VERDICT[=PATH] ...  the JSON of paperless_unified_search:search --explain
                                  --json: exactly these documents, in this order, each
                                  with its verdict and, where given, the path of its file
  try USER ID=VERDICT ...         the answer of Try a search of the settings page: the
                                  documents of USER with these verdicts, in this order,
                                  each with its ID, title and verdict and nothing more
  setupcheck SEVERITY TEXT        the JSON of occ setupchecks: the check of the app has
                                  this severity, and its description holds the text
  shares SUMMARY WARNING ...      the text of paperless_unified_search:check: the line
                                  SUMMARY of the shares of the archive account, such as
                                  "✓ Shared read-only: 1 user.", and right after it
                                  exactly these warnings about shares, each a line "! /…"
  token [WARNING ...]             the text of paperless_unified_search:check: right after
                                  the line of the connection, the warnings about the
                                  account of the API token, such as superuser, in this
                                  order, or the line that it may change nothing and may
                                  view what the results show when none is given
"""

import itertools
import json
import sys

SETUP_CHECK = "OCA\\PaperlessUnifiedSearch\\SetupCheck\\PaperlessSetupCheck"

# What occ says about the account of the API token, in English, as
# lib/Service/SettingsMessages.php and lib/Command/Check.php word it.
TOKEN_FINE = "✓ The account of the API token may change no document, and may view correspondents and document types."
TOKEN_WARNINGS = {
    "superuser": "The API token belongs to a Superuser of Paperless: whoever gets hold of it can change and delete every document. Use the token of an account that may only view documents.",
    "staff": "The API token belongs to an Admin of Paperless, who may also read its logs: whoever gets hold of the token can too. Use the token of an account that is neither Admin nor Superuser.",
    "changes_documents": "The account of the API token may add, change or delete documents in Paperless: whoever gets hold of the token can too. Use the token of an account that may only view documents.",
    "hides_correspondents": "The account of the API token may not view correspondents in Paperless, so results show without them. Give the account the permission View for Correspondents.",
    "hides_document_types": "The account of the API token may not view document types in Paperless, so results show without them. Give the account the permission View for Document Types.",
}


def fail(message):
    raise AssertionError(message)


# occ writes UTF-8, such as the ✓ of check, whatever the locale of Python.
text = sys.stdin.buffer.read().decode("utf-8")
mode = sys.argv[1]

if mode == "shares":
    summary, expected = sys.argv[2], [f"! {warning}" for warning in sys.argv[3:]]
    lines = text.splitlines()
    if summary not in lines:
        fail(f"Expected the line {summary!r} about the shares, got {text!r}")
    following = lines[lines.index(summary) + 1:]
    warnings = list(itertools.takewhile(lambda line: line.startswith("! /"), following))
    if warnings != expected:
        fail(f"Expected the warnings {expected!r} about the shares, got {warnings!r}")
    sys.exit(0)

if mode == "token":
    lines = text.splitlines()
    connection = next((index for index, line in enumerate(lines) if line.startswith("✓ Paperless ") and " answers at " in line), None)
    if connection is None:
        fail(f"occ checked no connection to Paperless: {text!r}")
    unknown = [warning for warning in sys.argv[2:] if warning not in TOKEN_WARNINGS]
    if unknown:
        fail(f"Unknown warnings about the token: {unknown!r}")
    expected = [f"! {TOKEN_WARNINGS[warning]}" for warning in sys.argv[2:]] or [TOKEN_FINE]
    # The lines about the token follow the connection, and the archive account follows them.
    actual = []
    for line in lines[connection + 1:]:
        if line.startswith("✓ The archive account"):
            break
        actual.append(line)
    if actual != expected:
        fail(f"Expected the lines {expected!r} about the API token after the connection, got {actual!r} in {text!r}")
    sys.exit(0)

try:
    payload = json.loads(text)
except json.JSONDecodeError as error:
    fail(f"occ wrote no JSON ({error}): {text!r}")

if mode == "verdicts":
    expected = []
    for argument in sys.argv[2:]:
        document, verdict, *path = argument.split("=", 2)
        expected.append((int(document), verdict, path[0] if path else None))
    documents = payload.get("documents", [])
    actual = [(item.get("id"), item.get("verdict")) for item in documents]
    if actual != [(document, verdict) for document, verdict, _ in expected]:
        fail(f"Expected the verdicts {expected!r}, got {documents!r}")
    for (document, _, path), item in zip(expected, documents):
        if path is not None and item.get("path") != path:
            fail(f"Expected the file {path!r} for document {document}, got {item!r}")
    sys.exit(0)

if mode == "try":
    user, expected = sys.argv[2], [tuple(argument.split("=", 1)) for argument in sys.argv[3:]]
    documents = payload.get("documents", [])
    if payload.get("user") != user or [(str(item.get("id")), item.get("verdict")) for item in documents] != expected:
        fail(f"Expected the verdicts {expected!r} for {user}, got {payload!r}")
    for item in documents:
        if sorted(item) != ["id", "title", "verdict"]:
            fail(f"Try a search tells more of a document than its ID, title and verdict: {item!r}")
    sys.exit(0)

if mode == "setupcheck":
    severity, needle = sys.argv[2], sys.argv[3]
    checks = {name: check for category in payload.values() for name, check in category.items()}
    check = checks.get(SETUP_CHECK)
    if check is None:
        fail(f"occ setupchecks has no check of the app: {sorted(checks)!r}")
    if check.get("severity") != severity or needle not in (check.get("description") or ""):
        fail(f"Expected a check with the severity {severity} that names {needle!r}, got {check!r}")
    parameters = check.get("descriptionParameters") or {}
    link = parameters.get("section", {}).get("link", "")
    if severity != "success" and not link.endswith("/settings/admin/paperless_unified_search"):
        fail(f"The warning doesn't link to the settings of the app: {check!r}")
    sys.exit(0)

fail(f"Unknown assertion mode: {mode}")
