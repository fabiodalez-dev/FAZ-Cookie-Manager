<?php
/**
 * Language controller file
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 * @package    FazCookie\Admin\Modules\Banners\Includes
 */

namespace FazCookie\Admin\Modules\Languages\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
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
	 * Cookie items
	 *
	 * @var array
	 */
	public $languages;

	public $faz_translated = array(
		"en","de","fr","it","es","nl","bg","da","ru","ar","pl","pt","ca","hu","sv","hr","zh","uk","sk","tr","lt","cs","fi","no","pt-br","sl","ro","th","et","lv","el","eu","bs","gl","ja","ko","mt","sr","tl","cy","sr-latn"
	);

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
	 * Get the available languages
	 *
	 * @since 3.0.0
	 *
	 * @return array
	 */
	public function get_languages() {
		if ( ! $this->languages ) {
			$this->languages = array(
				'Abkhazian'             => 'ab',
				'Afar'                  => 'aa',
				'Afrikaans'             => 'af',
				'Akan'                  => 'ak',
				'Albanian'              => 'sq',
				'Amharic'               => 'am',
				'Arabic'                => 'ar',
				'Armenian'              => 'hy',
				'Assamese'              => 'as',
				'Avar'                  => 'av',
				'Avestan'               => 'ae',
				'Aymara'                => 'ay',
				'Azerbaijani'           => 'az',
				'Bambara'               => 'bm',
				'Bashkir'               => 'ba',
				'Basque'                => 'eu',
				'Belarusian'            => 'be',
				'Bengali'               => 'bn',
				'Bhutani'               => 'dz',
				'Bihari'                => 'bh',
				'Bislama'               => 'bi',
				'Bosnian'               => 'bs',
				'Breton'                => 'br',
				'Bulgarian'             => 'bg',
				'Burmese'               => 'my',
				'Cambodian'             => 'km',
				'Catalan'               => 'ca',
				'Chamorro'              => 'ch',
				'Chechen'               => 'ce',
				'Chichewa'              => 'ny',
				'Chinese'               => 'zh',
				'Chinese (Simplified)'  => 'zh-hans',
				'Chinese (Traditional)' => 'zh-hant',
				'Chuvash'               => 'cv',
				'Cornish'               => 'kw',
				'Corsican'              => 'co',
				'Cree'                  => 'cr',
				'Croatian'              => 'hr',
				'Czech'                 => 'cs',
				'Danish'                => 'da',
				'Dutch'                 => 'nl',
				'English'               => 'en',
				'Esperanto'             => 'eo',
				'Estonian'              => 'et',
				'Ewe'                   => 'ee',
				'Faeroese'              => 'fo',
				'Fiji'                  => 'fj',
				'Finnish'               => 'fi',
				'French'                => 'fr',
				'Frisian'               => 'fy',
				'Fulah'                 => 'ff',
				'Galician'              => 'gl',
				'Georgian'              => 'ka',
				'German'                => 'de',
				'Greek'                 => 'el',
				'Greenlandic'           => 'kl',
				'Guarani'               => 'gn',
				'Gujarati'              => 'gu',
				'Hausa'                 => 'ha',
				'Hebrew'                => 'he',
				'Herero'                => 'hz',
				'Hindi'                 => 'hi',
				'Hiri Motu'             => 'ho',
				'Hungarian'             => 'hu',
				'Icelandic'             => 'is',
				'Igbo'                  => 'ig',
				'Indonesian'            => 'id',
				'Interlingua'           => 'ia',
				'Interlingue'           => 'ie',
				'Inuktitut'             => 'iu',
				'Inupiak'               => 'ik',
				'Irish'                 => 'ga',
				'Italian'               => 'it',
				'Japanese'              => 'ja',
				'Javanese'              => 'jv',
				'Kannada'               => 'kn',
				'Kanuri'                => 'kr',
				'Kashmiri'              => 'ks',
				'Kazakh'                => 'kk',
				'Kikuyu'                => 'ki',
				'Kinyarwanda'           => 'rw',
				'Kirghiz'               => 'ky',
				'Kirundi'               => 'rn',
				'Komi'                  => 'kv',
				'Kongo'                 => 'kg',
				'Korean'                => 'ko',
				'Kurdish'               => 'ku',
				'Kwanyama'              => 'kj',
				'Laothian'              => 'lo',
				'Latvian'               => 'lv',
				'Lingala'               => 'ln',
				'Lithuanian'            => 'lt',
				'Luganda'               => 'lg',
				'Luxembourgish'         => 'lb',
				'Macedonian'            => 'mk',
				'Malagasy'              => 'mg',
				'Malay'                 => 'ms',
				'Malayalam'             => 'ml',
				'Maldivian'             => 'dv',
				'Maltese'               => 'mt',
				'Manx'                  => 'gv',
				'Maori'                 => 'mi',
				'Marathi'               => 'mr',
				'Marshallese'           => 'mh',
				'Moldavian'             => 'mo',
				'Mongolian'             => 'mn',
				'Nauru'                 => 'na',
				'Navajo'                => 'nv',
				'Ndonga'                => 'ng',
				'Nepali'                => 'ne',
				'North Ndebele'         => 'nd',
				'Northern Sami'         => 'se',
				'Norwegian Bokmål'      => 'no',
				'Norwegian Nynorsk'     => 'nn',
				'Occitan'               => 'oc',
				'Old Slavonic'          => 'cu',
				'Oriya'                 => 'or',
				'Oromo'                 => 'om',
				'Ossetian'              => 'os',
				'Pali'                  => 'pi',
				'Pashto'                => 'ps',
				'Persian'               => 'fa',
				'Polish'                => 'pl',
				'Portuguese, Brazil'    => 'pt-br',
				'Portuguese, Portugal'  => 'pt',
				'Punjabi'               => 'pa',
				'Quechua'               => 'qu',
				'Rhaeto-Romance'        => 'rm',
				'Romanian'              => 'ro',
				'Russian'               => 'ru',
				'Samoan'                => 'sm',
				'Sango'                 => 'sg',
				'Sanskrit'              => 'sa',
				'Sardinian'             => 'sc',
				'Scots Gaelic'          => 'gd',
				'Serbian(Cyrillic)'     => 'sr',
				'Serbian(Latin)'        => 'sr-latn',
				'Serbo-Croatian'        => 'sh',
				'Sesotho'               => 'st',
				'Setswana'              => 'tn',
				'Shona'                 => 'sn',
				'Sindhi'                => 'sd',
				'Singhalese'            => 'si',
				'Siswati'               => 'ss',
				'Slavic'                => 'sla',
				'Slovak'                => 'sk',
				'Slovenian'             => 'sl',
				'Somali'                => 'so',
				'South Ndebele'         => 'nr',
				'Spanish'               => 'es',
				'Sudanese'              => 'su',
				'Swahili'               => 'sw',
				'Swedish'               => 'sv',
				'Tagalog'               => 'tl',
				'Tahitian'              => 'ty',
				'Tajik'                 => 'tg',
				'Tamil'                 => 'ta',
				'Tatar'                 => 'tt',
				'Telugu'                => 'te',
				'Thai'                  => 'th',
				'Tibetan'               => 'bo',
				'Tigrinya'              => 'ti',
				'Tonga'                 => 'to',
				'Tsonga'                => 'ts',
				'Turkish'               => 'tr',
				'Turkmen'               => 'tk',
				'Twi'                   => 'tw',
				'Uighur'                => 'ug',
				'Ukrainian'             => 'uk',
				'Urdu'                  => 'ur',
				'Uzbek'                 => 'uz',
				'Venda'                 => 've',
				'Vietnamese'            => 'vi',
				'Welsh'                 => 'cy',
				'Wolof'                 => 'wo',
				'Xhosa'                 => 'xh',
				'Yiddish'               => 'yi',
				'Yoruba'                => 'yo',
				'Zhuang'                => 'za',
				'Zulu'                  => 'zu',
			);

		}
		return $this->languages;
	}

	/**
	 * Localize list of languages.
	 *
	 * @return array
	 */
	public function load_config() {
		$data = array();
		foreach ( $this->get_languages() as $language => $code ) {
			$data[] = array(
				'code' => $code,
				'name' => $language,
			);
		}
		return $data;
	}

	public function is_faz_translated($lang) {
		return in_array($lang,$this->faz_translated);
	}

	public static function get_upload_path( $path = '' ) {
		$uploads    = wp_upload_dir();
		$upload_dir =  $uploads['basedir'] . '/fazcookie/' . $path;
		if ( !is_dir( $upload_dir)  ) {
			wp_mkdir_p($upload_dir);
		}
		return trailingslashit( $upload_dir );
	}

	public function download( $src ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        $upload_dir = $this->get_upload_path('languages/banners/');

        if ( ! file_exists( $upload_dir ) ) {
            wp_mkdir_p( $upload_dir );
        }

        //download file
        $tmpfile  = download_url( $src, $timeout = 25 );
        $file     = $upload_dir . basename( $src );

        //check for errors
        if ( !is_wp_error( $tmpfile ) ) {
            //remove current file
            if ( file_exists( $file ) && strpos( realpath( $file ), realpath( $upload_dir ) ) === 0 ) {
                wp_delete_file( $file );
            }

            //in case the server prevents deletion, we check it again.
            if ( ! file_exists( $file ) ) {
                copy( $tmpfile, $file );
            }
        } else {
            return $tmpfile;
        }

        if ( is_string( $tmpfile ) && file_exists( $tmpfile ) && strpos( realpath( $tmpfile ), realpath( sys_get_temp_dir() ) ) === 0 ) {
            wp_delete_file( $tmpfile );
        }
    }

	public function get_translations($lang) {
		if ($lang != 'en' && $this->is_faz_translated($lang)) {
			$upload_dir = wp_upload_dir();
			$upload_path = $upload_dir['basedir'] . '/fazcookie/languages/banners/' . esc_html( $lang ) . '.json';
			$contents = faz_read_json_file( $upload_path );
			if ( empty( $contents ) ) {
				// Copy from bundled translation files instead of downloading from cloud.
				$bundled = dirname( __DIR__, 2 ) . '/banners/includes/contents/' . esc_html( $lang ) . '.json';
				if ( file_exists( $bundled ) ) {
					$dest_dir = $this->get_upload_path( 'languages/banners/' );
					copy( $bundled, $dest_dir . esc_html( $lang ) . '.json' );
				}
			}
		}
		return true;
	}
}
