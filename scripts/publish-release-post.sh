#!/usr/bin/env bash
#
# publish-release-post.sh — publish the release write-up on fabiodalez.it.
#
# Every release gets an article in the FAZ category: what the release fixes,
# what I ran into on the way, and what I did about it. The changelog says
# WHAT changed; the article is where the reasoning lives, and it is the only
# artefact of a release a human actually reads.
#
# Usage:
#   scripts/publish-release-post.sh --version=1.28.0 \
#       --content=/path/to/article.html \
#       [--title="…"] [--screenshot=/path/shot.png] [--status=publish|draft] \
#       [--dry-run]
#
# --content is REQUIRED and holds the article body as classic HTML (<h2>, <p>,
#   <blockquote>) — the format fabiodalez.it uses. It is written per release
#   rather than generated, because what I ran into cannot be derived
#   from a diff: only whoever lived the release knows which wrong turns were
#   worth recording.
# --screenshot defaults to a fresh capture of the consent banner via
#   capture-release-screenshot.mjs. Passing --screenshot= (empty) skips the
#   image entirely; the post then has no featured image, which is a visible
#   regression on the blog and should be deliberate.
#
# Publishing is idempotent per version: if an article already exists whose slug
# is faz-cookie-manager-<version>, the script refuses rather than posting a
# duplicate. Re-running after a failure is therefore safe.
#
set -euo pipefail

PLUGIN_SRC="${PLUGIN_SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
SSH_HOST="${FAZ_BLOG_SSH_HOST:-fabiodalez.it}"
WP_PATH="${FAZ_BLOG_WP_PATH:-~/public_html}"
CATEGORY_SLUG="${FAZ_BLOG_CATEGORY:-faz}"

VERSION=""; CONTENT=""; TITLE=""; STATUS="publish"; DRY_RUN=false
SCREENSHOT="__auto__"

for arg in "$@"; do
    case "${arg}" in
        --version=*)    VERSION="${arg#--version=}" ;;
        --content=*)    CONTENT="${arg#--content=}" ;;
        --title=*)      TITLE="${arg#--title=}" ;;
        --screenshot=*) SCREENSHOT="${arg#--screenshot=}" ;;
        --status=*)     STATUS="${arg#--status=}" ;;
        --dry-run)      DRY_RUN=true ;;
        *) echo "ERROR: unknown argument '${arg}'" >&2; exit 1 ;;
    esac
done

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
cyan()  { printf '\033[36m%s\033[0m\n' "$*"; }
die()   { red "FAIL: $*"; exit 1; }

[[ "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "--version=X.Y.Z required"
[[ -n "${CONTENT}" ]] || die "--content=<file.html> required — see the header of this script for why it is not generated"
[[ -f "${CONTENT}" ]] || die "content file not found: ${CONTENT}"
[[ -s "${CONTENT}" ]] || die "content file is empty: ${CONTENT}"

SLUG="faz-cookie-manager-${VERSION//./-}"
[[ -n "${TITLE}" ]] || TITLE="FAZ Cookie Manager ${VERSION}"

# shellcheck disable=SC2029 # WP_PATH must expand HERE: it names the remote
# directory and is ours, not user input. Expanding it on the far side would
# send the literal string and break every call.
wp_remote() { ssh "${SSH_HOST}" "cd ${WP_PATH} && wp $*"; }

cyan "═══ Preflight ═══"
ssh -o BatchMode=yes -o ConnectTimeout=10 "${SSH_HOST}" true 2>/dev/null \
    || die "cannot reach ${SSH_HOST} over ssh"
wp_remote core version >/dev/null 2>&1 || die "wp-cli not usable at ${SSH_HOST}:${WP_PATH}"
green "  ✓ ${SSH_HOST} reachable, wp-cli responds"

# Idempotence. A second run after a partial failure must not create a twin.
EXISTING="$(wp_remote post list --post_type=post --name="${SLUG}" --field=ID --post_status=any 2>/dev/null | tr -d '\r')"
[[ -z "${EXISTING}" ]] || die "a post with slug '${SLUG}' already exists (ID ${EXISTING}) — delete it first, or bump the version"
green "  ✓ no existing post for ${VERSION}"

CAT_ID="$(wp_remote term list category --slug="${CATEGORY_SLUG}" --field=term_id 2>/dev/null | tr -d '\r')"
if [[ -n "${CAT_ID}" ]]; then
    green "  ✓ category '${CATEGORY_SLUG}' exists (${CAT_ID})"
elif [[ "${DRY_RUN}" == "true" ]]; then
    # A dry run must not touch the remote site. Creating the category here made
    # --dry-run mutate production while printing "Nothing was published."
    CAT_ID="<would be created>"
    green "  ✓ category '${CATEGORY_SLUG}' would be created"
else
    CAT_ID="$(wp_remote term create category "FAZ" --slug="${CATEGORY_SLUG}" --porcelain 2>/dev/null | tr -d '\r')"
    [[ -n "${CAT_ID}" ]] || die "could not create the '${CATEGORY_SLUG}' category"
    green "  ✓ category '${CATEGORY_SLUG}' created (${CAT_ID})"
fi

# ── Screenshot ───────────────────────────────────────────────────────────
SHOT=""
if [[ "${SCREENSHOT}" == "__auto__" ]]; then
    cyan "═══ Capturing the banner ═══"
    SHOT="/tmp/faz-release-${VERSION}.png"
    node "${PLUGIN_SRC}/scripts/capture-release-screenshot.mjs" --out="${SHOT}" >/dev/null \
        || die "screenshot capture failed — is the local test site up on 127.0.0.1:9998?"
    green "  ✓ captured ${SHOT} ($(du -h "${SHOT}" | cut -f1))"
elif [[ -n "${SCREENSHOT}" ]]; then
    [[ -f "${SCREENSHOT}" ]] || die "screenshot not found: ${SCREENSHOT}"
    SHOT="${SCREENSHOT}"
    green "  ✓ using ${SHOT}"
else
    red "  ! no screenshot — the post will have no featured image"
fi

if [[ "${DRY_RUN}" == "true" ]]; then
    cyan "═══ Dry run ═══"
    echo "  title:    ${TITLE}"
    echo "  slug:     ${SLUG}"
    echo "  category: ${CATEGORY_SLUG} (${CAT_ID})"
    echo "  status:   ${STATUS}"
    echo "  content:  ${CONTENT} ($(wc -c <"${CONTENT}" | tr -d ' ') bytes)"
    echo "  image:    ${SHOT:-<none>}"
    green "Nothing was published."
    exit 0
fi

# ── Upload the image and create the post ─────────────────────────────────
# mktemp on the far side, not a path built from the version and our PID. On a
# shared host that name is guessable: another user could pre-create it, or leave
# a symlink there before scp runs, and the article body and screenshot would be
# written somewhere else entirely.
REMOTE_TMP="$(ssh "${SSH_HOST}" 'mktemp -d /tmp/faz-release.XXXXXXXX')" \
    || die "could not create a remote temporary directory"
[[ -n "${REMOTE_TMP}" ]] || die "remote mktemp returned nothing"
# shellcheck disable=SC2064 # expand REMOTE_TMP now: the trap must survive the var going out of scope
trap "ssh '${SSH_HOST}' 'rm -rf ${REMOTE_TMP}' >/dev/null 2>&1 || true" EXIT

ATT_ID=""
if [[ -n "${SHOT}" ]]; then
    cyan "═══ Uploading the image ═══"
    scp -q "${SHOT}" "${SSH_HOST}:${REMOTE_TMP}/$(basename "${SHOT}")"
    ATT_ID="$(wp_remote media import "${REMOTE_TMP}/$(basename "${SHOT}")" \
        --title="'FAZ Cookie Manager ${VERSION}'" \
        --alt="'Il banner di consenso di FAZ Cookie Manager ${VERSION}'" \
        --porcelain 2>/dev/null | tr -d '\r')"
    [[ -n "${ATT_ID}" ]] || die "media import failed"
    green "  ✓ attachment ${ATT_ID}"
fi

cyan "═══ Creating the post ═══"
scp -q "${CONTENT}" "${SSH_HOST}:${REMOTE_TMP}/body.html"
# shellcheck disable=SC2029 # Same as above: both paths are local values that
# have to be baked into the command before it is sent.
POST_ID="$(ssh "${SSH_HOST}" "cd ${WP_PATH} && wp post create ${REMOTE_TMP}/body.html \
    --post_title=$(printf '%q' "${TITLE}") \
    --post_name='${SLUG}' \
    --post_status='${STATUS}' \
    --post_category='${CAT_ID}' \
    --porcelain" 2>/dev/null | tr -d '\r')"
[[ -n "${POST_ID}" ]] || die "post creation failed"

if [[ -n "${ATT_ID}" ]]; then
    wp_remote post meta update "${POST_ID}" _thumbnail_id "${ATT_ID}" >/dev/null 2>&1 \
        || red "  ! could not set the featured image (post ${POST_ID}, attachment ${ATT_ID})"
    wp_remote post update "${ATT_ID}" --post_parent="${POST_ID}" >/dev/null 2>&1 || true
fi

URL="$(wp_remote post list --post__in="${POST_ID}" --field=url --post_status=any 2>/dev/null | tr -d '\r')"
green "════════════════════════════════════════════════════════════════════"
green "  ✓ Post ${POST_ID} (${STATUS}) — ${URL:-slug ${SLUG}}"
green "════════════════════════════════════════════════════════════════════"
