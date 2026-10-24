"""The weekly mutation tests of mutmut: the plan that splits the code into parts, the
results of each part, and the report of the whole run.

One job can't run every mutant of a large project within the six hours of GitHub:
the plan estimates the mutants of each module and splits the modules into parts of
about the same size, each a job of its own that runs only the mutants of its modules.
Each part keeps its results and the change of each mutant that survived, which mutmut
shows only where its copy of the repository, mutants/, is. The report of the run puts
the parts together into one score, lists first the survivors that change what the
code does, which a test should notice, and opens one issue when the score of main
drops below that of the run before; the issue closes once a run is back at that score.
"""

from __future__ import annotations

import ast
import configparser
import difflib
import fnmatch
import json
import math
import re
import tomllib
from collections import Counter
from collections.abc import Callable, Iterable, Mapping, Sequence
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any

from blueprint.coach import code, fence, html_code
from blueprint.github import Api

# A part of the run gets about so many mutants: at the half a mutant a second of a slow
# test suite on the four cores of a runner, as in the integration of
# homedarts-for-autodarts, that is under two hours, and a quick suite needs only one
# part.
PART_MUTANTS = 3000
MOST_PARTS = 8
# The score and the score of the logic count as dropped when they fall by more than
# so many points: a run measures the same code a little differently at times, as when
# a mutant hits its time limit.
TOLERANCE = 0.5
# The summary shows the changes of so many survivors of the logic and so many rows of
# each table; GitHub takes a summary of up to a MiB. The artifact keeps every one.
MOST_DIFFS = 100
MOST_ROWS = 20
MOST_FAILING = 30
# The functions of the run before a drop that its issue keeps, the most survivors first.
MOST_KEPT = 300
LAST_WORDS = 20
TITLE = "Mutation score dropped"
LABELS = ["maintenance"]
# Greedy: the state holds objects of its own, on the one line of the marker.
MARKER = re.compile(r"<!-- mutation-score (\{.*\}) -->")

# The kinds of survivors, in the order in which the summary lists them.
LOGIC = "logic"
VALUE = "value"
TEXT = "text"
KINDS = (LOGIC, VALUE, TEXT)

# ---------------------------------------------------------------- the plan


def kind_of(root: Path) -> str:
    """The tool of the mutation tests: Infection for the PHP of a Nextcloud app, mutmut
    for Python with its pinned requirements, or none."""
    if (root / "appinfo/info.xml").is_file():
        return "php"
    mutmut = (root / ".github/mutation-requirements.txt").is_file() or (
        root / "stacks/mutation/requirements.txt"
    ).is_file()
    if (root / ".python-version").is_file() and mutmut:
        return "python"
    return ""


def default_paths(root: Path) -> list[str]:
    """What the workflow mutates without a configuration of the project, as its step
    writes it into setup.cfg: custom_components/, src/, or the modules of the root and
    its packages without the tests."""
    for folder in ("custom_components", "src"):
        if (root / folder).is_dir():
            return [folder]
    modules = [
        path.name
        for path in root.glob("*.py")
        if path.is_file() and path.name != "conftest.py"
    ]
    packages = [
        path.parent.name
        for path in root.glob("*/__init__.py")
        if path.parent.name != "tests"
    ]
    return sorted(modules + packages)


def configuration(root: Path) -> tuple[list[str], list[str], list[str]] | None:
    """The source paths and the patterns of only_mutate and do_not_mutate of the
    project's own configuration of mutmut, which reads pyproject.toml first."""
    pyproject = root / "pyproject.toml"
    if pyproject.is_file():
        table = tomllib.loads(pyproject.read_text(encoding="utf-8"))
        mutmut = table.get("tool", {}).get("mutmut")
        if isinstance(mutmut, dict):

            def items(key: str) -> list[str]:
                value = mutmut.get(key, [])
                return [value] if isinstance(value, str) else [str(x) for x in value]

            return (
                items("source_paths") or items("paths_to_mutate"),
                items("only_mutate"),
                items("do_not_mutate"),
            )
    parser = configparser.ConfigParser()
    parser.read(root / "setup.cfg", encoding="utf-8")
    if not parser.has_section("mutmut"):
        return None

    def listed(key: str) -> list[str]:
        value = parser.get("mutmut", key, fallback="")
        return [item.strip() for item in value.split("\n") if item.strip()]

    return (
        listed("source_paths") or listed("paths_to_mutate"),
        listed("only_mutate"),
        listed("do_not_mutate"),
    )


def module_name(path: str) -> str:
    """The name under which mutmut names the mutants of a file: blueprint/cli.py has
    blueprint.cli.x_main__mutmut_1, src/ and __init__ leave no trace."""
    name = path.removesuffix(".py").replace("/", ".").removeprefix("src.")
    return name.removesuffix(".__init__")


def mutated(node: ast.FunctionDef | ast.AsyncFunctionDef) -> bool:
    """Whether mutmut changes a function: not one with a decorator other than a lone
    staticmethod or classmethod, which runs as the module loads."""
    if not node.decorator_list:
        return True
    only = node.decorator_list[0]
    return (
        len(node.decorator_list) == 1
        and isinstance(only, ast.Name)
        and only.id in ("staticmethod", "classmethod")
    )


def functions(
    tree: ast.Module,
) -> Iterable[tuple[str, ast.FunctionDef | ast.AsyncFunctionDef]]:
    """The functions of a module and the methods of its classes that mutmut changes,
    each with the name of its mutants, such as x_main or xǁRunǁof; a function within
    one is part of it."""
    for node in tree.body:
        members = node.body if isinstance(node, ast.ClassDef) else [node]
        prefix = f"xǁ{node.name}ǁ" if isinstance(node, ast.ClassDef) else "x_"
        for member in members:
            if isinstance(member, ast.FunctionDef | ast.AsyncFunctionDef) and mutated(
                member
            ):
                yield f"{prefix}{member.name}", member


# mutmut changes no type, no decorator and no text of an f-string, only the code in
# its braces.
SKIPPED = frozenset({"annotation", "returns", "decorator_list", "type_params"})
SWAPPED = frozenset(
    {"lower", "upper", "lstrip", "rstrip", "find", "rfind", "ljust", "rjust", "index"}
    | {"rindex", "removeprefix", "removesuffix", "partition", "rpartition"}
)


def parts_of(function: ast.AST) -> Iterable[ast.AST]:
    stack = [function]
    while stack:
        node = stack.pop()
        yield node
        if isinstance(node, ast.JoinedStr):
            stack += [
                child
                for value in node.values
                if isinstance(value, ast.FormattedValue)
                for child in (value.value, value.format_spec)
                if child is not None
            ]
            continue
        for field, value in ast.iter_fields(node):
            if field not in SKIPPED:
                children = value if isinstance(value, list) else [value]
                stack += [child for child in children if isinstance(child, ast.AST)]


def mutants_of(node: ast.AST, lines: Sequence[bytes]) -> int:
    """The mutants that mutmut 3 makes of one node, about."""
    if isinstance(node, ast.Constant):
        value = node.value
        if isinstance(value, str | bytes):
            # The offset counts the bytes of the line in UTF-8.
            start = lines[node.lineno - 1][node.col_offset :].lstrip(b"rbuRBU")
            if start.startswith((b'"""', b"'''")):
                return 0
            return 1 + (value != value.lower()) + (value != value.upper())
        return int(isinstance(value, bool | int | float | complex))
    if isinstance(node, ast.Call):
        values = [*node.args, *(keyword.value for keyword in node.keywords)]
        # An argument that is None already doesn't become None.
        nones = sum(
            isinstance(value, ast.Constant) and value.value is None for value in values
        )
        arguments = len(values)
        swapped = isinstance(node.func, ast.Attribute) and node.func.attr in SWAPPED
        return arguments - nones + (arguments if arguments > 1 else 0) + swapped
    if isinstance(node, ast.AugAssign | ast.IfExp):
        return 2
    if isinstance(node, ast.BoolOp):
        return len(node.values) - 1
    if isinstance(node, ast.Compare):
        return len(node.ops)
    if isinstance(node, ast.Match):
        return len(node.cases) if len(node.cases) > 1 else 0
    if isinstance(node, ast.AnnAssign):
        return int(node.value is not None)
    return int(
        isinstance(
            node,
            ast.BinOp
            | ast.UnaryOp
            | ast.Assign
            | ast.Lambda
            | ast.Break
            | ast.Continue,
        )
    )


def estimate(source: str) -> dict[str, int] | None:
    """About as many mutants as mutmut 3 makes of each function of a module: of each
    text up to three, of each number, operator, comparison, assignment and condition
    one or two, of each argument of a call one, and one more for leaving it out where
    a call has several. None for code that doesn't parse here, as of a newer Python."""
    try:
        tree = ast.parse(source)
    except SyntaxError:
        return None
    lines = source.encode("utf-8").splitlines()
    found: dict[str, int] = {}
    for name, function in functions(tree):
        found[name] = found.get(name, 0) + sum(
            mutants_of(node, lines) for node in parts_of(function)
        )
    return found


@dataclass(frozen=True)
class Unit:
    """Code that one part runs whole: a module, or a function of a large one, with
    the names of its mutants for mutmut run and the estimate of their number."""

    name: str
    patterns: tuple[str, ...]
    mutants: int


@dataclass(frozen=True)
class Module:
    """A module of mutmut, with the estimate of the mutants of each function."""

    name: str
    functions: dict[str, int]
    # The estimate of code that doesn't parse here, for the whole module.
    lines: int = 0

    @property
    def mutants(self) -> int:
        return sum(self.functions.values()) + self.lines

    def whole(self) -> Unit:
        """The module in one part: its functions start with x_, its methods with xǁ
        and the name of their class."""
        return Unit(self.name, (f"{self.name}.x_*", f"{self.name}.xǁ*"), self.mutants)

    def split(self) -> list[Unit]:
        """Each function in a part of its own. The name of a function that starts the
        name of another, as run does run_all, takes only its own mutants; the others
        also match the tests that cover them, which mutmut runs first."""
        if self.lines:
            return [self.whole()]
        units = []
        for function, count in self.functions.items():
            longer = any(
                other != function and other.startswith(function)
                for other in self.functions
            )
            pattern = f"{self.name}.{function}{'__mutmut_*' if longer else '*'}"
            units.append(Unit(f"{self.name}.{function}", (pattern,), count))
        return units


def modules(root: Path) -> list[Module]:
    """The modules that mutmut mutates, with the estimates of their functions."""
    paths, only, never = configuration(root) or ([], [], [])
    paths = paths or default_paths(root)
    files: set[str] = set()
    for item in paths:
        path = root / item
        if path.is_file():
            files.add(item)
        elif path.is_dir():
            files.update(
                child.relative_to(root).as_posix() for child in path.rglob("*.py")
            )
    found: dict[str, Module] = {}
    for name in sorted(files):
        if only and not any(fnmatch.fnmatchcase(name, pattern) for pattern in only):
            continue
        if any(fnmatch.fnmatchcase(name, pattern) for pattern in never):
            continue
        text = (root / name).read_text(encoding="utf-8", errors="replace")
        estimated = estimate(text)
        # blueprint.py and blueprint/__init__.py name their mutants alike.
        have = found.get(module_name(name), Module(module_name(name), {}))
        if estimated is None:
            have = Module(
                have.name, have.functions, have.lines + len(text.splitlines())
            )
        else:
            merged = dict(have.functions)
            for function, count in estimated.items():
                merged[function] = merged.get(function, 0) + count
            have = Module(have.name, merged, have.lines)
        found[have.name] = have
    # A module whose functions the estimate puts at none may still have mutants, as of
    # code that the estimate misses: only one without a function has none.
    return [module for module in found.values() if module.functions or module.lines]


def split(found: Sequence[Module], parts: int | None) -> list[list[Unit]]:
    """The code in parts of about the same number of mutants, each of which runs in
    one job: so many parts, or as many as the estimate asks for. A module larger than
    a part splits into its functions. Every unit is in a part, also one that the
    estimate puts at no mutant, so that every mutant belongs to a part: such units
    come last, into the smallest parts."""
    total = sum(module.mutants for module in found)
    if parts is None:
        parts = math.ceil(total / PART_MUTANTS)
    parts = max(1, min(parts, MOST_PARTS))
    share = total / parts
    units = [
        unit
        for module in found
        for unit in (module.split() if module.mutants > share else [module.whole()])
    ]
    counted = sum(1 for unit in units if unit.mutants)
    buckets: list[list[Unit]] = [[] for _ in range(min(parts, max(counted, 1)))]
    sizes = [0] * len(buckets)
    for unit in sorted(units, key=lambda unit: (-unit.mutants, unit.name)):
        smallest = sizes.index(min(sizes))
        buckets[smallest].append(unit)
        sizes[smallest] += unit.mutants
    return buckets


def plan(root: Path, parts: int | None) -> tuple[str, list[dict[str, Any]]]:
    """The tool and the parts of the run, as the matrix of the workflow takes them.
    One part runs every mutant; several each run those of their code."""
    kind = kind_of(root)
    if not kind:
        return "", []
    buckets = split(modules(root), parts) if kind == "python" else [[]]
    return kind, [
        {
            "part": number,
            "parts": len(buckets),
            # One on each line, for the shell of the step.
            "patterns": ""
            if len(buckets) == 1
            else "\n".join(pattern for unit in bucket for pattern in unit.patterns),
            "mutants": sum(unit.mutants for unit in bucket),
        }
        for number, bucket in enumerate(buckets, 1)
    ]


# ---------------------------------------------------------------- the kinds of survivors

TOKEN = re.compile(
    r"""(?P<comment>\#.*)
    | (?P<string>[A-Za-z]{0,3}(?:"(?:[^"\\\n]|\\.)*"|'(?:[^'\\\n]|\\.)*'))
    | (?P<name>[^\W\d]\w*)
    | (?P<number>\d[\w.]*)
    | (?P<operator>\*\*=?|//=?|<<=?|>>=?|[-+*/%@&|^<>!=:]=|->|\S)""",
    re.VERBOSE,
)
# A text compared with another decides a branch, as in `if kind == "php"`.
COMPARING = frozenset({"==", "!=", "in", "not", "is"})
# So does a text that the code looks up: the key of `data.get("score")`, of
# `os.getenv("CI")`, or the name of `getattr(item, "name")`.
KEY_FIRST = frozenset({"get", "pop", "setdefault", "getenv"})
KEY_SECOND = frozenset({"getattr", "hasattr", "setattr", "delattr"})
OPENING = frozenset({"(", ","})
CLOSING = frozenset({")", ","})
BRACKETS = {")": "(", "]": "[", "}": "{"}


def tokens(line: str) -> list[tuple[str, str]]:
    """The tokens of a line of Python, without comments: enough of a lexer for the
    lines of a change, which may begin or end within a statement. The code in the
    braces of an f-string is code, as mutmut changes it."""
    found = []
    for match in TOKEN.finditer(line):
        kind = str(match.lastgroup)
        text = match[0]
        if kind == "string" and "f" in text[: text.index(text[-1])].lower():
            found += formatted(text)
        elif kind != "comment":
            found.append((kind, text))
    return found


def formatted(text: str) -> list[tuple[str, str]]:
    """The tokens of an f-string: its texts, which mutmut leaves alone, and the
    tokens of the code in its braces."""
    quote = text[-1]
    prefix, _, body = text.partition(quote)
    body = body.removesuffix(quote)
    found: list[tuple[str, str]] = []
    literal = prefix + quote
    depth = start = index = 0
    while index < len(body):
        char = body[index]
        if not depth and body[index : index + 2] in ("{{", "}}"):
            literal += body[index : index + 2]
            index += 1
        elif not depth and char == "{":
            found.append(("string", literal))
            literal = ""
            depth, start = 1, index + 1
        elif not depth:
            literal += char
        elif char in "{}":
            depth += 1 if char == "{" else -1
            if not depth:
                found += tokens(body[start:index])
        index += 1
    if depth:
        found += tokens(body[start:])
    found.append(("string", literal + quote))
    return found


def enclosing(before: Sequence[str]) -> tuple[int, int]:
    """The place of the bracket that is still open before a span of a change, -1 for
    none, and how many commas lie between it and the span."""
    depth = commas = 0
    for index in range(len(before) - 1, -1, -1):
        text = before[index]
        if text in BRACKETS:
            depth += 1
        elif text in BRACKETS.values():
            if not depth:
                return index, commas
            depth -= 1
        elif text == "," and not depth:
            commas += 1
    return -1, commas


def decides(before: Sequence[str], after: Sequence[str]) -> bool:
    """Whether a text at this place decides what the code does: one that it compares,
    a key, as of `now["score"]`, `{"score": …}` or `.get("score")`, the name of
    getattr, or one of the texts after in, as in `state in ("killed", "survived")`."""
    previous = before[-1] if before else ""
    following = after[0] if after else ""
    if previous in COMPARING or following in COMPARING or following == ":":
        return True
    if previous == "[" and following == "]":
        return True
    index, commas = enclosing(before)
    if index < 0:
        return False
    name = before[index - 1] if index else ""
    if before[index] == "(" and (
        (name in KEY_FIRST and not commas) or (name in KEY_SECOND and commas == 1)
    ):
        return True
    return name == "in"


def kind_of_change(
    removed: Sequence[tuple[str, str]],
    added: Sequence[tuple[str, str]],
    before: Sequence[str],
    after: Sequence[str],
) -> str:
    """The kind of one span of a change, with the tokens before and after it."""
    if (
        removed
        and added
        and all(kind == "string" for kind, _ in [*removed, *added])
        and not decides(before, after)
    ):
        return TEXT
    texts = [text for _, text in added]
    if removed and texts == ["None"]:
        return VALUE
    if [text for _, text in removed] == ["None"] and added and added[0][0] == "string":
        return VALUE
    # An argument left out of a call: "a," after "(" or ",", "a" between them and
    # ")" or ",", or ",a" before ")".
    if removed and not added:
        previous = before[-1] if before else ""
        following = after[0] if after else ""
        first, last = removed[0][1], removed[-1][1]
        if (previous in OPENING and (following in CLOSING or last == ",")) or (
            first == "," and following == ")"
        ):
            return VALUE
    return LOGIC


def classify(diff: str) -> str:
    """What a mutant changes, from the diff of mutmut show: only a text, such as a
    message or a help; a value, which it sets to None or leaves out of a call; or the
    logic, such as an operator, a comparison, a number or a condition."""
    old: list[tuple[str, str]] = []
    new: list[tuple[str, str]] = []
    for line in diff.splitlines():
        if line.startswith(("---", "+++", "@@", "#")):
            continue
        if line.startswith(("-", " ")):
            old += tokens(line[1:])
        if line.startswith(("+", " ")):
            new += tokens(line[1:])
    texts = [text for _, text in old]
    matcher = difflib.SequenceMatcher(
        None, texts, [text for _, text in new], autojunk=False
    )
    kinds = set()
    for tag, i1, i2, j1, j2 in matcher.get_opcodes():
        if tag == "equal":
            continue
        # A deletion between equal tokens can sit one place further, as "a," for
        # ",a" of "f(x, a)": the kind of the change is that of any of its places.
        spans = [(i1, i2)]
        if tag == "delete":
            start, end = i1, i2
            while start and texts[start - 1] == texts[end - 1]:
                start, end = start - 1, end - 1
                spans.append((start, end))
            start, end = i1, i2
            while end < len(texts) and texts[start] == texts[end]:
                start, end = start + 1, end + 1
                spans.append((start, end))
        found = {
            kind_of_change(
                old[start:end],
                new[j1:j2],
                texts[:start],
                texts[end:],
            )
            for start, end in spans
        }
        # The mildest kind of its places, since all of them are the same change.
        kinds.add(next(kind for kind in reversed(KINDS) if kind in found))
    return next((kind for kind in KINDS if kind in kinds), LOGIC)


def function_of(name: str) -> str:
    """The function of a mutant as its code names it: blueprint.cli.x_main__mutmut_3
    is blueprint.cli.main, a.b.xǁRunǁof__mutmut_1 is a.b.Run.of."""
    mangled = name.partition("__mutmut_")[0]
    module, _, function = mangled.rpartition(".")
    if function.startswith("xǁ"):
        function = function.removeprefix("xǁ").replace("ǁ", ".")
    else:
        function = function.removeprefix("x_")
    return f"{module}.{function}" if module else function


# ---------------------------------------------------------------- one part

# A line of mutmut results, such as "    a.x_f__mutmut_1: survived".
RESULT = re.compile(r"^\s*(\S+__mutmut_\S+): (.+?)\s*$")
# A line of the short summary of pytest, "FAILED <test> - <message>".
FAILING = ("FAILED ", "ERROR ")
NOTHING_MATCHES = "Filtered for specific mutants, but nothing matches"

Shower = Callable[[str], str]


def last_words(output: str) -> list[str]:
    """The output of mutmut as the log shows it: the last state of each line that its
    spinner redraws with a carriage return."""
    lines = output.removesuffix("\n").split("\n") if output else []
    return [line.rsplit("\r", 1)[-1] for line in lines]


def id_of_test(text: str) -> str:
    """The id of a test at the start of text, up to the first space outside of its
    parameters, which may hold spaces and brackets, as `test[a - b]`."""
    depth = 0
    for index, char in enumerate(text):
        if char == "[":
            depth += 1
        elif char == "]" and depth:
            depth -= 1
        elif char == " " and not depth:
            return text[:index]
    return text


def failing_tests(output: str) -> list[str]:
    """The tests that pytest reports as failed or in error, once each."""
    found: list[str] = []
    for line in last_words(output):
        line = line.strip()
        prefix = next((start for start in FAILING if line.startswith(start)), "")
        test = id_of_test(line.removeprefix(prefix)) if prefix else ""
        if ("::" in test or test.endswith(".py")) and test not in found:
            found.append(test)
    return found


def collect(
    results: str,
    output: str,
    patterns: Sequence[str],
    status: int,
    part: tuple[int, int],
    show: Shower,
    workers: int = 4,
) -> dict[str, Any]:
    """The results of one part: the status of each of its mutants, the change and the
    kind of each survivor, and the tests that failed without any mutant."""
    statuses: dict[str, str] = {}
    generated = 0
    for line in results.splitlines():
        match = RESULT.match(line)
        if not match:
            continue
        generated += 1
        name, state = match[1], match[2]
        if not patterns or any(fnmatch.fnmatchcase(name, p) for p in patterns):
            statuses[name] = state
    survivors = sorted(name for name, state in statuses.items() if state == "survived")
    with ThreadPoolExecutor(max_workers=max(1, workers)) as pool:
        diffs = list(pool.map(show, survivors))
    measured = any(state in ("killed", "survived") for state in statuses.values())
    return {
        "part": part[0],
        "parts": part[1],
        "status": status,
        # A part whose modules have no mutant at all, which mutmut refuses to run.
        "empty": NOTHING_MATCHES in output,
        "generated": generated,
        "results": statuses,
        "survivors": [
            {"name": name, "kind": classify(diff), "diff": diff_of(diff)}
            for name, diff in zip(survivors, diffs, strict=True)
        ],
        "failing": [] if measured else failing_tests(output),
        "last_words": [] if measured else last_words(output)[-LAST_WORDS:],
    }


def diff_of(shown: str) -> str:
    """The lines of the change from the output of mutmut show, without its headers."""
    lines = shown.splitlines()
    start = next((i for i, line in enumerate(lines) if line.startswith("@@")), 0)
    return "\n".join(line for line in lines[start:] if not line.startswith("@@"))


def no_mutant_ran(part: Mapping[str, Any]) -> str:
    """Why a part measured nothing, for its summary and that of the run."""
    lines = []
    failing = list(part.get("failing") or [])
    if failing:
        lines += [
            "**The tests fail without any mutant.** mutmut first runs the tests once "
            "in its copy of the repository, `mutants/`, which has no Git and holds the "
            "code with every mutant, and these failed there:",
            "",
            *(f"- {code(test)}" for test in failing[:MOST_FAILING]),
        ]
        if len(failing) > MOST_FAILING:
            lines.append(f"- and {len(failing) - MOST_FAILING} more")
        lines += [
            "",
            "A test that can't pass in that copy, because it reads the code as text, "
            "asks Git or starts the tool, gets the marker "
            "`@pytest.mark.mutation_unsafe`; a test that runs under a cache of "
            "`functools` clears it with `cache_clear()`, since the stats of mutmut run "
            "every test in one process. mutmut stops at the first test that fails: "
            'after `mutmut run` on your machine, `python -m pytest -m "not '
            'mutation_unsafe"` in `mutants/` lists every one.',
            "",
        ]
    lines += [
        "No mutant ran. The end of the output of mutmut:",
        "",
        fence("text", part.get("last_words") or []),
    ]
    return "\n".join(lines)


# ---------------------------------------------------------------- the whole run


@dataclass(frozen=True)
class Report:
    """The parts of a run put together."""

    parts: int
    reported: list[dict[str, Any]]
    results: dict[str, str]
    survivors: list[dict[str, Any]]
    generated: int

    @classmethod
    def of(cls, found: Iterable[dict[str, Any]]) -> Report:
        reported = sorted(found, key=lambda part: int(part["part"]))
        results: dict[str, str] = {}
        survivors: dict[str, dict[str, Any]] = {}
        for part in reported:
            for name, state in part["results"].items():
                results.setdefault(name, state)
            for survivor in part["survivors"]:
                survivors.setdefault(survivor["name"], survivor)
        return cls(
            parts=max((int(part["parts"]) for part in reported), default=0),
            reported=reported,
            results=results,
            survivors=sorted(survivors.values(), key=lambda item: item["name"]),
            generated=max((int(part["generated"]) for part in reported), default=0),
        )

    def count(self, state: str) -> int:
        return sum(1 for value in self.results.values() if value == state)

    @property
    def killed(self) -> int:
        return self.count("killed")

    @property
    def survived(self) -> int:
        return self.count("survived")

    @property
    def other(self) -> int:
        return len(self.results) - self.killed - self.survived

    def of_kind(self, kind: str) -> list[dict[str, Any]]:
        return [survivor for survivor in self.survivors if survivor["kind"] == kind]

    @property
    def measured(self) -> bool:
        return self.killed + self.survived > 0

    @property
    def missing(self) -> list[int]:
        """The parts of the plan that sent no results, such as one whose job failed
        before its end."""
        have = {int(part["part"]) for part in self.reported}
        return [number for number in range(1, self.parts + 1) if number not in have]

    @property
    def silent(self) -> list[dict[str, Any]]:
        """The parts in which no mutant ran, other than one without mutants."""
        return [
            part
            for part in self.reported
            if not part.get("empty")
            and not any(
                state in ("killed", "survived") for state in part["results"].values()
            )
        ]

    @property
    def stopped(self) -> bool:
        return any(int(part["status"]) == 124 for part in self.reported)

    @property
    def unmatched(self) -> int:
        """The mutants that no part ran, of a module that the plan didn't know."""
        return 0 if self.missing else max(0, self.generated - len(self.results))

    @property
    def complete(self) -> bool:
        return (
            self.measured
            and not self.missing
            and not self.silent
            and not self.stopped
            and not self.unmatched
        )

    @property
    def score(self) -> float:
        return score(self.killed, self.survived)

    @property
    def logic_score(self) -> float:
        return score(self.killed, len(self.of_kind(LOGIC)))

    def by_function(self, kind: str) -> dict[str, int]:
        counts = Counter(function_of(item["name"]) for item in self.of_kind(kind))
        return dict(sorted(counts.items(), key=lambda item: (-item[1], item[0])))

    def data(self, run: str, now: datetime) -> dict[str, Any]:
        """What the next run compares with: mutation.json of the artifact."""
        return {
            "tool": "mutmut",
            "run": run,
            "date": now.isoformat(timespec="seconds"),
            "complete": self.complete,
            "parts": self.parts,
            "killed": self.killed,
            "survived": self.survived,
            "other": self.other,
            "score": round(self.score, 1),
            "logic_score": round(self.logic_score, 1),
            "kinds": {kind: len(self.of_kind(kind)) for kind in KINDS},
            "logic_by_function": self.by_function(LOGIC),
        }

    def results_text(self) -> str:
        """The results as mutmut results writes them, which the dashboard reads."""
        return "".join(
            f"    {name}: {state}\n" for name, state in sorted(self.results.items())
        )


def score(killed: int, survived: int) -> float:
    return 100 * killed / (killed + survived) if killed + survived else 0.0


def percent(value: float) -> str:
    return f"{value:.1f} %"


def dropped(now: Mapping[str, Any], before: Mapping[str, Any]) -> bool:
    """Whether the score or the score of the logic fell since before."""
    return any(
        float(now[key]) < float(before[key]) - TOLERANCE
        for key in ("score", "logic_score")
    )


def comparable(data: Mapping[str, Any] | None) -> bool:
    return bool(
        data
        and data.get("complete")
        and isinstance(data.get("score"), int | float)
        and isinstance(data.get("logic_score"), int | float)
    )


def baseline_of(data: Mapping[str, Any] | None) -> dict[str, Any] | None:
    """What a run compares with: the run before, or, when that was incomplete, the
    last complete run, which an incomplete run keeps as its baseline."""
    if comparable(data):
        assert data is not None
        return dict(data)
    kept = data.get("baseline") if data else None
    return dict(kept) if isinstance(kept, dict) and comparable(kept) else None


def run_link(data: Mapping[str, Any], words: str) -> str:
    run = str(data.get("run") or "")
    day = str(data.get("date") or "")[:10]
    text = f"{words} of {day}" if day else words
    return f"[{text}]({run})" if run.startswith("https://") else text


def survivor_details(report: Report, kind: str, most_diffs: int) -> list[str]:
    """The survivors of one kind by function, the most first, each with its change
    while the summary has room for it."""
    lines: list[str] = []
    shown = 0
    by_function: dict[str, list[dict[str, Any]]] = {}
    for survivor in report.of_kind(kind):
        by_function.setdefault(function_of(survivor["name"]), []).append(survivor)
    for function, count in report.by_function(kind).items():
        lines += [f"<details><summary>{html_code(function)}: {count}</summary>", ""]
        for survivor in by_function[function]:
            lines += [code(survivor["name"]), ""]
            if shown < most_diffs:
                lines += [fence("diff", survivor["diff"].splitlines()), ""]
                shown += 1
        lines += ["</details>", ""]
    if shown < len(report.of_kind(kind)):
        lines.append(
            f"The changes of the other {len(report.of_kind(kind)) - shown} are in "
            "`survivors.md` of the artifact *mutation*."
        )
    return lines


def table(counts: Mapping[str, int], most: int) -> list[str]:
    lines = ["| Function | Survivors |", "| --- | --- |"]
    rows = list(counts.items())
    lines += [f"| {code(function)} | {count} |" for function, count in rows[:most]]
    if len(rows) > most:
        rest = sum(count for _, count in rows[most:])
        lines.append(f"| {len(rows) - most} more functions | {rest} |")
    return lines


def summary(
    report: Report,
    now: Mapping[str, Any],
    previous: Mapping[str, Any] | None,
    *,
    most_diffs: int = MOST_DIFFS,
    most_rows: int = MOST_ROWS,
) -> str:
    """The summary of the run in Markdown: the score, the comparison with the run
    before, the survivors of the logic first with their changes, then the others."""
    lines = ["### Mutation tests (mutmut)", ""]
    parts = f", in {report.parts} parts" if report.parts > 1 else ""
    if report.measured:
        lines.append(
            f"**{percent(report.score)}** of the mutants were noticed by a test: "
            f"{report.killed} killed, {report.survived} survived, "
            f"{report.other} other{parts}."
        )
    else:
        lines.append(f"No mutant ran{parts}.")
    for number in report.missing:
        lines += [
            "",
            f"Part {number} of {report.parts} sent no results: its job failed.",
        ]
    if report.stopped:
        lines += [
            "",
            "The run stopped at its time limit; these are the mutants it reached.",
        ]
    if report.unmatched:
        lines += [
            "",
            f"{report.unmatched} mutants belong to no part of the plan, so none of "
            "them ran: the plan didn't know their function.",
        ]
    for part in report.silent:
        heading = (
            f"Part {part['part']} of {part['parts']}: " if report.parts > 1 else ""
        )
        lines += ["", f"{heading}{no_mutant_ran(part)}"]
    if not report.measured:
        return "\n".join(lines) + "\n"
    logic, value, text = (len(report.of_kind(kind)) for kind in KINDS)
    lines += [
        "",
        f"The score of the logic is **{percent(report.logic_score)}**: {logic} of the "
        f"survivors change what the code does, {value} pass None or leave out an "
        f"argument, and {text} change only a text.",
        "",
        comparison(now, previous),
    ]
    if logic:
        lines += [
            "",
            f"#### {logic} survivors change the logic",
            "",
            "Kill these first: each changes an operator, a comparison, a number or a "
            "condition, and no test noticed. A test that fails with the change kills "
            "its mutant; `mutmut show <name>` shows the change, and "
            '`mutmut run "<name>"` tries it again.',
            "",
            *survivor_details(report, LOGIC, most_diffs),
        ]
    if value:
        lines += [
            "",
            f"#### {value} survivors pass None or leave out an argument",
            "",
            "A test that checks what the call gets kills these; often it is a default "
            "that no test needs.",
            "",
            *table(report.by_function(VALUE), most_rows),
        ]
    if text:
        lines += [
            "",
            f"#### {text} survivors change only a text",
            "",
            "Mostly messages, help and log lines. A line whose text no test needs to "
            "check gets `# pragma: no mutate`, and mutmut leaves it alone.",
            "",
            *table(report.by_function(TEXT), most_rows),
        ]
    return "\n".join(lines).rstrip("\n") + "\n"


def comparison(now: Mapping[str, Any], previous: Mapping[str, Any] | None) -> str:
    if not comparable(now):
        return "The run is incomplete, so it is compared with no other."
    if not comparable(previous):
        return "No complete run before this one to compare with."
    assert previous is not None
    return (
        f"Since {run_link(previous, 'the run')}: the score "
        f"{percent(float(previous['score']))} → {percent(float(now['score']))}, the "
        f"score of the logic {percent(float(previous['logic_score']))} → "
        f"{percent(float(now['logic_score']))}."
    )


# ---------------------------------------------------------------- the issue


@dataclass(frozen=True)
class Issue:
    number: int
    baseline: dict[str, Any]


def open_issue_of(api: Api, repo: str) -> Issue | None:
    """The open issue of a drop, with the run before the drop in its marker."""
    listed = api.get(f"repos/{repo}/issues?labels={LABELS[0]}&state=open&per_page=100")
    for item in listed or []:
        match = MARKER.search(str(item.get("body") or ""))
        if "pull_request" in item or not match:
            continue
        try:
            baseline = json.loads(match[1]).get("baseline")
        except (ValueError, AttributeError):
            continue
        if comparable(baseline):
            return Issue(int(item["number"]), dict(baseline))
    return None


def logic_count(data: Mapping[str, Any]) -> int:
    kinds = data.get("kinds")
    return int(kinds.get(LOGIC, 0)) if isinstance(kinds, dict) else 0


def issue_body(now: Mapping[str, Any], baseline: Mapping[str, Any]) -> str:
    """The text of the issue: the scores before and now, and the functions with more
    survivors of the logic than before."""
    kept = {
        **baseline,
        "logic_by_function": dict(
            list(dict(baseline.get("logic_by_function") or {}).items())[:MOST_KEPT]
        ),
    }
    state = json.dumps({"baseline": kept}, sort_keys=True).replace("--", "-\\u002d")
    before = kept["logic_by_function"]
    grown = {
        function: int(count) - int(before.get(function, 0))
        for function, count in dict(now.get("logic_by_function") or {}).items()
        if int(count) > int(before.get(function, 0))
    }
    rows = sorted(grown.items(), key=lambda item: (-item[1], item[0]))[:MOST_ROWS]
    lines = [
        f"<!-- mutation-score {state} -->",
        f"The mutation score of the default branch dropped in {run_link(now, 'the run')}"
        f", below that of {run_link(baseline, 'the run')}: more mutants of the code "
        "survive, changes that the tests run but don't check.",
        "",
        "| | Before | Now |",
        "| --- | --- | --- |",
        f"| Score | {percent(float(baseline['score']))} | {percent(float(now['score']))} |",
        f"| Score of the logic | {percent(float(baseline['logic_score']))} | "
        f"{percent(float(now['logic_score']))} |",
        f"| Survivors that change the logic | {logic_count(baseline)} | "
        f"{logic_count(now)} |",
    ]
    if rows:
        lines += [
            "",
            "The functions with more survivors of the logic than before:",
            "",
            "| Function | More survivors |",
            "| --- | --- |",
            *(f"| {code(function)} | {count} |" for function, count in rows),
        ]
    lines += [
        "",
        "The summary of the run lists each survivor with its change. Kill those of the "
        "logic with a test; a text that no test needs to check gets "
        "`# pragma: no mutate`. This issue closes by itself once a weekly run is back "
        "at the score before.",
    ]
    return "\n".join(lines) + "\n"


def keep_issue(
    api: Api, repo: str, now: Mapping[str, Any], previous: Mapping[str, Any] | None
) -> str:
    """Opens the one issue when the score of a complete run dropped below that of the
    run before, keeps it current while it stays below, and closes it once a run is
    back; a line for the summary."""
    if not comparable(now):
        return "An incomplete run leaves the issue of the score as it is."
    issue = open_issue_of(api, repo)
    if issue is not None:
        if dropped(now, issue.baseline):
            api.patch(
                f"repos/{repo}/issues/{issue.number}",
                {"body": issue_body(now, issue.baseline)},
            )
            return f"The score is still below that before the drop: updated #{issue.number}."
        api.post(
            f"repos/{repo}/issues/{issue.number}/comments",
            {
                "body": f"{run_link(now, 'The run')} is back at the score of "
                f"{run_link(issue.baseline, 'the run')} before the drop: "
                f"{percent(float(now['score']))}."
            },
        )
        api.patch(
            f"repos/{repo}/issues/{issue.number}",
            {"state": "closed", "state_reason": "completed"},
        )
        return f"The score is back: closed #{issue.number}."
    if not comparable(previous) or not dropped(now, previous or {}):
        return "The score didn't drop."
    assert previous is not None
    response = api.request(
        "POST",
        f"repos/{repo}/issues",
        {"title": TITLE, "body": issue_body(now, previous), "labels": LABELS},
    )
    return f"The score dropped: opened #{response.data['number']}."


def read_parts(folder: Path) -> list[dict[str, Any]]:
    """The results of the parts that the jobs of the run kept."""
    found = []
    for path in sorted(folder.rglob("part-*.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data, dict) and isinstance(data.get("results"), dict):
            found.append(data)
    return found


def read_previous(path: Path | None) -> dict[str, Any] | None:
    """mutation.json of the run before, if its artifact had one."""
    if path is None or not path.is_file():
        return None
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except ValueError:
        return None
    return data if isinstance(data, dict) else None


def survivors_text(report: Report) -> str:
    """Every survivor with its change, for the artifact."""
    lines = ["# The surviving mutants", ""]
    for kind, heading in (
        (LOGIC, "Survivors that change the logic"),
        (VALUE, "Survivors that pass None or leave out an argument"),
        (TEXT, "Survivors that change only a text"),
    ):
        found = report.of_kind(kind)
        lines += [f"## {heading}: {len(found)}", ""]
        for survivor in found:
            lines += [
                f"### {code(survivor['name'])}",
                "",
                fence("diff", survivor["diff"].splitlines()),
                "",
            ]
    return "\n".join(lines).rstrip("\n") + "\n"
