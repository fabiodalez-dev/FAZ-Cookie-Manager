#!/usr/bin/env bash
#
# run-unit-tests.sh — run every standalone PHP unit test under tests/unit/.
#
# Each tests/unit/test-*.php is a self-contained CLI runner (it stubs the WP
# functions it needs and exits 0 on success / 1 on failure). This wrapper runs
# them all, prints one line per suite, dumps output for any that fail, and exits
# non-zero if any suite failed.
#
# Usage:
#   scripts/run-unit-tests.sh           # run all suites
#   npm run test:unit                   # same, via package.json
#
set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

php_bin="${PHP_BIN:-php}"
log="$(mktemp)"
trap 'rm -f "$log"' EXIT

pass=0
fail=0
failed=()

shopt -s nullglob
suites=( tests/unit/test-*.php )
shopt -u nullglob

if [ "${#suites[@]}" -eq 0 ]; then
	echo "No unit test suites found under tests/unit/." >&2
	exit 2
fi

for f in "${suites[@]}"; do
	if "$php_bin" "$f" >"$log" 2>&1; then
		# Surface the suite's own PASS/total tail line when it prints one.
		summary="$(grep -E 'ALL PASS|PASS|passed' "$log" | tail -1)"
		printf '  \033[32mPASS\033[0m  %-44s %s\n' "${f#tests/unit/}" "$summary"
		pass=$((pass + 1))
	else
		printf '  \033[31mFAIL\033[0m  %s\n' "${f#tests/unit/}"
		sed 's/^/        /' "$log"
		fail=$((fail + 1))
		failed+=( "$f" )
	fi
done

echo "────────────────────────────────────────────────────────────"
echo "unit suites: ${pass} passed, ${fail} failed (of ${#suites[@]})"
if [ "$fail" -ne 0 ]; then
	printf 'failed suites:\n'
	printf '  - %s\n' "${failed[@]}"
	exit 1
fi
