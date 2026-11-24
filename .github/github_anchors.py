"""The anchors of the headings of the documentation website, as GitHub makes them.

A Markdown extension, which the blueprint keeps current: markdown_extensions of
.github/mkdocs-blueprint.yml names it, so that MkDocs, and Zensical after the move of
decision 0146, load it as they load every other extension (decision 1117). The slugify of the
extension toc makes the anchor of a heading, as before; this extension only hands it
the text of the heading as GitHub reads it, since toc collapses every run of
whitespace into one space before its slugify sees the text:

- a no-break space (U+00A0) and a narrow no-break space (U+202F), which French puts
  before ?, !, : and ;, stay what they are, so that the slugify drops them as GitHub
  does, where toc made them a hyphen;
- two spaces, also those around inline HTML such as `A <br> B`, stay two, so that
  they become two hyphens as on GitHub;
- an emoji shortcode such as :dart: counts with its name, as on GitHub, where the
  emoji that pymdownx.emoji puts in its place would count with nothing.

Everything else, such as escapes, entities, inline code, links and accents, comes
out as toc reads it. An id that a heading already has, such as {#id} of attr_list,
stays, and a repeated heading is numbered as toc numbers it, with _1, _2.
https://github.com/Dennis-Otto/repo-blueprint
"""

from __future__ import annotations

import html
import re
import xml.etree.ElementTree as etree
from copy import deepcopy
from typing import Any

from markdown import Markdown
from markdown.extensions import Extension
from markdown.extensions.toc import (
    TocTreeprocessor,
    remove_fnrefs,
    render_inner_html,
    unique,
)
from markdown.inlinepatterns import HTML_RE
from markdown.treeprocessors import Treeprocessor

# After attr_list (8), whose {#id} a heading keeps, and abbr (7), and before toc (5)
# and the anchors that MkDocs collects for its strict check at the same priority as
# toc, so that the permalinks, the table of contents, the search and that check all
# take the ids of this extension.
PRIORITY = 6
# An emoji shortcode, as pymdownx.emoji puts it into the title of its emoji: :dart:,
# :+1:, :man_technologist:.
SHORTCODE = re.compile(r":[^:\s]+:")


class GitHubAnchors(Treeprocessor):
    """Gives each heading without an id the anchor that GitHub gives it."""

    def run(self, root: etree.Element) -> None:
        # Without toc, no heading gets an id: then this extension gives none either.
        if "toc" not in self.md.treeprocessors:
            return
        toc = self.md.treeprocessors["toc"]
        assert isinstance(toc, TocTreeprocessor)
        # As toc does it: the ids that the page has already are taken, and a repeat
        # gets the first free _1, _2, so that every id but those of the headings
        # that GitHub reads otherwise stays as toc made it.
        used = {
            element.attrib["id"] for element in root.iter() if "id" in element.attrib
        }
        counters: dict[str, int] = {}
        emoji = emoji_class(self.md)
        for element in root.iter():
            if (
                isinstance(element.tag, str)
                and toc.header_rgx.match(element.tag)
                and "id" not in element.attrib
            ):
                text = text_of(element, self.md, emoji)
                element.attrib["id"] = unique(
                    toc.slugify(text, toc.sep), used, counters
                )


def emoji_class(md: Markdown) -> str | None:
    """The class that pymdownx.emoji gives each emoji of this page, or None without
    the extension."""
    if "emoji" not in md.inlinePatterns:
        return None
    pattern: Any = md.inlinePatterns["emoji"]
    index: str = pattern.emoji_index["name"]
    classes: str = pattern.options.get("classes", index)
    return classes


def text_of(heading: etree.Element, md: Markdown, emoji: str | None) -> str:
    """The text of a heading as GitHub reads it for its anchor: as toc reads it, with
    each emoji as its shortcode, and with its whitespace as it is."""
    heading = deepcopy(heading)
    for element in heading.iter():
        title = element.get("title", "")
        if emoji and element.get("class") == emoji and SHORTCODE.fullmatch(title):
            # The emoji as the text of its shortcode, which the slugify takes, with
            # the text that follows it.
            tail = element.tail
            element.clear()
            element.tag, element.text, element.tail = "span", title, tail
    text = render_inner_html(remove_fnrefs(heading), md)
    # As strip_tags of toc, without the collapse of the whitespace that follows it
    # there: a comment first, since a comment could hold a tag, then the tags.
    while (start := text.find("<!--")) != -1 and (end := text.find("-->", start)) != -1:
        text = text[:start] + text[end + 3 :]
    return html.unescape(re.sub(HTML_RE, "", text, flags=re.DOTALL))


class GitHubAnchorsExtension(Extension):
    """The extension that markdown_extensions names."""

    def extendMarkdown(self, md: Markdown) -> None:
        md.treeprocessors.register(GitHubAnchors(md), "github_anchors", PRIORITY)


def makeExtension(**kwargs: Any) -> GitHubAnchorsExtension:
    """The extension, as Python-Markdown asks a module for it by its name."""
    return GitHubAnchorsExtension(**kwargs)
