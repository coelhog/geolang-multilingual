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
 * Admin UI: fields added to category/tag edit screens via hooks.
 *
 * Frontend image strategy:
 *   - We override woocommerce_subcategory_thumbnail to output <img class="geolang-image"
 *     data-lang-pt="..." data-lang-en="..." data-lang-es="..."> so that the existing
 *     frontend.js mechanism swaps src instantly on flag click — no reload needed.
 *   - get_term_metadata filter is kept as a fallback for any WC code that reads
 *     thumbnail_id directly (product widgets, REST API, etc.) — PHP-side swap only.
 */
class GeoLang_WC_Terms {

	/** Taxonomies we translate. */
	private static $taxonomies = array( 'product_cat', 'product_tag' );

	/**
	 * Prevents our get_term_metadata filter from firing when we intentionally
	 * read the raw PT thumbnail_id inside multilingual_subcategory_thumbnail().
	 *
	 * @var bool
	 */
	private static $bypass_thumbnail_filter = false;

	public function __construct() {
		// Frontend: translate term name.
		add_filter( 'get_term', array( $this, 'filter_term' ), 10, 2 );

		// Frontend: PHP-side fallback — replaces thumbnail_id for widgets, REST, etc.
		add_filter( 'get_term_metadata', array( $this, 'filter_thumbnail_id' ), 10, 4 );

		// Frontend: override WC's subcategory thumbnail with a geolang-image <img>.
		// Must run on 'wp' (after WC has registered its hooks, before templates render).
		add_action( 'wp', array( $this, 'setup_thumbnail_override' ) );

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
	// Frontend – thumbnail override (JS-swappable <img>)
	// -----------------------------------------------------------------------

	/**
	 * Replaces WooCommerce's default subcategory thumbnail action with ours.
	 * Called on 'wp' — after WC has registered its hooks, before any template renders.
	 */
	public function setup_thumbnail_override() {
		remove_action( 'woocommerce_before_subcategory_title', 'woocommerce_subcategory_thumbnail', 10 );
		add_action( 'woocommerce_before_subcategory_title', array( $this, 'multilingual_subcategory_thumbnail' ), 10 );
	}

	/**
	 * Outputs the category thumbnail with all 3 language versions as data attributes.
	 *
	 * If no multilingual images are configured, delegates to WooCommerce's default
	 * function so no visual difference occurs.
	 *
	 * The <img class="geolang-image"> pattern is already handled by frontend.js
	 * applyLang() — it reads data-lang-{lang} and sets img.src instantly.
	 *
	 * @param \WP_Term $category
	 */
	public function multilingual_subcategory_thumbnail( $category ) {
		$size = apply_filters( 'subcategory_archive_thumbnail_size', 'woocommerce_thumbnail' );

		// Read the PT thumbnail bypassing our own filter (we need the real stored ID).
		self::$bypass_thumbnail_filter = true;
		$pt_id = (int) get_term_meta( $category->term_id, 'thumbnail_id', true );
		self::$bypass_thumbnail_filter = false;

		$en_id = (int) get_term_meta( $category->term_id, 'geolang_image_en', true );
		$es_id = (int) get_term_meta( $category->term_id, 'geolang_image_es', true );

		// If no multilingual images are set, fall back to WooCommerce default.
		if ( ! $en_id && ! $es_id ) {
			if ( function_exists( 'woocommerce_subcategory_thumbnail' ) ) {
				woocommerce_subcategory_thumbnail( $category );
			}
			return;
		}

		// Fallback chain: if EN/ES image not set, use PT image.
		$en_id = $en_id ?: $pt_id;
		$es_id = $es_id ?: $pt_id;

		$placeholder = function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( $size ) : '';

		$pt_url = $pt_id ? wp_get_attachment_image_url( $pt_id, $size ) : $placeholder;
		$en_url = $en_id ? wp_get_attachment_image_url( $en_id, $size ) : $pt_url;
		$es_url = $es_id ? wp_get_attachment_image_url( $es_id, $size ) : $pt_url;

		// Serve the correct language on initial PHP render (cache hit or first load).
		$lang        = GeoLang_Session::current();
		$current_url = 'en' === $lang ? $en_url : ( 'es' === $lang ? $es_url : $pt_url );

		// Build srcset / sizes for the EN attachment (best effort — same dimensions assumed).
		$active_id = 'en' === $lang ? $en_id : ( 'es' === $lang ? $es_id : $pt_id );
		$img_attr  = array(
			'src'          => esc_url( $current_url ),
			'class'        => 'geolang-image attachment-' . esc_attr( $size ) . ' size-' . esc_attr( $size ),
			'alt'          => esc_attr( $category->name ),
			'loading'      => 'lazy',
			'data-lang-pt' => esc_url( $pt_url ),
			'data-lang-en' => esc_url( $en_url ),
			'data-lang-es' => esc_url( $es_url ),
		);

		// Add srcset/sizes from the active attachment if available.
		$img_meta = wp_get_attachment_metadata( $active_id );
		if ( $img_meta ) {
			$srcset = wp_calculate_image_srcset( array( 0, 0 ), $img_meta, $active_id );
			$sizes  = wp_calculate_image_sizes( $size, null, null, $active_id );
			if ( $srcset ) {
				$img_attr['srcset'] = $srcset;
			}
			if ( $sizes ) {
				$img_attr['sizes'] = $sizes;
			}
		}

		$attr_str = '';
		foreach ( $img_attr as $key => $val ) {
			$attr_str .= ' ' . esc_attr( $key ) . '="' . $val . '"'; // val already escaped above
		}

		echo '<div class="woocommerce-loop-category__thumbnail"><img' . $attr_str . ' /></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// -----------------------------------------------------------------------
	// Frontend filter — thumbnail_id (PHP-side fallback for widgets / REST)
	// -----------------------------------------------------------------------

	/**
	 * Swap thumbnail_id when a non-default language is active.
	 * This handles PHP-rendered contexts outside the main loop (widgets, REST, etc.).
	 * Not used for the main subcategory loop (handled by multilingual_subcategory_thumbnail).
	 *
	 * @param mixed  $value    null (short-circuit; WP uses non-null return to skip DB)
	 * @param int    $term_id
	 * @param string $meta_key
	 * @param bool   $single
	 * @return mixed
	 */
	public function filter_thumbnail_id( $value, $term_id, $meta_key, $single ) {
		if ( self::$bypass_thumbnail_filter || 'thumbnail_id' !== $meta_key || is_admin() ) {
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
	// Admin: enqueue media uploader
	// -----------------------------------------------------------------------

	public function enqueue_admin_scripts( $hook ) {
		if ( ! in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) ) {
			return;
		}

		$tax = isset( $_GET['taxonomy'] ) ? sanitize_key( $_GET['taxonomy'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tax, self::$taxonomies, true ) ) {
			return;
		}

		wp_enqueue_media();

		$script = <<<'JS'
(function($){
	$(document).on('click', '.geolang-img-set', function(e){
		e.preventDefault();
		var lang    = $(this).data('lang');
		var $input  = $('#geolang_image_' + lang);
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
			$wrap.html('<img id="geolang-img-preview-' + lang + '" src="' + src + '" style="max-width:150px;height:auto;display:block;margin-bottom:8px;" />');
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
	// Admin UI – edit existing term
	// -----------------------------------------------------------------------

	public function render_edit_fields( $term ) {
		$en     = get_term_meta( $term->term_id, 'geolang_name_en',  true );
		$es     = get_term_meta( $term->term_id, 'geolang_name_es',  true );
		$img_en = (int) get_term_meta( $term->term_id, 'geolang_image_en', true );
		$img_es = (int) get_term_meta( $term->term_id, 'geolang_image_es', true );
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

		<tr class="form-field">
			<th scope="row"><label>🇺🇸 <?php esc_html_e( 'Nome em Inglês (EN)', 'geolang-multilingual' ); ?></label></th>
			<td>
				<input type="text" name="geolang_term_en" value="<?php echo esc_attr( $en ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Deixe em branco para usar o nome original.', 'geolang-multilingual' ); ?></p>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label>🇺🇸 <?php esc_html_e( 'Imagem em Inglês (EN)', 'geolang-multilingual' ); ?></label></th>
			<td>
				<input type="hidden" id="geolang_image_en" name="geolang_image_en" value="<?php echo esc_attr( $img_en ?: '' ); ?>" />
				<div id="geolang-img-wrap-en">
					<?php if ( $src_en ) : ?>
						<img id="geolang-img-preview-en" src="<?php echo esc_url( $src_en ); ?>"
							style="max-width:150px;height:auto;display:block;margin-bottom:8px;" />
					<?php endif; ?>
				</div>
				<button type="button" class="button geolang-img-set" data-lang="en">
					<?php esc_html_e( $src_en ? '🔄 Trocar imagem EN' : '📷 Definir imagem EN', 'geolang-multilingual' ); ?>
				</button>
				<button type="button" class="button geolang-img-remove" data-lang="en"
					id="geolang-img-remove-en" <?php echo $img_en ? '' : 'style="display:none"'; ?>>
					<?php esc_html_e( '✕ Remover', 'geolang-multilingual' ); ?>
				</button>
				<p class="description"><?php esc_html_e( 'Deixe em branco para usar a imagem padrão da categoria.', 'geolang-multilingual' ); ?></p>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label>🇪🇸 <?php esc_html_e( 'Nome em Espanhol (ES)', 'geolang-multilingual' ); ?></label></th>
			<td>
				<input type="text" name="geolang_term_es" value="<?php echo esc_attr( $es ); ?>" class="regular-text" />
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label>🇪🇸 <?php esc_html_e( 'Imagem em Espanhol (ES)', 'geolang-multilingual' ); ?></label></th>
			<td>
				<input type="hidden" id="geolang_image_es" name="geolang_image_es" value="<?php echo esc_attr( $img_es ?: '' ); ?>" />
				<div id="geolang-img-wrap-es">
					<?php if ( $src_es ) : ?>
						<img id="geolang-img-preview-es" src="<?php echo esc_url( $src_es ); ?>"
							style="max-width:150px;height:auto;display:block;margin-bottom:8px;" />
					<?php endif; ?>
				</div>
				<button type="button" class="button geolang-img-set" data-lang="es">
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
		<div class="form-field">
			<label>🇺🇸 <?php esc_html_e( 'Nome em Inglês (EN)', 'geolang-multilingual' ); ?></label>
			<input type="text" name="geolang_term_en" value="" class="regular-text" />
		</div>
		<div class="form-field">
			<label>🇺🇸 <?php esc_html_e( 'Imagem em Inglês (EN)', 'geolang-multilingual' ); ?></label>
			<input type="hidden" id="geolang_image_en" name="geolang_image_en" value="" />
			<div id="geolang-img-wrap-en"></div>
			<button type="button" class="button geolang-img-set" data-lang="en">📷 EN</button>
			<button type="button" class="button geolang-img-remove" data-lang="en" id="geolang-img-remove-en" style="display:none">✕</button>
		</div>
		<div class="form-field">
			<label>🇪🇸 <?php esc_html_e( 'Nome em Espanhol (ES)', 'geolang-multilingual' ); ?></label>
			<input type="text" name="geolang_term_es" value="" class="regular-text" />
		</div>
		<div class="form-field">
			<label>🇪🇸 <?php esc_html_e( 'Imagem em Espanhol (ES)', 'geolang-multilingual' ); ?></label>
			<input type="hidden" id="geolang_image_es" name="geolang_image_es" value="" />
			<div id="geolang-img-wrap-es"></div>
			<button type="button" class="button geolang-img-set" data-lang="es">📷 ES</button>
			<button type="button" class="button geolang-img-remove" data-lang="es" id="geolang-img-remove-es" style="display:none">✕</button>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Save term meta
	// -----------------------------------------------------------------------

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

		// Names.
		$en = sanitize_text_field( wp_unslash( $_POST['geolang_term_en'] ?? '' ) );
		$es = sanitize_text_field( wp_unslash( $_POST['geolang_term_es'] ?? '' ) );

		$en ? update_term_meta( $term_id, 'geolang_name_en', $en )
		    : delete_term_meta( $term_id, 'geolang_name_en' );
		$es ? update_term_meta( $term_id, 'geolang_name_es', $es )
		    : delete_term_meta( $term_id, 'geolang_name_es' );

		// Images — validate the attachment exists before saving.
		$img_en = absint( $_POST['geolang_image_en'] ?? 0 );
		$img_es = absint( $_POST['geolang_image_es'] ?? 0 );

		( $img_en && get_post( $img_en ) )
			? update_term_meta( $term_id, 'geolang_image_en', $img_en )
			: delete_term_meta( $term_id, 'geolang_image_en' );

		( $img_es && get_post( $img_es ) )
			? update_term_meta( $term_id, 'geolang_image_es', $img_es )
			: delete_term_meta( $term_id, 'geolang_image_es' );
	}
}
