<?php
defined( 'ABSPATH' ) || exit;

/**
 * Detects and exposes the active language.
 *
 * Priority: cookie → $_GET['lang'] → site default.
 * The cookie is written by JavaScript (frontend.js) to be cache-friendly.
 */
class GeoLang_Session {

	private static $current_lang = null;

	public function __construct() {
		add_action( 'init', array( $this, 'resolve_lang' ), 1 );
	}

	/**
	 * Resolves the active language and stores it in the static property.
	 */
	public function resolve_lang() {
		$default = get_option( 'geolang_default_lang', 'pt' );
		$active  = get_option( 'geolang_active_langs', array( 'pt', 'en', 'es' ) );

		$lang = $default;

		// GET param allows direct links like ?lang=en.
		if ( isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$candidate = sanitize_key( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( in_array( $candidate, $active, true ) ) {
				$lang = $candidate;
			}
		}

		// Cookie takes precedence over GET (JS writes it on click).
		if ( isset( $_COOKIE['geolang_lang'] ) ) {
			$candidate = sanitize_key( wp_unslash( $_COOKIE['geolang_lang'] ) );
			if ( in_array( $candidate, $active, true ) ) {
				$lang = $candidate;
			}
		}

		self::$current_lang = $lang;
	}

	/**
	 * Returns the active language code.
	 *
	 * @return string
	 */
	public static function current() {
		if ( null === self::$current_lang ) {
			return get_option( 'geolang_default_lang', 'pt' );
		}
		return self::$current_lang;
	}
}
