<?php
/**
 * FAZ Cookie Manager — Base Admin Template
 *
 * Shared wrapper for all admin pages.
 * Variables expected: $faz_page_title (string), $faz_page_slug (string)
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="faz-wrap" id="faz-admin">
	<?php
	$faz_nav_items = array(
		'dashboard'      => array( 'slug' => 'faz-cookie-manager',                'label' => __( 'Dashboard', 'faz-cookie-manager' ) ),
		'banner'         => array( 'slug' => 'faz-cookie-manager-banner',         'label' => __( 'Cookie Banner', 'faz-cookie-manager' ) ),
		'cookies'        => array( 'slug' => 'faz-cookie-manager-cookies',        'label' => __( 'Cookies', 'faz-cookie-manager' ) ),
		'consent-logs'   => array( 'slug' => 'faz-cookie-manager-consent-logs',   'label' => __( 'Consent Logs', 'faz-cookie-manager' ) ),
		'gcm'            => array( 'slug' => 'faz-cookie-manager-gcm',            'label' => __( 'Google Consent Mode', 'faz-cookie-manager' ) ),
		'languages'      => array( 'slug' => 'faz-cookie-manager-languages',      'label' => __( 'Languages', 'faz-cookie-manager' ) ),
		'settings'       => array( 'slug' => 'faz-cookie-manager-settings',       'label' => __( 'Settings', 'faz-cookie-manager' ) ),
		'gvl'            => array( 'slug' => 'faz-cookie-manager-gvl',            'label' => __( 'Global Vendor List', 'faz-cookie-manager' ) ),
		'import-export'  => array( 'slug' => 'faz-cookie-manager-import-export',  'label' => __( 'Import / Export', 'faz-cookie-manager' ) ),
		'system-status'  => array( 'slug' => 'faz-cookie-manager-system-status',  'label' => __( 'System Status', 'faz-cookie-manager' ) ),
	);
	$faz_page_descriptions = array(
		'dashboard'     => __( 'Monitor pageviews, consent behaviour, and the health of your privacy setup at a glance.', 'faz-cookie-manager' ),
		'banner'        => __( 'Configure layout, copy, colours, and consent behaviour without leaving the editor flow.', 'faz-cookie-manager' ),
		'cookies'       => __( 'Review discovered cookies, categorise them, run scans, and keep your declarations current.', 'faz-cookie-manager' ),
		'consent-logs'  => __( 'Inspect consent records, filter activity, and export audit data when you need it.', 'faz-cookie-manager' ),
		'gcm'           => __( 'Control Google Consent Mode defaults and updates with a clearer view of every signal.', 'faz-cookie-manager' ),
		'languages'     => __( 'Manage available languages and keep translated banner content organised.', 'faz-cookie-manager' ),
		'settings'      => __( 'Tune privacy, logging, scanner, and integration settings from one backend workspace.', 'faz-cookie-manager' ),
		'gvl'           => __( 'Browse IAB TCF vendors, review their declared purposes, and select which vendors your site works with.', 'faz-cookie-manager' ),
		'import-export' => __( 'Move settings safely between environments and keep repeatable backups close at hand.', 'faz-cookie-manager' ),
		'system-status' => __( 'Check environment details, plugin health, and runtime dependencies before troubleshooting.', 'faz-cookie-manager' ),
	);
	$faz_page_description = isset( $faz_page_descriptions[ $faz_page_slug ] ) ? $faz_page_descriptions[ $faz_page_slug ] : '';
	?>
	<nav class="faz-top-nav" aria-label="<?php esc_attr_e( 'FAZ Cookie Manager navigation', 'faz-cookie-manager' ); ?>">
		<div class="faz-top-nav-brand">
			<span class="faz-top-nav-brand-mark" aria-hidden="true">
				<svg viewBox="0 0 24 24" focusable="false">
					<path d="M12 2.75c4.42 0 8 3.58 8 8 0 5.54-5.43 9.44-7.28 10.58a1.4 1.4 0 0 1-1.45 0C9.43 20.19 4 16.29 4 10.75c0-4.42 3.58-8 8-8Zm0 2.25a5.75 5.75 0 1 0 0 11.5A5.75 5.75 0 0 0 12 5Zm-2.9 4.2a1.15 1.15 0 1 1 0 2.3 1.15 1.15 0 0 1 0-2.3Zm5.8 0a1.15 1.15 0 1 1 0 2.3 1.15 1.15 0 0 1 0-2.3Zm-2.9 2.55a1.15 1.15 0 1 1 0 2.3 1.15 1.15 0 0 1 0-2.3Z"/>
				</svg>
			</span>
			<span class="faz-top-nav-brand-copy">
				<strong><?php esc_html_e( 'Cookie Manager', 'faz-cookie-manager' ); ?></strong>
				<small><?php esc_html_e( 'Privacy controls for this WordPress site', 'faz-cookie-manager' ); ?></small>
			</span>
		</div>
		<ul class="faz-top-nav-menu">
			<?php foreach ( $faz_nav_items as $nav_key => $nav_item ) :
				$is_current = ( $faz_page_slug === $nav_key ) || ( 'dashboard' === $nav_key && $faz_nav_items['dashboard']['slug'] === $faz_page_slug );
			?>
				<li<?php echo $is_current ? ' class="current"' : ''; ?>>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $nav_item['slug'] ) ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $nav_item['label'] ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<div class="faz-page-header">
		<div class="faz-page-header-copy">
			<span class="faz-page-eyebrow"><?php esc_html_e( 'FAZ Cookie Manager', 'faz-cookie-manager' ); ?></span>
			<h1><?php echo esc_html( $faz_page_title ); ?></h1>
			<?php if ( ! empty( $faz_page_description ) ) : ?>
				<p><?php echo esc_html( $faz_page_description ); ?></p>
			<?php endif; ?>
		</div>
		<div class="faz-page-header-actions" id="faz-page-actions"></div>
	</div>
	<div id="faz-page-content">
		<?php
		$view_file = __DIR__ . '/' . $faz_page_slug . '.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
		?>
	</div>
</div>
