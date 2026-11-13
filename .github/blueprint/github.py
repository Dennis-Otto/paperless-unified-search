"""The API of GitHub, through the GitHub CLI."""

from __future__ import annotations

import json
import os
import re
import subprocess
import time
from collections.abc import Callable, Sequence
from dataclasses import dataclass, field
from http import HTTPStatus
from typing import Any

Json = Any

# Answers that may be others a moment later: an error of GitHub itself, a 5xx such as
# the "Internal server error" of its outage of 9 November 2026 or a gateway that timed
# out, a call that got no answer, and a rate limit. A request tries again after a wait:
# the time that GitHub names, or else after an error of GitHub three more times, 10, 20
# and 40 seconds apart, as the flaky-test bot does (decision 0133), and after a rate
# limit once, a minute later, as GitHub asks.
OUTAGE_WAITS = (10, 20, 40)
RATE_LIMIT_WAIT = 60
# What gh prints when GitHub gave no answer at all: its connection timed out, broke
# off (a bare EOF too) or was refused, or the call outlasted CALL_TIME_LIMIT; and the
# error of GraphQL that GitHub answers with 200 when it fails itself.
NO_ANSWER = re.compile(
    r"timed? ?out|deadline exceeded|connection (?:reset|refused)|unexpected EOF"
    r"|: EOF$|no answer from GitHub"
    r"|something went wrong while executing your query",
    re.IGNORECASE | re.MULTILINE,
)
# What run_command prints for a call that outlasted CALL_TIME_LIMIT.
STALLED = "no answer from GitHub within"
# The status that gh names in the error of a command, such as "HTTP 502: Bad Gateway"
# or "Not Found (HTTP 404)".
HTTP_STATUS = re.compile(r"\bHTTP (\d{3})\b")
# gh waits for an answer without a limit; a call that stalls this long in an outage
# ends as one without an answer, so that it tries again before its job times out. The
# time that it stalled counts against WAIT_BUDGET, as a wait does.
CALL_TIME_LIMIT = 90
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
# The version of GitHub's REST API that the blueprint is written for. Each call of the
# API asks for it, as GitHub recommends, so that a newer version that becomes GitHub's
# default changes no answer before the blueprint moves to it (decision 0125). The
# workflows ask with the same header, which tests/test_template.py keeps equal.
API_VERSION = "2022-11-28"
API_VERSION_HEADER = f"X-GitHub-Api-Version: {API_VERSION}"


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

    @property
    def unavailable(self) -> bool:
        """Whether GitHub failed, not the request: a 5xx, or no answer at all, which
        stays so after every try in an outage of GitHub (decision 0136)."""
        return self.status >= 500 or (
            self.status == 0 and NO_ANSWER.search(self.message) is not None
        )


def status_name(status: int) -> str:
    """The name of an HTTP status, such as Bad Gateway for 502, or "" for a status
    without one."""
    try:
        return HTTPStatus(status).phrase
    except ValueError:
        return ""


def rate_limited(status: int, message: str) -> bool:
    """Whether GitHub refused a request for its rate limit, not for a permission."""
    return status in (403, 429) and "rate limit" in message.lower()


@dataclass(frozen=True)
class Response:
    status: int
    data: Json


Runner = Callable[[Sequence[str], str | None], subprocess.CompletedProcess[str]]


def gh_command(arguments: Sequence[str]) -> list[str]:
    """The command of the GitHub CLI for the arguments: a call of the API asks at its
    end for the version of the API. Every runner of an Api starts gh with it."""
    command = ["gh", *arguments]
    if command[1:2] == ["api"]:
        command += ["--header", API_VERSION_HEADER]
    return command


def run_command(
    command: list[str], body: str | None, environment: dict[str, str] | None = None
) -> subprocess.CompletedProcess[str]:
    """Run a command of gh, in UTF-8, for CALL_TIME_LIMIT at most: a call that stalls
    ends as one that got no answer."""
    try:
        return subprocess.run(
            command,
            input=body,
            capture_output=True,
            text=True,
            encoding="utf-8",
            check=False,
            env=environment,
            timeout=CALL_TIME_LIMIT,
        )
    except subprocess.TimeoutExpired:
        return subprocess.CompletedProcess(
            command,
            1,
            "",
            f"gh: {STALLED} {CALL_TIME_LIMIT} seconds",
        )


def run_gh(
    arguments: Sequence[str], body: str | None
) -> subprocess.CompletedProcess[str]:
    """Run the GitHub CLI; the tests replace this with a simulated GitHub. gh reads
    and prints UTF-8 on every machine, and so does the runner: Python would otherwise
    take the code page of the locale, such as cp1252 on Windows, which can't read an
    emoji of a pull request and can't write one of a body, so gh would wait for the
    end of its input for ever."""
    return run_command(gh_command(arguments), body)


def run_gh_as(token: str) -> Runner:
    """The GitHub CLI with a token other than that of the environment, for a request
    that takes another right, such as those of the token of a job; in UTF-8, as
    run_gh."""

    def run(
        arguments: Sequence[str], body: str | None
    ) -> subprocess.CompletedProcess[str]:
        return run_command(
            gh_command(arguments), body, {**os.environ, "GH_TOKEN": token}
        )

    return run


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
            error = (result.stderr or result.stdout).strip()
            if NO_ANSWER.search(error):
                # No answer at all, which another try may change.
                return cls(0, None, error)
            raise GitHubError(0, error) from None
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
        items in a field. On an error, gh names the status: after GitHub's message,
        as "Not Found (HTTP 404)", or alone, as "HTTP 502", when the body of the error
        is no JSON of GitHub, such as the page of a gateway."""
        if result.returncode != 0:
            error = (result.stderr or "").strip()
            found = HTTP_STATUS.search(error)
            status = int(found[1]) if found else 0
            # gh failed, whatever status its message names.
            if "unknown flag: --slurp" in error:
                raise GitHubError(
                    0, f"{error}; this needs GitHub CLI {MINIMUM_GH} or later"
                )
            if found is None and NO_ANSWER.search(error):
                return cls(0, None, error)
            if found is None or status < 400:
                raise GitHubError(status, error or result.stdout.strip())
            # The message without the status, in either form; a bare status keeps
            # its name, such as Bad Gateway, or else its number.
            message = (
                error.removeprefix("gh: ")
                .replace(f"({found[0]})", "")
                .replace(found[0], "")
                .strip()
            )
            return cls(status, None, message or status_name(status) or found[0])
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

    @classmethod
    def of_command(cls, result: subprocess.CompletedProcess[str]) -> Answer:
        """The answer of a command of gh other than gh api --include, such as gh pr
        list or a query of GraphQL: what it printed, or the status that its error
        names, or none when GitHub gave no answer at all."""
        if result.returncode == 0:
            return cls(200, result.stdout, "")
        error = (result.stderr or result.stdout).strip()
        found = HTTP_STATUS.search(error)
        if found and int(found[1]) >= 400:
            return cls(int(found[1]), None, error)
        if NO_ANSWER.search(error):
            return cls(0, None, error)
        raise GitHubError(0, error)

    def failed(self, missing_ok: bool) -> bool:
        return (self.status >= 400 or self.status == 0) and not (
            missing_ok and self.status == 404
        )

    def wait(self, gateway: bool = True, tries: int = 1) -> float | None:
        """How long to wait before the request tries again, after it was sent tries
        times; None when another try can't change the answer, or only after too long
        a wait. Without gateway, an error of GitHub isn't tried again: GitHub may have
        done the request anyway."""
        after = self.headers.get("retry-after", "")
        if gateway and (self.status >= 500 or self.status == 0):
            if tries > len(OUTAGE_WAITS):
                return None
            seconds: float = OUTAGE_WAITS[tries - 1]
        elif tries == 1 and (
            rate_limited(self.status, self.message)
            # Too many requests, with the time to wait: what the code search answers
            # after a few searches in a minute, without a word of a rate limit.
            or (self.status == 429 and after.isdecimal())
        ):
            seconds = RATE_LIMIT_WAIT
        else:
            return None
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

    def sent(
        self,
        arguments: Sequence[str],
        payload: str | None,
        read: Callable[[subprocess.CompletedProcess[str]], Answer],
    ) -> Answer:
        """The answer to one try of a request. A try that stalled took CALL_TIME_LIMIT
        of the budget, so that a bot in an outage still ends long before its job's
        time limit."""
        answer = read(self.runner(arguments, payload))
        if answer.status == 0 and STALLED in answer.message:
            self.budget -= CALL_TIME_LIMIT
        return answer

    def answer(
        self,
        arguments: Sequence[str],
        payload: str | None,
        read: Callable[[subprocess.CompletedProcess[str]], Answer],
        missing_ok: bool,
        gateway: bool = True,
    ) -> Answer:
        """The answer to a request, which tries again after a wait where that can
        change the answer and the budget allows it; GitHubError when GitHub refused it
        or kept failing. Without gateway, an error of GitHub is the answer: for a
        request that isn't safe to send twice."""
        answer = self.sent(arguments, payload, read)
        tries = 1
        while answer.failed(missing_ok):
            wait = answer.wait(gateway, tries)
            if wait is None or wait > self.budget:
                break
            self.budget -= wait
            sleep(wait)
            answer = self.sent(arguments, payload, read)
            tries += 1
        if answer.failed(missing_ok):
            raise GitHubError(answer.status, answer.message, error_codes(answer.data))
        return answer

    def request(
        self,
        method: str,
        path: str,
        body: Json = None,
        *,
        missing_ok: bool = False,
        retry: bool | None = None,
    ) -> Response:
        """A request that GitHub answered without an error. A 404 is one, unless
        missing_ok makes it the answer that nothing is there: GitHub also answers 404
        to a token that lacks the permission for a change, which then didn't happen.
        With retry, an error of GitHub is asked again also for a POST that only
        reads, such as a query of GraphQL."""
        arguments = ["api", "--include", "--method", method, path]
        payload = None
        if body is not None:
            arguments += ["--input", "-"]
            payload = json.dumps(body)
        # A POST that GitHub failed, such as one that a gateway timed out, has often
        # been done all the same: sent twice, it would open a second issue, comment
        # or workflow run.
        gateway = method != "POST" if retry is None else retry
        answer = self.answer(arguments, payload, Answer.of_request, missing_ok, gateway)
        return Response(answer.status, answer.data)

    def get(self, path: str) -> Json:
        """The data at path, or None when GitHub doesn't know it."""
        response = self.request("GET", path, missing_ok=True)
        return None if response.status == 404 else response.data

    def get_all(self, path: str, *, missing_ok: bool = True) -> list[Json]:
        """Every item of the list at path, from all its pages, or [] when GitHub
        doesn't know it; without missing_ok, that is an error like any other."""
        if "per_page=" not in path:
            path += f"{'&' if '?' in path else '?'}per_page={PAGE}"
        arguments = ["api", "--method", "GET", "--paginate", "--slurp", path]
        answer = self.answer(arguments, None, Answer.of_pages, missing_ok=missing_ok)
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

    def command(self, arguments: Sequence[str]) -> str:
        """What a command of gh that only reads printed, such as gh pr list, which is
        asked again after an error of GitHub, as a request is."""
        answer = self.answer(arguments, None, Answer.of_command, missing_ok=False)
        return str(answer.data)

    def graphql(self, query: str) -> Json:
        """The data of a query of GraphQL, which only reads."""
        return json.loads(self.command(["api", "graphql", "-f", f"query={query}"]))[
            "data"
        ]


def current_repo(api: Api) -> str:
    result = api.runner(
        ["repo", "view", "--json", "nameWithOwner", "--jq", ".nameWithOwner"], None
    )
    if result.returncode != 0:
        # gh's own words tell a missing sign-in or a network error apart from a
        # folder that is no repository of GitHub.
        said = result.stderr.strip() or f"gh repo view ended with {result.returncode}"
        raise GitHubError(
            0,
            f"{said}\nIf this folder is no GitHub repository the CLI knows, "
            "pass --repo OWNER/NAME.",
        )
    return result.stdout.strip()
