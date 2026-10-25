"""What a built website promises, checked in its files, after MkDocs has built it.

The hooks of .github/mkdocs_blueprint.py change what the plugins of MkDocs write:
they copy the sitemap into every folder of pages, which the language switch of
Material asks for, keep the 404 page of the default language at the root, give each
page the full addresses of its versions in other languages, and show a page that a
further language lacks in the default language, which no search engine lists, and
take the attribute from the link to the repository with which the script of Material
would ask the API of GitHub for its numbers in the browser of every reader. Their
tests fake the plugins; check() reads what the real ones built, so that a new version
of Material or of mkdocs-static-i18n that breaks a hook fails the check docs, before
the website is published. It also weighs what each page loads at once, which on a
slow phone stays within BUDGET, and reads what the deploy job needs to know: the
further languages, whose home pages it asks for, and the path of the address, from
which the 404 page links the files of the website. With the sources of the pages, it
also finds a picture that a page lost on its way from the Markdown, the README
included, to the built file, and the empty paragraph that such a loss leaves.
"""

from __future__ import annotations

import gzip
import html
import json
import re
from bisect import bisect_right
from dataclasses import dataclass, field
from html.parser import HTMLParser
from itertools import accumulate
from pathlib import Path
from urllib.parse import unquote, urlsplit

# What the annotations of the CI call a finding.
TITLE = "What the website promises"
# The sitemap that the website hands to search engines, and that the language switch
# of Material reads in the folder of each page.
SITEMAP = "sitemap.xml"
# The page that GitHub Pages shows for every address that leads nowhere.
NOT_FOUND = "404.html"
LOCATION = re.compile(r"<loc>\s*([^<]*?)\s*</loc>")
# What a page may load at once, in bytes as GitHub Pages sends them, compressed:
# the budget of a page on a phone with 1.6 Mbit/s (decision 0058). It counts the
# page, its stylesheets and scripts, the pictures that load before the reader
# scrolls, the search with the index of the page's language, and Mermaid on a page
# that draws a diagram live; the fonts, a few of them, come on top.
BUDGET = 1_200_000
# The script that draws a ```mermaid block, which Material loads only on a page
# with one, from where the privacy plugin downloaded it for the website.
MERMAID = "assets/external/unpkg.com/mermaid@*/dist/mermaid.min.js"
# The files that GitHub Pages sends compressed, by their endings.
TEXT = (".html", ".css", ".js", ".mjs", ".json", ".svg", ".xml", ".txt")
# The search index of the page's language, which the script in the head of a page
# asks for in place of that of every language.
OWN_INDEX = re.compile(r'new URL\("(?P<index>[^"]*search_index\.[\w-]+\.json)"')
# The class of the article of Material, which holds the content of a page, without the
# pictures of the theme around it, such as the logo in the header.
ARTICLE = "md-content__inner"
# How many characters of the text of the block before an empty paragraph a finding
# quotes, such as a paragraph or an item of a list, which a block of HTML starts.
CONTEXT = 60
# The spaces of HTML, without those of Unicode such as &nbsp;, which a paragraph
# holds on purpose.
WHITESPACE = " \t\n\r\f"
BLOCKS = frozenset(
    {"p", "li", "dt", "dd", "td", "th", "h1", "h2", "h3", "h4", "h5", "h6"}
    | {"blockquote", "div", "figcaption", "pre", "summary"}
)


@dataclass
class Shown:
    """What a part of a page shows: the names of the files of its pictures and
    videos, as name_of() gives them, their texts, and the text before each empty
    paragraph, in which a plugin took out what it held."""

    names: set[str] = field(default_factory=set)
    texts: set[str] = field(default_factory=set)
    empty: list[str] = field(default_factory=list)


class Page(HTMLParser):
    """What a page of HTML says of itself: its language, the generator that built it,
    its canonical address, its versions in other languages, whether search engines
    may list it, the languages of its parts, and whether the script of Material asks
    the API of GitHub for the numbers of the repository."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.lang: str | None = None
        self.generator = ""
        self.robots = ""
        self.canonical: list[str] = []
        self.alternates: list[tuple[str, str]] = []
        self.parts: set[str] = set()
        self.asks_github = False
        # What the page loads at once, by the addresses that it names, whether it
        # draws a diagram live, the configuration of Material and the text of the
        # other scripts in the page.
        self.loads: list[str] = []
        self.draws_live = False
        self.config = ""
        self.scripts = ""
        self.script: str | None = None
        # What the whole page and its article show, how deep the parser is in the
        # article, whether a paragraph is open with nothing in it yet, the text of
        # the block that the parser is in, and that of the last block with a text.
        self.whole = Shown()
        self.article: Shown | None = None
        self.depth = 0
        self.paragraph = False
        self.block = ""
        self.before = ""

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        found = {name: value or "" for name, value in attrs}
        self.showing(tag, found)
        name = found.get("name", "").lower()
        if tag == "html":
            self.lang = found.get("lang", "") if self.lang is None else self.lang
        elif tag == "meta" and name == "generator":
            self.generator = found.get("content", "")
        elif tag == "meta" and name == "robots":
            self.robots = found.get("content", "").lower()
        elif tag == "link":
            rel = found.get("rel", "").lower().split()
            if "canonical" in rel:
                self.canonical.append(found.get("href", ""))
            if "alternate" in rel and "hreflang" in found:
                self.alternates.append((found["hreflang"], found.get("href", "")))
        elif "lang" in found:
            self.parts.add(found["lang"])
        if found.get("data-md-component") == "source":
            self.asks_github = True
        self.loading(tag, found)

    def loading(self, tag: str, found: dict[str, str]) -> None:
        """Note what the tag has the browser load at once."""
        rel = found.get("rel", "").lower().split()
        if tag == "link" and "stylesheet" in rel and found.get("href"):
            self.loads.append(found["href"])
        elif tag == "script":
            self.script = "config" if found.get("id") == "__config" else "scripts"
            if found.get("src"):
                self.loads.append(found["src"])
        elif tag == "img" and found.get("loading") != "lazy" and found.get("src"):
            self.loads.append(found["src"])
        elif tag == "video" and found.get("poster"):
            self.loads.append(found["poster"])
        if "mermaid" in found.get("class", "").split():
            self.draws_live = True

    def showing(self, tag: str, found: dict[str, str]) -> None:
        """Note the picture or the video that the tag shows, and the start of the
        article of Material; any tag in an open paragraph is something in it."""
        if tag == "article" and self.depth:
            self.depth += 1
        elif tag == "article" and ARTICLE in found.get("class", "").split():
            self.depth, self.article = 1, self.article or Shown()
        self.paragraph = tag == "p"
        if tag in BLOCKS and self.block.strip():
            # Without the sign of the link to a heading of Material, ¶.
            self.before = " ".join(self.block.replace("¶", "").split())
            self.block = ""
        if tag not in ("img", "video", "source"):
            return
        addresses = [found.get("src", ""), found.get("poster", ""), *candidates(found)]
        text = found.get("alt") or found.get("aria-label") or ""
        for part in self.places():
            part.names.update(name_of(address) for address in addresses if address)
            if text.strip():
                part.texts.add(" ".join(text.split()))

    def places(self) -> list[Shown]:
        """What the parser is in: the whole page, and the article while it is in
        it."""
        if self.depth and self.article is not None:
            return [self.whole, self.article]
        return [self.whole]

    def handle_endtag(self, tag: str) -> None:
        if tag == "script":
            self.script = None
        if tag == "p" and self.paragraph:
            before = self.before or "the start of the page"
            for part in self.places():
                part.empty.append(before)
        self.paragraph = False
        if tag == "article" and self.depth:
            self.depth -= 1

    def handle_data(self, data: str) -> None:
        if self.script is not None:
            setattr(self, self.script, getattr(self, self.script) + data)
        elif data.strip(WHITESPACE):
            self.paragraph = False
            self.block += data

    @property
    def content(self) -> Shown:
        """What the content of the page shows: its article of Material, or the whole
        page for one without it."""
        return self.whole if self.article is None else self.article

    @property
    def search(self) -> list[str]:
        """The worker of the search and the index that it loads, as the page names
        them, or none for a page without the search."""
        try:
            config = json.loads(self.config)
        except ValueError:
            return []
        if not isinstance(config, dict) or not config.get("search"):
            return []
        own = OWN_INDEX.search(self.scripts)
        base = config.get("base") or "."
        index = own["index"] if own else f"{base}/search/search_index.json"
        return [str(config["search"]), index]

    @property
    def built(self) -> bool:
        """Whether MkDocs built the page, rather than copying a page of HTML of the
        project, such as one that only leads to another address."""
        return self.generator.startswith("mkdocs")

    @property
    def unlisted(self) -> bool:
        return "noindex" in self.robots


def read(path: Path) -> Page:
    page = Page()
    page.feed(path.read_text(encoding="utf-8", errors="replace"))
    page.close()
    return page


@dataclass(frozen=True)
class Finding:
    """A promise that a file of the website breaks, with its path on the website."""

    path: str
    message: str


@dataclass(frozen=True)
class Site:
    """What check() found: the default language, the further ones that the website
    has, the path of its address, such as /repo/ for a project website, and every
    broken promise."""

    default: str
    further: tuple[str, ...]
    root: str
    findings: tuple[Finding, ...]


def check(site: Path, repository: Path | None = None, docs: str | None = None) -> Site:
    """Check the website that MkDocs built into site; with repository, the one
    whose folder docs holds the sources of its pages, docs_dir of its mkdocs.yml
    unless docs names it, also that every page shows each picture of its Markdown."""
    if not (site / "index.html").is_file():
        return Site("", (), "/", (Finding("index.html", "the home page is missing"),))
    pages = {
        path.relative_to(site).as_posix(): read(path)
        for path in sorted(site.rglob("*.html"))
    }
    home = pages["index.html"]
    default = home.lang or ""
    base = home.canonical[0].rpartition("/")[0] + "/" if home.canonical else ""
    root = urlsplit(base).path or "/"
    # A further language has a folder at the root, de/ for German, whose home page
    # MkDocs built in that language.
    further = tuple(
        str(page.lang)
        for name, page in pages.items()
        if page.built
        and page.lang
        and page.lang != default
        and name == f"{page.lang}/index.html"
    )
    findings = [
        *canonicals(pages, required=bool(home.canonical)),
        *languages(pages, default, further),
        *private(pages),
        *heavy(site, pages),
        *emptied(pages),
    ]
    if repository is not None:
        folder, bases = layout(repository)
        languages_of_pages = (default, *further)
        findings += lost(pages, repository, docs or folder, languages_of_pages, bases)
    if further:
        findings += sitemaps(site, pages, base)
        findings += not_found(pages, default)
    return Site(default, further, root, tuple(findings))


def canonicals(pages: dict[str, Page], *, required: bool) -> list[Finding]:
    """Every page that MkDocs built names one canonical address, once the website has
    an address, site_url of mkdocs.yml; the 404 page belongs to none."""
    findings = []
    for name, page in pages.items():
        if not page.built or name == NOT_FOUND:
            continue
        count = len(page.canonical)
        if count > 1:
            findings.append(
                Finding(name, f"{count} canonical addresses; a page names one")
            )
        elif required and not count:
            findings.append(Finding(name, "no canonical address"))
    return findings


def languages(
    pages: dict[str, Page], default: str, further: tuple[str, ...]
) -> list[Finding]:
    """The versions of a page in other languages are full addresses, which search
    engines take, with the version of the default language once more as x-default;
    a page that a further language shows untranslated names the page of the default
    language as canonical, reads in that language and is no version of its own."""
    findings = []
    for name, page in pages.items():
        # GitHub Pages answers with the 404 page and its status, whose head no search
        # engine reads.
        if not page.alternates or name == NOT_FOUND:
            continue
        named = dict(page.alternates)
        relative = sorted({href for _, href in page.alternates if not absolute(href)})
        if relative:
            findings.append(
                Finding(
                    name,
                    "the versions in other languages need full addresses, which "
                    f"search engines take, not {', '.join(relative)}",
                )
            )
        if default and named.get("x-default") != named.get(default):
            wanted = named.get(default) or f"which names no {default}"
            findings.append(
                Finding(
                    name,
                    f"x-default names {named.get('x-default') or 'nothing'}, not the "
                    f"version of the default language, {wanted}",
                )
            )
    for language in further:
        prefix = f"{language}/"
        for name, page in pages.items():
            original = pages.get(name[len(prefix) :])
            if (
                not name.startswith(prefix)
                or original is None
                or not page.built
                or not page.canonical
                or page.canonical != original.canonical
            ):
                continue
            # The page names the page of the default language as its original: the
            # further language shows it untranslated.
            if default not in page.parts:
                findings.append(
                    Finding(
                        name,
                        f'is untranslated, so its text needs <div lang="{default}">, '
                        "in whose language a screen reader reads it",
                    )
                )
            if page.alternates:
                findings.append(
                    Finding(
                        name,
                        "is untranslated, a copy of the page of the default language, "
                        "and names no versions of its own",
                    )
                )
            if not page.unlisted:
                findings.append(
                    Finding(
                        name,
                        "is untranslated, a copy that no search engine may list, and "
                        'needs <meta name="robots" content="noindex, follow">',
                    )
                )
    return findings


def private(pages: dict[str, Page]) -> list[Finding]:
    """No page that MkDocs built has the browser of its reader ask another server:
    the link to the repository shows the version that the hooks read at the build."""
    return [
        Finding(
            name,
            "has the browser of every reader ask the API of GitHub for the numbers of "
            'the repository: data-md-component="source" on its link to the '
            "repository, which the hooks of the website take away",
        )
        for name, page in pages.items()
        if page.built and page.asks_github
    ]


def heavy(site: Path, pages: dict[str, Page]) -> list[Finding]:
    """No page that MkDocs built loads more than BUDGET at once."""
    root = site.resolve()
    sizes: dict[Path, int] = {}
    mermaid = next(iter(sorted(root.glob(MERMAID))), None)
    findings = []
    for name, page in pages.items():
        if not page.built:
            continue
        here = root / name
        files = [here]
        for address in (*page.loads, *page.search):
            path = local_file(root, here, address)
            if path is not None and path not in files:
                files.append(path)
        if page.draws_live and mermaid is not None:
            files.append(mermaid)
        weights = {path: weight(path, sizes) for path in files}
        total = sum(weights.values())
        if total <= BUDGET:
            continue
        most = sorted(weights, key=lambda path: -weights[path])[:3]
        parts = ", ".join(
            f"{path.relative_to(root).as_posix()} ({kilobytes(weights[path])})"
            for path in most
        )
        live = (
            "; it draws a diagram live from a ```mermaid block, which loads Mermaid: "
            "show the pictures of scripts/diagrams.sh in its place"
            if page.draws_live
            else ""
        )
        findings.append(
            Finding(
                name,
                f"loads {kilobytes(total)} at once, more than the {kilobytes(BUDGET)} "
                f"that a page may on a slow phone; the most: {parts}{live}",
            )
        )
    return findings


def local_file(root: Path, page: Path, address: str) -> Path | None:
    """The file of the website in root that address names from page, or None for
    an address of another server, from the root of the server, or of a file that
    the website lacks."""
    parts = urlsplit(address)
    if parts.scheme or parts.netloc or parts.path.startswith("/"):
        return None
    path = (page.parent / parts.path).resolve()
    if not path.is_relative_to(root) or not path.is_file():
        return None
    return path


def weight(path: Path, sizes: dict[Path, int]) -> int:
    """The bytes that GitHub Pages sends of the file at path: compressed, for a file
    of text."""
    if path not in sizes:
        data = path.read_bytes()
        text = path.suffix.lower() in TEXT
        sizes[path] = len(gzip.compress(data, 6, mtime=0)) if text else len(data)
    return sizes[path]


def kilobytes(size: int) -> str:
    return f"{size / 1000:,.0f} KB"


def absolute(href: str) -> bool:
    parts = urlsplit(href)
    return parts.scheme in ("http", "https") and bool(parts.netloc)


# The badges of a README, such as those of shields.io, of a workflow or of the
# Scorecard, which the hooks take out of every page on purpose: BADGE of
# .github/mkdocs_blueprint.py, which a test keeps the same.
BADGE = re.compile(
    r"https?://(?:img\.shields\.io/|api\.reuse\.software/badge/"
    r"|[^\s\"'<>]*/badge(?:\.svg)?(?=$|[?#]))",
    re.I,
)
# The pictures that the Markdown of a page shows: a <picture> with a picture for
# each scheme, an <img>, and ![text](address "title"), also within a link. A value
# of an attribute in quotes may hold a >.
TAG = r"(?:[^>\"']|\"[^\"]*\"|'[^']*')*"
PICTURE = re.compile(rf"<picture\b{TAG}>.*?</picture>", re.S | re.I)
IMAGE = re.compile(rf"<(?P<tag>img|source)\b{TAG}>", re.I)
ATTRIBUTE = re.compile(
    r"(?P<name>[^\s=/>\"']+)\s*=\s*"
    r"(?:\"(?P<double>[^\"]*)\"|'(?P<single>[^']*)'|(?P<bare>[^\s>\"']+))"
)
MARKDOWN_IMAGE = re.compile(
    r"!\[(?P<text>(?:[^\]\\]|\\.)*)\]\(\s*<?(?P<address>[^\s)>]+)>?"
    r"(?:\s+(?:\"[^\"]*\"|'[^']*'|\([^)]*\)))?\s*\)"
)
# What shows no picture: a comment of HTML, a span of code and a fenced block of
# code, in which a picture is an example.
COMMENT = re.compile(r"<!--.*?-->", re.S)
CODE_SPAN = re.compile(r"(?<!`)(?P<ticks>`+)(?!`).+?(?<!`)(?P=ticks)(?!`)")
FENCE = re.compile(r"[ \t]*(?P<fence>`{3,}|~{3,})")
# A line of a page that includes a file, --8<-- "README.md", a part of it,
# "README.md:how-it-works", or some of its lines, "README.md:3:10"; a line --8<--
# alone starts and ends a block with one file on each line. A part starts and ends at
# a line --8<-- [start:NAME] and [end:NAME], such as in a comment of HTML. As
# pymdownx.snippets reads them, from the root of the repository; SNIPPET of
# .github/mkdocs_blueprint.py is the same.
SNIPPET = re.compile(
    r"[ \t]*-+8<-+[ \t]*(?:(?P<quote>[\"'])(?P<name>.+?)(?P=quote))?[ \t]*"
)
SECTION = re.compile(
    r"-+8<-+[ \t]*\[[ \t]*(?P<kind>start|end)[ \t]*:[ \t]*"
    r"(?P<name>[a-z][-_0-9a-z]*)[ \t]*\]",
    re.I,
)
PART = re.compile(
    r"(?P<path>.*?)(?::(?P<section>[a-z][-_0-9a-z]*)|:(?P<start>\d*)(?::(?P<end>\d*))?)?",
    re.I,
)
# The keys of mkdocs.yml that say where the Markdown of the pages and the files
# that they include are: docs_dir, the options of pymdownx.snippets, its base_path
# in them, and the file that the configuration inherits.
VALUE = r"[ \t]*(?P<value>[^#\n]*?)[ \t]*(?:#[^\n]*)?$"
DOCS_DIR = re.compile(rf"^docs_dir:{VALUE}", re.M)
INHERIT = re.compile(rf"^INHERIT:{VALUE}", re.M)
SNIPPETS_BLOCK = re.compile(
    r"^(?P<indent>[ \t]*)(?:-[ \t]+)?pymdownx\.snippets:[ \t]*(?:#[^\n]*)?\n"
    r"(?P<block>(?:(?P=indent)[ \t]+\S[^\n]*\n?|[ \t]*\n)*)",
    re.M,
)
BASE_PATH = re.compile(
    rf"^[ \t]+base_path:{VALUE}(?P<items>(?:\n[ \t]+-[^\n]*)*)", re.M
)
ITEM = re.compile(r"^[ \t]+-[ \t]*([^#\n]*?)[ \t]*(?:#[^\n]*)?$", re.M)
# The picture of a diagram that scripts/diagrams.sh draws, which a hook of a project
# may show as its source, which the page draws live with Mermaid.
DIAGRAM = re.compile(r"(?:^|/)diagrams/[^/?#]+\.svg(?:$|[?#])", re.I)
# The endings that the website adds to the name of a picture: a further language,
# NAME.de.png, and the digest of the query of an address of another server, which the
# privacy plugin downloads as NAME.1a2b3c4d.png.
SUFFIXES = re.compile(r"(?:\.[a-z]{2}(?:[-_][A-Za-z]{2,4})*)?(?:\.[0-9a-f]{8})?$")


@dataclass(frozen=True)
class Picture:
    """A picture that the Markdown of a page shows: its address and its text as the
    Markdown has them, the names of its files, one for each scheme of a <picture>,
    and the file of the Markdown, such as README.md for a page that includes it."""

    address: str
    text: str
    names: frozenset[str]
    origin: Path


def emptied(pages: dict[str, Page]) -> list[Finding]:
    """No page that MkDocs built has an empty paragraph in its content: the
    Markdown writes none, and the hooks take out the one that held only badges, so
    a plugin took out what it held, such as a picture, as mkdocs-glightbox did with
    selectolax 1.0.0 and 1.0.1."""
    return [
        Finding(
            name,
            f'has an empty paragraph after "{shortened(before)}": a plugin or a hook '
            "took out what it held, such as a picture",
        )
        for name, page in pages.items()
        if page.built
        for before in page.content.empty
    ]


def lost(
    pages: dict[str, Page],
    root: Path,
    docs: str,
    languages: tuple[str, ...],
    bases: tuple[str, ...] = (".",),
) -> list[Finding]:
    """Every page that MkDocs built from a file of Markdown in root/docs shows each
    picture of it, and of the files that it includes, such as a part of the README:
    one of the files of the picture, which the hooks may serve from elsewhere, show
    as a video or as one picture for each scheme, or its text. A badge, which the
    hooks take out, needs to show nowhere, and neither does the picture of a diagram
    on a page that a hook of the project has draw it live from its source."""
    findings = []
    for built, source in sources(root / docs, languages, pages).items():
        page = pages[built]
        for picture in pictures_in(resolved(source, root, bases)):
            shown = page.content
            if picture.names & shown.names or picture.text in shown.texts:
                continue
            if page.draws_live and DIAGRAM.search(picture.address):
                continue
            origin = picture.origin.relative_to(root.resolve()).as_posix()
            findings.append(
                Finding(
                    built,
                    f"lacks the picture {picture.address} that {origin} shows: a "
                    "plugin or a hook took it out of the page, as mkdocs-glightbox "
                    "did with selectolax 1.0.0 and 1.0.1",
                )
            )
    return findings


def sources(
    folder: Path, languages: tuple[str, ...], pages: dict[str, Page]
) -> dict[str, Path]:
    """The file of Markdown in folder of each page that MkDocs built from one, by the
    path of the page: in a folder with both, index.md rather than README.md, which
    MkDocs leaves out then, also in a further language."""
    found: dict[str, Path] = {}
    # The files index.md first, on every system in the same order.
    for source in sorted(
        folder.rglob("*.md"),
        key=lambda path: (not path.name.lower().startswith("index."), path.as_posix()),
    ):
        built = built_from(source.relative_to(folder).as_posix(), languages, pages)
        if built is not None:
            found.setdefault(built, source)
    return found


def built_from(
    name: str, languages: tuple[str, ...], pages: dict[str, Page]
) -> str | None:
    """The page that MkDocs built from the file of Markdown at name in docs/:
    guide.md as guide.html, or as guide/index.html with directory addresses,
    index.md and README.md as the index.html of their folder, and guide.de.md of a
    further language as de/guide.html, of the default language as guide.html; None
    for a file of which it built no page."""
    folder, _, file = name.rpartition("/")
    stem = file.removesuffix(".md")
    head, _, language = stem.rpartition(".")
    prefix = ""
    if head and language in languages:
        stem = head
        prefix = "" if language == languages[0] else f"{language}/"
    base = prefix + (f"{folder}/" if folder else "")
    if stem.lower() in ("index", "readme"):
        candidates = [f"{base}index.html"]
    else:
        candidates = [f"{base}{stem}.html", f"{base}{stem}/index.html"]
    return next(
        (path for path in candidates if path in pages and pages[path].built), None
    )


def resolved(
    path: Path, root: Path, bases: tuple[str, ...] = (".",)
) -> list[tuple[str, Path]]:
    """The lines of the Markdown file at path, each with the file that it comes from,
    once the lines --8<-- include the files that they name, as pymdownx.snippets
    does: from the first of the folders bases of the repository that has the file,
    its base_path, and never from outside the repository."""
    path, root = path.resolve(), root.resolve()
    folders = tuple((root / base).resolve() for base in bases)
    return expanded(lines_of(path), path, Snippets(root, folders), (path,))


@dataclass(frozen=True)
class Snippets:
    """Where a line --8<-- finds its file: in the first of the folders that has it,
    inside the root of the repository."""

    root: Path
    folders: tuple[Path, ...]


def expanded(
    lines: list[str], origin: Path, root: Snippets, seen: tuple[Path, ...]
) -> list[tuple[str, Path]]:
    """lines of the file at origin, with the files that they include in place of
    their lines --8<--; a file that includes itself, also through another one, is
    included once, and a line that starts or ends a part of a file is left out."""
    found: list[tuple[str, Path]] = []
    block = False
    for line in lines:
        match = SNIPPET.fullmatch(line)
        if match is not None and match["name"] is None:
            block = not block
        elif match is not None and not block:
            found += included(match["name"], root, seen)
        elif block and match is None and not line.strip().startswith(";"):
            found += included(line.strip(), root, seen)
        elif not block and SECTION.search(line) is None:
            found.append((line, origin))
    return found


def included(
    name: str, root: Snippets, seen: tuple[Path, ...]
) -> list[tuple[str, Path]]:
    """The lines that a line --8<-- includes with name: a file of the repository,
    a part of it or some of its lines; none for a file that is missing, an address
    of another server or a file outside the repository."""
    part = PART.fullmatch(name)
    assert part is not None  # the pattern matches every name
    found = [(folder / part["path"].strip()).resolve() for folder in root.folders]
    path = next(
        (
            file
            for file in found
            if part["path"].strip()
            and file.is_relative_to(root.root)
            and file.is_file()
        ),
        None,
    )
    if path is None or path in seen:
        return []
    lines = lines_of(path)
    if part["section"]:
        lines = section_of(lines, part["section"])
    elif part["start"] is not None:
        start = int(part["start"]) - 1 if part["start"] else 0
        end = int(part["end"]) if part["end"] else None
        lines = lines[max(start, 0) : end]
    return expanded(lines, path, root, (*seen, path))


def layout(repository: Path) -> tuple[str, tuple[str, ...]]:
    """docs_dir of the mkdocs.yml of the repository, the folder of the Markdown of
    its pages, and the folders base_path of pymdownx.snippets in it, in which a line
    --8<-- finds its file, also from the file that it inherits; MkDocs' docs and the
    root of the repository where it names none."""
    docs = "docs"
    bases: tuple[str, ...] = (".",)
    for text in configurations(repository / "mkdocs.yml", ()):
        if (match := DOCS_DIR.search(text)) is not None:
            docs = unquoted(match["value"])
        block = SNIPPETS_BLOCK.search(text)
        base = None if block is None else BASE_PATH.search(block["block"])
        if base is not None:
            bases = folders_of(base) or bases
    return docs, bases


def configurations(path: Path, seen: tuple[Path, ...]) -> list[str]:
    """The texts of the configuration at path, after those of the file that it
    inherits, INHERIT; none for a file that is missing or inherits itself."""
    path = path.resolve()
    if path in seen or not path.is_file():
        return []
    text = path.read_text(encoding="utf-8", errors="replace")
    parent = INHERIT.search(text)
    if parent is None:
        return [text]
    inherited = path.parent / unquoted(parent["value"])
    return [*configurations(inherited, (*seen, path)), text]


def folders_of(base: re.Match[str]) -> tuple[str, ...]:
    """The folders that base_path names: one, a list in brackets or a list of items
    on the lines below it."""
    value = base["value"]
    if value.startswith("["):
        items = value.strip("[]").split(",")
    elif value:
        items = [value]
    else:
        items = ITEM.findall(base["items"])
    return tuple(unquoted(item) for item in items if unquoted(item))


def unquoted(value: str) -> str:
    value = value.strip()
    if len(value) > 1 and value[0] == value[-1] and value[0] in "\"'":
        return value[1:-1]
    return value


def section_of(lines: list[str], name: str) -> list[str]:
    """The lines of the part of a file between --8<-- [start:name] and [end:name],
    without the lines that start or end other parts in it."""
    inside = False
    found = []
    for line in lines:
        marker = SECTION.search(line)
        if marker is None:
            if inside:
                found.append(line)
        elif marker["name"] != name:
            continue
        elif marker["kind"].lower() == "start":
            inside = True
        else:
            break
    return found


def lines_of(path: Path) -> list[str]:
    return path.read_text(encoding="utf-8", errors="replace").splitlines()


def pictures_in(lines: list[tuple[str, Path]]) -> list[Picture]:
    """The pictures that the lines of Markdown show, each with the file of its line,
    other than those in comments of HTML and in code, and badges."""
    shown = outside_code([line for line, _ in lines])
    starts = list(accumulate((len(line) + 1 for line in shown[:-1]), initial=0))
    text = CODE_SPAN.sub(blank, COMMENT.sub(blank, "\n".join(shown)))
    # Each picture as its address, its text, the addresses of its files and where it
    # starts in text.
    found: list[tuple[str, str, list[str], int]] = []
    for match in PICTURE.finditer(text):
        tags = [
            (tag["tag"].lower(), attributes_of(tag[0]))
            for tag in IMAGE.finditer(match[0])
        ]
        image = next((attrs for kind, attrs in tags if kind == "img"), {})
        files = [attrs["src"] for _, attrs in tags if attrs.get("src")]
        files += [address for _, attrs in tags for address in candidates(attrs)]
        address = image.get("src") or next(iter(files), "")
        found.append((address, image.get("alt", ""), files, match.start()))
    text = PICTURE.sub(blank, text)
    for match in IMAGE.finditer(text):
        attrs = attributes_of(match[0])
        if match["tag"].lower() == "img":
            src = attrs.get("src", "")
            found.append((src, attrs.get("alt", ""), [src], match.start()))
    for match in MARKDOWN_IMAGE.finditer(text):
        words = re.sub(r"\\(.)", r"\1", match["text"])
        found.append((match["address"], words, [match["address"]], match.start()))
    pictures = []
    for address, words, files, position in sorted(found, key=lambda item: item[3]):
        names = frozenset(name_of(file) for file in files if pictured(file))
        if names:
            origin = lines[bisect_right(starts, position) - 1][1]
            pictures.append(Picture(address, " ".join(words.split()), names, origin))
    return pictures


def outside_code(lines: list[str]) -> list[str]:
    """The lines with those of fenced blocks of code, ``` or ~~~, empty."""
    fence = ""
    shown = []
    for line in lines:
        marker = FENCE.match(line)
        if not fence and marker is not None:
            fence = marker["fence"]
        elif fence and marker is not None and closes(line.strip(), fence):
            fence = ""
        elif not fence:
            shown.append(line)
            continue
        shown.append("")
    return shown


def closes(line: str, fence: str) -> bool:
    """Whether the line, without the spaces around it, ends the block of code that
    fence opened: as many of its marks at least, and nothing else."""
    return set(line) == {fence[0]} and len(line) >= len(fence)


def pictured(address: str) -> bool:
    """Whether the address names the file of a picture that the website shows:
    neither a badge, which the hooks take out, nor data within the page."""
    return (
        bool(address) and not address.startswith("data:") and not BADGE.match(address)
    )


def blank(match: re.Match[str]) -> str:
    """The text of match with each of its characters but line ends as a space, so
    that every other part of the text keeps its place."""
    return re.sub(r"[^\n]", " ", match[0])


def attributes_of(tag: str) -> dict[str, str]:
    """The attributes of a tag of HTML that have a value, by their names."""
    found = {}
    for match in ATTRIBUTE.finditer(re.sub(r"^<[\w-]+", "", tag)):
        values = (match["double"], match["single"], match["bare"])
        value = next(value for value in values if value is not None)
        found[match["name"].lower()] = html.unescape(value)
    return found


def candidates(found: dict[str, str]) -> list[str]:
    """The addresses of the candidates of the srcset of a tag."""
    words = (candidate.split() for candidate in found.get("srcset", "").split(","))
    return [candidate[0] for candidate in words if candidate]


def name_of(address: str) -> str:
    """The name of the file that address names, in lower case and without its folder,
    its ending and what the website adds to it, so that a picture has the same name
    in the Markdown and on the website: guide for images/guide.de.png and for
    https://example.org/guide.png#only-dark."""
    path = unquote(address.split("#")[0].split("?")[0])
    file = path.rpartition("/")[2]
    stem = file.rpartition(".")[0] or file
    return (SUFFIXES.sub("", stem, count=1) or stem).lower()


def shortened(text: str) -> str:
    """text, or as many of its words as fit into CONTEXT characters with an
    ellipsis."""
    if len(text) <= CONTEXT:
        return text
    return text[:CONTEXT].rsplit(" ", 1)[0].rstrip(",;:") + "…"


def sitemaps(site: Path, pages: dict[str, Page], base: str) -> list[Finding]:
    """The sitemap is in every folder of pages, the same everywhere, and lists no page
    that search engines must leave out."""
    sitemap = site / SITEMAP
    if not sitemap.is_file():
        return [Finding(SITEMAP, "the sitemap is missing")]
    text = sitemap.read_bytes()
    findings = []
    for folder in sorted({name.rpartition("/")[0] for name in pages} - {""}):
        copy = site / folder / SITEMAP
        if not copy.is_file():
            findings.append(
                Finding(
                    f"{folder}/{SITEMAP}",
                    "the sitemap is missing, which the language switch of the pages "
                    "of this folder reads",
                )
            )
        elif copy.read_bytes() != text:
            findings.append(
                Finding(
                    f"{folder}/{SITEMAP}", f"differs from the {SITEMAP} at the root"
                )
            )
    for address in LOCATION.findall(text.decode("utf-8", errors="replace")):
        address = html.unescape(address)
        if not base or not address.startswith(base):
            continue
        name = address.removeprefix(base)
        if not name or name.endswith("/"):
            name += "index.html"
        page = pages.get(name)
        if page is not None and page.unlisted:
            findings.append(
                Finding(SITEMAP, f"lists {address}, which no search engine may list")
            )
    return findings


def not_found(pages: dict[str, Page], default: str) -> list[Finding]:
    """The 404 page at the root, which GitHub Pages shows for every address that
    leads nowhere, is in the default language."""
    page = pages.get(NOT_FOUND)
    if page is None:
        return [Finding(NOT_FOUND, "the 404 page is missing")]
    if page.lang != default:
        return [
            Finding(
                NOT_FOUND,
                f'is in the language "{page.lang}", not in the default one, '
                f'"{default}"',
            )
        ]
    return []


def annotation(finding: Finding, folder: str) -> str:
    """The finding as an annotation of the CI, which the summary of the check shows,
    with its path in folder, where MkDocs built the website."""
    message = f"{folder}/{finding.path}: {finding.message}"
    for sign, code in (("%", "%25"), ("\r", "%0D"), ("\n", "%0A")):
        message = message.replace(sign, code)
    return f"::error title={TITLE}::{message}"
