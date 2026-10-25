#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# Checks the log of Nextcloud, data/nextcloud.log, from standard input, at the end of
# run.sh, which sets log.condition to {"apps":["PHP","paperless_unified_search"]}, so that
# Nextcloud logs every record of PHP and of the app at debug, 0, every deprecation and
# notice of PHP as well, and everything else at its default level, warning. It fails on
#
# - an error, 3, or worse of the app: a record whose app is the app, or whose message,
#   exception or data names a file of the app or its namespace, such as an uncaught
#   exception that Nextcloud logs for the app index, core or PHP, with a trace through
#   lib/;
# - a deprecation, notice or warning of PHP raised in a file of the app, at any level:
#   the images of the end-to-end tests run a newer PHP than the unit tests, and the
#   next major version of PHP turns many deprecations into errors;
# - a warning, 2, of the app that EXPECTED_WARNINGS doesn't name.
#
# It prints how many records the log holds at each level, and of them the app, so that a
# log that grows shows in the output of the job, and each finding with its place in the
# files of the app, its request and the record. selftest.sh tests it with records of its
# own.
#
#   assert_log.py < nextcloud.log
#
# run.sh runs it in the container of the mock of Paperless, which has Python.

import json
import re
import sys

APP_ID = "paperless_unified_search"
# The app in the path of a file, such as that of a frame of a trace or of an error of
# PHP, and in the name of a class.
APP_PATH = f"/custom_apps/{APP_ID}/"
APP_NAMESPACE = "OCA\\PaperlessUnifiedSearch\\"
LEVELS = ("debug", "info", "warning", "error", "fatal")
WARNING = 2
ERROR = 3
# The fields of a record that name the code that wrote it. The others, such as the URL
# of the request, name the app in every request to it, whatever code failed.
CODE_FIELDS = ("message", "exception", "data")
# Nextcloud's ErrorHandler logs a deprecation of PHP at debug, a notice at info and a
# warning at warning, for the app PHP, with the message "<what> at <file>#<line>".
PHP_ERROR = re.compile(r" at (?P<file>/\S+)#(?P<line>\d+)\Z")
PHP_KINDS = ("deprecation of PHP", "notice of PHP", "warning of PHP")

# The warnings of the app that the tests provoke on purpose, each as the start of its
# message, a regular expression, with the steps of run.sh, or of the browser that it
# starts, that provoke it. Any other warning of the app fails the check: add one here
# only for a test that provokes it.
EXPECTED_WARNINGS = tuple(
    (re.compile(pattern), test)
    for pattern, test in (
        (
            r"The test of the connection to Paperless failed: local_address ",
            "the saves of the mock in run.sh and of other-host in accessibility.mjs, the switch for the local network off",
        ),
        (
            r"The test of the connection to Paperless failed: unknown_host ",
            "the save of https://paperless.invalid, a name without a record in the DNS, in run.sh",
        ),
        (
            r"The test of the connection to Paperless failed: unauthorized ",
            "the saves of e2e-wrong-token, which the mock refuses, in run.sh, accessibility.mjs and settings.mjs",
        ),
        (
            r"Paperless unified search failed \(OCA\\PaperlessUnifiedSearch\\Exception\\PaperlessRequestException\)\Z",
            "the search for e2e-error, which the mock answers with HTTP 500",
        ),
        (
            r"Value type is set to zero \(0\) in database\. ",
            (
                "the saves of the settings in run.sh right after occ paperless_unified_search:setup, as Apache "
                "still caches the configuration of the app for 3 seconds"
            ),
        ),
    )
)


def read_log(lines):
    """The records of the log, and how many lines aren't one."""
    found, unreadable = [], 0
    for raw_line in lines:
        line = raw_line.strip()
        if not line:
            continue
        try:
            record = json.loads(line)
        except json.JSONDecodeError:
            unreadable += 1
            continue
        if isinstance(record, dict):
            found.append(record)
        else:
            unreadable += 1
    return found, unreadable


def level_of(record):
    """The level of a record, or None for one that Nextcloud doesn't write."""
    level = record.get("level")
    if isinstance(level, int) and not isinstance(level, bool) and 0 <= level < len(LEVELS):
        return level
    return None


def texts(value):
    """Every text in a field of a record, without the arguments of the frames of a trace,
    which hold whatever the code passed, such as the paths of every app."""
    if isinstance(value, str):
        yield value
    elif isinstance(value, dict):
        for key, item in value.items():
            if key != "args":
                yield from texts(item)
    elif isinstance(value, list):
        for item in value:
            yield from texts(item)


def of_app(record):
    """Whether the app wrote the record, or the code of the app ran into it."""
    if record.get("app") == APP_ID:
        return True
    return any(APP_PATH in text or APP_NAMESPACE in text for field in CODE_FIELDS for text in texts(record.get(field)))


def in_app(file, line):
    """A place in the files of the app, such as lib/Search/Provider.php:42, or None."""
    if not isinstance(file, str) or APP_PATH not in file:
        return None
    return f"{file.split(APP_PATH, 1)[1]}:{line}"


def php_error(record):
    """The place in the files of the app where PHP raised the error of the record, or None."""
    if record.get("app") != "PHP":
        return None
    match = PHP_ERROR.search(str(record.get("message", "")))
    return in_app(match["file"], match["line"]) if match else None


def place(record):
    """The first place in the files of the app that the record names, or None."""
    found = php_error(record)
    exception = record.get("exception")
    while found is None and isinstance(exception, dict):
        frames = [exception, *(frame for frame in exception.get("Trace") or () if isinstance(frame, dict))]
        places = (
            in_app(frame.get("File", frame.get("file")), frame.get("Line", frame.get("line"))) for frame in frames
        )
        found = next((where for where in places if where), None)
        exception = exception.get("Previous")
    return found


def compact(record):
    """The record in JSON, with a line for each frame of its traces instead of the frame
    with its arguments."""

    def frames(exception):
        exception = dict(exception)
        if isinstance(exception.get("Trace"), list):
            exception["Trace"] = [
                f"{frame.get('file', '?')}:{frame.get('line', '?')} "
                f"{frame.get('class', '')}{frame.get('type', '')}{frame.get('function', '')}"
                if isinstance(frame, dict)
                else frame
                for frame in exception["Trace"]
            ]
        if isinstance(exception.get("Previous"), dict):
            exception["Previous"] = frames(exception["Previous"])
        return exception

    shown = dict(record)
    if isinstance(shown.get("exception"), dict):
        shown["exception"] = frames(shown["exception"])
    return json.dumps(shown, ensure_ascii=False, sort_keys=True)


def expected(record):
    """The test that provokes the warning of the app in the record, or None."""
    if record.get("app") != APP_ID:
        return None
    message = str(record.get("message", ""))
    return next((test for pattern, test in EXPECTED_WARNINGS if pattern.match(message)), None)


def finding(record):
    """What is wrong with a record of the log, or None when nothing is."""
    if not of_app(record):
        return None
    level = level_of(record)
    if level is None:
        return f"a record of the app at the level {record.get('level')!r}, which Nextcloud never writes"
    if level < ERROR and php_error(record) is not None:
        return f"a {PHP_KINDS[level]} in a file of the app"
    if level > ERROR:
        return "a fatal error of the app"
    if level == ERROR:
        return "an error of the app"
    if level == WARNING and expected(record) is None:
        return "a warning of the app that no test expects"
    return None


def request(record):
    """The request, or the command of occ, during which Nextcloud wrote the record."""
    command = record.get("occ_command")
    if isinstance(command, list) and command:
        return " ".join(str(part) for part in command)
    if record.get("url") not in (None, "", "--"):
        return f"{record.get('method') or '?'} {record['url']}"
    return "no request that the record names"


def plural(count, noun):
    """A count with its noun, such as "1 record" or "2 records"."""
    return f"{count} {noun}" if count == 1 else f"{count} {noun}s"


def counts(records):
    """How many records each level holds, such as "3 debug, 1 info, ..."."""
    levels = [level_of(record) for record in records]
    parts = [f"{levels.count(index)} {name}" for index, name in enumerate(LEVELS)]
    unknown = levels.count(None)
    if unknown:
        parts.append(f"{unknown} at no level of Nextcloud")
    return ", ".join(parts)


def main():
    records, unreadable = read_log(sys.stdin)
    app = [record for record in records if of_app(record)]
    print(f"The log of Nextcloud holds {plural(len(records), 'record')}: {counts(records)}.")
    print(f"Of them, {plural(len(app), 'record')} of the app: {counts(app)}.")
    if unreadable:
        print(f"Lines of the log that hold no record in JSON, which the check leaves out: {unreadable}.")
    for pattern, test in EXPECTED_WARNINGS:
        seen = sum(1 for record in app if level_of(record) == WARNING and expected(record) == test)
        print(f"Seen {plural(seen, 'time')}: the expected warning /{pattern.pattern}/, from {test}.")

    problems = [(found, record) for record in records for found in [finding(record)] if found]
    if not problems:
        return 0
    print(f"\nThe log of Nextcloud holds {plural(len(problems), 'record')} of the app that no test expects:")
    for found, record in problems:
        print(f"\n- {found}, for the app {record.get('app')!r}: {record.get('message')}")
        print(f"  at {place(record) or 'no file of the app'}, during {request(record)}")
        print(f"  {compact(record)}")
    print("\ntests/e2e/README.md#the-log-of-nextcloud explains each kind of finding and how to fix it.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
