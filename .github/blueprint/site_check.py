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
which the 404 page links the files of the website.
"""

from __future__ import annotations

import gzip
import html
import json
import re
from dataclasses import dataclass
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlsplit

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

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        found = {name: value or "" for name, value in attrs}
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

    def handle_endtag(self, tag: str) -> None:
        if tag == "script":
            self.script = None

    def handle_data(self, data: str) -> None:
        if self.script is not None:
            setattr(self, self.script, getattr(self, self.script) + data)

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


def check(site: Path) -> Site:
    """Check the website that MkDocs built into site."""
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
    ]
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
