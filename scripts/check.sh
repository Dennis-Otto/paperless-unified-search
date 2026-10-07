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
  printf '\n== %s\n' "$1"
}

files() {
  git ls-files -z --cached --others --exclude-standard "$@"
}

step "Text files"
problems=0
while IFS= read -r -d '' file; do
  case "$file" in
    LICENSE | LICENSES/*) continue ;;
  esac
  # Leave out deleted, empty and binary files.
  if [[ ! -s "$file" ]] || ! grep -Iq . "$file"; then
    continue
  fi
  if grep -q $'\r' "$file"; then
    echo "$file: has CRLF line endings"
    problems=$((problems + 1))
  fi
  if grep -qE '[[:blank:]]$' "$file"; then
    echo "$file: has trailing whitespace"
    problems=$((problems + 1))
  fi
  if [[ -n "$(tail -c 1 "$file")" ]]; then
    echo "$file: lacks the line break at its end"
    problems=$((problems + 1))
  fi
done < <(files)
if ((problems > 0)); then
  echo "Fix the $problems problem(s) above; .editorconfig sets up most editors for it."
  exit 1
fi
echo "Every text file is clean."

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
if curl --fail --location --silent --show-error --max-time 30 --output "$xsd" "$schema"; then
  # This is PHP code, not a shell expression.
  # shellcheck disable=SC2016
  php -r '$info = new DOMDocument(); $info->load("appinfo/info.xml"); exit($info->schemaValidate($argv[1]) ? 0 : 1);' "$xsd"
  echo "appinfo/info.xml follows the schema of the App Store."
else
  echo "The schema of the App Store is out of reach here; the CI checks it."
fi
rm -f "$xsd"

step "PHP syntax"
mapfile -t folders < <(find appinfo lib templates tests -maxdepth 0 -type d 2>/dev/null)
find "${folders[@]}" -name '*.php' -print0 | xargs -0 -n 1 php --syntax-check >/dev/null
echo "Every PHP file parses."

step "Coding standard"
vendor/bin/php-cs-fixer fix --dry-run --diff

step "Psalm"
vendor/bin/psalm --threads=1 --no-cache

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

if [[ -f scripts/check-project.sh ]]; then
  step "Checks of this project"
  bash scripts/check-project.sh
fi

printf '\nEvery check passed.\n'
