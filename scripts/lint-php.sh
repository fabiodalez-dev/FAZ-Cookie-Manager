#!/usr/bin/env bash
#
# lint-php.sh — syntax-check the plugin's own PHP, and only that.
#
# The recipe this replaces was `find . -name '*.php' -not -path './vendor/*'
# -exec php -l {} \;`. It was wrong in both directions at once:
#
#   TOO SLOW. It also linted the PHPCS fixture corpus under .phpcs-tools/ —
#   roughly 1,160 files against the plugin's ~300 — so a single sweep took about
#   three minutes. That directory is untracked and locally excluded, so it
#   survives every checkout and taxed every branch.
#
#   TOO NOISY. Those fixtures include files that are deliberately malformed or
#   target other PHP versions, so the sweep printed deprecation notices on a
#   perfectly clean tree. A check that cries wolf on every run is one people
#   learn to ignore, which is worse than not having it: the day it reports a
#   real parse error, it looks like the noise it always makes.
#
# Exit status is what callers should read: 0 means every plugin PHP file parses.
#
# Usage: scripts/lint-php.sh   (or: npm run lint:php)

set -uo pipefail
cd "$(dirname "$0")/.." || exit 2

php_bin="${PHP_BIN:-php}"

# Only trees that ship. Anything else is either someone else's code (vendor,
# node_modules) or tooling that is not part of the plugin.
#
# Piped into a while-read rather than mapfile: macOS still ships bash 3.2, where
# mapfile does not exist, and a lint script that fails to run on the maintainer's
# own machine is not a lint script.
failed=0
total=0
while IFS= read -r f; do
	total=$(( total + 1 ))
	# -d error_reporting silences version-dependent deprecations from the linter
	# itself. A genuine parse error is still reported and still sets the status;
	# this only stops a notice about someone's coding style from reading as one.
	if ! out="$( "$php_bin" -d error_reporting=E_ERROR -l "$f" 2>&1 )"; then
		echo "$out"
		failed=$(( failed + 1 ))
	fi
done <<EOF
$(
	find . -name '*.php' \
		-not -path './vendor/*' \
		-not -path './node_modules/*' \
		-not -path './.phpcs-tools/*' \
		-not -path './graphify-out/*' \
		-not -path './.code-review-graph/*' \
		-not -path './.git/*' \
		2>/dev/null | sort -u
)
EOF

if [ "$total" -eq 0 ]; then
	echo "lint-php: no PHP files found — wrong working directory?" >&2
	exit 2
fi

echo "────────────────────────────────────────────────────────────"
if [ "$failed" -ne 0 ]; then
	echo "php -l: ${failed} file(s) with syntax errors (of ${total})"
	exit 1
fi
echo "php -l: ${total} files, no syntax errors"
