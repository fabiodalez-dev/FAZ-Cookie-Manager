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
		'dashboard'    => array( 'slug' => 'faz-cookie-manager',              'label' => 'Dashboard' ),
		'banner'       => array( 'slug' => 'faz-cookie-manager-banner',       'label' => 'Cookie Banner' ),
		'cookies'      => array( 'slug' => 'faz-cookie-manager-cookies',      'label' => 'Cookies' ),
		'consent-logs' => array( 'slug' => 'faz-cookie-manager-consent-logs', 'label' => 'Consent Logs' ),
		'gcm'          => array( 'slug' => 'faz-cookie-manager-gcm',          'label' => 'Google Consent Mode' ),
		'languages'    => array( 'slug' => 'faz-cookie-manager-languages',    'label' => 'Languages' ),
		'settings'     => array( 'slug' => 'faz-cookie-manager-settings',     'label' => 'Settings' ),
	);
	?>
	<nav class="faz-top-nav" aria-label="FAZ Cookie Manager navigation">
		<span class="faz-top-nav-brand">FAZ Cookie</span>
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
		<h1><?php echo esc_html( $faz_page_title ); ?></h1>
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
