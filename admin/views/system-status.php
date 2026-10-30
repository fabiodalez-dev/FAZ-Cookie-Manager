<?php
/**
 * FAZ Cookie Manager — System Status view
 *
 * Displays environment info, plugin configuration, database stats,
 * cron jobs, and active plugins for diagnostic / support purposes.
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$settings     = get_option( 'faz_settings' );
$gcm_settings = get_option( 'faz_gcm_settings' );
$active_plugins = get_option( 'active_plugins', array() );
$theme = wp_get_theme();

// DB table sizes — cached for 2 minutes to avoid 10 queries per page load.
$table_info = get_transient( 'faz_system_status_tables' );
if ( false === $table_info ) {
	$tables     = array( 'faz_banners', 'faz_cookies', 'faz_cookie_categories', 'faz_consent_logs', 'faz_pageviews' );
	$table_info = array();
	foreach ( $tables as $t ) {
		$full   = $wpdb->prefix . $t;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- system-status table existence probe; bound via prepare(%s). Result cached at the function level via the transient set after this loop.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $full is $wpdb->prefix + an allowlisted plugin-table suffix from the foreach above. Result transient-cached after the loop.
			$row = $wpdb->get_row( "SELECT COUNT(*) as cnt FROM {$full}" );
			$table_info[ $t ] = $row ? absint( $row->cnt ) : 0;
		} else {
			$table_info[ $t ] = -1; // table missing
		}
	}
	set_transient( 'faz_system_status_tables', $table_info, 2 * MINUTE_IN_SECONDS );
}

// Cron status.
$next_scan    = wp_next_scheduled( 'faz_scheduled_scan' );
$next_cleanup = wp_next_scheduled( 'faz_daily_cleanup' );
$blocked_server_cookies = get_transient( 'faz_recent_blocked_server_cookies' );
$blocked_server_cookies = is_array( $blocked_server_cookies ) ? array_reverse( $blocked_server_cookies ) : array();
?>
<div id="faz-system-status">

	<div style="margin-bottom:12px;">
		<button class="faz-btn faz-btn-outline" id="faz-copy-status" type="button">
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
			<?php esc_html_e( 'Copy Status to Clipboard', 'faz-cookie-manager' ); ?>
		</button>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Environment', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<table class="faz-status-table">
				<tr><td><?php esc_html_e( 'Plugin Version', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( FAZ_VERSION ); ?></code></td></tr>
				<?php
				// The version the frontend runtime bundle was built for, read from
				// the file the frontend actually serves (script.min.js unless
				// SCRIPT_DEBUG). FAZ_VERSION comes from the main plugin file, so the
				// two differ only when the plugin directory mixes two releases — an
				// interrupted update, a partial deployment or staging sync — or when
				// a second, older copy of the plugin defines FAZ_VERSION first. That
				// install prints `?ver=<old>` on a newer bundle and fails in ways
				// that look like anything but a version problem (wp.org thread
				// "WooCommerce Express Checkout 3rd party buttons do not appear":
				// `script.min.js?ver=1.28.1` printing a 1.34 message). Read only on
				// this page.
				$faz_bundle_file    = FAZ_PLUGIN_BASEPATH . 'frontend/js/script' . faz_asset_suffix( 'frontend/js/script' ) . '.js';
				$faz_bundle_version = '';
				if ( is_readable( $faz_bundle_file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, read-only diagnostic.
					$faz_bundle_source = (string) file_get_contents( $faz_bundle_file );
					if ( preg_match( '/_FAZ_BUILD\s*=\s*["\']([0-9]+(?:\.[0-9]+)+(?:-(?:rc|beta|alpha)\.?[0-9]+)?)\+/', $faz_bundle_source, $faz_bundle_match )
						|| preg_match( '/["\']([0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:-(?:rc|beta|alpha)\.?[0-9]+)?)\+[A-Za-z0-9._-]+["\']/', $faz_bundle_source, $faz_bundle_match ) ) {
						$faz_bundle_version = $faz_bundle_match[1];
					}
					unset( $faz_bundle_source );
				}
				// Every installed copy of the plugin, active or not: two folders
				// carrying the same plugin are how an "updated" site keeps loading
				// the old main file.
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$faz_copies = array();
				foreach ( (array) get_plugins() as $faz_plugin_file => $faz_plugin_data ) {
					if ( isset( $faz_plugin_data['Name'] ) && 'FAZ Cookie Manager' === $faz_plugin_data['Name'] ) {
						$faz_copies[ $faz_plugin_file ] = isset( $faz_plugin_data['Version'] ) ? (string) $faz_plugin_data['Version'] : '';
					}
				}
				$faz_bundle_skew = '' !== $faz_bundle_version && FAZ_VERSION !== $faz_bundle_version;
				?>
				<tr>
					<td><?php esc_html_e( 'Runtime Bundle', 'faz-cookie-manager' ); ?></td>
					<td>
						<code><?php echo esc_html( '' !== $faz_bundle_version ? $faz_bundle_version : __( 'unknown', 'faz-cookie-manager' ) ); ?></code>
						<?php if ( $faz_bundle_skew ) : ?>
							&#10060; <?php esc_html_e( 'differs from the plugin version', 'faz-cookie-manager' ); ?>
							<div class="faz-help">
								<?php
								printf(
									/* translators: 1: version of the frontend runtime bundle, 2: plugin version from the main plugin file. */
									esc_html__( 'The frontend runtime is %1$s but the plugin reports %2$s: the plugin directory mixes files from two versions (an interrupted update, a partial deployment or staging sync, or a second copy of the plugin). Reinstall the plugin through Plugins → Add New → Upload Plugin or the updater, then purge every page, CDN and optimiser cache.', 'faz-cookie-manager' ),
									esc_html( $faz_bundle_version ),
									esc_html( FAZ_VERSION )
								);
								?>
							</div>
						<?php elseif ( '' !== $faz_bundle_version ) : ?>
							&#9989; <?php esc_html_e( 'matches the plugin version', 'faz-cookie-manager' ); ?>
						<?php endif; ?>
						<?php if ( count( $faz_copies ) > 1 ) : ?>
							<div class="faz-status-detail">
								&#10060; <?php esc_html_e( 'More than one copy of the plugin is installed.', 'faz-cookie-manager' ); ?>
								<?php
								$faz_copy_lines = array();
								foreach ( $faz_copies as $faz_plugin_file => $faz_plugin_version ) {
									$faz_copy_lines[] = dirname( $faz_plugin_file ) . ( '' !== $faz_plugin_version ? ' (' . $faz_plugin_version . ')' : '' );
								}
								printf(
									/* translators: 1: number of installed copies of the plugin, 2: comma-separated list of plugin folders with their versions. */
									esc_html__( '%1$s copies of FAZ Cookie Manager are installed: %2$s. Keep one: delete the others from Plugins (or wp-content/plugins) so only one main file can load.', 'faz-cookie-manager' ),
									esc_html( number_format_i18n( count( $faz_copies ) ) ),
									esc_html( implode( ', ', $faz_copy_lines ) )
								);
								?>
							</div>
						<?php endif; ?>
					</td>
				</tr>
				<?php
				// The content-hashed files the frontend loads from uploads, and how
				// the server actually serves the most recently used configuration
				// file (mtime is its last-used marker). The console says only "the
				// static configuration file failed to load" and the Network tab
				// shows a 200 from disk cache; what decides it is here: a zero-byte
				// or short copy, a non-JavaScript Content-Type (with nosniff the
				// browser refuses to run it), or an HTML page from a login or
				// staging-protection layer. One loopback request, 5 s at most,
				// remembered for two minutes.
				$faz_assets_dir   = \FazCookie\Includes\Filesystem::get_instance()->get_uploads_dir( 'faz-cookie-manager/assets' );
				$faz_assets_path  = is_array( $faz_assets_dir ) && ! empty( $faz_assets_dir['path'] ) ? (string) $faz_assets_dir['path'] : '';
				$faz_assets_url   = is_array( $faz_assets_dir ) && ! empty( $faz_assets_dir['url'] ) ? (string) $faz_assets_dir['url'] : '';
				$faz_config_files = '' !== $faz_assets_path ? glob( $faz_assets_path . 'config-*.js' ) : array();
				$faz_config_files = is_array( $faz_config_files ) ? $faz_config_files : array();
				$faz_banner_files = '' !== $faz_assets_path ? glob( $faz_assets_path . 'banner-*.css' ) : array();
				$faz_banner_files = is_array( $faz_banner_files ) ? $faz_banner_files : array();
				$faz_empty_assets = 0;
				$faz_latest_file  = '';
				$faz_latest_mtime = 0;
				foreach ( array_merge( $faz_config_files, $faz_banner_files ) as $faz_asset ) {
					if ( 0 === (int) @filesize( $faz_asset ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a vanished file reads as empty, which is what it is to a browser.
						++$faz_empty_assets;
					}
				}
				foreach ( $faz_config_files as $faz_asset ) {
					$faz_mtime = (int) @filemtime( $faz_asset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( $faz_mtime >= $faz_latest_mtime ) {
						$faz_latest_mtime = $faz_mtime;
						$faz_latest_file  = $faz_asset;
					}
				}
				$faz_probe = null;
				if ( '' !== $faz_latest_file && '' !== $faz_assets_url ) {
					$faz_probe_url = $faz_assets_url . basename( $faz_latest_file );
					$faz_probe_key = 'faz_system_status_asset_probe';
					$faz_probe     = get_transient( $faz_probe_key );
					if ( ! is_array( $faz_probe ) || ! isset( $faz_probe['url'], $faz_probe['error'] ) || $faz_probe['url'] !== $faz_probe_url ) {
						$faz_response = wp_remote_get(
							add_query_arg( 'faz-status', (string) time(), $faz_probe_url ),
							array(
								'timeout'     => 5,
								'redirection' => 2,
								// The relaxed local-request verification only for the
								// site's own host: an uploads URL offloaded to a CDN is a
								// remote host and keeps WordPress's default verification.
								'sslverify'   => ( wp_parse_url( $faz_probe_url, PHP_URL_HOST ) === wp_parse_url( home_url( '/' ), PHP_URL_HOST ) )
									? apply_filters( 'https_local_ssl_verify', false )
									: apply_filters( 'https_ssl_verify', true ),
							)
						);
						if ( is_wp_error( $faz_response ) ) {
							$faz_probe = array(
								'url'   => $faz_probe_url,
								'error' => $faz_response->get_error_message(),
							);
						} else {
							$faz_probe_body = (string) wp_remote_retrieve_body( $faz_response );
							$faz_probe      = array(
								'url'     => $faz_probe_url,
								'error'   => '',
								'status'  => (int) wp_remote_retrieve_response_code( $faz_response ),
								'type'    => implode( ', ', (array) wp_remote_retrieve_header( $faz_response, 'content-type' ) ),
								'nosniff' => false !== stripos( implode( ', ', (array) wp_remote_retrieve_header( $faz_response, 'x-content-type-options' ) ), 'nosniff' ),
								'bytes'   => strlen( $faz_probe_body ),
								'disk'    => (int) @filesize( $faz_latest_file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
								'html'    => '<' === substr( ltrim( $faz_probe_body ), 0, 1 ),
								'shape'   => 0 === strpos( ltrim( $faz_probe_body, "\xEF\xBB\xBF \t\r\n" ), 'window._fazStaticConfig=' ),
							);
							unset( $faz_probe_body );
						}
						set_transient( $faz_probe_key, $faz_probe, 2 * MINUTE_IN_SECONDS );
					}
				}
				$faz_probe_issues = array();
				if ( $faz_empty_assets > 0 ) {
					$faz_probe_issues[] = sprintf(
						/* translators: %s: number of generated asset files that are empty on disk. */
						_n( '%s generated file is empty on disk; the next page view rewrites it, then purge any CDN or server cache of wp-content/uploads/faz-cookie-manager/assets/.', '%s generated files are empty on disk; the next page views rewrite them, then purge any CDN or server cache of wp-content/uploads/faz-cookie-manager/assets/.', $faz_empty_assets, 'faz-cookie-manager' ),
						number_format_i18n( $faz_empty_assets )
					);
				}
				if ( is_array( $faz_probe ) && '' === $faz_probe['error'] ) {
					if ( 200 !== $faz_probe['status'] ) {
						$faz_probe_issues[] = sprintf(
							/* translators: %s: HTTP status code. */
							__( 'The configuration file is not served (HTTP %s): check the web server, security or staging-protection rules for wp-content/uploads.', 'faz-cookie-manager' ),
							$faz_probe['status']
						);
					} elseif ( $faz_probe['html'] ) {
						$faz_probe_issues[] = __( 'An HTML page answered instead of the script: a login, maintenance or staging-protection layer intercepts static files under wp-content/uploads. Exempt that folder.', 'faz-cookie-manager' );
					} elseif ( 0 === $faz_probe['bytes'] || $faz_probe['bytes'] < $faz_probe['disk'] || ! $faz_probe['shape'] ) {
						$faz_probe_issues[] = sprintf(
							/* translators: 1: bytes served, 2: bytes on disk. */
							__( 'The served copy is empty, truncated or rewritten (%1$s of %2$s bytes): purge the server and CDN cache for wp-content/uploads/faz-cookie-manager/assets/, and exclude that folder from minification.', 'faz-cookie-manager' ),
							number_format_i18n( $faz_probe['bytes'] ),
							number_format_i18n( $faz_probe['disk'] )
						);
					}
					if ( 200 === $faz_probe['status'] && ! $faz_probe['html'] && ! preg_match( '/javascript|ecmascript/i', $faz_probe['type'] ) ) {
						$faz_probe_issues[] = sprintf(
							/* translators: %s: the Content-Type header the file is served with. */
							__( 'The file is served as "%s", not as JavaScript; with X-Content-Type-Options: nosniff a browser refuses to run it. Map .js to application/javascript for wp-content/uploads in the web server or CDN.', 'faz-cookie-manager' ),
							'' !== $faz_probe['type'] ? $faz_probe['type'] : 'none'
						);
					}
				}
				?>
				<tr>
					<td><?php esc_html_e( 'Generated Assets', 'faz-cookie-manager' ); ?></td>
					<td>
						<code><?php echo esc_html( '' !== $faz_assets_path ? $faz_assets_path : __( 'unavailable', 'faz-cookie-manager' ) ); ?></code>
						<div class="faz-status-detail">
							<?php
							printf(
								/* translators: 1: number of config-*.js files, 2: number of banner-*.css files. */
								esc_html__( '%1$s configuration files (config-*.js), %2$s banner stylesheets (banner-*.css).', 'faz-cookie-manager' ),
								esc_html( number_format_i18n( count( $faz_config_files ) ) ),
								esc_html( number_format_i18n( count( $faz_banner_files ) ) )
							);
							if ( is_array( $faz_probe ) ) {
								echo '<br>';
								if ( '' !== $faz_probe['error'] ) {
									printf(
										/* translators: 1: file name, 2: error message. */
										esc_html__( 'Could not request %1$s from the server itself (%2$s). This check needs loopback requests; open the file in a browser and look at its status, Content-Type and size instead.', 'faz-cookie-manager' ),
										'<code>' . esc_html( basename( $faz_probe['url'] ) ) . '</code>',
										esc_html( $faz_probe['error'] )
									);
								} else {
									echo wp_kses_post( empty( $faz_probe_issues ) ? faz_status_flag( true ) : faz_status_flag( false ) ) . ' ';
									printf(
										/* translators: 1: file name, 2: HTTP status code, 3: Content-Type header, 4: number of bytes served. */
										esc_html__( 'Most recently used: %1$s — HTTP %2$s, Content-Type "%3$s", %4$s bytes.', 'faz-cookie-manager' ),
										'<code>' . esc_html( basename( $faz_probe['url'] ) ) . '</code>',
										esc_html( (string) $faz_probe['status'] ),
										esc_html( '' !== $faz_probe['type'] ? $faz_probe['type'] : 'none' ),
										esc_html( number_format_i18n( $faz_probe['bytes'] ) )
									);
								}
							}
							?>
						</div>
						<?php if ( ! empty( $faz_probe_issues ) ) : ?>
							<div class="faz-help"><?php echo esc_html( implode( ' ', $faz_probe_issues ) ); ?></div>
						<?php endif; ?>
					</td>
				</tr>
				<tr><td><?php esc_html_e( 'WordPress Version', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'PHP Version', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( PHP_VERSION ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'MySQL Version', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( $wpdb->db_version() ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Server', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown' ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Memory Limit', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( WP_MEMORY_LIMIT ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Max Execution Time', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s</code></td></tr>
				<tr><td><?php esc_html_e( 'PCRE Backtrack Limit', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( ini_get( 'pcre.backtrack_limit' ) ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Multisite', 'faz-cookie-manager' ); ?></td><td><code><?php echo is_multisite() ? 'Yes' : 'No'; ?></code></td></tr>
				<tr><td><?php esc_html_e( 'HTTPS', 'faz-cookie-manager' ); ?></td><td><code><?php echo is_ssl() ? 'Yes' : 'No'; ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Active Theme', 'faz-cookie-manager' ); ?></td><td><code><?php echo esc_html( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ); ?></code></td></tr>
			</table>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Plugin Configuration', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<table class="faz-status-table">
				<tr><td><?php esc_html_e( 'Banner Enabled', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['banner_control']['status'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Consent Logging', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['consent_logs']['status'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<?php
				// A refused consent record leaves no trace a site owner would
				// ever look at: the banner works, the visitor sees nothing, and
				// only the accountability record is missing. Say it here, where
				// the rest of the effective configuration is reported, and say
				// it even when the figure is zero — a row that appears only on
				// bad news makes its own absence unreadable, so a site losing
				// every record through a cause nothing counted looked exactly
				// like a healthy one. Issue #292.
				if ( ! empty( $settings['consent_logs']['status'] ) ) :
					$faz_tally          = \FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger::rejection_tally();
					$faz_refused        = (int) $faz_tally['count'];
					$faz_tally_days     = (int) \FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger::REJECTION_WINDOW_DAYS;
					$faz_token_days     = max( 1, (int) round( \FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger::token_max_age() / DAY_IN_SECONDS ) );
					$faz_cause_labels   = array(
						'stale_token'   => __( 'Stale origin token', 'faz-cookie-manager' ),
						'cross_origin'  => __( 'No same-origin signal', 'faz-cookie-manager' ),
						'missing_token' => __( 'No origin token', 'faz-cookie-manager' ),
						'throttled'     => __( 'Rate-limited', 'faz-cookie-manager' ),
						'write_failed'  => __( 'Database write failed', 'faz-cookie-manager' ),
					);
					?>
					<tr>
						<td><?php esc_html_e( 'Consent Records Refused', 'faz-cookie-manager' ); ?></td>
						<td>
							<?php
							printf(
								/* translators: 1: number of refused consent records, already formatted for the locale. 2: length of the reporting window, in days. */
								esc_html( _n( '%1$s in the last %2$s days — a consent decision that was not recorded.', '%1$s in the last %2$s days — consent decisions that were not recorded.', $faz_refused, 'faz-cookie-manager' ) ),
								esc_html( number_format_i18n( $faz_refused ) ),
								esc_html( number_format_i18n( $faz_tally_days ) )
							);

							if ( $faz_refused > 0 && $faz_tally['last'] > 0 ) {
								echo ' ';
								printf(
									/* translators: %s: human-readable time difference, e.g. "3 hours". */
									esc_html__( 'Most recent: %s ago.', 'faz-cookie-manager' ),
									esc_html( human_time_diff( (int) $faz_tally['last'] ) )
								);
							}

							if ( ! empty( $faz_tally['causes'] ) ) {
								echo '<div class="faz-status-detail">';
								$faz_cause_lines = array();
								foreach ( $faz_cause_labels as $faz_cause => $faz_label ) {
									if ( empty( $faz_tally['causes'][ $faz_cause ] ) ) {
										continue;
									}
									// Label and number, not a translatable "%1$s: %2$s" —
									// a format string with no words in it gives a
									// translator nothing to translate and one more
									// string to carry.
									$faz_cause_lines[] = esc_html( $faz_label ) . ': '
										. esc_html( number_format_i18n( (int) $faz_tally['causes'][ $faz_cause ] ) );
								}
								echo wp_kses_post( implode( ' &middot; ', $faz_cause_lines ) );
								echo '</div>';
							}
							?>
							<div class="faz-help">
							<?php
							// Two different windows meet in this row and both
							// are measured in days, so name each one. The tally
							// window is fixed; the token window is filterable.
							printf(
								/* translators: %s: accepted token window, in days. */
								esc_html__( 'A stale origin token means HTML was served from a cache older than the accepted token window, currently %s days: either shorten the cache lifetime or raise the window with the faz_consent_token_max_age filter. A token from another installation, or a malformed one, is refused the same way.', 'faz-cookie-manager' ),
								esc_html( number_format_i18n( $faz_token_days ) )
							);
							echo ' ';
							esc_html_e( 'A request with no same-origin signal, or no token at all, is normally automated traffic — but if that figure tracks your visitor numbers, your pages are being served on a host, scheme or port that differs from your WordPress Address: check that www and apex, http and https, and any non-default port all redirect to the canonical one. If the site is served on another domain through a translation proxy (GTranslate, Weglot) or a mirror, add that domain under Settings → Trusted Alternate Domains (or the faz_trusted_origins filter). A database write failure is always a real visitor whose consent was lost.', 'faz-cookie-manager' );
							echo ' ';
							// Rate-limited records behind a translation proxy: every
							// visitor of the translated domains reaches this origin from
							// the proxy's few addresses, so the 10-second per-address
							// window collides between unrelated people. Only said where
							// that topology is known to exist, because on any other site
							// the generic sentence below is the right one and naming a
							// vendor's header would only confuse.
							$faz_proxy_known = ! empty( \FazCookie\Includes\Integrations\Gtranslate::translated_hosts() )
								|| ( function_exists( 'faz_trusted_origins' ) && ! empty( faz_trusted_origins() ) );
							if ( $faz_proxy_known && ! empty( $faz_tally['causes']['throttled'] ) ) {
								echo wp_kses(
									sprintf(
										/* translators: 1: the faz_trusted_proxy_cidrs filter name. 2: the faz_proxy_forwarded_header filter name. 3: the HTTP_X_GT_CLIENTIP $_SERVER key. */
										esc_html__( 'Rate-limited records on a site served through a translation proxy usually mean that every visitor of the translated domains reaches WordPress from the proxy\'s few addresses and shares one rate-limit window. Ask the proxy provider (GTranslate support) for its server address ranges, declare them with the %1$s filter, and read the visitor\'s own address from the header the proxy forwards (X-GT-ClientIP) with the %2$s filter returning %3$s. Forwarded headers are never believed from an undeclared address, because anyone can send them.', 'faz-cookie-manager' ),
										'<code>faz_trusted_proxy_cidrs</code>',
										'<code>faz_proxy_forwarded_header</code>',
										'<code>HTTP_X_GT_CLIENTIP</code>'
									),
									array( 'code' => array() )
								);
								echo ' ';
							}
							printf(
								/* translators: %s: length of the reporting window, in days. */
								esc_html__( 'This tally covers %s days whatever the token window is set to, and counts at most one refusal per visitor per cause every ten seconds, so it is a floor rather than an exact total.', 'faz-cookie-manager' ),
								esc_html( number_format_i18n( $faz_tally_days ) )
							);
							?>
							</div>
						</td>
					</tr>
				<?php endif; ?>
				<tr><td><?php esc_html_e( 'Google Consent Mode', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $gcm_settings['status'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'IAB TCF v2.3', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['iab']['enabled'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Pageview Tracking', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['pageview_tracking'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Auto Scan', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['scanner']['auto_scan'] ) ? faz_status_flag( true ) . ' &mdash; ' . esc_html( $settings['scanner']['scan_frequency'] ?? 'weekly' ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Geo-Targeting', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['geolocation']['geo_targeting'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<?php
				// Both features were masked off in an earlier release and this
				// page said so. The mask was removed when they began to be driven
				// from the saved setting — see
				// Activator::reset_stale_per_cookie_consent() — but the copy here
				// was left behind, so the one page whose job is to report the
				// effective configuration went on reporting a live, enforced
				// feature as unavailable. Read the option, like every other row
				// does. The sanitiser already forces per_cookie false whenever
				// per_service is off, so a plain read IS the effective state.
				?>
				<tr><td><?php esc_html_e( 'Per-Service Consent', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['banner_control']['per_service_consent'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Per-Cookie Consent', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['banner_control']['per_cookie_consent'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Bot Detection', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ( ! isset( $settings['banner_control']['hide_from_bots'] ) || ! empty( $settings['banner_control']['hide_from_bots'] ) ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'GTM Data Layer', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['banner_control']['gtm_datalayer'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Age Gate', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['age_gate']['enabled'] ) ? faz_status_flag( true ) . ' ' . sprintf( /* translators: %d: minimum age in years. */ esc_html__( '(min age %d)', 'faz-cookie-manager' ), absint( $settings['age_gate']['min_age'] ?? 16 ) ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Cross-Domain Consent', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['consent_forwarding']['enabled'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<?php
				// The effective list the same-origin gates read, with where each
				// entry came from: a refusal tally that keeps counting
				// `cross_origin` on a proxied site is answered by this row, and
				// an entry that arrived through the filter is otherwise invisible.
				$faz_trusted_effective = function_exists( 'faz_trusted_origins' ) ? faz_trusted_origins() : array();
				$faz_trusted_detected  = \FazCookie\Includes\Integrations\Gtranslate::translated_hosts();
				$faz_trusted_setting   = array();
				if ( isset( $settings['trusted_origins']['alternate_domains'] ) && is_array( $settings['trusted_origins']['alternate_domains'] ) && function_exists( 'faz_normalize_trusted_origin' ) ) {
					$faz_trusted_setting = array_filter( array_map( 'faz_normalize_trusted_origin', $settings['trusted_origins']['alternate_domains'] ) );
				}
				?>
				<tr>
					<td><?php esc_html_e( 'Trusted Alternate Domains', 'faz-cookie-manager' ); ?></td>
					<td>
						<?php if ( empty( $faz_trusted_effective ) ) : ?>
							<?php esc_html_e( 'None — consent records and Do-Not-Sell submissions are accepted from the WordPress Address only.', 'faz-cookie-manager' ); ?>
						<?php else : ?>
							<?php
							$faz_trusted_lines = array();
							foreach ( $faz_trusted_effective as $faz_trusted_entry ) {
								if ( in_array( $faz_trusted_entry, $faz_trusted_detected, true ) ) {
									$faz_trusted_source = __( 'GTranslate, auto-detected', 'faz-cookie-manager' );
								} elseif ( in_array( $faz_trusted_entry, $faz_trusted_setting, true ) ) {
									$faz_trusted_source = __( 'Settings', 'faz-cookie-manager' );
								} else {
									$faz_trusted_source = __( 'faz_trusted_origins filter', 'faz-cookie-manager' );
								}
								$faz_trusted_lines[] = '<code>' . esc_html( $faz_trusted_entry ) . '</code> (' . esc_html( $faz_trusted_source ) . ')';
							}
							echo wp_kses_post( implode( ', ', $faz_trusted_lines ) );
							?>
						<?php endif; ?>
						<?php if ( \FazCookie\Includes\Integrations\Gtranslate::is_active() && empty( $faz_trusted_detected ) ) : ?>
							<div class="faz-help"><?php esc_html_e( 'GTranslate is active but its settings describe no translated domain of their own (the free widget and the sub-directory plan stay on this host), so nothing was auto-detected.', 'faz-cookie-manager' ); ?></div>
						<?php endif; ?>
					</td>
				</tr>
				<tr><td><?php esc_html_e( 'Ad-Blocker Compat', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['banner_control']['alternative_asset_path'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Microsoft UET', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['microsoft']['uet_consent_mode'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Microsoft Clarity', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['microsoft']['clarity_consent'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'PHP Set-Cookie Blocking', 'faz-cookie-manager' ); ?></td><td><?php echo wp_kses_post( ! empty( $settings['script_blocking']['block_server_cookies'] ) ? faz_status_flag( true ) : faz_status_flag( false ) ); ?></td></tr>
			</table>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Recently Blocked Server Cookies', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<?php if ( empty( $blocked_server_cookies ) ) : ?>
				<p><?php esc_html_e( 'No outgoing PHP Set-Cookie header has been blocked in the last 24 hours.', 'faz-cookie-manager' ); ?></p>
			<?php else : ?>
				<?php
				/*
				 * Four DATA columns, so this one carries faz-status-table-data.
				 * Every other table on this page is a label:value pair, which is
				 * what the bare .faz-status-table rules are built for — they bold
				 * the first cell and give it 40% width. Applied here that made the
				 * cookie name read as a row label and left the remaining three
				 * columns captionless, on the page an administrator uses to check
				 * that PHP Set-Cookie blocking is not breaking checkout or login.
				 */
				?>
				<table class="faz-status-table faz-status-table-data">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Cookie', 'faz-cookie-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Category', 'faz-cookie-manager' ); ?></th>
							<?php
							/*
							 * "Path", not "URI": record_blocked_server_cookies()
							 * stores strtok( REQUEST_URI, '?' ) capped at 255
							 * chars, because WordPress query strings routinely
							 * carry personal data (?email=, order_key, the
							 * password-reset key+login pair, search terms) and
							 * this row is written by anonymous visitors. An
							 * administrator reading this column to check that
							 * Set-Cookie blocking is not breaking checkout or
							 * login must not expect a query string that is
							 * deliberately never captured.
							 */
							?>
							<th scope="col"><?php esc_html_e( 'Request Path', 'faz-cookie-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Blocked At', 'faz-cookie-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $blocked_server_cookies, 0, 20 ) as $blocked_cookie ) : ?>
							<tr>
								<td><code><?php echo esc_html( isset( $blocked_cookie['name'] ) ? $blocked_cookie['name'] : '' ); ?></code></td>
								<td><?php echo esc_html( isset( $blocked_cookie['category'] ) ? $blocked_cookie['category'] : '' ); ?></td>
								<td><code><?php echo esc_html( isset( $blocked_cookie['request'] ) ? $blocked_cookie['request'] : '' ); ?></code></td>
								<td><?php echo ! empty( $blocked_cookie['blocked_at'] ) ? esc_html( date_i18n( 'Y-m-d H:i:s', absint( $blocked_cookie['blocked_at'] ) ) ) : '&mdash;'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Database', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<table class="faz-status-table">
				<?php foreach ( $table_info as $name => $count ) : ?>
				<tr><td><code><?php echo esc_html( $wpdb->prefix . $name ); ?></code></td><td><?php
					if ( -1 === $count ) {
						echo '<span style="color:red;">' . esc_html__( 'Table missing', 'faz-cookie-manager' ) . '</span>';
					} else {
						echo esc_html( number_format_i18n( $count ) ) . ' ' . esc_html__( 'rows', 'faz-cookie-manager' );
					}
				?></td></tr>
				<?php endforeach; ?>
			</table>
		</div>
	</div>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Cron Jobs', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<p class="faz-help"><?php esc_html_e( 'A hosting cron that runs wp-cron.php processes WordPress scheduled events, including FAZ scans and cleanup. WP-CLI is optional. DISABLE_WP_CRON only disables automatic spawning on page visits; it does not prevent your host from running scheduled events.', 'faz-cookie-manager' ); ?></p>
			<details>
				<summary><?php esc_html_e( 'cPanel setup without WP-CLI', 'faz-cookie-manager' ); ?></summary>
				<p><?php esc_html_e( 'If your host already runs wp-cron.php regularly, keep that job; do not create a duplicate. Otherwise, ask your host to add a Cron Job in cPanel every five minutes (minute: */5; all other schedule fields: *), using the command below. The host should confirm the curl path and that the request is not blocked by authentication or a firewall.', 'faz-cookie-manager' ); ?></p>
				<code style="overflow-wrap:anywhere;user-select:all;"><?php echo esc_html( '/usr/bin/curl --fail --silent --show-error ' . escapeshellarg( site_url( 'wp-cron.php?doing_wp_cron' ) ) . ' > /dev/null' ); ?></code>
				<p><?php esc_html_e( 'No separate Rulesets schedule is needed for WordPress cron events. Check that overdue dates advance after a run. A next-run timestamp shows scheduling, not proof that the last run succeeded.', 'faz-cookie-manager' ); ?></p>
			</details>
			<table class="faz-status-table">
				<tr>
					<td><?php esc_html_e( 'Next Scheduled Scan', 'faz-cookie-manager' ); ?></td>
					<td><?php echo wp_kses_post( faz_status_schedule( $next_scan ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Next Consent Log Cleanup', 'faz-cookie-manager' ); ?></td>
					<td><?php echo wp_kses_post( faz_status_schedule( $next_cleanup ) ); ?></td>
				</tr>
				<?php
				// The definitions' age belongs beside the cron rows, because the
				// two questions are the same one: "why are my definitions old?"
				// is usually answered by an overdue schedule two lines up.
				$faz_ocd_meta = class_exists( '\\FazCookie\\Includes\\Cookie_Definitions' )
					? ( new \FazCookie\Includes\Cookie_Definitions() )->get_meta()
					: array();
				$faz_gvl_meta = class_exists( '\\FazCookie\\Includes\\Gvl' )
					? ( new \FazCookie\Includes\Gvl() )->get_meta()
					: array();
				?>
				<tr>
					<td><?php esc_html_e( 'Cookie definitions updated', 'faz-cookie-manager' ); ?></td>
					<td><?php
					// Key on `source`, not on the presence of a date. get_meta()
					// always returns an updated_at now — the download's, or the
					// bundled snapshot's capture date — so testing the date alone
					// made the bundled branch unreachable and printed a snapshot
					// date as though the site had downloaded it that day. Two
					// different situations report source 'bundled': never
					// downloaded at all, and downloaded once but superseded by a
					// newer bundle, so the copy must not claim "never downloaded"
					// for both.
					if ( isset( $faz_ocd_meta['source'] ) && 'bundled' === $faz_ocd_meta['source'] && ! empty( $faz_ocd_meta['updated_at'] ) ) {
						printf(
							/* translators: %s: date the bundled snapshot's data was captured. */
							esc_html__( 'bundled data in use, captured %s', 'faz-cookie-manager' ),
							esc_html( $faz_ocd_meta['updated_at'] )
						);
					} elseif ( ! empty( $faz_ocd_meta['updated_at'] ) ) {
						echo esc_html( $faz_ocd_meta['updated_at'] );
					} else {
						esc_html_e( 'never downloaded (bundled data in use)', 'faz-cookie-manager' );
					}
					?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'IAB vendor list updated', 'faz-cookie-manager' ); ?></td>
					<td><?php echo ! empty( $faz_gvl_meta['last_updated'] )
						? esc_html( $faz_gvl_meta['last_updated'] )
						: esc_html__( 'never downloaded', 'faz-cookie-manager' ); ?></td>
				</tr>
			</table>
		</div>
	</div>

	<?php
	/*
	 * Requests this plugin parks without leaving a visible trace (#279).
	 *
	 * A blocked embed shows a placeholder, so it announces itself. A blocked
	 * stylesheet or script does not: the page simply renders in fallback fonts,
	 * or a control appears not to work. Those providers also declare no cookies,
	 * so they contribute no row to the cookie declaration either — nothing in
	 * the banner, the preference centre or the Cookie Policy says a third-party
	 * request is being held back.
	 *
	 * The result is a symptom that points at the wrong component. In #253 the
	 * report was a shortcode button "not functioning"; the cause was the theme
	 * loading Google Fonts from Google and FAZ parking that request. The
	 * reporter had already ruled out their cache plugin and their CDN. Days on
	 * both sides, for something the plugin knew it was doing.
	 *
	 * This is the page an administrator opens when something is odd, so it is
	 * where the answer belongs. The list is the shipped catalogue rather than
	 * what this site actually loads — knowing that would mean recording every
	 * parked request at render time, and this page should not cost that.
	 */
	$faz_silent_providers = class_exists( '\FazCookie\Includes\Known_Providers' )
		? \FazCookie\Includes\Known_Providers::get_silent_providers()
		: array();
	?>
	<?php if ( ! empty( $faz_silent_providers ) ) : ?>
	<?php
	// data-faz-copy="omit": this card is the shipped catalogue, the same on
	// every install, not a reading of this site. In a copied report it only
	// added a few hundred lines of third-party domains, enough for forums and
	// chat services to refuse the message as spam.
	?>
	<div class="faz-card" data-faz-copy="omit">
		<div class="faz-card-header"><h3><?php esc_html_e( 'May be blocked without a visible placeholder', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<p class="faz-help">
				<?php
				printf(
					/* translators: %d: number of providers. */
					esc_html__( 'These %d catalogued services declare no cookies, so they appear in no cookie declaration, and when blocked on a script or stylesheet pattern no placeholder is shown. This is a catalogue of potential matches, not a report of what this site loads; a matching whitelist entry may also allow a request. When a request is blocked before consent, fonts can fall back or a control can look broken.', 'faz-cookie-manager' ),
					count( $faz_silent_providers )
				);
				?>
			</p>
			<p class="faz-help">
				<?php esc_html_e( 'Whitelisting a pattern in Settings → Script Blocking lets it load before consent. Google Fonts is deliberately not whitelisted: German courts have held that loading it from Google without consent is unlawful. Serving those fonts from your own server avoids both the block and the question.', 'faz-cookie-manager' ); ?>
			</p>
			<div style="font-size:13px;line-height:1.8;max-height:300px;overflow-y:auto;">
				<?php
				foreach ( $faz_silent_providers as $faz_provider ) {
					$faz_patterns = (array) $faz_provider['patterns'];
					echo '<strong>' . esc_html( $faz_provider['label'] ?? '?' ) . '</strong> ';
					echo '<code>' . esc_html( $faz_provider['category'] ?? '' ) . '</code> ';
					echo esc_html( implode( ', ', array_slice( $faz_patterns, 0, 3 ) ) );
					echo '<br>';
				}
				?>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="faz-card">
		<div class="faz-card-header"><h3><?php esc_html_e( 'Active Plugins', 'faz-cookie-manager' ); ?></h3></div>
		<div class="faz-card-body">
			<div style="font-size:13px;line-height:1.8;max-height:300px;overflow-y:auto;">
				<?php
				foreach ( $active_plugins as $p ) {
					$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $p, false, false );
					echo esc_html( $plugin_data['Name'] ?? $p ) . ' <code>' . esc_html( $plugin_data['Version'] ?? '?' ) . '</code><br>';
				}
				?>
			</div>
		</div>
	</div>

</div>

<?php
/*
 * Page-specific styles live in admin/assets/css/faz-admin.css under the
 * "System Status page" block — automatically enqueued for every FAZ
 * admin page.
 *
 * Page-specific behaviour lives in admin/assets/js/pages/system-status.js —
 * automatically enqueued by class-admin.php::enqueue_scripts() when the
 * current page view is "system-status". The localized "Status copied"
 * string is registered in the same enqueue block under
 * fazConfig.i18n.systemStatus.copied.
 */
?>
