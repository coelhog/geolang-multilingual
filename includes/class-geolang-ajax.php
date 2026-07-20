<?php
defined( 'ABSPATH' ) || exit;

/**
 * AJAX handlers for GeoLang.
 *
 * All handlers: verify nonce → check capability → sanitize → act → respond.
 */
class GeoLang_Ajax {

	public function __construct() {
		$actions = array(
			'geolang_save_field'    => 'save_field',
			'geolang_get_fields'    => 'get_fields',
			'geolang_delete_field'  => 'delete_field',
			'geolang_get_field_keys'=> 'get_field_keys',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	// -----------------------------------------------------------------------
	// geolang_save_field
	// -----------------------------------------------------------------------

	public function save_field() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'geolang-multilingual' ) ), 403 );
		}

		$post_id    = absint( $_POST['post_id'] ?? 0 );
		$field_key  = $this->sanitize_field_key( wp_unslash( $_POST['field_key'] ?? '' ) );
		$field_type = $this->sanitize_field_type( wp_unslash( $_POST['field_type'] ?? 'text' ) );
		$lang_pt    = GeoLang_Core::kses_html( wp_unslash( $_POST['lang_pt'] ?? '' ) );
		$lang_en    = GeoLang_Core::kses_html( wp_unslash( $_POST['lang_en'] ?? '' ) );
		$lang_es    = GeoLang_Core::kses_html( wp_unslash( $_POST['lang_es'] ?? '' ) );

		if ( ! $field_key ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'geolang-multilingual' ) ) );
		}

		// post_id=0 means global field — only check edit_posts capability.
		// For post-specific fields, also verify the user can edit that post.
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot edit this post.', 'geolang-multilingual' ) ), 403 );
		}

		// Basic rate limiting: 60 saves/minute per user.
		if ( ! $this->check_rate_limit( get_current_user_id(), 'save', 60, 60 ) ) {
			wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Please wait before saving again.', 'geolang-multilingual' ) ), 429 );
		}

		// URL fields: sanitize as URLs.
		if ( 'url' === $field_type ) {
			$lang_pt = esc_url_raw( $lang_pt );
			$lang_en = esc_url_raw( $lang_en );
			$lang_es = esc_url_raw( $lang_es );
		}

		global $wpdb;

		$result = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			GeoLang_Core::table(),
			array(
				'post_id'    => $post_id,
				'field_key'  => $field_key,
				'field_type' => $field_type,
				'lang_pt'    => $lang_pt,
				'lang_en'    => $lang_en,
				'lang_es'    => $lang_es,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Database error.', 'geolang-multilingual' ) ) );
		}

		$inserted_id = $wpdb->insert_id ?: $this->get_row_id( $post_id, $field_key );

		wp_send_json_success( array( 'id' => $inserted_id ) );
	}

	// -----------------------------------------------------------------------
	// geolang_get_fields
	// -----------------------------------------------------------------------

	public function get_fields() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'geolang-multilingual' ) ), 403 );
		}

		global $wpdb;

		$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$field_type = isset( $_POST['field_type'] ) ? $this->sanitize_field_type( wp_unslash( $_POST['field_type'] ) ) : '';
		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$page       = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page   = 20;
		$offset     = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		if ( $post_id ) {
			$where[]  = 'post_id = %d';
			$params[] = $post_id;
		}

		if ( $field_type ) {
			$where[]  = 'field_type = %s';
			$params[] = $field_type;
		}

		if ( $search ) {
			// Search both field_key and PT content.
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]   = '(g.field_key LIKE %s OR g.lang_pt LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// Total count — use alias g so the WHERE clause (which may reference g.field_key, g.lang_pt) works.
		$gl_table_c = GeoLang_Core::table();
		$count_sql  = "SELECT COUNT(*) FROM {$gl_table_c} g WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total      = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL

		// Data — LEFT JOIN wp_posts to include human-readable post title.
		$gl_table    = GeoLang_Core::table();
		$posts_table = $wpdb->posts;
		$data_sql    = "SELECT g.*, COALESCE(p.post_title,'') AS post_title FROM {$gl_table} g LEFT JOIN {$posts_table} p ON p.ID = g.post_id WHERE {$where_sql} ORDER BY g.post_id ASC, g.field_key ASC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL

		wp_send_json_success(
			array(
				'rows'        => $rows,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// geolang_delete_field
	// -----------------------------------------------------------------------

	public function delete_field() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'geolang-multilingual' ) ), 403 );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'geolang-multilingual' ) ) );
		}

		global $wpdb;
		$deleted = $wpdb->delete( GeoLang_Core::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Database error.', 'geolang-multilingual' ) ) );
		}

		wp_send_json_success();
	}

	// -----------------------------------------------------------------------
	// geolang_get_field_keys
	// -----------------------------------------------------------------------

	public function get_field_keys() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'geolang-multilingual' ) ), 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'geolang-multilingual' ) ) );
		}

		$keys = GeoLang_Core::get_field_keys( $post_id );
		wp_send_json_success( array( 'keys' => $keys ) );
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	private function sanitize_field_key( $key ) {
		$key = strtolower( sanitize_text_field( $key ) );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', $key );
		return substr( $key, 0, 100 );
	}

	private function sanitize_field_type( $type ) {
		return in_array( $type, array( 'text', 'url', 'image' ), true ) ? $type : 'text';
	}

	/**
	 * Basic rate limiting using transients.
	 *
	 * @param int    $user_id
	 * @param string $action
	 * @param int    $limit   Max hits per window.
	 * @param int    $window  Seconds.
	 * @return bool True if within limit.
	 */
	private function check_rate_limit( $user_id, $action, $limit, $window ) {
		$key   = 'geolang_rl_' . $action . '_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		if ( 0 === $count ) {
			set_transient( $key, 1, $window );
		} else {
			set_transient( $key, $count + 1, $window );
		}

		return true;
	}

	private function get_row_id( $post_id, $field_key ) {
		global $wpdb;
		$table = GeoLang_Core::table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE post_id = %d AND field_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				$field_key
			)
		);
	}
}
