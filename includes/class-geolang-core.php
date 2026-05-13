<?php
defined( 'ABSPATH' ) || exit;

/**
 * Core orchestrator — loads all modules and exposes shared helpers.
 */
class GeoLang_Core {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->init_modules();
	}

	private function load_dependencies() {
		$files = array(
			'class-geolang-session.php',
			'class-geolang-ajax.php',
			'class-geolang-admin.php',
			'class-geolang-frontend.php',
			'class-geolang-shortcode.php',
			'class-geolang-metabox.php',
			'class-geolang-menus.php',
			'class-geolang-openrouter.php',
			'class-geolang-updater.php',
		);

		foreach ( $files as $file ) {
			require_once GEOLANG_PATH . 'includes/' . $file;
		}
	}

	private function init_modules() {
		new GeoLang_Session();
		new GeoLang_Ajax();
		new GeoLang_Admin();
		new GeoLang_Frontend();
		new GeoLang_Shortcode();
		new GeoLang_Metabox();
		new GeoLang_Menus();
		new GeoLang_OpenRouter();
		new GeoLang_Updater();

		// Load Elementor integration only after Elementor itself is fully initialized.
		// This guarantees all Elementor classes (including DynamicTags parents) are
		// available when our file is parsed — avoiding "Class not found" fatal errors.
		add_action( 'elementor/init', function () {
			require_once GEOLANG_PATH . 'includes/class-geolang-elementor.php';
			new GeoLang_Elementor();
		}, 0 );

		// Load WooCommerce integrations when WooCommerce is active.
		if ( class_exists( 'WooCommerce' ) ) {
			require_once GEOLANG_PATH . 'includes/class-geolang-woocommerce.php';
			new GeoLang_WooCommerce();

			require_once GEOLANG_PATH . 'includes/class-geolang-wc-terms.php';
			new GeoLang_WC_Terms();
		}
	}

	// -----------------------------------------------------------------------
	// Shared DB helpers
	// -----------------------------------------------------------------------

	/**
	 * Returns the fully-qualified table name.
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'geolang_strings';
	}

	/**
	 * Returns a translated field value for the given post, key, and lang.
	 *
	 * Falls back to PT if the requested lang is empty.
	 *
	 * @param int    $post_id
	 * @param string $field_key
	 * @param string $lang        'pt'|'en'|'es'
	 * @param string $fallback    Fallback string if nothing found.
	 * @return string
	 */
	public static function get_field( $post_id, $field_key, $lang = 'pt', $fallback = '' ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT lang_pt, lang_en, lang_es FROM {$table} WHERE post_id = %d AND field_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $post_id ),
				sanitize_key( $field_key )
			)
		);

		if ( ! $row ) {
			return $fallback;
		}

		$col   = 'lang_' . sanitize_key( $lang );
		$value = isset( $row->$col ) ? $row->$col : '';

		if ( '' === $value && 'pt' !== $lang ) {
			$value = $row->lang_pt;
		}

		return $value ?: $fallback;
	}

	/**
	 * Returns all field keys registered for a given post_id.
	 *
	 * @param int $post_id
	 * @return string[]
	 */
	public static function get_field_keys( $post_id ) {
		global $wpdb;

		$table = self::table();
		return $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT field_key FROM {$table} WHERE post_id = %d ORDER BY field_key ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $post_id )
			)
		);
	}
}
