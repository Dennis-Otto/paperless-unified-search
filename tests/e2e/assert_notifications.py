#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Checks an answer of the notifications API of Nextcloud for run.sh, from standard input:

    assert_notifications.py none        the user has no notification of the app
    assert_notifications.py one TITLE   the user has one notification of the app, which
                                        names the document with this title and links its
                                        file, as Nextcloud prepares it for a client
"""

import json
import sys

APP = "paperless_unified_search"


def fail(message):
    print(message, file=sys.stderr)
    sys.exit(1)


def main():
    mode = sys.argv[1]
    notifications = [
        notification
        for notification in json.load(sys.stdin)["ocs"]["data"]
        if notification["app"] == APP
    ]

    if mode == "none":
        if notifications:
            fail(f"A user who can't open the file got a notification: {notifications}")
        return

    title = sys.argv[2]
    if len(notifications) != 1:
        fail(f"Expected one notification about {title!r}, not {notifications}")
    notification = notifications[0]
    if title not in notification["subject"]:
        fail(f"The notification doesn't name {title!r}: {notification}")
    if "/f/" not in notification["link"]:
        fail(f"The notification doesn't link the file: {notification}")
    document = notification["subjectRichParameters"]["document"]
    if document["type"] != "file" or document["name"] != title or "/f/" not in document["link"]:
        fail(f"The rich subject doesn't name the file: {notification}")


if __name__ == "__main__":
    main()
