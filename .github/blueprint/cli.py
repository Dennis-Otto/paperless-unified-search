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
from blueprint import mutation, screenshots
from blueprint.bootstrap import CreateError, create
from blueprint.bot_health import renovate_report, sweep, watch_run
from blueprint.branches import next_branch
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
    Part,
    check_steps,
    link,
    missing,
    plain,
    required_checks,
    summary,
    undeclared,
    unknown,
)
from blueprint.coach import annotation as coach_annotation
from blueprint.coach import report as coach_report
from blueprint.coverage_comment import Coverage, coverage_comment
from blueprint.diagrams import (
    SOURCES,
    commit_request,
    extract,
    follow,
    keep,
    listing,
    report,
)
from blueprint.fixes import (
    Fixes,
    FixLoop,
    collect,
    commit_onto_branch,
    downloader,
    fix_text,
    fixers,
    heal_main,
    load,
    plan,
    report_kept,
    run_until_done,
    suggest,
)
from blueprint.fixes import keep as keep_fixes
from blueprint.github import Api, GitHubError, Json, current_repo
from blueprint.notices import third_party_notices
from blueprint.pages import Layout, annotation, command, fixed, layouts, pages
from blueprint.pr_report import about as pr_parts
from blueprint.settings import CONFIG, ConfigError, Settings, apply, check, describe
from blueprint.site_check import annotation as site_annotation
from blueprint.site_check import check as check_site
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
        document = openvex(
            arguments.repo, arguments.tag, arguments.timestamp, text, arguments.version
        )
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
    head = Coverage.read(Path(arguments.head), root)
    base_file = Path(arguments.base)
    base = Coverage.read(base_file, root) if base_file.is_file() else None
    sys.stdout.write(coverage_comment(head, base))
    return 0


def read_coverage(head: str, base: str) -> tuple[Coverage, Coverage | None] | None:
    """The coverage reports of a pull request and of main for its report, none when
    the tests kept none. A pull request from a fork writes its report as it likes, and
    one that can't be read leaves the coverage out of the report."""
    root = Path.cwd()
    try:
        found = Coverage.read(Path(head), root)
        base_file = Path(base)
        before = (
            Coverage.read(base_file, root) if base and base_file.is_file() else None
        )
    except (OSError, ElementTree.ParseError, ValueError):
        return None
    return found, before


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
        silent = undeclared(catalog)
        for gap in silent:
            print(
                f"blueprint.py: the explanation of {gap} has no autofix: the commands "
                "that fix what it finds, or the reason why no command can",
                file=sys.stderr,
            )
        if gaps or silent:
            return 1
        print(
            "Every required check and every step of scripts/check.sh is explained, "
            "and every explanation says how it fixes itself or why it can't."
        )
        return 0
    # A check that runs several others, as lint runs every linter, explains each of
    # them that failed, and itself only when none did.
    if not arguments.parts:
        explain(catalog, arguments.name, arguments.step)
    for part in arguments.parts:
        explain(catalog, part, arguments.step, within=arguments.name)
    return 0


def explain(catalog: Catalog, name: str, step: bool, within: str = "") -> None:
    """What a check or a step found and how to fix it: in the log, and in the CI also
    in the summary of the job and as its annotation; within names the check whose job
    ran it, if another one."""
    entry = catalog.step(name) if step else catalog.check(name)
    if entry is None:
        text = unknown(name, step)
        sys.stdout.write(text)
    else:
        repo = os.environ.get("GITHUB_REPOSITORY") or None
        entry = entry.within(repo)
        sys.stdout.write(plain(entry, step, within))
        if os.environ.get("GITHUB_ACTIONS") == "true":
            print(coach_annotation(entry, step, within))
        url = link(
            entry.docs,
            repo,
            os.environ.get("GITHUB_SERVER_URL") or "https://github.com",
        )
        text = summary(entry, step, url, within)
    target = os.environ.get("GITHUB_STEP_SUMMARY")
    if target:
        with Path(target).open("a", encoding="utf-8") as file:
            file.write(text + "\n")


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


def show_mutant(name: str) -> str:
    """The change of a mutant, as mutmut show prints it: mutmut of this Python, which
    the step installed, without the folder of the project ahead of its modules."""
    return subprocess.run(
        [sys.executable, "-X", "utf8", "-P", "-m", "mutmut", "show", name],
        capture_output=True,
        encoding="utf-8",
        errors="replace",
        check=False,
    ).stdout


def read_output(path: Path) -> str:
    """What a tool printed, with the carriage returns of its spinner."""
    if not path.is_file():
        return ""
    with path.open(encoding="utf-8", errors="replace", newline="") as file:
        return file.read()


def parts_count(text: str) -> int | None:
    """The number of parts of a run: auto, or none, for the estimate."""
    if text in ("", "auto"):
        return None
    if not text.isdigit() or int(text) < 1:
        raise ValueError(f"the parts of a run are a number or auto, not {text!r}")
    return int(text)


def mutation_command(
    arguments: argparse.Namespace,
    root: Path,
    api: Api | None,
) -> int:
    if arguments.action == "plan":
        kind, parts = mutation.plan(root, parts_count(arguments.parts))
        print(f"kind={kind}")
        print(f"parts={json.dumps(parts, ensure_ascii=False)}")
        for part in parts:
            print(
                f"Part {part['part']} of {part['parts']}: about {part['mutants']} "
                "mutants",
                file=sys.stderr,
            )
        return 0
    if arguments.action == "collect":
        number, _, total = arguments.part.partition("/")
        output = Path(arguments.output)
        part = mutation.collect(
            Path(arguments.results).read_text(encoding="utf-8", errors="replace"),
            read_output(output),
            arguments.patterns.split(),
            arguments.status,
            (int(number), int(total or 1)),
            show_mutant,
            os.cpu_count() or 4,
        )
        Path(arguments.out).write_text(
            json.dumps(part, ensure_ascii=False, indent=1) + "\n", encoding="utf-8"
        )
        silent = mutation.Report.of([part]).silent
        if silent:
            print(mutation.no_mutant_ran(part))
        return 1 if silent else 0
    if arguments.action == "issue":
        folder = Path(arguments.folder)
        now = mutation.read_previous(folder / "mutation.json")
        if now is None:
            print(f"No mutation.json in {folder}: the issue stays as it is.")
            return 1
        previous = mutation.read_previous(folder / "previous.json")
        api = api or Api()
        repo = arguments.repo or current_repo(api)
        print(mutation.keep_issue(api, repo, now, previous))
        return 0
    report = mutation.Report.of(mutation.read_parts(Path(arguments.parts_folder)))
    previous = mutation.baseline_of(mutation.read_previous(arguments.previous))
    now = report.data(arguments.run, datetime.now(UTC))
    # An incomplete run keeps the last complete one, so that the next run compares
    # with that, and a drop past an incomplete run still shows.
    if not now["complete"] and previous is not None:
        now["baseline"] = previous
    out = Path(arguments.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "mutation.json").write_text(
        json.dumps(now, ensure_ascii=False, indent=1) + "\n", encoding="utf-8"
    )
    # What the run compared with, for the job that keeps the issue of a drop.
    if previous is not None:
        (out / "previous.json").write_text(
            json.dumps(previous, ensure_ascii=False, indent=1) + "\n", encoding="utf-8"
        )
    (out / "results.txt").write_text(report.results_text(), encoding="utf-8")
    (out / "survivors.md").write_text(mutation.survivors_text(report), encoding="utf-8")
    sys.stdout.write(mutation.summary(report, now, previous))
    return 0 if report.measured and not report.silent and not report.missing else 1


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
    if arguments.action == "check":
        stale = [found for found in follow(root, write=False) if found.line]
        for found in stale:
            print(
                f"{found.path.as_posix()}:{found.line}: the alt of the picture of "
                f"{found.source.as_posix()} isn't its accTitle and accDescr; bash "
                "scripts/diagrams.sh --extract writes them into it"
            )
        return 1 if stale else 0
    for found in follow(root):
        if found.line:
            print(
                f"{found.path.as_posix()}:{found.line}: the alt of the picture repeats "
                f"{found.source.as_posix()} again"
            )
        else:
            print(
                f"{found.path.as_posix()}: its labels lost their HTML, which the "
                "pictures show as text"
            )
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


def screenshots_command(arguments: argparse.Namespace, root: Path) -> int:
    if arguments.action == "due":
        ready, reason = screenshots.due(
            root, arguments.since, arguments.until, arguments.paths, arguments.script
        )
        print(reason)
        return 0 if ready else 1
    if arguments.action in ("keep", "restore"):
        folder = arguments.folder if arguments.action == "keep" else None
        try:
            found = screenshots.keep(root, folder)
        except ModuleNotFoundError as error:
            # Pillow compares the pixels; a project's own tools may not have it.
            wanted = ".github/screenshots-requirements.txt"
            if not (root / wanted).is_file():
                wanted = "stacks/screenshots/requirements.txt"
            print(
                f"blueprint.py: screenshots {arguments.action} needs {error.name}:"
                f" python3 -m pip install --require-hashes -r {wanted}",
                file=sys.stderr,
            )
            return 2
        print(screenshots.summary(found), end="")
        return 0
    if arguments.action == "parts":
        print(len(screenshots.parts(arguments.folder)))
        return 0
    if arguments.action == "commit":
        if not arguments.repo:
            print("blueprint.py: screenshots commit needs --repo", file=sys.stderr)
            return 2
        request = screenshots.commit_request(
            arguments.folder,
            arguments.repo,
            arguments.branch,
            arguments.head,
            arguments.headline,
            arguments.body,
            arguments.part,
        )
        print(json.dumps(request))
        return 0
    text = screenshots.report(
        arguments.folder, arguments.sha, arguments.run, arguments.script
    )
    print(text, end="")
    return 0


def fix_command(arguments: argparse.Namespace, root: Path) -> int:
    """The fixes that a command makes, on your machine and in the check that failed."""
    if arguments.action == "text":
        fixed = fix_text(root)
        for path in fixed:
            print(
                f"{path}: LF line endings, no trailing whitespace, a line break at its end"
            )
        print(
            f"{len(fixed)} text file(s) fixed."
            if fixed
            else "Every text file is clean."
        )
        return 0
    if arguments.action == "keep":
        print("\n".join(report_kept(keep_fixes(root, arguments.folder), kept=True)))
        return 0
    catalog = Catalog.load(root)
    commands = fixers(catalog, root, arguments.check)
    what = arguments.check or "the steps of scripts/check.sh"
    if not commands:
        print(f"No command of the explanations fixes what {what} found.")
        return 0
    lines = [
        "### Fixed by a command",
        "",
        f"The commands that fix what {what} found, from `.github/checks.toml`:",
        "",
        *run_until_done(commands, root),
        "",
    ]
    if arguments.keep is None:
        lines.append("`git diff` shows what they changed; commit it.")
    else:
        lines += report_kept(keep_fixes(root, arguments.keep), kept=True)
    print("\n".join(lines))
    return 0


def kept_fixes(folder: Path) -> Fixes:
    """The fixes that fix collect downloaded into folder, one folder for each check."""
    if not folder.is_dir():
        return Fixes({})
    return load(sorted(path for path in folder.iterdir() if path.is_dir()))


def fix_bot_command(api: Api, repo: str, arguments: argparse.Namespace) -> int:
    """The fix bot after the checks: collect the fixes of a commit and decide, then
    commit them, suggest them or keep the pull request of main."""
    if arguments.action == "collect":
        folders = collect(
            api,
            repo,
            arguments.run,
            arguments.sha,
            arguments.event,
            arguments.folder,
            downloader(api, repo),
        )
        fixes = load(folders)
        number = int(arguments.pull) if arguments.pull else None
        try:
            decided = plan(
                api,
                repo,
                arguments.sha,
                arguments.event,
                arguments.branch,
                number,
                fixes,
            )
        except FixLoop as error:
            # The annotation in the log, the reason in the summary as well.
            print(f"::error::{error}", file=sys.stderr)
            print(error)
            return 1
        if arguments.output:
            with Path(arguments.output).open("a", encoding="utf-8") as file:
                file.write(f"action={decided.action}\n")
                file.write(f"pull={decided.number or ''}\n")
        sys.stdout.write(decided.text)
        return 0
    fixes = kept_fixes(arguments.folder)
    if arguments.action == "commit":
        said = commit_onto_branch(
            api,
            repo,
            arguments.branch,
            arguments.sha,
            fixes,
            arguments.app,
            arguments.url,
        )
    elif arguments.action == "suggest":
        said = suggest(api, repo, int(arguments.pull), arguments.sha, fixes)
    else:
        said = heal_main(
            api,
            repo,
            arguments.sha,
            arguments.base,
            fixes,
            arguments.app,
            arguments.url,
        )
    sys.stdout.write(said)
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


def site_check_command(arguments: argparse.Namespace, root: Path) -> int:
    """What the website that MkDocs built promises, one broken promise on each line,
    and in the CI also as annotations; with --output, the further languages and the
    path of the address for the steps that follow."""
    folder = Path(arguments.site).as_posix().rstrip("/") or "."
    site = check_site(root / arguments.site)
    if arguments.output:
        with Path(arguments.output).open("a", encoding="utf-8") as file:
            file.write(f"languages={' '.join(site.further)}\n")
            file.write(f"root={site.root}\n")
    if not site.findings:
        languages = " ".join((site.default, *site.further)).strip() or "one language"
        print(f"The website in {folder} keeps what its hooks promise ({languages}).")
        return 0
    for finding in site.findings:
        print(f"{folder}/{finding.path}: {finding.message}")
    if os.environ.get("GITHUB_ACTIONS") == "true":
        for finding in site.findings:
            print(site_annotation(finding, folder))
    print(
        f"{len(site.findings)} file(s) of the website break what the hooks of "
        ".github/mkdocs_blueprint.py promise. A new version of Material or of "
        "mkdocs-static-i18n that writes its pages differently, or a hook of the "
        "project, changed them: build the website, compare the files named above "
        "with what the hooks write, and report it to the blueprint if its hooks "
        "need to change."
    )
    return 1


def settings_command(
    api: Api, repo: str, settings: Settings, action: str, path: Path
) -> int:
    """settings check or apply: 0 when GitHub matches the settings, 1 when it differs,
    2 when GitHub refused a part of them."""
    drift = check(api, repo, settings)
    refused: list[str] = []
    if action == "apply" and drift:
        refused = apply(api, repo, settings, drift)
        drift = check(api, repo, settings)
    for reason in refused:
        print(f"blueprint.py: {reason}", file=sys.stderr)
    # The path as the documentation writes it, with slashes on Windows too.
    config = path.as_posix()
    if not drift:
        print(f"The settings of {repo} match {config}.")
        return 0
    print(f"The settings of {repo} differ from {config}:")
    print(describe(drift))
    if action == "check":
        print("python3 blueprint.py settings apply sets what the API can set.")
    return 2 if refused else 1


def checklist_command(api: Api, repo: str, settings: Settings, root: Path) -> int:
    print(f"The set-up of {repo}:")
    steps = checklist(api, repo, settings, root)
    print(describe_steps(steps))
    return 0 if all(step.done is not False for step in steps) else 1


def create_command(api: Api, arguments: argparse.Namespace, root: Path) -> int:
    """blueprint.py create: the repository on GitHub of a new copy, then its settings
    and its checklist. The settings load first, so that a configuration that can't be
    right stops it before anything is made. What only a person can do, such as the
    secrets, the checklist names, so only a part of the settings that GitHub refused
    fails it."""
    settings = Settings.load(root / arguments.config, root)
    repo = create(
        root,
        str(settings.repository.get("description", "")),
        repo=arguments.repo,
        private=arguments.private,
        dry_run=arguments.dry_run,
    )
    if repo is None:
        return 0
    applied = settings_command(api, repo, settings, "apply", arguments.config)
    checklist_command(api, repo, settings, root)
    return 2 if applied == 2 else 0


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
    create_parser = commands.add_parser(
        "create",
        help="the repository on GitHub of a new copy: git init, the first commit, "
        "gh repo create, the settings and the checklist",
    )
    create_parser.add_argument(
        "--private", action="store_true", help="a private repository; public by default"
    )
    create_parser.add_argument(
        "--dry-run", action="store_true", help="print the commands and run none"
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
        help="the comment of the coverage bot: a Cobertura or LCOV report against that "
        "of main",
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
    vex_parser.add_argument(
        "--version",
        help="the version of the release, which its SBOMs name; the tag without its v "
        "by default",
    )
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
    next_branch_parser = commands.add_parser(
        "next-branch",
        help="bring the next pull request that waits for auto-merge up to date, one "
        "at a time, for the branch bot",
    )
    next_branch_parser.add_argument(
        "--warnings-only",
        action="store_true",
        help="pass even when GitHub refused a request, with the warnings alone, as "
        "in a run that shows as a check of a pull request",
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
    explain_parser.add_argument(
        "--parts",
        nargs="*",
        default=[],
        metavar="PART",
        help="the checks that the job of the check runs and that failed, such as "
        "markdown in lint; the coach explains each of them instead of the check",
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
    coach_report_parser.add_argument(
        "--coverage", default="", help="the coverage report of its tests, if any"
    )
    coach_report_parser.add_argument(
        "--coverage-base", default="", help="the coverage report of main, if any"
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
        "extract",
        help="every ```mermaid block as a source and the <picture> of it, the labels "
        "of the sources without HTML, and the alt of each picture as its source says",
    )
    diagrams_actions.add_parser(
        "check",
        help="whether the alt of each picture repeats the accTitle and accDescr of "
        "its source, for scripts/diagrams.sh --check",
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
    screenshots_parser = commands.add_parser(
        "screenshots",
        help="the pictures that scripts/screenshots.sh renders, renewed by the "
        "screenshot bot only when their pixels change",
    )
    screenshots_actions = screenshots_parser.add_subparsers(
        dest="action", required=True
    )
    due_parser = screenshots_actions.add_parser(
        "due",
        help="whether the bot renders: with the script, and after a change only when "
        "it touches a file of the list; exits 1 when not, and says why",
    )
    due_parser.add_argument(
        "--since", default="", help="the commit before the change; empty for always"
    )
    due_parser.add_argument("--until", default="HEAD", help="the commit of the change")
    due_parser.add_argument(
        "--paths",
        type=Path,
        default=screenshots.PATHS,
        help="the list of the files whose change can change the pictures",
    )
    due_parser.add_argument(
        "--script",
        type=Path,
        default=screenshots.SCRIPT,
        help="the script that renders",
    )
    screenshots_actions.add_parser(
        "restore",
        help="give each picture whose pixels the script didn't change its committed "
        "file back, and list the others",
    )
    shots_keep_parser = screenshots_actions.add_parser(
        "keep",
        help="restore, then copy the pictures that look different into a folder, "
        "for the screenshot bot",
    )
    shots_keep_parser.add_argument("folder", type=Path)
    parts_parser = screenshots_actions.add_parser(
        "parts", help="how many commits carry the pictures of a folder of keep"
    )
    parts_parser.add_argument("folder", type=Path)
    shots_commit_parser = screenshots_actions.add_parser(
        "commit",
        help="the request to GitHub's GraphQL API that commits a part of what a "
        "folder of keep holds onto a branch of --repo, once it is checked",
    )
    shots_commit_parser.add_argument("folder", type=Path)
    shots_commit_parser.add_argument("--branch", required=True)
    shots_commit_parser.add_argument(
        "--head", required=True, help="the commit that the branch must still be at"
    )
    shots_commit_parser.add_argument("--headline", required=True)
    shots_commit_parser.add_argument("--body", default="")
    shots_commit_parser.add_argument(
        "--part", type=int, default=1, help="which of the parts, from 1"
    )
    shots_report_parser = screenshots_actions.add_parser(
        "report", help="the description of the bot's pull request"
    )
    shots_report_parser.add_argument("folder", type=Path)
    shots_report_parser.add_argument(
        "--sha", required=True, help="the commit that was rendered"
    )
    shots_report_parser.add_argument(
        "--run", required=True, help="the link of the run of the workflow"
    )
    shots_report_parser.add_argument(
        "--script",
        type=Path,
        default=screenshots.SCRIPT,
        help="the script that rendered",
    )
    fix_parser = commands.add_parser(
        "fix",
        help="the fix bot: the commands that fix what a check found, and the commits "
        "and suggestions of their fixes after the checks",
    )
    fix_actions = fix_parser.add_subparsers(dest="action", required=True)
    fix_run_parser = fix_actions.add_parser(
        "run",
        help="run the commands of autofix that fix what a check found, by default "
        "those of each step of scripts/check.sh",
    )
    fix_run_parser.add_argument(
        "check", nargs="?", help="the name of the check that failed, such as markdown"
    )
    fix_run_parser.add_argument(
        "--keep",
        type=Path,
        help="a folder to copy the files that they changed into, for the fix bot",
    )
    fix_keep_parser = fix_actions.add_parser(
        "keep",
        help="copy the files of the commit that a fix changed into a folder, for the "
        "fix bot",
    )
    fix_keep_parser.add_argument("folder", type=Path)
    fix_actions.add_parser(
        "text",
        help="give every text file LF line endings, no trailing whitespace and a line "
        "break at its end",
    )
    collect_parser = fix_actions.add_parser(
        "collect",
        help="download the fixes of the checks of a commit once they ended, and "
        "decide what the fix bot does with them",
    )
    collect_parser.add_argument("run", type=int, help="the run that ended")
    commit_fixes_parser = fix_actions.add_parser(
        "commit",
        help="commit the fixes onto the branch of a pull request, as the release app",
    )
    suggest_parser = fix_actions.add_parser(
        "suggest",
        help="suggest the fixes at their lines of a pull request from a fork",
    )
    main_parser = fix_actions.add_parser(
        "main",
        help="keep the one pull request from bot/self-heal with the fixes of main",
    )
    for bot_parser in (
        collect_parser,
        commit_fixes_parser,
        suggest_parser,
        main_parser,
    ):
        bot_parser.add_argument("--sha", required=True, help="the commit of the checks")
        bot_parser.add_argument(
            "--folder", type=Path, required=True, help="the fixes, one folder per check"
        )
    collect_parser.add_argument(
        "--event", required=True, choices=["pull_request", "push"]
    )
    collect_parser.add_argument("--branch", required=True, help="the branch of the run")
    collect_parser.add_argument("--pull", default="", help="the pull request, if named")
    collect_parser.add_argument(
        "--output", help="the file of the outputs of the step, $GITHUB_OUTPUT"
    )
    commit_fixes_parser.add_argument("--branch", required=True)
    suggest_parser.add_argument("--pull", required=True)
    main_parser.add_argument("--base", required=True, help="the default branch")
    for bot_parser in (commit_fixes_parser, main_parser):
        bot_parser.add_argument(
            "--app", required=True, help="the slug of the release app, which commits"
        )
        bot_parser.add_argument("--url", required=True, help="the run of the checks")
    mutation_parser = commands.add_parser(
        "mutation",
        help="the parts of a run of the mutation tests, the results of each and the "
        "report of the run, for the Mutation workflow",
    )
    mutation_actions = mutation_parser.add_subparsers(dest="action", required=True)
    plan_parser = mutation_actions.add_parser(
        "plan",
        help="the tool and the parts of the run, as lines for $GITHUB_OUTPUT",
    )
    plan_parser.add_argument(
        "--parts",
        default="auto",
        help="the number of parts, or auto for the estimate of the mutants",
    )
    collect_mutants_parser = mutation_actions.add_parser(
        "collect",
        help="the results of one part, with the change and the kind of each survivor",
    )
    collect_mutants_parser.add_argument("results", help="what mutmut results printed")
    collect_mutants_parser.add_argument("output", help="what mutmut run printed")
    collect_mutants_parser.add_argument(
        "--part", required=True, help="the part and the parts, such as 1/3"
    )
    collect_mutants_parser.add_argument(
        "--patterns", default="", help="the names of the mutants of the part"
    )
    collect_mutants_parser.add_argument(
        "--status", type=int, default=0, help="the exit code of mutmut run"
    )
    collect_mutants_parser.add_argument(
        "--out", required=True, help="the results of the part, in JSON"
    )
    mutation_report_parser = mutation_actions.add_parser(
        "report",
        help="the score of the run and its survivors, the logic first, and the issue "
        "when the score drops",
    )
    mutation_report_parser.add_argument(
        "parts_folder", metavar="PARTS", help="the folder with the results of the parts"
    )
    mutation_report_parser.add_argument(
        "--out", required=True, help="the folder for the artifact of the run"
    )
    mutation_report_parser.add_argument(
        "--previous", type=Path, help="mutation.json of the run before"
    )
    mutation_report_parser.add_argument("--run", default="", help="the link of the run")
    mutation_issue_parser = mutation_actions.add_parser(
        "issue",
        help="open, keep or close the one issue of a dropped score, from the report",
    )
    mutation_issue_parser.add_argument(
        "folder",
        help="the folder of the report, with mutation.json and previous.json",
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
    site_check_parser = commands.add_parser(
        "site-check",
        help="what the website that MkDocs built promises: a sitemap in every folder "
        "of pages, the 404 page in the default language, one canonical address and "
        "full addresses of the other languages on every page",
    )
    site_check_parser.add_argument(
        "site", help="the folder that MkDocs built the website into, such as site"
    )
    site_check_parser.add_argument(
        "--output",
        help="the file of the outputs of the step, $GITHUB_OUTPUT: the further "
        "languages and the path of the address",
    )
    arguments = parser.parse_args(argv)

    if arguments.command == "coverage":
        # A report that isn't there, isn't XML or isn't text of UTF-8.
        try:
            return coverage_command(arguments)
        except (OSError, ElementTree.ParseError, ValueError) as error:
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
    if arguments.command == "screenshots":
        try:
            return screenshots_command(arguments, root)
        except (OSError, ValueError, subprocess.CalledProcessError) as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    if arguments.command == "fix" and arguments.action in ("run", "keep", "text"):
        try:
            return fix_command(arguments, root)
        except (OSError, CatalogError, subprocess.CalledProcessError) as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    if arguments.command == "fix" and not (
        re.fullmatch(r"[0-9a-f]{40}", arguments.sha)
        and re.fullmatch(r"[0-9]*", getattr(arguments, "pull", ""))
    ):
        print(
            "blueprint.py: a commit is 40 hex digits, a pull request a number",
            file=sys.stderr,
        )
        return 2
    if arguments.command == "mutation":
        try:
            return mutation_command(arguments, root, api)
        except (OSError, ValueError, GitHubError) as error:
            print(f"blueprint.py: {error}", file=sys.stderr)
            return 2
    if arguments.command == "site-check":
        try:
            return site_check_command(arguments, root)
        except OSError as error:
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
        if arguments.command == "create":
            return create_command(api, arguments, root)
        repo = arguments.repo or current_repo(api)
        if arguments.command == "coach":
            number = int(arguments.pull) if arguments.pull else None
            catalog = Catalog.load(root)
            coverage = (
                read_coverage(arguments.coverage, arguments.coverage_base)
                if arguments.coverage
                else None
            )
            client = api

            def parts(pull: Json) -> list[Part]:
                return pr_parts(client, repo, pull, root, coverage)

            sys.stdout.write(
                coach_report(api, repo, arguments.sha, number, catalog, about=parts)
            )
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
        if arguments.command == "next-branch":
            outcome = next_branch(api, repo)
            for warning in outcome.warnings:
                print(warning, file=sys.stderr)
            print(outcome.summary, end="")
            return 1 if outcome.warnings and not arguments.warnings_only else 0
        if arguments.command == "fix":
            return fix_bot_command(api, repo, arguments)
        settings = Settings.load(root / arguments.config, root)
        if arguments.command == "checklist":
            return checklist_command(api, repo, settings, root)
        return settings_command(api, repo, settings, arguments.action, arguments.config)
    except (
        GitHubError,
        OSError,
        tomllib.TOMLDecodeError,
        ConfigError,
        Outdated,
        CatalogError,
        CreateError,
    ) as error:
        print(f"blueprint.py: {error}", file=sys.stderr)
        return 2
