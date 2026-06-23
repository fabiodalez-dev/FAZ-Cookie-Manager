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

# JS unit suites (jsdom) under tests/unit/js/*.test.mjs. Each is a self-contained
# node runner that exits 0 on success / 1 on failure, mirroring the PHP ones.
shopt -s nullglob
js_suites=( tests/unit/js/*.test.mjs )
shopt -u nullglob
node_bin="${NODE_BIN:-node}"
js_ran=0
if [ "${#js_suites[@]}" -gt 0 ]; then
	if ! command -v "$node_bin" >/dev/null 2>&1; then
		printf '  \033[33mSKIP\033[0m  %s (node not found)\n' "tests/unit/js/*.test.mjs"
	else
		js_ran=${#js_suites[@]}
		for f in "${js_suites[@]}"; do
			if "$node_bin" "$f" >"$log" 2>&1; then
				summary="$(grep -E 'passed' "$log" | tail -1)"
				printf '  \033[32mPASS\033[0m  %-44s %s\n' "${f#tests/unit/}" "$summary"
				pass=$((pass + 1))
			else
				printf '  \033[31mFAIL\033[0m  %s\n' "${f#tests/unit/}"
				sed 's/^/        /' "$log"
				fail=$((fail + 1))
				failed+=( "$f" )
			fi
		done
	fi
fi

total=$(( ${#suites[@]} + js_ran ))
echo "────────────────────────────────────────────────────────────"
echo "unit suites: ${pass} passed, ${fail} failed (of ${total})"
if [ "$fail" -ne 0 ]; then
	printf 'failed suites:\n'
	printf '  - %s\n' "${failed[@]}"
	exit 1
fi
