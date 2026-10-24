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

# The files of git, tracked or new, each once: during a merge, git lists a file with
# a conflict once for each side otherwise.
files() {
  git ls-files -z --cached --others --exclude-standard --deduplicate "$@"
}

step "Text files"
# --deduplicate is new in Git 2.31; an older git would list no files, and every step
# would pass without checking anything. The coach has nothing to add to that.
if ! git ls-files --deduplicate >/dev/null 2>&1; then
  echo "scripts/check.sh needs Git 2.31 or newer." >&2
  current=""
  exit 1
fi
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
# The files of git other than the licenses, and of those the text files: the ones
# with a character other than a line break and without the NUL of a binary file, as
# grep -I sees them, so neither deleted, empty nor binary ones.
names=()
while IFS= read -r -d '' file; do
  case "$file" in
    LICENSE | LICENSES/*) ;;
    *) names+=("$file") ;;
  esac
done < <(files)
text=()
if ((${#names[@]} > 0)); then
  mapfile -d '' text < <(printf '%s\0' "${names[@]}" | xargs -0 grep -Ils --null . --)
fi
# The text files that a command lists, each with a NUL at its end, as the keys of the
# array named first: one command for all the files, or a few for a long list, rather
# than a few for each file, which took half a minute on Windows.
listed_by() {
  local -n found="$1"
  local file
  shift
  while IFS= read -r -d '' file; do
    found["$file"]=1
  done < <(printf '%s\0' "${text[@]}" | xargs -0 "$@" --)
}
declare -A crlf=() blanks=() unended=() markers=()
# A line that git writes around a conflict that a merge leaves to a person: alone,
# with the CR of a CRLF line ending, or before a blank and the name of a side.
marker=$'^(<{7}|>{7})( |\r|$)'
if ((${#text[@]} > 0)); then
  # -U reads the bytes as they are: the grep of Git for Windows drops the CR at the
  # end of a line otherwise, and found no CRLF line endings there.
  listed_by crlf grep -lU --null $'\r'
  listed_by blanks grep -lUE --null '[[:blank:]]$'
  listed_by markers grep -lUE --null "$marker"
  # The files whose last byte is no line break. Perl, which comes with Git for
  # Windows, macOS and Linux, reads the last byte of every file; tail, without it,
  # that of one file at a time.
  if command -v perl >/dev/null 2>&1; then
    # shellcheck disable=SC2016 # The variables are those of the program of Perl.
    listed_by unended perl -e 'for my $name (@ARGV) {
      my $last = "\n";
      open(my $file, "<:raw", $name) or next;
      seek($file, -1, 2) and read($file, $last, 1);
      print "$name\0" if $last ne "\n";
    }'
  else
    for file in "${text[@]}"; do
      if [[ -n "$(tail -c 1 "$file")" ]]; then
        unended["$file"]=1
      fi
    done
  fi
fi
for file in "${text[@]}"; do
  if [[ -n "${crlf[$file]:-}" ]]; then
    problem "$file" "has CRLF line endings" $'\r'
  fi
  if [[ -n "${blanks[$file]:-}" ]]; then
    problem "$file" "has trailing whitespace" '[[:blank:]]$'
  fi
  if [[ -n "${unended[$file]:-}" ]]; then
    problem "$file" "lacks the line break at its end"
  fi
  if [[ -n "${markers[$file]:-}" ]]; then
    problem "$file" "has conflict markers" "$marker"
  fi
done
if ((problems > 0)); then
  echo "Fix the $problems problem(s) above; .editorconfig sets up most editors for it."
  if ((${#markers[@]} > 0)); then
    echo "A conflict needs a person: keep the lines that belong, and delete the markers."
  fi
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

step "Changelog fragments"
# Every fragment of changelog.d/ as the release takes it in, those that wait on main
# for the next release too: rules that get stricter, in blueprint.py or in an update
# of the blueprint, meet them here, before the release does. The check of the title
# checks only the fragments of a pull request, with the code of main. Each problem
# names its fragment and the fix. The Python and blueprint.py of the step before.
if [[ -n "$python" ]]; then
  "$python" "$tool" fragments
elif [[ -n "${CI:-}" ]]; then
  echo "Checking the fragments needs Python 3.12 or newer, which this runner lacks."
  exit 1
else
  echo "Python 3.12 or newer is not installed here; the CI checks the fragments."
fi

step "Accepted advisories"
# The versions that an osv-scanner.toml accepts with every advisory, such as one that
# another package pins exactly, need a rule of Renovate in
# .github/renovate-accepted.json5, or Renovate tries a fix in every run that the lock
# file can't take. The step checks that the rules are those of the files, and that
# OSV-Scanner can read each file, which it ignores whole for an unknown key; advisories
# --fix writes the rules. The Python and blueprint.py of the step before.
if [[ -n "$python" ]]; then
  "$python" "$tool" advisories
elif [[ -n "${CI:-}" ]]; then
  echo "Checking the accepted advisories needs Python 3.12 or newer, which this runner lacks."
  exit 1
else
  echo "Python 3.12 or newer is not installed here; the CI checks the accepted advisories."
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
# ShellCheck, which the CI never skips: a runner without it fails here, as one whose
# image no longer has it. The runners of Linux have it; pip installs it from the
# package shellcheck-py.
if ! command -v shellcheck >/dev/null 2>&1; then
  if [[ -n "${CI:-}" ]]; then
    echo "Checking the shell scripts needs ShellCheck, which this runner lacks."
    exit 1
  fi
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

step "Oldest PHP"
# The app runs on the oldest PHP of appinfo/info.xml and on every newer one, its tools on
# the PHP of config.platform.php in composer.json, which may be newer: the code that the
# app ships has to parse on the oldest PHP, and the packages that it ships have to
# install there. The CI sets up that PHP beside the one of the tools, as php8.2 or the
# like. This is PHP code, not a shell expression.
# shellcheck disable=SC2016
oldest="$(php -r '$php = simplexml_load_file("appinfo/info.xml")->dependencies->php;
echo implode(".", array_slice(explode(".", (string) ($php["min-version"] ?? "")), 0, 2));')"
tools="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
if [[ -z "$oldest" || "$oldest" == "$tools" ]]; then
  echo "The tools run on PHP $tools, the oldest PHP of the app."
elif command -v "php$oldest" >/dev/null 2>&1; then
  mapfile -t shipped < <(find appinfo lib templates -maxdepth 0 -type d 2>/dev/null)
  find "${shipped[@]}" -name '*.php' -print0 |
    xargs -0 -n 1 -P "$(getconf _NPROCESSORS_ONLN)" "php$oldest" --syntax-check |
    { grep -v '^No syntax errors detected in ' || true; }
  "php$oldest" "$(command -v composer)" check-platform-reqs --lock --no-dev
  echo "The code of the app parses on PHP $oldest, and the packages that it ships install there."
elif [[ -n "${CI:-}" ]]; then
  echo "PHP $oldest, the oldest PHP of appinfo/info.xml, is missing: the CI needs it as php$oldest beside PHP $tools, the PHP of the tools." >&2
  exit 1
else
  echo "PHP $oldest, the oldest PHP of appinfo/info.xml, is not installed here as php$oldest; the CI checks the code on it."
fi

step "Coding standard"
vendor/bin/php-cs-fixer fix --dry-run --diff

step "Psalm"
# The canary of the taint analysis runs on another processor meanwhile; its step reads
# what it found, never the report of a run before.
mkdir -p build
rm -f build/psalm-canary.json
vendor/bin/psalm --config=.github/psalm/canary.xml --taint-analysis --threads=1 --no-cache \
  --no-progress --monochrome --report=build/psalm-canary.json >build/psalm-canary.log 2>&1 &
canary=$!
if ! vendor/bin/psalm --threads=1 --no-cache; then
  # A check that stops here leaves no canary running behind it.
  kill "$canary" 2>/dev/null || true
  exit 1
fi

step "Psalm taint canary"
# The controller of .github/psalm/canary/ lets the input of a request reach each kind of
# sink: the analysis has to find every path that it expects, and no other, or it has
# gone blind and would pass the code of every app in silence.
wait "$canary" || true
if ! php .github/psalm/check-canary.php build/psalm-canary.json; then
  cat build/psalm-canary.log
  exit 1
fi

step "Psalm taint analysis"
# Untrusted input that reaches HTML, SQL, a shell, a file, a request to another server
# or a header unescaped: the parameters of a request to a controller, the term of a
# search and the answers of other servers, as .github/psalm/NextcloudTaintPlugin.php
# teaches Psalm. The CI shows each path in code scanning too, from the SARIF report.
vendor/bin/psalm --taint-analysis --threads=1 --no-cache --report=build/psalm-taint.sarif

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
      echo "The website builds strictly."
      # What the hooks of the website promise, as the check docs checks it after the
      # build, with the Python and blueprint.py of the step Pages in one column.
      if [[ -n "${python:-}" && -f "${tool:-}" ]] && ! "$python" "$tool" site-check "$site"; then
        echo "The website breaks what its hooks promise; the lines above name each file."
        exit 1
      fi
    )
  fi
fi

if [[ -f scripts/check-project.sh ]]; then
  step "Checks of this project"
  bash scripts/check-project.sh
fi

printf '\nEvery check passed.\n'
