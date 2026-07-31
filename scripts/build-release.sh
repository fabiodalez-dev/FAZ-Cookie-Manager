#!/usr/bin/env bash
#
# build-release.sh — build release ZIPs for wp.org, GitHub, and ClassicPress.
#
# Usage:
#   scripts/build-release.sh --version=1.13.17
#   scripts/build-release.sh --version=1.13.17 --output-dir=/tmp/faz-release
#
# Outputs:
#   faz-cookie-manager-{version}.zip       wp.org shape
#   faz-cookie-manager-{version}-full.zip  GitHub full shape
#   faz-cookie-manager-v{version}.zip      ClassicPress Directory shape

set -euo pipefail

PROJECT_ROOT="${PROJECT_ROOT:-/Users/fabio/Documents/GitHub/Cookie Crawler}"
PLUGIN_SRC="${PLUGIN_SRC:-${PROJECT_ROOT}/faz-cookie-manager}"
OUTPUT_DIR="${OUTPUT_DIR:-${PROJECT_ROOT}}"
CP_REQUIRES="${CP_REQUIRES:-1.0}"

VERSION=""

for arg in "$@"; do
    case "$arg" in
        --version=*)    VERSION="${arg#--version=}";;
        --output-dir=*) OUTPUT_DIR="${arg#--output-dir=}";;
        -h|--help)
            sed -n '3,22p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown arg: $arg" >&2
            exit 1
            ;;
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
if [[ ! -d "${PLUGIN_SRC}" ]]; then
    echo "ERROR: plugin source not found: ${PLUGIN_SRC}" >&2
    exit 1
fi

PLUGIN_SLUG="faz-cookie-manager"
MAIN_FILE="${PLUGIN_SRC}/${PLUGIN_SLUG}.php"
README_FILE="${PLUGIN_SRC}/readme.txt"
mkdir -p "${OUTPUT_DIR}"

cyan() { printf '\033[36m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
red() { printf '\033[31m%s\033[0m\n' "$*"; }

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        red "Missing required command: $1"
        exit 1
    fi
}

require_cmd rsync
require_cmd zip
require_cmd unzip

header_value() {
    local field="$1"
    grep -E "^[[:space:]]*\\*[[:space:]]*${field}:" "${MAIN_FILE}" \
        | head -1 \
        | sed -E "s/^[[:space:]]*\\*[[:space:]]*${field}:[[:space:]]*//" \
        | tr -d '\r'
}

README_TAG="$(grep -E '^Stable tag:' "${README_FILE}" | awk '{print $3}' | tr -d '\r')"
PLUGIN_VERSION="$(header_value "Version")"
FAZ_VERSION_CONST="$(grep -E "^define\( 'FAZ_VERSION'" "${MAIN_FILE}" | grep -oE "'[0-9]+\.[0-9]+\.[0-9]+'" | tr -d "'")"

if [[ "${README_TAG}" != "${VERSION}" ]]; then
    red "readme.txt Stable tag (${README_TAG}) does not match --version=${VERSION}"
    exit 1
fi
if [[ "${PLUGIN_VERSION}" != "${VERSION}" ]]; then
    red "Plugin header Version (${PLUGIN_VERSION}) does not match --version=${VERSION}"
    exit 1
fi
if [[ "${FAZ_VERSION_CONST}" != "${VERSION}" ]]; then
    red "FAZ_VERSION (${FAZ_VERSION_CONST}) does not match --version=${VERSION}"
    exit 1
fi

# The ZIPs are built from `git archive HEAD` (see copy_plugin), so uncommitted
# work is silently absent from them. That is the right default for a release —
# the package must equal the commit it claims to be — but it makes a dirty tree
# a trap during local experiments, where the edit you are testing simply is not
# in the ZIP. Say so loudly; --allow-dirty is for local iteration only and must
# never be used by the publish flow.
ALLOW_DIRTY="${ALLOW_DIRTY:-false}"
if [[ -n "$(git -C "${PLUGIN_SRC}" status --porcelain 2>/dev/null)" ]]; then
    if [[ "${ALLOW_DIRTY}" == "true" ]]; then
        red "WARNING: working tree is dirty; the ZIPs contain HEAD, not your edits."
    else
        red "FAIL: working tree is dirty. The ZIPs are built from HEAD, so uncommitted"
        red "      changes would NOT be in them. Commit first, or re-run with ALLOW_DIRTY=true"
        red "      if you are only experimenting locally."
        git -C "${PLUGIN_SRC}" status --short >&2
        exit 1
    fi
fi

COMMON_EXCLUDES=(
    "faz-cookie-manager/.git/*"
    "faz-cookie-manager/.github/*"
    "faz-cookie-manager/.githooks/*"
    "faz-cookie-manager/.claude/*"
    "faz-cookie-manager/.wordpress-org/*"
    "faz-cookie-manager/.coderabbit.yaml"
    "faz-cookie-manager/.distignore"
    "faz-cookie-manager/assets/*"
    "faz-cookie-manager/node_modules/*"
    "faz-cookie-manager/vendor/*"
    "faz-cookie-manager/tests/*"
    "faz-cookie-manager/test-results/*"
    "faz-cookie-manager/.playwright-mcp/*"
    "faz-cookie-manager/.playwright-cli/*"
    "faz-cookie-manager/.code-review-graph/*"
    "faz-cookie-manager/graphify-out/*"
    "faz-cookie-manager/.serena/*"
    "faz-cookie-manager/.phpcs-tools/*"
    "faz-cookie-manager/.specify/*"
    "faz-cookie-manager/specs/*"
    "faz-cookie-manager/.gitignore"
    "faz-cookie-manager/.gitattributes"
    "faz-cookie-manager/.env*"
    "faz-cookie-manager/package*.json"
    "faz-cookie-manager/tsconfig.json"
    "faz-cookie-manager/composer.json"
    "faz-cookie-manager/composer.lock"
    "faz-cookie-manager/phpstan.neon"
    "faz-cookie-manager/phpstan-bootstrap.php"
    "faz-cookie-manager/.DS_Store"
    "faz-cookie-manager/.security-tools/*"
    "faz-cookie-manager/CLAUDE-SECURITY-*"
    "faz-cookie-manager/**/.DS_Store"
    "faz-cookie-manager/docs/*"
    "faz-cookie-manager/scripts/*"
    "faz-cookie-manager/social-preview.png"
    "faz-cookie-manager/bricks-placeholder.png"
    "faz-cookie-manager/settings-*.png"
    "faz-cookie-manager/settings-*.jpg"
    "faz-cookie-manager/fabiodalez-*.png"
    "faz-cookie-manager/fabiodalez-*.jpg"
    # Catch-all for stray root-level screenshots/images (the * does not cross /,
    # so this only excludes files directly in the plugin root — legitimate plugin
    # images live under admin/dist/img, frontend/images, and .wordpress-org).
    "faz-cookie-manager/*.png"
    "faz-cookie-manager/*.jpg"
    "faz-cookie-manager/*.jpeg"
    "faz-cookie-manager/*.gif"
    "faz-cookie-manager/*.webp"
    "faz-cookie-manager/release.md"
    "faz-cookie-manager/plan.md"
    "faz-cookie-manager/eslint.config.mjs"
    "faz-cookie-manager/cookie-banner-compliance-checklist.md"
    "faz-cookie-manager/languages/*.po~"
    "faz-cookie-manager/languages/messages.mo"
    "faz-cookie-manager/biome.json"
    "faz-cookie-manager/CLAUDE.md"
    "faz-cookie-manager/report.md"
    "faz-cookie-manager/README.md"
    "faz-cookie-manager/CHANGELOG.md"
    "faz-cookie-manager/revisit.svg"
    "faz-cookie-manager/*.log"
    "faz-cookie-manager/*.zip"
)

WPORG_ONLY_EXCLUDES=(
    "faz-cookie-manager/admin/modules/scanner/run-scan.php"
    "faz-cookie-manager/admin/assets/js/cp-api-fetch-polyfill.js"
)

copy_plugin() {
    local dest="$1"
    shift
    local excludes=("${COMMON_EXCLUDES[@]}" "$@")
    local rsync_args=(-a --delete)
    local pattern

    rm -rf "${dest}"
    mkdir -p "${dest}"
    for pattern in "${excludes[@]}"; do
        if [[ "${pattern}" == "${PLUGIN_SLUG}/"* ]]; then
            pattern="/${pattern#${PLUGIN_SLUG}/}"
        fi
        rsync_args+=(--exclude="${pattern}")
    done
    # Source from `git archive HEAD`, never the working tree. Rsyncing the
    # working tree means anything untracked sitting in the plugin directory
    # ships by default — that is how a stray Playwright screenshot reached SVN
    # trunk in 1.19.2 and a security-scan report directory reached all three
    # 1.25.0 ZIPs and the GitHub release assets. Both were caught by a human
    # reading a diff. Archiving the commit makes the whole class impossible
    # rather than merely detectable, and guarantees the ZIP matches the tag.
    local archive_src="${TMP_ROOT}/gitarchive"
    rm -rf "${archive_src}"
    mkdir -p "${archive_src}"
    git -C "${PLUGIN_SRC}" archive HEAD | tar -x -C "${archive_src}"
    rsync "${rsync_args[@]}" "${archive_src}/" "${dest}/${PLUGIN_SLUG}/"
    rm -rf "${archive_src}"
    find "${dest}/${PLUGIN_SLUG}" -type d -empty -delete
}

zip_stage() {
    local stage="$1"
    local zip_file="$2"

    rm -f "${zip_file}"
    ( cd "${stage}" && zip -qr "${zip_file}" "${PLUGIN_SLUG}" )
}

assert_contains() {
    local zip_file="$1"
    local pattern="$2"
    local listing
    listing="$(unzip -Z1 "${zip_file}")"
    if ! grep -Eq "${pattern}" <<< "${listing}"; then
        red "FAIL: ${zip_file} should contain ${pattern}"
        exit 1
    fi
}

assert_not_contains() {
    local zip_file="$1"
    local pattern="$2"
    local listing
    listing="$(unzip -Z1 "${zip_file}")"
    if grep -Eq "${pattern}" <<< "${listing}"; then
        red "FAIL: ${zip_file} should not contain ${pattern}"
        exit 1
    fi
}

inject_classicpress_header() {
    local file="$1"
    if grep -q '^[[:space:]]*\*[[:space:]]*Requires CP:' "${file}"; then
        return
    fi
    awk -v cp="${CP_REQUIRES}" '
        {
            print
            if ($0 ~ /^[[:space:]]*\*[[:space:]]*Requires PHP:/) {
                print " * Requires CP:        " cp
            }
        }
    ' "${file}" > "${file}.tmp"
    mv "${file}.tmp" "${file}"
}

TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/faz-release.XXXXXX")"
trap 'rm -rf "${TMP_ROOT}"' EXIT

WPORG_STAGE="${TMP_ROOT}/wporg"
FULL_STAGE="${TMP_ROOT}/full"
CP_STAGE="${TMP_ROOT}/classicpress"

WPORG_ZIP="${OUTPUT_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
FULL_ZIP="${OUTPUT_DIR}/${PLUGIN_SLUG}-${VERSION}-full.zip"
CP_ZIP="${OUTPUT_DIR}/${PLUGIN_SLUG}-v${VERSION}.zip"

cyan "Building wp.org ZIP"
# Stage via rsync (copy_plugin) then zip the filtered tree. rsync anchors the
# root-level catch-all excludes (e.g. /*.png) so legitimate sub-directory images
# like frontend/images/cookie.png survive. Zipping the source directly with
# `zip -x "faz-cookie-manager/*.png"` does NOT work: Info-ZIP's `*` crosses `/`,
# so it silently strips frontend/images/cookie.png (the default banner logo).
copy_plugin "${WPORG_STAGE}" "${WPORG_ONLY_EXCLUDES[@]}"
zip_stage "${WPORG_STAGE}" "${WPORG_ZIP}"

cyan "Building GitHub full ZIP"
copy_plugin "${FULL_STAGE}"
zip_stage "${FULL_STAGE}" "${FULL_ZIP}"

cyan "Building ClassicPress ZIP"
copy_plugin "${CP_STAGE}"
inject_classicpress_header "${CP_STAGE}/${PLUGIN_SLUG}/${PLUGIN_SLUG}.php"
cp "${PLUGIN_SRC}/README.md" "${CP_STAGE}/${PLUGIN_SLUG}/README.md"
zip_stage "${CP_STAGE}" "${CP_ZIP}"

cyan "Sanity checks"

# Every shipped file must be one git knows about.
#
# This script rsyncs the working tree rather than using `git archive`, so
# anything sitting untracked in the plugin directory ships too. That is how a
# security-scan report directory reached all three 1.25.0 ZIPs and the GitHub
# release assets, caught only by eyeballing the SVN staging diff. The exclude
# list is the safety net for artefacts we know about; this is the backstop for
# the ones nobody thought of, and it fails the build rather than the review.
# LC_ALL=C must wrap `comm` itself, not just the two `sort`s: comm compares
# with the *current* locale, so C-sorted input fed to a UTF-8 comm reports
# almost every line as divergent.
untracked_in_zip=$(
    LC_ALL=C comm -23 \
        <(unzip -Z1 "${WPORG_ZIP}" | sed "s#^${PLUGIN_SLUG}/##" | grep -v '/$' | grep -v '^$' | LC_ALL=C sort -u) \
        <(git -C "${PROJECT_ROOT}/${PLUGIN_SLUG}" ls-files | LC_ALL=C sort -u)
)
# The other direction: a tracked file that the ZIP is MISSING. This is the
# 1.20.0 near-miss — exclude patterns silently dropped frontend/images/cookie.png,
# the referenced default banner logo, and committing that to SVN would have
# deleted it for every live install. A human spotted the `D` in the staging
# diff. Comparing the two sets catches it without one, for every tracked file
# rather than the three that happen to have a named assert below.
missing_from_zip=$(
    LC_ALL=C comm -13 \
        <(unzip -Z1 "${WPORG_ZIP}" | sed "s#^${PLUGIN_SLUG}/##" | grep -v '/$' | grep -v '^$' | LC_ALL=C sort -u) \
        <(git -C "${PROJECT_ROOT}/${PLUGIN_SLUG}" ls-files | LC_ALL=C sort -u)
)
# Everything legitimately absent is absent because an exclude says so. Rather
# than re-implement rsync's matching, ask the staged tree: a tracked file that
# survived staging but is missing from the ZIP is a packaging bug.
unexpectedly_missing=""
while IFS= read -r f; do
    [[ -z "${f}" ]] && continue
    if [[ -e "${WPORG_STAGE}/${PLUGIN_SLUG}/${f}" ]]; then
        unexpectedly_missing+="${f}"$'\n'
    fi
done <<< "${missing_from_zip}"
if [[ -n "${unexpectedly_missing//[[:space:]]/}" ]]; then
    red "FAIL: files staged for release are missing from the wp.org ZIP:"
    printf '  %s\n' ${unexpectedly_missing} >&2
    exit 1
fi

if [[ -n "${untracked_in_zip}" ]]; then
    red "FAIL: the wp.org ZIP ships files git does not track:"
    printf '  %s\n' ${untracked_in_zip} >&2
    red "Move them out of ${PLUGIN_SLUG}/ or add them to COMMON_EXCLUDES."
    exit 1
fi

assert_not_contains "${WPORG_ZIP}" 'run-scan\.php'
assert_not_contains "${WPORG_ZIP}" 'cp-api-fetch-polyfill\.js'
# The default banner logo is a referenced raster asset (admin/class-admin.php
# defaultLogo). It must ship in every variant — a build that drops it leaves
# users with a broken default logo. Guards the zip-glob-crosses-slash bug.
assert_contains "${WPORG_ZIP}" 'frontend/images/cookie\.png'
assert_contains "${FULL_ZIP}" 'frontend/images/cookie\.png'
assert_contains "${CP_ZIP}" 'frontend/images/cookie\.png'
assert_contains "${FULL_ZIP}" 'run-scan\.php'
assert_contains "${FULL_ZIP}" 'cp-api-fetch-polyfill\.js'
assert_contains "${CP_ZIP}" 'run-scan\.php'
assert_contains "${CP_ZIP}" 'cp-api-fetch-polyfill\.js'
assert_contains "${CP_ZIP}" 'README\.md'
if ! unzip -p "${CP_ZIP}" "${PLUGIN_SLUG}/${PLUGIN_SLUG}.php" | grep -q "Requires CP:[[:space:]]*${CP_REQUIRES}"; then
    red "FAIL: ClassicPress ZIP missing Requires CP: ${CP_REQUIRES}"
    exit 1
fi

green "Built release ZIPs:"
printf '  %s (%s)\n' "${WPORG_ZIP}" "$(du -h "${WPORG_ZIP}" | cut -f1)"
printf '  %s (%s)\n' "${FULL_ZIP}" "$(du -h "${FULL_ZIP}" | cut -f1)"
printf '  %s (%s)\n' "${CP_ZIP}" "$(du -h "${CP_ZIP}" | cut -f1)"
