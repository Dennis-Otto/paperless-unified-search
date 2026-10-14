"""The command line of blueprint.py."""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tomllib
from collections.abc import Sequence
from datetime import UTC, datetime
from pathlib import Path
from xml.etree import ElementTree

import blueprint
from blueprint.bot_health import renovate_report, sweep, watch_run
from blueprint.changelog import (
    FRAGMENTS,
    FragmentError,
    beta_notes,
    fragment_problems,
    read_fragments,
    release_changelog,
    release_notes,
    released_fragments,
)
from blueprint.checklist import checklist, describe_steps
from blueprint.coach import (
    PROJECT_CATALOG,
    Catalog,
    CatalogError,
    check_steps,
    link,
    missing,
    plain,
    required_checks,
    summary,
    unknown,
)
from blueprint.coach import annotation as coach_annotation
from blueprint.coach import report as coach_report
from blueprint.coverage_comment import Coverage, coverage_comment
from blueprint.diagrams import SOURCES, commit_request, extract, keep, listing, report
from blueprint.github import Api, GitHubError, current_repo
from blueprint.notices import third_party_notices
from blueprint.pages import Layout, annotation, command, fixed, layouts, pages
from blueprint.settings import CONFIG, ConfigError, Settings, apply, check, describe
from blueprint.update import SOURCE, Outdated, update_pull_request
from blueprint.vex import openvex


def changelog_command(arguments: argparse.Namespace) -> int:
    if arguments.command == "beta":
        text = Path(arguments.file).read_text(encoding="utf-8")
        fragments = read_fragments(Path(arguments.fragments))
        sys.stdout.write(beta_notes(arguments.version, text, fragments))
        return 0
    if arguments.command == "fragments":
        return fragments_command(arguments)
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
    if arguments.action == "notes":
        sys.stdout.write(release_notes(arguments.version, *read))
        return 0
    main, branch = read
    fragments = read_fragments(Path(arguments.fragments))
    sys.stdout.write(release_changelog(arguments.version, main, branch, fragments))
    if arguments.taken:
        taken = released_fragments(arguments.version, branch, fragments)
        Path(arguments.taken).write_text(
            "".join(f"{path}\n" for path in taken), encoding="utf-8", newline="\n"
        )
    return 0


def fragments_command(arguments: argparse.Namespace) -> int:
    """Checks the fragments as the release takes them in: the problems on stdout, one
    on each line, for the check of a pull request to show."""
    folder = Path(arguments.folder)
    changelog = Path(arguments.file)
    text = changelog.read_text(encoding="utf-8") if changelog.is_file() else ""
    fragments = read_fragments(folder)
    problems = fragment_problems(fragments, text)
    for problem in problems:
        print(problem)
    if not problems:
        print(f"The {len(fragments)} fragment(s) in {folder.name}/ are fine.")
    return 1 if problems else 0


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


def explain_command(arguments: argparse.Namespace, root: Path) -> int:
    """What failed and how to fix it: in the log, and in the CI also in the summary
    of the job and as an annotation, from the variables that GitHub sets."""
    catalog = Catalog.load(root)
    if arguments.action == "verify":
        gaps = missing(catalog, required_checks(root), check_steps(root))
        for gap in gaps:
            print(
                f"blueprint.py: {gap} has no explanation; add it to "
                f"{PROJECT_CATALOG.as_posix()}",
                file=sys.stderr,
            )
        if gaps:
            return 1
        print("Every required check and every step of scripts/check.sh is explained.")
        return 0
    step = arguments.step
    entry = catalog.step(arguments.name) if step else catalog.check(arguments.name)
    if entry is None:
        text = unknown(arguments.name, step)
        sys.stdout.write(text)
    else:
        repo = os.environ.get("GITHUB_REPOSITORY") or None
        entry = entry.within(repo)
        sys.stdout.write(plain(entry, step))
        if os.environ.get("GITHUB_ACTIONS") == "true":
            print(coach_annotation(entry, step))
        url = link(
            entry.docs,
            repo,
            os.environ.get("GITHUB_SERVER_URL") or "https://github.com",
        )
        text = summary(entry, step, url)
    target = os.environ.get("GITHUB_STEP_SUMMARY")
    if target:
        with Path(target).open("a", encoding="utf-8") as file:
            file.write(text + "\n")
    return 0


def bot_health_command(
    api: Api, repo: str, arguments: argparse.Namespace, root: Path
) -> int:
    now = datetime.now(UTC)
    if arguments.action == "run":
        print(watch_run(api, repo, arguments.run_id, now))
        return 0
    if arguments.action == "sweep":
        summary, healthy = sweep(api, repo, root, now)
        print(summary, end="")
        return 0 if healthy else 1
    print(renovate_report(api, repo, Path(arguments.log), arguments.run), end="")
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


def pages_command(arguments: argparse.Namespace, root: Path) -> int:
    """The layouts of the pages that GitHub shows, one on each line, and in the CI also
    as annotations at their lines; with --fix, the pages in one column first, where
    there is a safe way to."""
    shown = pages(root)
    left: list[tuple[Path, Layout]] = []
    fixable = 0
    for page in shown:
        path = root / page
        text = path.read_text(encoding="utf-8")
        found = layouts(text)
        editable = sum(1 for layout in found if layout.edits)
        fixable += editable
        if arguments.fix and editable:
            text = fixed(text, found)
            path.write_text(text, encoding="utf-8", newline="\n")
            found = layouts(text)
            print(f"{page.as_posix()}: {editable} layout(s) in one column now")
        left += [(page, layout) for layout in found]
    if not left:
        print(f"The {len(shown)} page(s) that GitHub shows have one column.")
        return 0
    for page, layout in left:
        print(f"{page.as_posix()}:{layout.line}: {layout.message}")
    if os.environ.get("GITHUB_ACTIONS") == "true":
        for page, layout in left:
            print(annotation(page, layout))
    print(
        f"{len(left)} layout(s) set columns side by side, which the GitHub app and "
        "phones squeeze. A page has one column, so that it reads well everywhere: the "
        "text, and below it each picture at full width; a table is for tabular data, "
        "as CONTRIBUTING.md describes."
    )
    if arguments.fix:
        print(
            "These are left to you: a table inside another one, a table without its "
            "end, or one with content outside of its cells or on its lines."
        )
    elif fixable:
        print(
            f"{command(root)} pages --fix puts {fixable} of them in one column: the "
            "cells of a layout table one below the other, the text before its picture, "
            "and cells without their width."
        )
    return 1


def utf8_output() -> None:
    """Windows consoles default to a code page without the marks of the checklist, and
    to CRLF, which a file of the repository written from the output must not get."""
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure is not None:
            reconfigure(encoding="utf-8", newline="\n")


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
    changelog_parser.add_argument(
        "--fragments",
        default=FRAGMENTS,
        help="release: the folder of the fragments, by default %(default)s",
    )
    changelog_parser.add_argument(
        "--taken",
        help="release: the file to write the paths of the fragments into that the "
        "release takes in, one on each line, for the release bot to delete",
    )
    beta_parser = commands.add_parser(
        "beta", help="the notes of a beta of the next release, for the release bot"
    )
    beta_parser.add_argument(
        "version", help="the version of the beta, such as 1.3.0-beta.2"
    )
    beta_parser.add_argument("--file", default="CHANGELOG.md")
    beta_parser.add_argument("--fragments", default=FRAGMENTS)
    fragments_parser = commands.add_parser(
        "fragments",
        help="check the fragments of the changelog as a release takes them in, for "
        "the changelog check",
    )
    fragments_parser.add_argument(
        "--folder", default=FRAGMENTS, help="by default %(default)s"
    )
    fragments_parser.add_argument(
        "--file",
        default="CHANGELOG.md",
        help="the changelog, whose headings a fragment may use too",
    )
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
    coach_parser = commands.add_parser(
        "coach",
        help="the PR coach: how to fix a check that failed, in its summary and in one "
        "comment on the pull request",
    )
    coach_actions = coach_parser.add_subparsers(dest="action", required=True)
    explain_parser = coach_actions.add_parser(
        "explain",
        help="what a check or a step of scripts/check.sh found and how to fix it; in "
        "the CI also in the summary and as an annotation of the job",
    )
    explain_parser.add_argument(
        "name", help="the name of the check, such as markdown, or of the step"
    )
    explain_parser.add_argument(
        "--step", action="store_true", help="the name is a step of scripts/check.sh"
    )
    coach_report_parser = coach_actions.add_parser(
        "report",
        help="keep the one comment of the PR coach on the pull request of a commit up "
        "to date, for the bots after the checks",
    )
    coach_report_parser.add_argument("sha", help="the head of the pull request")
    coach_report_parser.add_argument(
        "--pull", default="", help="its number, when the run of the checks names it"
    )
    coach_actions.add_parser(
        "verify",
        help="whether every required check and every step of scripts/check.sh has "
        "its explanation",
    )
    health_parser = commands.add_parser(
        "bot-health",
        help="one issue for each bot that fails on the default branch, for the "
        "bot-health bot",
    )
    health = health_parser.add_subparsers(dest="action", required=True)
    run_parser = health.add_parser(
        "run",
        help="the end of a run of a watched workflow: run its failed jobs once more, "
        "or keep its issue current",
    )
    run_parser.add_argument("run_id", type=int, metavar="RUN", help="the id of the run")
    health.add_parser(
        "sweep",
        help="the latest run of every workflow, and the workflows whose schedule "
        "GitHub missed, once a day",
    )
    renovate_parser = health.add_parser(
        "renovate", help="the warnings and errors of a run of Renovate, in one issue"
    )
    renovate_parser.add_argument("log", help="the log of Renovate in JSON")
    renovate_parser.add_argument(
        "--run", required=True, help="the link of the run of Renovate"
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
    pages_parser = commands.add_parser(
        "pages",
        help="the READMEs, CONTRIBUTING files and pages of docs/ in one column, for "
        "the GitHub app and phones: no table that sets a picture beside text, no cell "
        "with a width",
    )
    pages_parser.add_argument(
        "--fix",
        action="store_true",
        help="put the cells of a layout table one below the other, the text before "
        "its picture, and leave out the widths of cells, first",
    )
    arguments = parser.parse_args(argv)

    if arguments.command == "coverage":
        try:
            return coverage_command(arguments)
        except (OSError, ElementTree.ParseError) as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    if arguments.command == "coach" and arguments.action != "report":
        try:
            return explain_command(arguments, root)
        except (OSError, CatalogError) as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    if arguments.command == "coach" and not (
        re.fullmatch(r"[0-9a-f]{40}", arguments.sha)
        and re.fullmatch(r"[0-9]*", arguments.pull)
    ):
        print(
            "blueprint.py: a commit is 40 hex digits, a pull request a number",
            file=sys.stderr,
        )
        return 2
    if arguments.command == "diagrams":
        try:
            return diagrams_command(arguments, root)
        except (OSError, ValueError, subprocess.CalledProcessError) as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    if arguments.command == "pages":
        try:
            return pages_command(arguments, root)
        except (OSError, UnicodeDecodeError, subprocess.CalledProcessError) as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    if arguments.command in (
        "changelog",
        "beta",
        "fragments",
        "vex",
        "notices",
    ):
        try:
            return changelog_command(arguments)
        except FragmentError as error:
            for problem in error.problems:
                print(f"blueprint.py: {problem}", file=sys.stderr)
            return 1
        except OSError as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    api = api or Api()
    try:
        repo = arguments.repo or current_repo(api)
        if arguments.command == "coach":
            number = int(arguments.pull) if arguments.pull else None
            catalog = Catalog.load(root)
            sys.stdout.write(coach_report(api, repo, arguments.sha, number, catalog))
            return 0
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
        if arguments.command == "bot-health":
            return bot_health_command(api, repo, arguments, root)
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
    except (
        GitHubError,
        OSError,
        tomllib.TOMLDecodeError,
        ConfigError,
        Outdated,
        CatalogError,
    ) as error:
        print(f"blueprint.py: {error}", file=sys.stderr)
        return 2
