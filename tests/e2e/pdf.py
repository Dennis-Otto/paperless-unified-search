#!/usr/bin/env python3

# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

# Writes a PDF of one A4 page with the line of its argument to standard output: the file
# that a result of the search opens, which the Viewer of Nextcloud draws in flow.mjs.
# run.sh runs it in the container of the mock of Paperless, which has Python.

import sys


def pdf(line):
    text = line.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")
    stream = f"BT /F1 18 Tf 72 760 Td ({text}) Tj ET"
    objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        f"<< /Length {len(stream)} >>\nstream\n{stream}\nendstream",
    ]
    document = "%PDF-1.4\n"
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(len(document))
        document += f"{number} 0 obj\n{body}\nendobj\n"
    # The table of the places of the objects, each entry 20 bytes long.
    xref = len(document)
    document += f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n"
    document += "".join(f"{offset:010d} 00000 n \n" for offset in offsets)
    document += f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n"
    # Only ASCII, so that the places count bytes.
    return document.encode("ascii")


sys.stdout.buffer.write(pdf(sys.argv[1]))
