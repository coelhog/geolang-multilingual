<?php
defined( 'ABSPATH' ) || exit;

/**
 * Meta box shown in all post types.
 * Allows adding/editing GeoLang translation fields directly from the WP post editor
 * — works for posts, pages, WooCommerce products, CPTs, anything.
 */
class GeoLang_Metabox {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_geolang_save_metabox', array( $this, 'ajax_save' ) );
	}

	/**
	 * AJAX save — called by the "Salvar Traduções" button.
	 * Works in both classic editor and Gutenberg.
	 */
	public function ajax_save() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'geolang-multilingual' ) ), 403 );
		}

		$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] )
			? wp_unslash( $_POST['fields'] )
			: array();

		global $wpdb;
		$table     = GeoLang_Core::table();
		$seen_keys = array();

		foreach ( $fields as $row ) {
			$field_key  = strtolower( sanitize_text_field( $row['field_key'] ?? '' ) );
			$field_key  = preg_replace( '/[^a-z0-9_\-]/', '', $field_key );
			$field_key  = substr( $field_key, 0, 100 );
			$field_type = in_array( $row['field_type'] ?? '', array( 'text', 'url', 'image' ), true )
				? $row['field_type'] : 'text';

			if ( ! $field_key || in_array( $field_key, $seen_keys, true ) ) {
				continue;
			}

			$seen_keys[] = $field_key;

			$lang_pt = wp_kses_post( $row['lang_pt'] ?? '' );
			$lang_en = wp_kses_post( $row['lang_en'] ?? '' );
			$lang_es = wp_kses_post( $row['lang_es'] ?? '' );

			if ( 'url' === $field_type ) {
				$lang_pt = esc_url_raw( $lang_pt );
				$lang_en = esc_url_raw( $lang_en );
				$lang_es = esc_url_raw( $lang_es );
			}

			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
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
		}

		// Remove keys deleted in the UI.
		if ( ! empty( $seen_keys ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $seen_keys ), '%s' ) );
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE post_id = %d AND field_key NOT IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( array( $post_id ), $seen_keys )
				)
			);
		} else {
			$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		wp_send_json_success( array( 'count' => count( $seen_keys ) ) );
	}

	// -----------------------------------------------------------------------
	// Register on all public post types
	// -----------------------------------------------------------------------

	public function register() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		foreach ( $post_types as $type ) {
			add_meta_box(
				'geolang_translations',
				__( '🌐 GeoLang – Traduções', 'geolang-multilingual' ),
				array( $this, 'render' ),
				$type,
				'normal',
				'default'
			);
		}
	}

	// -----------------------------------------------------------------------
	// Render meta box
	// -----------------------------------------------------------------------

	public function render( $post ) {
		wp_nonce_field( 'geolang_metabox_save', 'geolang_metabox_nonce' );

		global $wpdb;
		$table = GeoLang_Core::table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d ORDER BY field_key ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post->ID
			)
		);

		// Filter out WC reserved keys from custom-fields list to avoid duplicates.
		$wc_keys     = array( '_wc_title', '_wc_description', '_wc_short_desc' );
		$custom_rows = $rows ? array_filter( $rows, function ( $r ) use ( $wc_keys ) {
			return ! in_array( $r->field_key, $wc_keys, true );
		} ) : array();

		$is_product = ( get_post_type( $post->ID ) === 'product' );

		?>
		<div class="geolang-mb">
			<?php if ( $is_product ) : ?>
				<?php $this->render_wc_section( $post ); ?>
			<?php else : ?>
			<p class="geolang-mb__intro">
				<?php esc_html_e( 'Adicione campos traduzíveis para este post. Eles ficam disponíveis como Dynamic Tags no Elementor e via shortcode.', 'geolang-multilingual' ); ?>
			</p>
			<?php endif; ?>

			<?php if ( $is_product ) : ?>
			<div class="geolang-mb__section-title">
				<?php esc_html_e( '✏️ Campos personalizados adicionais', 'geolang-multilingual' ); ?>
			</div>
			<?php endif; ?>

			<div id="geolang-mb-fields">
				<?php foreach ( $custom_rows as $row ) : ?>
					<?php $this->render_row( $row->id, $row->field_key, $row->field_type, $row->lang_pt, $row->lang_en, $row->lang_es ); ?>
				<?php endforeach; ?>
			</div>

			<div class="geolang-mb__actions">
				<button type="button" class="button geolang-mb-add-row" id="geolang-mb-add">
					+ <?php esc_html_e( 'Adicionar campo', 'geolang-multilingual' ); ?>
				</button>
				<button type="button" class="button button-primary" id="geolang-mb-save" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( '💾 Salvar Traduções', 'geolang-multilingual' ); ?>
				</button>
				<span id="geolang-mb-status" style="display:none;margin-left:8px;font-style:italic;color:#646970;"></span>
			</div>

			<!-- Row template (hidden, cloned by JS) -->
			<script type="text/html" id="geolang-mb-template">
				<?php $this->render_row( '', '', 'text', '', '', '', true ); ?>
			</script>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// WooCommerce dedicated section
	// -----------------------------------------------------------------------

	/**
	 * Renders the fixed WooCommerce fields section (title, long desc, short desc).
	 * Only called when the current post type is 'product'.
	 *
	 * @param WP_Post $post
	 */
	private function render_wc_section( $post ) {
		global $wpdb;
		$table = GeoLang_Core::table();

		// Fetch any previously saved translations for the three reserved keys.
		$saved = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT field_key, lang_pt, lang_en, lang_es FROM {$table} WHERE post_id = %d AND field_key IN ('_wc_title','_wc_description','_wc_short_desc')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post->ID
			),
			OBJECT_K
		);

		// Helper to get saved value or fall back to current WP content.
		$val = function ( $key, $lang, $wp_fallback ) use ( $saved ) {
			if ( isset( $saved[ $key ] ) ) {
				$col = 'lang_' . $lang;
				return $saved[ $key ]->$col ?? '';
			}
			return ( 'pt' === $lang ) ? $wp_fallback : '';
		};

		$wc_fields = array(
			'_wc_title'       => array(
				'label'    => __( '📝 Título do produto', 'geolang-multilingual' ),
				'pt_raw'   => get_the_title( $post->ID ),
				'rows'     => 2,
				'html_note'=> false,
			),
			'_wc_description' => array(
				'label'    => __( '📄 Descrição longa', 'geolang-multilingual' ),
				'pt_raw'   => get_post_field( 'post_content', $post->ID ),
				'rows'     => 6,
				'html_note'=> true,
			),
			'_wc_short_desc'  => array(
				'label'    => __( '📋 Descrição curta', 'geolang-multilingual' ),
				'pt_raw'   => get_post_field( 'post_excerpt', $post->ID ),
				'rows'     => 4,
				'html_note'=> true,
			),
		);
		?>
		<div class="geolang-mb__wc-section">
			<div class="geolang-mb__wc-header">
				🛍️ <?php esc_html_e( 'Campos padrão do produto (WooCommerce)', 'geolang-multilingual' ); ?>
			</div>

			<div id="geolang-wc-fields">
				<?php foreach ( $wc_fields as $key => $cfg ) :
					$idx = esc_attr( 'wc_' . ltrim( $key, '_' ) );
					$pt  = $val( $key, 'pt', $cfg['pt_raw'] );
					$en  = $val( $key, 'en', '' );
					$es  = $val( $key, 'es', '' );
				?>
				<div class="geolang-mb-row geolang-mb-row--wc" data-wc-key="<?php echo esc_attr( $key ); ?>">
					<div class="geolang-mb-row__header geolang-mb-row__header--wc">
						<strong class="geolang-mb-row__wc-label"><?php echo esc_html( $cfg['label'] ); ?></strong>
						<span class="geolang-mb-row__wc-key-badge"><?php echo esc_html( $key ); ?></span>
						<!-- Hidden inputs carry the key and type for the JS save handler -->
						<input type="hidden" name="geolang_fields[<?php echo $idx; ?>][field_key]"  value="<?php echo esc_attr( $key ); ?>" />
						<input type="hidden" name="geolang_fields[<?php echo $idx; ?>][field_type]" value="text" />
					</div>

					<div class="geolang-mb-row__langs">
						<div class="geolang-mb-lang geolang-mb-lang--wc-pt">
							<label>🇧🇷 PT/BR <span class="geolang-mb-lang__ref">(<?php esc_html_e( 'referência', 'geolang-multilingual' ); ?>)</span></label>
							<textarea name="geolang_fields[<?php echo $idx; ?>][lang_pt]"
									rows="<?php echo esc_attr( $cfg['rows'] ); ?>"><?php echo esc_textarea( $pt ); ?></textarea>
							<?php if ( $cfg['html_note'] ) : ?>
							<span class="geolang-mb-lang__note"><?php esc_html_e( 'Aceita HTML', 'geolang-multilingual' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="geolang-mb-lang">
							<label>🇺🇸 EN</label>
							<textarea name="geolang_fields[<?php echo $idx; ?>][lang_en]"
									rows="<?php echo esc_attr( $cfg['rows'] ); ?>"><?php echo esc_textarea( $en ); ?></textarea>
							<?php if ( $cfg['html_note'] ) : ?>
							<span class="geolang-mb-lang__note"><?php esc_html_e( 'Aceita HTML', 'geolang-multilingual' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="geolang-mb-lang">
							<label>🇪🇸 ES</label>
							<textarea name="geolang_fields[<?php echo $idx; ?>][lang_es]"
									rows="<?php echo esc_attr( $cfg['rows'] ); ?>"><?php echo esc_textarea( $es ); ?></textarea>
							<?php if ( $cfg['html_note'] ) : ?>
							<span class="geolang-mb-lang__note"><?php esc_html_e( 'Aceita HTML', 'geolang-multilingual' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a single translation row.
	 *
	 * @param mixed  $id        DB row id (empty for new rows).
	 * @param string $field_key
	 * @param string $field_type text|url|image
	 * @param string $pt
	 * @param string $en
	 * @param string $es
	 * @param bool   $is_template Whether this is the hidden template row.
	 */
	private function render_row( $id, $field_key, $field_type, $pt, $en, $es, $is_template = false ) {
		$idx = $is_template ? '{{INDEX}}' : esc_attr( $id ?: 'new_' . uniqid() );
		?>
		<div class="geolang-mb-row" data-id="<?php echo esc_attr( $idx ); ?>">
			<div class="geolang-mb-row__header">
				<div class="geolang-mb-row__key">
					<label><?php esc_html_e( 'Key', 'geolang-multilingual' ); ?></label>
					<input type="text"
						name="geolang_fields[<?php echo esc_attr( $idx ); ?>][field_key]"
						value="<?php echo esc_attr( $field_key ); ?>"
						placeholder="ex: titulo_principal"
						class="geolang-mb-key-input"
						pattern="[a-z0-9_\-]+"
						title="<?php esc_attr_e( 'Apenas letras minúsculas, números, _ e -', 'geolang-multilingual' ); ?>" />
				</div>

				<div class="geolang-mb-row__type">
					<label><?php esc_html_e( 'Tipo', 'geolang-multilingual' ); ?></label>
					<select name="geolang_fields[<?php echo esc_attr( $idx ); ?>][field_type]">
						<option value="text"  <?php selected( $field_type, 'text' ); ?>><?php esc_html_e( 'Texto', 'geolang-multilingual' ); ?></option>
						<option value="url"   <?php selected( $field_type, 'url' ); ?>><?php esc_html_e( 'URL', 'geolang-multilingual' ); ?></option>
						<option value="image" <?php selected( $field_type, 'image' ); ?>><?php esc_html_e( 'Imagem (URL)', 'geolang-multilingual' ); ?></option>
					</select>
				</div>

				<button type="button" class="geolang-mb-remove" title="<?php esc_attr_e( 'Remover campo', 'geolang-multilingual' ); ?>">✕</button>
			</div>

			<div class="geolang-mb-row__langs">
				<div class="geolang-mb-lang">
					<label>🇧🇷 PT/BR</label>
					<textarea name="geolang_fields[<?php echo esc_attr( $idx ); ?>][lang_pt]" rows="2"><?php echo esc_textarea( $pt ); ?></textarea>
				</div>
				<div class="geolang-mb-lang">
					<label>🇺🇸 EN</label>
					<textarea name="geolang_fields[<?php echo esc_attr( $idx ); ?>][lang_en]" rows="2"><?php echo esc_textarea( $en ); ?></textarea>
				</div>
				<div class="geolang-mb-lang">
					<label>🇪🇸 ES</label>
					<textarea name="geolang_fields[<?php echo esc_attr( $idx ); ?>][lang_es]" rows="2"><?php echo esc_textarea( $es ); ?></textarea>
				</div>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Save
	// -----------------------------------------------------------------------

	public function save( $post_id, $post ) {
		// Nonce, autosave, capability checks.
		if ( ! isset( $_POST['geolang_metabox_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['geolang_metabox_nonce'] ), 'geolang_metabox_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = isset( $_POST['geolang_fields'] ) && is_array( $_POST['geolang_fields'] )
			? wp_unslash( $_POST['geolang_fields'] )
			: array();

		global $wpdb;
		$table       = GeoLang_Core::table();
		$seen_keys   = array();

		foreach ( $fields as $row ) {
			$field_key  = strtolower( sanitize_text_field( $row['field_key'] ?? '' ) );
			$field_key  = preg_replace( '/[^a-z0-9_\-]/', '', $field_key );
			$field_key  = substr( $field_key, 0, 100 );
			$field_type = in_array( $row['field_type'] ?? '', array( 'text', 'url', 'image' ), true )
				? $row['field_type'] : 'text';

			if ( ! $field_key || in_array( $field_key, $seen_keys, true ) ) {
				continue;
			}

			$seen_keys[] = $field_key;

			$lang_pt = wp_kses_post( $row['lang_pt'] ?? '' );
			$lang_en = wp_kses_post( $row['lang_en'] ?? '' );
			$lang_es = wp_kses_post( $row['lang_es'] ?? '' );

			if ( 'url' === $field_type ) {
				$lang_pt = esc_url_raw( $lang_pt );
				$lang_en = esc_url_raw( $lang_en );
				$lang_es = esc_url_raw( $lang_es );
			}

			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
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
		}

		// Remove keys that were deleted in the UI (no longer in $seen_keys).
		if ( ! empty( $seen_keys ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $seen_keys ), '%s' ) );
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE post_id = %d AND field_key NOT IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( array( $post_id ), $seen_keys )
				)
			);
		} else {
			// All fields were removed — delete everything for this post.
			$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	// -----------------------------------------------------------------------
	// Assets (only on post edit screens)
	// -----------------------------------------------------------------------

	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		// Ensure the admin stylesheet is available on post edit screens too.
		if ( ! wp_style_is( 'geolang-admin', 'enqueued' ) ) {
			wp_enqueue_style(
				'geolang-admin',
				GEOLANG_URL . 'assets/css/admin.css',
				array(),
				GEOLANG_VERSION
			);
		}

		// Ensure jQuery + our admin script are available.
		if ( ! wp_script_is( 'geolang-admin', 'enqueued' ) ) {
			wp_enqueue_script(
				'geolang-admin',
				GEOLANG_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				GEOLANG_VERSION,
				true
			);

			wp_localize_script(
				'geolang-admin',
				'GeoLangAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'geolang_admin' ),
					'i18n'    => array(
						'confirmDelete' => __( 'Tem certeza que deseja excluir este campo?', 'geolang-multilingual' ),
						'saving'        => __( 'Salvando…', 'geolang-multilingual' ),
						'saved'         => __( 'Salvo!', 'geolang-multilingual' ),
						'error'         => __( 'Erro ao salvar.', 'geolang-multilingual' ),
					),
				)
			);
		}

		wp_add_inline_style( 'geolang-admin', $this->inline_css() );
		wp_add_inline_script( 'geolang-admin', $this->inline_js(), 'after' );
	}

	private function inline_css() {
		return '
		.geolang-mb { padding: 4px 0; }
		.geolang-mb__intro { color: #646970; margin: 0 0 12px; }
		.geolang-mb__section-title { font-weight: 600; font-size: 13px; color: #1d2327; margin: 16px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #c3c4c7; }
		.geolang-mb-row { border: 1px solid #c3c4c7; border-radius: 4px; margin-bottom: 12px; overflow: hidden; }
		.geolang-mb-row__header { display: flex; align-items: flex-end; gap: 12px; padding: 10px 12px; background: #f6f7f7; flex-wrap: wrap; }
		.geolang-mb-row__key { flex: 2; min-width: 160px; }
		.geolang-mb-row__type { flex: 1; min-width: 100px; }
		.geolang-mb-row__key label,
		.geolang-mb-row__type label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; }
		.geolang-mb-key-input { width: 100%; }
		.geolang-mb-remove { margin-left: auto; background: none; border: none; cursor: pointer; color: #d63638; font-size: 1rem; padding: 4px 6px; line-height:1; }
		.geolang-mb-remove:hover { color: #8b0000; }
		.geolang-mb-row__langs { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0; }
		.geolang-mb-lang { padding: 10px 12px; border-top: 1px solid #e2e4e7; }
		.geolang-mb-lang:not(:last-child) { border-right: 1px solid #e2e4e7; }
		.geolang-mb-lang label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; }
		.geolang-mb-lang textarea { width: 100%; resize: vertical; }
		.geolang-mb-lang__note { display: block; font-size: 11px; color: #8c8f94; margin-top: 3px; }
		.geolang-mb-lang__ref { font-weight: 400; color: #8c8f94; font-size: 11px; }
		.geolang-mb-lang--wc-pt textarea { background: #f9f9f9; }
		.geolang-mb__actions { display: flex; align-items: center; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
		/* WooCommerce section */
		.geolang-mb__wc-section { margin-bottom: 16px; }
		.geolang-mb__wc-header { font-weight: 600; font-size: 13px; color: #7f54b3; background: #f6f3ff; border: 1px solid #d0c3f0; border-radius: 4px 4px 0 0; padding: 8px 12px; }
		.geolang-mb__wc-section .geolang-mb-row { border-top: none; border-radius: 0; margin-bottom: 0; border-bottom: none; }
		.geolang-mb__wc-section .geolang-mb-row:last-child { border-bottom: 1px solid #c3c4c7; border-radius: 0 0 4px 4px; }
		.geolang-mb-row__header--wc { background: #faf7ff; border-bottom: 1px solid #e2e4e7; align-items: center; }
		.geolang-mb-row__wc-label { font-size: 13px; color: #1d2327; }
		.geolang-mb-row__wc-key-badge { display: inline-block; background: #e8e0f7; color: #7f54b3; font-family: monospace; font-size: 11px; padding: 1px 6px; border-radius: 3px; margin-left: 8px; }
		@media (max-width: 782px) {
			.geolang-mb-row__langs { grid-template-columns: 1fr; }
			.geolang-mb-lang:not(:last-child) { border-right: none; border-bottom: 1px solid #e2e4e7; }
		}
		';
	}

	private function inline_js() {
		return "
		(function($) {
			var addBtn = document.getElementById('geolang-mb-add');
			if (!addBtn) return;

			var container = document.getElementById('geolang-mb-fields');
			var tmpl      = document.getElementById('geolang-mb-template');
			var saveBtn   = document.getElementById('geolang-mb-save');
			var status    = document.getElementById('geolang-mb-status');
			var mbRoot    = document.querySelector('.geolang-mb');

			// Add new row.
			addBtn.addEventListener('click', function() {
				var idx  = 'new_' + Date.now();
				var html = tmpl.innerHTML.replace(/\\{\\{INDEX\\}\\}/g, idx);
				var div  = document.createElement('div');
				div.innerHTML = html;
				container.appendChild(div.firstElementChild);
			});

			// Remove row (only for removable custom-field rows, not WC locked rows).
			container.addEventListener('click', function(e) {
				var btn = e.target.closest('.geolang-mb-remove');
				if (btn) btn.closest('.geolang-mb-row').remove();
			});

			// Auto-lowercase + sanitise key inputs.
			container.addEventListener('input', function(e) {
				if (e.target.classList.contains('geolang-mb-key-input')) {
					e.target.value = e.target.value.toLowerCase().replace(/[^a-z0-9_\\-]/g, '');
				}
			});

			// AJAX save button — collects rows from WC section AND custom fields section.
			// Works with both classic editor and Gutenberg.
			if (saveBtn) {
				saveBtn.addEventListener('click', function() {
					var postId = saveBtn.getAttribute('data-post-id');
					// Query ALL rows in the entire meta box (WC pinned + custom).
					var rows   = mbRoot.querySelectorAll('.geolang-mb-row');
					var fields = [];

					rows.forEach(function(row) {
						var keyEl  = row.querySelector('[name*=\"[field_key]\"]');
						var typeEl = row.querySelector('[name*=\"[field_type]\"]');
						var ptEl   = row.querySelector('[name*=\"[lang_pt]\"]');
						var enEl   = row.querySelector('[name*=\"[lang_en]\"]');
						var esEl   = row.querySelector('[name*=\"[lang_es]\"]');
						if (!keyEl || !typeEl || !ptEl || !enEl || !esEl) return;
						fields.push({
							field_key : keyEl.value,
							field_type: typeEl.value,
							lang_pt   : ptEl.value,
							lang_en   : enEl.value,
							lang_es   : esEl.value,
						});
					});

					saveBtn.disabled = true;
					status.style.display = 'inline';
					status.textContent = 'Salvando…';

					$.post(
						(window.GeoLangAdmin || {}).ajaxUrl || ajaxurl,
						{
							action : 'geolang_save_metabox',
							nonce  : (window.GeoLangAdmin || {}).nonce || '',
							post_id: postId,
							fields : fields,
						},
						function(res) {
							saveBtn.disabled = false;
							if (res.success) {
								status.textContent = '✅ Salvo! (' + res.data.count + ' campos)';
								setTimeout(function() { status.style.display = 'none'; }, 3000);
							} else {
								status.textContent = '❌ Erro ao salvar.';
							}
						}
					).fail(function() {
						saveBtn.disabled = false;
						status.textContent = '❌ Erro de rede.';
					});
				});
			}
		})(jQuery);
		";
	}
}
