<?php
/**
 * Plugin Name:       GeoLang – Multilingual Manager
 * Plugin URI:
 * Description:       Multilingual content manager for Elementor Pro pages. Supports PT, EN and ES with language switcher, Dynamic Tags and a centralized translation panel.
 * Version:           1.0.16
 * Author:            GeoLang
 * Text Domain:       geolang-multilingual
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'GEOLANG_VERSION', '1.0.16' );
define( 'GEOLANG_PATH', plugin_dir_path( __FILE__ ) );
define( 'GEOLANG_URL', plugin_dir_url( __FILE__ ) );
define( 'GEOLANG_BASENAME', plugin_basename( __FILE__ ) );

// ---------------------------------------------------------------------------
// Activation
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, 'geolang_activate' );

function geolang_activate() {
	geolang_create_table();

	if ( false === get_option( 'geolang_default_lang' ) ) {
		update_option( 'geolang_default_lang', 'pt' );
	}

	if ( false === get_option( 'geolang_active_langs' ) ) {
		update_option( 'geolang_active_langs', array( 'pt', 'en', 'es' ) );
	}
}

function geolang_create_table() {
	global $wpdb;

	$table        = $wpdb->prefix . 'geolang_strings';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id          BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		post_id     BIGINT(20) UNSIGNED NOT NULL,
		field_key   VARCHAR(191) NOT NULL,
		lang_pt     LONGTEXT,
		lang_en     LONGTEXT,
		lang_es     LONGTEXT,
		field_type  ENUM('text','url','image') DEFAULT 'text',
		created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
		updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY post_field (post_id, field_key),
		KEY post_id (post_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'geolang_db_version', GEOLANG_VERSION );
}

// ---------------------------------------------------------------------------
// Deactivation
// ---------------------------------------------------------------------------
register_deactivation_hook( __FILE__, 'geolang_deactivate' );

function geolang_deactivate() {
	// Remove all transients created by GeoLang.
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_geolang_%' OR option_name LIKE '_transient_timeout_geolang_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', 'geolang_boot' );

function geolang_boot() {
	// Require Elementor (free or any Pro variant like Pro Elements).
	if ( ! class_exists( '\Elementor\Plugin' ) ) {
		add_action( 'admin_notices', 'geolang_notice_elementor_required' );
		return;
	}

	// Ensure DB table exists (handles first run after manual copy).
	if ( get_option( 'geolang_db_version' ) !== GEOLANG_VERSION ) {
		geolang_create_table();
	}

	require_once GEOLANG_PATH . 'includes/class-geolang-core.php';
	GeoLang_Core::instance();
}

function geolang_notice_elementor_required() {
	$msg = __( '<strong>GeoLang – Multilingual Manager</strong> requires <strong>Elementor</strong> (free ou Pro Elements) to be installed and active.', 'geolang-multilingual' );
	printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $msg ) );
}
