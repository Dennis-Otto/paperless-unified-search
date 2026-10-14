#!/usr/bin/env bash
# The checks of Paperless Unified Search, the same ones its CI runs. Run them before every
# push:  bash scripts/check.sh
# The tools come from:  composer install
#
# The blueprint keeps this file current: https://github.com/Dennis-Otto/repo-blueprint
# Checks of this project alone belong in scripts/check-project.sh, which runs last.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

step() {
  current="$1"
  printf '\n== %s\n' "$1"
}

# When a step fails, the PR coach says what it found and how to fix it, from
# .github/checks.toml: here, and in the CI also in the summary of the check, as its
# annotation and on the pull request. It needs a Python 3.11 or newer, if there is one.
coach() {
  local status=$? script=.github/blueprint.py python
  [[ -f "$script" ]] || script=blueprint.py
  if ((status != 0)) && [[ -n "${current:-}" && -f "$script" ]]; then
    for python in python3 python py; do
      if "$python" "$script" coach explain --step "$current" 2>/dev/null; then
        break
      fi
    done
  fi
}
trap coach EXIT

files() {
  git ls-files -z --cached --others --exclude-standard "$@"
}

step "Text files"
problems=0
# A problem of a file: in the log, and in the CI also as an annotation at the first
# line that matches the pattern, or at the end of the file, which the files of a pull
# request show at their place.
problem() {
  local line name shown
  # The name on one line of the log, where no name can start a command of the runner:
  # its line breaks as \r and \n, and ./ before a name that starts with a blank, a
  # colon or another sign.
  shown="${1//$'\r'/\\r}"
  shown="${shown//$'\n'/\\n}"
  if [[ "$shown" != [[:alnum:]._-]* ]]; then
    shown="./$shown"
  fi
  printf '%s: %s\n' "$shown" "$2"
  problems=$((problems + 1))
  if [[ "${GITHUB_ACTIONS:-}" == true ]]; then
    if [[ -n "${3:-}" ]]; then
      line="$(grep -nU -m 1 -E -- "$3" "$1")"
      line="${line%%:*}"
    else
      line="$(($(wc -l <"$1") + 1))"
    fi
    # The name as a property of a command of the runner, which no name can end.
    name="${1//%/%25}"
    name="${name//$'\r'/%0D}"
    name="${name//$'\n'/%0A}"
    name="${name//:/%3A}"
    name="${name//,/%2C}"
    echo "::error file=$name,line=$line,title=Text files::$2"
  fi
}
while IFS= read -r -d '' file; do
  case "$file" in
    LICENSE | LICENSES/*) continue ;;
  esac
  # Leave out deleted, empty and binary files.
  if [[ ! -s "$file" ]] || ! grep -Iq . "$file"; then
    continue
  fi
  # -U reads the bytes as they are: the grep of Git for Windows drops the CR at the
  # end of a line otherwise, and found no CRLF line endings there.
  if grep -qU $'\r' "$file"; then
    problem "$file" "has CRLF line endings" $'\r'
  fi
  if grep -qUE '[[:blank:]]$' "$file"; then
    problem "$file" "has trailing whitespace" '[[:blank:]]$'
  fi
  if [[ -n "$(tail -c 1 "$file")" ]]; then
    problem "$file" "lacks the line break at its end"
  fi
done < <(files)
if ((problems > 0)); then
  echo "Fix the $problems problem(s) above; .editorconfig sets up most editors for it."
  exit 1
fi
echo "Every text file is clean."

step "Diagrams"
# Pictures drawn from the sources in docs/diagrams/, which the GitHub app shows too;
# scripts/diagrams.sh draws them, with Docker.
bash scripts/diagrams.sh --check

step "Pages in one column"
# The READMEs, CONTRIBUTING.md and the pages of docs/ without columns side by side,
# which the GitHub app and phones squeeze: no <table> that sets a picture beside text,
# no cell with a width. Each finding says how to fix it; pages --fix fixes what it can.
tool=.github/blueprint.py
[[ -f "$tool" ]] || tool=blueprint.py
python=""
for candidate in python3 python py; do
  if "$candidate" -c 'import sys; sys.exit(sys.version_info < (3, 12))' >/dev/null 2>&1; then
    python="$candidate"
    break
  fi
done
if [[ -n "$python" ]]; then
  "$python" "$tool" pages
elif [[ -n "${CI:-}" ]]; then
  echo "Checking the pages needs Python 3.12 or newer, which this runner lacks."
  exit 1
else
  echo "Python 3.12 or newer is not installed here; the CI checks the pages."
fi

step "Version"
# The release bot writes the version of each release into these files.
release="$(grep -oE '"\.": *"[^"]+"' .release-please-manifest.json | grep -oE '[0-9][^"]*')"
version_in() {
  local found
  found="$(grep -oE -m 1 "$2" "$1" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+[^"<]*' | head -n 1 || true)"
  if [[ "$found" != "$release" ]]; then
    echo "$1 has version ${found:-none}; the manifest of the release bot has $release."
    exit 1
  fi
}
version_in version.txt '^[0-9][^[:space:]]*'
version_in appinfo/info.xml '<version>[^<]+</version>'
echo "Every file has version $release."

step "Git hooks"
# Git runs a hook only when its file is executable, which git records as the mode
# 100755. A file added on Windows, which has no such mode, gets 100644, and git on
# Linux and macOS, in the dev container and in Codespaces then skips the hook.
mapfile -t hooks < <(git ls-files --stage -- .githooks | grep -v '^100755 ' | cut -f 2-)
if ((${#hooks[@]} > 0)); then
  printf '%s: is not executable, so git skips it\n' "${hooks[@]}"
  echo "Make it executable and commit that: git update-index --chmod=+x ${hooks[*]}"
  exit 1
fi
echo "Every hook in .githooks/ is executable."

step "Shell scripts"
mapfile -d '' scripts < <(files '*.sh')
if ! command -v shellcheck >/dev/null 2>&1; then
  echo "shellcheck is not installed here; the CI runs it."
elif ((${#scripts[@]} > 0)); then
  shellcheck "${scripts[@]}"
  echo "shellcheck found nothing in ${#scripts[@]} script(s)."
fi

step "Composer"
composer validate --strict
composer audit

step "App metadata"
xsd="$(mktemp)"
schema=https://apps.nextcloud.com/schema/apps/info.xsd
# Again after a passing error of the network or of the App Store. Only the CI can't do
# without it, because no other check of info.xml follows there.
if curl --fail --location --silent --show-error --retry 3 --retry-all-errors \
  --connect-timeout 10 --max-time 30 --output "$xsd" "$schema"; then
  # This is PHP code, not a shell expression.
  # shellcheck disable=SC2016
  php -r '$info = new DOMDocument(); $info->load("appinfo/info.xml"); exit($info->schemaValidate($argv[1]) ? 0 : 1);' "$xsd"
  echo "appinfo/info.xml follows the schema of the App Store."
elif [[ -n "${CI:-}" ]]; then
  rm -f "$xsd"
  echo "The schema of the App Store is out of reach, and the CI checks appinfo/info.xml against it; run the checks again once apps.nextcloud.com answers."
  exit 1
else
  echo "The schema of the App Store is out of reach here; the CI checks it."
fi
rm -f "$xsd"

step "PHP syntax"
mapfile -t folders < <(find appinfo lib templates tests -maxdepth 0 -type d 2>/dev/null)
# One PHP for each file, as many at once as there are processors; getconf counts them
# on Linux, on macOS and in Git Bash. Only the errors show, wherever PHP writes them.
find "${folders[@]}" -name '*.php' -print0 |
  xargs -0 -n 1 -P "$(getconf _NPROCESSORS_ONLN)" php --syntax-check |
  { grep -v '^No syntax errors detected in ' || true; }
echo "Every PHP file parses."

step "Coding standard"
vendor/bin/php-cs-fixer fix --dry-run --diff

step "Psalm"
vendor/bin/psalm --threads=1 --no-cache

step "Psalm taint analysis"
# Untrusted input that reaches HTML, SQL, a shell, a file or a header unescaped.
vendor/bin/psalm --taint-analysis --threads=1 --no-cache

step "Tests, with every line covered"
# pcov measures the coverage in the CI, Xdebug in the dev container.
if ! php -r 'exit(extension_loaded("pcov") || extension_loaded("xdebug") ? 0 : 1);'; then
  echo "The tests need pcov or Xdebug to measure their coverage: pecl install pcov" >&2
  exit 1
fi
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text --coverage-cobertura build/coverage.xml
# This is PHP code, not a shell expression.
# shellcheck disable=SC2016
php -r '$rate = (float) simplexml_load_file($argv[1])["line-rate"];
if ($rate < 1) {
    fwrite(STDERR, sprintf("%.2f %% of the lines are covered; every line needs a test.\n", 100 * $rate));
    exit(1);
}' build/coverage.xml
echo "Every line of lib/ is covered."

step "Package"
if command -v krankerl >/dev/null 2>&1; then
  krankerl package
  # This is PHP code, not a shell expression.
  # shellcheck disable=SC2016
  app_id="$(php -r 'echo simplexml_load_file("appinfo/info.xml")->id;')"
  # The project's own checks of what its package holds, if it has them.
  if [[ -f scripts/check-package.sh ]]; then
    bash scripts/check-package.sh "build/artifacts/$app_id.tar.gz" unsigned
  fi
  echo "The package build/artifacts/$app_id.tar.gz builds."
else
  echo "krankerl is not installed here; the CI builds the package."
fi

step "Website"
# The website of the Docs workflow, built strictly as the check docs builds it, so
# that a link or an anchor that leads nowhere fails here already. Its tools are those
# of the preview in .venv-docs, which CONTRIBUTING.md sets up, or those of an activated
# virtual environment. A MkDocs from elsewhere on the PATH lacks the theme and the
# plugins of the website as a rule, so the step leaves it alone.
if [[ ! -f mkdocs.yml ]] || [[ ! -f docs/index.md && ! -f docs/README.md ]]; then
  echo "No website here: it needs mkdocs.yml and a home page, docs/index.md."
else
  candidates=(.venv-docs/bin/mkdocs .venv-docs/Scripts/mkdocs.exe)
  if [[ -n "${VIRTUAL_ENV:-}" ]]; then
    candidates+=("$VIRTUAL_ENV/bin/mkdocs" "$VIRTUAL_ENV/Scripts/mkdocs.exe")
  fi
  mkdocs=""
  for candidate in "${candidates[@]}"; do
    if [[ -x "$candidate" ]]; then
      mkdocs="$candidate"
      break
    fi
  done
  if [[ -z "$mkdocs" ]]; then
    echo "MkDocs is not installed here; the CI builds the website."
  else
    # Into a folder of its own, which goes in any case, and with the log only when
    # the build fails. Without the warning about MkDocs 2.0, as in the Docs workflow.
    (
      site="$(mktemp -d)"
      trap 'rm -rf "$site"' EXIT
      if ! log="$(NO_MKDOCS_2_WARNING=true "$mkdocs" build --strict --site-dir "$site" 2>&1)"; then
        printf '%s\n' "$log"
        echo "The website fails to build strictly with $mkdocs; the log above says why."
        echo "Tools that are out of date or incomplete install again as CONTRIBUTING.md shows."
        exit 1
      fi
    )
    echo "The website builds strictly."
  fi
fi

if [[ -f scripts/check-project.sh ]]; then
  step "Checks of this project"
  bash scripts/check-project.sh
fi

printf '\nEvery check passed.\n'
