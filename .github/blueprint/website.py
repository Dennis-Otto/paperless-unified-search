"""The steps of a website that need no generator: the page of the automation, which
is written before the build, and the sitemaps and the map of the website for AI
assistants, llms.txt, which are finished after it.

The hooks of .github/mkdocs_blueprint.py take these steps in every build of MkDocs,
with what the build knows; blueprint.py website takes them for a build with another
generator, such as Zensical, which runs no hooks of MkDocs (decision 0166):

    python3 blueprint.py website automation DIR --site-name NAME ...
                                            the page of the automation into DIR
    python3 blueprint.py website finish SITE --site-url URL [--menu FILE]
                                            the sitemaps and llms.txt of SITE
"""

from __future__ import annotations

import gzip
import html
import json
import re
import shutil
import tomllib
from collections.abc import Sequence
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, NamedTuple
from urllib.parse import unquote, urlparse

# ---------------------------------------------------------- the page of the automation


# The page of every website that shows how its repository looks after itself: each
# workflow of .github/workflows/ with the comment at its top, what starts it, which
# of its checks main requires, and the environments and secrets that it uses. The
# hooks write it at every build, so it changes with the workflows. A page of the
# project by the same name, docs/automation.md, takes its place, and
# docs/automation.de.md that of the German version.
AUTOMATION = "automation.md"
# The settings as code, whose required_checks of [branch] main requires.
SETTINGS = ("repository.toml", "repository.project.toml")
# The workflows of the blueprint that only it runs: every job of them asks for
# another repository than the one of the website.
GUARD = re.compile(
    r"\s*(?:\$\{\{\s*)?github\.repository\s*!=\s*'(?P<repository>[^']+)'\s*(?:\}\}\s*)?"
)
# The secrets that a workflow uses; GitHub gives every run GITHUB_TOKEN.
SECRET = re.compile(r"\bsecrets\.(?P<name>[A-Za-z_]\w*)")
GIVEN = frozenset({"GITHUB_TOKEN"})
# The address with which the comment of a workflow of the blueprint ends, which the
# page shows as the origin of the workflow on the websites of other repositories.
BLUEPRINT = "https://github.com/Dennis-Otto/repo-blueprint"
# What the comment at the top of a workflow names as code, or as an address.
CODE_OR_ADDRESS = re.compile(
    r"(?<!`)`(?P<code>[^`]+)`(?!`)"
    r"|(?P<address>https?://[^\s<>\"'`]*[^\s<>\"'`.,;:!?)])"
)
# The groups of the page, in its order, and the words of the file names of the
# workflows that put one into its group: the first group with such a word, in the
# order of KEYWORDS, takes it. A workflow without one is a check when a push or a
# pull request starts it, maintenance when a schedule does, and a bot otherwise.
GROUPS = ("checks", "releases", "bots", "security", "maintenance")
KEYWORDS = (
    ("security", {"codeql", "dependency", "findings", "fuzz", "osv", "scorecard"}),
    ("security", {"secret", "security", "zizmor"}),
    ("releases", {"deploy", "publish", "register", "release", "rollout", "sbom"}),
    ("bots", {"after", "area", "automerge", "branches", "issue", "pictures"}),
    ("bots", {"renovate", "screenshot", "screenshots", "update", "welcome"}),
    ("maintenance", {"cleanup", "dashboard", "health", "labels", "report"}),
    ("maintenance", {"settings", "translations", "upstream"}),
    ("checks", {"ci", "diagrams", "docs", "downstream", "e2e", "lint", "links"}),
    ("checks", {"mutation", "newest", "properties", "runner", "test", "title"}),
    ("checks", {"variants", "visual", "windows"}),
)
CHANGES = frozenset({"push", "pull_request", "pull_request_target", "merge_group"})
# The headings of the page that are not those of a workflow, by their anchors, the
# same in every language.
SECTIONS = ("required-checks", "at-a-glance", *GROUPS)
# The attribute of the heading of the table of the workflows by which blueprint.py
# site-check knows the page of the hooks from a page of the project with such a heading.
AUTOMATION_MARK = 'data-blueprint-automation="true"'
# The words of the page in each language that it has. The comments of the
# workflows are English on every page.
WORDS: dict[str, dict[str, str]] = {
    "en": {
        "title": "Automation",
        "description": (
            "How {site} looks after itself: its {count} workflows of GitHub Actions, "
            "what starts each one, which checks a pull request needs and which "
            "workflows hold secrets."
        ),
        "intro": (
            "How this repository looks after itself: its {count} workflows of GitHub "
            "Actions, in `.github/workflows/`, check every change, make a release of "
            "what reaches `main`, answer issues and pull requests, look for "
            "vulnerabilities and keep the repository current. The website writes "
            "this page from the workflows at every build, with the comment at the "
            "top of each, so it is always as current as they are."
        ),
        "required-checks": "What a pull request needs",
        "required intro": (
            "A pull request merges into `main` once each of these checks passes, as "
            "the settings in {files} require:"
        ),
        "check of": "{check} of {workflow}",
        "at-a-glance": "At a glance",
        "glance intro": (
            "The times are UTC. GitHub starts a scheduled run when it can: often "
            "some minutes late, and at busy times not at all."
        ),
        "workflow": "Workflow",
        "when": "When",
        "required": "Required checks",
        "environments": "Environments",
        "secrets": "Secrets",
        "source": "Source",
        "from": "{source}, from the [Repo Blueprint]({blueprint})",
        "checks": "Checks",
        "checks intro": "They check every change before it reaches `main`.",
        "releases": "Releases",
        "releases intro": (
            "They make a signed release of what reaches `main`, deliver it and "
            "verify it as its users can."
        ),
        "bots": "Bots",
        "bots intro": (
            "They answer issues and pull requests, fix what a command can fix and "
            "keep the pull requests moving."
        ),
        "security": "Security",
        "security intro": (
            "They look for vulnerabilities, leaked secrets and risky dependencies."
        ),
        "maintenance": "Maintenance",
        "maintenance intro": (
            "They keep the repository, its settings, its labels and its reports "
            "current."
        ),
        "push": "every push",
        "push to": "every push to {branches}",
        "tags": "every new tag",
        "paths": " that changes certain files",
        "pull request": "every pull request",
        "after": "after a run of {workflows}",
        "after many": "after {count} workflows",
        "on merge_group": "the merge queue",
        "on workflow_dispatch": "by hand",
        "on workflow_call": "when another workflow calls it",
        "on issues": "new and changed issues",
        "on issue_comment": "comments",
        "on release": "a release",
        "event": "the event {event}",
        "hourly": "every hour at :{minute}",
        "hours": "every {step} hours at :{minute}",
        "daily": "every day at {time}",
        "weekly": "every {days} at {time}",
        "monthly": "on day {day} of every month at {time}",
        "schedule": "on the schedule {cron}",
        "and": "and",
        "or": "or",
    },
    "de": {
        "title": "Automatisierung",
        "description": (
            "Wie sich {site} selbst pflegt: seine {count} Workflows von GitHub "
            "Actions, was jeden startet, welche Prüfungen ein Pull Request braucht "
            "und welche Workflows Secrets halten."
        ),
        "intro": (
            "Wie sich dieses Repository selbst pflegt: seine {count} Workflows von "
            "GitHub Actions in `.github/workflows/` prüfen jede Änderung, machen ein "
            "Release aus dem, was `main` erreicht, beantworten Issues und Pull "
            "Requests, suchen nach Schwachstellen und halten das Repository aktuell. "
            "Die Website schreibt diese Seite bei jedem Build aus den Workflows, mit "
            "dem englischen Kommentar am Anfang jedes Workflows, und bleibt so immer "
            "auf ihrem Stand."
        ),
        "required-checks": "Was ein Pull Request braucht",
        "required intro": (
            "Ein Pull Request kommt nach `main`, sobald jede dieser Prüfungen "
            "besteht, wie es die Einstellungen in {files} verlangen:"
        ),
        "check of": "{check} aus {workflow}",
        "at-a-glance": "Auf einen Blick",
        "glance intro": (
            "Die Zeiten sind UTC. GitHub startet einen geplanten Lauf, wenn es kann: "
            "oft einige Minuten später und zu Spitzenzeiten auch gar nicht."
        ),
        "workflow": "Workflow",
        "when": "Wann",
        "required": "Pflichtprüfungen",
        "environments": "Umgebungen",
        "secrets": "Secrets",
        "source": "Quelle",
        "from": "{source}, aus dem [Repo Blueprint]({blueprint})",
        "checks": "Prüfungen",
        "checks intro": "Sie prüfen jede Änderung, bevor sie `main` erreicht.",
        "releases": "Releases",
        "releases intro": (
            "Sie machen aus dem, was `main` erreicht, ein signiertes Release, liefern "
            "es aus und prüfen es so, wie seine Nutzer es können."
        ),
        "bots": "Bots",
        "bots intro": (
            "Sie beantworten Issues und Pull Requests, beheben, was ein Befehl "
            "beheben kann, und bringen die Pull Requests voran."
        ),
        "security": "Sicherheit",
        "security intro": (
            "Sie suchen nach Schwachstellen, verratenen Secrets und riskanten "
            "Abhängigkeiten."
        ),
        "maintenance": "Pflege",
        "maintenance intro": (
            "Sie halten das Repository, seine Einstellungen, seine Labels und seine "
            "Berichte aktuell."
        ),
        "push": "jeder Push",
        "push to": "jeder Push auf {branches}",
        "tags": "jedes neue Tag",
        "paths": ", der bestimmte Dateien ändert",
        "pull request": "jeder Pull Request",
        "after": "nach einem Lauf von {workflows}",
        "after many": "nach {count} Workflows",
        "on merge_group": "die Merge-Queue",
        "on workflow_dispatch": "von Hand",
        "on workflow_call": "wenn ein anderer Workflow ihn aufruft",
        "on issues": "neue und geänderte Issues",
        "on issue_comment": "Kommentare",
        "on release": "ein Release",
        "event": "das Ereignis {event}",
        "hourly": "jede Stunde um :{minute}",
        "hours": "alle {step} Stunden um :{minute}",
        "daily": "jeden Tag um {time}",
        "weekly": "jeden {days} um {time}",
        "monthly": "am {day}. jedes Monats um {time}",
        "schedule": "nach dem Zeitplan {cron}",
        "and": "und",
        "or": "oder",
    },
}
# The days of the week as cron counts them, from Sunday.
WEEKDAYS = {
    "en": (
        "Sunday",
        "Monday",
        "Tuesday",
        "Wednesday",
        "Thursday",
        "Friday",
        "Saturday",
    ),
    "de": (
        "Sonntag",
        "Montag",
        "Dienstag",
        "Mittwoch",
        "Donnerstag",
        "Freitag",
        "Samstag",
    ),
}
# The diagrams of the page, which the blueprint shows in its README and on its page of
# the releases: how a repository looks after itself, and from a change to a release. The page shows the pictures that
# scripts/diagrams.sh draws of them, NAME.svg and NAME.dark.svg, NAME.de.svg and
# NAME.de.dark.svg in German, which every repository gets in PICTURES beside this
# file and the website serves in SERVED; the blueprint itself has them in
# docs/images/diagrams/.
DIAGRAMS = ("how-a-repository-looks-after-itself", "from-a-change-to-a-release")
PICTURES = "mkdocs-blueprint-diagrams"
SERVED = "assets/images/automation"
# The title and the description of a diagram in its picture, from its accTitle and
# accDescr, which the text of the picture repeats.
TITLE = re.compile(r"<title\b[^>]*>(?P<text>[^<]*)</title>")
DESC = re.compile(r"<desc\b[^>]*>(?P<text>[^<]*)</desc>")


class Workflow(NamedTuple):
    """A workflow of .github/workflows/, as the page of the automation shows it: its
    file, its name, the paragraphs of the comment at its top, its events with their
    settings, the names of its jobs, which are those of their checks, the
    environments and secrets that it uses, and whether it comes from the blueprint."""

    file: str
    name: str
    about: tuple[str, ...]
    events: dict[str, Any]
    jobs: tuple[str, ...]
    environments: tuple[str, ...]
    secrets: tuple[str, ...]
    blueprint: bool = False

    @property
    def anchor(self) -> str:
        """The anchor of its heading on the page, from the name of its file."""
        slug = re.sub(r"[^a-z0-9]+", "-", Path(self.file).stem.lower()).strip("-")
        return f"{slug}-workflow" if slug in SECTIONS or not slug else slug

    @property
    def group(self) -> str:
        """The group of the page that shows it, one of GROUPS."""
        words = set(re.split(r"[^a-z0-9]+", Path(self.file).stem.lower()))
        for group, keywords in KEYWORDS:
            if words & keywords:
                return group
        if CHANGES & set(self.events):
            return "checks"
        return "maintenance" if "schedule" in self.events else "bots"

    def when(
        self, language: str, anchors: dict[str, str], brief: bool = False
    ) -> list[str]:
        """What starts it, in words: each event once, the workflows after whose run
        it runs linked to their headings, or only counted when brief."""
        words = WORDS[language]
        found: list[str] = []
        for event, value in self.events.items():
            settings = value if isinstance(value, dict) else {}
            changes = settings.get("paths") or settings.get("paths-ignore")
            if event == "schedule":
                phrases = [
                    cron(str(entry.get("cron", "")), language)
                    for entry in (value if isinstance(value, list) else [])
                    if isinstance(entry, dict)
                ]
            elif event == "push":
                phrases = [
                    pushed(settings, words) + (words["paths"] if changes else "")
                ]
            elif event in ("pull_request", "pull_request_target"):
                phrases = [words["pull request"] + (words["paths"] if changes else "")]
            elif event == "workflow_run" and settings.get("workflows"):
                names = [str(name) for name in settings["workflows"]]
                phrases = [after(names, words, anchors, brief)]
            else:
                phrases = [
                    words.get(f"on {event}") or words["event"].format(event=code(event))
                ]
            for phrase in phrases:
                if phrase not in found:
                    found.append(phrase)
        return found


def after(
    names: list[str], words: dict[str, str], anchors: dict[str, str], brief: bool
) -> str:
    """The workflows after whose run a workflow runs, in words: each linked to its
    heading on the page, or only counted when brief."""
    if brief and len(names) > 1:
        return words["after many"].format(count=len(names))
    linked = [
        f"[{plain(name)}](#{anchors[name]})" if name in anchors else plain(name)
        for name in names
    ]
    return words["after"].format(workflows=listing(linked, words["or"]))


@dataclass
class Automation:
    """The page of the automation in each language, by its path in docs/; the files
    that it is made of, the workflows and the settings, by which the website dates
    it; the pictures of its diagrams that the website serves in SERVED, since they
    are no files of docs/; and the names of the pictures of each diagram that is
    missing, which a page shows without them."""

    pages: dict[str, str] = field(default_factory=dict)
    sources: list[Path] = field(default_factory=list)
    pictures: list[Path] = field(default_factory=list)
    missing: list[list[str]] = field(default_factory=list)


def automation(
    folder: Path,
    docs: Path,
    pictures: Path,
    *,
    site_name: str,
    repository: str,
    default: str,
    further: Sequence[str] = (),
) -> Automation:
    """The page of the automation of the workflows in folder, in default, the
    language of the website, and in each language of further that has WORDS and no
    page of the project in docs. site_name is the name of the website, repository
    the address of its repository, such as https://github.com/owner/name, or "", and
    pictures the folder PICTURES, whose pictures of the diagrams come before those
    of docs/images/diagrams/."""
    root = folder.parent.parent
    settings = [root / ".github" / name for name in SETTINGS]
    settings = [path for path in settings if path.is_file()]
    required: list[str] = []
    for path in settings:
        data = tomllib.loads(path.read_text(encoding="utf-8"))
        required += data.get("branch", {}).get("required_checks", [])
    required = list(dict.fromkeys(required))
    made = Automation(sources=[folder, *settings])
    flows = workflows(folder, urlparse(repository).path.strip("/"))
    names = [path.name for path in settings]
    shown = default if default in WORDS else "en"
    languages = [(AUTOMATION, shown)] + [
        (AUTOMATION.replace(".md", f".{language}.md"), language) for language in further
    ]
    for name, language in languages:
        if language in WORDS and (name == AUTOMATION or not (docs / name).exists()):
            made.pages[name] = automation_page(
                flows,
                required,
                names,
                site_name,
                repository,
                language,
                diagrams(made, docs, pictures, language),
            )
    return made


def diagrams(made: Automation, docs: Path, pictures: Path, language: str) -> list[str]:
    """The diagrams of the page of the automation in language, each as the
    <picture> of its pictures for the light and the dark scheme, as a page of docs/
    shows one, with its title and its description as the text of the picture, or
    "" for one whose pictures are missing, which go into made.missing.

    Those in pictures go into made.pictures, which the website serves in SERVED;
    in the blueprint, which has no such folder, those of docs/images/diagrams/ are
    files of the website already.
    """
    folders = (pictures, docs / "images/diagrams")
    suffix = "" if language == "en" else f".{language}"
    shown = []
    for diagram in DIAGRAMS:
        names = [f"{diagram}{suffix}{scheme}.svg" for scheme in ("", ".dark")]
        folder = next(
            (
                folder
                for folder in folders
                if all((folder / n).is_file() for n in names)
            ),
            None,
        )
        if folder is None:
            made.missing.append(names)
            shown.append("")
            continue
        light, dark = (folder / name for name in names)
        if folder.is_relative_to(docs):
            sources = [path.relative_to(docs).as_posix() for path in (light, dark)]
        else:
            made.pictures += [light, dark]
            sources = [f"{SERVED}/{path.name}" for path in (light, dark)]
        shown.append(picture_of(light, *sources))
    return shown


def picture_of(light: Path, src: str, dark: str) -> str:
    """The <picture> of a diagram, as scripts/diagrams.sh --extract writes it, whose
    text is the title and the description in the picture light."""
    svg = light.read_text(encoding="utf-8")
    parts = [found["text"] for found in (TITLE.search(svg), DESC.search(svg)) if found]
    alt = html.escape(": ".join(html.unescape(part).strip() for part in parts))
    return (
        "<picture>\n"
        f'  <source media="(prefers-color-scheme: dark)" srcset="{dark}">\n'
        f'  <img src="{src}" alt="{alt}">\n'
        "</picture>"
    )


def workflows(folder: Path, repository: str) -> list[Workflow]:
    """The workflows in folder, by the names of their files, without those of which
    no job runs in repository, the owner/name of the website's, such as the checks
    of the kinds of projects in the blueprint itself. A file that is no workflow
    shows by its name alone."""
    # PyYAML, which only this step needs: the others run with Python alone, as
    # blueprint.py does.
    import yaml

    found = []
    for path in sorted(folder.glob("*.y*ml")):
        text = path.read_text(encoding="utf-8")
        try:
            data = yaml.load(text, Loader=getattr(yaml, "CSafeLoader", yaml.SafeLoader))
        except yaml.YAMLError:
            data = None
        data = data if isinstance(data, dict) else {}
        jobs = data.get("jobs")
        jobs = {
            str(key): job
            for key, job in (jobs.items() if isinstance(jobs, dict) else ())
            if isinstance(job, dict)
        }
        if jobs and idle(jobs, repository):
            continue
        # YAML 1.1 reads the key on as true.
        events = data.get(True, data.get("on"))
        if isinstance(events, str):
            events = {events: None}
        elif isinstance(events, list):
            events = dict.fromkeys(map(str, events))
        about = header(text)
        blueprint = bool(about) and about[-1].endswith(BLUEPRINT)
        if blueprint:
            about[-1] = about[-1].removesuffix(BLUEPRINT).rstrip()
        found.append(
            Workflow(
                file=path.name,
                name=str(data.get("name") or path.stem),
                about=tuple(paragraph for paragraph in about if paragraph),
                events=events if isinstance(events, dict) else {},
                jobs=tuple(str(job.get("name") or key) for key, job in jobs.items()),
                environments=tuple(
                    sorted(
                        {name for job in jobs.values() if (name := environment(job))}
                    )
                ),
                secrets=tuple(sorted(set(SECRET.findall(text)) - GIVEN)),
                blueprint=blueprint,
            )
        )
    return found


def idle(jobs: dict[str, dict[str, Any]], repository: str) -> bool:
    """Whether no job of a workflow runs in repository: each asks for another
    repository, or waits only for jobs that don't run."""
    skipped = {key for key, job in jobs.items() if elsewhere(job.get("if"), repository)}
    waiting = True
    while waiting:
        waiting = False
        for key, job in jobs.items():
            needs = job.get("needs") or []
            needs = [needs] if isinstance(needs, str) else list(needs)
            if key not in skipped and needs and set(needs) <= skipped:
                skipped.add(key)
                waiting = True
    return skipped == set(jobs)


def elsewhere(condition: Any, repository: str) -> bool:
    """Whether the condition if: of a job asks for another repository than this
    one, github.repository != 'owner/name' with the name of this one."""
    match = GUARD.fullmatch(str(condition or ""))
    return bool(match and match["repository"].lower() == repository.lower())


def environment(job: dict[str, Any]) -> str:
    """The name of the environment of a job, or an empty one."""
    value = job.get("environment")
    if isinstance(value, dict):
        value = value.get("name")
    return str(value or "")


def header(text: str) -> list[str]:
    """The paragraphs of the comment at the top of a workflow, before its first key
    other than its name; an empty line of the comment ends a paragraph, and the
    lines of its license, SPDX-…, are none."""
    paragraphs: list[str] = []
    lines: list[str] = []
    for line in text.splitlines():
        if line.startswith("#"):
            words = line[1:].strip()
            if words.startswith("SPDX-"):
                continue
            if words:
                lines.append(words)
                continue
        elif line.strip() and not line.startswith("name:"):
            break
        if lines:
            paragraphs.append(" ".join(lines))
            lines = []
    if lines:
        paragraphs.append(" ".join(lines))
    return paragraphs


def pushed(settings: dict[str, Any], words: dict[str, str]) -> str:
    """A push that starts a workflow, in words: to its branches, of a tag, or any."""
    branches = [code(str(branch)) for branch in settings.get("branches") or []]
    if branches:
        return words["push to"].format(branches=listing(branches, words["or"]))
    return words["tags"] if settings.get("tags") else words["push"]


def cron(expression: str, language: str) -> str:
    """A schedule of cron in words, such as every Tuesday at 04:40 UTC, or as it is
    when it says more than its minute, its hour and a day of the week or month."""
    words = WORDS[language]
    fields = expression.split()
    if len(fields) == 5 and fields[0].isdigit() and fields[3] == "*":
        minute, hour, day, _, weekday = fields
        at = f"{int(minute):02d}"
        step = re.fullmatch(r"\*/(\d+)", hour)
        every = day == "*" and weekday == "*"
        if hour == "*" and every:
            return words["hourly"].format(minute=at)
        if step and every:
            return words["hours"].format(step=step[1], minute=at)
        time = f"{int(hour):02d}:{at} UTC" if hour.isdigit() else ""
        days = weekdays(weekday, language) if weekday != "*" else None
        if time and every:
            return words["daily"].format(time=time)
        if time and day == "*" and days:
            return words["weekly"].format(days=listing(days, words["and"]), time=time)
        if time and day.isdigit() and weekday == "*":
            return words["monthly"].format(day=int(day), time=time)
    return words["schedule"].format(cron=code(expression))


def weekdays(field: str, language: str) -> list[str]:
    """The days of the week of cron in the field of a schedule, such as 1,3 or 1-5,
    by their names; none for a field that names them otherwise, such as MON."""
    days: list[int] = []
    for part in field.split(","):
        bounds = part.split("-")
        if len(bounds) > 2 or not all(b.isdigit() and int(b) <= 7 for b in bounds):
            return []
        days += range(int(bounds[0]), int(bounds[-1]) + 1)
    return [WEEKDAYS[language][day % 7] for day in days]


def listing(items: list[str], conjunction: str) -> str:
    """items in a sentence: A, B or C."""
    if len(items) < 2:
        return "".join(items)
    return f"{', '.join(items[:-1])} {conjunction} {items[-1]}"


def code(value: str) -> str:
    """value as code, in HTML, which Markdown leaves as it is."""
    return f"<code>{html.escape(value, quote=False)}</code>"


def plain(value: str) -> str:
    """value as text of Markdown, without a mark that would format it."""
    return re.sub(r"([\\`*_{}\[\]#])", r"\\\1", html.escape(value, quote=False))


def prose(text: str, language: str) -> str:
    """A paragraph of the comment of a workflow as a paragraph of HTML, with its
    `code` as code and its addresses as links, and in English on a page of another
    language (WCAG 3.1.2). The comment is no Markdown: a ```mermaid at the start of
    a line of it would start a block of code."""
    parts = []
    last = 0
    for match in CODE_OR_ADDRESS.finditer(text):
        parts.append(html.escape(text[last : match.start()], quote=False))
        if match["code"] is not None:
            parts.append(code(match["code"]))
        else:
            address = match["address"]
            parts.append(f'<a href="{html.escape(address)}">{html.escape(address)}</a>')
        last = match.end()
    parts.append(html.escape(text[last:], quote=False))
    english = "" if language == "en" else ' lang="en"'
    return f"<p{english}>{''.join(parts)}</p>"


def automation_page(
    flows: list[Workflow],
    required: list[str],
    settings: list[str],
    site_name: str,
    repository: str,
    language: str,
    shown: list[str],
) -> str:
    """The page of the automation in language, as Markdown, of the website site_name
    of the repository at the address repository: what the workflows do, with the
    diagrams shown, what a pull request needs by the files of settings in
    .github/ that require it, a table of what starts each, and each workflow in its
    group."""
    words = WORDS[language]
    overview, release = shown
    count = len(flows)
    anchors = {flow.name: flow.anchor for flow in flows}
    providers: dict[str, Workflow] = {}
    for flow in flows:
        for job in flow.jobs:
            providers.setdefault(job, flow)
    description = words["description"].format(site=site_name, count=count)
    lines = [
        "---",
        f"description: {json.dumps(description, ensure_ascii=False)}",
        "---",
        "",
        f"# {words['title']}",
        "",
        words["intro"].format(count=count),
        "",
    ]
    if overview:
        lines += [overview, ""]
    if required:
        lines += [f"## {words['required-checks']} {{#required-checks}}", ""]
        files = listing([f"`.github/{name}`" for name in settings], words["and"])
        lines += [words["required intro"].format(files=files), ""]
        for check in required:
            provider = providers.get(check)
            lines.append(
                "- "
                + (
                    words["check of"].format(
                        check=code(check),
                        workflow=f"[{plain(provider.name)}](#{provider.anchor})",
                    )
                    if provider
                    else code(check)
                )
            )
        lines.append("")
    groups = {
        group: [flow for flow in flows if flow.group == group] for group in GROUPS
    }
    lines += [f"## {words['at-a-glance']} {{#at-a-glance {AUTOMATION_MARK}}}", ""]
    lines += [words["glance intro"], ""]
    lines += [f"| {words['workflow']} | {words['when']} |", "| --- | --- |"]
    for flow in (flow for group in GROUPS for flow in groups[group]):
        when = ", ".join(flow.when(language, anchors, brief=True))
        lines.append(f"| [{plain(flow.name)}](#{flow.anchor}) | {when} |")
    lines.append("")
    for group in GROUPS:
        if not groups[group]:
            continue
        lines += [f"## {words[group]} {{#{group}}}", "", words[f"{group} intro"], ""]
        if group == "releases" and release:
            lines += [release, ""]
        for flow in groups[group]:
            lines += [f"### {plain(flow.name)} {{#{flow.anchor}}}", ""]
            for paragraph in flow.about:
                lines += [prose(paragraph, language), ""]
            path = f".github/workflows/{flow.file}"
            source = (
                f"[`{path}`]({repository}/blob/HEAD/{path})"
                if repository
                else f"`{path}`"
            )
            if flow.blueprint and repository != BLUEPRINT:
                source = words["from"].format(source=source, blueprint=BLUEPRINT)
            facts = (
                ("when", ", ".join(flow.when(language, anchors))),
                (
                    "required",
                    ", ".join(code(job) for job in flow.jobs if job in required),
                ),
                ("environments", ", ".join(map(code, flow.environments))),
                ("secrets", ", ".join(map(code, flow.secrets))),
                ("source", source),
            )
            lines += [f"- **{words[key]}:** {value}" for key, value in facts if value]
            lines.append("")
    return "\n".join(lines)


# ------------------------------------------------ the sitemaps and the map of the website

# The sitemap that the website hands to search engines, with an entry for each page.
SITEMAP_ENTRY = re.compile(r"\s*<url>\s*<loc>(?P<loc>[^<]*)</loc>.*?</url>", re.S)
SITEMAP_LINK = re.compile(r'\s*<xhtml:link\b[^>]*?\bhref="(?P<href>[^"]*)"[^>]*/>')
# The map of the website for AI assistants (https://llmstxt.org/), at its root; the
# section of the pages at the top of the menu; the folder of the decision records, at
# any depth, which are for maintainers and stay out of it; and what a link of the map
# can't hold, as its parsers take the first ] for the end of the name and the first )
# for the end of the address.
LLMS_TXT = "llms.txt"
LLMS_PAGES = "Pages"
DECISIONS = "decisions"
LINK_NAME = str.maketrans("[]", "()")
LINK_ADDRESS = str.maketrans({"(": "%28", ")": "%29"})
SENTENCE_ENDS = ".…!?"
# The sitemaps of the website: the plain one and the packed one.
SITEMAPS = ("sitemap.xml", "sitemap.xml.gz")
# The tag of the head of a page that no search engine lists.
NOINDEX = re.compile(
    r"<meta\b(?=[^>]*\bname=[\"']robots[\"'])[^>]*\bcontent=[\"'][^\"']*\bnoindex\b",
    re.I,
)
# The file that a folder of the website serves at its own address.
INDEX = "index.html"


@dataclass(frozen=True)
class Entry:
    """A page of a menu: its title, its path on the website, such as guide.html or
    de/guide.html, its full address and its description."""

    title: str
    url: str
    address: str
    description: str = ""


@dataclass(frozen=True)
class Section:
    """A section of a menu, with its pages and its sections."""

    title: str
    children: tuple[Item, ...] = ()


Item = Entry | Section


class Menu(NamedTuple):
    """The menu of the website in a language: the name and the description of the
    website in that language, and the items of its menu."""

    name: str
    summary: str | None
    items: tuple[Item, ...]


def map_of_website(
    menus: dict[str, Menu], default: str, further: dict[str, str], unlisted: set[str]
) -> str:
    """llms.txt (https://llmstxt.org/) of the website, in default, its language: its
    name, its description and a section for each part of its menu in menus, with the
    pages of that part. A link names the page of each language of further, by its
    name, that has a version of its own. A page at an address of unlisted, which no
    search engine lists, stays out."""
    menu = menus[default]
    lines = [f"# {one_line(menu.name)}", ""]
    if menu.summary:
        lines += [f"> {one_line(menu.summary)}", ""]
    translated = (
        f", each with its page in {' and '.join(further.values())} where there is one"
        if further
        else ""
    )
    lines += [f"The pages of this website, in the order of its menu{translated}.", ""]
    versions = {
        language: {page.url: page for page in pages_in(menus[language].items)}
        for language in further
    }
    for heading, pages in parts_of(menu.items):
        items = [
            map_item(page, further, versions, unlisted)
            for page in pages
            if page.address not in unlisted and DECISIONS not in page.url.split("/")
        ]
        if items:
            lines += [f"## {one_line(heading)}", "", *items, ""]
    return "\n".join(lines)


def parts_of(items: Sequence[Item]) -> list[tuple[str, list[Entry]]]:
    """The parts of a menu, each with its pages in the order of the menu: the pages at
    its top under LLMS_PAGES, and then each of its sections, with the pages of the
    sections inside it."""
    top = [item for item in items if isinstance(item, Entry)]
    sections = [item for item in items if isinstance(item, Section)]
    return [
        (LLMS_PAGES, top),
        *((section.title, pages_in(section.children)) for section in sections),
    ]


def pages_in(items: Sequence[Item]) -> list[Entry]:
    """The pages among items of a menu, and inside its sections, in the order of the
    menu."""
    pages: list[Entry] = []
    for item in items:
        if isinstance(item, Section):
            pages += pages_in(item.children)
        else:
            pages.append(item)
    return pages


def map_item(
    page: Entry,
    further: dict[str, str],
    versions: dict[str, dict[str, Entry]],
    unlisted: set[str],
) -> str:
    """The item of the map for page: its title, which links it, and after it its
    description and its version in each language of further, in versions by its path
    on the website, unless no search engine may list that version, by its address in
    unlisted: it shows the page untranslated, or its own front matter says noindex."""
    description = one_line(page.description)
    if description and description[-1] not in SENTENCE_ENDS:
        description += "."
    links = []
    for language, name in further.items():
        version = versions[language].get(f"{language}/{page.url}")
        if version is not None and version.address not in unlisted:
            links.append(f"{name}: {map_link(version)}")
    notes = " ".join(filter(None, (description, ". ".join(links))))
    return f"- {map_link(page)}: {notes}" if notes else f"- {map_link(page)}"


def map_link(page: Entry) -> str:
    """A link of the map to page, by its title and its full address."""
    address = page.address.translate(LINK_ADDRESS)
    return f"[{one_line(page.title)}]({address})"


def one_line(text: str) -> str:
    """text for the map: on one line, with a space between two words, and with
    parentheses for its brackets."""
    return " ".join(text.split()).translate(LINK_NAME)


def unlist_pages(site: Path, addresses: set[str]) -> None:
    """Take the pages at addresses out of both sitemaps of the website in site."""
    for name in SITEMAPS:
        unlist(site / name, addresses)


def unlist(path: Path, addresses: set[str]) -> None:
    """Take the pages at addresses out of a sitemap, sitemap.xml or the packed
    sitemap.xml.gz, and the links to them out of its other pages."""
    if not path.is_file():
        return

    def listed(address: str) -> bool:
        # A generator writes an address with a space or a letter beyond ASCII
        # percent-encoded, and noindexed reads it from the name of the file.
        address = html.unescape(address)
        return address in addresses or unquote(address) in addresses

    def keep(text: str) -> str:
        text = SITEMAP_ENTRY.sub(lambda m: "" if listed(m["loc"]) else m[0], text)
        return SITEMAP_LINK.sub(lambda m: "" if listed(m["href"]) else m[0], text)

    if path.suffix != ".gz":
        text = path.read_text(encoding="utf-8")
        path.write_text(keep(text), encoding="utf-8", newline="\n")
        return
    with gzip.GzipFile(path) as reader:
        text = reader.read().decode("utf-8")
        built = reader.mtime
    with gzip.GzipFile(path, "wb", mtime=built) as writer:
        writer.write(keep(text).encode("utf-8"))


def copy_sitemap(site: Path) -> None:
    """Copy the sitemap of a website in several languages into every folder of its
    pages, in which the language switch of Material asks for it, as
    decisions/sitemap.xml and de/decisions/sitemap.xml on
    decisions/0006-beta-channel.html."""
    sitemap = site / SITEMAPS[0]
    if not sitemap.is_file():
        return
    for folder in {page.parent for page in site.rglob("*.html")} - {site}:
        shutil.copyfile(sitemap, folder / SITEMAPS[0])


def noindexed(site: Path, base: str) -> set[str]:
    """The addresses of the pages of the website in site, whose address is base,
    that no search engine lists, by the tag robots with noindex in their head: a
    folder's index.html by both of its addresses, with and without its name."""
    base = base if base.endswith("/") else f"{base}/"
    found = set()
    for page in site.rglob("*.html"):
        head = page.read_text(encoding="utf-8").partition("</head>")[0]
        if NOINDEX.search(head):
            path = page.relative_to(site).as_posix()
            found.add(base + path)
            if path == INDEX or path.endswith(f"/{INDEX}"):
                found.add(base + path.removesuffix(INDEX))
    return found


def several_languages(site: Path) -> bool:
    """Whether the sitemap of the website in site names the versions of its pages in
    other languages, as that of a website in several languages does."""
    sitemap = site / SITEMAPS[0]
    return sitemap.is_file() and "hreflang=" in sitemap.read_text(encoding="utf-8")


# The menus of a website by their languages, its default language, and the names
# of its further languages, as read_menus reads them.
Menus = tuple[dict[str, Menu], str, dict[str, str]]


def read_menus(path: Path) -> Menus:
    """The menus of the website in the file path, by their languages, its default
    language, and the names of its further languages, from JSON such as

        {"default": "en", "languages": {"en": {"name": "Website", "summary": "…",
         "label": "English", "items": [{"title": "Home", "url": "index.html",
         "address": "https://…/index.html", "description": "…"},
         {"title": "Guides", "children": […]}]}}}
    """
    data = json.loads(path.read_text(encoding="utf-8"))
    default = str(data["default"])
    menus = {}
    further = {}
    for language, menu in data["languages"].items():
        summary = menu.get("summary")
        menus[language] = Menu(
            str(menu["name"]),
            None if summary is None else str(summary),
            items_of(menu.get("items", [])),
        )
        if language != default:
            further[language] = str(menu.get("label") or language)
    return menus, default, further


def items_of(items: list[dict[str, Any]]) -> tuple[Item, ...]:
    """The items of a menu in JSON: a section has children, a page an address."""
    return tuple(
        Section(str(item["title"]), items_of(item["children"]))
        if "children" in item
        else Entry(
            str(item["title"]),
            str(item["url"]),
            str(item["address"]),
            str(item.get("description") or ""),
        )
        for item in items
    )


def finish(site: Path, base: str, menus: Menus | None = None) -> list[str]:
    """Finish the website in site, whose address is base, after its build: take the
    pages that no search engine lists out of its sitemaps, copy the sitemap of a
    website in several languages into every folder of its pages, and write llms.txt
    from menus, as read_menus reads them, unless the website has one. What it did,
    one step on each line."""
    unlisted = noindexed(site, base)
    unlist_pages(site, unlisted)
    done = [
        f"{len(unlisted)} address(es) that no search engine lists left the sitemaps"
    ]
    if several_languages(site):
        copy_sitemap(site)
        done.append("the sitemap of a website in several languages is in every folder")
    target = site / LLMS_TXT
    if menus is not None and not target.exists():
        languages, default, further = menus
        target.write_text(
            map_of_website(languages, default, further, unlisted),
            encoding="utf-8",
            newline="\n",
        )
        done.append(f"{LLMS_TXT} maps the menu of {', '.join(languages)}")
    return done
