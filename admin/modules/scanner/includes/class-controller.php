<?php
/**
 * Local cookie scanner controller.
 *
 * Replaces the cloud-based scanner with a local PHP crawler
 * that fetches pages via wp_remote_get() and parses Set-Cookie headers.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Scanner\Includes;

use FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Local cookie scanner controller.
 *
 * @class       Controller
 * @version     3.0.0
 * @package     FazCookie
 */
class Controller {

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Default scan data
	 *
	 * @var array
	 */
	private static $default = array(
		'id'            => 0,
		'status'        => '',
		'type'          => 'local',
		'date'          => '',
		'total_cookies' => 0,
		'pages_scanned' => 0,
	);

	/**
	 * WP-Cron action name for async scanning.
	 */
	const CRON_HOOK = 'faz_async_cookie_scan';

	/**
	 * Last scan info.
	 *
	 * @var array|null
	 */
	protected $last_scan_info;

	/**
	 * Return the current instance of the class
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the WP-Cron hook for async scanning.
	 */
	public static function register_cron_hook() {
		add_action( self::CRON_HOOK, array( self::get_instance(), 'run_scan_async' ) );
	}

	/**
	 * Schedule an async scan via background PHP-CLI process.
	 *
	 * The PHP built-in dev server is single-threaded, so we cannot make
	 * loopback HTTP requests within a web request. Instead we spawn a
	 * separate PHP-CLI process that bootstraps WordPress independently.
	 *
	 * @param int $max_pages Maximum pages to scan.
	 * @return array Current scan info.
	 */
	public function schedule_scan( $max_pages = 20 ) {
		$max_pages = absint( $max_pages );
		$abspath   = ABSPATH;

		// Try WP-CLI first (most reliable).
		$wp_cli = $this->find_wp_cli();
		if ( $wp_cli ) {
			// Build safe eval string — max_pages is already absint'd.
			$eval_code = 'FazCookie\\Admin\\Modules\\Scanner\\Includes\\Controller::get_instance()->run_scan(' . $max_pages . ');';
			$cmd_parts = array(
				escapeshellarg( $wp_cli ),
				'eval',
				escapeshellarg( $eval_code ),
				'--path=' . escapeshellarg( $abspath ),
			);
			$cmd = implode( ' ', $cmd_parts ) . ' > /dev/null 2>&1 &';
		} else {
			// Fallback: spawn PHP-CLI with bootstrap script.
			$runner = ( defined( 'FAZ_PLUGIN_BASEPATH' ) ? FAZ_PLUGIN_BASEPATH : plugin_dir_path( __DIR__ ) . '../../../' ) . 'admin/modules/scanner/run-scan.php';
			$php_bin = defined( 'PHP_BINARY' ) ? PHP_BINARY : '';
			$php    = ( '' !== $php_bin ) ? $php_bin : 'php';
			$cmd    = sprintf(
				'%s %s %s %d > /dev/null 2>&1 &',
				escapeshellarg( $php ),
				escapeshellarg( $runner ),
				escapeshellarg( $abspath ),
				$max_pages
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Required for background scan.
		exec( $cmd ); // nosemgrep: php.lang.security.exec-use

		return $this->get_info();
	}

	/**
	 * Schedule a background server-side check of the homepage to detect
	 * httpOnly cookies that JavaScript cannot read via document.cookie.
	 *
	 * Uses a background PHP-CLI process to avoid single-threaded deadlocks.
	 *
	 * @return void
	 */
	public function schedule_httponly_check() {
		$abspath = ABSPATH;
		$wp_cli  = $this->find_wp_cli();

		if ( $wp_cli ) {
			$eval_code = 'FazCookie\\Admin\\Modules\\Scanner\\Includes\\Controller::get_instance()->run_httponly_check();';
			$cmd_parts = array(
				escapeshellarg( $wp_cli ),
				'eval',
				escapeshellarg( $eval_code ),
				'--path=' . escapeshellarg( $abspath ),
			);
			$cmd = implode( ' ', $cmd_parts ) . ' > /dev/null 2>&1 &';
		} else {
			$runner  = ( defined( 'FAZ_PLUGIN_BASEPATH' ) ? FAZ_PLUGIN_BASEPATH : plugin_dir_path( __DIR__ ) . '../../../' ) . 'admin/modules/scanner/run-scan.php';
			$php_bin = defined( 'PHP_BINARY' ) ? PHP_BINARY : '';
			$php     = ( '' !== $php_bin ) ? $php_bin : 'php';
			// Note: all values passed to escapeshellarg are safe — $runner is a hardcoded path, $abspath is ABSPATH.
			$cmd     = sprintf(
				'%s %s %s httponly > /dev/null 2>&1 &',
				escapeshellarg( $php ),
				escapeshellarg( $runner ),
				escapeshellarg( $abspath )
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Required for background scan.
		exec( $cmd ); // nosemgrep: php.lang.security.exec-use
	}

	/**
	 * Run a server-side check for httpOnly cookies on the homepage.
	 *
	 * Called as a background process — scans the homepage via HTTP and
	 * saves any httpOnly cookies not already in the database.
	 *
	 * @return void
	 */
	public function run_httponly_check() {
		$site_url     = home_url( '/' );
		$page_cookies = $this->scan_page( $site_url );

		if ( ! empty( $page_cookies ) ) {
			$this->save_cookies( $page_cookies );
		}
	}

	/**
	 * Find WP-CLI binary path.
	 *
	 * @return string|false Path to wp binary, or false if not found.
	 */
	private function find_wp_cli() {
		$output = array();
		$code   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'which wp 2>/dev/null', $output, $code ); // nosemgrep: php.lang.security.exec-use
		if ( 0 === $code && ! empty( $output[0] ) ) {
			return trim( $output[0] );
		}
		// Common paths.
		$paths = array( '/usr/local/bin/wp', '/opt/homebrew/bin/wp' );
		foreach ( $paths as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}
		return false;
	}

	/**
	 * WP-Cron callback — runs the actual scan (fallback for cron-capable servers).
	 */
	public function run_scan_async() {
		$max_pages = absint( get_option( 'faz_scan_max_pages', 20 ) );
		$this->run_scan( $max_pages );
	}

	/**
	 * Run a full local cookie scan.
	 *
	 * @param int $max_pages Maximum number of pages to crawl.
	 * @return array Scan results summary.
	 */
	public function run_scan( $max_pages = 20 ) {
		// Scanning makes many HTTP requests; prevent PHP timeout.
		@set_time_limit( 300 );

		$site_url = home_url( '/' );
		$pages    = $this->discover_pages( $site_url, $max_pages );
		$cookies  = array();

		foreach ( $pages as $url ) {
			$page_cookies = $this->scan_page( $url );
			foreach ( $page_cookies as $cookie_data ) {
				$name = $cookie_data['name'];
				// Deduplicate by cookie name, keeping first occurrence.
				if ( ! isset( $cookies[ $name ] ) ) {
					$cookies[ $name ] = $cookie_data;
				}
			}
		}

		$total_cookies = count( $cookies );
		$this->save_cookies( $cookies );

		$scan_id = absint( get_option( 'faz_scan_counter', 0 ) ) + 1;
		update_option( 'faz_scan_counter', $scan_id );

		$this->update_info(
			array(
				'id'            => $scan_id,
				'status'        => 'completed',
				'type'          => 'local',
				'date'          => current_time( 'mysql' ),
				'total_cookies' => $total_cookies,
				'pages_scanned' => count( $pages ),
			)
		);

		// Store scan history entry.
		$history   = get_option( 'faz_scan_history', array() );
		$history[] = array(
			'id'            => $scan_id,
			'status'        => 'completed',
			'type'          => 'local',
			'date'          => current_time( 'mysql' ),
			'total_cookies' => $total_cookies,
			'pages_scanned' => count( $pages ),
		);
		// Keep only last 50 entries.
		if ( count( $history ) > 50 ) {
			$history = array_slice( $history, -50 );
		}
		update_option( 'faz_scan_history', $history );

		return $this->get_info();
	}

	/**
	 * Discover pages using WordPress database queries (no HTTP requests).
	 *
	 * Used by the browser-based scanner's discover endpoint to avoid
	 * loopback deadlocks on single-threaded dev servers. Queries
	 * published posts, pages, and custom post types directly.
	 *
	 * @param int $max Maximum number of pages.
	 * @return array List of URLs.
	 */
	public function discover_pages_from_db( $max ) {
		$pages = array( home_url( '/' ) );

		// Get published posts and pages.
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$posts      = get_posts(
			array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => 'publish',
				'posts_per_page' => $max,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $posts as $post ) {
			$url = get_permalink( $post );
			if ( $url && ! in_array( $url, $pages, true ) ) {
				$pages[] = $url;
				if ( count( $pages ) >= $max ) {
					break;
				}
			}
		}

		// Add category/tag archive pages if we still have room.
		if ( count( $pages ) < $max ) {
			$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
			$terms      = get_terms(
				array(
					'taxonomy'   => array_values( $taxonomies ),
					'hide_empty' => true,
					'number'     => $max - count( $pages ),
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$url = get_term_link( $term );
					if ( ! is_wp_error( $url ) && ! in_array( $url, $pages, true ) ) {
						$pages[] = $url;
						if ( count( $pages ) >= $max ) {
							break;
						}
					}
				}
			}
		}

		return array_slice( $pages, 0, $max );
	}

	/**
	 * Discover pages to scan from sitemap.xml and homepage links.
	 *
	 * @param string $site_url The site URL.
	 * @param int    $max      Maximum number of pages.
	 * @return array List of URLs.
	 */
	public function discover_pages( $site_url, $max ) {
		$pages = array( $site_url );

		// Try sitemap.xml.
		$sitemap_url = trailingslashit( $site_url ) . 'sitemap.xml';
		$response    = wp_remote_get(
			$sitemap_url,
			array(
				'timeout'   => 15,
				'sslverify' => false,
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			if ( ! empty( $body ) ) {
				// Suppress XML errors and parse.
				$previous = libxml_use_internal_errors( true );
				$xml      = simplexml_load_string( $body );
				libxml_use_internal_errors( $previous );

				if ( false !== $xml ) {
					// Handle sitemap index (contains other sitemaps).
					if ( isset( $xml->sitemap ) ) {
						foreach ( $xml->sitemap as $sitemap ) {
							if ( isset( $sitemap->loc ) ) {
								$sub_response = wp_remote_get(
									(string) $sitemap->loc,
									array(
										'timeout'   => 15,
										'sslverify' => false,
									)
								);
								if ( ! is_wp_error( $sub_response ) && 200 === wp_remote_retrieve_response_code( $sub_response ) ) {
									$sub_body = wp_remote_retrieve_body( $sub_response );
									$previous = libxml_use_internal_errors( true );
									$sub_xml  = simplexml_load_string( $sub_body );
									libxml_use_internal_errors( $previous );
									if ( false !== $sub_xml && isset( $sub_xml->url ) ) {
										foreach ( $sub_xml->url as $url_entry ) {
											if ( isset( $url_entry->loc ) ) {
												$pages[] = (string) $url_entry->loc;
												if ( count( $pages ) >= $max ) {
													break 2;
												}
											}
										}
									}
								}
							}
							if ( count( $pages ) >= $max ) {
								break;
							}
						}
					}
					// Handle regular URL sitemap.
					if ( isset( $xml->url ) ) {
						foreach ( $xml->url as $url_entry ) {
							if ( isset( $url_entry->loc ) ) {
								$pages[] = (string) $url_entry->loc;
								if ( count( $pages ) >= $max ) {
									break;
								}
							}
						}
					}
				}
			}
		}

		// If sitemap didn't yield enough pages, parse homepage links.
		if ( count( $pages ) < $max ) {
			$homepage_response = wp_remote_get(
				$site_url,
				array(
					'timeout'   => 15,
					'sslverify' => false,
				)
			);
			if ( ! is_wp_error( $homepage_response ) && 200 === wp_remote_retrieve_response_code( $homepage_response ) ) {
				$html = wp_remote_retrieve_body( $homepage_response );
				$host = wp_parse_url( $site_url, PHP_URL_HOST );
				if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
					foreach ( $matches[1] as $href ) {
						$parsed = wp_parse_url( $href );
						// Only follow internal links.
						if ( isset( $parsed['host'] ) && $parsed['host'] !== $host ) {
							continue;
						}
						// Build absolute URL for relative links.
						if ( ! isset( $parsed['host'] ) ) {
							$href = trailingslashit( $site_url ) . ltrim( $href, '/' );
						}
						// Skip anchors, mailto, tel, javascript.
						if ( preg_match( '/^(#|mailto:|tel:|javascript:)/i', $href ) ) {
							continue;
						}
						// Skip non-page resources.
						if ( preg_match( '/\.(jpg|jpeg|png|gif|svg|css|js|pdf|zip|xml)(\?|$)/i', $href ) ) {
							continue;
						}
						if ( ! in_array( $href, $pages, true ) ) {
							$pages[] = $href;
							if ( count( $pages ) >= $max ) {
								break;
							}
						}
					}
				}
			}
		}

		return array_unique( array_slice( $pages, 0, $max ) );
	}

	/**
	 * Scan a single page for cookies via Set-Cookie headers.
	 *
	 * @param string $url URL to scan.
	 * @return array Array of discovered cookie data.
	 */
	public function scan_page( $url ) {
		$cookies   = array();
		$settings  = \FazCookie\Admin\Modules\Settings\Includes\Settings::get_instance();
		$static_ip = $settings->get( 'scanner', 'static_ip' );
		$args      = array(
			'timeout'     => 15,
			'sslverify'   => false,
			'redirection' => 3,
		);
		if ( ! empty( $static_ip ) && filter_var( $static_ip, FILTER_VALIDATE_IP ) ) {
			$parsed = wp_parse_url( $url );
			$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
			$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
			$port   = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
			$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
			$query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
			$url    = $scheme . '://' . $static_ip . $port . $path . $query;
			$args['headers'] = array( 'Host' => $host );
		}
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $cookies;
		}

		// Parse Set-Cookie headers.
		$headers = wp_remote_retrieve_headers( $response );
		$raw_cookies = array();

		if ( $headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary || ( class_exists( '\Requests_Utility_CaseInsensitiveDictionary' ) && $headers instanceof \Requests_Utility_CaseInsensitiveDictionary ) ) {
			$all = $headers->getAll();
			if ( isset( $all['set-cookie'] ) ) {
				$raw_cookies = (array) $all['set-cookie'];
			}
		} elseif ( is_array( $headers ) ) {
			if ( isset( $headers['set-cookie'] ) ) {
				$raw_cookies = (array) $headers['set-cookie'];
			}
		}

		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $raw_cookies as $cookie_string ) {
			$parsed = $this->parse_set_cookie( $cookie_string );
			if ( empty( $parsed['name'] ) ) {
				continue;
			}

			$name   = $parsed['name'];
			$domain = ! empty( $parsed['domain'] ) ? $parsed['domain'] : $site_domain;

			// Look up in known cookies database.
			$known = Cookie_Database::lookup( $name );

			if ( $known ) {
				$cookies[] = array(
					'name'        => $name,
					'domain'      => $domain,
					'duration'    => $known['duration'],
					'description' => $known['description'],
					'category'    => $known['category'],
				);
			} else {
				// Unknown cookie - categorize as uncategorized.
				$duration = 'session';
				if ( ! empty( $parsed['expires'] ) ) {
					$expires_time = strtotime( $parsed['expires'] );
					if ( false !== $expires_time ) {
						$diff     = $expires_time - time();
						$duration = $diff > 0 ? $this->seconds_to_human( $diff ) : 'session';
					}
				} elseif ( ! empty( $parsed['max-age'] ) ) {
					$max_age  = absint( $parsed['max-age'] );
					$duration = $max_age > 0 ? $this->seconds_to_human( $max_age ) : 'session';
				}

				$cookies[] = array(
					'name'        => $name,
					'domain'      => $domain,
					'duration'    => $duration,
					'description' => '',
					'category'    => 'uncategorized',
				);
			}
		}

		return $cookies;
	}

	/**
	 * Parse a Set-Cookie header string.
	 *
	 * @param string $cookie_string The raw Set-Cookie header value.
	 * @return array Parsed cookie attributes.
	 */
	public function parse_set_cookie( $cookie_string ) {
		$result = array(
			'name'     => '',
			'value'    => '',
			'domain'   => '',
			'path'     => '',
			'expires'  => '',
			'max-age'  => '',
			'secure'   => false,
			'httponly' => false,
			'samesite' => '',
		);

		$parts = explode( ';', $cookie_string );
		if ( empty( $parts ) ) {
			return $result;
		}

		// First part is name=value.
		$name_value = trim( $parts[0] );
		$eq_pos     = strpos( $name_value, '=' );
		if ( false === $eq_pos ) {
			return $result;
		}

		$result['name']  = trim( substr( $name_value, 0, $eq_pos ) );
		$result['value'] = trim( substr( $name_value, $eq_pos + 1 ) );

		// Parse remaining attributes.
		for ( $i = 1; $i < count( $parts ); $i++ ) {
			$part = trim( $parts[ $i ] );
			if ( empty( $part ) ) {
				continue;
			}
			$eq_pos = strpos( $part, '=' );
			if ( false !== $eq_pos ) {
				$attr_name  = strtolower( trim( substr( $part, 0, $eq_pos ) ) );
				$attr_value = trim( substr( $part, $eq_pos + 1 ) );
				if ( isset( $result[ $attr_name ] ) ) {
					$result[ $attr_name ] = $attr_value;
				}
			} else {
				$attr_name = strtolower( $part );
				if ( 'secure' === $attr_name ) {
					$result['secure'] = true;
				} elseif ( 'httponly' === $attr_name ) {
					$result['httponly'] = true;
				}
			}
		}

		return $result;
	}

	/**
	 * Convert seconds to a human-readable duration string.
	 *
	 * @param int $seconds Number of seconds.
	 * @return string Human-readable duration.
	 */
	public function seconds_to_human( $seconds ) {
		if ( $seconds <= 0 ) {
			return 'session';
		}

		$years  = floor( $seconds / ( 365.25 * DAY_IN_SECONDS ) );
		$months = floor( $seconds / ( 30.44 * DAY_IN_SECONDS ) );
		$days   = floor( $seconds / DAY_IN_SECONDS );
		$hours  = floor( $seconds / HOUR_IN_SECONDS );
		$mins   = floor( $seconds / MINUTE_IN_SECONDS );

		if ( $years >= 1 ) {
			return 1 === (int) $years ? '1 year' : $years . ' years';
		}
		if ( $months >= 1 ) {
			return 1 === (int) $months ? '1 month' : $months . ' months';
		}
		if ( $days >= 1 ) {
			return 1 === (int) $days ? '1 day' : $days . ' days';
		}
		if ( $hours >= 1 ) {
			return 1 === (int) $hours ? '1 hour' : $hours . ' hours';
		}
		if ( $mins >= 1 ) {
			return 1 === (int) $mins ? '1 minute' : $mins . ' minutes';
		}

		return $seconds . ' seconds';
	}

	/**
	 * Save scan results from the browser-based scanner.
	 *
	 * Receives cookies discovered by the client-side iframe scanner,
	 * merges inferred cookies from script analysis, saves everything,
	 * and updates scan history.
	 *
	 * @param array $cookies       Array of cookie data arrays.
	 * @param int   $pages_scanned Number of pages scanned.
	 * @param array $scripts       Array of detected script URLs (for inference).
	 * @return array Scan result summary.
	 */
	public function save_scan_result( $cookies, $pages_scanned, $scripts = array() ) {
		// Merge inferred cookies from script patterns.
		if ( ! empty( $scripts ) ) {
			$inferred       = Cookie_Database::lookup_scripts( $scripts );
			$existing_names = array();
			foreach ( $cookies as $c ) {
				$existing_names[] = $c['name'];
			}
			foreach ( $inferred as $inf ) {
				if ( ! in_array( $inf['name'], $existing_names, true ) ) {
					$cookies[]        = $inf;
					$existing_names[] = $inf['name'];
				}
			}
		}

		// Deduplicate by name.
		$unique  = array();
		$seen    = array();
		foreach ( $cookies as $c ) {
			if ( ! isset( $seen[ $c['name'] ] ) ) {
				$seen[ $c['name'] ] = true;
				$unique[]           = $c;
			}
		}

		$total_cookies = count( $unique );
		$this->save_cookies( $unique );

		$scan_id = absint( get_option( 'faz_scan_counter', 0 ) ) + 1;
		update_option( 'faz_scan_counter', $scan_id );

		$this->update_info(
			array(
				'id'            => $scan_id,
				'status'        => 'completed',
				'type'          => 'browser',
				'date'          => current_time( 'mysql' ),
				'total_cookies' => $total_cookies,
				'pages_scanned' => $pages_scanned,
			)
		);

		// Store scan history entry.
		$history   = get_option( 'faz_scan_history', array() );
		$history[] = array(
			'id'            => $scan_id,
			'status'        => 'completed',
			'type'          => 'browser',
			'date'          => current_time( 'mysql' ),
			'total_cookies' => $total_cookies,
			'pages_scanned' => $pages_scanned,
		);
		if ( count( $history ) > 50 ) {
			$history = array_slice( $history, -50 );
		}
		update_option( 'faz_scan_history', $history );

		return array(
			'scan_id'       => $scan_id,
			'total_cookies' => $total_cookies,
			'pages_scanned' => $pages_scanned,
		);
	}

	/**
	 * Save discovered cookies to the database using the Cookie model.
	 *
	 * @param array $cookies Array of discovered cookie data arrays.
	 * @return void
	 */
	public function save_cookies( $cookies ) {
		$category_controller = Category_Controller::get_instance();
		$categories          = $category_controller->get_items();
		$category_map        = array();
		foreach ( $categories as $cat ) {
			$category_map[ $cat->slug ] = $cat->category_id;
		}

		// Get existing cookies to avoid duplicates.
		$existing_cookies = Cookie_Controller::get_instance()->get_item_from_db();
		$existing_names   = array();
		if ( ! empty( $existing_cookies ) && is_array( $existing_cookies ) ) {
			foreach ( $existing_cookies as $ec ) {
				$existing_names[] = $ec->name;
			}
		}

		$default_lang = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';

		// Default fallback category for unknown cookies — prefer uncategorized.
		$default_cat_id = isset( $category_map['uncategorized'] )
			? $category_map['uncategorized']
			: ( isset( $category_map['necessary'] ) ? $category_map['necessary'] : 1 );

		foreach ( $cookies as $cookie_data ) {
			if ( in_array( $cookie_data['name'], $existing_names, true ) ) {
				continue; // Don't overwrite existing cookies.
			}

			// Try known cookies database first (handles WP admin cookies, etc.).
			$known = Cookie_Database::lookup( $cookie_data['name'] );
			if ( $known ) {
				$cat_slug = $known['category'];
				if ( ! empty( $known['description'] ) && empty( $cookie_data['description'] ) ) {
					$cookie_data['description'] = $known['description'];
				}
				if ( ! empty( $known['duration'] ) && ( empty( $cookie_data['duration'] ) || 'session' === $cookie_data['duration'] ) ) {
					$cookie_data['duration'] = $known['duration'];
				}
			} else {
				$cat_slug = isset( $cookie_data['category'] ) ? $cookie_data['category'] : 'necessary';
			}
			$category_id = isset( $category_map[ $cat_slug ] ) ? $category_map[ $cat_slug ] : $default_cat_id;

			$cookie = new Cookie();
			$cookie->set_name( sanitize_text_field( $cookie_data['name'] ) );
			$cookie->set_slug( sanitize_title( $cookie_data['name'] ) );
			$cookie->set_description( array( $default_lang => sanitize_text_field( $cookie_data['description'] ) ) );
			$cookie->set_duration( array( $default_lang => sanitize_text_field( $cookie_data['duration'] ) ) );
			$cookie->set_domain( sanitize_text_field( $cookie_data['domain'] ) );
			$cookie->set_category( $category_id );
			$cookie->set_type( 1 );
			$cookie->set_discovered( true );

			Cookie_Controller::get_instance()->create_item( $cookie );
		}
	}

	/**
	 * Get the last scan info.
	 *
	 * @return array
	 */
	public function get_info() {
		if ( ! $this->last_scan_info ) {
			$data = get_option( 'faz_scan_details', self::$default );
			$data = wp_parse_args( $data, self::$default );

			$formatted = '';
			if ( ! empty( $data['date'] ) ) {
				$timestamp = strtotime( sanitize_text_field( $data['date'] ) );
				$formatted = $timestamp ? gmdate( 'd F Y H:i:s', $timestamp ) : '';
			}

			$this->last_scan_info = array(
				'id'            => absint( $data['id'] ),
				'status'        => sanitize_text_field( $data['status'] ),
				'type'          => sanitize_text_field( $data['type'] ),
				'date'          => sanitize_text_field( $formatted ),
				'total_cookies' => absint( $data['total_cookies'] ),
				'pages_scanned' => absint( $data['pages_scanned'] ),
			);
		}
		return $this->last_scan_info;
	}

	/**
	 * Update the last scan info in the options table.
	 *
	 * @param array $data Scan data.
	 * @return void
	 */
	public function update_info( $data = array() ) {
		$scan_data = get_option( 'faz_scan_details', self::$default );
		$scan_data = wp_parse_args( $scan_data, self::$default );
		$data      = wp_parse_args( $data, $scan_data );

		$sanitized = array(
			'id'            => absint( $data['id'] ),
			'status'        => sanitize_text_field( $data['status'] ),
			'type'          => sanitize_text_field( $data['type'] ),
			'date'          => sanitize_text_field( $data['date'] ),
			'total_cookies' => absint( $data['total_cookies'] ),
			'pages_scanned' => absint( $data['pages_scanned'] ),
		);

		update_option( 'faz_scan_details', $sanitized );
		$this->last_scan_info = null; // Reset cached info.
	}

	/**
	 * Load scanner configs into WordPress localization function.
	 *
	 * @return array
	 */
	public function load_scanner_config() {
		return $this->get_info();
	}
}
