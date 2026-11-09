"""The social preview of a repository: the picture that GitHub shows for links to it,
and whether it is the one that the repository holds.

GitHub takes the social preview only from an upload under Settings → General → Social
preview, which no API offers, so a new `.github/social-preview.png` reaches the cards
of links only once a person uploads it again. GitHub serves the uploaded file byte for
byte at the `openGraphImageUrl` of the repository: right after an upload, the sha256
of that download is the sha256 of the committed picture, and it differs once the
picture changes and nobody uploads it. The checklist and the bot-health bot compare
the two, in a public repository: GitHub shows the social preview of no private one.
"""

from __future__ import annotations

import hashlib
import re
import urllib.error
import urllib.parse
import urllib.request
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from blueprint import github
from blueprint.github import Api

# The page that scripts/social-preview.sh renders, and the picture that it renders.
SOURCE = ".github/social-preview.html"
PICTURE = ".github/social-preview.png"
# The attribute of the panel that a new repository starts with: a drawing of its kind
# of project, made from the answers of copier copy. A repository made before it keeps
# the card of the blueprint's checks that came first, the same in every repository,
# since the page belongs to the project.
DEFAULT_PANEL = "data-default-panel"
DEFAULT_PANELS = (DEFAULT_PANEL, '<div class="card checks">')
# The host that serves an uploaded social preview. Without an upload, GitHub draws a
# card of its own elsewhere; the bots reach no other host for it, and the job of the
# bot-health bot that compares the pictures allows only this one besides GitHub's API.
HOST = "repository-images.githubusercontent.com"
# GitHub takes an upload of at most 1 MB: a larger answer is no upload of the picture,
# and its sha256 differs all the same.
LARGEST = 1024 * 1024
TIMEOUT = 30
# A host that times out or fails on its side once gets one more try after a pause, as
# the requests to GitHub's API do, so that one bad answer doesn't fail the nightly look.
ATTEMPTS = 2
PAUSE = 5
# GitHub shares the uploaded picture only from a public repository, and takes a new
# upload in a private one only where someone uploaded one before: until the repository
# is public, nobody sees the picture, so the bots compare nothing and download no
# upload that GitHub may serve to no one without a token.
PRIVATE = (
    "The repository is private, and GitHub shows the social preview only for a public"
    " repository"
)


class Unreadable(Exception):
    """The uploaded picture couldn't be read, so nobody can tell whether it is current."""


@dataclass(frozen=True)
class Preview:
    """The sha256 of the committed picture, and whether someone uploaded a social
    preview and the sha256 of that upload; empty where there is none."""

    picture: str
    uploaded: bool
    shown: str = ""
    # Whether GitHub takes the committed picture at all: an upload is at most 1 MB.
    fits: bool = True
    # Whether GitHub shows the social preview at all: only for a public repository.
    public: bool = True

    @property
    def current(self) -> bool:
        return bool(self.picture) and self.shown == self.picture


def settings_page(repo: str) -> str:
    """Where a person uploads it: the social preview is a part of Settings → General."""
    return f"https://github.com/{repo}/settings"


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def shows_the_project(root: Path) -> bool:
    """Whether the page shows the project, not the default panel of its kind."""
    page = root / SOURCE
    text = page.read_text(encoding="utf-8") if page.exists() else ""
    # The template's comment names the mark that the project takes out: only the
    # markup counts, not what a comment says of it.
    text = re.sub(r"<!--.*?-->", "", text, flags=re.DOTALL)
    return not any(mark in text for mark in DEFAULT_PANELS)


def download(url: str, opener: Callable[..., Any] | None = None) -> bytes:
    """The uploaded picture, from GitHub's host of uploads and no other."""
    parts = urllib.parse.urlsplit(url)
    if parts.scheme != "https" or parts.hostname != HOST:
        raise Unreadable(f"GitHub names {url}, not a picture on {HOST}")
    attempt = 1
    while True:
        try:
            # The opener of urllib unless a test names another, at the time of the call.
            with (opener or urllib.request.urlopen)(url, timeout=TIMEOUT) as response:
                return bytes(response.read(LARGEST + 1))
        except OSError as error:
            # A refusal of the host, such as 404, stays the same when asked again.
            passing = True
            if isinstance(error, urllib.error.HTTPError):
                passing = error.code >= 500
                # The error is the answer too, and holds its body until it is closed.
                error.close()
            if not passing or attempt >= ATTEMPTS:
                raise Unreadable(f"{url}: {error}") from error
        attempt += 1
        github.sleep(PAUSE)


def preview(
    api: Api,
    repo: str,
    root: Path,
    opener: Callable[..., Any] | None = None,
) -> Preview:
    """The committed picture and the uploaded one; the upload is read only when there
    is a picture to compare it with, in a public repository."""
    owner, name = repo.split("/", 1)
    data = api.graphql(
        f'{{ repository(owner: "{owner}", name: "{name}") '
        "{ isPrivate usesCustomOpenGraphImage openGraphImageUrl } }"
    )["repository"]
    uploaded = bool(data["usesCustomOpenGraphImage"])
    public = not data["isPrivate"]
    path = root / PICTURE
    data_of_picture = path.read_bytes() if path.is_file() else b""
    picture = digest(data_of_picture) if path.is_file() else ""
    fits = len(data_of_picture) <= LARGEST
    if not (picture and uploaded and public):
        return Preview(picture, uploaded, fits=fits, public=public)
    return Preview(
        picture,
        uploaded,
        digest(download(data["openGraphImageUrl"], opener)),
        fits,
    )
