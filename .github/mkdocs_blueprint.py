"""The hooks of the documentation website, which the blueprint keeps current.

.github/mkdocs-blueprint.yml names this file under hooks. A project with hooks of
its own lists this file there too, since its list replaces that of the blueprint.
https://github.com/Dennis-Otto/repo-blueprint
"""

from __future__ import annotations

import shutil
from pathlib import Path
from typing import Any


def on_post_build(config: Any) -> None:
    """Give every further language of the website the sitemap.

    The language switch of Material looks up a page in the sitemap next to the pages
    of the other language, such as de/sitemap.xml. The i18n plugin writes a single
    sitemap with every language at the root, so each further language gets a copy.
    """
    plugin = config.plugins.get("i18n")
    sitemap = Path(config.site_dir) / "sitemap.xml"
    if plugin is None or not sitemap.is_file():
        return
    for language in plugin.config.languages:
        if language.build and not language.default:
            folder = sitemap.parent / language.locale
            folder.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(sitemap, folder / "sitemap.xml")
