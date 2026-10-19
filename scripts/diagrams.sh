#!/usr/bin/env bash
# The diagrams of the documentation, as pictures that every reader sees: GitHub draws
# Mermaid only in the browser, while its app, the stores of the projects and other
# renderers of Markdown show the source as code. Each diagram is Mermaid in
# docs/diagrams/NAME.mmd, drawn as docs/images/diagrams/NAME.svg on a white card and as
# NAME.dark.svg for a dark theme, which the Markdown shows with <picture>. The website
# draws the source itself, with the hook in .github/mkdocs_blueprint.py. A source that
# no page shows and that has no picture yet may belong to a way of the project's own
# to draw it, and stays as it is.
#
#   bash scripts/diagrams.sh            draw the pictures of every source that a page
#                                       shows anew (Docker)
#   bash scripts/diagrams.sh --extract  turn every ```mermaid block of the Markdown into
#                                       a source and its picture, then draw them
#   bash scripts/diagrams.sh --check    without Docker, what scripts/check.sh checks: no
#                                       ```mermaid block, both pictures of every source
#                                       that a page shows, a source for every picture,
#                                       and the alt of each, which repeats its source
#   bash scripts/diagrams.sh --adopted  whether the pages show pictures of diagrams yet;
#                                       until then, the checks only warn of a block
#
# The blueprint keeps this file current: https://github.com/Dennis-Otto/repo-blueprint
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# The command line of mermaid with its Chromium, which Renovate keeps current.
image="minlag/mermaid-cli:12.0.1@sha256:2c5d27365df71da06c288beebe531ea3637cf9b422b871239a3b016233591d24"
sources=docs/diagrams
pictures=docs/images/diagrams
# The tags of HTML in labels other than <br>, as python3 blueprint.py diagrams extract
# finds them.
html='</?(a|b|code|em|font|i|kbd|mark|s|small|span|strong|sub|sup|tt|u)([[:space:]][^>]*)?>'
# The lines of a page that open a ```mermaid block, also in a list or a quote, other
# than those in another block of code, such as the example of a page, and in a comment
# of HTML: the blocks that python3 blueprint.py diagrams extract takes out. A program
# of awk, whose $0 is no variable of bash.
# shellcheck disable=SC2016
fences='
{
  text = $0
  sub(/\r$/, "", text)
  if (comment) {
    if (index(text, "-->")) comment = 0
    next
  }
  raw = text
  sub(/^([ \t]*>)+[ \t]?/, "", text)
  run = ""
  if (match(text, /^[ \t]*(```+|~~~+)/)) {
    run = substr(text, RSTART, RLENGTH)
    info = substr(text, RSTART + RLENGTH)
    sub(/^[ \t]*/, "", run)
  }
  if (fence != "") {
    if (run != "" && index(run, fence) == 1 && info !~ /[^ \t]/) fence = ""
    next
  }
  if (run != "") {
    fence = run
    if (info ~ /^[ \t]*mermaid([ \t]|$)/) print FNR
    next
  }
  if (raw ~ /^ ? ? ?<!--/ && !index(substr(raw, index(raw, "<!--") + 4), "-->")) comment = 1
}'

usage() {
  sed -n 's/^#   //p' "${BASH_SOURCE[0]}"
}

# The Markdown of the repository, without what git ignores.
markdown() {
  git ls-files -z --cached --others --exclude-standard -- '*.md'
}

# A picture of a diagram, as a page shows it.
shown='images/diagrams/[A-Za-z0-9._-]+\.svg'

# The names of the sources that this script draws: those whose picture a page shows,
# and those that have a picture already. Another source in docs/diagrams/ may belong
# to a way of the project's own to draw it, which this script leaves alone.
drawn_here() {
  local file name wanted=$'\n'
  while IFS= read -r file; do
    name="${file##*/}"
    name="${name%.svg}"
    wanted+="${name%.dark}"$'\n'
  done < <(
    for file in "$pictures"/*.svg; do
      [[ -f "$file" ]] && echo "$file"
    done
    git grep --untracked -hoE "$shown" -- '*.md' || true
  )
  for file in "$sources"/*.mmd; do
    name="${file##*/}"
    name="${name%.mmd}"
    case "$wanted" in
      *$'\n'"$name"$'\n'*) [[ -f "$file" ]] && echo "$name" ;;
    esac
  done
}

# Python 3.12 or newer, for blueprint.py, or nothing.
python_here() {
  local candidate
  for candidate in python3 python py; do
    if "$candidate" -c 'import sys; sys.exit(sys.version_info < (3, 12))' >/dev/null 2>&1; then
      echo "$candidate"
      return 0
    fi
  done
}

# blueprint.py, in .github/ of a repository made from the blueprint.
tool() {
  if [[ -f .github/blueprint.py ]]; then
    echo .github/blueprint.py
  else
    echo blueprint.py
  fi
}

# Whether the repository shows its diagrams as pictures: a picture is drawn, or a page
# shows one. Until then, a ```mermaid block is a warning, so that an update of the
# blueprint passes before its diagrams moved.
adopted() {
  local file
  for file in "$pictures"/*.svg; do
    [[ -f "$file" ]] && return 0
  done
  git grep --untracked -qE "$shown" -- '*.md'
}

# The checks, which need neither Docker nor Python; with Python, the alt of each
# picture as well.
check() {
  local -a found=() blocks=() problems=()
  local file name picture line in_use=false python said others=0
  if adopted; then
    in_use=true
    while IFS= read -r name; do
      found+=("$sources/$name.mmd")
    done < <(drawn_here)
  fi
  while IFS= read -r -d '' file; do
    [[ -f "$file" ]] || continue
    while IFS= read -r line; do
      blocks+=("$file:$line: a \`\`\`mermaid block, which the GitHub app shows as code")
    done < <(awk "$fences" "$file")
    while IFS=: read -r line picture; do
      if [[ ! -f "$pictures/${picture##*/}" ]]; then
        problems+=("$file:$line: shows $pictures/${picture##*/}, which doesn't exist")
      fi
    done < <(grep -noE "$shown" "$file" || true)
  done < <(markdown)
  for file in "${found[@]}"; do
    name="$(basename "$file" .mmd)"
    for picture in "$pictures/$name.svg" "$pictures/$name.dark.svg"; do
      if [[ ! -f "$picture" ]]; then
        problems+=("$file: its picture $picture is missing")
      fi
    done
    # Labels are text of the picture, where HTML other than <br> shows as it is.
    while IFS=: read -r line _; do
      problems+=("$file:$line: HTML, which the picture shows as text; only <br> breaks a line, and **bold** and *italic* need a label in \"\`…\`\"")
    done < <(grep -niE "$html" "$file" || true)
  done
  for picture in "$pictures"/*.svg; do
    [[ -f "$picture" ]] || continue
    name="$(basename "$picture" .svg)"
    if [[ ! -f "$sources/${name%.dark}.mmd" ]]; then
      problems+=("$picture: its source $sources/${name%.dark}.mmd is missing")
    fi
  done
  # The alt of each picture repeats the accTitle and the accDescr of its source, as
  # blueprint.py compares them where Python is at hand, as in the CI.
  if [[ "$in_use" == true ]]; then
    python="$(python_here)"
    if [[ -n "$python" && -f "$(tool)" ]]; then
      if ! said="$("$python" "$(tool)" diagrams check)"; then
        [[ -n "$said" ]] || said="$(tool) diagrams check failed; the error above says why."
        while IFS= read -r line; do
          problems+=("$line")
        done <<<"$said"
      fi
    fi
  fi

  local extract="bash scripts/diagrams.sh --extract turns each into a picture of a source in $sources/."
  if ((${#blocks[@]} > 0)); then
    printf '%s\n' "${blocks[@]}"
    if [[ "$in_use" == true ]]; then
      problems+=("${#blocks[@]} diagram(s) in a \`\`\`mermaid block; $extract")
    else
      echo "A warning for now: $extract"
    fi
  fi
  if ((${#problems[@]} > 0)); then
    printf '%s\n' "${problems[@]}"
    echo "bash scripts/diagrams.sh --extract fixes what it can, with Docker: it takes the blocks out, writes the alt of each picture and draws the pictures anew."
    return 1
  fi
  for file in "$sources"/*.mmd; do
    [[ -f "$file" ]] && others=$((others + 1))
  done
  others=$((others - ${#found[@]}))
  if ((${#found[@]} > 0 && others > 0)); then
    echo "Each of the ${#found[@]} diagram(s) in $sources/ that a page shows has its pictures; no page shows the other $others source(s), which are left as they are."
  elif ((${#found[@]} > 0)); then
    echo "Each of the ${#found[@]} diagram(s) in $sources/ has its pictures."
  elif compgen -G "$sources/*.mmd" > /dev/null; then
    echo "No page shows a picture of $pictures/ yet, so the sources in $sources/ are left as they are."
  elif ((${#blocks[@]} == 0)); then
    echo "No diagrams here."
  fi
}

# Draws every source that a page shows in a container without network, which gets
# the sources and the script of node through stdin and gives the pictures back
# through stdout, so that they belong to whoever runs this. A picture whose source is
# gone goes too.
render() {
  local -a names=()
  local name
  while IFS= read -r name; do
    names+=("$name.mmd")
  done < <(drawn_here)
  if ((${#names[@]} == 0)); then
    rm -f "$pictures"/*.svg
    if compgen -G "$sources/*.mmd" >/dev/null; then
      echo "No page shows a picture of a source in $sources/, so there is nothing to draw."
    else
      echo "No diagrams in $sources/."
    fi
    return 0
  fi
  if ! command -v docker >/dev/null 2>&1; then
    echo "Drawing the diagrams needs Docker, which is not installed here; the diagram bot of a pull request draws them too." >&2
    return 1
  fi
  local work
  work="$(mktemp -d)"
  # The trap runs when the script ends, after the function returned.
  # shellcheck disable=SC2064
  trap "rm -rf '$work'" EXIT
  mkdir "$work/in" "$work/out"
  cp "${names[@]/#/$sources/}" "$work/in/"
  cat >"$work/in/draw.mjs" <<'JAVASCRIPT'
// Draws each Mermaid source of /tmp/in as two pictures in /tmp/out: NAME.svg in the
// light theme on a white card, and NAME.dark.svg in the dark theme on the background
// of GitHub's. Labels are SVG text, not HTML, which every renderer of pictures shows;
// they are measured with DejaVu Sans, wider than the fonts that readers have before
// it, so that every label fits its box.
import fs from "node:fs";
import puppeteer from "puppeteer";
import { renderMermaid } from "@mermaid-js/mermaid-cli";

const FONT = '"trebuchet ms", verdana, "DejaVu Sans", arial, sans-serif';
const THEMES = [
  { suffix: "", theme: "default", card: "#ffffff" },
  { suffix: ".dark", theme: "dark", card: "#0d1117" },
];
const PADDING = 16;
const RADIUS = 12;
// The pages of the browser that draw at the same time.
const PAGES = 4;
const GEOMETRY = / (d|points|transform|x|y|x1|y1|x2|y2|cx|cy|r|rx|ry|width|height)="([^"]*)"/g;

// Hundredths of a pixel are precise enough, and keep the files small.
function rounded(value) {
  return value.replace(/-?\d*\.\d+(?:e-?\d+)?/g, (number) =>
    String(Math.round(Number(number) * 100) / 100),
  );
}

// The picture at its natural size, which pages scale down to their width, on a card
// with rounded corners that keeps it readable on any background.
function onCard(svg, color) {
  const open = svg.match(/^<svg\b[^>]*>/)[0];
  const [x, y, width, height] = open
    .match(/viewBox="([^"]+)"/)[1]
    .trim()
    .split(/[\s,]+/)
    .map(Number);
  const box = [x - PADDING, y - PADDING, width + 2 * PADDING, height + 2 * PADDING].map(
    (value) => Math.round(value * 100) / 100,
  );
  const tag = open
    .replace(/ (?:width|height|style|viewBox)="[^"]*"/g, "")
    .replace(/^<svg/, `<svg width="${box[2]}" height="${box[3]}" viewBox="${box.join(" ")}"`);
  const card = `<rect x="${box[0]}" y="${box[1]}" width="${box[2]}" height="${box[3]}" rx="${RADIUS}" style="fill:${color};stroke:none"/>`;
  const body = svg
    .slice(open.length)
    .replace(GEOMETRY, (_, name, value) => ` ${name}="${rounded(value)}"`);
  const end = body.indexOf("</style>") + "</style>".length;
  return `${tag}${body.slice(0, end)}${card}${body.slice(end)}\n`;
}

const browser = await puppeteer.launch({
  headless: "shell",
  ...JSON.parse(fs.readFileSync("/puppeteer-config.json", "utf8")),
});

async function draw(file) {
  const name = file.slice(0, -".mmd".length);
  const definition = fs.readFileSync(`/tmp/in/${file}`, "utf8");
  for (const { suffix, theme, card } of THEMES) {
    let svg;
    try {
      const { data } = await renderMermaid(browser, definition, "svg", {
        backgroundColor: "transparent",
        fontEmbed: false,
        svgId: `diagram-${name.replace(/[^A-Za-z0-9_-]/g, "-")}`,
        mermaidConfig: {
          theme,
          look: "classic",
          // The shapes with curves are drawn with random points unless they have a seed.
          handDrawnSeed: 1,
          fontFamily: FONT,
          themeVariables: { fontFamily: FONT },
          htmlLabels: false,
          flowchart: { htmlLabels: false },
        },
      });
      svg = new TextDecoder().decode(data);
    } catch (error) {
      throw new Error(`${file}: ${error.message}`);
    }
    fs.writeFileSync(`/tmp/out/${name}${suffix}.svg`, onCard(svg, card));
  }
  console.log(`${file}: ${name}.svg and ${name}.dark.svg`);
}

try {
  const files = fs.readdirSync("/tmp/in").filter((file) => file.endsWith(".mmd")).sort();
  const drawing = async () => {
    for (let file = files.shift(); file; file = files.shift()) {
      await draw(file);
    }
  };
  await Promise.all(Array.from({ length: PAGES }, drawing));
} finally {
  await browser.close();
}
JAVASCRIPT

  # Git Bash on Windows: the paths of the container stay as they are.
  if ! tar -cf - -C "$work/in" . |
    MSYS_NO_PATHCONV=1 docker run --rm --interactive --network none \
      --workdir /home/mermaidcli --entrypoint sh "$image" -c '
      set -e
      mkdir -p /tmp/in /tmp/out
      tar -xf - -C /tmp/in
      node --input-type=module - </tmp/in/draw.mjs >&2
      tar -cf - -C /tmp/out .' >"$work/pictures.tar" 2>"$work/log"; then
    cat "$work/log" >&2
    echo "Drawing the diagrams failed; the log above says why." >&2
    return 1
  fi
  tar -xf "$work/pictures.tar" -C "$work/out"
  mkdir -p "$pictures"
  rm -f "$pictures"/*.svg
  cp "$work/out"/*.svg "$pictures/"
  echo "Drew the ${#names[@]} diagram(s) of $sources/ into $pictures/."
}

# The Markdown with a <picture> in place of each ```mermaid block, and its source.
extract() {
  local python
  python="$(command -v python3 || command -v python || true)"
  if [[ -z "$python" ]]; then
    echo "Taking the diagrams out of the Markdown needs Python 3." >&2
    return 1
  fi
  "$python" "$(tool)" diagrams extract
}

# Takes the blocks out, and draws the pictures once a page shows them.
extract_and_render() {
  extract
  if adopted; then
    render
  else
    echo "No page shows a picture of $pictures/ yet, so there is nothing to draw."
  fi
}

case "${1:-}" in
  "") render ;;
  --check) check ;;
  --adopted) adopted ;;
  --extract) extract_and_render ;;
  -h | --help) usage ;;
  *)
    usage >&2
    exit 2
    ;;
esac
