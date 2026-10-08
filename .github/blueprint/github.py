"""The API of GitHub, through the GitHub CLI."""

from __future__ import annotations

import json
import subprocess
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from typing import Any

Json = Any


class GitHubError(Exception):
    """A request to GitHub failed."""

    def __init__(self, status: int, message: str) -> None:
        super().__init__(f"GitHub answered {status}: {message}")
        self.status = status


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


class Api:
    """The REST and GraphQL API of GitHub, through the GitHub CLI."""

    def __init__(self, runner: Runner = run_gh) -> None:
        self.runner = runner

    def request(self, method: str, path: str, body: Json = None) -> Response:
        arguments = ["api", "--include", "--method", method, path]
        payload = None
        if body is not None:
            arguments += ["--input", "-"]
            payload = json.dumps(body)
        result = self.runner(arguments, payload)
        head, _, text = result.stdout.replace("\r\n", "\n").partition("\n\n")
        status_line = head.split("\n", 1)[0]
        try:
            status = int(status_line.split()[1])
        except (IndexError, ValueError):
            raise GitHubError(0, (result.stderr or result.stdout).strip()) from None
        try:
            data = json.loads(text) if text.strip() else None
        except (ValueError, RecursionError):
            data = text
        if status >= 400 and status != 404:
            message = data.get("message", "") if isinstance(data, dict) else text
            raise GitHubError(status, message)
        return Response(status, data)

    def get(self, path: str) -> Json:
        """The data at path, or None when GitHub doesn't know it."""
        response = self.request("GET", path)
        return None if response.status == 404 else response.data

    def exists(self, path: str) -> bool:
        return self.request("GET", path).status != 404

    def put(self, path: str, body: Json = None) -> None:
        self.request("PUT", path, {} if body is None else body)

    def patch(self, path: str, body: Json) -> None:
        self.request("PATCH", path, body)

    def post(self, path: str, body: Json) -> None:
        self.request("POST", path, body)

    def delete(self, path: str) -> None:
        self.request("DELETE", path)

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
