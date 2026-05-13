<?php
/**
 * Uninstall GeoLang – Multilingual Manager.
 *
 * Removes: custom table, plugin options, and all post meta added by the plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop custom table.
$table = $wpdb->prefix . 'geolang_strings';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove plugin options.
delete_option( 'geolang_default_lang' );
delete_option( 'geolang_active_langs' );
delete_option( 'geolang_db_version' );

// Remove all transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_geolang_%' OR option_name LIKE '_transient_timeout_geolang_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
