"""The API of GitHub, through the GitHub CLI."""

from __future__ import annotations

import json
import re
import subprocess
import time
from collections.abc import Callable, Sequence
from dataclasses import dataclass, field
from typing import Any

Json = Any

# Answers that may be others a moment later: a gateway of GitHub that timed out, and a
# rate limit. A request tries once more after a wait: the time that GitHub names, or a
# few seconds after a gateway and a minute after a rate limit, as GitHub asks.
GATEWAY_ERRORS = frozenset({502, 503, 504})
GATEWAY_WAIT = 5
RATE_LIMIT_WAIT = 60
# A longer wait, such as for a rate limit that resets in half an hour, would only hold
# up the run: the request fails at once instead, and the next run tries again.
LONGEST_WAIT = 90
# All the waits of one run together: a run that asks many times, such as for each
# workflow of the bot health, stops waiting once a rate limit outlasts it, and then
# fails fast and reports what it couldn't do before its job times out.
WAIT_BUDGET = 120
# The most items that GitHub puts on a page of a list.
PAGE = 100
# The first GitHub CLI that reads every page into one list (gh api --slurp).
MINIMUM_GH = "2.48"


class GitHubError(Exception):
    """A request to GitHub failed."""

    def __init__(self, status: int, message: str, codes: Sequence[str] = ()) -> None:
        super().__init__(f"GitHub answered {status}: {message}")
        self.status = status
        self.message = message
        # Why GitHub refused the fields of a request, such as already_exists.
        self.codes = tuple(codes)

    @property
    def rate_limited(self) -> bool:
        return rate_limited(self.status, self.message)


def rate_limited(status: int, message: str) -> bool:
    """Whether GitHub refused a request for its rate limit, not for a permission."""
    return status in (403, 429) and "rate limit" in message.lower()


@dataclass(frozen=True)
class Response:
    status: int
    data: Json


Runner = Callable[[Sequence[str], str | None], subprocess.CompletedProcess[str]]


def run_gh(
    arguments: Sequence[str], body: str | None
) -> subprocess.CompletedProcess[str]:
    """Run the GitHub CLI; the tests replace this with a simulated GitHub."""
    return subprocess.run(  # pragma: no cover - the tests simulate gh
        ["gh", *arguments], input=body, capture_output=True, text=True, check=False
    )


def sleep(seconds: float) -> None:
    """Wait before a request tries again; the tests replace this, so none waits."""
    time.sleep(seconds)  # pragma: no cover - no test waits


def error_codes(data: Json) -> list[str]:
    """The codes of the errors of a refused request, such as already_exists."""
    errors = data.get("errors") if isinstance(data, dict) else None
    if not isinstance(errors, list):
        return []
    return [
        str(error["code"])
        for error in errors
        if isinstance(error, dict) and "code" in error
    ]


@dataclass(frozen=True)
class Answer:
    """What the GitHub CLI printed for a request."""

    status: int
    data: Json
    message: str
    headers: dict[str, str] = field(default_factory=dict)

    @classmethod
    def of_request(cls, result: subprocess.CompletedProcess[str]) -> Answer:
        """The answer of gh api --include: the status line, the headers and the body."""
        head, _, text = result.stdout.replace("\r\n", "\n").partition("\n\n")
        status_line, *lines = head.split("\n")
        try:
            status = int(status_line.split()[1])
        except (IndexError, ValueError):
            raise GitHubError(0, (result.stderr or result.stdout).strip()) from None
        headers = {}
        for line in lines:
            name, colon, value = line.partition(":")
            if colon:
                headers[name.strip().lower()] = value.strip()
        try:
            data = json.loads(text) if text.strip() else None
        except (ValueError, RecursionError):
            data = text
        message = str(data.get("message", "")) if isinstance(data, dict) else text
        return cls(status, data, message, headers)

    @classmethod
    def of_pages(cls, result: subprocess.CompletedProcess[str]) -> Answer:
        """The answer of gh api --paginate --slurp: every page in one list, whose items
        are the data. A page of a list such as that of the workflow runs holds its
        items in a field. On an error, gh ends its message with the status."""
        if result.returncode != 0:
            error = (result.stderr or "").strip()
            found = re.search(r"\(HTTP (\d{3})\)", error)
            status = int(found[1]) if found else 0
            # gh failed, whatever status its message names.
            if "unknown flag: --slurp" in error:
                raise GitHubError(
                    0, f"{error}; this needs GitHub CLI {MINIMUM_GH} or later"
                )
            if found is None or status < 400:
                raise GitHubError(status, error or result.stdout.strip())
            message = error.removeprefix("gh: ").replace(found[0], "").strip()
            return cls(status, None, message)
        try:
            pages = json.loads(result.stdout)
        except (ValueError, RecursionError):
            pages = None
        if not isinstance(pages, list):
            raise GitHubError(0, f"no list of pages: {result.stdout[:200]}")
        items: list[Json] = []
        for page in pages:
            if isinstance(page, list):
                items += page
            elif isinstance(page, dict):
                items += [
                    item
                    for value in page.values()
                    if isinstance(value, list)
                    for item in value
                ]
        return cls(200, items, "")

    def failed(self, missing_ok: bool) -> bool:
        return self.status >= 400 and not (missing_ok and self.status == 404)

    def wait(self, gateway: bool = True) -> float | None:
        """How long to wait before the request tries again; None when another try
        can't change the answer, or only after too long a wait. Without gateway, a
        gateway error isn't tried again: GitHub may have done the request anyway."""
        if gateway and self.status in GATEWAY_ERRORS:
            seconds: float = GATEWAY_WAIT
        elif rate_limited(self.status, self.message):
            seconds = RATE_LIMIT_WAIT
        else:
            return None
        after = self.headers.get("retry-after", "")
        reset = self.headers.get("x-ratelimit-reset", "")
        if after.isdecimal():
            seconds = int(after)
        elif self.headers.get("x-ratelimit-remaining") == "0" and reset.isdecimal():
            seconds = max(int(reset) - time.time(), 0) + 1
        return seconds if seconds <= LONGEST_WAIT else None


class Api:
    """The REST and GraphQL API of GitHub, through the GitHub CLI."""

    def __init__(self, runner: Runner = run_gh) -> None:
        self.runner = runner
        # The seconds that this Api may still wait before it tries a request again.
        self.budget: float = WAIT_BUDGET

    def answer(
        self,
        arguments: Sequence[str],
        payload: str | None,
        read: Callable[[subprocess.CompletedProcess[str]], Answer],
        missing_ok: bool,
        gateway: bool = True,
    ) -> Answer:
        """The answer to a request, which tries once more after a wait where that can
        change the answer and the budget allows it; GitHubError when GitHub refused it.
        Without gateway, a gateway error is the answer: for a request that isn't safe
        to send twice."""
        answer = read(self.runner(arguments, payload))
        wait = answer.wait(gateway) if answer.failed(missing_ok) else None
        if wait is not None and wait <= self.budget:
            self.budget -= wait
            sleep(wait)
            answer = read(self.runner(arguments, payload))
        if answer.failed(missing_ok):
            raise GitHubError(answer.status, answer.message, error_codes(answer.data))
        return answer

    def request(
        self, method: str, path: str, body: Json = None, *, missing_ok: bool = False
    ) -> Response:
        """A request that GitHub answered without an error. A 404 is one, unless
        missing_ok makes it the answer that nothing is there: GitHub also answers 404
        to a token that lacks the permission for a change, which then didn't happen."""
        arguments = ["api", "--include", "--method", method, path]
        payload = None
        if body is not None:
            arguments += ["--input", "-"]
            payload = json.dumps(body)
        # A POST that a gateway timed out has often been done all the same: sent
        # twice, it would open a second issue, comment or workflow run.
        answer = self.answer(
            arguments, payload, Answer.of_request, missing_ok, method != "POST"
        )
        return Response(answer.status, answer.data)

    def get(self, path: str) -> Json:
        """The data at path, or None when GitHub doesn't know it."""
        response = self.request("GET", path, missing_ok=True)
        return None if response.status == 404 else response.data

    def get_all(self, path: str) -> list[Json]:
        """Every item of the list at path, from all its pages, or [] when GitHub
        doesn't know it."""
        if "per_page=" not in path:
            path += f"{'&' if '?' in path else '?'}per_page={PAGE}"
        arguments = ["api", "--method", "GET", "--paginate", "--slurp", path]
        answer = self.answer(arguments, None, Answer.of_pages, missing_ok=True)
        return [] if answer.status == 404 else list(answer.data)

    def exists(self, path: str) -> bool:
        return self.request("GET", path, missing_ok=True).status != 404

    def put(self, path: str, body: Json = None) -> None:
        self.request("PUT", path, {} if body is None else body)

    def patch(self, path: str, body: Json) -> None:
        self.request("PATCH", path, body)

    def post(self, path: str, body: Json) -> None:
        self.request("POST", path, body)

    def delete(self, path: str, *, missing_ok: bool = False) -> None:
        """Delete what is at path; with missing_ok, what is gone already is no error,
        such as a label that someone took off a pull request a moment ago."""
        self.request("DELETE", path, missing_ok=missing_ok)

    def graphql(self, query: str) -> Json:
        result = self.runner(["api", "graphql", "-f", f"query={query}"], None)
        if result.returncode != 0:
            raise GitHubError(0, (result.stderr or result.stdout).strip())
        return json.loads(result.stdout)["data"]


def current_repo(api: Api) -> str:
    result = api.runner(
        ["repo", "view", "--json", "nameWithOwner", "--jq", ".nameWithOwner"], None
    )
    if result.returncode != 0:
        raise GitHubError(
            0,
            "this folder is no GitHub repository the CLI knows; pass --repo OWNER/NAME",
        )
    return result.stdout.strip()
