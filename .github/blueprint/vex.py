"""The advisories that don't affect a release, as an OpenVEX document."""

from __future__ import annotations

import json
import tomllib
from urllib.parse import quote

OPENVEX = "https://openvex.dev/ns/v0.2.0"


def openvex(
    repo: str, tag: str, timestamp: str, accepted: str, version: str | None = None
) -> str | None:
    """An OpenVEX document of the release: every advisory that osv-scanner.toml
    accepts, each with its reason, is not one that affects the release. None when it
    accepts none.

    The release is the product by its tag on GitHub, and by the name that a scanner
    gives the root of its SBOM, which build-release.yml names after the repository
    and the version of the release: Grype applies a statement to the matches of
    "grype sbom:<name>.spdx.json --vex <name>.openvex.json" only for the product
    pkg:generic/<name in lowercase>@<version>. The version is the tag without its v,
    unless the build gives it."""
    entries = tomllib.loads(accepted).get("IgnoredVulns", [])
    if not entries:
        return None
    name = repo.rsplit("/", 1)[-1]
    release = version if version is not None else tag.removeprefix("v")
    product = {
        "@id": f"pkg:github/{repo}@{tag}",
        "identifiers": {
            "purl": f"pkg:generic/{name.lower()}@{quote(release, safe='')}"
        },
    }
    document = {
        "@context": OPENVEX,
        "@id": f"https://github.com/{repo}/releases/download/{tag}/{name}.openvex.json",
        "author": f"The release bot of {repo}",
        "timestamp": timestamp,
        "version": 1,
        "statements": [
            {
                "vulnerability": {"name": entry["id"]},
                "products": [product],
                "status": "not_affected",
                "impact_statement": entry.get("reason", "").strip()
                or "The project accepts this advisory; see its osv-scanner.toml.",
            }
            for entry in entries
        ],
    }
    return json.dumps(document, indent=2, ensure_ascii=False) + "\n"
