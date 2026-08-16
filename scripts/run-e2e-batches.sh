#!/bin/bash
# Run the E2E suite in batches, resetting the site between each.
#
# The whole suite does not complete in one pass here — four attempts, four
# system kills — and a killed run leaves the site dirty, so the NEXT run fails
# on drifted prerequisites and the reds move around. Batching gives each group
# a clean start, which is what makes the numbers comparable at all.
set -u

# Resolve the repo from this script's own location so the runner is portable.
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP="${WP_PATH:-/Users/fabio/Sites/faz-test}"
OUT="${E2E_BATCH_OUT:-/private/tmp/faz-e2e-batch}"
BATCH_SIZE="${BATCH_SIZE:-12}"
mkdir -p "$OUT"

cd "$REPO" || exit 1
# macOS ships bash 3.2, which has no mapfile; fill the array portably.
SPECS=()
while IFS= read -r line; do SPECS+=("$line"); done < <(find tests/e2e/specs -maxdepth 1 -type f -name '*.spec.ts' -print | LC_ALL=C sort)
TOTAL=${#SPECS[@]}

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
while [ "$i" -lt "$TOTAL" ]; do
  batch=$((batch+1))
  slice=("${SPECS[@]:$i:$BATCH_SIZE}")
  log="$OUT/batch-$(printf '%02d' $batch).log"

  reset_site
  CI=1 WP_BASE_URL=http://127.0.0.1:9998 WP_ADMIN_USER=admin WP_ADMIN_PASS=admin \
    WP_PATH="$WP" \
    FAZ_PLUGIN_DEPLOY_PATH="$WP/wp-content/plugins/faz-cookie-manager/" \
    npx playwright test -c tests/e2e/playwright.config.ts "${slice[@]}" \
    --reporter=line > "$log" 2>&1
  rc=$?

  # The line reporter's tail carries the counts; strip control bytes first.
  tail=$(tr -cd '\11\12\15\40-\176' < "$log" | grep -E '^[[:space:]]*[0-9]+ (passed|failed|flaky|skipped|did not run)' | tr '\n' ' ')
  printf 'batch %02d (%2d spec) rc=%s | %s\n' "$batch" "${#slice[@]}" "$rc" "${tail:-no summary}" >> "$SUMMARY"
  printf 'batch %02d rc=%s | %s\n' "$batch" "$rc" "${tail:-no summary}"

  i=$((i+BATCH_SIZE))
done

echo "--- TOTALE ---"
cat "$SUMMARY"
