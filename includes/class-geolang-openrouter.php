<?php
defined( 'ABSPATH' ) || exit;

/**
 * GeoLang – OpenRouter AI translation integration.
 *
 * Provides automatic translation via OpenRouter's chat completion API.
 * API key is stored in the geolang_openrouter_key option (never hardcoded).
 *
 * AJAX endpoints:
 *   geolang_ai_translate_field  — translate a single field (missing langs)
 *   geolang_ai_translate_batch  — translate all incomplete fields for a post
 *   geolang_ai_test_connection  — verify API key is valid
 */
class GeoLang_OpenRouter {

	/** OpenRouter chat completions endpoint. */
	const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

	/**
	 * Available models shown in the settings select.
	 * Ordered cheapest-first. Prices are approximate per 1M tokens (input/output).
	 */
	public static function free_models() {
		return array(
			'openai/gpt-4o-mini'              => 'GPT-4o Mini — $0.15 / $0.60 ✓ Recomendado',
			'openai/gpt-4.1-nano'             => 'GPT-4.1 Nano — $0.10 / $0.40 (mais barato)',
			'openai/gpt-4.1-mini'             => 'GPT-4.1 Mini — $0.40 / $1.60',
			'openai/gpt-3.5-turbo'            => 'GPT-3.5 Turbo — $0.50 / $1.50',
			'openai/gpt-4o'                   => 'GPT-4o — $2.50 / $10.00 (premium)',
			'mistralai/mistral-7b-instruct'   => 'Mistral 7B Instruct — $0.055 / $0.055',
			'mistralai/mistral-small'         => 'Mistral Small — $0.20 / $0.60',
		);
	}

	public function __construct() {
		add_action( 'wp_ajax_geolang_ai_translate_field',    array( $this, 'ajax_translate_field' ) );
		add_action( 'wp_ajax_geolang_ai_translate_batch',    array( $this, 'ajax_translate_batch' ) );
		add_action( 'wp_ajax_geolang_ai_translate_selected', array( $this, 'ajax_translate_selected' ) );
		add_action( 'wp_ajax_geolang_ai_test_connection',    array( $this, 'ajax_test_connection' ) );
	}

	// -----------------------------------------------------------------------
	// Public static translate method
	// -----------------------------------------------------------------------

	/**
	 * Translate a string using OpenRouter.
	 *
	 * @param string $text       Source text (may contain HTML).
	 * @param string $src_lang   'pt' | 'en' | 'es'
	 * @param string $tgt_lang   'pt' | 'en' | 'es'
	 * @param string $field_type 'text' | 'url' | 'image'
	 * @return string|\WP_Error  Translated text or WP_Error on failure.
	 */
	public static function translate( $text, $src_lang, $tgt_lang, $field_type = 'text' ) {
		// URL and image fields are not translated.
		if ( in_array( $field_type, array( 'url', 'image' ), true ) ) {
			return $text;
		}

		if ( '' === trim( $text ) ) {
			return '';
		}

		$api_key = get_option( 'geolang_openrouter_key', '' );
		if ( ! $api_key ) {
			return new WP_Error( 'no_api_key', __( 'Chave de API OpenRouter não configurada. Acesse GeoLang → Configurações.', 'geolang-multilingual' ) );
		}

		$model = get_option( 'geolang_openrouter_model', 'openai/gpt-4o-mini' );

		$lang_names = array(
			'pt' => 'Brazilian Portuguese',
			'en' => 'English',
			'es' => 'Spanish',
		);

		$src_name = $lang_names[ $src_lang ] ?? $src_lang;
		$tgt_name = $lang_names[ $tgt_lang ] ?? $tgt_lang;

		// Detect if content has HTML tags.
		$has_html = ( $text !== strip_tags( $text ) );

		$system_prompt = 'You are a professional translator. Translate accurately from ' . $src_name . ' to ' . $tgt_name . '.';

		if ( $has_html ) {
			$system_prompt .= "\nIMPORTANT: The content contains HTML. Preserve ALL HTML tags, attributes, class names, and URLs exactly. Only translate the visible text between tags. Return ONLY the translated content with no explanation.";
		} else {
			$system_prompt .= "\nReturn ONLY the translated text. No explanations, no extra text.";
		}

		$body = wp_json_encode(
			array(
				'model'    => $model,
				'messages' => array(
					array( 'role' => 'system', 'content' => $system_prompt ),
					array( 'role' => 'user',   'content' => $text ),
				),
				'max_tokens'  => 2000,
				'temperature' => 0.3,
			)
		);

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'HTTP-Referer'  => get_site_url(),
					'X-Title'       => 'GeoLang Multilingual',
				),
				'body' => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code !== 200 ) {
			$api_msg  = $json['error']['message'] ?? '';
			$api_code = $json['error']['code']    ?? '';
			if ( $api_msg ) {
				$msg = '[' . $model . '] HTTP ' . $code . ': ' . $api_msg;
				if ( $api_code ) {
					$msg .= ' (code: ' . $api_code . ')';
				}
			} else {
				$msg = '[' . $model . '] HTTP ' . $code . ' — ' . wp_remote_retrieve_response_message( $response );
			}
			return new WP_Error( 'api_error', $msg );
		}

		$translated = $json['choices'][0]['message']['content'] ?? '';
		if ( '' === $translated ) {
			return new WP_Error( 'empty_response', __( 'A API retornou resposta vazia.', 'geolang-multilingual' ) );
		}

		return trim( $translated );
	}

	// -----------------------------------------------------------------------
	// AJAX: translate single field
	// -----------------------------------------------------------------------

	public function ajax_translate_field() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'geolang-multilingual' ) ), 403 );
		}

		$post_id    = absint( $_POST['post_id']    ?? 0 );
		$field_key  = sanitize_text_field( wp_unslash( $_POST['field_key']  ?? '' ) );
		$field_type = sanitize_text_field( wp_unslash( $_POST['field_type'] ?? 'text' ) );
		$lang_pt    = wp_kses_post( wp_unslash( $_POST['lang_pt'] ?? '' ) );
		$lang_en    = wp_kses_post( wp_unslash( $_POST['lang_en'] ?? '' ) );
		$lang_es    = wp_kses_post( wp_unslash( $_POST['lang_es'] ?? '' ) );

		if ( ! $post_id || ! $field_key ) {
			wp_send_json_error( array( 'message' => 'Parâmetros inválidos.' ) );
		}

		// URL / image: no translation needed.
		if ( in_array( $field_type, array( 'url', 'image' ), true ) ) {
			wp_send_json_success( array( 'lang_pt' => $lang_pt, 'lang_en' => $lang_en, 'lang_es' => $lang_es, 'skipped' => true ) );
		}

		$default_lang = get_option( 'geolang_default_lang', 'pt' );
		$source_text  = $lang_pt; // Always translate from PT (source language).

		if ( '' === trim( $source_text ) ) {
			wp_send_json_error( array( 'message' => 'Texto PT está vazio — sem conteúdo para traduzir.' ) );
		}

		$result = array( 'lang_pt' => $lang_pt, 'lang_en' => $lang_en, 'lang_es' => $lang_es );
		$errors = array();

		// Only translate missing languages.
		if ( '' === trim( $lang_en ) ) {
			$translated = self::translate( $source_text, 'pt', 'en', $field_type );
			if ( is_wp_error( $translated ) ) {
				$errors[] = 'EN: ' . $translated->get_error_message();
			} else {
				$result['lang_en'] = $translated;
			}
		}

		if ( '' === trim( $lang_es ) ) {
			$translated = self::translate( $source_text, 'pt', 'es', $field_type );
			if ( is_wp_error( $translated ) ) {
				$errors[] = 'ES: ' . $translated->get_error_message();
			} else {
				$result['lang_es'] = $translated;
			}
		}

		// Persist translations to DB.
		global $wpdb;
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			GeoLang_Core::table(),
			array(
				'post_id'    => $post_id,
				'field_key'  => $field_key,
				'field_type' => $field_type,
				'lang_pt'    => wp_kses_post( $result['lang_pt'] ),
				'lang_en'    => wp_kses_post( $result['lang_en'] ),
				'lang_es'    => wp_kses_post( $result['lang_es'] ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $errors ) {
			wp_send_json_error( array( 'message' => implode( ' | ', $errors ), 'partial' => $result ) );
		}

		wp_send_json_success( $result );
	}

	// -----------------------------------------------------------------------
	// AJAX: batch translate all incomplete fields for a post
	// -----------------------------------------------------------------------

	public function ajax_translate_batch() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'geolang-multilingual' ) ), 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );

		global $wpdb;
		$table = GeoLang_Core::table();

		// Fetch all fields for this post that are missing EN or ES (text type only).
		if ( $post_id ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE post_id = %d AND field_type = 'text' AND (lang_en = '' OR lang_es = '')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$post_id
				)
			);
		} else {
			// Batch across all posts.
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT * FROM {$table} WHERE field_type = 'text' AND (lang_en = '' OR lang_es = '') LIMIT 50" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		$processed = 0;
		$errors    = array();

		foreach ( $rows as $row ) {
			if ( '' === trim( $row->lang_pt ) ) {
				continue;
			}

			$en = $row->lang_en;
			$es = $row->lang_es;

			if ( '' === trim( $en ) ) {
				$t = self::translate( $row->lang_pt, 'pt', 'en', 'text' );
				if ( is_wp_error( $t ) ) {
					$errors[] = $row->field_key . ' EN: ' . $t->get_error_message();
					continue;
				}
				$en = $t;
			}

			if ( '' === trim( $es ) ) {
				$t = self::translate( $row->lang_pt, 'pt', 'es', 'text' );
				if ( is_wp_error( $t ) ) {
					$errors[] = $row->field_key . ' ES: ' . $t->get_error_message();
					continue;
				}
				$es = $t;
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array( 'lang_en' => wp_kses_post( $en ), 'lang_es' => wp_kses_post( $es ) ),
				array( 'id' => $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$processed++;
		}

		wp_send_json_success(
			array(
				'processed' => $processed,
				'errors'    => $errors,
			)
		);
	}

	// -----------------------------------------------------------------------
	// AJAX: translate specific rows by DB id
	// -----------------------------------------------------------------------

	public function ajax_translate_selected() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'geolang-multilingual' ) ), 403 );
		}

		$raw_ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] )
			? array_map( 'absint', $_POST['ids'] )
			: array();

		if ( empty( $raw_ids ) ) {
			wp_send_json_error( array( 'message' => 'Nenhum ID informado.' ) );
		}

		global $wpdb;
		$table = GeoLang_Core::table();

		$placeholders = implode( ',', array_fill( 0, count( $raw_ids ), '%d' ) );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id IN ({$placeholders}) AND field_type = 'text'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$raw_ids
			)
		);

		$processed = 0;
		$errors    = array();
		$results   = array(); // id => {lang_en, lang_es}

		foreach ( $rows as $row ) {
			if ( '' === trim( $row->lang_pt ) ) {
				continue;
			}

			$en = $row->lang_en;
			$es = $row->lang_es;

			if ( '' === trim( $en ) ) {
				$t = self::translate( $row->lang_pt, 'pt', 'en', 'text' );
				if ( is_wp_error( $t ) ) {
					$errors[] = '#' . $row->id . ' EN: ' . $t->get_error_message();
					continue;
				}
				$en = $t;
			}

			if ( '' === trim( $es ) ) {
				$t = self::translate( $row->lang_pt, 'pt', 'es', 'text' );
				if ( is_wp_error( $t ) ) {
					$errors[] = '#' . $row->id . ' ES: ' . $t->get_error_message();
					continue;
				}
				$es = $t;
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array( 'lang_en' => wp_kses_post( $en ), 'lang_es' => wp_kses_post( $es ) ),
				array( 'id' => $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$results[ $row->id ] = array( 'lang_en' => $en, 'lang_es' => $es );
			$processed++;
		}

		wp_send_json_success(
			array(
				'processed' => $processed,
				'results'   => $results,
				'errors'    => $errors,
			)
		);
	}

	// -----------------------------------------------------------------------
	// AJAX: test connection
	// -----------------------------------------------------------------------

	public function ajax_test_connection() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'geolang-multilingual' ) ), 403 );
		}

		$result = self::translate( 'Hello, world!', 'en', 'pt', 'text' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'sample' => $result ) );
	}
}
