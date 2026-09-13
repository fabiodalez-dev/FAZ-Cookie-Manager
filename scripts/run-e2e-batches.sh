#!/bin/bash
# Run the E2E suite in batches, resetting the site between each.
#
# The whole suite does not complete reliably as one concurrent pass: many specs
# intentionally mutate the same WordPress options and custom tables. Parallel
# workers therefore race even inside a freshly reset batch and create moving
# reds that pass in isolation. Batching limits process lifetime; one worker is
# the safe default that makes each database mutation serial and comparable.
set -u

# Resolve the repo from this script's own location so the runner is portable.
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP="${WP_PATH:-/Users/fabio/Sites/faz-test}"
OUT="${E2E_BATCH_OUT:-/private/tmp/faz-e2e-batch}"
BATCH_SIZE="${BATCH_SIZE:-1}"
E2E_WORKERS="${E2E_WORKERS:-1}"
mkdir -p "$OUT"
# A resumed run must not combine green batches from different candidates.
COMMIT="$(git -C "$REPO" rev-parse HEAD)"
if ! CANDIDATE_STATUS="$(git -C "$REPO" status --porcelain --untracked-files=all)" || [ -n "$CANDIDATE_STATUS" ]; then
  echo 'Commit the candidate before collecting release E2E evidence.' >&2
  exit 2
fi
if [ "${START_BATCH:-1}" = "1" ]; then
  printf '%s\n' "$COMMIT" > "$OUT/commit.txt"
elif [ "$(cat "$OUT/commit.txt" 2>/dev/null)" != "$COMMIT" ]; then
  echo 'Cannot resume E2E batches from a different commit.' >&2
  exit 2
fi

rm -f "$OUT/evidence.json"
# Lifecycle and compliance specs re-deploy their source tree. In release mode
# their source must remain the verified archive, never the development checkout.
if [ -n "${FAZ_E2E_PACKAGE:-}" ]; then
  PACKAGE_SOURCE="$(mktemp -d "$OUT/package-source.XXXXXX")" || exit 2
  python3 - "$FAZ_E2E_PACKAGE" "$PACKAGE_SOURCE" <<'PYSOURCE' || exit 2
import stat
import sys
import zipfile
from pathlib import PurePosixPath
with zipfile.ZipFile(sys.argv[1]) as archive:
    for entry in archive.infolist():
        parts = PurePosixPath(entry.filename).parts
        if not parts or parts[0] != 'faz-cookie-manager' or '..' in parts or stat.S_ISLNK(entry.external_attr >> 16):
            raise ValueError('unsafe release ZIP member')
    archive.extractall(sys.argv[2])
PYSOURCE
  export FAZ_PLUGIN_SOURCE_PATH="$PACKAGE_SOURCE/faz-cookie-manager/"
  # Compare the ZIP with the INSTALLED plugin, not with the copy just extracted
  # from that same ZIP — that comparison can only ever come back empty. The
  # drift list is the answer here, so an empty one is the only one that may
  # continue: the site must already run the package under test.
  PACKAGE_DRIFT="$(python3 "$REPO/scripts/check-package-deploy.py" "$FAZ_E2E_PACKAGE" \
    "${FAZ_E2E_BUILD_MANIFEST:?Release package manifest is required}" "$REPO" \
    "$WP/wp-content/plugins/faz-cookie-manager/")" || exit 2
  if [ "$PACKAGE_DRIFT" != "[]" ]; then
    echo "The installed plugin is not the package under test:" >&2
    echo "$PACKAGE_DRIFT" >&2
    echo "Install it first: wp --path=$WP plugin install $FAZ_E2E_PACKAGE --force" >&2
    exit 2
  fi
fi
cd "$REPO" || exit 1
# The same mandatory consent gate as npm run test:e2e, once before any reset.
npm run test:consent || exit $?
# macOS ships bash 3.2, which has no mapfile; fill the array portably.
SPECS=()
while IFS= read -r line; do
  # This spec requires an actual subdirectory network and a /child base URL.
  # It is exercised by test:e2e:multisite, which creates that network from
  # scratch; running it against this single-site fixture is always invalid.
  case "$line" in
    *release-verify-multisite-scanner.spec.ts) continue ;;
  esac
  SPECS+=("$line")
done < <(find tests/e2e/specs -maxdepth 1 -type f -name '*.spec.ts' -print | LC_ALL=C sort)
TOTAL=${#SPECS[@]}
# No spec found means the glob, the path or the checkout is wrong — never that
# the suite passed. Without this the batch loop never runs and the evidence is
# written for zero tests.
if [ "$TOTAL" -eq 0 ]; then
  echo 'No single-site E2E specs found: refusing to record evidence for zero tests.' >&2
  exit 2
fi

reset_site() {
  ( cd "$WP" || return
    wp option delete faz_banner_template faz_httponly_scan_urls faz_httponly_scan_lock \
      faz_cookie_missed_scans faz_cookies_recycle_bin >/dev/null 2>&1
    wp db query "DELETE FROM wp_options WHERE option_name LIKE '_transient_faz_%' OR option_name LIKE '_transient_timeout_faz_%' OR option_name LIKE '_site_transient_faz_%' OR option_name LIKE '_site_transient_timeout_faz_%' OR option_name LIKE 'faz_boosted_css_%';" >/dev/null 2>&1
    wp cache flush >/dev/null 2>&1 )
}

SUMMARY="$OUT/summary.txt"
[ "${START_BATCH:-1}" = "1" ] && : > "$SUMMARY"

START_BATCH="${START_BATCH:-1}"
i=$(( (START_BATCH-1) * BATCH_SIZE ))
batch=$((START_BATCH-1))
overall_rc=0
while [ "$i" -lt "$TOTAL" ]; do
  batch=$((batch+1))
  slice=("${SPECS[@]:$i:$BATCH_SIZE}")
  log="$OUT/batch-$(printf '%02d' $batch).log"

  rm -f "$OUT/batch-$(printf '%02d' "$batch").json"
  reset_site || exit 2
  PLAYWRIGHT_JSON_OUTPUT_NAME="$OUT/batch-$(printf '%02d' "$batch").json" \
  CI=1 WP_BASE_URL=http://127.0.0.1:9998 WP_ADMIN_USER=admin WP_ADMIN_PASS=admin \
    WP_PATH="$WP" \
    FAZ_PLUGIN_DEPLOY_PATH="$WP/wp-content/plugins/faz-cookie-manager/" \
    npx playwright test -c tests/e2e/playwright.config.ts "${slice[@]}" \
    --workers="$E2E_WORKERS" --retries=0 --reporter=line,json > "$log" 2>&1
  rc=$?
	[ "$rc" -eq 0 ] || overall_rc=1

  # The line reporter's tail carries the counts. Removing ESC alone leaves CSI
  # fragments such as "[1A[2K" in front of the count, so strip those fragments
  # too before applying the anchored summary expression.
  tail=$(tr -cd '\11\12\15\40-\176' < "$log" | sed -E 's/\[[0-9;?]*[A-Za-z]//g' | grep -E '^[[:space:]]*[0-9]+ (passed|failed|flaky|skipped|did not run)' | tr '\n' ' ')
  printf 'batch %02d (%2d spec) rc=%s | %s\n' "$batch" "${#slice[@]}" "$rc" "${tail:-no summary}" >> "$SUMMARY"
  printf 'batch %02d rc=%s | %s\n' "$batch" "$rc" "${tail:-no summary}"

  i=$((i+BATCH_SIZE))
done

echo "--- TOTALE ---"
cat "$SUMMARY"
python3 "$REPO/scripts/summarize-e2e.py" "$OUT" "$batch" || overall_rc=1
exit "$overall_rc"
