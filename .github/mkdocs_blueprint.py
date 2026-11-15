"""The hooks of the documentation website, which the blueprint keeps current.

.github/mkdocs-blueprint.yml names this file under hooks. A project with hooks of
its own lists this file there too, since its list replaces that of the blueprint.
https://github.com/Dennis-Otto/repo-blueprint
"""

from __future__ import annotations

import faulthandler
import hashlib
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
from typing import TYPE_CHECKING, Any, NamedTuple
from urllib.parse import quote, unquote, urljoin, urlparse, urlsplit

import yaml

log = logging.getLogger("mkdocs.hooks.blueprint")


def code_of(names: tuple[str, ...], module: str) -> ModuleType | None:
    """The code of blueprint.py in the first file of names, by its path from this
    file, loaded as module, which needs nothing but Python; None without the file."""
    here = Path(__file__).resolve().parent
    path = next((here / name for name in names if (here / name).is_file()), None)
    if path is None:
        return None
    spec = importlib.util.spec_from_file_location(module, path)
    # A file of Python always has a spec, with its loader.
    assert spec is not None
    assert spec.loader is not None
    code = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = code
    spec.loader.exec_module(code)
    return code


# The steps of the website that need no generator (decision 0166): the page of the
# automation before the build, and the sitemaps and the map of the website after
# it. blueprint/website.py holds them, beside this file in a repository made from
# the blueprint, and at the root of the blueprint itself; the hooks hand them what
# MkDocs knows, so that a build with another generator can take the same steps.
WEBSITE_CODE = ("blueprint/website.py", "../blueprint/website.py")
if TYPE_CHECKING:
    from blueprint import website
else:
    website = code_of(WEBSITE_CODE, "blueprint_website")
    if website is None:
        raise ImportError(
            "The hooks of the website need blueprint/website.py beside "
            ".github/mkdocs_blueprint.py, which copier update brings with them."
        )

# The plugins of Material that download from other servers while the website is
# built: privacy the fonts and scripts that the pages use, social the fonts of the
# preview cards.
DOWNLOADING = ("material/privacy", "material/social")
# How often a download is tried before the plugin gets its error.
ATTEMPTS = 3
# The seconds that an attempt waits for the connection and then for each next part
# of the file, when the plugin names no time limit itself: the social plugin names
# none, so a server that stops answering would hold the build until the CI ends it.
TIMEOUT = (10, 30)
# The seconds after which a build of the website prints the stack of every thread,
# well before the end of the five minutes of its step, which leaves no trace of where
# it stood. It goes on: a big website may just need that long.
STACKS_AFTER = 4 * 60
# The stylesheet and the script of every website: the path where the website serves
# each, and the file beside this one that holds it.
ASSETS = {
    "assets/stylesheets/blueprint.css": "mkdocs-blueprint.css",
    "assets/javascripts/blueprint.js": "mkdocs-blueprint.js",
}
STYLESHEET, SCRIPT = ASSETS
# The fonts of every website, Roboto and Roboto Mono, which Material would load with
# the stylesheet of Google Fonts, which may change at any time: theme.font of
# .github/mkdocs-blueprint.yml turns that off, and the website serves the copy of it
# beside this file, before the stylesheet of the blueprint. A project that names
# fonts of its own in theme.font gets those from Google Fonts, as Material loads them.
FONTS = "assets/stylesheets/blueprint-fonts.css"
FONTS_FILE = "mkdocs-blueprint-fonts.css"
# The files of other servers that every website serves, in the file beside this one:
# the release of Mermaid and the fonts, each with its sha256, which the hook checks
# on every download of the privacy plugin and of its own, and on every copy of one
# in the cache, so that a build fails on a file that differs.
PINS_FILE = "mkdocs-blueprint-pins.toml"
PINNED: dict[str, Any] = tomllib.loads(
    (Path(__file__).resolve().parent / PINS_FILE).read_text(encoding="utf-8")
)
# The Mermaid that draws a ```mermaid block of a project's page, which the website
# still draws live; a diagram that the pages show as its pictures needs none.
# Material asks unpkg.com for the newest release of a major version, which the
# privacy plugin downloads in every build, so that two builds of the same commit
# could draw the diagrams differently.
# The website loads the release of PINNED instead, which Renovate keeps current
# within the major version that Material loads, and only when a page has a
# ```mermaid block: the hook downloads it after the pages, not the privacy plugin.
MERMAID = (
    f"https://unpkg.com/mermaid@{PINNED['mermaid']['version']}/dist/mermaid.min.js"
)
SHA256: dict[str, str] = {MERMAID: PINNED["mermaid"]["sha256"], **PINNED["fonts"]}
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
# The width that an <img> names, as a browser reads it: a number, which with a % is a
# part of the column, and nothing of what follows, such as the px of 396px.
NAMED_WIDTH = re.compile(r"\s*(?P<number>\d+(?:\.\d+)?)(?P<percent>%?)")
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
# The designed preview of the repository, which GitHub shows for a link to it, where
# the website serves it, and the tags of the head that name the picture of a preview
# card, which the home page of each language takes from it instead of the card that
# the social plugin draws: that one shows the name of the website twice and cuts its
# description. The inner pages keep their cards, whose titles are their own, and the
# home page keeps the text of its picture (og:image:alt and twitter:image:alt), which
# names the project as the preview does.
PREVIEW = ".github/social-preview.png"
SERVED_PREVIEW = "assets/images/social-preview.png"
PREVIEW_TAG = re.compile(
    rf'<meta\b(?={TAG}\bproperty="(?:og:image(?::(?!alt")\w+)?|twitter:image)")'
    rf"{TAG}>\n?",
    re.I,
)
TWITTER_CARD = 'property="twitter:card"'

# The pages that a website in several languages, and any other, keeps out of the
# search and out of search engines: the template of a decision record, which is
# there to be copied, and a page with robots: noindex in its front matter. A page
# that a further language shows in the default one, untranslated, is the same page
# as that one, which search engines list and the search finds instead.
TEMPLATES = ("decisions/0000-template.md",)
NOINDEX = '<meta name="robots" content="noindex, follow">'
# The front matter of a page as MkDocs looks for it, which it takes away from the
# Markdown once it reads it as a mapping of YAML. When it can't, MkDocs keeps it as
# Markdown without a word: its last line of dashes makes the rest a heading of the
# page. Zensical refuses to build such a page (decision 0160).
FRONT_MATTER = re.compile(r"\A-{3}[ \t]*\n.*?\n(?:\.{3}|-{3})[ \t]*\n", re.S)
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
# A line of a page that includes a file of the repository, --8<-- "README.md", or a
# part of one, "README.md:loop"; a line --8<-- alone starts and ends a block with
# one file on each line.
SNIPPET = re.compile(
    r"[ \t]*-+8<-+[ \t]*(?:(?P<quote>[\"'])(?P<name>.+?)(?P=quote))?[ \t]*"
)


class Retrying:
    """The requests of a plugin, whose get tries a download again, after a second
    and then two, when the network or the server fails, and gives up on an attempt
    after TIMEOUT unless the plugin names its own time limit. With checked, a file
    that PINNED names must have its sha256, or get raises Mismatch, which the plugin
    takes for a download that failed."""

    def __init__(self, requests: Any, *, checked: bool = False) -> None:
        self.requests = requests
        self.checked = checked

    def __getattr__(self, name: str) -> Any:
        return getattr(self.requests, name)

    def get(self, url: str, **kwargs: Any) -> Any:
        response = self.tried(url, **kwargs)
        if self.checked:
            check(url, response)
        return response

    def tried(self, url: str, **kwargs: Any) -> Any:
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


class Mismatch(Exception):
    """A download whose sha256 differs from the one that PINNED names."""


def check(url: str, response: Any) -> None:
    """Fail on a file that PINNED names, as the server sent it, with another sha256:
    the error fails every build, and a strict one fails on the warning of the plugin
    that gets the Mismatch too."""
    expected = SHA256.get(url)
    if expected is None or response.status_code != 200:
        return
    found = hashlib.sha256(response.content).hexdigest()
    if found != expected:
        message = (
            f"{url} has the sha256 {found}, and .github/{PINS_FILE} pins "
            f"{expected}: the website serves no file that differs from its pin. A new "
            "release of Mermaid gets its sha256 from python3 pins.py of the blueprint, "
            "and a font that changed under its address is no longer the one pinned."
        )
        log.error(message)
        raise Mismatch(message)


def unchecked(cache: Path) -> None:
    """Remove every copy in the cache of a download of the privacy plugin that PINNED
    names and that differs from its sha256, such as one from a cache of the time
    before the hook checked it: the plugin never downloads a file again that it has,
    so this one would reach the website unchecked. The plugin downloads it anew."""
    for url, expected in SHA256.items():
        address = urlsplit(url)
        copy = cache / address.netloc / address.path.lstrip("/")
        if copy.is_file() and sha256(copy) != expected:
            log.info(
                f"Downloading {url} again: its copy in {cache} isn't the pinned one"
            )
            copy.unlink()


def sha256(path: Path) -> str:
    """The sha256 of the file at path."""
    return hashlib.sha256(path.read_bytes()).hexdigest()


def fetching(config: Any) -> Any | None:
    """The privacy plugin, when it downloads the files of other servers that the
    pages name and serves them with the website, as it does by default; else None."""
    plugin = config.plugins.get("material/privacy")
    options = getattr(plugin, "config", None)
    if options is None or not all(
        getattr(options, name, True) for name in ("enabled", "assets", "assets_fetch")
    ):
        return None
    return plugin


def downloads(privacy: Any) -> Path:
    """The folder of the privacy plugin's cache with the files of other servers, one
    folder for each server, as the website serves them in its own folder."""
    return Path(privacy.config.cache_dir, privacy.config.assets_fetch_dir)


def served(privacy: Any) -> str:
    """The path where the website serves MERMAID, where the privacy plugin would."""
    address = urlsplit(MERMAID)
    return f"{privacy.config.assets_fetch_dir}/{address.netloc}{address.path}"


class Layers(dict[str, Any]):
    """The jobs that draw the layers of the preview cards, in the social plugin of
    Material, whose job also finishes the placeholder that it replaces.

    To start each layer once, a thread of the plugin puts an empty Future under the
    key of the layer with setdefault, and replaces it with the job when submit has
    started it. A card with the same layer that asks in between gets the placeholder,
    which nothing ever finishes: it waits without end, the build for that card, and
    its step until the CI ends it, with no error and no line in the log. Starting a
    job can start a thread, which lets the other threads run just then.

    When a version of Material gives the jobs another name, on_config warns, which
    fails a strict build: then look whether Material fixed this, and change or remove
    this class. Decision 0089 of the blueprint tells how this was found.
    """

    def __setitem__(self, key: str, job: Any) -> None:
        placeholder = self.get(key)
        super().__setitem__(key, job)
        if placeholder is not None:
            job.add_done_callback(lambda done: self.finish(placeholder, done))

    @staticmethod
    def finish(placeholder: Any, job: Any) -> None:
        """Give whoever waits for the placeholder the outcome of the job."""
        if job.cancelled():
            placeholder.cancel()
        elif (error := job.exception()) is not None:
            placeholder.set_exception(error)
        else:
            placeholder.set_result(job.result())


def watch_the_layers(social: Any) -> None:
    """Give the social plugin the jobs of Layers, or say what to do when it has none."""
    jobs = getattr(social, "card_layer_pool_jobs", None)
    if jobs is None:
        log.warning(
            "Material no longer keeps the jobs of the social plugin in "
            "card_layer_pool_jobs: look whether it closed the gap of decision 0089 of "
            "the blueprint, and change or remove Layers in .github/mkdocs_blueprint.py."
        )
    elif not isinstance(jobs, Layers):
        social.card_layer_pool_jobs = Layers(jobs)


def on_startup(command: str, dirty: bool) -> None:
    """Print where every thread stands when a build takes nearly as long as its step
    may, for the next hang: a log that ends with the last download says nothing about
    what came after it. A preview runs for as long as its author writes."""
    if command != "serve":
        faulthandler.dump_traceback_later(STACKS_AFTER)


def on_config(config: Any) -> None:
    """Let the plugins of Material try a download again before they give up, give
    every page the stylesheet and the script of the blueprint, the links to the
    owner in the footer and the version of the latest release in the header, and
    put the page of the automation into the menu.

    The plugins try every file once: the privacy plugin warns when a font of Google
    Fonts doesn't come within 5 seconds, which fails a strict build, and the social
    plugin fails on any error of the network and waits without end for a server that
    stopped answering, or for a layer of its card that another card just starts, as
    Layers says. The privacy plugin gets the files that PINNED names only with their
    sha256, also from its cache. The stylesheet and the script come
    before those of the project, whose extra_css replaces the list of the blueprint
    and whose rules win over them, and the fonts before the stylesheet, unless the
    project names fonts of its own. The menu of mkdocs.yml belongs to the project, so
    the page comes after the security design there, unless the menu names it.
    """
    for name in DOWNLOADING:
        plugin = config.plugins.get(name)
        if plugin is None:
            continue
        module: Any = sys.modules[type(plugin).__module__]
        if not isinstance(module.requests, Retrying):
            module.requests = Retrying(
                module.requests, checked=name == "material/privacy"
            )
    privacy = fetching(config)
    if privacy is not None:
        unchecked(downloads(privacy))
    social = config.plugins.get("material/social")
    if social is not None:
        watch_the_layers(social)
    for path, listed in (
        (STYLESHEET, config.extra_css),
        (SCRIPT, config.extra_javascript),
    ):
        if path not in map(str, listed):
            listed.insert(0, path)
    if config.theme.get("font") is False and FONTS not in map(str, config.extra_css):
        config.extra_css.insert(0, FONTS)
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
        and not listed_in(config.nav, website.AUTOMATION)
    ):
        add_to_menu(config.nav, website.AUTOMATION)


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
    """Serve the stylesheet, the fonts and the script of the blueprint and the preview
    of the repository, pin Mermaid, and add the page of the automation.

    They are files beside this one, which the i18n plugin leaves in place as files
    of the theme; the privacy plugin, which runs after this hook, downloads the fonts
    that the stylesheet of the fonts names. The script of Material that loads Mermaid
    comes from a copy in .cache/ that names the release of MERMAID, as the website
    serves it when the privacy plugin downloads for it: on_post_build then downloads
    it once a page has a ```mermaid block, and a website without an address, whose
    script can't name the full address of its copy, loads it on every page, as the
    privacy plugin does. The page of the automation, and
    its German version on a website in German, are files of docs/ that the folder
    doesn't hold: the i18n plugin, which runs after this hook too, takes them for
    pages of their languages, and on_nav gives them their text.
    """
    here = Path(__file__).resolve().parent
    kind = type(next(iter(files)))
    privacy = fetching(config)
    address = MERMAID
    if privacy is not None:
        address = served(privacy)
        if config.site_url:
            address = urljoin(config.site_url, address)
        else:
            BUILT.mermaid = True
            if address not in map(str, config.extra_javascript):
                config.extra_javascript.append(address)
    for file in files:
        if BUNDLE.fullmatch(file.src_uri) and file.abs_src_path:
            pin(file, Path(config.docs_dir).parent / ".cache/plugin/blueprint", address)
    assets = dict(ASSETS)
    if FONTS in map(str, config.extra_css):
        assets[FONTS] = FONTS_FILE
    for destination, name in assets.items():
        files.append(
            kind(
                name,
                str(here),
                config.site_dir,
                config.use_directory_urls,
                dest_uri=destination,
            )
        )
    root = Path(config.config_file_path).parent
    preview = root / PREVIEW
    BUILT.preview = size(str(preview)) if preview.is_file() else None
    if BUILT.preview is not None:
        # From its folder: MkDocs leaves out a file whose path starts with a dot.
        files.append(
            kind(
                preview.name,
                str(preview.parent),
                config.site_dir,
                config.use_directory_urls,
                dest_uri=SERVED_PREVIEW,
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
                dest_uri=f"{website.SERVED}/{picture.name}",
            )
        )
    return files


def pin(file: Any, cache: Path, address: str) -> None:
    """Point the script of Material at a copy of it that loads MERMAID from address.

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
            f"change the version of mermaid in .github/{PINS_FILE} to a release of "
            f"Mermaid {found['major']}, and run python3 pins.py of the blueprint."
        )
        return
    copy = cache / file.src_uri
    copy.parent.mkdir(parents=True, exist_ok=True)
    copy.write_text(
        FLOATING.sub(lambda _: address, script), encoding="utf-8", newline=""
    )
    file.abs_src_path = str(copy)


# The changelog that a page includes, and the folder beside it with the fragments, in
# which pull requests describe their changes until a release takes them in.
CHANGELOG = "CHANGELOG.md"
FRAGMENTS = "changelog.d"
# The code of blueprint.py that reads the changelog and its fragments, by its path
# from this file: beside it in a repository made from the blueprint, and at the root
# of the blueprint itself.
CHANGELOG_CODE = ("blueprint/changelog.py", "../blueprint/changelog.py")


class Menu(NamedTuple):
    """What the build of a language gives the map of the website: the name and the
    description of the website in that language, and its menu, whose pages have
    their titles and descriptions once MkDocs has read them."""

    name: str
    summary: str | None
    nav: Any


class Built:
    """What the builds of every language found, for the steps after the last one:
    the paths of the pages that a further language shows untranslated, such as
    de/roadmap.html, and the addresses of the pages that no search engine lists;
    the pages that the hooks write, such as the page of the automation, with
    the text of each by its path in docs/, the files that they are made of and the
    pictures beside this file that they show; the pictures of each page that
    on_page_content left, by its path on the website, each by the name of its file;
    the links in the footer that the hooks gave the build before, None until they
    did; the version of the latest release, which the header shows; and the menu of
    each language, for the map of the website; and the width and the height of the
    preview of the repository that the website serves, None without one.

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
        self.menus: dict[str, Menu] = {}
        self.preview: tuple[int, int] | None = None
        # Whether the website serves MERMAID: a page has a ```mermaid block.
        self.mermaid = False


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
    """Leave the pages that nobody looks for out of the search, date a page by the
    newest of its files, and warn, which stops the strict build, about front matter
    that MkDocs couldn't read.

    It runs after the plugin git-revision-date-localized, which dates the page by
    its own file, and before the search plugin, which reads the front matter.
    """
    if FRONT_MATTER.match(markdown):
        log.warning(
            "The front matter of %s is no mapping of YAML, so MkDocs shows it as text "
            "at the top of the page and Zensical doesn't build the page at all "
            "(decision 0160): put a value with a colon and a space, or with # after "
            "a space, in quotes, such as description: 'Why: the reason'.",
            page.file.src_uri,
        )
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
    return code_of(CHANGELOG_CODE, "blueprint_changelog")


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
    html_text, diagrams = MERMAID_BLOCK.subn(r"\g<0> data-search-exclude", html_text)
    BUILT.mermaid = BUILT.mermaid or diagrams > 0
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

    The video stands where the GIF stood: in the scheme alone that its address names,
    which the stylesheet of the blueprint hides in the other one, and as wide as the
    width that its <img> names; the size of the file gives its shape until it loads.
    """
    attrs = attributes(tag)
    src = attrs.get("src") or ""
    target = where.find(src)
    folder, found = where.folder, where.found
    if target is None or not target.lower().endswith(".gif"):
        return tag
    stem = target[: -len(".gif")]
    if f"{stem}.mp4" not in found:
        return tag
    only = next((ending[1:] for ending in SCHEMES if src.endswith(ending)), None)
    shown: dict[str, str | None] = {
        "class": f"blueprint-video blueprint-{only}" if only else "blueprint-video",
        "src": relative(f"{stem}.mp4", folder),
    }
    poster = next((f"{stem}{end}" for end in POSTERS if f"{stem}{end}" in found), None)
    if poster is not None:
        shown["poster"] = relative(poster, folder)
    dimensions = size(found[target].abs_src_path)
    if dimensions is not None:
        shown["width"], shown["height"] = map(str, dimensions)
    named = NAMED_WIDTH.match(attrs.get("width") or "")
    if named is not None:
        shown["style"] = f"width:{named['number']}{named['percent'] or 'px'}"
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
    if page.file.dest_uri in homes(config):
        output = previewed(output, config)
    kept(output, page)
    return source_facts(output)


def previewed(output: str, config: Any) -> str:
    """A home page whose link shows the preview of the repository, when the website
    serves one and has an address, in place of the picture of the card that the
    social plugin drew, which runs before this hook.

    A link preview needs the full address of the picture. Without the plugin, as
    in a preview on a computer without Cairo or on a website that turned it off,
    the page gets the tags of the picture alone, and the card of Twitter that
    shows it large.
    """
    if BUILT.preview is None or not config.site_url:
        return output
    address = urljoin(config.site_url, SERVED_PREVIEW)
    width, height = BUILT.preview
    tags = {
        "og:image": address,
        "og:image:type": "image/png",
        "og:image:width": str(width),
        "og:image:height": str(height),
        "twitter:image": address,
    }
    if TWITTER_CARD not in output:
        tags["twitter:card"] = "summary_large_image"
    head = "".join(
        f'<meta property="{name}" content="{html.escape(value)}" />\n'
        for name, value in tags.items()
    )
    return PREVIEW_TAG.sub("", output).replace("</head>", f"{head}</head>", 1)


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
    version names none, and an untranslated page names none either, even when
    another further language has translated the page, and names the page of the
    default language as its canonical address.
    """
    here = urljoin(config.site_url or "", page.url)
    copy = untranslated(page, config)
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
    if found.default in named and len(named) > 1 and not copy:
        named["x-default"] = named[found.default]
    else:
        named = {}
    tags = "".join(
        f'<link rel="alternate" href="{href}" hreflang="{language}">'
        for language, href in named.items()
    )
    default = alternates.get(found.default)
    canonical = None
    if default is not None and copy:
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
    """Write the map of the website and the search index of each language, keep the
    pages that no search engine lists out of the sitemap, serve Mermaid once a page
    of any language has a ```mermaid block, and copy the sitemap of a website in
    several languages into every folder of pages.

    The language switch of Material asks for sitemap.xml in the folder of the page in
    each language: decisions/ and de/decisions/ on decisions/0006-beta-channel.html.
    The i18n plugin writes a single sitemap with every language at the root, so every
    other folder that holds a page gets a copy.

    The sitemaps and the map are steps of blueprint/website.py, which need no
    generator: the hook hands them what the build knows, the pages that no search
    engine lists and the menu of each language.
    """
    site = Path(config.site_dir)
    website.unlist_pages(site, BUILT.unlisted)
    write_map(site / website.LLMS_TXT, config)
    if BUILT.mermaid:
        serve_mermaid(config)
    found = languages(config)
    if found is None:
        return
    index = site / SEARCH_INDEX
    if index.is_file():
        search_by_language(index, found, BUILT.untranslated)
        search_request(site / "assets/javascripts")
    website.copy_sitemap(site)


def serve_mermaid(config: Any) -> None:
    """Serve MERMAID where the script of Material loads it, from the cache of the
    privacy plugin, where on_config left only the pinned file, or downloaded with the
    requests of the plugin, which check it, into the cache. The build of each language
    writes into the folder of the whole website, so the first build after a page with
    a ```mermaid block serves it for every language, and a later one finds it in the
    cache. A download that fails warns, which fails a strict build, as the privacy
    plugin does."""
    privacy = fetching(config)
    if privacy is None:
        return
    path = served(privacy)
    copy = Path(privacy.config.cache_dir) / path
    if not copy.is_file():
        requests = sys.modules[type(privacy).__module__].requests
        try:
            response = requests.get(MERMAID)
        except (Mismatch, OSError) as error:
            log.warning(f"Couldn't retrieve {MERMAID}: {error}")
            return
        if response.status_code != 200:
            log.warning(
                f"Couldn't retrieve {MERMAID}: the server answered "
                f"{response.status_code}"
            )
            return
        copy.parent.mkdir(parents=True, exist_ok=True)
        copy.write_bytes(response.content)
    target = Path(config.site_dir) / path
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(copy, target)


# After the i18n plugin, which writes the search index of every language with -100,
# and after the hooks of a project that add to it, such as -110.
on_post_build.mkdocs_priority = -120  # type: ignore[attr-defined]


def write_map(path: Path, config: Any) -> None:
    """Write llms.txt, the map of the website for AI assistants, once every language
    has given its menu: the build of the last further language, within that of the
    default one, has all that the map needs.

    A website without an address has no full address for a link. A file that is there
    already stays: the project's own, from docs/llms.txt or from a hook of its own
    that runs before this one, such as the website at the root of an address, whose
    map lists every website there.
    """
    found = languages(config)
    spoken = (found.default, *found.further) if found else ("",)
    if (
        not config.site_url
        or path.exists()
        or any(language not in BUILT.menus for language in spoken)
    ):
        return
    names = {
        language.locale: language.name
        for language in (config.plugins["i18n"].config.languages if found else ())
    }
    further = {language: names[language] for language in spoken[1:]}
    menus = {
        language: website.Menu(menu.name, menu.summary, menu_items(menu.nav.items))
        for language, menu in BUILT.menus.items()
    }
    text = website.map_of_website(menus, spoken[0], further, BUILT.unlisted)
    path.write_text(text, encoding="utf-8", newline="\n")


def menu_items(items: list[Any]) -> tuple[website.Item, ...]:
    """The items of a menu of MkDocs as the map of the website reads them: each page
    with its title, its path on the website, its full address and its description,
    and each section with its items. A link to another site is no page of the
    website."""
    found: list[website.Item] = []
    for item in items:
        if item.is_section:
            found.append(website.Section(item.title, menu_items(item.children)))
        elif item.is_page:
            description = item.meta.get("description") or ""
            found.append(
                website.Entry(item.title, item.url, item.canonical_url, description)
            )
    return tuple(found)


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
    """Keep the 404 page of the default language at the root, with content that
    leads on and the link to the repository of every other page.

    The i18n plugin builds every further language into the same folder, and that
    build would write 404.html at the root again: in the further language, and with
    the language switch of the page that was built last. MkDocs writes no empty page.
    """
    plugin = config.plugins.get("i18n")
    further = plugin is not None and not plugin.is_default_language_build
    if template_name == "404.html":
        if further:
            return ""
        output_content = not_found(output_content, config)
    return source_facts(output_content)


# The article of the 404 page of Material, which says no more than its heading: a
# project that gives the page content of its own, in an override of the theme,
# keeps it.
MATERIAL_404 = re.compile(
    r'(?P<start><article class="md-content__inner[^"]*"[^>]*>)\s*'
    r"<h1>404 - Not found</h1>\s*(?=</article>)"
)
# The title of a page in its head, which on the 404 page starts with what it is.
PAGE_TITLE = re.compile(r"(?P<start><title>)(?P<rest>.*?</title>)", re.S)
# The words of the 404 page in each language that has them. A website in another
# language takes the English ones, and a further language without them gets no part.
NOT_FOUND = {
    "en": {
        "title": "Page not found",
        "text": (
            "There is no page at this address. It may have moved, or the address "
            "may have a typo."
        ),
        "home": "Go to the home page",
        "search": "The search at the top of every page finds what you looked for.",
    },
    "de": {
        "title": "Seite nicht gefunden",
        "text": (
            "Unter dieser Adresse gibt es keine Seite. Vielleicht ist sie umgezogen, "
            "oder in der Adresse steckt ein Tippfehler."
        ),
        "home": "Zur Startseite",
        "search": "Die Suche oben auf jeder Seite findet, was du gesucht hast.",
    },
}
# The script of the 404 page, after its content. The pages of the website end in
# .html, so an address in the style of folders, such as faq/ or faq, which most
# websites of MkDocs have and which people guess, leads on to faq.html when that is
# a page; the content shows only when it isn't one either, or without scripts.
NOT_FOUND_SCRIPT = (
    "<script>(function(){"
    'var p=location.pathname,t=/[^/]\\/$/.test(p)?p.slice(0,-1)+".html"'
    ':/\\/[^/.]+$/.test(p)?p+".html":"";if(!t)return;'
    'var d=document.getElementById("not-found");d.hidden=true;'
    'fetch(t,{method:"HEAD"}).then(function(r){'
    "if(r.ok)location.replace(t+location.search+location.hash);else d.hidden=false"
    "}).catch(function(){d.hidden=false})"
    "})()</script>"
)


def not_found(output: str, config: Any) -> str:
    """The 404 page of Material with content that leads on: what happened, a link
    to the home page and the hint at the search, in each language of the website
    that has NOT_FOUND, and the script that tries the address as a page of .html.

    GitHub Pages serves the page at the root for a wrong address in any folder, so
    its links start from the path of the address of the website, as MkDocs writes
    those of the theme on it.
    """
    match = MATERIAL_404.search(output)
    if match is None:
        return output
    base = urlsplit(config.site_url or "/").path
    base = base if base.endswith("/") else f"{base}/"
    found = languages(config)
    if found is None:
        spoken = str(config.theme.get("language") or "en")
        homes = [(spoken, base)]
    else:
        spoken = found.default
        homes = [(spoken, base)]
        homes += [(language, f"{base}{language}/") for language in found.further]
    parts: list[str] = []
    for language, home in homes:
        words = NOT_FOUND.get(language)
        if words is None:
            if parts:
                continue
            language, words = "en", NOT_FOUND["en"]
        heading = "h2" if parts else "h1"
        named = f' lang="{language}"' if language != spoken else ""
        parts.append(
            f"<section{named}><{heading}>{words['title']}</{heading}>"
            f"<p>{words['text']}</p>"
            f'<p><a class="md-button md-button--primary" href="{html.escape(home)}">'
            f"{words['home']}</a></p><p>{words['search']}</p></section>"
        )
    content = f'<div id="not-found">{"".join(parts)}</div>{NOT_FOUND_SCRIPT}'
    title = NOT_FOUND.get(spoken, NOT_FOUND["en"])["title"]
    output = output[: match.start()] + match["start"] + content + output[match.end() :]
    return PAGE_TITLE.sub(
        lambda tag: f"{tag['start']}{title} - {tag['rest']}", output, 1
    )


# ---------------------------------------------------------- the page of the automation

# The page of every website that shows how its repository looks after itself, which
# blueprint/website.py writes from the workflows at every build (AUTOMATION there):
# the hooks add it to the files and the menu of the website, serve its pictures and
# fold the section of each workflow.
# The page of the menu after which the page of the automation comes, when the menu
# of a project doesn't name it.
SECURITY = "security.md"

# The section of a workflow in the HTML of the page: its heading, the only kind that
# the page has at this level, without the link to it that the extension toc puts in
# it, and what follows it, up to the next heading of a workflow or a group, or the end
# of the text, which on a page that a further language shows untranslated is the end
# of the element around it. The section itself holds only paragraphs and a list, and
# what a workflow says in them is escaped.
WORKFLOW_SECTION = re.compile(
    r'(?P<heading><h3\b[^>]*>.*?)(?:<a class="headerlink"[^>]*>.*?</a>)?</h3>'
    r"(?P<body>.*?)(?=<h[23]\b|</div>|\Z)",
    re.S,
)


def automated(config: Any) -> Path | None:
    """The folder of the workflows that the page of the automation shows, or None
    for a website without workflows and for one whose project writes the page."""
    folder = Path(config.config_file_path).parent / ".github/workflows"
    if (Path(config.docs_dir) / website.AUTOMATION).exists() or not any(
        folder.glob("*.y*ml")
    ):
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
    further one that has its words and no page of the project, by their paths in
    docs/, as blueprint/website.py writes them.

    BUILT.sources becomes the files that they are made of: the workflows and the
    settings, by which redate dates them. BUILT.pictures becomes the pictures of
    their diagrams in PICTURES beside this file, which the website serves in SERVED;
    in the blueprint, which has no such folder, those of docs/images/diagrams/ are
    files of the website already. A missing picture warns, which stops the strict
    build.
    """
    found = languages(config)
    made = website.automation(
        folder,
        Path(config.docs_dir),
        Path(__file__).resolve().parent / website.PICTURES,
        site_name=config.site_name,
        repository=repository_address(config),
        default=found.default if found else str(config.theme.get("language")),
        further=found.further if found else (),
    )
    for names in made.missing:
        log.warning(
            f"The page of the automation lacks the pictures {', '.join(names)}, "
            f"which copier update brings into .github/{website.PICTURES}/."
        )
    BUILT.sources = made.sources
    BUILT.pictures = made.pictures
    return made.pages


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


def folded_workflows(html_text: str) -> str:
    """The HTML of the page of the automation with the section of each workflow in a
    fold, whose title is its heading: a reader sees the list of the workflows and opens
    the one they want, and an anchor still leads to each, since the title of a fold
    always shows and the Material theme opens the fold around the element that a link
    leads to.

    The heading loses the link to it, which the reader finds in the menu of the page
    and in the table at a glance: a control in the title of a fold, itself a control,
    is a violation of WCAG 4.1.2 that the check of the accessibility fails on.
    """
    return WORKFLOW_SECTION.sub(
        r'<details class="blueprint-fold">'
        r"<summary>\g<heading></h3></summary>\g<body></details>",
        html_text,
    )


def on_page_context(context: Any, page: Any, config: Any, nav: Any) -> Any:
    """Fold the section of each workflow on a page of the automation that the hooks
    wrote, which on a phone was three times as tall as any other page of a website;
    a page of the project in its place stays as it is.

    It comes after the search of Material has read the page in this same event: the
    search ends a section at the end of the element around its heading, so that in the
    HTML of a fold it would file the text of each workflow under the heading of its
    group, whose anchor doesn't lead to it.
    """
    if page.file.src_uri in BUILT.pages:
        page.content = folded_workflows(page.content)
    return context


# After the plugin material/search, which has the default priority.
on_page_context.mkdocs_priority = -100  # type: ignore[attr-defined]


def on_nav(nav: Any, config: Any, files: Any) -> Any:
    """Give the pages that the hooks write their text, once the i18n plugin has made
    a file of each language of them in on_files, and before MkDocs reads them, and
    keep the menu of this language for the map of the website.

    They have no file of their own: MkDocs shows no pencil to edit them, and the
    plugin git-revision-date-localized dates them by the build, which redate
    corrects.
    """
    found = languages(config)
    BUILT.menus[found.current if found else ""] = Menu(
        config.site_name, config.site_description, nav
    )
    for file in files:
        text = BUILT.pages.get(file.src_uri)
        if text is not None:
            file.content_string = text
            file.generated_by = __name__
            if file.page is not None:
                file.page.edit_url = None
    return nav


# After the i18n plugin, which translates the menu with -100, and can make a new one.
on_nav.mkdocs_priority = -110  # type: ignore[attr-defined]
