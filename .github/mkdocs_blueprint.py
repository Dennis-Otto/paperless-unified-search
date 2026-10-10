"""The hooks of the documentation website, which the blueprint keeps current.

.github/mkdocs-blueprint.yml names this file under hooks. A project with hooks of
its own lists this file there too, since its list replaces that of the blueprint.
https://github.com/Dennis-Otto/repo-blueprint
"""

from __future__ import annotations

import shutil
import sys
import time
from pathlib import Path
from typing import Any

# The plugins of Material that download from other servers while the website is
# built: privacy the fonts and scripts that the pages use, social the fonts of the
# preview cards.
DOWNLOADING = ("material/privacy", "material/social")
# How often a download is tried before the plugin gets its error.
ATTEMPTS = 3


class Retrying:
    """The requests of a plugin, whose get tries a download again, after a second
    and then two, when the network or the server fails."""

    def __init__(self, requests: Any) -> None:
        self.requests = requests

    def __getattr__(self, name: str) -> Any:
        return getattr(self.requests, name)

    def get(self, url: str, **kwargs: Any) -> Any:
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
    """Let the plugins of Material try a download again before they give up.

    They try every file once: the privacy plugin warns when a font of Google Fonts
    doesn't come within 5 seconds, which fails a strict build, and the social plugin
    fails on any error of the network.
    """
    for name in DOWNLOADING:
        plugin = config.plugins.get(name)
        if plugin is None:
            continue
        module: Any = sys.modules[type(plugin).__module__]
        if not isinstance(module.requests, Retrying):
            module.requests = Retrying(module.requests)


def on_post_build(config: Any) -> None:
    """Copy the sitemap of a website in several languages into every folder of pages.

    The language switch of Material asks for sitemap.xml in the folder of the page in
    each language: decisions/ and de/decisions/ on decisions/0006-beta-channel.html.
    The i18n plugin writes a single sitemap with every language at the root, so every
    other folder that holds a page gets a copy.
    """
    plugin = config.plugins.get("i18n")
    site = Path(config.site_dir)
    sitemap = site / "sitemap.xml"
    if plugin is None or not sitemap.is_file():
        return
    if not any(lang.build and not lang.default for lang in plugin.config.languages):
        return
    for folder in {page.parent for page in site.rglob("*.html")} - {site}:
        shutil.copyfile(sitemap, folder / "sitemap.xml")


def on_post_template(output_content: str, template_name: str, config: Any) -> str:
    """Keep the 404 page of the default language at the root.

    The i18n plugin builds every further language into the same folder, and that
    build would write 404.html at the root again: in the further language, and with
    the language switch of the page that was built last. MkDocs writes no empty page.
    """
    plugin = config.plugins.get("i18n")
    further = plugin is not None and not plugin.is_default_language_build
    return "" if template_name == "404.html" and further else output_content
