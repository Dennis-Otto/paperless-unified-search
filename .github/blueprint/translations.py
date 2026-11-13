"""The catalogs of the translations of a project, which the step *Translations* of
scripts/check.sh checks.

Three kinds of projects ship them, each in a layout of its own:

- a Home Assistant integration: `custom_components/<domain>/translations/*.json`,
  translations of `strings.json`, or of `translations/en.json` without one, with nested
  keys and placeholders such as `{name}`; `en.json` is the copy of `strings.json`;
- a Nextcloud app: `l10n/<language>.json`, which PHP loads, and `l10n/<language>.js`,
  which the browser loads, with the English texts as their keys, placeholders such as
  `%s`, `%1$s`, `%n` and `{name}`, and plurals in as many forms as `pluralForm` names;
- a Chrome extension: `_locales/<language>/messages.json` beside its `manifest.json`,
  translations of the `default_locale`, with placeholders such as `$NAME$` that each
  message defines.

Every language has the texts of the source and no other, each text has words and keeps
the placeholders of the source, and the two files of a catalog of Nextcloud say the
same. A language that lacks a text, keeps a stale one or loses a placeholder would go
unnoticed until a user reports it.

`translations --fix` makes what a command can make of that, which the fix bot commits:
`en.json` becomes the copy of `strings.json`, every translation takes the order of its
source and loses the texts that the source no longer has, and each `l10n/<language>.js`
is written from its `l10n/<language>.json`. A text that a language lacks, or whose
placeholders differ, needs words that only a person can write, so the check names it.
What only a project knows, such as which texts its code uses, stays a check of its own.
"""

from __future__ import annotations

import json
import re
from collections import Counter
from collections.abc import Iterator, Mapping
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

# The placeholders of each layout: {name} of Home Assistant; %s, %1$s, %d, %n and
# {name} of Nextcloud; $NAME$ of Chrome, whose names are the same in any case.
HOME_ASSISTANT_PLACEHOLDER = re.compile(r"\{[^{}\s]+\}")
NEXTCLOUD_PLACEHOLDER = re.compile(r"%(?:(\d+)\$)?([sdfn])|\{[A-Za-z0-9_]+\}")
CHROME_PLACEHOLDER = re.compile(r"\$([A-Za-z0-9_@]+)\$")
# A plural of Nextcloud: its singular and its plural as the key of one text.
PLURAL = re.compile(r"_(.*)_::_(.*)_", re.DOTALL)
NPLURALS = re.compile(r"nplurals\s*=\s*(\d+)")
# l10n/<language>.js: OC.L10N.register("app", {texts}, "pluralForm");
REGISTER = re.compile(r"\s*OC\.L10N\.register\((?P<arguments>.*)\)\s*;?\s*", re.DOTALL)
# A string literal of JavaScript, in single or double quotes, or a comma before the
# end of an object or an array, which JavaScript allows and JSON doesn't.
JS_LITERAL = re.compile(
    r"(?P<string>'(?:[^'\\\n]|\\.)*'|\"(?:[^\"\\\n]|\\.)*\")"
    r"|(?<=[^\s{\[,])(?P<space>\s*),(?P<end>\s*[}\]])",
    re.DOTALL,
)
JS_ESCAPE = re.compile(
    r"\\(?:u\{(?P<code>[0-9a-fA-F]+)\}|u(?P<unicode>[0-9a-fA-F]{4})"
    r"|x(?P<hex>[0-9a-fA-F]{2})|(?P<other>.))",
    re.DOTALL,
)
JS_ESCAPES = {
    "n": "\n",
    "r": "\r",
    "t": "\t",
    "b": "\b",
    "f": "\f",
    "v": "\v",
    "0": "\0",
}
APP_ID = re.compile(r"<id>\s*([^<\s]+)\s*</id>")
# The folders that hold no catalogs of the project.
PACKAGES = frozenset({"node_modules", "vendor", ".git", ".venv"})


@dataclass(frozen=True)
class Finding:
    """What is wrong with a catalog, at a line of its file, or of none."""

    path: str
    message: str
    line: int = 0
    # Whether translations --fix fixes it.
    fixable: bool = False

    def text(self) -> str:
        place = f"{self.path}:{self.line}" if self.line else self.path
        return f"{place}: {self.message}"


@dataclass
class Result:
    """The catalogs that the check found, what is wrong with them, and the files that
    --fix wrote."""

    catalogs: int = 0
    findings: list[Finding] = field(default_factory=list)
    fixed: list[str] = field(default_factory=list)


def line_of(text: str, keys: tuple[str, ...]) -> int:
    """The line of a key of a JSON file, below the keys before it, or 0 without one."""
    position = 0
    for key in keys:
        found = re.compile(re.escape(json.dumps(key, ensure_ascii=False)) + r"\s*:")
        match = found.search(text, position)
        if match is None:
            return 0
        position = match.end()
    return text.count("\n", 0, position) + 1


def dumped(data: Any) -> str:
    """A catalog as the files of the template write it: the letters of a language as
    they are, and a line break at the end."""
    return json.dumps(data, indent=2, ensure_ascii=False) + "\n"


def write(root: Path, path: Path, text: str, result: Result) -> None:
    path.write_text(text, encoding="utf-8", newline="\n")
    result.fixed.append(path.relative_to(root).as_posix())


def read_json(root: Path, path: Path, result: Result) -> tuple[str, Any] | None:
    """The text of a JSON file and what it holds, or None, with a finding, for one that
    is no JSON."""
    text = path.read_text(encoding="utf-8")
    try:
        return text, json.loads(text)
    except ValueError as error:
        result.findings.append(
            Finding(path.relative_to(root).as_posix(), f"is no JSON: {error}")
        )
        return None


def quoted(names: Counter[str] | set[str]) -> str:
    return ", ".join(sorted(names)) if names else "none"


# ---------------------------------------------------------------- Home Assistant


def leaves(
    tree: Mapping[str, Any], prefix: tuple[str, ...] = ()
) -> dict[tuple[str, ...], Any]:
    """Every text of a tree by its path, such as config.step.user.title."""
    found: dict[tuple[str, ...], Any] = {}
    for key, value in tree.items():
        path = (*prefix, key)
        if isinstance(value, dict):
            found.update(leaves(value, path))
        else:
            found[path] = value
    return found


def ordered(source: Mapping[str, Any], translated: Mapping[str, Any]) -> dict[str, Any]:
    """The texts of a translation in the order of the source, without those that the
    source doesn't have; a text where the source has a group of texts, or the other way
    round, stays as it is for a person to decide."""
    result: dict[str, Any] = {}
    for key, value in source.items():
        if key not in translated:
            continue
        mine = translated[key]
        both = isinstance(value, dict) and isinstance(mine, dict)
        result[key] = ordered(value, mine) if both else mine
    return result


def home_assistant(root: Path, folder: Path, fix: bool, result: Result) -> None:
    """The translations of an integration against strings.json, or en.json."""
    strings = folder.parent / "strings.json"
    english = folder / "en.json"
    source_path = strings if strings.is_file() else english
    if not source_path.is_file():
        return
    result.catalogs += 1
    source_name = source_path.relative_to(root).as_posix()
    read = read_json(root, source_path, result)
    if read is None:
        return
    source_text, source = read
    if not isinstance(source, dict):
        result.findings.append(Finding(source_name, "holds no object of texts"))
        return
    if source_path == strings and (
        not english.is_file() or english.read_text(encoding="utf-8") != source_text
    ):
        if fix:
            write(root, english, source_text, result)
        else:
            result.findings.append(
                Finding(
                    english.relative_to(root).as_posix(),
                    f"is not the copy of {source_name}, which Home Assistant shows in "
                    "English",
                    fixable=True,
                )
            )
    texts = leaves(source)
    for path, value in texts.items():
        if not isinstance(value, str) or not value.strip():
            result.findings.append(
                Finding(
                    source_name,
                    f"has no words for {'.'.join(path)}",
                    line_of(source_text, path),
                )
            )
    for other in sorted(folder.glob("*.json")):
        if other in (source_path, english):
            continue
        translation(root, other, source, source_name, fix, result)


def translation(
    root: Path,
    path: Path,
    source: Mapping[str, Any],
    source_name: str,
    fix: bool,
    result: Result,
) -> None:
    """One translation of an integration against its source."""
    name = path.relative_to(root).as_posix()
    read = read_json(root, path, result)
    if read is None:
        return
    text, tree = read
    if not isinstance(tree, dict):
        result.findings.append(Finding(name, "holds no object of texts"))
        return
    wanted = ordered(source, tree)
    if json.dumps(wanted) != json.dumps(tree):
        if fix:
            write(root, path, dumped(wanted), result)
            text, tree = dumped(wanted), wanted
        else:
            stale = [key for key in leaves(tree) if key not in leaves(wanted)]
            result.findings.append(
                Finding(
                    name,
                    f"has {len(stale)} text(s) that {source_name} doesn't have, such "
                    f"as {'.'.join(stale[0])}"
                    if stale
                    else f"has its texts in another order than {source_name}",
                    line_of(text, stale[0]) if stale else 0,
                    fixable=True,
                )
            )
    mine = leaves(tree)
    texts = leaves(source)
    missing = [key for key in texts if key not in mine]
    if missing:
        result.findings.append(
            Finding(
                name,
                f"lacks {len(missing)} of the texts of {source_name}, such as "
                f"{'.'.join(missing[0])}: write them in its language",
            )
        )
    for key, value in mine.items():
        if key not in texts:
            continue
        where = line_of(text, key)
        dotted = ".".join(key)
        if not isinstance(value, str) or not value.strip():
            result.findings.append(Finding(name, f"has no words for {dotted}", where))
            continue
        theirs = set(HOME_ASSISTANT_PLACEHOLDER.findall(str(texts[key])))
        ours = set(HOME_ASSISTANT_PLACEHOLDER.findall(value))
        if ours != theirs:
            result.findings.append(
                Finding(
                    name,
                    f"shows the placeholders {quoted(ours)} in {dotted}, but "
                    f"{source_name} {quoted(theirs)}: each stands for a value, so "
                    "keep those of the source",
                    where,
                )
            )


# ---------------------------------------------------------------- Nextcloud


def nextcloud_placeholders(text: str) -> Counter[str]:
    """The placeholders of a text of Nextcloud, with a number for each %s without
    one, in its order, so that a translation may number them to change their order."""
    found: Counter[str] = Counter()
    count = 0
    for match in NEXTCLOUD_PLACEHOLDER.finditer(text):
        number, kind = match[1], match[2]
        if kind is None:
            found[match[0]] += 1
        elif kind == "n":
            found["%n"] += 1
        else:
            count += 1
            found[f"%{number or count}${kind}"] += 1
    return found


def browser_catalog(app: str, texts: Mapping[str, Any], plural: str) -> str:
    """l10n/<language>.js as Nextcloud writes it from l10n/<language>.json."""
    body = json.dumps(texts, indent="\t", ensure_ascii=False).replace("\n", "\n\t")
    return (
        f"OC.L10N.register(\n\t{json.dumps(app)},\n\t{body},\n\t"
        f"{json.dumps(plural, ensure_ascii=False)}\n)\n"
    )


def js_unescape(found: re.Match[str]) -> str:
    """The character that an escape of a JavaScript string stands for."""
    code = found["code"] or found["unicode"] or found["hex"]
    if code is not None:
        return chr(int(code, 16))
    other = found["other"]
    return "" if other in "\r\n\u2028\u2029" else JS_ESCAPES.get(other, other)


def js_to_json(text: str) -> str:
    """A literal of JavaScript as JSON: its strings in double quotes, no trailing commas.

    Nextcloud writes l10n/<language>.js in double quotes, a project's own tool may
    write it in single quotes and with a comma after the last text: both mean the same.
    """

    def literal(found: re.Match[str]) -> str:
        if found["string"] is None:
            return f"{found['space']}{found['end']}"
        value = JS_ESCAPE.sub(js_unescape, found["string"][1:-1])
        return json.dumps(value, ensure_ascii=False)

    return JS_LITERAL.sub(literal, text)


def registered(text: str) -> tuple[str, Any, str] | None:
    """The app, the texts and the rule of the plurals of l10n/<language>.js."""
    found = REGISTER.fullmatch(text)
    if found is None:
        return None
    try:
        arguments = json.loads(js_to_json(f"[{found['arguments']}]"))
    except ValueError:
        return None
    match arguments:
        case [str(app), dict(texts), str(plural)]:
            return app, texts, plural
    return None


def app_id(root: Path) -> str:
    info = root / "appinfo/info.xml"
    found = APP_ID.search(info.read_text(encoding="utf-8")) if info.is_file() else None
    return found[1] if found else root.resolve().name


def nextcloud(root: Path, folder: Path, fix: bool, result: Result) -> None:
    """The catalogs of l10n/: each against the others, and its .js against its .json."""
    catalogs: dict[str, dict[str, Any]] = {}
    for path in sorted(folder.glob("*.json")):
        name = path.relative_to(root).as_posix()
        result.catalogs += 1
        read = read_json(root, path, result)
        if read is None:
            continue
        text, data = read
        texts = data.get("translations") if isinstance(data, dict) else None
        plural = data.get("pluralForm") if isinstance(data, dict) else None
        if not isinstance(texts, dict) or not isinstance(plural, str):
            result.findings.append(
                Finding(
                    name,
                    'holds no object "translations" with a rule "pluralForm", such as '
                    '"nplurals=2; plural=(n != 1);"',
                )
            )
            continue
        catalogs[name] = texts
        browser(root, path, texts, plural, fix, result)
        forms = NPLURALS.search(plural)
        for key, value in texts.items():
            nextcloud_text(
                name, text, key, value, int(forms[1]) if forms else 0, result
            )
    every = {key: name for name, texts in catalogs.items() for key in texts}
    for name, texts in catalogs.items():
        missing = [key for key in every if key not in texts]
        if missing:
            result.findings.append(
                Finding(
                    name,
                    f"lacks {len(missing)} text(s) that {every[missing[0]]} has, such "
                    f"as {json.dumps(missing[0], ensure_ascii=False)}: translate them, "
                    "or remove them from every catalog when the code no longer uses "
                    "them",
                )
            )


def browser(
    root: Path,
    path: Path,
    texts: Mapping[str, Any],
    plural: str,
    fix: bool,
    result: Result,
) -> None:
    """l10n/<language>.js, which has to say what l10n/<language>.json says."""
    script = path.with_suffix(".js")
    name = script.relative_to(root).as_posix()
    found = registered(script.read_text(encoding="utf-8")) if script.is_file() else None
    if found is not None and found[1] == texts and found[2] == plural:
        return
    if fix:
        app = found[0] if found is not None else app_id(root)
        write(root, script, browser_catalog(app, texts, plural), result)
        return
    result.findings.append(
        Finding(
            name,
            f"doesn't say what {path.relative_to(root).as_posix()} says, which the "
            "browser would show otherwise than PHP"
            if script.is_file()
            else f"is missing: the browser loads the texts of "
            f"{path.relative_to(root).as_posix()} from it",
            fixable=True,
        )
    )


def nextcloud_text(
    name: str, text: str, key: str, value: Any, forms: int, result: Result
) -> None:
    """One text of a catalog of Nextcloud: its words, its placeholders and its forms."""
    where = line_of(text, ("translations", key))
    shown = json.dumps(key, ensure_ascii=False)
    plural = PLURAL.fullmatch(key)
    if plural is None:
        if not isinstance(value, str) or not value.strip():
            result.findings.append(Finding(name, f"has no words for {shown}", where))
            return
        compared = [(value, key)]
    else:
        if not isinstance(value, list) or len(value) != forms:
            result.findings.append(
                Finding(
                    name,
                    f"has {len(value) if isinstance(value, list) else 'no list of'} "
                    f"form(s) for {shown}, but its pluralForm names {forms}",
                    where,
                )
            )
            return
        if not all(isinstance(form, str) and form.strip() for form in value):
            result.findings.append(
                Finding(name, f"has a form without words for {shown}", where)
            )
            return
        # A form may write the count as a word, as "one document" does.
        compared = [(form, plural[2]) for form in value]
    for translated, source in compared:
        ours = nextcloud_placeholders(translated)
        theirs = nextcloud_placeholders(source)
        if plural is not None:
            del ours["%n"], theirs["%n"]
        if ours != theirs:
            result.findings.append(
                Finding(
                    name,
                    f"shows the placeholders {quoted(ours)} in "
                    f"{json.dumps(translated, ensure_ascii=False)}, but {shown} "
                    f"{quoted(theirs)}: each stands for a value, so keep them",
                    where,
                )
            )
            return


# ---------------------------------------------------------------- Chrome


def chrome_placeholders(message: str) -> set[str]:
    return {name.upper() for name in CHROME_PLACEHOLDER.findall(message)}


def chrome_defined(entry: Mapping[str, Any]) -> dict[str, Any]:
    defined = entry.get("placeholders")
    if not isinstance(defined, dict):
        return {}
    return {
        str(name).upper(): value.get("content") if isinstance(value, dict) else None
        for name, value in defined.items()
    }


def chrome(root: Path, manifest: Path, fix: bool, result: Result) -> None:
    """The messages of each language of _locales/ against the default_locale."""
    locales = manifest.parent / "_locales"
    try:
        default = json.loads(manifest.read_text(encoding="utf-8")).get("default_locale")
    except (ValueError, AttributeError):
        return
    if not isinstance(default, str) or not locales.is_dir():
        return
    source_path = locales / default / "messages.json"
    paths = sorted(locales.glob("*/messages.json"))
    result.catalogs += len(paths)
    if source_path not in paths:
        result.findings.append(
            Finding(
                manifest.relative_to(root).as_posix(),
                f"names the default_locale {default}, which _locales/ lacks",
            )
        )
        return
    source_name = source_path.relative_to(root).as_posix()
    read = read_json(root, source_path, result)
    if read is None:
        return
    source_text, source = read
    if not isinstance(source, dict):
        result.findings.append(Finding(source_name, "holds no object of messages"))
        return
    for path in paths:
        if path == source_path:
            messages(root, path, source_text, source, source, source_name, result)
            continue
        locale(root, path, source, source_name, fix, result)


def locale(
    root: Path,
    path: Path,
    source: Mapping[str, Any],
    source_name: str,
    fix: bool,
    result: Result,
) -> None:
    """The messages of one language against those of the default_locale."""
    name = path.relative_to(root).as_posix()
    read = read_json(root, path, result)
    if read is None:
        return
    text, catalog = read
    if not isinstance(catalog, dict):
        result.findings.append(Finding(name, "holds no object of messages"))
        return
    wanted = {key: catalog[key] for key in source if key in catalog}
    if list(wanted) != list(catalog):
        if fix:
            write(root, path, dumped(wanted), result)
            text, catalog = dumped(wanted), wanted
        else:
            stale = [key for key in catalog if key not in source]
            result.findings.append(
                Finding(
                    name,
                    f"has {len(stale)} message(s) that {source_name} doesn't have, "
                    f"such as {stale[0]}"
                    if stale
                    else f"has its messages in another order than {source_name}",
                    line_of(text, (stale[0],)) if stale else 0,
                    fixable=True,
                )
            )
    missing = [key for key in source if key not in catalog]
    if missing:
        result.findings.append(
            Finding(
                name,
                f"lacks {len(missing)} of the messages of {source_name}, such as "
                f"{missing[0]}: write them in its language",
            )
        )
    messages(root, path, text, catalog, source, source_name, result)


def messages(
    root: Path,
    path: Path,
    text: str,
    catalog: Mapping[str, Any],
    source: Mapping[str, Any],
    source_name: str,
    result: Result,
) -> None:
    """Each message of a language: its words, the placeholders that it shows, as the
    source shows them, and their definitions, with the content of the source."""
    name = path.relative_to(root).as_posix()
    for key, entry in catalog.items():
        where = line_of(text, (key,))
        message = entry.get("message") if isinstance(entry, dict) else None
        if not isinstance(message, str) or not message.strip():
            result.findings.append(Finding(name, f"has no words for {key}", where))
            continue
        original = source.get(key)
        if not isinstance(original, dict):
            continue
        ours = chrome_placeholders(message)
        theirs = chrome_placeholders(str(original.get("message", "")))
        defined, contents = chrome_defined(entry), chrome_defined(original)
        if ours != theirs:
            result.findings.append(
                Finding(
                    name,
                    f"shows the placeholders {quoted(ours)} in {key}, but "
                    f"{source_name} {quoted(theirs)}: each stands for a value, so "
                    "keep those of the source",
                    where,
                )
            )
        for placeholder in sorted(ours):
            if placeholder not in defined:
                result.findings.append(
                    Finding(
                        name,
                        f"shows ${placeholder}$ in {key}, which its placeholders don't "
                        "define",
                        where,
                    )
                )
            elif (
                placeholder in contents
                and defined[placeholder] != contents[placeholder]
            ):
                result.findings.append(
                    Finding(
                        name,
                        f"defines ${placeholder}$ of {key} as "
                        f"{json.dumps(defined[placeholder])}, but {source_name} as "
                        f"{json.dumps(contents[placeholder])}: it would show another "
                        "value",
                        where,
                    )
                )


# ---------------------------------------------------------------- the check


def manifests(root: Path) -> Iterator[Path]:
    """The manifests of Chrome extensions: in the root, or in a folder of it."""
    for path in [root / "manifest.json", *sorted(root.glob("*/manifest.json"))]:
        if path.is_file() and not PACKAGES & set(path.relative_to(root).parts):
            yield path


def check(root: Path, fix: bool = False) -> Result:
    """What is wrong with the catalogs of the translations below root; with fix, what
    a command can fix is fixed first, and the rest is left for a person."""
    result = Result()
    for folder in sorted(root.glob("custom_components/*/translations")):
        home_assistant(root, folder, fix, result)
    if (root / "l10n").is_dir():
        nextcloud(root, root / "l10n", fix, result)
    for manifest in manifests(root):
        chrome(root, manifest, fix, result)
    return result
