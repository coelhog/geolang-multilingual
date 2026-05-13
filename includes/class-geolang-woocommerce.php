<?php
defined( 'ABSPATH' ) || exit;

/**
 * GeoLang WooCommerce integration.
 *
 * Hooks PHP filters so that product title, long description and short
 * description are automatically swapped to the visitor's active language
 * when the active language differs from the site default.
 *
 * Reserved meta-box keys used by this module:
 *   _wc_title        → the_title filter          (priority 10, 2 args)
 *   _wc_description  → the_content filter         (priority 10, 1 arg)
 *   _wc_short_desc   → woocommerce_short_description filter (priority 10, 1 arg)
 *
 * All filters are registered on the frontend only (is_admin() === false).
 * They fall back to the original WordPress/WooCommerce value when no
 * translation is found.
 */
class GeoLang_WooCommerce {

	public function __construct() {
		if ( is_admin() ) {
			return;
		}

		add_filter( 'the_title',                    array( $this, 'filter_title' ),      10, 2 );
		add_filter( 'the_content',                  array( $this, 'filter_content' ),    10, 1 );
		add_filter( 'woocommerce_short_description', array( $this, 'filter_short_desc' ), 10, 1 );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Returns the active language if it differs from the default, else null.
	 *
	 * @return string|null
	 */
	private function get_lang() {
		$lang    = GeoLang_Session::current();
		$default = get_option( 'geolang_default_lang', 'pt' );
		return ( $lang !== $default ) ? $lang : null;
	}

	// -----------------------------------------------------------------------
	// Filters
	// -----------------------------------------------------------------------

	/**
	 * Replaces the product title with the translated version.
	 *
	 * @param string $title   Original title.
	 * @param int    $post_id Post ID (second arg provided by WP core).
	 * @return string
	 */
	public function filter_title( $title, $post_id = 0 ) {
		if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) {
			return $title;
		}

		$lang = $this->get_lang();
		if ( ! $lang ) {
			return $title;
		}

		$translated = GeoLang_Core::get_field( $post_id, '_wc_title', $lang );
		return $translated ?: $title;
	}

	/**
	 * Replaces the product long description with the translated version.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public function filter_content( $content ) {
		$post_id = get_the_ID();
		if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) {
			return $content;
		}

		$lang = $this->get_lang();
		if ( ! $lang ) {
			return $content;
		}

		$translated = GeoLang_Core::get_field( $post_id, '_wc_description', $lang );
		return $translated ?: $content;
	}

	/**
	 * Replaces the product short description with the translated version.
	 * Hooks into woocommerce_short_description (product single & loop).
	 *
	 * @param string $description Original short description.
	 * @return string
	 */
	public function filter_short_desc( $description ) {
		$post_id = get_the_ID();
		if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) {
			return $description;
		}

		$lang = $this->get_lang();
		if ( ! $lang ) {
			return $description;
		}

		$translated = GeoLang_Core::get_field( $post_id, '_wc_short_desc', $lang );
		return $translated ?: $description;
	}
}
