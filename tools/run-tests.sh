#!/usr/bin/env bash
set -euo pipefail

plugin_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
php_bin="${PHP_BIN:-php}"
if ! command -v "$php_bin" >/dev/null 2>&1; then
    printf 'PHP CLI was not found. Set PHP_BIN to a PHP >=8.2 executable.\n' >&2
    exit 2
fi
"$php_bin" -r 'if (PHP_VERSION_ID < 80200) {fwrite(STDERR, "PHP >=8.2 required\n"); exit(2);} echo "PHP ",PHP_VERSION,"\n";'

lint_count=0
while IFS= read -r -d '' source; do
    "$php_bin" -l "$source" >/dev/null
    lint_count=$((lint_count + 1))
done < <(find "$plugin_root" -type f -name '*.php' -not -path '*/vendor/*' -print0)
printf 'PASS PHP syntax: %s files\n' "$lint_count"

test_count=0
while IFS= read -r -d '' test_file; do
    printf '\nRunning %s\n' "${test_file#"$plugin_root/"}"
    # Standalone fixtures must never accidentally boot a caller's GLPI database.
    (unset GLPI_ROOT; "$php_bin" "$test_file")
    test_count=$((test_count + 1))
done < <(find "$plugin_root/tests" -maxdepth 1 -type f -name '*_test.php' -print0 | sort -z)
if [[ "$test_count" -eq 0 ]]; then
    printf 'No standalone test files were found. Syntax validation alone is not behavior coverage.\n' >&2
    exit 3
fi
printf '\nPASS %s standalone test files. Live GLPI integration must be run separately.\n' "$test_count"
