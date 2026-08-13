#!/usr/bin/env bash
#
# deploy-test.sh — sync the plugin into the local WordPress test site.
#
# One script rather than a command in the docs, because there were two copies of
# that command and they had already drifted: one carried a list of exclusions,
# the other none, and NEITHER excluded `.git`. Every deploy therefore copied a
# 57 MB clone of the repository into
# wp-content/plugins/faz-cookie-manager/.git.
#
# That is not only waste. It broke `wp plugin install --force` outright —
# WordPress could not remove the old directory — and the same pattern used
# against a real server would put a complete repository, including its whole
# history, under the webroot. Exclusions belong in one place that cannot drift.
#
# Usage:
#   bash scripts/deploy-test.sh                 # default target
#   FAZ_DEPLOY_TARGET=/path/to/plugins/faz-cookie-manager/ bash scripts/deploy-test.sh
#   bash scripts/deploy-test.sh --print-excludes   # the list, one per line
#
# --print-excludes exists so that nothing else has to restate the list. The E2E
# preflight compares the working tree against the deployed plugin and refuses to
# run the suite when they differ; it held a THIRD copy of these exclusions, and
# the copies disagreed about `.gitignore` and `.githooks/`, so a correct deploy
# was reported as five files of drift and the whole suite declined to start.
# It now asks this script instead.

set -euo pipefail
cd "$(dirname "$0")/.." || exit 2

# The single list. Note that no pattern carries a trailing slash, and that is
# load-bearing: in rsync a trailing slash restricts the pattern to DIRECTORIES.
# Run from a git worktree — the normal way to work on two branches at once —
# `.git` is a file rather than a directory and `node_modules` is usually a
# symlink into the main checkout, so slash-suffixed patterns match neither and
# rsync copies both. That was not hypothetical: it shipped a 17 MB target with
# node_modules in it.
#
# `tests` is excluded because the plugin does not ship its own test suite, and
# because the preflight already compared without it — deploying it would have
# meant the two disagreed in the other direction.
EXCLUDES=(
	'.git'
	'.git*'
	'.github'
	'node_modules'
	'.phpcs-tools'
	'vendor'
	'graphify-out'
	'.code-review-graph'
	'.serena'
	'tests'
	'*.zip'
	'.DS_Store'
)

if [ "${1:-}" = '--print-excludes' ]; then
	printf '%s\n' "${EXCLUDES[@]}"
	exit 0
fi

TARGET="${FAZ_DEPLOY_TARGET:-/Users/fabio/Sites/faz-test/wp-content/plugins/faz-cookie-manager/}"

if [ ! -f faz-cookie-manager.php ]; then
	echo "deploy-test: not in the plugin root (faz-cookie-manager.php missing)" >&2
	exit 2
fi

# A missing trailing slash on the target makes rsync nest the source INSIDE it.
case "$TARGET" in
	*/) ;;
	*) TARGET="${TARGET}/" ;;
esac

# rsync --delete is intentionally destructive inside the plugin directory, so
# the destination must be proven to be exactly that directory. Canonicalising
# the existing parent also defeats `..` components and a symlinked target that
# only LOOKS like wp-content/plugins/faz-cookie-manager but resolves elsewhere.
target_leaf="${TARGET%/}"
target_parent="$(dirname "$target_leaf")"
target_name="$(basename "$target_leaf")"
if [ ! -d "$target_parent" ]; then
	echo "deploy-test: target parent does not exist: ${target_parent}" >&2
	exit 2
fi
if [ -L "$target_leaf" ]; then
	echo "deploy-test: target must not be a symbolic link" >&2
	exit 2
fi
target_parent="$(cd "$target_parent" && pwd -P)"
TARGET="${target_parent}/${target_name}/"
case "$TARGET" in
	*/wp-content/plugins/faz-cookie-manager/) ;;
	*)
		echo "deploy-test: target must end with wp-content/plugins/faz-cookie-manager/" >&2
		exit 2
		;;
esac

# --delete is deliberate: a stale file left behind after a rename is how a test
# passes against code that no longer ships. It also means the exclusions above
# are the only thing standing between this and deleting them at the target, so
# every entry is repository-side clutter, never plugin content.
RSYNC_EXCLUDES=()
for pattern in "${EXCLUDES[@]}"; do
	RSYNC_EXCLUDES+=( "--exclude=${pattern}" )
done

rsync -a --delete "${RSYNC_EXCLUDES[@]}" ./ "$TARGET"

echo "deployed → ${TARGET}"
du -sh "$TARGET" 2>/dev/null | awk '{print "size: " $1}'

# A .git that reappears means an exclusion was dropped; say so rather than let
# the next `wp plugin install` fail with a permissions error that names 400
# object files and explains nothing.
# -e, not -d: from a worktree `.git` arrives as a file, which -d would miss —
# the same distinction the exclusions above turn on.
if [ -e "${TARGET}.git" ] || [ -e "${TARGET}node_modules" ]; then
	echo "deploy-test: WARNING — .git or node_modules reached the target; the exclusions are wrong" >&2
	exit 1
fi
