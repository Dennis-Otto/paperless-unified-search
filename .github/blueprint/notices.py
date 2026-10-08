"""The licenses of the third-party components, for the releases."""

from __future__ import annotations

from blueprint.github import Json

UNNAMED = "Without a license in the dependency graph"


def third_party_notices(version: str, sbom: Json) -> str:
    """The third-party components of a release and their licenses, from the SPDX SBOM
    of GitHub's dependency graph, grouped by license."""
    document = sbom.get("sbom", sbom)
    root = {
        relation["relatedSpdxElement"]
        for relation in document.get("relationships", [])
        if relation.get("relationshipType") == "DESCRIBES"
    }
    groups: dict[str, list[tuple[str, str, str]]] = {}
    for package in document.get("packages", []):
        if package.get("SPDXID") in root:
            continue
        purl = next(
            (
                ref["referenceLocator"]
                for ref in package.get("externalRefs", [])
                if ref.get("referenceType") == "purl"
            ),
            "",
        )
        ecosystem = purl.removeprefix("pkg:").split("/", 1)[0] if purl else ""
        license_name = package.get("licenseConcluded") or UNNAMED
        if license_name == "NOASSERTION":
            license_name = UNNAMED
        groups.setdefault(license_name, []).append(
            (package.get("name", ""), package.get("versionInfo") or "", ecosystem)
        )
    lines = [
        "# Third-party components",
        "",
        f"The components that the repository uses at {version}, as GitHub's dependency"
        " graph lists them, each with the license that the graph names: what the code"
        " needs to run and the tools of its checks, tests and workflows. The package of"
        " the release contains only what its own build puts into it.",
    ]
    for license_name in sorted(
        groups, key=lambda name: (name == UNNAMED, name.lower())
    ):
        lines += ["", f"## {license_name}", "", "| Component | Version | Ecosystem |"]
        lines.append("| --- | --- | --- |")
        lines += [
            f"| {name} | {release} | {ecosystem} |"
            for name, release, ecosystem in sorted(set(groups[license_name]))
        ]
    if not groups:
        lines += ["", "The repository uses no third-party components."]
    return "\n".join(lines) + "\n"
