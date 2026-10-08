"""The advisories that don't affect a release, as an OpenVEX document."""

from __future__ import annotations

import json
import tomllib

OPENVEX = "https://openvex.dev/ns/v0.2.0"


def openvex(repo: str, tag: str, timestamp: str, accepted: str) -> str | None:
    """An OpenVEX document of the release: every advisory that osv-scanner.toml
    accepts, each with its reason, is not one that affects the release. None when it
    accepts none."""
    entries = tomllib.loads(accepted).get("IgnoredVulns", [])
    if not entries:
        return None
    product = f"pkg:github/{repo}@{tag}"
    name = repo.rsplit("/", 1)[-1]
    document = {
        "@context": OPENVEX,
        "@id": f"https://github.com/{repo}/releases/download/{tag}/{name}.openvex.json",
        "author": f"The release bot of {repo}",
        "timestamp": timestamp,
        "version": 1,
        "statements": [
            {
                "vulnerability": {"name": entry["id"]},
                "products": [{"@id": product}],
                "status": "not_affected",
                "impact_statement": entry.get("reason", "").strip()
                or "The project accepts this advisory; see its osv-scanner.toml.",
            }
            for entry in entries
        ],
    }
    return json.dumps(document, indent=2, ensure_ascii=False) + "\n"
