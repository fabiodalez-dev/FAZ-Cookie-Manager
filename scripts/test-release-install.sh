#!/usr/bin/env bash
# Verify a release ZIP on a disposable database, never uninstall on the reference site.
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP="${1:?usage: WP_PATH=reference scripts/test-release-install.sh package.zip previous-version}"
PREVIOUS="${2:?previous published version is required}"
SOURCE_WP="${WP_PATH:?WP_PATH must identify the reference WordPress}"
[[ -f "$SOURCE_WP/wp-config.php" && -f "$ZIP" ]] || exit 2
ZIP="$(cd "$(dirname "$ZIP")" && pwd)/$(basename "$ZIP")"
RUN_DIR="$(mktemp -d /private/tmp/faz-install-gate.XXXXXX)"
WP_DIR="$RUN_DIR/wordpress"
DB_NAME="faz_install_gate_$(date +%s)_${RANDOM}"
PORT="${FAZ_INSTALL_PORT:-10002}"
URL="http://127.0.0.1:${PORT}"
SERVER_PID=""
cleanup() {
    if [[ -n "$SERVER_PID" ]]; then
        kill "$SERVER_PID" >/dev/null 2>&1 || true
        wait "$SERVER_PID" >/dev/null 2>&1 || true
    fi
    wp --path="$SOURCE_WP" db query "DROP DATABASE IF EXISTS \`${DB_NAME}\`" >/dev/null
    case "$RUN_DIR" in /private/tmp/faz-install-gate.*) rm -rf -- "$RUN_DIR" ;; esac
}
trap cleanup EXIT
if curl -fsS --max-time 1 "$URL/" >/dev/null 2>&1; then
    echo "Port $PORT is already serving a site; choose FAZ_INSTALL_PORT" >&2
    exit 2
fi
echo "Commit: $(git -C "$REPO" rev-parse HEAD)"
shasum -a 256 "$ZIP"
mkdir -p "$WP_DIR/wp-content/themes"
rsync -a --exclude=wp-content "$SOURCE_WP/" "$WP_DIR/"
rsync -a "$SOURCE_WP/wp-content/themes/" "$WP_DIR/wp-content/themes/"
wp --path="$SOURCE_WP" db query "CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null
wp --path="$WP_DIR" config set DB_NAME "$DB_NAME" --quiet
for constant in MULTISITE SUBDOMAIN_INSTALL DOMAIN_CURRENT_SITE PATH_CURRENT_SITE SITE_ID_CURRENT_SITE BLOG_ID_CURRENT_SITE WP_HOME WP_SITEURL; do
    wp --path="$WP_DIR" config delete "$constant" --quiet >/dev/null 2>&1 || true
done
wp --path="$WP_DIR" config set WP_DEBUG true --raw --quiet
wp --path="$WP_DIR" config set WP_DEBUG_LOG true --raw --quiet
wp --path="$WP_DIR" config set WP_DEBUG_DISPLAY false --raw --quiet
install_core() {
    wp --path="$WP_DIR" core install --url="$URL" --title='FAZ install gate' --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email --quiet
    wp --path="$WP_DIR" option update permalink_structure '/%postname%/' --quiet
}
install_core
php -S "127.0.0.1:${PORT}" -t "$WP_DIR" "$REPO/tests/e2e/fixtures/multisite-router.php" >"$RUN_DIR/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 40); do
    curl -fsS "$URL/wp-login.php" >/dev/null 2>&1 && break
    sleep 0.25
done
kill -0 "$SERVER_PID"
verify_http() {
    curl -fsS "$URL/" > "$RUN_DIR/front.html"
    grep -q '_fazConfig' "$RUN_DIR/front.html"
    curl -fsS "$URL/wp-json/faz/v1/" > "$RUN_DIR/rest.json"
    if [[ -f "$WP_DIR/wp-content/debug.log" ]] && grep -qi 'Fatal error' "$WP_DIR/wp-content/debug.log"; then
        echo 'Fatal error found in isolated WordPress log' >&2
        exit 1
    fi
    echo 'HTTP: frontend + config and FAZ REST passed; no PHP fatal'
}
# shellcheck disable=SC2016 # PHP variables must reach wp eval literally.
wp --path="$WP_DIR" eval '
global $wpdb;
if ($wpdb->get_var("SHOW TABLES LIKE \"{$wpdb->prefix}faz_%\"") || $wpdb->get_var("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"faz_%\" LIMIT 1")) { throw new Exception("Not a clean install"); }
echo "CLEAN: no FAZ tables or options\n";
'
wp --path="$WP_DIR" plugin install "$ZIP" --activate --quiet
# shellcheck disable=SC2016 # PHP variables must reach wp eval literally.
wp --path="$WP_DIR" eval '
global $wpdb;
$tables = $wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->prefix}faz_%\"");
$categories = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookie_categories");
$banners = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}faz_banners");
if (count($tables)!==5 || $categories!==7 || $banners!==2) { throw new Exception("Fresh-install schema mismatch"); }
echo "INSTALL: ".FAZ_VERSION."; 5 tables, 7 categories, 2 banners\n";
'
VERSION="$(wp --path="$WP_DIR" plugin get faz-cookie-manager --field=version)"
verify_http
echo 'INSTALL PASSED'

# Start the upgrade on a second clean database state, so no new-version
# migration markers can leak into the previous-version installation.
wp --path="$WP_DIR" db reset --yes --quiet
install_core
wp --path="$WP_DIR" plugin install faz-cookie-manager --version="$PREVIOUS" --force --activate --quiet
# shellcheck disable=SC2016 # PHP variables must reach wp eval literally.
wp --path="$WP_DIR" eval '
global $wpdb;
$wpdb->insert($wpdb->prefix."faz_cookies", array("name"=>"faz_upgrade_probe","slug"=>"faz-upgrade-probe","domain"=>"probe.test","category"=>1,"type"=>"HTTP","discovered"=>1));
if (!$wpdb->insert_id) { throw new Exception("Probe insert failed"); }
update_option("faz_audit_keep", array("value"=>"must survive"));
$wpdb->query("UPDATE {$wpdb->prefix}faz_cookie_categories SET sell_personal_data=1, share_personal_data=1, date_modified=\"2026-09-01 00:00:00\" WHERE slug=\"functional\"");
echo "UPGRADE: previous version ".FAZ_VERSION."; probe and intentional option inserted\n";
'
wp --path="$WP_DIR" option list --search='faz_*' --field=option_name > "$RUN_DIR/options-before.txt"
: > "$WP_DIR/wp-content/debug.log"
wp --path="$WP_DIR" plugin install "$ZIP" --force --quiet
# Exercise the real admin_init migration entry point after the file upgrade.
# WP-CLI eval alone does not dispatch admin_init.
curl -fsS -c "$RUN_DIR/admin-cookies.txt" "$URL/wp-login.php" >/dev/null
curl -fsSL -c "$RUN_DIR/admin-cookies.txt" -b "$RUN_DIR/admin-cookies.txt" \
    --data-urlencode 'log=admin' --data-urlencode 'pwd=admin' \
    --data-urlencode 'testcookie=1' --data-urlencode "redirect_to=$URL/wp-admin/" \
    "$URL/wp-login.php" > "$RUN_DIR/admin.html"
grep -q 'id="wpadminbar"' "$RUN_DIR/admin.html"

# shellcheck disable=SC2016 # PHP variables must reach wp eval literally.
wp --path="$WP_DIR" eval '
global $wpdb;
$probe = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookies WHERE slug=\"faz-upgrade-probe\"");
$cats = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookie_categories");
$banners = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}faz_banners");
if ($probe!==1 || $cats!==7 || $banners!==2 || get_option("faz_audit_keep")!==array("value"=>"must survive")) { throw new Exception("Upgrade lost data"); }
$row = $wpdb->get_row("SELECT sell_personal_data, share_personal_data FROM {$wpdb->prefix}faz_cookie_categories WHERE slug=\"functional\"");
if ((int)$row->sell_personal_data || (int)$row->share_personal_data || !get_option("faz_normalize_legacy_functional_optout_done") || !get_option("faz_functional_optout_notice")) { throw new Exception("Migration or notice missing"); }
$wpdb->query("UPDATE {$wpdb->prefix}faz_cookie_categories SET sell_personal_data=1, share_personal_data=1 WHERE slug=\"functional\"");
\FazCookie\Includes\Activator::normalize_legacy_functional_optout_flags();
if ((int)$wpdb->get_var("SELECT sell_personal_data FROM {$wpdb->prefix}faz_cookie_categories WHERE slug=\"functional\"")!==1) { throw new Exception("Migration ran twice"); }
echo "UPGRADE: ".FAZ_VERSION."; probe, categories, banners and custom option preserved; migration + notice + one-shot passed\n";
'
[[ "$(wp --path="$WP_DIR" plugin get faz-cookie-manager --field=version)" == "$VERSION" ]]
wp --path="$WP_DIR" option list --search='faz_*' --field=option_name > "$RUN_DIR/options-after.txt"
python3 - "$RUN_DIR/options-before.txt" "$RUN_DIR/options-after.txt" <<'PYOPTIONS'
import sys
from pathlib import Path
before, after = (set(Path(p).read_text().splitlines()) for p in sys.argv[1:])
assert before <= after, f"Upgrade removed options: {sorted(before - after)}"
print('UPGRADE: all previous FAZ option names retained')
PYOPTIONS
verify_http
echo 'UPGRADE PASSED; reference database was not changed by this isolated gate'
