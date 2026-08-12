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

set -euo pipefail
cd "$(dirname "$0")/.." || exit 2

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

# --delete is deliberate: a stale file left behind after a rename is how a test
# passes against code that no longer ships. It also means the exclusions below
# are the only thing standing between this and deleting them at the target, so
# every entry is repository-side clutter, never plugin content.
#
# None of these patterns carries a trailing slash, and that is load-bearing: in
# rsync a trailing slash restricts the pattern to DIRECTORIES. Run from a git
# worktree — the normal way to work on two branches at once — `.git` is a file
# rather than a directory and `node_modules` is usually a symlink into the main
# checkout, so slash-suffixed patterns match neither and rsync copies both. That
# was not hypothetical: it shipped a 17 MB target with node_modules in it.
rsync -a --delete \
	--exclude='.git' \
	--exclude='.git*' \
	--exclude='node_modules' \
	--exclude='.phpcs-tools' \
	--exclude='graphify-out' \
	--exclude='.code-review-graph' \
	--exclude='.serena' \
	--exclude='tests/e2e/reports' \
	--exclude='*.zip' \
	./ "$TARGET"

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
