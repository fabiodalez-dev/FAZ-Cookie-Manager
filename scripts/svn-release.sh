#!/usr/bin/env bash
#
# svn-release.sh — staged release helper for the wordpress.org SVN repo.
#
# This script is the safe path to ship a new FAZ Cookie Manager version to
# https://plugins.svn.wordpress.org/faz-cookie-manager/ . It enforces the
# rule from .wordpress-org/PUBLISHING-GUIDE.md §2:
#
#     "Never run `rsync … trunk/` followed by `svn ci` in the same shot."
#
# Concretely it:
#   1. Validates the version arg matches the wp.org-shape ZIP filename, the
#      Stable tag in readme.txt, and the FAZ_VERSION constant.
#   2. Extracts the wp.org-shape ZIP into a staging directory OUTSIDE the
#      SVN checkout (default: ~/Sites/faz-cookie-manager-svn-stage/).
#   3. Computes a diff vs the current SVN trunk and prints a summary
#      (file count, size, sample of added/removed/changed paths).
#   4. ASKS for confirmation before applying anything to the SVN working
#      copy. (Gate 1.)
#   5. rsyncs staging → trunk, copies screenshots/banner/icon into assets/,
#      svn-copies trunk → tags/{version}, runs `svn add --force` for new
#      files and `svn rm` for deleted ones.
#   6. Prints `svn status` and the planned commit message.
#   7. ASKS for confirmation again before `svn ci`. (Gate 2.)
#
# Usage:
#   scripts/svn-release.sh --version=1.13.11
#   scripts/svn-release.sh --version=1.13.11 --dry-run     # skip the final svn ci
#   scripts/svn-release.sh --version=1.13.11 --no-tag      # update trunk + assets only (e.g. assets refresh)
#
# Required environment:
#   - svn 1.10+ in PATH (brew install subversion)
#   - SVN credentials cached in macOS Keychain (see PUBLISHING-GUIDE §2.1)
#   - Repo paths (PROJECT_ROOT, SVN_DIR, STAGE_DIR) are macOS-default;
#     override via env vars if your layout differs.

set -euo pipefail

# ── Defaults (override via env) ──────────────────────────────────────────
PROJECT_ROOT="${PROJECT_ROOT:-/Users/fabio/Documents/GitHub/Cookie Crawler}"
SVN_DIR="${SVN_DIR:-${HOME}/Sites/faz-cookie-manager-svn}"
STAGE_DIR="${STAGE_DIR:-${HOME}/Sites/faz-cookie-manager-svn-stage}"
PLUGIN_SRC="${PROJECT_ROOT}/faz-cookie-manager"
SVN_USERNAME="${SVN_USERNAME:-fabiodalez}"

# ── Args ─────────────────────────────────────────────────────────────────
VERSION=""
DRY_RUN=0
NO_TAG=0

for arg in "$@"; do
    case "$arg" in
        --version=*)  VERSION="${arg#--version=}";;
        --dry-run)    DRY_RUN=1;;
        --no-tag)     NO_TAG=1;;
        -h|--help)
            sed -n '3,40p' "$0"; exit 0;;
        *)
            echo "Unknown arg: $arg" >&2; exit 1;;
    esac
done

if [[ -z "${VERSION}" ]]; then
    echo "ERROR: --version=X.Y.Z is required" >&2
    exit 1
fi
if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "ERROR: VERSION must be semantic (X.Y.Z), got: ${VERSION}" >&2
    exit 1
fi

ZIP_FILE="${PROJECT_ROOT}/faz-cookie-manager-${VERSION}.zip"

cyan() { printf '\033[36m%s\033[0m\n' "$*"; }
yellow() { printf '\033[33m%s\033[0m\n' "$*"; }
red() { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }

# ── Pre-flight ───────────────────────────────────────────────────────────
cyan "═══ Pre-flight checks ═══"

# 1. svn binary
if ! command -v svn >/dev/null 2>&1; then
    red "svn not found in PATH. Install with: brew install subversion"
    exit 1
fi
echo "  svn:           $(svn --version --quiet)"

# 2. ZIP exists
if [[ ! -f "${ZIP_FILE}" ]]; then
    red "wp.org-shape ZIP not found: ${ZIP_FILE}"
    red "  Build it first via release.md §3 (this is the wp.org variant — no -full suffix)."
    exit 1
fi
echo "  ZIP:           ${ZIP_FILE} ($(du -h "${ZIP_FILE}" | cut -f1))"

# 3. SVN checkout exists
if [[ ! -d "${SVN_DIR}/.svn" ]]; then
    red "SVN checkout not found at: ${SVN_DIR}"
    red "  Run: svn co https://plugins.svn.wordpress.org/faz-cookie-manager/ ${SVN_DIR}"
    exit 1
fi
echo "  SVN checkout:  ${SVN_DIR}"

# 4. Stable tag in readme.txt matches VERSION
README_TAG="$(grep -E '^Stable tag:' "${PLUGIN_SRC}/readme.txt" | awk '{print $3}' | tr -d '\r')"
if [[ "${README_TAG}" != "${VERSION}" ]]; then
    red "readme.txt 'Stable tag: ${README_TAG}' does not match --version=${VERSION}"
    red "  Bump readme.txt before releasing (see release.md §1)."
    exit 1
fi
echo "  readme tag:    ${README_TAG} ✓"

# 5. FAZ_VERSION constant matches
FAZ_VERSION_CONST="$(grep -E "^define\( 'FAZ_VERSION'" "${PLUGIN_SRC}/faz-cookie-manager.php" | grep -oE "'[0-9]+\.[0-9]+\.[0-9]+'" | tr -d "'")"
if [[ "${FAZ_VERSION_CONST}" != "${VERSION}" ]]; then
    red "FAZ_VERSION='${FAZ_VERSION_CONST}' does not match --version=${VERSION}"
    exit 1
fi
echo "  FAZ_VERSION:   ${FAZ_VERSION_CONST} ✓"

# 6. SVN trunk reachable + up-to-date
echo
cyan "═══ svn up ═══"
( cd "${SVN_DIR}" && svn up --non-interactive )

# ── Stage extraction ─────────────────────────────────────────────────────
echo
cyan "═══ Stage: extract ZIP into ${STAGE_DIR} ═══"
rm -rf "${STAGE_DIR}"
mkdir -p "${STAGE_DIR}"
unzip -q "${ZIP_FILE}" -d "${STAGE_DIR}"
STAGE_PLUGIN="${STAGE_DIR}/faz-cookie-manager"
if [[ ! -d "${STAGE_PLUGIN}" ]]; then
    red "Extraction failed — expected ${STAGE_PLUGIN}/"
    exit 1
fi
STAGE_FILES="$(find "${STAGE_PLUGIN}" -type f | wc -l | tr -d ' ')"
STAGE_SIZE="$(du -sh "${STAGE_PLUGIN}" | cut -f1)"
echo "  Staging files: ${STAGE_FILES}  (size: ${STAGE_SIZE})"

# ── Diff staging vs current trunk ────────────────────────────────────────
echo
cyan "═══ Diff: staging vs current trunk ═══"
TRUNK_DIR="${SVN_DIR}/trunk"
TRUNK_FILES_BEFORE="$(find "${TRUNK_DIR}" -type f -not -path '*/.svn/*' 2>/dev/null | wc -l | tr -d ' ')"
echo "  Trunk files before: ${TRUNK_FILES_BEFORE}"

if [[ "${TRUNK_FILES_BEFORE}" == "0" ]]; then
    yellow "  (Trunk is empty — this is a FIRST release.)"
    echo "  All ${STAGE_FILES} staging files will be added to trunk."
else
    DIFF_OUT="$(diff -rq "${STAGE_PLUGIN}/" "${TRUNK_DIR}/" 2>/dev/null \
        | grep -v "Only in ${TRUNK_DIR}: \.svn$" || true)"
    if [[ -z "${DIFF_OUT}" ]]; then
        green "  No differences — trunk is already in sync with this version."
        echo "  (Continuing anyway — useful if only assets/ or tags/ need update.)"
    else
        echo "${DIFF_OUT}" | head -40
        DIFF_LINES="$(printf '%s\n' "${DIFF_OUT}" | wc -l | tr -d ' ')"
        if [[ "${DIFF_LINES}" -gt 40 ]]; then
            yellow "  …and $((DIFF_LINES - 40)) more lines (truncated)."
        fi
        echo
        echo "  Total diff lines:   ${DIFF_LINES}"
    fi
fi

# ── Gate 1: post-diff confirmation ───────────────────────────────────────
echo
yellow "═══ Gate 1: review the diff above ═══"
echo "  Anything unexpected (files outside the documented release scope, dev"
echo "  artefacts, suspicious size deltas) is a STOP signal — abort here and"
echo "  rebuild the ZIP from a clean main branch."
echo
read -r -p "  Proceed with sync into ${SVN_DIR}/trunk/ ? [y/N] " confirm1
if [[ "${confirm1}" != "y" && "${confirm1}" != "Y" ]]; then
    yellow "Aborted at Gate 1 — no SVN changes made."
    exit 0
fi

# ── Apply: rsync staging → trunk, copy assets, svn cp tag ────────────────
echo
cyan "═══ Apply: rsync → trunk ═══"
cd "${SVN_DIR}"
rsync -a --delete --exclude='.svn' "${STAGE_PLUGIN}/" trunk/

cyan "═══ Apply: copy screenshots/banner/icon into assets/ ═══"
mkdir -p assets
cp "${PLUGIN_SRC}/.wordpress-org/"screenshot-*.png assets/ 2>/dev/null || true
cp "${PLUGIN_SRC}/.wordpress-org/"banner-*.png assets/ 2>/dev/null || true
cp "${PLUGIN_SRC}/.wordpress-org/"icon-*.png assets/ 2>/dev/null || true

if [[ "${NO_TAG}" == "0" ]]; then
    cyan "═══ Apply: svn cp trunk → tags/${VERSION} ═══"
    if [[ -d "tags/${VERSION}" ]]; then
        red "tags/${VERSION} already exists — refusing to overwrite a published tag."
        red "  (wp.org tags are immutable. Bump the version and rerun if needed.)"
        exit 1
    fi
    svn cp trunk "tags/${VERSION}"
fi

cyan "═══ Apply: svn add new files + svn rm deleted ═══"
svn add --force trunk assets >/dev/null 2>&1 || true
[[ "${NO_TAG}" == "0" ]] && svn add --force "tags/${VERSION}" >/dev/null 2>&1 || true
# Mark deleted files for SVN.
DELETED="$(svn status | awk '/^!/ {print $2}')"
if [[ -n "${DELETED}" ]]; then
    echo "${DELETED}" | xargs -I{} svn rm {}
fi

# ── Status preview ───────────────────────────────────────────────────────
echo
cyan "═══ svn status preview (first 40 lines) ═══"
svn status | head -40
TOTAL_CHANGES="$(svn status | wc -l | tr -d ' ')"
echo
echo "  Total SVN changes staged: ${TOTAL_CHANGES}"

if [[ "${TOTAL_CHANGES}" == "0" ]]; then
    yellow "  No SVN changes — nothing to commit. Aborting."
    exit 0
fi

# ── Gate 2: pre-commit confirmation ──────────────────────────────────────
COMMIT_MSG="Release ${VERSION}"
[[ "${NO_TAG}" == "1" ]] && COMMIT_MSG="Release ${VERSION} (assets/trunk update)"

echo
yellow "═══ Gate 2: review the SVN status above ═══"
echo "  Commit message will be: \"${COMMIT_MSG}\""
echo "  Atomic commit: trunk/ + assets/ + tags/${VERSION}/ in one go."
echo

if [[ "${DRY_RUN}" == "1" ]]; then
    yellow "  --dry-run: would commit but not actually running 'svn ci'."
    echo "  Working copy is left in the staged state — inspect or revert with:"
    echo "    cd ${SVN_DIR} && svn revert -R ."
    exit 0
fi

read -r -p "  Run 'svn ci' now? [y/N] " confirm2
if [[ "${confirm2}" != "y" && "${confirm2}" != "Y" ]]; then
    yellow "Aborted at Gate 2 — working copy left in staged state."
    echo "  To revert: cd ${SVN_DIR} && svn revert -R ."
    exit 0
fi

# ── Commit ───────────────────────────────────────────────────────────────
echo
cyan "═══ svn ci ═══"
svn ci -m "${COMMIT_MSG}" --username "${SVN_USERNAME}" --non-interactive

green "════════════════════════════════════════════════════════════════════"
green "  ✓ Release ${VERSION} committed to wordpress.org SVN."
green "════════════════════════════════════════════════════════════════════"
echo "  Public page:  https://wordpress.org/plugins/faz-cookie-manager"
echo "  Tag URL:      https://plugins.svn.wordpress.org/faz-cookie-manager/tags/${VERSION}/"
echo
echo "  Note: it can take 5–30 minutes for the directory page to update,"
echo "  and up to 12 hours before all WordPress installs see the update"
echo "  notice via the wp_update_plugins cron."
