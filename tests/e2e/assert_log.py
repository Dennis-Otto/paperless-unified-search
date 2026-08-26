#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import sys

APP_ID = "paperless_unified_search"

for raw_line in sys.stdin:
    line = raw_line.strip()
    if not line:
        continue
    try:
        record = json.loads(line)
    except json.JSONDecodeError:
        continue

    app = str(record.get("app", ""))
    message = str(record.get("message", ""))
    level = record.get("level", 0)
    if APP_ID in (app, message) and isinstance(level, int) and level >= 3:
        raise AssertionError(f"Nextcloud logged an application error: {record!r}")
