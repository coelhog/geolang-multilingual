<?php
defined( 'ABSPATH' ) || exit;

/**
 * Frontend asset enqueueing and JS localisation.
 */
class GeoLang_Frontend {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets() {
		wp_enqueue_style(
			'geolang-frontend',
			GEOLANG_URL . 'assets/css/frontend.css',
			array(),
			GEOLANG_VERSION
		);

		wp_enqueue_script(
			'geolang-frontend',
			GEOLANG_URL . 'assets/js/frontend.js',
			array(),
			GEOLANG_VERSION,
			true
		);

		$current_lang = GeoLang_Session::current();
		$default_lang = get_option( 'geolang_default_lang', 'pt' );
		$active_langs = get_option( 'geolang_active_langs', array( 'pt', 'en', 'es' ) );

		wp_localize_script(
			'geolang-frontend',
			'GeoLang',
			array(
				'currentLang' => $current_lang,
				'defaultLang' => $default_lang,
				'activeLangs' => $active_langs,
				'cookieName'  => 'geolang_lang',
				'cookieDays'  => 30,
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'geolang_frontend' ),
			)
		);
	}
}
