<?php
/** Category translations through the real controller, model and REST builder. */
namespace FazCookie\Includes {
	class Cache {
		public static function get( $key, $group ) { return $GLOBALS['category_rows']; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	function absint( $v ) { return abs( (int) $v ); }
	function sanitize_text_field( $v ) { return (string) $v; }
	function sanitize_title( $v ) { return (string) $v; }
	function sanitize_file_name( $v ) { return basename( $v ); }
	function wp_strip_all_tags( $v ) { return strip_tags( $v ); }
	function wp_kses_post( $v ) { return (string) $v; }
	function wp_filter_post_kses( $v ) { return (string) $v; }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function faz_default_language() { return 'en'; }
	function faz_selected_languages( $lang = '' ) { return array_unique( array_merge( array( 'en', 'ru', 'uk' ), $lang ? array( $lang ) : array() ) ); }
	function wp_upload_dir() { return array( 'basedir' => '/category-test-uploads' ); }
	function wp_cache_get( $key, $group ) { return $GLOBALS['category_cache'][ $key ] ?? false; }
	function wp_cache_set( $key, $value, $group, $ttl ) { $GLOBALS['category_cache'][ $key ] = $value; }
	function wp_cache_delete( $key, $group ) { unset( $GLOBALS['category_cache'][ $key ] ); return true; }
	function faz_read_json_file( $path ) {
		if ( 0 === strpos( $path, '/category-test-uploads/' ) ) { return $GLOBALS['category_uploads'][ basename( $path ) ] ?? array(); }
		return is_file( $path ) ? json_decode( file_get_contents( $path ), true ) : array();
	}
	function apply_filters( $hook, $value, ...$args ) { return $value; }

	$root = dirname( __DIR__, 2 );
	require_once $root . '/includes/class-base-controller.php';
	require_once $root . '/includes/class-store.php';
	require_once $root . '/admin/modules/cookies/includes/class-category-controller.php';
	require_once $root . '/admin/modules/cookies/includes/class-cookie-categories.php';
	require_once $root . '/frontend/includes/class-geo-runtime.php';
	require_once $root . '/frontend/modules/banner-rest/class-banner-rest.php';

	use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
	use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories;
	use FazCookie\Frontend\Modules\Banner_Rest\Banner_Rest;

	$passed = 0;
	function eq( $actual, $expected, $label ) {
		global $passed;
		if ( $actual !== $expected ) { throw new \RuntimeException( $label . ': ' . var_export( $actual, true ) ); }
		++$passed;
	}
	function row( $name, $description ) {
		return (object) array( 'category_id' => 1, 'slug' => 'necessary', 'name' => $name, 'description' => $description, 'cookies' => array(), 'meta' => '{}', 'visibility' => 1, 'prior_consent' => 1 );
	}
	$rest = ( new \ReflectionClass( Banner_Rest::class ) )->newInstanceWithoutConstructor();
	$build = new \ReflectionMethod( Banner_Rest::class, 'build_categories_payload' );
	$build->setAccessible( true );
	$names = array( 'en' => 'Necessary', 'ru' => 'Необходимые на нашем сайте', 'uk' => 'Необхідні на нашому сайті', 'de' => 'Eigener Text' );
	$descs = array( 'en' => 'Our description', 'ru' => 'Наше описание', 'uk' => 'Наш опис', 'de' => 'Beschreibung' );
	foreach ( array( 'json', 'array', 'object' ) as $format ) {
		$encode = function ( $v ) use ( $format ) { return 'json' === $format ? json_encode( $v ) : ( 'object' === $format ? (object) $v : $v ); };
		$GLOBALS['category_rows'] = array( row( $encode( $names ), $encode( $descs ) ) );
		foreach ( array( 'ru', 'uk', 'en' ) as $lang ) {
			$payload = $build->invoke( $rest, $lang );
			eq( $payload[0]['name'], $names[ $lang ], "$format $lang REST name" );
			eq( $payload[0]['description'], $descs[ $lang ], "$format $lang REST description" );
		}
		$prepared = Category_Controller::get_instance()->get_items();
		$model = new Cookie_Categories( reset( $prepared ) );
		eq( $model->get_name()['de'], $names['de'], 'deselected name preserved' );
		eq( $model->get_description()['de'], $descs['de'], 'deselected description preserved' );
	}
	$english = faz_read_json_file( $root . '/admin/modules/cookies/includes/contents/categories/en.json' );
	foreach ( array( 'ru', 'uk' ) as $lang ) {
		$bundled = faz_read_json_file( $root . "/admin/modules/cookies/includes/contents/categories/$lang.json" );
		eq( array_keys( $bundled ), array_keys( $english ), "$lang catalogue complete" );
		foreach ( $english as $slug => $fields ) {
			$model = new Cookie_Categories();
			$model->set_slug( $slug );
			$model->set_name( array( $lang => $fields['name'] ) );
			$model->set_description( array( $lang => $fields['description'] ) );
			eq( $model->get_name( $lang ), $bundled[ $slug ]['name'], "$lang $slug stored English name repaired" );
			eq( $model->get_description( $lang ), $bundled[ $slug ]['description'], "$lang $slug stored English description repaired" );
			$model->set_description( array( $lang => strip_tags( $fields['description'] ) ) );
			eq( $model->get_description( $lang ), $bundled[ $slug ]['description'], "$lang $slug editor-stripped English repaired" );
		}
		$GLOBALS['category_rows'] = array( row( '{}', '{}' ) );
		$payload = $build->invoke( $rest, $lang );
		eq( $payload[0]['name'], $bundled['necessary']['name'], "$lang missing REST name uses bundled translation" );
		eq( $payload[0]['description'], $bundled['necessary']['description'], "$lang missing REST description uses bundled translation" );
	}
	$GLOBALS['category_rows'] = array( row( 'Custom legacy name', 'Custom legacy description' ) );
	$payload = $build->invoke( $rest, 'en' );
	eq( $payload[0]['name'], 'Custom legacy name', 'plain text survives controller' );
	eq( $payload[0]['description'], 'Custom legacy description', 'plain description survives controller' );
	$GLOBALS['category_cache'] = array();
	$GLOBALS['category_uploads']['ru.json'] = array( 'category_data' => array( 'necessary' => array( 'name' => 'Особые файлы', 'description' => '' ), 'functional' => $english['functional'] ) );
	$model = new Cookie_Categories();
	$model->set_slug( 'necessary' );
	eq( $model->get_name( 'ru' ), 'Особые файлы', 'uploaded translation takes priority' );
	$ru = faz_read_json_file( $root . '/admin/modules/cookies/includes/contents/categories/ru.json' );
	eq( $model->get_description( 'ru' ), $ru['necessary']['description'], 'partial upload retains bundled description' );
	$model->set_slug( 'functional' );
	eq( $model->get_name( 'ru' ), $ru['functional']['name'], 'English upload does not hide bundled Russian' );
	// Downloading a language replaces the file on disk; the resolved catalogue is
	// cached for twelve hours, so without an explicit flush the editor and the
	// REST payload keep serving the previous one — indistinguishable, to an
	// administrator, from the download having failed.
	$GLOBALS['category_cache'] = array();
	$GLOBALS['category_uploads']['ru.json'] = array( 'category_data' => array( 'necessary' => array( 'name' => 'Прежнее имя' ) ) );
	$model = new Cookie_Categories();
	$model->set_slug( 'necessary' );
	eq( $model->get_name( 'ru' ), 'Прежнее имя', 'the downloaded catalogue is served' );
	// A newer file lands on disk, exactly as Controller::download() leaves it.
	$GLOBALS['category_uploads']['ru.json'] = array( 'category_data' => array( 'necessary' => array( 'name' => 'Новое имя' ) ) );
	eq( $model->get_name( 'ru' ), 'Прежнее имя', 'and the cache still holds the old one until flushed' );
	Cookie_Categories::flush_translation_cache( 'ru' );
	eq( $model->get_name( 'ru' ), 'Новое имя', 'flushing makes the new download visible immediately' );
	// A flush must be scoped to its language, not clear the whole group.
	$GLOBALS['category_cache']['faz_category_contents_v2_uk'] = array( 'necessary' => array( 'name' => 'sentinel' ) );
	Cookie_Categories::flush_translation_cache( 'ru' );
	eq( isset( $GLOBALS['category_cache']['faz_category_contents_v2_uk'] ), true, 'and leaves other languages cached' );

	echo "category i18n: $passed passed\n";
}
