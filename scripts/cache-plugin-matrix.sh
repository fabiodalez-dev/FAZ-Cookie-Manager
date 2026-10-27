#!/usr/bin/env bash
# shellcheck disable=SC2016,SC2317,SC2329
# SC2016: the single-quoted strings are PHP handed to `wp eval`; their `$` must
# NOT expand in the shell. SC2317/SC2329 (one check, renamed between ShellCheck
# releases): restore_site() is invoked through the EXIT trap.
#
# cache-plugin-matrix.sh — run tests/e2e/specs/cache-plugin-matrix.spec.ts
# against real cache / optimisation plugins, one at a time, on the local
# test site.
#
# Usage:
#   bash scripts/cache-plugin-matrix.sh                 # every plugin below
#   bash scripts/cache-plugin-matrix.sh surge w3-total-cache
#
# Each plugin is activated and configured with its page cache AND its
# JS/CSS/HTML optimisations on (the worst case for a consent banner), the spec
# runs, then the plugin is deactivated and its drop-ins, cache directories and
# WP_CACHE are removed, so the site is handed back as it was found. Every
# plugin must already be installed (wp plugin install <slug>).
#
# Results: tests/e2e/reports/cache-matrix/<slug>.json (Playwright JSON), the
# HTML each verdict was read from (<slug>-*.html) and a one-line verdict per
# plugin on stdout. Exit code is non-zero when any plugin had a failing test.
#
# comet-cache has a recipe but is not in the default list: its last release
# (2017) never stores a page on the PHP 8.4 / WordPress 7.0 test site, so it
# fails every cache test for a reason that is not this plugin's. Name it
# explicitly to try again.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 2

WP_PATH="${WP_PATH:-/Users/fabio/Sites/faz-test}"
export WP_PATH
export WP_BASE_URL="${WP_BASE_URL:-http://127.0.0.1:9998}"
export WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
export WP_ADMIN_PASS="${WP_ADMIN_PASS:-admin}"
export FAZ_PLUGIN_DEPLOY_PATH="${FAZ_PLUGIN_DEPLOY_PATH:-$WP_PATH/wp-content/plugins/faz-cookie-manager/}"
WPC="$WP_PATH/wp-content"
REPORT_DIR="tests/e2e/reports/cache-matrix"
mkdir -p "$REPORT_DIR"
export FAZ_CACHE_DUMP_DIR="$PWD/$REPORT_DIR"

ALL_PLUGINS=(
  wp-super-cache
  w3-total-cache
  wp-fastest-cache
  cache-enabler
  hummingbird-performance
  breeze
  wp-optimize
  cachify
  surge
  jetpack-boost
  wp-cloudflare-page-cache
  flying-press
  litespeed-cache
  autoptimize
  sg-cachepress
)
PLUGINS=("$@")
[ ${#PLUGINS[@]} -eq 0 ] && PLUGINS=("${ALL_PLUGINS[@]}")

wpq() { wp --path="$WP_PATH" --skip-themes "$@" 2>/dev/null; }
wpe() { wp --path="$WP_PATH" --skip-themes eval "$1"; }

# Plugins whose page cache cannot serve on this host (optimisation-only run).
PAGECACHE=1
NATIVE_PURGE=''

set_wp_cache() {
  if [ "$1" = "true" ]; then
    wpq config set WP_CACHE true --raw --type=constant >/dev/null
  else
    wpq config delete WP_CACHE >/dev/null 2>&1 || true
  fi
}

configure_plugin() {
  PAGECACHE=1
  NATIVE_PURGE=''
  case "$1" in
    wp-super-cache)
      set_wp_cache true
      wpe '
        global $wp_cache_config_file, $cache_enabled, $super_cache_enabled, $wp_cache_mod_rewrite, $cache_path, $wp_cache_not_logged_in, $wp_cache_make_known_anon;
        include_once WP_PLUGIN_DIR . "/wp-super-cache/wp-cache.php";
        wp_cache_setting( "wp_cache_mod_rewrite", 0 );
        wp_cache_setting( "wp_cache_not_logged_in", 2 );
        wp_cache_setting( "wp_cache_make_known_anon", 0 );
        wp_cache_setting( "cache_enabled", true );
        wp_cache_setting( "super_cache_enabled", true );
        wp_cache_setting( "wp_cache_mutex_disabled", 1 );
        wp_cache_enable();
        wp_super_cache_enable();
        if ( function_exists( "wpsc_create_advanced_cache" ) ) { wpsc_create_advanced_cache(); }
        echo "WPSC configured\n";
      '
      NATIVE_PURGE='if ( function_exists( "wp_cache_clear_cache" ) ) { wp_cache_clear_cache(); }'
      ;;
    w3-total-cache)
      set_wp_cache true
      wpq w3-total-cache option set pgcache.enabled true --type=boolean >/dev/null
      wpq w3-total-cache option set pgcache.engine file >/dev/null
      wpq w3-total-cache option set pgcache.cache.home true --type=boolean >/dev/null
      wpq w3-total-cache option set minify.enabled true --type=boolean >/dev/null
      wpq w3-total-cache option set minify.auto true --type=boolean >/dev/null
      wpq w3-total-cache option set minify.engine file >/dev/null
      # HTML minify off on purpose: it strips every comment, including the
      # footer the HIT detection reads. The HTML-minify dimension is covered
      # by Breeze, WP Fastest Cache, Cache Enabler, Cachify and Autoptimize.
      wpq w3-total-cache option set minify.html.enable false --type=boolean >/dev/null
      wpq w3-total-cache option set pgcache.debug false --type=boolean >/dev/null
      wpq w3-total-cache option set minify.js.enable true --type=boolean >/dev/null
      wpq w3-total-cache option set minify.css.enable true --type=boolean >/dev/null
      wpq w3-total-cache fix_environment >/dev/null 2>&1 || true
      wpq w3-total-cache flush all >/dev/null 2>&1 || true
      NATIVE_PURGE='if ( function_exists( "w3tc_flush_all" ) ) { w3tc_flush_all(); }'
      ;;
    wp-fastest-cache)
      wpq option update WpFastestCache '{"wpFastestCacheStatus":"on","wpFastestCacheLoggedInUser":"","wpFastestCacheMinifyHtml":"on","wpFastestCacheMinifyCss":"on","wpFastestCacheCombineCss":"on","wpFastestCacheCombineJs":"on","wpFastestCacheGzip":"on","wpFastestCacheLBC":"on","wpFastestCacheLanguage":"eng","wpFastestCacheNewPost":"on","wpFastestCacheUpdatePost":"on","wpFastestCacheLazyLoad":"","wpFastestCacheRenderBlocking":"on","wpFastestCacheRenderBlockingCss":"on"}' >/dev/null
      NATIVE_PURGE='if ( function_exists( "wpfc_clear_all_cache" ) ) { wpfc_clear_all_cache( true ); }'
      ;;
    cache-enabler)
      set_wp_cache true
      wpe '
        $o = (array) get_option( "cache_enabler", array() );
        $o = array_merge( $o, array( "minify_html" => 1, "minify_inline_css_js" => 1, "cache_expires" => 0, "excluded_cookies" => "", "clear_site_cache_on_saved_post" => 0 ) );
        update_option( "cache_enabler", $o );
        // The engine only starts when its per-site settings file exists; the
        // activation hook writes it on a normal request, not under WP-CLI.
        if ( class_exists( "Cache_Enabler_Disk" ) ) { Cache_Enabler_Disk::setup(); }
        if ( class_exists( "Cache_Enabler" ) ) { Cache_Enabler::update_backend(); }
        $dir = WP_CONTENT_DIR . "/settings/cache-enabler";
        echo "Cache Enabler configured; settings file: " . ( is_dir( $dir ) && count( glob( $dir . "/*.php" ) ) ? "present" : "MISSING" ) . "\n";
      '
      NATIVE_PURGE='if ( class_exists( "Cache_Enabler" ) ) { Cache_Enabler::clear_complete_cache(); }'
      ;;
    hummingbird-performance)
      set_wp_cache true
      wpe '
        $m = \Hummingbird\Core\Utils::get_module( "page_cache" );
        $m->enable();
        $s = \Hummingbird\Core\Settings::get_settings( "page_cache" );
        \Hummingbird\Core\Settings::update_setting( "enabled", true, "page_cache" );
        echo "Hummingbird page cache enabled: " . ( $m->is_active() ? "yes" : "no" ) . "\n";
      '
      NATIVE_PURGE='$m = \Hummingbird\Core\Utils::get_module( "page_cache" ); if ( $m ) { $m->clear_cache(); }'
      ;;
    breeze)
      set_wp_cache true
      wpe '
        $basic = (array) get_option( "breeze_basic_settings", array() );
        $basic = array_merge( $basic, array( "breeze-active" => "1", "breeze-ttl" => 1440, "breeze-minify-html" => "1", "breeze-minify-css" => "1", "breeze-minify-js" => "1", "breeze-gzip-compression" => "1", "breeze-browser-cache" => "1", "breeze-include-inline-js" => "1", "breeze-include-inline-css" => "1" ) );
        update_option( "breeze_basic_settings", $basic );
        $adv = (array) get_option( "breeze_advanced_settings", array() );
        $adv = array_merge( $adv, array( "breeze-defer-js" => array(), "breeze-enable-js-delay" => "1", "breeze-delay-all-js" => "1" ) );
        update_option( "breeze_advanced_settings", $adv );
        $file = (array) get_option( "breeze_file_settings", array() );
        $file = array_merge( $file, array( "breeze-minify-html" => "1", "breeze-minify-css" => "1", "breeze-minify-js" => "1", "breeze-group-css" => "1", "breeze-group-js" => "1", "breeze-include-inline-js" => "1", "breeze-include-inline-css" => "1", "breeze-enable-js-delay" => "1", "breeze-delay-all-js" => "1", "breeze-defer-js" => array(), "breeze-move-to-footer-js" => array(), "breeze-delay-js-scripts" => array(), "no-breeze-no-delay-js" => array() ) );
        update_option( "breeze_file_settings", $file );
        if ( class_exists( "Breeze_ConfigCache" ) ) {
          Breeze_ConfigCache::factory()->write();
          Breeze_ConfigCache::factory()->write_config_cache();
          Breeze_ConfigCache::factory()->toggle_caching( true );
        }
        echo "Breeze configured\n";
      '
      NATIVE_PURGE='do_action( "breeze_clear_all_cache" ); if ( class_exists( "Breeze_PurgeCache" ) ) { Breeze_PurgeCache::breeze_cache_flush(); }'
      ;;
    wp-optimize)
      set_wp_cache true
      wpe '
        $pc = WP_Optimize()->get_page_cache();
        $pc->enable( true );
        $cfg = (array) get_option( "wpo_cache_config", array() );
        $cfg["enable_page_caching"] = true;
        $cfg["page_cache_length_value"] = 0;
        update_option( "wpo_cache_config", $cfg );
        if ( method_exists( $pc, "update_cache_config" ) ) { $pc->update_cache_config(); }
        $min = (array) get_option( "wpo_minify_config", array() );
        $min = array_merge( $min, array( "enabled" => true, "enable_js" => true, "enable_css" => true, "enable_js_minification" => true, "enable_css_minification" => true, "enable_merging_of_js" => true, "enable_merging_of_css" => true, "enable_defer" => true, "defer_js_type" => "all", "html_minification" => true, "enable_html_minification" => true ) );
        update_option( "wpo_minify_config", $min );
        echo "WP-Optimize configured: cache=" . ( WPO_Page_Cache::instance()->is_enabled() ? "on" : "off" ) . "\n";
      '
      NATIVE_PURGE='if ( function_exists( "wpo_cache_flush" ) ) { wpo_cache_flush(); }'
      ;;
    comet-cache)
      set_wp_cache true
      wpe '
        $cc = $GLOBALS["comet_cache"];
        $cc->updateOptions( array( "enable" => "1", "htmlc_enable" => "1", "htmlc_compress_combine_head_body_css" => "1", "htmlc_compress_combine_head_js" => "1", "htmlc_compress_combine_footer_js" => "1", "htmlc_compress_inline_js_code" => "1", "htmlc_compress_html_code" => "1", "htmlc_compress_js_code" => "1", "htmlc_compress_css_code" => "1", "htmlc_when_logged_in" => "0" ) );
        $a = $cc->addWpCacheToWpConfig();
        $b = $cc->addAdvancedCache();
        $cc->updateBlogPaths();
        echo "Comet Cache configured: wp_cache=" . var_export( $a, true ) . " advanced_cache=" . var_export( $b, true ) . " enable=" . var_export( $cc->options["enable"] ?? null, true ) . "\n";
      '
      NATIVE_PURGE='$cc = $GLOBALS["comet_cache"]; $cc->wipeCache(); $cc->clearCache();'
      ;;
    cachify)
      wpe '
        $o = (array) get_option( "cachify", array() );
        $o = array_merge( $o, array( "only_guests" => 1, "compress_html" => 2, "cache_expires" => 24, "use_apc" => 0, "without_ids" => "", "without_agents" => "", "sig_detail" => 1 ) );
        update_option( "cachify", $o );
        echo "Cachify configured (DB cache, HTML+inline JS minify)\n";
      '
      NATIVE_PURGE='if ( class_exists( "Cachify" ) ) { Cachify::flush_total_cache( true ); }'
      ;;
    surge)
      set_wp_cache true
      NATIVE_PURGE='$dir = WP_CONTENT_DIR . "/cache/surge"; if ( is_dir( $dir ) ) { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } } if ( function_exists( "Surge\\\\invalidate_cache" ) ) { Surge\invalidate_cache(); }'
      ;;
    jetpack-boost)
      set_wp_cache true
      wpe '
        // Boost stores module status under jetpack_boost_status_<slug-with-dashes> (Lib\Status::get_option_name()).
        foreach ( array( "page-cache", "render-blocking-js", "minify-js", "minify-css" ) as $m ) { update_option( "jetpack_boost_status_" . $m, true ); }
        update_option( "jetpack_boost_ds_page_cache_setup_notice_dismissed", true );
        if ( class_exists( "\\Automattic\\Jetpack_Boost\\Modules\\Optimizations\\Page_Cache\\Pre_WordPress\\Boost_Cache_Settings" ) ) {
          \Automattic\Jetpack_Boost\Modules\Optimizations\Page_Cache\Pre_WordPress\Boost_Cache_Settings::get_instance()->set( array( "logging" => true, "enabled" => true ) );
        }
        if ( class_exists( "\\Automattic\\Jetpack_Boost\\Modules\\Optimizations\\Page_Cache\\Pre_WordPress\\Boost_Cache_Setup" ) ) {
          $r = \Automattic\Jetpack_Boost\Modules\Optimizations\Page_Cache\Pre_WordPress\Boost_Cache_Setup::run_setup();
          echo "Boost setup: " . ( is_wp_error( $r ) ? $r->get_error_message() : "ok" ) . "\n";
        } elseif ( class_exists( "\\Automattic\\Jetpack_Boost\\Modules\\Optimizations\\Page_Cache\\Page_Cache_Setup" ) ) {
          $r = \Automattic\Jetpack_Boost\Modules\Optimizations\Page_Cache\Page_Cache_Setup::run_setup();
          echo "Boost setup: " . ( is_wp_error( $r ) ? $r->get_error_message() : "ok" ) . "\n";
        } else {
          echo "Boost setup class not found\n";
        }
      '
      NATIVE_PURGE='if ( class_exists( "\\Automattic\\Jetpack_Boost\\Modules\\Optimizations\\Page_Cache\\Pre_WordPress\\Boost_Cache_Actions" ) ) { \Automattic\Jetpack_Boost\Modules\Optimizations\Page_Cache\Pre_WordPress\Boost_Cache_Actions::purge_all(); } $dir = WP_CONTENT_DIR . "/boost-cache/cache"; if ( is_dir( $dir ) ) { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } }'
      ;;
    wp-cloudflare-page-cache)
      # No WP_CACHE beforehand: its writer refuses when the constant already exists.
      wpe '
        $store = \SPC\Services\Settings_Store::get_instance();
        $store->set_multiple( array( "cf_cache_enabled" => 1, "cf_fallback_cache" => 1, "cf_fallback_cache_ttl" => 0, "cf_fallback_cache_curl" => 0, "cf_fallback_cache_auto_purge" => 1 ) );
        $store->save();
        $loader = isset( $GLOBALS["sw_cloudflare_pagecache"] ) ? $GLOBALS["sw_cloudflare_pagecache"] : null;
        $modules = ( $loader && method_exists( $loader, "get_modules" ) ) ? $loader->get_modules() : array();
        $fb = isset( $modules["fallback_cache"] ) ? $modules["fallback_cache"] : new \SPC\Modules\Fallback_Cache();
        $fb->fallback_cache_enable();
        $r = $fb->fallback_cache_advanced_cache_enable( false );
        echo "Super Page Cache fallback cache: " . ( $r ? "enabled" : "advanced-cache NOT enabled" ) . "\n";
      '
      NATIVE_PURGE='do_action( "swcfpc_purge_cache" ); $fb = new \SPC\Modules\Fallback_Cache(); if ( method_exists( $fb, "fallback_cache_purge_all" ) ) { $fb->fallback_cache_purge_all(); }'
      ;;
    flying-press)
      NATIVE_PURGE='if ( class_exists( "\\FlyingPress\\Purge" ) ) { \FlyingPress\Purge::purge_everything(); }'
      ;;
    litespeed-cache)
      PAGECACHE=0
      wpq litespeed-option set optm-css_min true >/dev/null 2>&1 || true
      wpq litespeed-option set optm-css_comb true >/dev/null 2>&1 || true
      wpq litespeed-option set optm-js_min true >/dev/null 2>&1 || true
      wpq litespeed-option set optm-js_comb true >/dev/null 2>&1 || true
      wpq litespeed-option set optm-js_defer 2 >/dev/null 2>&1 || true
      wpq litespeed-option set optm-html_min true >/dev/null 2>&1 || true
      NATIVE_PURGE='do_action( "litespeed_purge_all" );'
      ;;
    autoptimize)
      PAGECACHE=0
      for o in autoptimize_js autoptimize_js_aggregate autoptimize_js_include_inline autoptimize_js_defer_inline autoptimize_css autoptimize_css_aggregate autoptimize_css_include_inline autoptimize_html autoptimize_html_keepcomments; do
        wpq option update "$o" on >/dev/null
      done
      NATIVE_PURGE='if ( class_exists( "autoptimizeCache" ) ) { autoptimizeCache::clearall(); }'
      ;;
    sg-cachepress)
      PAGECACHE=0
      for o in siteground_optimizer_optimize_html siteground_optimizer_optimize_javascript siteground_optimizer_optimize_javascript_async siteground_optimizer_combine_javascript siteground_optimizer_optimize_css siteground_optimizer_combine_css siteground_optimizer_remove_query_strings; do
        wpq option update "$o" 1 >/dev/null
      done
      NATIVE_PURGE='if ( function_exists( "sg_cachepress_purge_cache" ) ) { sg_cachepress_purge_cache(); }'
      ;;
    *)
      echo "no configuration recipe for $1" >&2
      ;;
  esac
}

cleanup_plugin() {
  local active_plugins active_slug active_status
  wpq plugin deactivate "$1" >/dev/null 2>&1 || wp --path="$WP_PATH" --skip-plugins --skip-themes plugin deactivate "$1" >/dev/null 2>&1 || true
  # Do not load a possibly broken plugin just to confirm its removal. A failed
  # status query is not proof of inactivity, so stop before deleting its files
  # or activating another cache plugin. The EXIT trap still restores the site.
  if ! active_plugins=$(wp --path="$WP_PATH" --skip-plugins --skip-themes plugin list --fields=name,status --format=csv); then
    echo "!! could not verify deactivation of $1 — stopping the matrix" >&2
    return 1
  fi
  while IFS=, read -r active_slug active_status; do
    if [ "$active_slug" = "$1" ] && { [ "$active_status" = 'active' ] || [ "$active_status" = 'active-network' ]; }; then
      echo "!! $1 is still active after cleanup — stopping the matrix" >&2
      return 1
    fi
  done <<< "$active_plugins"
  rm -f "$WPC/advanced-cache.php" "$WPC/object-cache.php"
  rm -rf "$WPC/cache/surge" "$WPC/cache/cache-enabler" "$WPC/cache/wp-fastest-cache" "$WPC/cache/all" "$WPC/cache/wpfc-mobile-cache" \
    "$WPC/cache/breeze"* "$WPC/cache/page_enhanced" "$WPC/cache/minify" "$WPC/cache/supercache" "$WPC/cache/blogs" \
    "$WPC/cache/wphb-cache" "$WPC/cache/comet-cache" "$WPC/cache/autoptimize" "$WPC/cache/hummingbird" "$WPC/cache/db" \
    "$WPC/wpo-cache" "$WPC/boost-cache" "$WPC/cache/wp-cloudflare-super-page-cache" "$WPC/wp-cloudflare-super-page-cache" \
    "$WPC/cache/wp-optimize" "$WPC/cache/object" "$WPC/cache/tmp" "$WPC/cache/wpo-minify"
  rm -f "$WPC/wp-cache-config.php.bak"
  rm -rf "$WPC/w3tc-config" "$WPC/cache/page" "$WPC/cache/log"
  set_wp_cache false
  wpq cache flush >/dev/null 2>&1 || true
}

# Keep configuration, not cached pages. Restoring only active_plugins and the
# drop-ins lost W3TC's w3tc-config directory on EVERY cleanup, even on an
# Autoptimize-only run, and left each recipe's option changes behind.
options_helper="$PWD/scripts/cache-plugin-matrix-options.php"
wp_config_path=$(wp --path="$WP_PATH" --skip-plugins --skip-themes config path) || exit 2
snapshot_dir=$(mktemp -d) || exit 2
state_paths=(
  "$wp_config_path"
  "$WP_PATH/.htaccess"
  "$WPC/.htaccess"
  "$WPC/cache/.htaccess"
  "$WPC/w3tc-config"
  "$WPC/wp-cache-config.php"
  "$WPC/wp-cache-config.php.bak"
  "$WPC/settings/cache-enabler"
  "$WPC/wpo-cache/config"
  "$WPC/breeze-config"
  "$WPC/wp-cloudflare-super-page-cache"
  "$WPC/boost-cache/config.php"
)
for i in "${!state_paths[@]}"; do
  path="${state_paths[$i]}"
  if [ -e "$path" ] || [ -L "$path" ]; then
    if ! cp -pPR "$path" "$snapshot_dir/state-$i"; then
      rm -rf "$snapshot_dir"
      exit 2
    fi
  fi
done
for dropin in advanced-cache.php object-cache.php; do
  if [ -e "$WPC/$dropin" ] || [ -L "$WPC/$dropin" ]; then
    if ! cp -pPR "$WPC/$dropin" "$snapshot_dir/$dropin"; then
      rm -rf "$snapshot_dir"
      exit 2
    fi
  fi
done
if ! wp --path="$WP_PATH" --skip-plugins --skip-themes eval-file "$options_helper" snapshot "$snapshot_dir/options"; then
  rm -rf "$snapshot_dir"
  exit 2
fi

restore_site() {
  local run_status=$?
  local restore_failed=0
  local options_restored=0
  trap - EXIT
  echo "--- restoring site state"
  # Do not bootstrap a cache drop-in against the matrix's temporary options.
  rm -f "$WPC/advanced-cache.php" "$WPC/object-cache.php"
  for i in "${!state_paths[@]}"; do
    path="${state_paths[$i]}"
    if ! rm -rf "$path"; then
      echo "!! could not remove $path" >&2
      restore_failed=1
      continue
    fi
    if [ -e "$snapshot_dir/state-$i" ] || [ -L "$snapshot_dir/state-$i" ]; then
      if ! mkdir -p "$(dirname "$path")" || ! cp -pPR "$snapshot_dir/state-$i" "$path"; then
        echo "!! could not restore $path" >&2
        restore_failed=1
      fi
    fi
  done
  if ! wp --path="$WP_PATH" --skip-plugins --skip-themes eval-file "$options_helper" restore "$snapshot_dir/options"; then
    echo "!! option restore failed" >&2
    restore_failed=1
  else
    options_restored=1
  fi
  if [ "$options_restored" -eq 1 ]; then
    wp --path="$WP_PATH" --skip-plugins --skip-themes option delete faz_banner_template >/dev/null 2>&1 || true
    for dropin in advanced-cache.php object-cache.php; do
      if [ -e "$snapshot_dir/$dropin" ] || [ -L "$snapshot_dir/$dropin" ]; then
        if ! cp -pPR "$snapshot_dir/$dropin" "$WPC/$dropin"; then
          echo "!! drop-in restore failed: $dropin" >&2
          restore_failed=1
        fi
      fi
    done
    # Flush through the original persistent backend after restoring it.
    if ! wp --path="$WP_PATH" --skip-plugins --skip-themes cache flush >/dev/null; then
      echo "!! cache flush failed" >&2
      restore_failed=1
    fi
  else
    echo "!! drop-in restore skipped because option restore failed" >&2
  fi
  if [ "$restore_failed" -ne 0 ]; then
    echo "!! restore incomplete; snapshot retained at $snapshot_dir" >&2
    exit 1
  fi
  rm -rf "$snapshot_dir"
  exit "$run_status"
}
trap restore_site EXIT

# Baseline: only FAZ active, plugin defaults that make a normal page cacheable
# (no jurisdiction routing, no cache-compatibility mode, hide_from_bots on).
echo "--- baseline: FAZ only, default cacheable settings"
wpe '
  update_option( "active_plugins", array( "faz-cookie-manager/faz-cookie-manager.php" ) );
  $s = get_option( "faz_settings", array() );
  if ( ! is_array( $s ) ) { $s = array(); }
  $s["geolocation"]["geo_targeting"] = false;
  $s["banner_control"]["cache_compatibility"] = false;
  $s["banner_control"]["hide_from_bots"] = true;
  $s["banner_control"]["status"] = true;
  $s["iab"]["enabled"] = false;
  update_option( "faz_settings", $s );
  delete_option( "faz_banner_template" );
' >/dev/null
rm -f "$WPC/advanced-cache.php"
set_wp_cache false
# The fixture page the spec judges; the spec upserts it by slug as well, this
# only guarantees the baseline probe below does not read a 404 on a clean site.
wpe '
  if ( ! get_page_by_path( "faz-cache-matrix", OBJECT, "page" ) ) {
    wp_insert_post( array( "post_type" => "page", "post_status" => "publish", "post_name" => "faz-cache-matrix", "post_title" => "FAZ Cache Matrix", "post_content" => "<p>FAZ cache matrix fixture.</p>" ) );
  }
' >/dev/null
baseline_status=$(curl -s -o /dev/null -w "%{http_code}" -A "Mozilla/5.0 matrix" "$WP_BASE_URL/faz-cache-matrix/")
if [ "$baseline_status" != "200" ]; then
  echo "!! the fixture page answers HTTP $baseline_status with only FAZ active. Aborting." >&2
  exit 3
fi
baseline_cc=$(curl -s -D - -o /dev/null -A "Mozilla/5.0 matrix" "$WP_BASE_URL/faz-cache-matrix/" | grep -i "^cache-control" || true)
echo "baseline Cache-Control on the fixture page: ${baseline_cc:-(none — cacheable)}"
if echo "$baseline_cc" | grep -qi "no-store"; then
  echo "!! baseline page is already no-store with only FAZ active — nothing below can measure a cache. Aborting." >&2
  exit 3
fi

summary=()
rc=0
for slug in "${PLUGINS[@]}"; do
  echo
  echo "=================== $slug ==================="
  if ! wpq plugin is-installed "$slug"; then
    echo "not installed — skipped"
    summary+=("$slug: NOT INSTALLED")
    continue
  fi
  if ! wpq plugin activate "$slug" >/dev/null 2>&1; then
    echo "activation failed"
    summary+=("$slug: ACTIVATION FAILED")
    cleanup_plugin "$slug" || exit 1
    continue
  fi
  configure_plugin "$slug"
  wpq cache flush >/dev/null 2>&1 || true
  health=$(curl -s -o /dev/null -w "%{http_code}" -A "Mozilla/5.0 matrix" "$WP_BASE_URL/faz-cache-matrix/")
  if [ "$health" != "200" ]; then
    echo "!! site answers $health with $slug configured — not running the spec"
    summary+=("$slug: ERROR — site answers HTTP $health after configuration")
    rc=1
    cleanup_plugin "$slug" || exit 1
    continue
  fi
  echo "WP_CACHE=$(wpq config get WP_CACHE 2>/dev/null || echo unset) advanced-cache.php=$([ -f "$WPC/advanced-cache.php" ] && echo present || echo absent)"
  curl -s -D - -o /dev/null -A "Mozilla/5.0 matrix" "$WP_BASE_URL/faz-cache-matrix/" | grep -i -E "^(cache-control|x-cache|x-jetpack|x-flying|x-wp-cf|x-litespeed|x-powered)" | sed 's/^/  hdr: /'

  FAZ_E2E_SKIP_PREFLIGHT=1 FAZ_CACHE_PLUGIN="$slug" FAZ_CACHE_PAGECACHE="$PAGECACHE" FAZ_CACHE_NATIVE_PURGE_PHP="$NATIVE_PURGE" \
    PLAYWRIGHT_JSON_OUTPUT_NAME="$PWD/$REPORT_DIR/$slug.json" \
    npx playwright test -c tests/e2e/playwright.config.ts tests/e2e/specs/cache-plugin-matrix.spec.ts --workers=1 --retries=0 \
      --reporter=list,json 2>&1 | tee "$REPORT_DIR/$slug.log" | grep -E "^\s+(✓|✘|-|×)|passed|failed|skipped|Error:" | awk 'NR <= 40'
  if grep -qE "[0-9]+ failed" "$REPORT_DIR/$slug.log"; then
    verdict="FAIL $(grep -oE '[0-9]+ failed' "$REPORT_DIR/$slug.log" | head -1), $(grep -oE '[0-9]+ passed' "$REPORT_DIR/$slug.log" | head -1)"
    rc=1
  elif ! grep -qE "[0-9]+ passed" "$REPORT_DIR/$slug.log"; then
    verdict="ERROR — the run did not complete (see $REPORT_DIR/$slug.log)"
    rc=1
  else
    verdict="ok $(grep -oE '[0-9]+ passed' "$REPORT_DIR/$slug.log" | head -1) $(grep -oE '[0-9]+ skipped' "$REPORT_DIR/$slug.log" | head -1)"
  fi
  summary+=("$slug: $verdict")
  if [ "$slug" = "jetpack-boost" ] && ls "$WPC"/boost-cache/logs/*.log >/dev/null 2>&1; then
    echo "  boost log:"; tail -12 "$WPC"/boost-cache/logs/*.log | cut -c1-200 | sed 's/^/    /'
  fi
  cleanup_plugin "$slug" || exit 1
done

echo
echo "=================== summary ==================="
printf '%s\n' "${summary[@]}"
exit $rc
