#!/usr/bin/env bash
#
# bump-version.sh — set the release version everywhere it is written, and keep
# readme.txt's changelog under the wp.org truncation limit.
#
# The version lives in four literal places because WordPress requires literal
# headers: it cannot be templated out of one source. So instead of one source,
# this is the ONE WRITER — the validators already in build-release.sh and
# svn-release.sh stay as the backstop that catches a hand edit.
#
# wp.org silently truncates `== Changelog ==` past ~5,000 words, which turns a
# release into "the changelog just stops" with only an author-only warning to
# explain it. Every release adds an entry, so the section grows monotonically
# and the cap is reached by doing nothing wrong. This script trims to the
# newest KEEP_ENTRIES versions and refuses to leave the file over the limit.
#
# Usage:
#   scripts/bump-version.sh 1.26.0
#   scripts/bump-version.sh 1.26.0 --check     # verify only, change nothing
#
set -euo pipefail

PLUGIN_SRC="${PLUGIN_SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
MAIN_FILE="${PLUGIN_SRC}/faz-cookie-manager.php"
README_TXT="${PLUGIN_SRC}/readme.txt"
CHANGELOG_MD="${PLUGIN_SRC}/CHANGELOG.md"
README_MD="${PLUGIN_SRC}/README.md"

KEEP_ENTRIES="${KEEP_ENTRIES:-13}"
WORD_LIMIT=4500          # wp.org truncates at ~5000; leave margin.

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
cyan()  { printf '\033[36m%s\033[0m\n' "$*"; }

VERSION="${1:-}"
CHECK_ONLY=false
[[ "${2:-}" == "--check" ]] && CHECK_ONLY=true

if [[ -z "${VERSION}" || ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    red "Usage: scripts/bump-version.sh X.Y.Z [--check]"
    exit 1
fi

changelog_words() {
    awk '/^== Changelog ==/{f=1} f' "${README_TXT}" | wc -w | tr -d ' '
}

# ---------------------------------------------------------------- write ----
if [[ "${CHECK_ONLY}" == "false" ]]; then
    cyan "Setting version to ${VERSION}"
    # Four literal sites; anchored so a stray match elsewhere cannot be hit.
    sed -i '' -E "s/^( \* Version:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+(-(rc|beta|alpha)[0-9]+)?$/\1${VERSION}/" "${MAIN_FILE}"
    sed -i '' -E "s/^( \* Stable tag:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+(-(rc|beta|alpha)[0-9]+)?$/\1${VERSION}/" "${MAIN_FILE}"
    sed -i '' -E "s/(define\( 'FAZ_VERSION', ')[0-9]+\.[0-9]+\.[0-9]+(-(rc|beta|alpha)[0-9]+)?('  *\);)/\1${VERSION}\4/" "${MAIN_FILE}"
    sed -i '' -E "s/^(Stable tag: )[0-9]+\.[0-9]+\.[0-9]+(-(rc|beta|alpha)[0-9]+)?$/\1${VERSION}/" "${README_TXT}"

    # Trim the changelog to the newest KEEP_ENTRIES versions, preserving the
    # "= Older versions =" pointer that sends readers to the full history.
    python3 - "$README_TXT" "$KEEP_ENTRIES" <<'PY'
import re, sys
path, keep = sys.argv[1], int(sys.argv[2])
s = open(path, encoding='utf-8').read()
head, sep, tail = s.partition('== Changelog ==')
if not sep:
    sys.exit(0)
older = re.search(r'\n= Older versions =', tail)
body, footer = (tail[:older.start()], tail[older.start():]) if older else (tail, '')
parts = re.split(r'(?m)^(?== \d+\.\d+\.\d+ =)', body)
intro, entries = parts[0], parts[1:]
if len(entries) > keep:
    entries = entries[:keep]
open(path, 'w', encoding='utf-8').write(head + sep + intro + ''.join(entries) + footer)
PY
fi

# ---------------------------------------------------------------- verify ---
fail=0
check() {  # label expected actual
    if [[ "$2" != "$3" ]]; then red "  ✗ $1: expected ${2}, found ${3}"; fail=1
    else green "  ✓ $1"; fi
}

cyan "Verifying"
check "faz-cookie-manager.php Version"    "${VERSION}" "$(grep -E '^ \* Version:' "${MAIN_FILE}" | awk '{print $3}')"
check "faz-cookie-manager.php Stable tag" "${VERSION}" "$(grep -E '^ \* Stable tag:' "${MAIN_FILE}" | awk '{print $4}')"
check "FAZ_VERSION"                       "${VERSION}" "$(grep -oE "'[0-9]+\.[0-9]+\.[0-9]+(-(rc|beta|alpha)[0-9]+)?'" <<< "$(grep "define( 'FAZ_VERSION'" "${MAIN_FILE}")" | tr -d "'")"
check "readme.txt Stable tag"             "${VERSION}" "$(grep -E '^Stable tag:' "${README_TXT}" | awk '{print $3}')"

# Plugin Check fails when these differ, and nothing else in the toolchain
# compares them — a mismatch surfaces only at wp.org review time.
tested_php="$(grep -E '^ \* Tested up to:' "${MAIN_FILE}" | awk '{print $5}')"
tested_txt="$(grep -E '^Tested up to:' "${README_TXT}" | awk '{print $4}')"
check "Tested up to (php ↔ readme.txt)" "${tested_php}" "${tested_txt}"

for f in "${CHANGELOG_MD}:## \[${VERSION}\]" "${README_MD}:### ${VERSION}" "${README_TXT}:= ${VERSION} ="; do
    file="${f%%:*}"; pat="${f#*:}"
    if grep -qE "^${pat}" "${file}"; then green "  ✓ $(basename "${file}") has a ${VERSION} entry"
    else red "  ✗ $(basename "${file}") has no ${VERSION} entry"; fail=1; fi
done

words="$(changelog_words)"
if (( words < WORD_LIMIT )); then green "  ✓ readme.txt changelog ${words} words (limit ${WORD_LIMIT})"
else red "  ✗ readme.txt changelog ${words} words — wp.org truncates past ~5000; lower KEEP_ENTRIES"; fail=1; fi

exit "${fail}"
