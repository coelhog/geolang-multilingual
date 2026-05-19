<?php
defined( 'ABSPATH' ) || exit;

/**
 * GeoLang – WooCommerce taxonomy term translation.
 *
 * Translates product_cat and product_tag term names AND product_cat thumbnail
 * images using WordPress term_meta.
 *
 * Name meta keys  : geolang_name_en,  geolang_name_es  (PT is the native WP name)
 * Image meta keys : geolang_image_en, geolang_image_es (attachment IDs; PT uses native thumbnail_id)
 *
 * Admin UI: fields are added to the category/tag edit screens via hooks.
 * Frontend: term names and thumbnail_id are swapped via filters when lang ≠ default.
 */
class GeoLang_WC_Terms {

	/** Taxonomies we translate. */
	private static $taxonomies = array( 'product_cat', 'product_tag' );

	public function __construct() {
		// Frontend: replace term names and category thumbnails when lang ≠ default.
		add_filter( 'get_term',          array( $this, 'filter_term' ),         10, 2 );
		add_filter( 'get_term_metadata', array( $this, 'filter_thumbnail_id' ), 10, 4 );

		// Admin: enqueue media uploader on taxonomy edit screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Admin: edit / add form fields.
		foreach ( self::$taxonomies as $tax ) {
			add_action( $tax . '_edit_form_fields', array( $this, 'render_edit_fields' ), 10, 1 );
			add_action( 'edited_' . $tax,           array( $this, 'save_term_meta' ),     10, 1 );
			add_action( $tax . '_add_form_fields',  array( $this, 'render_add_fields' ),  10, 1 );
			add_action( 'created_' . $tax,          array( $this, 'save_term_meta' ),     10, 1 );
		}
	}

	// -----------------------------------------------------------------------
	// Admin: enqueue media uploader
	// -----------------------------------------------------------------------

	/**
	 * Enqueue wp.media on product category / tag edit screens.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only on term.php (edit existing) or edit-tags.php (list + add new).
		if ( ! in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) ) {
			return;
		}

		$tax = isset( $_GET['taxonomy'] ) ? sanitize_key( $_GET['taxonomy'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tax, self::$taxonomies, true ) ) {
			return;
		}

		wp_enqueue_media();

		// Tiny inline script: opens a wp.media frame and stores the chosen
		// attachment ID + preview URL in the hidden input / <img> placeholder.
		$script = <<<'JS'
(function($){
	$(document).on('click', '.geolang-img-set', function(e){
		e.preventDefault();
		var lang    = $(this).data('lang');
		var $input  = $('#geolang_image_' + lang);
		var $img    = $('#geolang-img-preview-' + lang);
		var $wrap   = $('#geolang-img-wrap-' + lang);
		var $remove = $('#geolang-img-remove-' + lang);

		var frame = wp.media({
			title: $(this).data('title') || 'Selecionar imagem',
			button: { text: 'Usar esta imagem' },
			multiple: false,
			library: { type: 'image' }
		});

		frame.on('select', function(){
			var att = frame.state().get('selection').first().toJSON();
			$input.val(att.id);
			var src = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
			if ($img.length) {
				$img.attr('src', src).show();
			} else {
				$wrap.html('<img id="geolang-img-preview-' + lang + '" src="' + src + '" style="max-width:150px;height:auto;display:block;margin-bottom:8px;" />');
			}
			$remove.show();
		});

		frame.open();
	});

	$(document).on('click', '.geolang-img-remove', function(e){
		e.preventDefault();
		var lang = $(this).data('lang');
		$('#geolang_image_' + lang).val('');
		$('#geolang-img-preview-' + lang).remove();
		$(this).hide();
	});
})(jQuery);
JS;

		wp_add_inline_script( 'jquery', $script );
	}

	// -----------------------------------------------------------------------
	// Frontend filter — term name
	// -----------------------------------------------------------------------

	/**
	 * Replace term name when not on default language.
	 *
	 * @param \WP_Term|mixed $term
	 * @param string         $taxonomy
	 * @return mixed
	 */
	public function filter_term( $term, $taxonomy = '' ) {
		if ( is_admin() || ! is_a( $term, 'WP_Term' ) ) {
			return $term;
		}

		if ( ! in_array( $term->taxonomy, self::$taxonomies, true ) ) {
			return $term;
		}

		$lang    = GeoLang_Session::current();
		$default = get_option( 'geolang_default_lang', 'pt' );

		if ( $lang === $default ) {
			return $term;
		}

		$translated = get_term_meta( $term->term_id, 'geolang_name_' . $lang, true );
		if ( $translated ) {
			$term->name = $translated;
		}

		return $term;
	}

	// -----------------------------------------------------------------------
	// Frontend filter — category image (thumbnail_id)
	// -----------------------------------------------------------------------

	/**
	 * Swap the WooCommerce category thumbnail attachment ID when a non-default
	 * language is active and the user has set a translated image.
	 *
	 * WooCommerce reads thumbnail_id via get_term_meta( $term_id, 'thumbnail_id', true ).
	 * Returning a non-null value here short-circuits the normal meta lookup.
	 *
	 * No recursion risk: the inner get_term_meta() uses 'geolang_image_en/es',
	 * not 'thumbnail_id', so this filter does not re-trigger itself.
	 *
	 * @param mixed  $value    null (short-circuit value, always null when filter fires first)
	 * @param int    $term_id
	 * @param string $meta_key
	 * @param bool   $single
	 * @return mixed  Attachment ID string, array with ID, or null (proceed normally).
	 */
	public function filter_thumbnail_id( $value, $term_id, $meta_key, $single ) {
		if ( 'thumbnail_id' !== $meta_key || is_admin() ) {
			return $value;
		}

		$lang    = GeoLang_Session::current();
		$default = get_option( 'geolang_default_lang', 'pt' );

		if ( $lang === $default ) {
			return $value;
		}

		$translated_id = get_term_meta( $term_id, 'geolang_image_' . $lang, true );
		if ( ! $translated_id ) {
			return $value;
		}

		return $single ? (string) $translated_id : array( (string) $translated_id );
	}

	// -----------------------------------------------------------------------
	// Admin UI – edit existing term
	// -----------------------------------------------------------------------

	/**
	 * Renders EN / ES name + image translation fields on the "edit term" screen.
	 *
	 * @param \WP_Term $term
	 */
	public function render_edit_fields( $term ) {
		$en       = get_term_meta( $term->term_id, 'geolang_name_en',  true );
		$es       = get_term_meta( $term->term_id, 'geolang_name_es',  true );
		$img_en   = (int) get_term_meta( $term->term_id, 'geolang_image_en', true );
		$img_es   = (int) get_term_meta( $term->term_id, 'geolang_image_es', true );

		$src_en = $img_en ? wp_get_attachment_image_url( $img_en, 'thumbnail' ) : '';
		$src_es = $img_es ? wp_get_attachment_image_url( $img_es, 'thumbnail' ) : '';

		wp_nonce_field( 'geolang_term_save_' . $term->term_id, 'geolang_term_nonce' );
		?>

		<tr class="form-field">
			<th scope="row" colspan="2">
				<hr style="margin:8px 0;" />
				<strong style="font-size:13px;">🌐 <?php esc_html_e( 'GeoLang – Traduções', 'geolang-multilingual' ); ?></strong>
			</th>
		</tr>

		<!-- ── EN: nome ── -->
		<tr class="form-field">
			<th scope="row">
				<label>🇺🇸 <?php esc_html_e( 'Nome em Inglês (EN)', 'geolang-multilingual' ); ?></label>
			</th>
			<td>
				<input type="text" name="geolang_term_en" value="<?php echo esc_attr( $en ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Deixe em branco para usar o nome original.', 'geolang-multilingual' ); ?></p>
			</td>
		</tr>

		<!-- ── EN: imagem ── -->
		<tr class="form-field">
			<th scope="row">
				<label>🇺🇸 <?php esc_html_e( 'Imagem em Inglês (EN)', 'geolang-multilingual' ); ?></label>
			</th>
			<td>
				<input type="hidden" id="geolang_image_en" name="geolang_image_en" value="<?php echo esc_attr( $img_en ?: '' ); ?>" />
				<div id="geolang-img-wrap-en">
					<?php if ( $src_en ) : ?>
						<img id="geolang-img-preview-en" src="<?php echo esc_url( $src_en ); ?>"
							style="max-width:150px;height:auto;display:block;margin-bottom:8px;" />
					<?php endif; ?>
				</div>
				<button type="button" class="button geolang-img-set" data-lang="en"
					data-title="<?php esc_attr_e( 'Imagem EN – selecionar', 'geolang-multilingual' ); ?>">
					<?php esc_html_e( $src_en ? '🔄 Trocar imagem EN' : '📷 Definir imagem EN', 'geolang-multilingual' ); ?>
				</button>
				<button type="button" class="button geolang-img-remove" data-lang="en"
					id="geolang-img-remove-en" <?php echo $img_en ? '' : 'style="display:none"'; ?>>
					<?php esc_html_e( '✕ Remover', 'geolang-multilingual' ); ?>
				</button>
				<p class="description"><?php esc_html_e( 'Deixe em branco para usar a imagem padrão da categoria.', 'geolang-multilingual' ); ?></p>
			</td>
		</tr>

		<!-- ── ES: nome ── -->
		<tr class="form-field">
			<th scope="row">
				<label>🇪🇸 <?php esc_html_e( 'Nome em Espanhol (ES)', 'geolang-multilingual' ); ?></label>
			</th>
			<td>
				<input type="text" name="geolang_term_es" value="<?php echo esc_attr( $es ); ?>" class="regular-text" />
			</td>
		</tr>

		<!-- ── ES: imagem ── -->
		<tr class="form-field">
			<th scope="row">
				<label>🇪🇸 <?php esc_html_e( 'Imagem em Espanhol (ES)', 'geolang-multilingual' ); ?></label>
			</th>
			<td>
				<input type="hidden" id="geolang_image_es" name="geolang_image_es" value="<?php echo esc_attr( $img_es ?: '' ); ?>" />
				<div id="geolang-img-wrap-es">
					<?php if ( $src_es ) : ?>
						<img id="geolang-img-preview-es" src="<?php echo esc_url( $src_es ); ?>"
							style="max-width:150px;height:auto;display:block;margin-bottom:8px;" />
					<?php endif; ?>
				</div>
				<button type="button" class="button geolang-img-set" data-lang="es"
					data-title="<?php esc_attr_e( 'Imagem ES – selecionar', 'geolang-multilingual' ); ?>">
					<?php esc_html_e( $src_es ? '🔄 Trocar imagem ES' : '📷 Definir imagem ES', 'geolang-multilingual' ); ?>
				</button>
				<button type="button" class="button geolang-img-remove" data-lang="es"
					id="geolang-img-remove-es" <?php echo $img_es ? '' : 'style="display:none"'; ?>>
					<?php esc_html_e( '✕ Remover', 'geolang-multilingual' ); ?>
				</button>
				<p class="description"><?php esc_html_e( 'Deixe em branco para usar a imagem padrão da categoria.', 'geolang-multilingual' ); ?></p>
			</td>
		</tr>
		<?php
	}

	// -----------------------------------------------------------------------
	// Admin UI – add new term
	// -----------------------------------------------------------------------

	public function render_add_fields() {
		wp_nonce_field( 'geolang_term_add', 'geolang_term_nonce' );
		?>
		<div class="form-field">
			<hr style="margin:4px 0 12px;" />
			<strong>🌐 <?php esc_html_e( 'GeoLang – Traduções', 'geolang-multilingual' ); ?></strong>
		</div>

		<!-- ── EN ── -->
		<div class="form-field">
			<label>🇺🇸 <?php esc_html_e( 'Nome em Inglês (EN)', 'geolang-multilingual' ); ?></label>
			<input type="text" name="geolang_term_en" value="" class="regular-text" />
		</div>
		<div class="form-field">
			<label>🇺🇸 <?php esc_html_e( 'Imagem em Inglês (EN)', 'geolang-multilingual' ); ?></label>
			<input type="hidden" id="geolang_image_en" name="geolang_image_en" value="" />
			<div id="geolang-img-wrap-en"></div>
			<button type="button" class="button geolang-img-set" data-lang="en">
				<?php esc_html_e( '📷 Definir imagem EN', 'geolang-multilingual' ); ?>
			</button>
			<button type="button" class="button geolang-img-remove" data-lang="en"
				id="geolang-img-remove-en" style="display:none">
				<?php esc_html_e( '✕ Remover', 'geolang-multilingual' ); ?>
			</button>
		</div>

		<!-- ── ES ── -->
		<div class="form-field">
			<label>🇪🇸 <?php esc_html_e( 'Nome em Espanhol (ES)', 'geolang-multilingual' ); ?></label>
			<input type="text" name="geolang_term_es" value="" class="regular-text" />
		</div>
		<div class="form-field">
			<label>🇪🇸 <?php esc_html_e( 'Imagem em Espanhol (ES)', 'geolang-multilingual' ); ?></label>
			<input type="hidden" id="geolang_image_es" name="geolang_image_es" value="" />
			<div id="geolang-img-wrap-es"></div>
			<button type="button" class="button geolang-img-set" data-lang="es">
				<?php esc_html_e( '📷 Definir imagem ES', 'geolang-multilingual' ); ?>
			</button>
			<button type="button" class="button geolang-img-remove" data-lang="es"
				id="geolang-img-remove-es" style="display:none">
				<?php esc_html_e( '✕ Remover', 'geolang-multilingual' ); ?>
			</button>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Save term meta
	// -----------------------------------------------------------------------

	/**
	 * Saves EN / ES term name and image translations on term create/update.
	 *
	 * @param int $term_id
	 */
	public function save_term_meta( $term_id ) {
		if ( ! isset( $_POST['geolang_term_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_key( wp_unslash( $_POST['geolang_term_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'geolang_term_save_' . $term_id )
			&& ! wp_verify_nonce( $nonce, 'geolang_term_add' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// ── Names ────────────────────────────────────────────────────────────
		$en = sanitize_text_field( wp_unslash( $_POST['geolang_term_en'] ?? '' ) );
		$es = sanitize_text_field( wp_unslash( $_POST['geolang_term_es'] ?? '' ) );

		if ( $en ) {
			update_term_meta( $term_id, 'geolang_name_en', $en );
		} else {
			delete_term_meta( $term_id, 'geolang_name_en' );
		}

		if ( $es ) {
			update_term_meta( $term_id, 'geolang_name_es', $es );
		} else {
			delete_term_meta( $term_id, 'geolang_name_es' );
		}

		// ── Images ───────────────────────────────────────────────────────────
		$img_en = absint( $_POST['geolang_image_en'] ?? 0 );
		$img_es = absint( $_POST['geolang_image_es'] ?? 0 );

		// Only save if the attachment actually belongs to this site.
		if ( $img_en && get_post( $img_en ) ) {
			update_term_meta( $term_id, 'geolang_image_en', $img_en );
		} else {
			delete_term_meta( $term_id, 'geolang_image_en' );
		}

		if ( $img_es && get_post( $img_es ) ) {
			update_term_meta( $term_id, 'geolang_image_es', $img_es );
		} else {
			delete_term_meta( $term_id, 'geolang_image_es' );
		}
	}
}
