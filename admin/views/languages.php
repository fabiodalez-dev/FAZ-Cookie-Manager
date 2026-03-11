<?php
/**
 * FAZ Cookie Manager — Languages Page
 *
 * @package FazCookie\Admin
 */

defined( 'ABSPATH' ) || exit;

// Load full language list from controller.
$lang_controller = \FazCookie\Admin\Modules\Languages\Includes\Controller::get_instance();
$all_languages   = $lang_controller->get_languages(); // [ 'English' => 'en', ... ]

// Flip so we get [ 'en' => 'English', ... ] for easier JS consumption.
$lang_map = array();
foreach ( $all_languages as $name => $code ) {
	$lang_map[ $code ] = $name;
}
?>
<div id="faz-languages">

	<div class="faz-card">
		<div class="faz-card-header">
			<h3><?php esc_html_e( 'Active Languages', 'faz-cookie-manager' ); ?></h3>
		</div>
		<div class="faz-card-body">

			<div class="faz-form-group">
				<label><?php esc_html_e( 'Selected Languages', 'faz-cookie-manager' ); ?></label>
				<div id="faz-lang-tags" class="faz-lang-tags">
					<!-- JS renders selected language tags here -->
				</div>
			</div>

			<div class="faz-form-group">
				<label><?php esc_html_e( 'Add Language', 'faz-cookie-manager' ); ?></label>
				<div class="faz-lang-search-wrap">
					<input type="text" class="faz-input" id="faz-lang-search" placeholder="<?php echo esc_attr__( 'Search languages...', 'faz-cookie-manager' ); ?>" autocomplete="off">
					<div id="faz-lang-dropdown" class="faz-lang-dropdown"></div>
				</div>
				<div class="faz-help"><?php
					printf(
						/* translators: %d: number of available languages */
						esc_html__( 'Type to search from %d available languages.', 'faz-cookie-manager' ),
						count( $lang_map )
					);
				?></div>
			</div>

			<div class="faz-form-group">
				<label><?php esc_html_e( 'Default Language', 'faz-cookie-manager' ); ?></label>
				<select class="faz-select" id="faz-default-lang" style="width:auto;max-width:300px;">
					<!-- JS populates from selected languages -->
				</select>
				<div class="faz-help"><?php esc_html_e( 'The language used when the visitor\'s browser language does not match any of the selected languages.', 'faz-cookie-manager' ); ?></div>
			</div>
		</div>
		<div class="faz-card-footer">
			<button class="faz-btn faz-btn-primary" id="faz-lang-save"><?php esc_html_e( 'Save Languages', 'faz-cookie-manager' ); ?></button>
		</div>
	</div>
</div>

<script>
	window.fazAllLanguages = <?php echo wp_json_encode( $lang_map ); ?>;
</script>
