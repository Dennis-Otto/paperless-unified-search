"""The PR coach: a check that fails says what it found, why that matters and how to fix
it, in the summary of the check and in one comment on the pull request, also on a pull
request from a fork.

The explanations are tables of .github/checks.toml, which the blueprint keeps, and of
.github/checks.project.toml, which belongs to the project: one for each check, by the
name that GitHub shows, and one for each step of scripts/check.sh. A job that fails runs
`blueprint.py coach explain`, and scripts/check.sh does so for the step that stopped
it. After the checks of a pull request, `blueprint.py coach report` reads its check runs
from the API and keeps the one comment up to date. It runs no code of the pull request,
and shows its text, such as the messages of the annotations, only as code.
"""

from __future__ import annotations

import fnmatch
import html
import re
import textwrap
import time
import tomllib
from collections.abc import Callable, Iterable, Iterator, Sequence
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Any

from blueprint.github import Api, Json

CATALOG = Path(".github/checks.toml")
PROJECT_CATALOG = Path(".github/checks.project.toml")
MARKER = "<!-- pr-coach -->"
# Each annotation of the coach has a title with this prefix; that of a step of
# scripts/check.sh ends with the suffix, by which the report finds the step.
PREFIX = "PR coach: "
STEP_SUFFIX = " (scripts/check.sh)"
# The account of the token of the workflows, which writes the comment; a comment of
# anyone else stays as it is, even with the marker.
BOT = "github-actions[bot]"
# The markers of the comments of retired bots whose place the report took, such as
# that of the coverage bot: the coach deletes them on every pull request, also on one
# that gets no report, as no job updates them any more.
RETIRED = ("<!-- coverage-bot -->",)
# The check of the title of a pull request; its section suggests a title that passes.
TITLE_CHECK = "conventional title"
# The first line of each commit of the fix bot, by which the report names its fixes.
FIX_HEADLINE = "style: apply the automatic fixes"
TYPES = (
    "feat",
    "fix",
    "docs",
    "perf",
    "refactor",
    "test",
    "ci",
    "build",
    "chore",
    "style",
    "revert",
)
# The words that people use for a type instead of its name.
ALIASES = {
    "feature": "feat",
    "features": "feat",
    "add": "feat",
    "new": "feat",
    "bugfix": "fix",
    "bug": "fix",
    "hotfix": "fix",
    "doc": "docs",
    "documentation": "docs",
    "tests": "test",
    "chores": "chore",
    "deps": "build",
    "dependencies": "build",
}
# The types that stand for themselves without a colon, as in "fix the crash": a title
# that starts with one of them names its type. Others, such as add or test, may be the
# first word of a sentence.
BARE = frozenset(
    {"feat", "fix", "docs", "perf", "refactor", "ci", "chore", "feature", "bugfix"}
)
# Those of them that may also be a noun of the sentence, as in "Feature flags for the
# API": with no colon, brackets, scope or "!", it names the type and stays in place.
NOUNS = frozenset({"docs", "perf", "ci", "feature", "bugfix"})
FAILED = frozenset({"failure", "timed_out", "startup_failure"})
# A check that ended before it finished, such as a job that ran out of time, which
# GitHub reports as cancelled, or one that waits for a person: it hasn't passed.
STOPPED = frozenset({"cancelled", "action_required", "stale"})
# The checks that run scripts/check.sh of this repository, whose step explains them.
OWN_CHECKS = "check (*)"
# The app of the jobs of the workflows, which start the coach when they end; the check
# runs of other apps, such as the alerts of code scanning, may end after the last one.
ACTIONS = "github-actions"
WAIT = 20
WAITS = 9
# What a command of an explanation writes for the repository, as OWNER/NAME.
REPO = "{repo}"
# The annotation that GitHub adds to every job that fails, which says nothing more.
GENERIC = re.compile(r"Process completed with exit code \d+\.")
# What the report shows of a pull request at most: GitHub takes 65536 characters.
FINDINGS = 10
# The checks that failed which the report explains, each one with what it found.
SECTIONS = 20
FINDING_LENGTH = 300
NAME_LENGTH = 100
BODY_LENGTH = 60000
PAGE = 100
FIELDS = ("title", "problem", "why", "fix")
CONTROL = re.compile(r"[\x00-\x1f\x7f-\x9f\u2028\u2029]")
# A link to a page of GitHub, without a character that could end it in Markdown.
GITHUB_PAGE = re.compile(r"https://github\.com/[A-Za-z0-9._~%/-]+")


class CatalogError(ValueError):
    """An explanation of .github/checks.toml or checks.project.toml is incomplete."""


@dataclass(frozen=True)
class Entry:
    """The explanation of a check or of a step: what it found, why that matters, how
    to fix it, with the commands to run and a link to read more."""

    key: str
    title: str
    problem: str
    why: str
    fix: str
    commands: tuple[str, ...] = ()
    docs: str = ""
    # The commands that fix what the check finds, which the fix bot runs, or, where
    # no command can, the reason why not; an explanation without either has yet to
    # answer the question.
    autofix: tuple[str, ...] = ()
    manual: str = ""

    @property
    def declared(self) -> bool:
        """Whether the explanation says how the fix bot fixes it, or why it can't."""
        return bool(self.autofix or self.manual)

    @classmethod
    def parse(cls, kind: str, key: str, table: Json, source: str) -> Entry:
        where = f"{source}: [{kind}.{key!r}]"
        if not isinstance(table, dict):
            raise CatalogError(f"{where} is no table")
        unknown = sorted(set(table) - {*FIELDS, "commands", "docs", "autofix"})
        if unknown:
            raise CatalogError(f"{where} has unknown keys: {', '.join(unknown)}")
        # A step is named by its line in scripts/check.sh, which is its title.
        texts = {"title": key} if kind == "step" else {}
        texts |= table
        for name in FIELDS:
            if not isinstance(texts.get(name), str) or not texts[name].strip():
                raise CatalogError(f"{where} needs the text {name}")
        commands = table.get("commands", [])
        if not isinstance(commands, list) or not all(
            isinstance(command, str) for command in commands
        ):
            raise CatalogError(f"{where} has commands that are no list of lines")
        docs = table.get("docs", "")
        if not isinstance(docs, str):
            raise CatalogError(f"{where} has docs that are no link")
        autofix, manual = parse_autofix(table.get("autofix"), where)
        # The texts may wrap over several lines of the file.
        title, problem, why, fix = (" ".join(texts[name].split()) for name in FIELDS)
        return cls(
            key, title, problem, why, fix, tuple(commands), docs, autofix, manual
        )

    def within(self, repo: str | None) -> Entry:
        """The explanation for a repository: {repo} in a command becomes its name, so
        that a fork rebases onto the main of this repository, not onto its own."""
        name = repo or "OWNER/NAME"
        commands = tuple(command.replace(REPO, name) for command in self.commands)
        return replace(self, commands=commands)


def parse_autofix(value: Json, where: str) -> tuple[tuple[str, ...], str]:
    """The commands of autofix, a list, or the reason why no command fixes the check,
    a sentence; nothing when the explanation doesn't say yet."""
    if value is None:
        return (), ""
    if isinstance(value, str) and value.strip():
        return (), " ".join(value.split())
    if (
        isinstance(value, list)
        and value
        and all(isinstance(command, str) and command.strip() for command in value)
    ):
        return tuple(value), ""
    raise CatalogError(
        f"{where} has an autofix that is neither a list of commands nor the reason "
        "why no command fixes it"
    )


@dataclass(frozen=True)
class Catalog:
    """The explanations of the checks, by their names, and of the steps of
    scripts/check.sh; those of the project win over those of the blueprint."""

    checks: dict[str, Entry]
    steps: dict[str, Entry]

    @classmethod
    def load(cls, root: Path) -> Catalog:
        checks: dict[str, Entry] = {}
        steps: dict[str, Entry] = {}
        # The project's first, so that its patterns match before the blueprint's.
        for path in (root / PROJECT_CATALOG, root / CATALOG):
            if not path.is_file():
                continue
            source = path.relative_to(root).as_posix()
            try:
                data = tomllib.loads(path.read_text(encoding="utf-8"))
            except tomllib.TOMLDecodeError as error:
                raise CatalogError(f"{source}: {error}") from None
            unknown = sorted(set(data) - {"check", "step"})
            if unknown:
                raise CatalogError(f"{source} has unknown tables: {', '.join(unknown)}")
            for kind, entries in (("check", checks), ("step", steps)):
                tables = data.get(kind, {})
                if not isinstance(tables, dict):
                    raise CatalogError(f"{source}: {kind} is no table of tables")
                for key, table in tables.items():
                    entry = Entry.parse(kind, key, table, source)
                    entries.setdefault(key, entry)
        return cls(checks, steps)

    def check(self, name: str) -> Entry | None:
        """The explanation of a check, by its name; a key with a * stands for every
        name that it matches, as check (*) for check (python)."""
        if name in self.checks:
            return self.checks[name]
        for key, entry in self.checks.items():
            if "*" in key and fnmatch.fnmatchcase(name, key):
                return entry
        return None

    def step(self, name: str) -> Entry | None:
        return self.steps.get(name)


# ---------------------------------------------------------------- the text of the coach


def heading(entry: Entry, step: bool, within: str = "") -> str:
    """The title of the annotation and of the summary of a check or a step; that of a
    check that the job of another one runs, as lint runs markdown, names that one."""
    if step:
        return f"{PREFIX}{entry.key}{STEP_SUFFIX}"
    if within and within != entry.key:
        return f"{PREFIX}{entry.title} (in {within})"
    return f"{PREFIX}{entry.title}"


def link(docs: str, repo: str | None, server: str = "https://github.com") -> str:
    """Where to read more: a link as it is, a file of the repository on GitHub."""
    if not docs or docs.startswith("https://") or repo is None:
        return docs
    return f"{server}/{repo}/blob/HEAD/{docs}"


def fence(language: str, lines: Iterable[str]) -> str:
    """A block of code that no line of it can close."""
    text = "\n".join(lines)
    longest = max((len(run) for run in re.findall(r"`+", text)), default=0)
    mark = "`" * max(3, longest + 1)
    return f"{mark}{language}\n{text}\n{mark}"


# What the summary and the report say of a check whose findings a command fixes.
FIXES_ITSELF = (
    "**It fixes itself:** after the checks, the fix bot commits the fix onto a branch "
    "of this repository, and suggests it at its lines on a pull request from a fork."
)


def details(entry: Entry, url: str) -> list[str]:
    """The explanation as Markdown, for the summary of a check and for the report."""
    lines = [
        f"**What is wrong:** {entry.problem}",
        "",
        f"**Why it matters:** {entry.why}",
        "",
    ]
    if entry.autofix:
        lines += [FIXES_ITSELF, ""]
    lines += [f"**How to fix it:** {entry.fix}", ""]
    if entry.commands:
        lines += [fence("sh", entry.commands), ""]
    if url:
        label = url if url == entry.docs else entry.docs
        lines += [f"More: [{label}]({url})", ""]
    return lines


def summary(entry: Entry, step: bool, url: str, within: str = "") -> str:
    """The explanation in the summary of a job that failed."""
    return "\n".join([f"### {heading(entry, step, within)}", "", *details(entry, url)])


def plain(entry: Entry, step: bool, within: str = "") -> str:
    """The explanation in the log, and in the terminal of a person."""

    def wrap(label: str, text: str) -> str:
        return textwrap.fill(
            f"{label} {text}", width=88, initial_indent="  ", subsequent_indent="    "
        )

    lines = [
        heading(entry, step, within),
        wrap("What is wrong:", entry.problem),
        wrap("Why it matters:", entry.why),
        wrap("How to fix it:", entry.fix),
        *(f"      {command}" for command in entry.commands),
    ]
    if entry.docs:
        lines.append(f"  More: {entry.docs}")
    return "\n".join([*lines, ""])


def escape_data(text: str) -> str:
    """The message of a workflow command, as the runner reads it back."""
    return text.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")


def escape_property(text: str) -> str:
    """A property of a workflow command, such as its title."""
    return escape_data(text).replace(":", "%3A").replace(",", "%2C")


def annotation(entry: Entry, step: bool, within: str = "") -> str:
    """The annotation of the check, which GitHub shows with its log and in the files of
    the pull request. That of a step of scripts/check.sh is a notice: GitHub keeps only
    the first 10 errors of a step, which the tools of the step may have written."""
    message = "\n".join([entry.problem, f"How to fix it: {entry.fix}", *entry.commands])
    title = escape_property(heading(entry, step, within))
    level = "notice" if step else "error"
    return f"::{level} title={title}::{escape_data(message)}"


def unknown(name: str, step: bool) -> str:
    """What a check or a step without an explanation says."""
    where = f"the step {name} of scripts/check.sh" if step else f"the check {name}"
    return (
        f"The PR coach has no explanation of {where} yet; its log says what it found. "
        f"Explain it in {PROJECT_CATALOG.as_posix()}, so that the next person sees "
        "how to fix it.\n"
    )


# ---------------------------------------------------------------- untrusted text


def clean(text: str, limit: int) -> str:
    """Text of a pull request on one line, without control characters, cut at limit."""
    text = " ".join(CONTROL.sub(" ", text).split())
    return text if len(text) <= limit else text[: limit - 1].rstrip() + "…"


def code(text: str, limit: int = FINDING_LENGTH) -> str:
    """Untrusted text as inline code, which shows it as it is: no Markdown, HTML,
    mention or link of it takes effect."""
    text = clean(text, limit)
    if not text:
        return ""
    longest = max((len(run) for run in re.findall(r"`+", text)), default=0)
    mark = "`" * (longest + 1)
    pad = " " if text.startswith("`") or text.endswith("`") else ""
    return f"{mark}{pad}{text}{pad}{mark}"


def html_code(text: str) -> str:
    """Untrusted text as code inside HTML, as in the summary of a section."""
    return f"<code>{html.escape(clean(text, NAME_LENGTH))}</code>"


def suggest_title(title: str, branch: str) -> str:
    """A Conventional Commit title from a title that isn't one: the type from the title
    or the name of the branch, as feat/dark-mode, or from its words."""
    text = clean(title, 200)
    kind = scope = bang = ""
    match = re.match(
        r"^\[?([A-Za-z]+)(?:\(([^)]*)\))?\]?(!)?(\s*(?::|\]|-)\s*|\s+)(.*)$", text
    )
    word = match[1].lower() if match else ""
    # A type with a colon, or one that is no word of a sentence without it.
    typed = bool(match and match[4].strip()) and word in (*TYPES, *ALIASES)
    if match and (typed or word in BARE):
        kind = ALIASES.get(word, word)
        scope = re.sub(r"[^a-z0-9._/-]+", "-", (match[2] or "").lower()).strip("-")
        bang = match[3] or ""
        if typed or scope or bang or text[:1] == "[" or word not in NOUNS:
            text = match[5]
    if not kind:
        prefix = re.split(r"[/_-]", branch.lower(), maxsplit=1)[0]
        if prefix in (*TYPES, *ALIASES):
            kind = ALIASES.get(prefix, prefix)
    if not kind:
        repair = re.search(r"\b(fix|fixes|fixed|bug|repair|crash|error)\b", text, re.I)
        kind = "fix" if repair else "feat"
    text = text.rstrip(". ")
    # A lower first letter, but not of a name in capitals, such as README or CI.
    if text[:1].isupper() and not text[1:2].isupper():
        text = text[0].lower() + text[1:]
    return (
        f"{kind}{f'({scope})' if scope else ''}{bang}: {text or 'describe the change'}"
    )


# ---------------------------------------------------------------- the report


@dataclass(frozen=True)
class Run:
    """A check run of the head of a pull request."""

    id: int
    name: str
    status: str
    conclusion: str
    url: str
    annotations: int
    app: str = ACTIONS

    @classmethod
    def parse(cls, data: Json) -> Run:
        url = str(data.get("html_url") or "")
        output = data.get("output") or {}
        return cls(
            id=int(data["id"]),
            name=str(data.get("name") or ""),
            status=str(data.get("status") or ""),
            conclusion=str(data.get("conclusion") or ""),
            # Only a link to GitHub itself; an app may set any other.
            url=url if GITHUB_PAGE.fullmatch(url) else "",
            annotations=int(output.get("annotations_count") or 0),
            app=str((data.get("app") or {}).get("slug") or ""),
        )

    @property
    def failed(self) -> bool:
        return self.status == "completed" and self.conclusion in FAILED

    @property
    def stopped(self) -> bool:
        return self.status == "completed" and self.conclusion in STOPPED

    @property
    def pending(self) -> bool:
        return self.status != "completed"


@dataclass(frozen=True)
class Finding:
    """An annotation of a check that failed."""

    path: str
    line: int
    title: str
    message: str

    @classmethod
    def parse(cls, data: Json) -> Finding:
        return cls(
            path=str(data.get("path") or ""),
            line=int(data.get("start_line") or 0),
            title=str(data.get("title") or ""),
            message=str(data.get("message") or ""),
        )

    @property
    def step(self) -> str | None:
        """The step of scripts/check.sh that the coach explained in this annotation."""
        if self.title.startswith(PREFIX) and self.title.endswith(STEP_SUFFIX):
            return self.title.removeprefix(PREFIX).removesuffix(STEP_SUFFIX)
        return None

    @property
    def coach(self) -> bool:
        return self.title.startswith(PREFIX)

    def markdown(self) -> str:
        text = f"{self.title}: {self.message}" if self.title else self.message
        # Annotations without a file are GitHub's own, at the folder .github.
        where = "" if self.path in ("", ".github") else self.path
        if where and self.line:
            where += f":{self.line}"
        return " ".join(part for part in ("-", code(where, 160), code(text)) if part)


@dataclass(frozen=True)
class Part:
    """A part of the report about the pull request itself, after the checks, such as
    the release that it makes: its Markdown, whether it is worth a comment on a pull
    request whose checks pass, and the marker of an older comment of a bot that it
    takes the place of."""

    text: str
    news: bool = False
    replaces: str = ""


def paged(api: Api, path: str) -> Iterator[Json]:
    """Every item of a list of the API, one page after the other."""
    page = 1
    while True:
        separator = "&" if "?" in path else "?"
        items = api.get(f"{path}{separator}per_page={PAGE}&page={page}") or []
        yield from items
        if len(items) < PAGE:
            return
        page += 1


def find_pull(api: Api, repo: str, sha: str, number: int | None) -> Json | None:
    """The open pull request whose head is sha. A workflow run names a pull request of
    the same repository; one from a fork only its commit finds."""
    if number is not None:
        pull = api.get(f"repos/{repo}/pulls/{number}")
        candidates: Iterable[Json] = [pull] if pull else []
    else:
        candidates = paged(api, f"repos/{repo}/pulls?state=open")
    for pull in candidates:
        if pull.get("state") == "open" and pull.get("head", {}).get("sha") == sha:
            return pull
    return None


def check_runs(api: Api, repo: str, sha: str) -> list[Run]:
    """The latest check run of each check of a commit. Each run of a workflow is a
    check suite of its own, and the API lists the runs of every suite: a check that ran
    again, as the title check does after an edit of the title, counts with its newest
    run alone. Two apps may name a check alike, so the app is part of the check."""
    latest: dict[tuple[str, str], Run] = {}
    page = 1
    while True:
        data = api.get(
            f"repos/{repo}/commits/{sha}/check-runs?per_page={PAGE}&page={page}"
        ) or {"check_runs": []}
        for item in data["check_runs"]:
            run = Run.parse(item)
            key = (run.app, run.name)
            # The ids of check runs grow, so the highest is the newest.
            if key not in latest or run.id > latest[key].id:
                latest[key] = run
        if len(data["check_runs"]) < PAGE:
            return sorted(latest.values(), key=lambda run: (run.name, run.id))
        page += 1


def findings(api: Api, repo: str, run: Run) -> list[Finding]:
    """The errors that a check run reports as annotations, without GitHub's own, and
    the explanations of the coach."""
    if not run.annotations:
        return []
    items = paged(api, f"repos/{repo}/check-runs/{run.id}/annotations")
    found = [
        Finding.parse(item)
        for item in items
        if item.get("annotation_level") == "failure"
        or str(item.get("title") or "").startswith(PREFIX)
    ]
    return [finding for finding in found if not GENERIC.fullmatch(finding.message)]


def parts_of(found: Iterable[Finding], catalog: Catalog, check: str) -> list[Entry]:
    """The checks that the job of a check runs and that the coach explained in its
    annotations, such as each linter that failed in lint."""
    titles = {finding.title for finding in found if finding.coach}
    return [
        entry
        for entry in catalog.checks.values()
        if heading(entry, False, check) in titles
    ]


def section(
    run: Run,
    found: list[Finding],
    catalog: Catalog,
    pull: Json,
    repo: str,
    expanded: bool,
) -> str:
    """The part of the report about one check that failed."""
    entry = catalog.check(run.name)
    if entry:
        entry = entry.within(repo)
    name = html_code(run.name)
    title = f"<b>{html.escape(entry.title)}</b> · {name}" if entry else name
    lines = [
        f"<details{' open' if expanded else ''}>",
        f"<summary>❌ {title}</summary>",
        "",
    ]
    steps = [
        catalog.steps[step]
        for step in dict.fromkeys(finding.step for finding in found)
        if step in catalog.steps
    ]
    # A check that runs several others, as lint runs every linter, is explained by
    # each of them that failed, and by its own explanation only when it gave that too.
    explained = parts_of(found, catalog, run.name)
    parts = [part for part in explained if entry is None or part.key != entry.key]
    itself = len(parts) < len(explained) or not parts
    # A check that runs scripts/check.sh of this repository is explained by the step
    # that stopped it. Another one that runs a scripts/check.sh, as a variant runs that
    # of the project that it made, keeps its own explanation and names the step.
    own = entry is None or fnmatch.fnmatchcase(run.name, OWN_CHECKS)
    for step in steps:
        lines += [f"It stopped at the step **{step.key}** of `scripts/check.sh`.", ""]
        if not own:
            continue
        lines += details(step.within(repo), link(step.docs, repo))
        lines += [
            "Then run every check on your machine with `bash scripts/check.sh`, which "
            "explains each step that fails as well.",
            "",
        ]
    if entry and itself and not (own and steps):
        lines += details(entry, link(entry.docs, repo))
    if not entry and not steps and not parts:
        lines += [
            "The PR coach has no explanation of this check yet; its log says what it "
            f"found. Maintainers explain it in `{PROJECT_CATALOG.as_posix()}`.",
            "",
        ]
    if entry and itself and entry.key == TITLE_CHECK:
        suggestion = suggest_title(
            str(pull.get("title") or ""), str(pull["head"]["ref"])
        )
        lines += ["**A title that passes:**", "", fence("text", [suggestion]), ""]
    for part in parts:
        lines += [f"**{html.escape(part.title)}** · {html_code(part.key)}", ""]
        lines += details(part.within(repo), link(part.docs, repo))
    others = [finding for finding in found if not finding.coach]
    if others:
        lines += ["**What the check found:**", ""]
        lines += [finding.markdown() for finding in others[:FINDINGS]]
        if len(others) > FINDINGS:
            lines.append(
                f"- … and {len(others) - FINDINGS} more in the log of the check"
            )
        lines.append("")
    if run.url:
        lines += [f"[The log of the check]({run.url})", ""]
    return "\n".join([*lines, "</details>", ""])


def halted(stopped: list[Run]) -> list[str]:
    """The checks that stopped before the end, which haven't passed."""
    one = len(stopped) == 1
    count = "1 check" if one else f"{len(stopped)} checks"
    lines = [
        f"\u26a0\ufe0f **{count} stopped before the end**, cancelled or held, and "
        f"{'hasn' if one else 'haven'}'t passed until {'it runs' if one else 'they run'} "
        "again. A push runs every check again, and a maintainer can run one again "
        "from its log:",
        "",
    ]
    for run in stopped[:FINDINGS]:
        log = f" · [its log]({run.url})" if run.url else ""
        lines.append(f"- {code(run.name, NAME_LENGTH)}{log}")
    if len(stopped) > FINDINGS:
        lines.append(f"- … and {len(stopped) - FINDINGS} more")
    return lines


def fix_note(sha: str, fixed: Sequence[str]) -> str:
    """What the report says of a head that is a commit of the fix bot."""
    shown = ", ".join(code(path, NAME_LENGTH) for path in fixed[:FINDINGS])
    more = f" and {len(fixed) - FINDINGS} more" if len(fixed) > FINDINGS else ""
    files = f": {shown}{more}" if shown else ""
    return (
        f"🔧 **The fix bot committed the automatic fixes** in {sha[:7]}{files}. They "
        "are what the commands of the checks make; pull the branch before you push "
        "again."
    )


def footer(repo: str, sha: str) -> str:
    guide = link("CONTRIBUTING.md#when-a-check-fails", repo)
    return (
        f"<sub>For {sha[:7]}. The PR coach updates this comment after every run of the "
        f"checks. [How it works]({guide})</sub>\n"
    )


def render(
    repo: str,
    sha: str,
    pull: Json,
    runs: list[Run],
    found: dict[int, list[Finding]],
    catalog: Catalog,
    fixed: Sequence[str] | None = None,
    parts: Sequence[Part] = (),
) -> str:
    """The comment of the PR coach on a pull request; fixed holds the files of the
    head when the fix bot made it, and parts what the report says of the pull request
    itself, after the checks."""
    failed = [run for run in runs if run.failed]
    stopped = [run for run in runs if run.stopped]
    pending = sum(1 for run in runs if run.pending)
    lines = [MARKER, "### PR coach", ""]
    if failed:
        count = f"{len(failed)} check{'s' if len(failed) > 1 else ''}"
        lines += [
            f"**{count} failed.** Each one below says what it found, why it matters "
            "and how to fix it. Push the fix to this branch, and this comment "
            "follows.",
            "",
        ]
    elif pending or stopped:
        lines += ["⏳ No check has failed so far.", ""]
    else:
        lines += ["✅ **Every check passed.** Thank you!", ""]
    # No promise that the comment follows: the coach starts only after the workflows
    # that it watches, and a check may belong to another one or to an app.
    if pending:
        running = "1 check is" if pending == 1 else f"{pending} checks are"
        lines += [f"{running} still running.", ""]
    if stopped:
        lines += [*halted(stopped), ""]
    if fixed is not None:
        lines += [fix_note(sha, fixed), ""]
    body = "\n".join([*lines, ""])
    about = "".join(f"{part.text.rstrip()}\n\n" for part in parts)
    shown = 0
    for run in failed[:SECTIONS]:
        part = section(
            run, found.get(run.id, []), catalog, pull, repo, len(failed) <= 3
        )
        if len(body) + len(part) + len(about) > BODY_LENGTH:
            break
        body += part + "\n"
        shown += 1
    if shown < len(failed):
        body += f"… and {len(failed) - shown} more; their logs say what they found.\n\n"
    return body + about + footer(repo, sha)


def fixed_by_bot(api: Api, repo: str, sha: str) -> list[str] | None:
    """The files of sha when it is a commit of the fix bot, an app with its headline."""
    commit = api.get(f"repos/{repo}/commits/{sha}") or {}
    message = str((commit.get("commit") or {}).get("message") or "")
    headline = message.partition("\n")[0]
    if (commit.get("author") or {}).get("type") != "Bot" or headline != FIX_HEADLINE:
        return None
    return [str(item.get("filename") or "") for item in commit.get("files") or []]


def bot_comments(api: Api, repo: str, number: int) -> list[Json]:
    """The comments of the bot on a pull request; those of anyone else stay as they
    are, even with a marker."""
    return [
        comment
        for comment in paged(api, f"repos/{repo}/issues/{number}/comments")
        if (comment.get("user") or {}).get("login") == BOT
    ]


def marked(comments: Iterable[Json], marker: str) -> list[Json]:
    """The comments whose body starts with marker."""
    return [
        comment
        for comment in comments
        if str(comment.get("body") or "").startswith(marker)
    ]


def apps_only(runs: list[Run]) -> bool:
    """Whether only checks of apps other than the workflows still run: none of the
    workflows that start the coach ends after them."""
    pending = [run for run in runs if run.pending]
    return bool(pending) and all(run.app != ACTIONS for run in pending)


def report(
    api: Api,
    repo: str,
    sha: str,
    number: int | None,
    catalog: Catalog,
    wait: Callable[[float], None] = time.sleep,
    about: Callable[[Json], Sequence[Part]] | None = None,
) -> str:
    """Keeps the one comment of the PR coach on the pull request of sha up to date,
    and says what it did, for the summary of the job. about gives the parts of the
    report about the pull request of a person, such as the release that it makes; a
    pull request of a bot gets none. A pull request whose checks never failed gets no
    comment, unless one of its parts is news."""
    pull = find_pull(api, repo, sha, number)
    if pull is None:
        return f"{sha[:7]} is no longer the head of an open pull request; no report.\n"
    number = int(pull["number"])
    runs = check_runs(api, repo, sha)
    # The checks of other apps start no coach when they end; it waits a little for them.
    for _ in range(WAITS):
        if not apps_only(runs):
            break
        wait(WAIT)
        runs = check_runs(api, repo, sha)
    comments = bot_comments(api, repo, number)
    comment = next(iter(marked(comments, MARKER)), None)
    failed = [run for run in runs if run.failed]
    person = (pull.get("user") or {}).get("type") != "Bot"
    parts = list(about(pull)) if about is not None and person else []
    if comment is None and not failed and not any(part.news for part in parts):
        retire(api, repo, comments, parts)
        return f"No check of #{number} failed; it needs no report.\n"
    # What the checks found, only of those that the report can show: each check that
    # failed has one annotation at least, and the API takes only so many requests.
    found = {run.id: findings(api, repo, run) for run in failed[:SECTIONS]}
    fixed = fixed_by_bot(api, repo, sha)
    body = render(repo, sha, pull, runs, found, catalog, fixed, parts)
    if comment is not None and comment.get("body") == body:
        said = f"The report of #{number} is up to date:\n\n{body}"
    # A push while the coach read the checks: the report of the new head follows, and
    # this one neither overwrites it nor adds a second comment next to it.
    elif find_pull(api, repo, sha, number) is None:
        return f"{sha[:7]} is no longer the head of #{number}; no report.\n"
    elif comment is None:
        api.post(f"repos/{repo}/issues/{number}/comments", {"body": body})
        said = f"The report of #{number}:\n\n{body}"
    else:
        api.patch(f"repos/{repo}/issues/comments/{comment['id']}", {"body": body})
        said = f"The report of #{number}, updated:\n\n{body}"
    retire(api, repo, comments, parts)
    return said


def retire(api: Api, repo: str, comments: list[Json], parts: Sequence[Part]) -> None:
    """Deletes the comments of the bot whose place the report took: those of the
    retired bots, and those that a part replaces, as the report says what they said."""
    replaced = [part.replaces for part in parts if part.replaces]
    for marker in dict.fromkeys([*RETIRED, *replaced]):
        for old in marked(comments, marker):
            # Someone may have deleted it a moment ago.
            api.delete(f"repos/{repo}/issues/comments/{old['id']}", missing_ok=True)


# ---------------------------------------------------------------- what needs explaining


def required_checks(root: Path) -> list[str]:
    """The checks that the rules of main require, of the blueprint and the project."""
    names: list[str] = []
    for name in ("repository.toml", "repository.project.toml"):
        path = root / ".github" / name
        if path.is_file():
            config: dict[str, Any] = tomllib.loads(path.read_text(encoding="utf-8"))
            names += config.get("branch", {}).get("required_checks", [])
    return list(dict.fromkeys(names))


def check_steps(root: Path) -> list[str]:
    """The steps of scripts/check.sh, by the names that its function step prints: also
    one indented within an if, as the step Checks of this project, which runs only where
    scripts/check-project.sh is."""
    path = root / "scripts/check.sh"
    text = path.read_text(encoding="utf-8") if path.is_file() else ""
    return re.findall(r'^[ \t]*step "([^"$`\\]+)"$', text, re.M)


def undeclared(catalog: Catalog) -> list[str]:
    """The explanations that say neither how the fix bot fixes their check nor why no
    command can."""
    return [
        *(
            f"the check {key}"
            for key, entry in catalog.checks.items()
            if not entry.declared
        ),
        *(
            f"the step {key} of scripts/check.sh"
            for key, entry in catalog.steps.items()
            if not entry.declared
        ),
    ]


def missing(catalog: Catalog, checks: Iterable[str], steps: Iterable[str]) -> list[str]:
    """The checks and the steps that the catalog doesn't explain."""
    return [
        *(f"the check {name}" for name in checks if catalog.check(name) is None),
        *(
            f"the step {name} of scripts/check.sh"
            for name in steps
            if catalog.step(name) is None
        ),
    ]
