#!/usr/bin/env bash
#
# publish-release.sh — publish one version, in an order that cannot burn it.
#
# WHY THIS EXISTS
#
# The old flow was: git tag → GitHub release → SVN. That ordering is the bug.
# Pushing vX.Y.Z makes Packagist freeze the referenced commit immediately, and
# force-moving a published tag is refused — so any problem found after tagging
# but before wp.org went live cost a version number (1.19.1 → 1.19.2). Yet
# nothing downstream needs the git tag first: wp.org's tags/X.Y.Z is created by
# the same `svn ci`, and Packagist and the GitHub release are pure consumers.
#
# So the order is inverted here. The SVN commit is the moment the release
# becomes real; the git tag records that it happened. Everything before it is
# reversible, and a failure at any point costs a rerun, not a version.
#
# The GitHub release is created as a DRAFT and only published at the end.
# Draft assets are not publicly downloadable — which is what would have
# contained the 1.25.0 security-report leak instead of exposing it.
#
# RESUMABILITY
#
# Each completed step is appended to .release-state-X.Y.Z.json. Re-running the
# same command skips what is already done rather than repeating it, so an
# interruption between "SVN committed" and "tag pushed" resolves by re-running.
#
# Usage:
#   scripts/publish-release.sh --version=1.26.0
#   scripts/publish-release.sh --version=1.26.0 --dry-run   # phases A-B only
#
set -euo pipefail

PLUGIN_SRC="${PLUGIN_SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PROJECT_ROOT="${PROJECT_ROOT:-$(cd "${PLUGIN_SRC}/.." && pwd)}"
SLUG="faz-cookie-manager"

VERSION=""; DRY_RUN=false
for arg in "$@"; do
    case "$arg" in
        --version=*) VERSION="${arg#--version=}" ;;
        --dry-run)   DRY_RUN=true ;;
        -h|--help)   sed -n '3,30p' "$0"; exit 0 ;;
        *) echo "Unknown arg: $arg" >&2; exit 1 ;;
    esac
done
[[ "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "ERROR: --version=X.Y.Z required" >&2; exit 1; }

STATE="${PLUGIN_SRC}/.release-state-${VERSION}.json"
TAG="v${VERSION}"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
cyan()  { printf '\033[36m%s\033[0m\n' "$*"; }
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }

done_step()  { [[ -f "${STATE}" ]] && grep -q "\"$1\"" "${STATE}"; }
mark_step()  { printf '{"step":"%s","at":"%s"}\n' "$1" "$(date -u +%FT%TZ)" >> "${STATE}"; }
die()        { red "FAIL: $*"; exit 1; }

# ═══════════════════════════ Phase A — preflight ═══════════════════════════
# Read-only and all-or-nothing. Nothing below this point should ever discover
# a problem that could have been found here.
cyan "═══ A. Preflight ═══"

branch="$(git -C "${PLUGIN_SRC}" rev-parse --abbrev-ref HEAD)"
[[ "${branch}" == "main" ]] || die "on branch ${branch}, expected main"
[[ -z "$(git -C "${PLUGIN_SRC}" status --porcelain)" ]] || die "working tree is dirty"

git -C "${PLUGIN_SRC}" fetch origin --quiet
head_sha="$(git -C "${PLUGIN_SRC}" rev-parse HEAD)"
[[ "${head_sha}" == "$(git -C "${PLUGIN_SRC}" rev-parse origin/main)" ]] \
    || die "HEAD is not pushed to origin/main"
green "  ✓ clean main, pushed (${head_sha:0:7})"

bash "${PLUGIN_SRC}/scripts/bump-version.sh" "${VERSION}" --check >/dev/null 2>&1 \
    || { bash "${PLUGIN_SRC}/scripts/bump-version.sh" "${VERSION}" --check; die "version/changelog checks failed"; }
green "  ✓ version consistent in 4 places; changelog entries present and under the cap"

# CI is a gate here, not advice. A red Plugin Check means wp.org review will
# reject what we are about to publish.
if command -v gh >/dev/null 2>&1; then
    concl="$(gh api "repos/{owner}/{repo}/commits/${head_sha}/check-runs" \
		--jq '[.check_runs[] | select(.name | test("Plugin Check|CodeQL|Quality")) | .conclusion] | unique | join(",")' 2>/dev/null || echo "")"
    if [[ -z "${concl}" ]]; then
        red "  ! could not read CI status for ${head_sha:0:7} — continuing, verify manually"
    elif [[ "${concl}" != "success" ]]; then
        die "CI on ${head_sha:0:7} is '${concl}', not success"
    else
        green "  ✓ CI green on this commit"
    fi
fi

# Nothing may already exist under this version. Discovering a half-published
# version mid-flight is the state this script exists to prevent.
if git -C "${PLUGIN_SRC}" rev-parse "${TAG}" >/dev/null 2>&1 && ! done_step svn_committed; then
    die "tag ${TAG} already exists but SVN was not committed by this run — resolve by hand"
fi
if svn ls "https://plugins.svn.wordpress.org/${SLUG}/tags/${VERSION}" >/dev/null 2>&1 && ! done_step svn_committed; then
    die "wp.org already has tags/${VERSION}"
fi
green "  ✓ ${VERSION} is unpublished"

if [[ "${DRY_RUN}" == "true" ]]; then
    cyan "Dry run: stopping before any build or publish."
    exit 0
fi

# ═══════════════════ Phase B — build + draft release ═══════════════════════
# Reversible. Draft assets are private, so a leak here is not yet an incident.
cyan "═══ B. Build and stage a DRAFT release ═══"

if ! done_step built; then
    bash "${PLUGIN_SRC}/scripts/build-release.sh" --version="${VERSION}"
    mark_step built
fi
WPORG_ZIP="${PROJECT_ROOT}/${SLUG}-${VERSION}.zip"
FULL_ZIP="${PROJECT_ROOT}/${SLUG}-${VERSION}-full.zip"
CP_ZIP="${PROJECT_ROOT}/${SLUG}-v${VERSION}.zip"
for z in "${WPORG_ZIP}" "${FULL_ZIP}" "${CP_ZIP}"; do [[ -f "$z" ]] || die "missing build output: $z"; done
green "  ✓ three ZIPs built"

if ! done_step draft; then
    # Release notes come from this version's CHANGELOG.md section only. Passing
    # the whole file (as release.md once suggested) ships the entire history as
    # the release notes for every version.
    notes="$(awk -v v="## \\[${VERSION}\\]" '$0 ~ v {f=1; next} /^## \[/ {f=0} f' "${PLUGIN_SRC}/CHANGELOG.md")"
    [[ -n "${notes}" ]] || die "no CHANGELOG.md section for ${VERSION}"
    printf '%s\n' "${notes}" > "${PLUGIN_SRC}/.release-notes-${VERSION}.md"
    gh release create "${TAG}" --draft --title "${TAG}" \
        --notes-file "${PLUGIN_SRC}/.release-notes-${VERSION}.md" --target main
    gh release upload "${TAG}" "${WPORG_ZIP}" "${FULL_ZIP}" "${CP_ZIP}" --clobber
    mark_step draft
fi
green "  ✓ draft release holds the three ZIPs (not publicly downloadable)"

# ═════════════════ Phase C/D — human gate on the SVN diff ══════════════════
# svn-release.sh keeps BOTH of its [y/N] gates. Every automated check above is
# an inventory check; the human is here for intent — "why does this release
# touch that file?" — which is the judgement that caught 1.20.0 and 1.25.0.
cyan "═══ C. SVN staging — review the diff, then confirm ═══"
bold "  The staging diff is the last line of defence. Read it."

if ! done_step svn_committed; then
    bash "${PLUGIN_SRC}/scripts/svn-release.sh" --version="${VERSION}"
    mark_step svn_committed
    green "  ✓ published to wordpress.org"
fi

# ═══════════════════ Phase E — irreversible, in order ══════════════════════
# The release is real now. The tag records that; it no longer risks anything.
cyan "═══ D. Record the release ═══"

if ! done_step tagged; then
    git -C "${PLUGIN_SRC}" tag -a "${TAG}" -m "${TAG}" 2>/dev/null || true
    git -C "${PLUGIN_SRC}" push origin "${TAG}"
    mark_step tagged
fi
green "  ✓ ${TAG} pushed (Packagist freezes it now — the release already exists)"

if ! done_step published; then
    gh release edit "${TAG}" --draft=false
    mark_step published
fi
green "  ✓ GitHub release published"

# ════════════════════ Phase F — post-publish verification ══════════════════
cyan "═══ E. Verify what the world sees ═══"

remote_tag_files="$(svn ls -R "https://plugins.svn.wordpress.org/${SLUG}/tags/${VERSION}" 2>/dev/null | grep -v '/$' | LC_ALL=C sort -u | wc -l | tr -d ' ')"
zip_files="$(unzip -Z1 "${WPORG_ZIP}" | sed "s#^${SLUG}/##" | grep -v '/$' | grep -v '^$' | LC_ALL=C sort -u | wc -l | tr -d ' ')"
if [[ "${remote_tag_files}" == "${zip_files}" ]]; then
    green "  ✓ wp.org tag matches the ZIP (${zip_files} files)"
else
    red "  ! wp.org tag has ${remote_tag_files} files, ZIP has ${zip_files} — investigate"
fi

cyan "  waiting for wp.org to serve ${VERSION} (up to 30 min)…"
for _ in $(seq 1 60); do
    live="$(curl -s "https://api.wordpress.org/plugins/info/1.0/${SLUG}.json" | sed -n 's/.*"version":"\([^"]*\)".*/\1/p' | head -1)"
    [[ "${live}" == "${VERSION}" ]] && break
    sleep 30
done
if [[ "${live:-}" == "${VERSION}" ]]; then
    green "  ✓ wp.org serves ${VERSION}"
else
    red "  ! wp.org still serves ${live:-unknown}"
fi

rm -f "${PLUGIN_SRC}/.release-notes-${VERSION}.md"
echo
bold "Published ${VERSION}."
echo "Remaining manual steps — these are not automatable:"
echo "  • Revoke and regenerate the SVN Application Password on wordpress.org."
echo "  • Smoke-test in Playground: https://playground.wordpress.net/?plugin=${SLUG}"
echo "  • Eyeball https://wordpress.org/plugins/${SLUG}/ once the directory page updates."
echo
echo "State file kept at ${STATE} — delete it once you are satisfied."
