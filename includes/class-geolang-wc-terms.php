<?php
defined( 'ABSPATH' ) || exit;

/**
 * GeoLang – WooCommerce taxonomy term translation.
 *
 * Translates product_cat and product_tag term names using WordPress term_meta.
 * Meta keys: geolang_name_en, geolang_name_es  (PT is the native WP name).
 *
 * Admin UI: fields are added to the category/tag edit screens via hooks.
 */
class GeoLang_WC_Terms {

	/** Taxonomies we translate. */
	private static $taxonomies = array( 'product_cat', 'product_tag' );

	public function __construct() {
		// Frontend: replace term names when lang ≠ default.
		add_filter( 'get_term', array( $this, 'filter_term' ), 10, 2 );

		// Admin: edit form fields.
		foreach ( self::$taxonomies as $tax ) {
			add_action( $tax . '_edit_form_fields',  array( $this, 'render_edit_fields' ), 10, 1 );
			add_action( 'edited_' . $tax,            array( $this, 'save_term_meta' ),     10, 1 );
			add_action( $tax . '_add_form_fields',   array( $this, 'render_add_fields' ),  10, 1 );
			add_action( 'created_' . $tax,           array( $this, 'save_term_meta' ),     10, 1 );
		}
	}

	// -----------------------------------------------------------------------
	// Frontend filter
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
	// Admin UI – edit existing term
	// -----------------------------------------------------------------------

	/**
	 * Renders the EN / ES translation fields on the "edit term" screen.
	 *
	 * @param \WP_Term $term
	 */
	public function render_edit_fields( $term ) {
		$en = get_term_meta( $term->term_id, 'geolang_name_en', true );
		$es = get_term_meta( $term->term_id, 'geolang_name_es', true );

		wp_nonce_field( 'geolang_term_save_' . $term->term_id, 'geolang_term_nonce' );
		?>
		<tr class="form-field">
			<th scope="row">
				<label>🇺🇸 <?php esc_html_e( 'Nome em Inglês (EN)', 'geolang-multilingual' ); ?></label>
			</th>
			<td>
				<input type="text" name="geolang_term_en" value="<?php echo esc_attr( $en ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Deixe em branco para usar o nome original.', 'geolang-multilingual' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label>🇪🇸 <?php esc_html_e( 'Nome em Espanhol (ES)', 'geolang-multilingual' ); ?></label>
			</th>
			<td>
				<input type="text" name="geolang_term_es" value="<?php echo esc_attr( $es ); ?>" class="regular-text" />
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
			<label>🇺🇸 <?php esc_html_e( 'Nome em Inglês (EN)', 'geolang-multilingual' ); ?></label>
			<input type="text" name="geolang_term_en" value="" class="regular-text" />
		</div>
		<div class="form-field">
			<label>🇪🇸 <?php esc_html_e( 'Nome em Espanhol (ES)', 'geolang-multilingual' ); ?></label>
			<input type="text" name="geolang_term_es" value="" class="regular-text" />
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Save term meta
	// -----------------------------------------------------------------------

	/**
	 * Saves EN / ES term name translations on term create/update.
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
	}
}
