"""The hooks of the documentation website, which the blueprint keeps current.

.github/mkdocs-blueprint.yml names this file under hooks. A project with hooks of
its own lists this file there too, since its list replaces that of the blueprint.
https://github.com/Dennis-Otto/repo-blueprint
"""

from __future__ import annotations

import gzip
import html
import importlib.util
import json
import logging
import posixpath
import re
import shutil
import struct
import subprocess
import sys
import time
import tomllib
from collections import Counter
from pathlib import Path
from types import ModuleType
from typing import Any, NamedTuple
from urllib.parse import quote, unquote, urljoin, urlparse, urlsplit

import yaml

log = logging.getLogger("mkdocs.hooks.blueprint")

# The plugins of Material that download from other servers while the website is
# built: privacy the fonts and scripts that the pages use, social the fonts of the
# preview cards.
DOWNLOADING = ("material/privacy", "material/social")
# How often a download is tried before the plugin gets its error.
ATTEMPTS = 3
# The seconds that an attempt waits for the connection and then for each next part
# of the file, when the plugin names no time limit itself: the social plugin names
# none, so a server that stopped answering held the build until the CI ended it.
TIMEOUT = (10, 30)
# The stylesheet and the script of every website: the path where the website serves
# each, and the file beside this one that holds it.
ASSETS = {
    "assets/stylesheets/blueprint.css": "mkdocs-blueprint.css",
    "assets/javascripts/blueprint.js": "mkdocs-blueprint.js",
}
STYLESHEET, SCRIPT = ASSETS
# The Mermaid that draws a ```mermaid block of a project's page, which the website
# still draws live; a diagram that the pages show as its pictures needs none.
# Material asks unpkg.com for the newest release of a major version, which the
# privacy plugin downloads in every build, so that two builds of the same commit
# could draw the diagrams differently.
# The website loads this release instead, which Renovate keeps current within the
# major version that Material loads.
MERMAID = "https://unpkg.com/mermaid@11.17.2/dist/mermaid.min.js"
FLOATING = re.compile(
    r"https://unpkg\.com/mermaid@(?P<major>\d+)/dist/mermaid\.min\.js"
)
# The script of Material, which loads Mermaid.
BUNDLE = re.compile(r"assets/javascripts/bundle\.\w+\.min\.js")
# The tags of HTML that the hook reads; a value in quotes may hold a >.
TAG = r"(?:[^>\"']|\"[^\"]*\"|'[^']*')*"
IMAGE = re.compile(rf"<img\b{TAG}>", re.I)
SOURCE = re.compile(rf"<source\b{TAG}>", re.I)
PICTURE = re.compile(
    rf"<picture\b{TAG}>(?P<inside>(?:(?!</picture>).)*?)</picture>", re.S | re.I
)
ATTRIBUTE = re.compile(
    r"(?P<name>[^\s=/>\"']+)"
    r"(?:\s*=\s*(?:\"(?P<double>[^\"]*)\"|'(?P<single>[^']*)'|(?P<bare>[^\s>\"']+)))?"
)
# The endings of the address of a picture that Material shows in one scheme alone.
SCHEMES = ("#only-light", "#only-dark")
# A file of docs/ in a further language, such as images/diagrams/NAME.de.svg, which
# the i18n plugin serves for the pages of that language without its suffix, at
# de/images/diagrams/NAME.svg.
LOCALIZED = re.compile(
    r"(?P<path>.+)\.(?P<language>[a-z]{2}(?:[-_][A-Za-z]{2,4})*)(?P<ending>\.[^./]+)"
)
# The start of an SVG, whose root names its width and height, in the bytes that are
# read of it.
SVG_HEAD = 1024
SVG_ROOT = re.compile(rb"\s*(?:<\?xml[^>]*>\s*)?<svg\s[^>]*")
SVG_LENGTHS = tuple(
    re.compile(rb"\s" + name + rb"\s*=\s*([\"'])(?P<length>\d+(?:\.\d+)?)(?:px)?\1")
    for name in (b"width", b"height")
)
# The pictures beside a video, NAME.webp or NAME.png, that show until it plays.
POSTERS = (".webp", ".png")
# A picture of the repository that a page shows from main, as a README names it so
# that it shows wherever the README does, such as on PyPI or in the store of an app:
# github.com/OWNER/NAME/raw/main/PATH, its blob/main/PATH?raw=true, and
# raw.githubusercontent.com/OWNER/NAME/main/PATH, also from HEAD or refs/heads/main.
# The website serves it from the checkout, in this folder, so that a pull request
# that adds a screenshot shows it on the website too: the privacy plugin would
# download it from main, which doesn't have it before the merge.
CHECKOUT = "assets/images/repository"
BRANCHES = ("main", "HEAD")
# Whether git tracks a file, which main then has once the change is merged: git
# lists the path that follows, as it is, or nothing.
TRACKED = ("git", "--literal-pathspecs", "ls-files", "-z", "--")
# The characters of a path that its address on the website keeps as they are.
UNQUOTED = "/@+:"
# The diagrams that a page still draws live, from a ```mermaid block, whose sources
# the search leaves out: a search for "token" would show "flowchart LR admin([..."
# as its text.
MERMAID_BLOCK = re.compile(r'<pre class="mermaid"(?=>)')
# The badges of a README, such as those of shields.io, of a workflow or of the
# Scorecard: the website shows none. The privacy plugin would download each once,
# as the build sees it, so the website would show a status that is long gone. A
# button whose address names badges, such as those of My Home Assistant at
# .../badges/hacs_repository.svg, shows no status and stays.
BADGE = re.compile(
    r"https?://(?:img\.shields\.io/|api\.reuse\.software/badge/"
    r"|[^\s\"'<>]*/badge(?:\.svg)?(?=$|[?#]))",
    re.I,
)
LINKED_IMAGE = re.compile(rf"<a\b{TAG}>\s*(?P<image><img\b{TAG}>)\s*</a>", re.I)
EMPTY = re.compile(rf"<p\b{TAG}>\s*</p>\n?")
# The first paragraph of a page, from which a page without a description in its
# front matter takes the description that search engines and preview cards show, of
# DESCRIPTION characters at most. A paragraph of links alone, such as the line that
# leads to the version in another language, describes nothing.
LINK = re.compile(r"<a\b[^>]*>.*?</a>", re.S)
PARAGRAPH = re.compile(r"<p>(?P<inside>(?:(?!</p>).)*?)</p>", re.S)
DESCRIPTION = 160

# The pages that a website in several languages, and any other, keeps out of the
# search and out of search engines: the template of a decision record, which is
# there to be copied, and a page with robots: noindex in its front matter. A page
# that a further language shows in the default one, untranslated, is the same page
# as that one, which search engines list and the search finds instead.
TEMPLATES = ("decisions/0000-template.md",)
NOINDEX = '<meta name="robots" content="noindex, follow">'
# The links in the head of a page, such as those to its versions in other languages.
HEAD_LINK = re.compile(rf"<link\b{TAG}>", re.I)
# The names of the switch between light and dark in a further language, for those
# of the palette of .github/mkdocs-blueprint.yml.
TOGGLES = {
    "de": {
        "Switch to light mode": "Zum hellen Farbschema wechseln",
        "Switch to dark mode": "Zum dunklen Farbschema wechseln",
        "Switch to the scheme of the system": "Zum Farbschema des Systems wechseln",
    },
}
ENGLISH = {
    name: english for names in TOGGLES.values() for english, name in names.items()
}
# The search index of every language, which the search plugin writes, and beside it
# that of each language, which the hooks write: search/search_index.de.json.
SEARCH_INDEX = "search/search_index.json"
# The script that gives the search of a page the index of its language. Material
# asks for every, search/search_index.json from the folder of the website, before
# the scripts of the website run; the script in the head of the page asks for own,
# the index of the page's language, in its place, and leaves every other request as
# it is.
SEARCH_SCRIPT = (
    "<script>(function(){{"
    "var a=new URL({every},location.href).href,b=new URL({own},location.href).href,"
    "o=XMLHttpRequest.prototype.open;"
    "XMLHttpRequest.prototype.open=function(m,u){{var r=[].slice.call(arguments);"
    "if(new URL(String(u),location.href).href===a)r[1]=b;return o.apply(this,r)}}"
    "}})()</script>"
)
# What the scripts of Material hold while they ask for the index in the way that
# SEARCH_SCRIPT redirects.
SEARCH_REQUEST = ("XMLHttpRequest", "search_index.json")
# The sitemap that the website hands to search engines, with an entry for each page.
SITEMAP_ENTRY = re.compile(r"\s*<url>\s*<loc>(?P<loc>[^<]*)</loc>.*?</url>", re.S)
SITEMAP_LINK = re.compile(r'\s*<xhtml:link\b[^>]*?\bhref="(?P<href>[^"]*)"[^>]*/>')
# A line of a page that includes a file of the repository, --8<-- "README.md", or a
# part of one, "README.md:loop"; a line --8<-- alone starts and ends a block with
# one file on each line.
SNIPPET = re.compile(
    r"[ \t]*-+8<-+[ \t]*(?:(?P<quote>[\"'])(?P<name>.+?)(?P=quote))?[ \t]*"
)


class Retrying:
    """The requests of a plugin, whose get tries a download again, after a second
    and then two, when the network or the server fails, and gives up on an attempt
    after TIMEOUT unless the plugin names its own time limit."""

    def __init__(self, requests: Any) -> None:
        self.requests = requests

    def __getattr__(self, name: str) -> Any:
        return getattr(self.requests, name)

    def get(self, url: str, **kwargs: Any) -> Any:
        kwargs.setdefault("timeout", TIMEOUT)
        transient = (self.requests.ConnectionError, self.requests.Timeout)
        for attempt in range(1, ATTEMPTS):
            try:
                response = self.requests.get(url, **kwargs)
            except transient:
                pass
            else:
                if response.status_code < 500:
                    return response
            time.sleep(attempt)
        return self.requests.get(url, **kwargs)


def on_config(config: Any) -> None:
    """Let the plugins of Material try a download again before they give up, give
    every page the stylesheet and the script of the blueprint, the links to the
    owner in the footer and the version of the latest release in the header, and
    put the page of the automation into the menu.

    The plugins try every file once: the privacy plugin warns when a font of Google
    Fonts doesn't come within 5 seconds, which fails a strict build, and the social
    plugin fails on any error of the network and waits without end for a server that
    stopped answering. The stylesheet and the script come
    before those of the project, whose extra_css replaces the list of the blueprint
    and whose rules win over them. The menu of mkdocs.yml belongs to the project, so
    the page comes after the security design there, unless the menu names it.
    """
    for name in DOWNLOADING:
        plugin = config.plugins.get(name)
        if plugin is None:
            continue
        module: Any = sys.modules[type(plugin).__module__]
        if not isinstance(module.requests, Retrying):
            module.requests = Retrying(module.requests)
    for path, listed in (
        (STYLESHEET, config.extra_css),
        (SCRIPT, config.extra_javascript),
    ):
        if path not in map(str, listed):
            listed.insert(0, path)
    found = languages(config)
    if found is not None:
        name_the_toggles(config.theme, found.current)
    link_the_owner(config, found)
    # The link to the repository shows its version; one to the profile of the
    # owner, as on a personal website, shows none of the website's.
    root = Path(config.config_file_path).parent
    repository = urlparse(config.repo_url or "").path.strip("/").count("/") == 1
    BUILT.version = newest_release(root) if repository else ""
    if (
        isinstance(config.nav, list)
        and automated(config) is not None
        and not listed_in(config.nav, AUTOMATION)
    ):
        add_to_menu(config.nav, AUTOMATION)


class Languages(NamedTuple):
    """The languages of a website in several: the default one, the further ones that
    the i18n plugin builds, and the one that it builds now."""

    default: str
    further: tuple[str, ...]
    current: str

    def of(self, location: str) -> str:
        """The language of a page by its path on the website: a further one for
        de/guide.html, and for homedarts-for-autodarts/de/games.html, a page of a
        project website that the website of its owner adds to its search; the
        default one for every other page."""
        parts = location.split("/")
        if parts[0] in self.further:
            return parts[0]
        if len(parts) > 2 and parts[1] in self.further:
            return parts[1]
        return self.default

    def versions(self, location: str, language: str) -> tuple[str, ...]:
        """The paths where the version in language of the page of the default
        language at location would be: de/guide.html for guide.html, and also
        homedarts-for-autodarts/de/games.html for
        homedarts-for-autodarts/games.html."""
        head, slash, rest = location.partition("/")
        nested = (f"{head}/{language}/{rest}",) if slash else ()
        return (f"{language}/{location}", *nested)


def languages(config: Any) -> Languages | None:
    """The languages of the website, or None for a website in one language."""
    plugin = config.plugins.get("i18n")
    if plugin is None:
        return None
    default = ""
    further = []
    for language in plugin.config.languages:
        if language.default:
            default = language.locale
        elif language.build:
            further.append(language.locale)
    if not further:
        return None
    return Languages(default, tuple(further), plugin.current_language or default)


def name_the_toggles(theme: Any, language: str) -> None:
    """Name the switch between light and dark in the language of the build.

    The palette of .github/mkdocs-blueprint.yml names it in English, which the
    German pages showed as well. The i18n plugin sets the palette of a language
    from its theme in mkdocs.yml afterwards, so a name there wins over these.
    """
    palette = theme.get("palette")
    for option in palette if isinstance(palette, list) else []:
        toggle = option.get("toggle") or {}
        if "name" in toggle:
            english = ENGLISH.get(toggle["name"], toggle["name"])
            toggle["name"] = TOGGLES.get(language, {}).get(english, english)


# ------------------------------------------------- the header and the footer of a page

# The links in the footer of every page, which lead to the owner of the repository:
# their profile on GitHub, their own website with all of their projects, for a
# website under it, such as https://owner.github.io/demo/ under
# https://owner.github.io/, and GitHub Sponsors, when .github/FUNDING.yml names an
# account there, as the sponsor button of the repository does. Their names, which
# a screen reader reads and a pointer shows, in each language that has words here;
# every other one takes the English ones.
FUNDING = ".github/FUNDING.yml"
ACCOUNT = re.compile(r"[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?")
OWNER_LINKS = {
    "en": {
        "profile": "{owner} on GitHub",
        "projects": "All projects of {owner}",
        "sponsor": "Sponsor {account}",
    },
    "de": {
        "profile": "{owner} auf GitHub",
        "projects": "Alle Projekte von {owner}",
        "sponsor": "{account} sponsern",
    },
}
# The link to the repository in the header and in the menu of a phone. The script of
# Material fills a link with this attribute with the numbers of the repository, its
# latest release, stars and forks, which the browser of every reader asked the API
# of GitHub for, once in each session: that failed on a website without a release,
# and a network that many share hit the limit of the API. The hooks take the
# attribute away and write the version of the latest release into the link, read
# from git at the build.
SOURCE_LINK = re.compile(
    r'(?P<link><a\b[^>]*?\bclass="md-source")\s+data-md-component="source"'
    r'(?P<name>[^>]*>.*?<div class="md-source__repository">.*?)(?=\s*</div>)',
    re.S,
)
# A stable version as release-please writes it, such as 1.2.0 and not 1.3.0-beta.1.
STABLE = re.compile(r"\d+\.\d+\.\d+")
# The newest stable release before the commit of the build, such as v1.2.0 and not
# v1.3.0-beta.1, by the tag that git describes it with.
NEWEST_RELEASE = (
    "git",
    "describe",
    "--tags",
    "--abbrev=0",
    "--match",
    "v[0-9]*",
    "--exclude",
    "*-*",
)


def link_the_owner(config: Any, found: Languages | None) -> None:
    """Give the footer of every page the links to the owner of the repository, in
    the language of the build.

    A list extra.social of mkdocs.yml, also an empty one, takes their place, since
    INHERIT replaces every list. The i18n plugin builds every further language with
    the configuration of the build before, so a list that the hooks gave it before
    is theirs to name anew.
    """
    social = config.extra.get("social")
    if social is not None and social != BUILT.social:
        return
    language = found.current if found else str(config.theme.get("language"))
    BUILT.social = owner_links(config, OWNER_LINKS.get(language, OWNER_LINKS["en"]))
    if BUILT.social:
        config.extra["social"] = BUILT.social


def owner_links(config: Any, names: dict[str, str]) -> list[dict[str, str]]:
    """The links to the owner, with their names: none for a website whose
    repository is not on GitHub."""
    owner = owner_of(config)
    if not owner:
        return []
    links = [
        {
            "icon": "fontawesome/brands/github",
            "link": f"https://github.com/{owner}",
            "name": names["profile"].format(owner=owner),
        }
    ]
    hub = f"{owner.lower()}.github.io"
    address = urlparse(config.site_url or "")
    if address.netloc.lower() == hub and address.path.strip("/"):
        links.append(
            {
                "icon": "material/view-grid-outline",
                "link": f"https://{hub}/",
                "name": names["projects"].format(owner=owner),
            }
        )
    account = sponsored(Path(config.config_file_path).parent / FUNDING)
    if account:
        links.append(
            {
                "icon": "octicons/heart-16",
                "link": f"https://github.com/sponsors/{account}",
                "name": names["sponsor"].format(account=account),
            }
        )
    return links


def owner_of(config: Any) -> str:
    """The owner of the website's repository on GitHub, from repo_url, also the
    profile of the owner on a personal website, or from an absolute edit_uri; ""
    for a repository elsewhere."""
    for value in (config.repo_url, config.edit_uri):
        address = urlparse(value or "")
        owner = address.path.strip("/").partition("/")[0]
        if (
            address.scheme in ("http", "https")
            and address.netloc == "github.com"
            and owner
        ):
            return owner
    return ""


def sponsored(path: Path) -> str:
    """The first account of GitHub Sponsors that the FUNDING.yml at path names, or
    "" when it names none or can't be read."""
    # One except for each, as a tuple of them would read differently to Ruff for
    # Python 3.14, which a Home Assistant project targets, than for Python 3.12.
    try:
        text = path.read_text(encoding="utf-8")
    except OSError:
        return ""
    try:
        funding = yaml.safe_load(text)
    except yaml.YAMLError:
        return ""
    accounts = funding.get("github") if isinstance(funding, dict) else None
    if not isinstance(accounts, list):
        accounts = [accounts]
    first = accounts[0] if accounts else None
    return first if isinstance(first, str) and ACCOUNT.fullmatch(first) else ""


def released(root: Path) -> str:
    """The tag of the stable version that release-please wrote to the manifest at
    root, such as v1.2.0, or "" for none, the first 0.0.0 or one it can't read.

    The commit of a release already has its version there, while the workflow
    of the release only signs and pushes its tag a while after the build starts.
    """
    try:
        manifest = json.loads(
            (root / ".release-please-manifest.json").read_text(encoding="utf-8")
        )
    except OSError:
        return ""
    except ValueError:
        return ""
    version = manifest.get(".") if isinstance(manifest, dict) else None
    if isinstance(version, str) and STABLE.fullmatch(version) and version != "0.0.0":
        return f"v{version}"
    return ""


def newest_release(root: Path) -> str:
    """The tag of the newest stable release in the repository at root, such as
    v1.2.0, or "" without one, without the history or without git: the version
    of release-please's manifest, else the newest stable tag.

    The Docs workflow checks the repository out with its whole history and tags.
    """
    manifested = released(root)
    if manifested:
        return manifested
    try:
        described = subprocess.run(
            NEWEST_RELEASE,
            cwd=root,
            capture_output=True,
            check=False,
            text=True,
            encoding="utf-8",
        )
    except OSError:
        return ""
    return described.stdout.strip() if described.returncode == 0 else ""


def source_facts(output: str) -> str:
    """The link to the repository in the header and in the menu of a phone, without
    the attribute that has the script of Material ask GitHub for its numbers, and
    with the version of the latest release, as that script would show it."""
    facts = ""
    if BUILT.version:
        facts = (
            '<ul class="md-source__facts">'
            '<li class="md-source__fact md-source__fact--version">'
            f"{html.escape(BUILT.version)}</li></ul>"
        )
    return SOURCE_LINK.sub(lambda match: match["link"] + match["name"] + facts, output)


def on_files(files: Any, config: Any) -> Any:
    """Serve the stylesheet and the script of the blueprint, pin Mermaid, and add the
    page of the automation.

    Both are files beside this one, which the i18n plugin leaves in place as files
    of the theme. The script of Material that loads Mermaid comes from a copy in
    .cache/ that names the release of MERMAID: the privacy plugin, which runs after
    this hook, downloads that one for the website. The page of the automation, and
    its German version on a website in German, are files of docs/ that the folder
    doesn't hold: the i18n plugin, which runs after this hook too, takes them for
    pages of their languages, and on_nav gives them their text.
    """
    here = Path(__file__).resolve().parent
    kind = type(next(iter(files)))
    for file in files:
        if BUNDLE.fullmatch(file.src_uri) and file.abs_src_path:
            pin(file, Path(config.docs_dir).parent / ".cache/plugin/blueprint")
    for destination, name in ASSETS.items():
        files.append(
            kind(
                name,
                str(here),
                config.site_dir,
                config.use_directory_urls,
                dest_uri=destination,
            )
        )
    folder = automated(config)
    BUILT.pages = {} if folder is None else automation_pages(folder, config)
    for path in BUILT.pages:
        files.append(
            kind(path, config.docs_dir, config.site_dir, config.use_directory_urls)
        )
    for picture in BUILT.pictures:
        files.append(
            kind(
                picture.name,
                str(picture.parent),
                config.site_dir,
                config.use_directory_urls,
                dest_uri=f"{SERVED}/{picture.name}",
            )
        )
    return files


def pin(file: Any, cache: Path) -> None:
    """Point the script of Material at a copy of it that loads MERMAID.

    A Material that loads another major version of Mermaid, or no longer from
    unpkg.com, warns, which fails a strict build, so that MERMAID follows it.
    """
    script = Path(file.abs_src_path).read_text(encoding="utf-8")
    found = FLOATING.search(script)
    if found is None:
        log.warning(
            "Material no longer loads Mermaid from unpkg.com, so the website can't "
            "pin it: change MERMAID in .github/mkdocs_blueprint.py to follow it."
        )
        return
    major = MERMAID.split("@")[1].split(".")[0]
    if found["major"] != major:
        log.warning(
            f"Material loads Mermaid {found['major']}, and the website pins {MERMAID}: "
            f"change MERMAID in .github/mkdocs_blueprint.py to a release of Mermaid "
            f"{found['major']}."
        )
        return
    copy = cache / file.src_uri
    copy.parent.mkdir(parents=True, exist_ok=True)
    copy.write_text(FLOATING.sub(MERMAID, script), encoding="utf-8", newline="")
    file.abs_src_path = str(copy)


# The changelog that a page includes, and the folder beside it with the fragments, in
# which pull requests describe their changes until a release takes them in.
CHANGELOG = "CHANGELOG.md"
FRAGMENTS = "changelog.d"
# The code of blueprint.py that reads the changelog and its fragments, by its path
# from this file: beside it in a repository made from the blueprint, and at the root
# of the blueprint itself.
CHANGELOG_CODE = ("blueprint/changelog.py", "../blueprint/changelog.py")


class Built:
    """What the builds of every language found, for the steps after the last one:
    the paths of the pages that a further language shows untranslated, such as
    de/roadmap.html, and the addresses of the pages that no search engine lists;
    the pages that the hooks write, such as the page of the automation, with
    the text of each by its path in docs/, the files that they are made of and the
    pictures beside this file that they show; the pictures of each page that
    on_page_content left, by its path on the website, each by the name of its file;
    the links in the footer that the hooks gave the build before, None until they
    did; and the version of the latest release, which the header shows.

    The i18n plugin builds every further language within the build of the default
    one, and MkDocs loads the hooks anew for each build of mkdocs serve.
    """

    def __init__(self) -> None:
        self.untranslated: set[str] = set()
        self.unlisted: set[str] = set()
        self.pages: dict[str, str] = {}
        self.sources: list[Path] = []
        self.pictures: list[Path] = []
        self.images: dict[str, list[str]] = {}
        self.social: list[dict[str, str]] | None = None
        self.version = ""


BUILT = Built()


def untranslated(page: Any, config: Any) -> bool:
    """Whether a further language shows this page in the default one: the i18n
    plugin builds it from the file of the default language."""
    found = languages(config)
    if found is None:
        return False
    return bool(getattr(page.file, "locale", found.current) != found.current)


def unlisted(page: Any, config: Any) -> bool:
    """Whether the search and search engines leave the page out: the template of a
    decision record, a page with robots: noindex in its front matter, and a page
    that a further language shows untranslated."""
    robots = str(page.meta.get("robots", "")).lower()
    return (
        page.file.src_uri in TEMPLATES
        or "noindex" in robots
        or untranslated(page, config)
    )


def on_page_markdown(markdown: str, page: Any, config: Any, files: Any) -> str:
    """Leave the pages that nobody looks for out of the search, and date a page by
    the newest of its files.

    It runs after the plugin git-revision-date-localized, which dates the page by
    its own file, and before the search plugin, which reads the front matter.
    """
    if unlisted(page, config):
        page.meta["search"] = {**(page.meta.get("search") or {}), "exclude": True}
        BUILT.unlisted.add(page.canonical_url)
        if untranslated(page, config):
            BUILT.untranslated.add(page.url)
    if config.plugins.get("material/social") and page.file.dest_uri in homes(config):
        # The preview card of a home page took its title from the menu: Home. The
        # social plugin draws it after this hook.
        social = page.meta.setdefault("social", {})
        social.setdefault("cards_layout_options", {}).setdefault(
            "title", config.site_name
        )
    redate(markdown, page, config)
    # The sitemap tells search engines when a page changed last; MkDocs gives it the
    # date of the build.
    changed = page.meta.get("git_revision_date_localized_raw_iso_date")
    if changed:
        page.update_date = changed
    return with_fragments(markdown, config)


# After the plugin git-revision-date-localized.
on_page_markdown.mkdocs_priority = -10  # type: ignore[attr-defined]


def redate(markdown: str, page: Any, config: Any) -> None:
    """Date a page that includes files of the repository by the newest of them, a
    page that the hooks write by the newest of the files that it is made of, and an
    untranslated page in the language of its menu.

    The guide includes README.md, and the changelog CHANGELOG.md, with a line
    --8<--, so their own files hardly ever change, and the plugin dated them by
    those: the changelog showed the date of its first release. A page that the
    hooks write has no file of its own, and the plugin dated it by the build. The
    plugin writes the date in the language of the file, which for an untranslated
    page is not that of the words around it, such as "Letzte Aktualisierung".
    """
    plugin = config.plugins.get("git-revision-date-localized")
    if plugin is None or "git_revision_date_localized" not in page.meta:
        return
    paths = included(markdown, snippet_bases(config))
    # The changelog shows the fragments beside it too.
    paths += [
        fragment
        for path in paths
        if path.name == CHANGELOG
        for fragment in sorted((path.parent / FRAGMENTS).glob("[!.]*.md"))
    ]
    own = [Path(page.file.abs_src_path)] if page.file.abs_src_path else []
    if not own and page.file.src_uri in BUILT.pages:
        paths += BUILT.sources
    found = languages(config)
    copy = untranslated(page, config)
    if not paths and not (copy and own):
        return
    newest = max(
        (
            plugin.util.get_git_commit_timestamp(path=str(path), is_first_commit=False)
            for path in [*own, *paths]
        ),
        key=lambda commit: commit[1],
    )
    locale = found.current if found and copy else plugin._get_locale(page)
    plugin._render_date(markdown, page, locale, "git_revision_date_localized", *newest)


def snippet_bases(config: Any) -> list[Path]:
    """The folders in which a line --8<-- finds its file, as the extension
    pymdownx.snippets reads them from its base_path: the root of the repository, unless
    the configuration names others."""
    root = Path(config.config_file_path).parent
    bases = config.mdx_configs.get("pymdownx.snippets", {}).get("base_path", ".")
    return [root / base for base in ([bases] if isinstance(bases, str) else bases)]


def with_fragments(markdown: str, config: Any) -> str:
    """The markdown with the fragments of the changelog under Unreleased.

    A line --8<-- that includes CHANGELOG.md, whose folder has fragments in
    changelog.d/, takes in its place the changelog as the next release will have it:
    the entries of the fragments joined to the text of Unreleased, each kind of change
    under one heading, as the release bot groups them with blueprint.py. Before, the
    website showed only the text of Unreleased until a release, which pull requests
    no longer write. A changelog without fragments stays a line --8<--, and so does a
    part of one, such as CHANGELOG.md:10:20, and every changelog when no blueprint.py
    is beside this file.
    """
    if CHANGELOG not in markdown:
        return markdown
    code = changelog_code()
    if code is None:
        return markdown
    bases = snippet_bases(config)
    lines = []
    for line in markdown.splitlines(keepends=True):
        match = SNIPPET.fullmatch(line.rstrip("\r\n"))
        name = match["name"] if match else None
        path = next(
            (base / name for base in bases if name and (base / name).is_file()), None
        )
        text = (
            path.read_text(encoding="utf-8") if path and path.name == CHANGELOG else ""
        )
        folded = (
            code.with_fragments(text, code.read_fragments(path.parent / FRAGMENTS))
            if path and text
            else text
        )
        if folded == text:
            lines.append(line)
            continue
        # A line indented in a list includes the file indented as well.
        indent = line[: len(line) - len(line.lstrip(" \t"))]
        lines += [
            indent + part if part.strip() else part
            for part in folded.splitlines(keepends=True)
        ]
    return "".join(lines)


def changelog_code() -> ModuleType | None:
    """blueprint/changelog.py, the code of blueprint.py that reads the changelog and its
    fragments as the release bot does, loaded from its file, which needs nothing but
    Python; None without the file."""
    here = Path(__file__).resolve().parent
    path = next(
        (here / name for name in CHANGELOG_CODE if (here / name).is_file()), None
    )
    if path is None:
        return None
    spec = importlib.util.spec_from_file_location("blueprint_changelog", path)
    # A file of Python always has a spec, with its loader.
    assert spec is not None
    assert spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def included(
    markdown: str, bases: list[Path], seen: set[Path] | None = None
) -> list[Path]:
    """The files that the lines --8<-- of markdown include, and those that they
    include in turn, each found in the first folder of bases that has it, as the
    extension pymdownx.snippets finds them. A part of a file, README.md:loop, is
    that file; an address or a file that is missing is none."""
    seen = set() if seen is None else seen
    names = []
    block = False
    for line in markdown.splitlines():
        match = SNIPPET.fullmatch(line)
        if match and match["name"]:
            names.append(match["name"])
        elif match:
            block = not block
        elif block and line.strip() and not line.lstrip().startswith(";"):
            names.append(line.strip())
    found = []
    for name in names:
        candidates = (base / name.split(":")[0] for base in bases)
        path = next((path for path in candidates if path.is_file()), None)
        if path is not None and path not in seen:
            seen.add(path)
            found.append(path)
            found += included(path.read_text(encoding="utf-8"), bases, seen)
    return found


def on_page_content(html_text: str, page: Any, config: Any, files: Any) -> str:
    """Fit a page to the website: the diagrams of its ```mermaid blocks, which the
    search leaves out, the pictures that it shows from main of its own repository
    from the checkout, pictures that follow the scheme the reader picks, such as
    those of its diagrams, videos in place of GIFs,
    pictures that load as the reader comes to them, no badges, a description of its
    own, and the language of an untranslated page.

    It runs before the plugin glightbox, which then wraps each picture that is left
    in a link that opens it enlarged, among the pictures of the reader's scheme.
    """
    docs = Path(config.docs_dir)
    where = Where(
        posixpath.dirname(page.file.dest_uri),
        posixpath.dirname(page.file.src_uri),
        docs.name,
        {file.dest_uri: file for file in files},
    )
    html_text = MERMAID_BLOCK.sub(r"\g<0> data-search-exclude", html_text)
    html_text = without_badges(html_text)
    html_text = from_checkout(html_text, page, config, files, where)
    html_text = PICTURE.sub(lambda m: themed(m, where), html_text)
    html_text = IMAGE.sub(lambda m: video(m[0], where), html_text)
    html_text = lazy(html_text, where)
    describe(page, html_text, config)
    found = languages(config)
    if found is not None and untranslated(page, config):
        # The text is in the default language, the menu and the header in that of
        # the page; a screen reader reads each in its own (WCAG 3.1.2).
        html_text = f'<div lang="{found.default}">\n{html_text}\n</div>'
    BUILT.images[page.file.dest_uri] = picture_files(html_text)
    return html_text


# Before the plugin glightbox.
on_page_content.mkdocs_priority = 10  # type: ignore[attr-defined]


def attributes(tag: str) -> dict[str, str | None]:
    """The attributes of a tag of HTML by their names, each with its value as the
    tag has it; None for one without a value, such as controls."""
    inside = re.sub(r"^<[\w-]+|/?>$", "", tag)
    found: dict[str, str | None] = {}
    for match in ATTRIBUTE.finditer(inside):
        values = (match["double"], match["single"], match["bare"])
        found[match["name"].lower()] = next((v for v in values if v is not None), None)
    return found


def element(name: str, attrs: dict[str, str | None]) -> str:
    """The opening tag of an element of HTML with these attributes."""
    parts = [name]
    for key, value in attrs.items():
        parts.append(
            key if value is None else f'{key}="{value.replace(chr(34), "&quot;")}"'
        )
    return f"<{' '.join(parts)}>"


def local(
    src: str, folder: str, found: dict[str, Any], *, moved: bool = False
) -> str | None:
    """The path on the website of the file that src names from a page in folder,
    when the website has that file; with moved, of the file in a further language
    that the i18n plugin serves without its suffix, such as images/NAME.de.svg at
    de/images/NAME.svg."""
    path = src.split("#")[0].split("?")[0]
    if not path or re.match(r"[a-z][a-z0-9+.-]*:|/", path, re.I):
        return None
    target = posixpath.normpath(posixpath.join(folder, path))
    localized = LOCALIZED.fullmatch(target) if moved else None
    if localized is not None:
        target = f"{localized['language']}/{localized['path']}{localized['ending']}"
    return target if target in found else None


def relative(target: str, folder: str) -> str:
    """The address of a file of the website from a page in folder."""
    return posixpath.relpath(target, folder or ".")


def repository_of(config: Any) -> str:
    """The repository of the website on GitHub, OWNER/NAME, from repo_url; "" for a
    repository elsewhere or none."""
    address = urlsplit(config.repo_url or "")
    name = address.path.strip("/").removesuffix(".git")
    if address.scheme in ("http", "https") and address.hostname == "github.com":
        return name if name.count("/") == 1 else ""
    return ""


def path_on_main(url: str, config: Any) -> str | None:
    """The path in the repository of the file that url shows from main of the
    website's own repository on GitHub, such as screenshots/settings.png; None for
    any other address, and for a path out of the repository. url is as the tag of
    HTML has it, such as with &amp; for &."""
    address = urlsplit(html.unescape(url))
    segments = address.path.split("/")[1:]
    if address.scheme != "https":
        return None
    if address.hostname == "github.com" and segments[2:3] == ["raw"]:
        rest = segments[3:]
    elif address.hostname == "github.com" and segments[2:3] == ["blob"]:
        rest = segments[3:] if "raw=true" in address.query.split("&") else []
    elif address.hostname == "raw.githubusercontent.com":
        rest = segments[2:]
    else:
        return None
    repository = repository_of(config)
    if not repository or "/".join(segments[:2]).lower() != repository.lower():
        return None
    if rest[:2] == ["refs", "heads"]:
        rest = rest[2:]
    if len(rest) < 2 or rest[0] not in BRANCHES:
        return None
    path = posixpath.normpath(unquote("/".join(rest[1:])))
    return None if path in (".", "..") or path.startswith(("../", "/")) else path


def tracked(root: Path, path: str) -> bool | None:
    """Whether git tracks the file at path in the repository at root, so that main
    has it once the change is merged; None without git or outside a repository,
    where nothing can tell."""
    try:
        listed = subprocess.run(
            (*TRACKED, path),
            cwd=root,
            capture_output=True,
            check=False,
            text=True,
            encoding="utf-8",
        )
    except OSError:
        return None
    return bool(listed.stdout) if listed.returncode == 0 else None


def servable(root: Path, path: str) -> tuple[bool | None, bool]:
    """Whether git tracks path in the repository at root, as tracked() tells, and
    whether the website may serve it: a file that git tracks, inside the
    repository, so that no symbolic link publishes a file from outside it."""
    known = tracked(root, path)
    source = (root / path).resolve()
    inside = source.is_file() and source.is_relative_to(root.resolve())
    return known, bool(known) and inside


def serve(path: str, root: Path, config: Any, files: Any, where: Where) -> None:
    """Serve the file at path in the repository at root on the website, in CHECKOUT,
    with the video and the posters beside a GIF that git tracks, which the website
    shows in its place.

    Each file of the website is named by its path there, so that it takes the
    place of no file of docs/, whose path in docs/ may be the same.
    """
    kind = type(next(iter(files)))
    names = [path]
    if path.lower().endswith(".gif"):
        stem = path[: -len(".gif")]
        beside = (f"{stem}{ending}" for ending in (".mp4", *POSTERS))
        names += [name for name in beside if servable(root, name)[1]]
    for name in names:
        file = kind(
            f"{CHECKOUT}/{name}", str(root), config.site_dir, config.use_directory_urls
        )
        file.abs_src_path = str(root / name)
        files.append(file)
        where.found[file.dest_uri] = file


def checked_out(url: str, page: Any, config: Any, files: Any, where: Where) -> str:
    """The address from the page of the picture that url shows from main of the
    website's own repository, as the website serves it from the checkout: a file
    of docs/ as the website has it, any other one in CHECKOUT, once git tracks it.

    url stays as it is for any other picture, and without git, which the privacy
    plugin then downloads; one that git doesn't track warns, which fails a strict
    build, as main would lack it once the change is merged.
    """
    path = path_on_main(url, config)
    if path is None:
        return url
    target = where.find(path) if path.startswith(f"{where.docs}/") else None
    if target is None:
        target = f"{CHECKOUT}/{path}"
    if target not in where.found:
        root = Path(config.config_file_path).parent
        known, allowed = servable(root, path)
        if known is None:
            return url
        if not allowed:
            log.warning(
                f"{page.file.src_uri} shows {url}, a file that git doesn't track in "
                "the repository, so that the picture would be missing on main: add "
                "it with git add, or correct its address."
            )
            return url
        serve(path, root, config, files, where)
    fragment = urlsplit(url).fragment
    address = quote(relative(target, where.folder), safe=UNQUOTED)
    return address + (f"#{fragment}" if fragment else "")


def from_checkout(
    html_text: str, page: Any, config: Any, files: Any, where: Where
) -> str:
    """Show the pictures that the page shows from main of its own repository, in
    <img> and in each candidate of the srcset of a <source>, from the checkout."""

    def candidates(srcset: str) -> str:
        shown = []
        for candidate in srcset.split(","):
            words = candidate.split()
            if words:
                words[0] = checked_out(words[0], page, config, files, where)
            shown.append(" ".join(words))
        return ", ".join(shown)

    def tag(match: re.Match[str], name: str, key: str) -> str:
        attrs = attributes(match[0])
        value = attrs.get(key)
        if not value:
            return match[0]
        changed = (
            candidates(value)
            if key == "srcset"
            else checked_out(value, page, config, files, where)
        )
        if changed == value:
            return match[0]
        return element(name, {**attrs, key: changed})

    html_text = IMAGE.sub(lambda m: tag(m, "img", "src"), html_text)
    return SOURCE.sub(lambda m: tag(m, "source", "srcset"), html_text)


class Where(NamedTuple):
    """Where a page is: its folder on the website and that of its source, the name
    of the folder of the sources, and the files of the website by their paths."""

    folder: str
    source: str
    docs: str
    found: dict[str, Any]

    def find(self, src: str) -> str | None:
        """The path on the website of the file that src names, when the website has
        that file.

        MkDocs leads the path of a picture of Markdown from the page on the website,
        but leaves that of HTML as the source names it: from the folder of its
        source, which for guide.de.md in de/ is not the folder of the page, or from
        the root of the repository, as a README names docs/images/NAME.png. A
        picture in a further language, docs/images/NAME.de.png, is the one that the
        i18n plugin serves for the pages of that language, at de/images/NAME.png.
        """
        bases = [(src, self.folder), (src, self.source)]
        if src.startswith(f"{self.docs}/"):
            bases.append((src.removeprefix(f"{self.docs}/"), ""))
        for moved in (False, True):
            for path, folder in bases:
                target = local(path, folder, self.found, moved=moved)
                if target is not None:
                    return target
        return None

    def address(self, src: str) -> str:
        """src as the page on the website reaches its file, with its ending such as
        #only-dark; src as it is when the website lacks that file."""
        target = self.find(src)
        if target is None or local(src, self.folder, self.found) is not None:
            return src
        ending = re.search(r"[?#].*", src)
        return relative(target, self.folder) + (ending[0] if ending else "")


def themed(match: re.Match[str], where: Where) -> str:
    """Show a <picture> with a picture for each scheme as two pictures.

    GitHub picks the picture of <source media="(prefers-color-scheme: dark)"> by the
    scheme of the reader's system, but the reader of the website can switch it, which
    a <picture> doesn't follow. The website shows each picture with #only-light or
    #only-dark, which Material shows in that scheme alone, and the other one never
    loads. A path from the source of the page, or from the root of the repository
    as a README names docs/images/NAME.png, leads to the same picture on the
    website. Any other <picture> stays as it is.
    """
    image = IMAGE.search(match["inside"])
    if image is None:
        return match[0]
    attrs = attributes(image[0])
    chosen = {"light": attrs.get("src"), "dark": None}
    for source in SOURCE.finditer(match["inside"]):
        source_attrs = attributes(source[0])
        media = re.sub(r"\s+", "", source_attrs.get("media") or "")
        named = re.fullmatch(r"\(prefers-color-scheme:(light|dark)\)", media)
        srcset = (source_attrs.get("srcset") or "").split(",")[0].split()
        if named and srcset:
            chosen[named[1]] = srcset[0]
    if not chosen["light"] or not chosen["dark"]:
        return match[0]
    pictures = []
    for scheme, ending in zip(("light", "dark"), SCHEMES, strict=True):
        src = where.address(str(chosen[scheme]).split("#")[0])
        pictures.append(element("img", {**attrs, "src": src.split("#")[0] + ending}))
    return "".join(pictures)


def size(path: str) -> tuple[int, int] | None:
    """The width and the height of a PNG, a GIF or a WebP, from its first bytes, or
    of an SVG that names them in pixels at the start of its root, as the pictures
    of the diagrams do."""
    with open(path, "rb") as file:
        head = file.read(SVG_HEAD)
    root = SVG_ROOT.match(head)
    if root is not None:
        return svg_size(root[0])
    head = head[:30]
    if head.startswith(b"\x89PNG\r\n\x1a\n") and head[12:16] == b"IHDR":
        width, height = struct.unpack(">II", head[16:24])
        return width, height
    if head[:6] in (b"GIF87a", b"GIF89a"):
        width, height = struct.unpack("<HH", head[6:10])
        return width, height
    if head[:4] != b"RIFF" or head[8:12] != b"WEBP" or len(head) < 30:
        return None
    # The three kinds of WebP: lossy, lossless, and extended, such as an animation.
    chunk = head[12:16]
    if chunk == b"VP8 ":
        width, height = struct.unpack("<HH", head[26:30])
        return width & 0x3FFF, height & 0x3FFF
    if chunk == b"VP8L":
        bits = int.from_bytes(head[21:25], "little")
        return (bits & 0x3FFF) + 1, (bits >> 14 & 0x3FFF) + 1
    if chunk == b"VP8X":
        width = int.from_bytes(head[24:27], "little") + 1
        return width, int.from_bytes(head[27:30], "little") + 1
    return None


def svg_size(root: bytes) -> tuple[int, int] | None:
    """The width and the height that the root of an SVG names in pixels."""
    lengths = [length.search(root) for length in SVG_LENGTHS]
    if lengths[0] is None or lengths[1] is None:
        return None
    return round(float(lengths[0]["length"])), round(float(lengths[1]["length"]))


def video(tag: str, where: Where) -> str:
    """Show a GIF with a video beside it, NAME.mp4, as that video.

    GitHub shows the GIF, the website the video, which is a third of its size and
    loads only once the reader scrolls to it: the script of the blueprint plays it
    while it is in view, muted and in a loop as the GIF, and the reader can pause it.
    NAME.webp or NAME.png beside it shows until it plays, and the text of the GIF
    names it.
    """
    attrs = attributes(tag)
    target = where.find(attrs.get("src") or "")
    folder, found = where.folder, where.found
    if target is None or not target.lower().endswith(".gif"):
        return tag
    stem = target[: -len(".gif")]
    if f"{stem}.mp4" not in found:
        return tag
    shown: dict[str, str | None] = {
        "class": "blueprint-video",
        "src": relative(f"{stem}.mp4", folder),
    }
    poster = next((f"{stem}{end}" for end in POSTERS if f"{stem}{end}" in found), None)
    if poster is not None:
        shown["poster"] = relative(poster, folder)
    dimensions = size(found[target].abs_src_path)
    if dimensions is not None:
        shown["width"], shown["height"] = map(str, dimensions)
    for flag in ("controls", "muted", "loop", "playsinline"):
        shown[flag] = None
    shown["preload"] = "none"
    for name, shown_as in (("alt", "aria-label"), ("title", "title")):
        if attrs.get(name):
            shown[shown_as] = attrs[name]
    return f"{element('video', shown)}</video>"


def lazy(html_text: str, where: Where) -> str:
    """Load the pictures of a page as the reader comes to them.

    The first picture loads first, as the largest part of the page that a reader
    sees at once; every other one waits until it comes near the screen, and one for
    the other scheme never comes. A PNG or a GIF of the website gets its width and
    height, so that the page doesn't shift while it loads. A picture that says how
    it loads stays as it is.
    """
    first = True

    def load(match: re.Match[str]) -> str:
        nonlocal first
        attrs = attributes(match[0])
        was_first, first = first, False
        if "loading" in attrs:
            return match[0]
        src = attrs.get("src") or ""
        target = where.find(src)
        if target is not None:
            attrs["src"] = src = where.address(src)
        if target is not None and "width" not in attrs and "height" not in attrs:
            dimensions = size(where.found[target].abs_src_path)
            if dimensions is not None:
                attrs["width"], attrs["height"] = map(str, dimensions)
        if was_first and not src.endswith(SCHEMES):
            attrs["fetchpriority"] = "high"
        else:
            attrs["loading"] = "lazy"
            attrs["decoding"] = "async"
        return element("img", attrs)

    return IMAGE.sub(load, html_text)


def without_badges(html_text: str) -> str:
    """Take the badges of a README out of the page, with their links, and the
    paragraph that held nothing else.

    GitHub shows them as they are now; the website would show each as the build
    downloaded it, a release or a result of the checks that is long gone.
    """

    def badge(tag: str) -> bool:
        return bool(BADGE.match(attributes(tag).get("src") or ""))

    html_text = LINKED_IMAGE.sub(lambda m: "" if badge(m["image"]) else m[0], html_text)
    html_text = IMAGE.sub(lambda m: "" if badge(m[0]) else m[0], html_text)
    return EMPTY.sub("", html_text)


def describe(page: Any, html_text: str, config: Any) -> None:
    """Give a page the description that search engines and preview cards show: the
    start of its first paragraph, unless its front matter has one.

    Every page had the description of the website, so search engines saw the same
    text for each. The home page of each language keeps it.
    """
    if page.meta.get("description") or page.file.dest_uri in homes(config):
        return
    for paragraph in PARAGRAPH.finditer(html_text):
        # A paragraph of pictures or of links alone describes nothing.
        words = re.sub(r"<[^>]*>", "", LINK.sub("", paragraph["inside"]))
        if re.search(r"\w", words):
            text = " ".join(
                html.unescape(re.sub(r"<[^>]*>", "", paragraph["inside"])).split()
            )
            # MkDocs writes it into an attribute as it is.
            page.meta["description"] = shorten(text.replace('"', "'"), DESCRIPTION)
            return


def homes(config: Any) -> set[str]:
    """The home pages of the website, one of each language that is built."""
    found = languages(config)
    return {"index.html"} | ({f"{found.current}/index.html"} if found else set())


def shorten(text: str, limit: int) -> str:
    """text, or as many of its words as fit into limit characters with an ellipsis."""
    if len(text) <= limit:
        return text
    words = text[:limit].rsplit(" ", 1)[0].rstrip(",;:")
    return f"{words}…"


def on_post_page(output: str, page: Any, config: Any) -> str:
    """Give the head of a page what search engines and the search need to know of
    its language: the full addresses of its versions in other languages, a page
    that a further language shows untranslated as a copy of the page of the default
    language, which no search engine lists, and the search in its language; and
    the link to the repository the version of the latest release, which the
    browser of the reader no longer asks GitHub for.

    It runs after the hooks of a project, such as one that leads the home page to
    the root of the address, and after the privacy plugin.
    """
    found = languages(config)
    if found is not None:
        output = versions(output, page, config, found)
        if config.plugins.get("material/search"):
            output = search_in_language(output, page, found)
    if unlisted(page, config):
        output = output.replace("</head>", f"{NOINDEX}</head>", 1)
    kept(output, page)
    return source_facts(output)


def kept(output: str, page: Any) -> None:
    """Warn, which fails a strict build, when a plugin took pictures out of the page
    that on_page_content left in it, as glightbox did with selectolax 1.0.0 and
    1.0.1: it moved each picture into the link that opens it enlarged and then
    replaced the picture with that link, which took both out of every page. The
    warning names the files of the pictures that are gone; when a plugin renamed
    one that stayed, as the privacy plugin does with a picture of another server,
    the files among which they are."""
    shown = BUILT.images.pop(page.file.dest_uri, [])
    left = picture_files(article(output))
    if len(left) < len(shown):
        gone = sorted((Counter(shown) - Counter(left)).elements())
        among = "among " if len(gone) > len(shown) - len(left) else ""
        log.warning(
            f"{page.file.src_uri}: a plugin took {len(shown) - len(left)} of its "
            f"{len(shown)} picture(s) out of the page ({among}{', '.join(gone)}), "
            "such as mkdocs-glightbox with a release of selectolax that it doesn't "
            "work with; the lock of the tools of the website needs another release "
            "of it."
        )


def picture_files(html_text: str) -> list[str]:
    """The pictures of html_text, each by the name of its file, such as
    analysis-light.webp for images/analysis-light.webp#only-light."""
    names = []
    for match in IMAGE.finditer(html_text):
        src = attributes(match[0]).get("src") or ""
        names.append(posixpath.basename(re.split(r"[?#]", src, maxsplit=1)[0]))
    return names


on_post_page.mkdocs_priority = -60  # type: ignore[attr-defined]


def article(output: str) -> str:
    """The content of a page in its theme, without the pictures of the theme around
    it, such as the logo in the header and in the drawer; the whole page when the
    theme puts its content in no article of Material."""
    start = output.find('<article class="md-content__inner')
    end = output.rfind("</article>")
    return output[start:end] if -1 < start < end else output


def versions(output: str, page: Any, config: Any, found: Languages) -> str:
    """Name the versions of a page in other languages with their full addresses,
    and the version of the default language once more for every other language.

    Search engines take only a full address, and x-default for the readers of a
    language that the website lacks. A version that only shows the page of the
    default language, untranslated, is no version of its own: a page with no other
    version names none, and an untranslated page names the page of the default
    language as its canonical address.
    """
    here = urljoin(config.site_url or "", page.url)
    alternates = getattr(page.file, "alternates", None) or {}
    translated = {
        language for language, file in alternates.items() if file.locale == language
    }
    named = {}
    for match in HEAD_LINK.finditer(output):
        attrs = attributes(match[0])
        language = attrs.get("hreflang")
        if attrs.get("rel") == "alternate" and language in translated:
            named[str(language)] = urljoin(here, attrs.get("href") or "")
    if found.default in named and len(named) > 1:
        named["x-default"] = named[found.default]
    else:
        named = {}
    tags = "".join(
        f'<link rel="alternate" href="{href}" hreflang="{language}">'
        for language, href in named.items()
    )
    default = alternates.get(found.default)
    canonical = None
    if default is not None and untranslated(page, config):
        canonical = urljoin(config.site_url or "", default.url)

    def replace(match: re.Match[str]) -> str:
        nonlocal tags
        attrs = attributes(match[0])
        if attrs.get("rel") == "alternate" and attrs.get("hreflang"):
            # The links of the languages, all in place of the first one.
            written, tags = tags, ""
            return written
        # A project may have named another page as canonical, such as the one that
        # replaced a page that moved; only the page itself becomes the original.
        if (
            attrs.get("rel") == "canonical"
            and canonical
            and urljoin(here, attrs.get("href") or "") == here
        ):
            return element("link", {**attrs, "href": canonical})
        return match[0]

    return HEAD_LINK.sub(replace, output)


def search_in_language(output: str, page: Any, found: Languages) -> str:
    """Let the search of a page find the pages of its language: the script of
    SEARCH_SCRIPT gives it the index of its language, which on_post_build writes.

    One index held every language: an English search found the German pages too,
    often first, and the German one the English pages that have a German version.
    """
    folder = posixpath.dirname(page.file.dest_uri)
    every = relative(SEARCH_INDEX, folder)
    own = relative(SEARCH_INDEX.replace(".json", f".{found.current}.json"), folder)
    script = SEARCH_SCRIPT.format(every=json.dumps(every), own=json.dumps(own))
    return output.replace("</head>", f"{script}</head>", 1)


def on_post_build(config: Any) -> None:
    """Write the search index of each language, keep the pages that no search engine
    lists out of the sitemap, and copy the sitemap of a website in several
    languages into every folder of pages.

    The language switch of Material asks for sitemap.xml in the folder of the page in
    each language: decisions/ and de/decisions/ on decisions/0006-beta-channel.html.
    The i18n plugin writes a single sitemap with every language at the root, so every
    other folder that holds a page gets a copy.
    """
    site = Path(config.site_dir)
    sitemap = site / "sitemap.xml"
    for path in (sitemap, site / "sitemap.xml.gz"):
        unlist(path, BUILT.unlisted)
    found = languages(config)
    if found is None:
        return
    index = site / SEARCH_INDEX
    if index.is_file():
        search_by_language(index, found, BUILT.untranslated)
        search_request(site / "assets/javascripts")
    if not sitemap.is_file():
        return
    for folder in {page.parent for page in site.rglob("*.html")} - {site}:
        shutil.copyfile(sitemap, folder / "sitemap.xml")


# After the i18n plugin, which writes the search index of every language with -100,
# and after the hooks of a project that add to it, such as -110.
on_post_build.mkdocs_priority = -120  # type: ignore[attr-defined]


def unlist(path: Path, addresses: set[str]) -> None:
    """Take the pages at addresses out of a sitemap, sitemap.xml or the packed
    sitemap.xml.gz, and the links to them out of its other pages."""
    if not path.is_file():
        return

    def keep(text: str) -> str:
        text = SITEMAP_ENTRY.sub(
            lambda m: "" if html.unescape(m["loc"]) in addresses else m[0], text
        )
        return SITEMAP_LINK.sub(
            lambda m: "" if html.unescape(m["href"]) in addresses else m[0], text
        )

    if path.suffix != ".gz":
        text = path.read_text(encoding="utf-8")
        path.write_text(keep(text), encoding="utf-8", newline="\n")
        return
    with gzip.GzipFile(path) as reader:
        text = reader.read().decode("utf-8")
        built = reader.mtime
    with gzip.GzipFile(path, "wb", mtime=built) as writer:
        writer.write(keep(text).encode("utf-8"))


def search_by_language(index: Path, found: Languages, copies: set[str]) -> None:
    """Write the search index of each language beside that of every language.

    The index of a language holds its pages, and in a further language the pages
    of the default one that have no version in it, at the path of copies where the
    website shows them in that language. A section whose page the index lacks gets
    one, with its title: the search showed it under the address .../undefined.
    """
    data = json.loads(index.read_text(encoding="utf-8"))
    docs = []
    pages = {doc["location"] for doc in data["docs"] if "#" not in doc["location"]}
    for doc in data["docs"]:
        page = doc["location"].partition("#")[0]
        if page not in pages:
            docs.append({"location": page, "title": doc["title"], "text": ""})
            pages.add(page)
        docs.append(doc)
    write_index(index, {**data, "docs": docs})
    for language in (found.default, *found.further):
        kept = []
        for doc in docs:
            location = doc["location"]
            page = location.partition("#")[0]
            spoken = found.of(page)
            if spoken == language:
                kept.append(doc)
            elif language != found.default and spoken == found.default:
                there = found.versions(page, language)
                if not pages.intersection(there):
                    shown = there[0] if there[0] in copies else page
                    kept.append({**doc, "location": shown + location[len(page) :]})
        name = SEARCH_INDEX.replace(".json", f".{language}.json")
        write_index(index.parent.parent / name, {**data, "docs": kept})


def search_request(scripts: Path) -> None:
    """Warn, which stops the strict build, when the scripts of Material in scripts
    ask for the search index in a way that SEARCH_SCRIPT doesn't redirect.

    The search of every page would find every language again, and nothing else
    would show it; the update of Material that changes it fails instead.
    """
    bundles = [path.read_text(encoding="utf-8") for path in scripts.glob("bundle.*.js")]
    if bundles and not any(
        all(part in bundle for part in SEARCH_REQUEST) for bundle in bundles
    ):
        log.warning(
            "Material no longer asks for %s with an XMLHttpRequest, so the search "
            "of every page would find every language: SEARCH_SCRIPT of "
            ".github/mkdocs_blueprint.py has to follow the way it asks now.",
            SEARCH_INDEX,
        )


def write_index(path: Path, data: dict[str, Any]) -> None:
    """Write a search index as the search plugin of Material writes it."""
    text = json.dumps(data, separators=(",", ":"))
    path.write_text(text, encoding="utf-8", newline="\n")


def on_post_template(output_content: str, template_name: str, config: Any) -> str:
    """Keep the 404 page of the default language at the root, with the link to the
    repository of every other page.

    The i18n plugin builds every further language into the same folder, and that
    build would write 404.html at the root again: in the further language, and with
    the language switch of the page that was built last. MkDocs writes no empty page.
    """
    plugin = config.plugins.get("i18n")
    further = plugin is not None and not plugin.is_default_language_build
    if template_name == "404.html" and further:
        return ""
    return source_facts(output_content)


# ---------------------------------------------------------- the page of the automation

# The page of every website that shows how its repository looks after itself: each
# workflow of .github/workflows/ with the comment at its top, what starts it, which
# of its checks main requires, and the environments and secrets that it uses. The
# hooks write it at every build, so it changes with the workflows. A page of the
# project by the same name, docs/automation.md, takes its place, and
# docs/automation.de.md that of the German version.
AUTOMATION = "automation.md"
# The page of the menu after which the page of the automation comes, when the menu
# of a project doesn't name it.
SECURITY = "security.md"
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


def automated(config: Any) -> Path | None:
    """The folder of the workflows that the page of the automation shows, or None
    for a website without workflows and for one whose project writes the page."""
    folder = Path(config.config_file_path).parent / ".github/workflows"
    if (Path(config.docs_dir) / AUTOMATION).exists() or not any(folder.glob("*.y*ml")):
        return None
    return folder


def listed_in(nav: Any, page: str) -> bool:
    """Whether the menu nav, or an entry of it, names page."""
    if isinstance(nav, dict):
        return any(listed_in(entry, page) for entry in nav.values())
    if isinstance(nav, list):
        return any(listed_in(entry, page) for entry in nav)
    return bool(nav == page)


def add_to_menu(nav: list[Any], page: str) -> None:
    """Put page into the menu nav after the security design, wherever the menu has
    it, or at its end. The entry is the page alone, so that the menu shows its
    title in the language of each website."""
    items, index = place(nav, SECURITY) or (nav, len(nav) - 1)
    items.insert(index + 1, page)


def place(nav: list[Any], page: str) -> tuple[list[Any], int] | None:
    """The list of the menu nav that names page as an entry of its own, and where."""
    for index, entry in enumerate(nav):
        if entry == page or (isinstance(entry, dict) and page in entry.values()):
            return nav, index
        for value in entry.values() if isinstance(entry, dict) else ():
            found = place(value, page) if isinstance(value, list) else None
            if found:
                return found
    return None


def automation_pages(folder: Path, config: Any) -> dict[str, str]:
    """The page of the automation in the language of the website, and in each
    further one that has WORDS and no page of the project, by their paths in docs/.

    BUILT.sources becomes the files that they are made of: the workflows and the
    settings, by which redate dates them.
    """
    root = folder.parent.parent
    settings = [root / ".github" / name for name in SETTINGS]
    settings = [path for path in settings if path.is_file()]
    required: list[str] = []
    for path in settings:
        data = tomllib.loads(path.read_text(encoding="utf-8"))
        required += data.get("branch", {}).get("required_checks", [])
    required = list(dict.fromkeys(required))
    BUILT.sources = [folder, *settings]
    BUILT.pictures = []
    address = repository_address(config)
    flows = workflows(folder, urlparse(address).path.strip("/"))
    names = [path.name for path in settings]
    found = languages(config)
    default = found.default if found else str(config.theme.get("language"))
    shown = default if default in WORDS else "en"
    pages = {
        AUTOMATION: automation_page(
            flows, required, names, config, shown, diagrams(config, shown)
        )
    }
    for language in found.further if found else ():
        name = AUTOMATION.replace(".md", f".{language}.md")
        if language in WORDS and not (Path(config.docs_dir) / name).exists():
            pages[name] = automation_page(
                flows, required, names, config, language, diagrams(config, language)
            )
    return pages


def diagrams(config: Any, language: str) -> list[str]:
    """The diagrams of the page of the automation in language, each as the
    <picture> of its pictures for the light and the dark scheme, as a page of docs/
    shows one, with its title and its description as the text of the picture, or
    "" for one whose pictures are missing.

    Those in PICTURES beside this file go into BUILT.pictures, which the website
    serves in SERVED; in the blueprint, which has no such folder, those of
    docs/images/diagrams/ are files of the website already.
    """
    docs = Path(config.docs_dir)
    folders = (Path(__file__).resolve().parent / PICTURES, docs / "images/diagrams")
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
            log.warning(
                f"The page of the automation lacks the pictures {', '.join(names)}, "
                f"which copier update brings into .github/{PICTURES}/."
            )
            shown.append("")
            continue
        light, dark = (folder / name for name in names)
        if folder.is_relative_to(docs):
            sources = [path.relative_to(docs).as_posix() for path in (light, dark)]
        else:
            BUILT.pictures += [light, dark]
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


def repository_address(config: Any) -> str:
    """The address of the website's repository, such as
    https://github.com/owner/name, or "" when the website does not tell it.

    repo_url is it when its path is owner/name; a website whose repo_url is the
    profile of its owner, such as a personal one, tells it by an absolute edit_uri.
    """
    for value, exact in ((config.repo_url, True), (config.edit_uri, False)):
        address = urlparse(value or "")
        parts = [part for part in address.path.split("/") if part]
        if address.netloc and (len(parts) == 2 or (not exact and len(parts) > 2)):
            return f"{address.scheme}://{address.netloc}/{parts[0]}/{parts[1]}"
    return ""


def automation_page(
    flows: list[Workflow],
    required: list[str],
    settings: list[str],
    config: Any,
    language: str,
    shown: list[str],
) -> str:
    """The page of the automation in language, as Markdown: what the workflows do,
    with the diagrams shown, what a pull request needs by the files of settings in
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
    description = words["description"].format(site=config.site_name, count=count)
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
    lines += [f"## {words['at-a-glance']} {{#at-a-glance}}", ""]
    lines += [words["glance intro"], ""]
    lines += [f"| {words['workflow']} | {words['when']} |", "| --- | --- |"]
    for flow in (flow for group in GROUPS for flow in groups[group]):
        when = ", ".join(flow.when(language, anchors, brief=True))
        lines.append(f"| [{plain(flow.name)}](#{flow.anchor}) | {when} |")
    lines.append("")
    repository = repository_address(config)
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


def on_nav(nav: Any, config: Any, files: Any) -> Any:
    """Give the pages that the hooks write their text, once the i18n plugin has made
    a file of each language of them in on_files, and before MkDocs reads them.

    They have no file of their own: MkDocs shows no pencil to edit them, and the
    plugin git-revision-date-localized dates them by the build, which redate
    corrects.
    """
    for file in files:
        text = BUILT.pages.get(file.src_uri)
        if text is not None:
            file.content_string = text
            file.generated_by = __name__
            if file.page is not None:
                file.page.edit_url = None
    return nav
