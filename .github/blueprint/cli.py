"""The command line of blueprint.py."""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import tomllib
from collections.abc import Sequence
from pathlib import Path
from xml.etree import ElementTree

import blueprint
from blueprint.changelog import (
    add_unreleased,
    beta_notes,
    release_changelog,
    release_notes,
)
from blueprint.checklist import checklist, describe_steps
from blueprint.coverage_comment import Coverage, coverage_comment
from blueprint.diagrams import SOURCES, commit_request, extract, keep, listing, report
from blueprint.github import Api, GitHubError, current_repo
from blueprint.notices import third_party_notices
from blueprint.settings import CONFIG, Settings, apply, check, describe
from blueprint.update import SOURCE, Outdated, update_pull_request
from blueprint.vex import openvex


def changelog_command(arguments: argparse.Namespace) -> int:
    if arguments.command == "unreleased":
        path = Path(arguments.file)
        text = path.read_text(encoding="utf-8")
        path.write_text(
            add_unreleased(text, arguments.heading, arguments.entry), encoding="utf-8"
        )
        return 0
    if arguments.command == "beta":
        text = Path(arguments.file).read_text(encoding="utf-8")
        sys.stdout.write(beta_notes(arguments.version, text))
        return 0
    if arguments.command == "vex":
        accepted = Path(arguments.file)
        text = accepted.read_text(encoding="utf-8") if accepted.is_file() else ""
        document = openvex(arguments.repo, arguments.tag, arguments.timestamp, text)
        if document is not None:
            sys.stdout.write(document)
        return 0
    if arguments.command == "notices":
        sbom = json.loads(Path(arguments.sbom).read_text(encoding="utf-8"))
        sys.stdout.write(third_party_notices(arguments.version, sbom))
        return 0
    read = [Path(name).read_text(encoding="utf-8") for name in arguments.files]
    if arguments.action == "release":
        sys.stdout.write(release_changelog(arguments.version, *read))
    else:
        sys.stdout.write(release_notes(arguments.version, *read))
    return 0


def coverage_command(arguments: argparse.Namespace) -> int:
    # The coverage bot runs in the checkout at the path where the checks ran.
    root = Path.cwd()
    head = Coverage.parse(Path(arguments.head).read_text(encoding="utf-8"), root)
    base_file = Path(arguments.base)
    base = (
        Coverage.parse(base_file.read_text(encoding="utf-8"), root)
        if base_file.is_file()
        else None
    )
    sys.stdout.write(coverage_comment(head, base))
    return 0


def diagrams_command(arguments: argparse.Namespace, root: Path) -> int:
    if arguments.action == "keep":
        print(listing(keep(root, arguments.folder)), end="")
        return 0
    if arguments.action == "commit":
        if not arguments.repo:
            print("blueprint.py: diagrams commit needs --repo", file=sys.stderr)
            return 2
        request = commit_request(
            arguments.folder,
            arguments.repo,
            arguments.branch,
            arguments.head,
            arguments.headline,
            arguments.body,
        )
        print(json.dumps(request))
        return 0
    if arguments.action == "report":
        print(report(arguments.folder, arguments.kind), end="")
        return 0
    extracted = extract(root)
    for diagram in extracted:
        known = "" if diagram.new else ", which was there already"
        print(f"{diagram.page.as_posix()}: {diagram.source.as_posix()}{known}")
    if extracted:
        print(
            f"{len(extracted)} diagram(s) became pictures of their sources in "
            f"{SOURCES.as_posix()}/."
        )
    else:
        print("No ```mermaid block in the Markdown.")
    return 0


def utf8_output() -> None:
    """Windows consoles default to a code page without the marks of the checklist."""
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure is not None:
            reconfigure(encoding="utf-8")


def main(
    argv: Sequence[str] | None = None, api: Api | None = None, root: Path = Path()
) -> int:
    utf8_output()
    parser = argparse.ArgumentParser(
        prog="blueprint.py", description=blueprint.__doc__.split("\n\n")[0]
    )
    parser.add_argument(
        "--repo", help="OWNER/NAME; by default the repository of this folder"
    )
    parser.add_argument(
        "--config",
        type=Path,
        default=CONFIG,
        help="the settings, by default %(default)s",
    )
    commands = parser.add_subparsers(dest="command", required=True)
    settings_parser = commands.add_parser(
        "settings", help="compare or apply the settings as code"
    )
    settings_parser.add_argument("action", choices=["check", "apply"])
    commands.add_parser(
        "checklist", help="the steps outside the API, and which are done"
    )
    changelog_parser = commands.add_parser(
        "changelog",
        help="the changelog and the notes of a release, for the release bot",
    )
    changelog_parser.add_argument(
        "action",
        choices=["release", "notes"],
        help="release: the changelog of the release pull request; notes: the release notes",
    )
    changelog_parser.add_argument("version")
    changelog_parser.add_argument(
        "files",
        nargs=2,
        metavar="FILE",
        help="release: the changelog of main and that of the release branch; "
        "notes: the changelog of the release and the notes of release-please",
    )
    unreleased_parser = commands.add_parser(
        "unreleased", help="add an entry under Unreleased in the changelog, for bots"
    )
    unreleased_parser.add_argument("heading", help="such as Changed, without ###")
    unreleased_parser.add_argument("entry", help="the line, such as '- Supports ...'")
    unreleased_parser.add_argument("--file", default="CHANGELOG.md")
    beta_parser = commands.add_parser(
        "beta", help="the notes of a beta of the next release, for the release bot"
    )
    beta_parser.add_argument(
        "version", help="the version of the beta, such as 1.3.0-beta.2"
    )
    beta_parser.add_argument("--file", default="CHANGELOG.md")
    coverage_parser = commands.add_parser(
        "coverage",
        help="the comment of the coverage bot: a Cobertura report against that of main",
    )
    coverage_parser.add_argument("head", help="the report of the pull request")
    coverage_parser.add_argument(
        "base", nargs="?", default="", help="the report of main, if there is one"
    )
    vex_parser = commands.add_parser(
        "vex",
        help="the OpenVEX document of a release from osv-scanner.toml, for the release "
        "bot; nothing when it accepts no advisory",
    )
    vex_parser.add_argument("repo", help="owner/name")
    vex_parser.add_argument("tag", help="the tag of the release")
    vex_parser.add_argument("timestamp", help="when the release is made, RFC 3339")
    vex_parser.add_argument("--file", default="osv-scanner.toml")
    notices_parser = commands.add_parser(
        "notices", help="the third-party components and their licenses, for releases"
    )
    notices_parser.add_argument("version", help="the tag of the release")
    notices_parser.add_argument("sbom", help="the SBOM of GitHub's dependency graph")
    update_parser = commands.add_parser(
        "update-commit",
        help="commit what copier update staged and keep its one pull request, for the "
        "blueprint bot",
    )
    update_parser.add_argument(
        "--app",
        required=True,
        help="the login of the release app, such as name[bot]; the branch of a pull "
        "request with commits of anyone else stays as it is",
    )
    update_parser.add_argument(
        "--automerge", default="on", help="off leaves the merge to the maintainer"
    )
    update_parser.add_argument(
        "--run-id",
        default="manual",
        help="names the branch that a commit without the modes of the files needs",
    )
    update_parser.add_argument(
        "--source",
        default=SOURCE,
        help="the blueprint on GitHub as OWNER/NAME, a fork perhaps, whose release the "
        "pull request links",
    )
    diagrams_parser = commands.add_parser(
        "diagrams",
        help="the ```mermaid blocks of the Markdown as sources in docs/diagrams/ and "
        "their pictures, for scripts/diagrams.sh",
    )
    diagrams_actions = diagrams_parser.add_subparsers(dest="action", required=True)
    diagrams_actions.add_parser(
        "extract", help="every ```mermaid block as a source and the <picture> of it"
    )
    keep_parser = diagrams_actions.add_parser(
        "keep",
        help="copy the sources, pictures and pages that scripts/diagrams.sh changed "
        "into a folder, for the diagram bot",
    )
    keep_parser.add_argument("folder", type=Path)
    commit_parser = diagrams_actions.add_parser(
        "commit",
        help="the request to GitHub's GraphQL API that commits what a folder of keep "
        "holds onto a branch of --repo, once it is checked",
    )
    commit_parser.add_argument("folder", type=Path)
    commit_parser.add_argument("--branch", required=True)
    commit_parser.add_argument(
        "--head", required=True, help="the commit that the branch must still be at"
    )
    commit_parser.add_argument("--headline", required=True)
    commit_parser.add_argument("--body", default="")
    report_parser = diagrams_actions.add_parser(
        "report", help="what the diagram bot says about what a folder of keep holds"
    )
    report_parser.add_argument("folder", type=Path)
    report_parser.add_argument(
        "--for",
        dest="kind",
        choices=["comment", "pull-request"],
        default="comment",
        help="comment: on the pull request whose blocks became pictures; "
        "pull-request: the description of the bot's pull request for main",
    )
    arguments = parser.parse_args(argv)

    if arguments.command == "coverage":
        try:
            return coverage_command(arguments)
        except (OSError, ElementTree.ParseError) as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    if arguments.command == "diagrams":
        try:
            return diagrams_command(arguments, root)
        except (OSError, ValueError, subprocess.CalledProcessError) as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    if arguments.command in ("changelog", "unreleased", "beta", "vex", "notices"):
        try:
            return changelog_command(arguments)
        except OSError as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    api = api or Api()
    try:
        repo = arguments.repo or current_repo(api)
        if arguments.command == "update-commit":
            print(
                update_pull_request(
                    api,
                    repo,
                    root,
                    app=arguments.app,
                    automerge=arguments.automerge != "off",
                    run_id=arguments.run_id,
                    source=arguments.source,
                )
            )
            return 0
        settings = Settings.load(root / arguments.config)
        if arguments.command == "checklist":
            print(f"The set-up of {repo}:")
            steps = checklist(api, repo, settings, root)
            print(describe_steps(steps))
            return 0 if all(step.done is not False for step in steps) else 1
        drift = check(api, repo, settings)
        refused: list[str] = []
        if arguments.action == "apply" and drift:
            refused = apply(api, repo, settings, drift)
            drift = check(api, repo, settings)
        for reason in refused:
            print(f"blueprint.py: {reason}", file=sys.stderr)
        # The path as the documentation writes it, with slashes on Windows too.
        config = arguments.config.as_posix()
        if not drift:
            print(f"The settings of {repo} match {config}.")
            return 0
        print(f"The settings of {repo} differ from {config}:")
        print(describe(drift))
        if arguments.action == "check":
            print("python3 blueprint.py settings apply sets what the API can set.")
        return 2 if refused else 1
    except (GitHubError, OSError, tomllib.TOMLDecodeError, Outdated) as error:
        print(f"blueprint.py: {error}", file=sys.stderr)
        return 2
