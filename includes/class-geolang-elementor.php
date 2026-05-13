<?php
defined( 'ABSPATH' ) || exit;

/**
 * Elementor integration: Dynamic Tags (Text, Image, URL) + Field Manager widget + Switcher widget.
 *
 * Compatible with Elementor Free, Elementor Pro, and Pro Elements.
 *
 * Dynamic Tag workflow (no pre-registration needed):
 *   1. User clicks dynamic tag icon on any Elementor field.
 *   2. Chooses a GeoLang tag.
 *   3. Types a key name (e.g. "flipbox_description") and fills PT/BR · EN · ES inline.
 *   4. Saves the page → all tags are synced to the DB automatically.
 */
class GeoLang_Elementor {

	public function __construct() {
		add_action( 'elementor/dynamic_tags/register', array( $this, 'register_tags' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );

		// Try both Elementor-specific hooks (different versions use different ones).
		add_action( 'elementor/editor/after_save',    array( $this, 'sync_after_save' ),    10, 2 );
		add_action( 'elementor/document/after_save',  array( $this, 'sync_from_document' ), 10, 2 );

		// Most reliable fallback: read _elementor_data meta directly on every WP save_post.
		// This fires regardless of Elementor version and catches all save paths.
		add_action( 'save_post', array( $this, 'sync_from_post_meta' ), 99 );

		// AJAX: manual re-sync of a single post (triggered from admin panel).
		add_action( 'wp_ajax_geolang_resync_post', array( $this, 'ajax_resync_post' ) );
		// AJAX: re-sync ALL posts that have _elementor_data.
		add_action( 'wp_ajax_geolang_resync_all', array( $this, 'ajax_resync_all' ) );
	}

	/**
	 * Adapter for the newer elementor/document/after_save hook.
	 *
	 * @param \Elementor\Core\Documents_Manager|\Elementor\Core\Base\Document $document
	 * @param array $data
	 */
	public function sync_from_document( $document, $data ) {
		$post_id = $document->get_post()->ID ?? 0;

		// Different Elementor/Pro Elements versions use different keys.
		$editor_data = $data['elements'] ?? ( $data['content'] ?? array() );

		if ( $post_id && $editor_data ) {
			$this->sync_after_save( $post_id, $editor_data );
		}
	}

	// -----------------------------------------------------------------------
	// save_post fallback sync (most reliable — reads _elementor_data meta directly)
	// -----------------------------------------------------------------------

	/**
	 * Fires on every WordPress save_post.
	 * Reads _elementor_data from post meta and syncs GeoLang fields to DB.
	 * This is the most reliable sync path — works across all Elementor versions.
	 *
	 * @param int $post_id
	 */
	public function sync_from_post_meta( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', absint( $post_id ) ) ) {
			return;
		}

		$raw = get_post_meta( absint( $post_id ), '_elementor_data', true );
		if ( ! $raw ) {
			return;
		}

		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			return;
		}

		$this->sync_after_save( $post_id, $data );
	}

	// -----------------------------------------------------------------------
	// AJAX: manual re-sync
	// -----------------------------------------------------------------------

	/**
	 * Re-sync a single post_id from its _elementor_data meta.
	 */
	public function ajax_resync_post() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permissão negada.' ), 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_id inválido.' ) );
		}

		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = $raw ? ( is_string( $raw ) ? json_decode( $raw, true ) : $raw ) : null;

		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => 'Nenhum dado Elementor encontrado para este post.' ) );
		}

		$this->sync_after_save( $post_id, $data );
		wp_send_json_success( array( 'message' => 'Post #' . $post_id . ' re-sincronizado.' ) );
	}

	/**
	 * Re-sync ALL posts that have _elementor_data.
	 */
	public function ajax_resync_all() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permissão negada.' ), 403 );
		}

		global $wpdb;

		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$synced = 0;
		foreach ( $post_ids as $post_id ) {
			$post_id = absint( $post_id );
			$raw     = get_post_meta( $post_id, '_elementor_data', true );
			$data    = $raw ? ( is_string( $raw ) ? json_decode( $raw, true ) : $raw ) : null;
			if ( ! is_array( $data ) ) {
				continue;
			}
			$this->sync_after_save( $post_id, $data );
			$synced++;
		}

		wp_send_json_success( array( 'synced' => $synced, 'message' => $synced . ' página(s) re-sincronizadas.' ) );
	}

	// -----------------------------------------------------------------------
	// Dynamic Tags
	// -----------------------------------------------------------------------

	public function register_tags( $dynamic_tags ) {
		if ( ! class_exists( '\Elementor\Modules\DynamicTags\Module' ) ) {
			return;
		}

		$dynamic_tags->register_group(
			'geolang',
			array( 'title' => __( 'GeoLang', 'geolang-multilingual' ) )
		);

		$dynamic_tags->register( new GeoLang_Text_Tag() );
		$dynamic_tags->register( new GeoLang_Image_Tag() );
		$dynamic_tags->register( new GeoLang_URL_Tag() );
	}

	// -----------------------------------------------------------------------
	// Widgets
	// -----------------------------------------------------------------------

	public function register_widgets( $widgets_manager ) {
		$widgets_manager->register( new GeoLang_Field_Manager_Widget() );
		$widgets_manager->register( new GeoLang_Switcher_Widget() );
	}

	// -----------------------------------------------------------------------
	// After-save sync
	// -----------------------------------------------------------------------

	/**
	 * Walks all elements saved by Elementor and syncs GeoLang content to the DB.
	 *
	 * Handles two sources:
	 *   a) GeoLang Field Manager widgets (explicit widget).
	 *   b) __dynamic__ tag settings embedded in ANY widget (inline tag workflow).
	 *
	 * @param int   $post_id
	 * @param array $editor_data Raw Elementor data.
	 */
	public function sync_after_save( $post_id, $editor_data ) {
		if ( ! current_user_can( 'edit_post', absint( $post_id ) ) ) {
			return;
		}

		$widgets = $this->collect_widgets( $editor_data );

		foreach ( $widgets as $widget ) {
			// a) Field Manager widgets.
			if ( 'geolang-field-manager' === ( $widget['widgetType'] ?? '' ) ) {
				$this->sync_field_manager( $post_id, $widget );
			}

			// b) Inline Dynamic Tag content embedded in __dynamic__ settings.
			$this->sync_inline_tags( $post_id, $widget );
		}
	}

	/**
	 * Syncs a GeoLang Field Manager widget's settings to the DB.
	 */
	private function sync_field_manager( $post_id, $widget ) {
		$settings   = $widget['settings'] ?? array();
		$field_key  = $this->sanitize_key( $settings['field_key'] ?? '' );
		$field_type = in_array( $settings['field_type'] ?? '', array( 'text', 'url', 'image' ), true )
			? $settings['field_type'] : 'text';

		// Auto-generate key from PT content + element ID when the user left it blank.
		if ( ! $field_key ) {
			$pt_raw    = $settings['content_pt'] ?? '';
			$elem_id   = $widget['id'] ?? '';
			$field_key = GeoLang_Field_Manager_Widget::auto_key( $pt_raw, $elem_id );
		}

		if ( ! $field_key ) {
			return;
		}

		$this->upsert(
			$post_id,
			$field_key,
			$field_type,
			$settings['content_pt'] ?? '',
			$settings['content_en'] ?? '',
			$settings['content_es'] ?? ''
		);
	}

	/**
	 * Parses __dynamic__ fields inside a widget's settings and syncs any GeoLang tags.
	 *
	 * Elementor stores dynamic tag data as a JSON string inside settings.__dynamic__:
	 *   { "title": "{\"name\":\"geolang-text\",\"settings\":{\"field_key\":\"hero_title\",\"content_pt\":\"Olá\",...}}" }
	 */
	private function sync_inline_tags( $post_id, $widget ) {
		$dynamic = $widget['settings']['__dynamic__'] ?? array();

		if ( empty( $dynamic ) || ! is_array( $dynamic ) ) {
			return;
		}

		$geolang_tags = array( 'geolang-text', 'geolang-url', 'geolang-image' );

		foreach ( $dynamic as $tag_json ) {
			// Newer Elementor builds may already have decoded the JSON into an array.
			if ( is_array( $tag_json ) ) {
				$tag_data = $tag_json;
			} elseif ( is_string( $tag_json ) ) {
				$tag_data = json_decode( $tag_json, true );
			} else {
				continue;
			}

			if ( ! is_array( $tag_data ) ) {
				continue;
			}

			$tag_name = $tag_data['name'] ?? '';
			if ( ! in_array( $tag_name, $geolang_tags, true ) ) {
				continue;
			}

			$settings   = $tag_data['settings'] ?? array();
			$field_key  = $this->sanitize_key( $settings['field_key'] ?? '' );
			$field_type = ( 'geolang-url' === $tag_name ) ? 'url'
				: ( ( 'geolang-image' === $tag_name ) ? 'image' : 'text' );

			if ( ! $field_key ) {
				continue;
			}

			$this->upsert(
				$post_id,
				$field_key,
				$field_type,
				$settings['content_pt'] ?? '',
				$settings['content_en'] ?? '',
				$settings['content_es'] ?? ''
			);
		}
	}

	/**
	 * INSERT or UPDATE a row in the translations table.
	 */
	private function upsert( $post_id, $field_key, $field_type, $pt, $en, $es ) {
		$lang_pt = wp_kses_post( $pt );
		$lang_en = wp_kses_post( $en );
		$lang_es = wp_kses_post( $es );

		if ( 'url' === $field_type ) {
			$lang_pt = esc_url_raw( $lang_pt );
			$lang_en = esc_url_raw( $lang_en );
			$lang_es = esc_url_raw( $lang_es );
		}

		global $wpdb;
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			GeoLang_Core::table(),
			array(
				'post_id'    => absint( $post_id ),
				'field_key'  => $field_key,
				'field_type' => $field_type,
				'lang_pt'    => $lang_pt,
				'lang_en'    => $lang_en,
				'lang_es'    => $lang_es,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Recursively collect all widget elements from Elementor's nested structure.
	 */
	private function collect_widgets( $elements ) {
		$widgets = array();

		if ( ! is_array( $elements ) ) {
			return $widgets;
		}

		foreach ( $elements as $element ) {
			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				$widgets[] = $element;
			}

			if ( ! empty( $element['elements'] ) ) {
				$widgets = array_merge( $widgets, $this->collect_widgets( $element['elements'] ) );
			}
		}

		return $widgets;
	}

	private function sanitize_key( $key ) {
		$key = strtolower( sanitize_text_field( $key ) );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', $key );
		return substr( $key, 0, 100 );
	}
}

// =============================================================================
// Shared controls trait — used by all three tags
// =============================================================================

/**
 * Registers the inline content controls shared by all GeoLang tags:
 * field_key (text), content_pt, content_en, content_es.
 */
trait GeoLang_Tag_Controls {

	protected function register_geolang_controls( $with_fallback = false ) {
		$this->add_control(
			'field_key',
			array(
				'label'       => __( 'Nome da key (ex: flipbox_title)', 'geolang-multilingual' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'meu_campo',
				'description' => __( 'Use apenas letras minúsculas, números, _ e -. Cada campo único numa página.', 'geolang-multilingual' ),
			)
		);

		$this->add_control(
			'content_pt',
			array(
				'label'       => __( '🇧🇷 PT/BR', 'geolang-multilingual' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'placeholder' => __( 'Texto em Português…', 'geolang-multilingual' ),
				'rows'        => 3,
			)
		);

		$this->add_control(
			'content_en',
			array(
				'label'       => __( '🇺🇸 EN', 'geolang-multilingual' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'placeholder' => __( 'Text in English…', 'geolang-multilingual' ),
				'rows'        => 3,
			)
		);

		$this->add_control(
			'content_es',
			array(
				'label'       => __( '🇪🇸 ES', 'geolang-multilingual' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'placeholder' => __( 'Texto en Español…', 'geolang-multilingual' ),
				'rows'        => 3,
			)
		);

		if ( $with_fallback ) {
			$this->add_control(
				'fallback',
				array(
					'label'       => __( 'Fallback (se vazio)', 'geolang-multilingual' ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'placeholder' => __( 'Texto padrão', 'geolang-multilingual' ),
				)
			);
		}
	}

	/**
	 * Returns all three language values for a field.
	 * Priority: DB → inline tag settings.
	 *
	 * @param string $field_key
	 * @param string $fallback
	 * @return array { pt: string, en: string, es: string }
	 */
	protected function resolve_all( $field_key, $fallback = '' ) {
		$post_id = absint( get_the_ID() );
		$langs   = array( 'pt', 'en', 'es' );
		$result  = array();

		foreach ( $langs as $lang ) {
			// Try DB.
			$val = GeoLang_Core::get_field( $post_id, $field_key, $lang );

			// Fall back to inline tag setting.
			if ( '' === $val ) {
				$val = $this->get_settings( 'content_' . $lang ) ?? '';
			}

			// Last resort: fallback string.
			$result[ $lang ] = '' !== $val ? $val : $fallback;
		}

		return $result;
	}

	/**
	 * Renders a <span> with all 3 language values as data attributes.
	 * JS reads the cookie and shows the right language instantly — no reload needed.
	 */
	protected function render_text_field( $field_key, $fallback = '' ) {
		$values  = $this->resolve_all( $field_key, $fallback );
		$default = GeoLang_Session::current();
		$display = $values[ $default ] ?? $values['pt'];

		printf(
			'<span class="geolang-field" data-lang-pt="%s" data-lang-en="%s" data-lang-es="%s">%s</span>',
			esc_attr( $values['pt'] ),
			esc_attr( $values['en'] ),
			esc_attr( $values['es'] ),
			wp_kses_post( $display )
		);
	}
}

// =============================================================================
// Dynamic Tag: Text
// =============================================================================

class GeoLang_Text_Tag extends \Elementor\Core\DynamicTags\Tag {

	use GeoLang_Tag_Controls;

	public function get_name() {
		return 'geolang-text';
	}

	public function get_title() {
		return __( 'GeoLang – Texto', 'geolang-multilingual' );
	}

	public function get_group() {
		return 'geolang';
	}

	public function get_categories() {
		return array(
			\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
			\Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
		);
	}

	protected function register_controls() {
		$this->register_geolang_controls( true );
	}

	public function render() {
		$field_key = $this->sanitize_field_key( $this->get_settings( 'field_key' ) );
		$fallback  = sanitize_text_field( $this->get_settings( 'fallback' ) ?? '' );

		if ( ! $field_key ) {
			echo esc_html( $fallback );
			return;
		}

		// Output all 3 langs as data attributes — JS swaps without reload.
		$this->render_text_field( $field_key, $fallback );
	}

	private function sanitize_field_key( $key ) {
		$key = strtolower( sanitize_text_field( $key ?? '' ) );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

// =============================================================================
// Dynamic Tag: Image
// =============================================================================

class GeoLang_Image_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	use GeoLang_Tag_Controls;

	public function get_name() {
		return 'geolang-image';
	}

	public function get_title() {
		return __( 'GeoLang – Imagem', 'geolang-multilingual' );
	}

	public function get_group() {
		return 'geolang';
	}

	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'field_key',
			array(
				'label'       => __( 'Nome da key (ex: hero_image)', 'geolang-multilingual' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'minha_imagem',
				'description' => __( 'Use apenas letras minúsculas, números, _ e -.', 'geolang-multilingual' ),
			)
		);

		foreach ( array( 'pt' => '🇧🇷 PT/BR', 'en' => '🇺🇸 EN', 'es' => '🇪🇸 ES' ) as $code => $label ) {
			$this->add_control(
				'content_' . $code,
				array(
					'label'       => $label . ' — ' . __( 'URL da imagem', 'geolang-multilingual' ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'placeholder' => 'https://…',
				)
			);
		}
	}

	public function get_value( array $options = array() ) {
		$field_key = strtolower( sanitize_text_field( $this->get_settings( 'field_key' ) ?? '' ) );
		$field_key = preg_replace( '/[^a-z0-9_\-]/', '', $field_key );

		if ( ! $field_key ) {
			return array( 'id' => 0, 'url' => '' );
		}

		$lang    = GeoLang_Session::current();
		$post_id = absint( get_the_ID() );
		$value   = GeoLang_Core::get_field( $post_id, $field_key, $lang );

		if ( '' === $value ) {
			$col_map = array( 'pt' => 'content_pt', 'en' => 'content_en', 'es' => 'content_es' );
			$value   = $this->get_settings( $col_map[ $lang ] ?? 'content_pt' ) ?? '';
		}

		if ( ! $value ) {
			return array( 'id' => 0, 'url' => '' );
		}

		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_url( (int) $value );
			return array( 'id' => (int) $value, 'url' => $url ?: '' );
		}

		$url       = esc_url( $value );
		$attach_id = attachment_url_to_postid( $url );
		return array( 'id' => $attach_id ?: 0, 'url' => $url );
	}
}

// =============================================================================
// Dynamic Tag: URL
// =============================================================================

class GeoLang_URL_Tag extends \Elementor\Core\DynamicTags\Tag {

	use GeoLang_Tag_Controls;

	/**
	 * Static registry of all URL fields rendered on this page request.
	 * Emitted as window.GeoLangURLData at wp_footer so JS can live-swap hrefs/srcs.
	 *
	 * @var array[]
	 */
	private static $url_registry = array();

	/** Whether the wp_footer action has been registered. */
	private static $footer_hooked = false;

	public function get_name() {
		return 'geolang-url';
	}

	public function get_title() {
		return __( 'GeoLang – URL/Link', 'geolang-multilingual' );
	}

	public function get_group() {
		return 'geolang';
	}

	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::URL_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'field_key',
			array(
				'label'       => __( 'Nome da key (ex: cta_link)', 'geolang-multilingual' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'meu_link',
				'description' => __( 'Use apenas letras minúsculas, números, _ e -.', 'geolang-multilingual' ),
			)
		);

		foreach ( array( 'pt' => '🇧🇷 PT/BR', 'en' => '🇺🇸 EN', 'es' => '🇪🇸 ES' ) as $code => $label ) {
			$this->add_control(
				'content_' . $code,
				array(
					'label'       => $label . ' — URL',
					'type'        => \Elementor\Controls_Manager::TEXT,
					'placeholder' => 'https://…',
					'input_type'  => 'url',
				)
			);
		}
	}

	public function render() {
		$field_key = strtolower( sanitize_text_field( $this->get_settings( 'field_key' ) ?? '' ) );
		$field_key = preg_replace( '/[^a-z0-9_\-]/', '', $field_key );

		if ( ! $field_key ) {
			return;
		}

		$lang    = GeoLang_Session::current();
		$post_id = absint( get_the_ID() );

		// Resolve all three URL variants for JS live-switching.
		$all_urls = array();
		foreach ( array( 'pt', 'en', 'es' ) as $l ) {
			$v = GeoLang_Core::get_field( $post_id, $field_key, $l );
			if ( '' === $v ) {
				$v = $this->get_settings( 'content_' . $l ) ?? '';
			}
			$all_urls[ $l ] = esc_url_raw( $v );
		}

		// Current language URL for initial PHP render.
		$value = $all_urls[ $lang ] ?? $all_urls['pt'] ?? '';

		// Register for JS live-switching via GeoLangURLData.
		self::$url_registry[] = array_merge( array( 'key' => $field_key ), $all_urls );

		if ( ! self::$footer_hooked ) {
			self::$footer_hooked = true;
			add_action( 'wp_footer', array( 'GeoLang_URL_Tag', 'output_url_registry' ), 20 );
		}

		echo esc_url( $value );
	}

	/**
	 * Outputs window.GeoLangURLData JSON at wp_footer for frontend JS to consume.
	 */
	public static function output_url_registry() {
		if ( empty( self::$url_registry ) ) {
			return;
		}
		echo '<script id="geolang-url-data">window.GeoLangURLData=' . wp_json_encode( self::$url_registry ) . ';</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

// =============================================================================
// Widget: GeoLang Field Manager (alternative to inline tags)
// =============================================================================

class GeoLang_Field_Manager_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'geolang-field-manager';
	}

	public function get_title() {
		return __( 'GeoLang Field Manager', 'geolang-multilingual' );
	}

	public function get_icon() {
		return 'eicon-globe';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'geolang', 'translation', 'multilingual', 'lang' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_field',
			array(
				'label' => __( 'Campo de Tradução', 'geolang-multilingual' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'field_key',
			array(
				'label'       => __( 'Chave (opcional)', 'geolang-multilingual' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'gerado automaticamente do texto PT', 'geolang-multilingual' ),
				'description' => __( 'Deixe em branco para gerar automaticamente. Se preencher: letras minúsculas, números, _ e -.', 'geolang-multilingual' ),
			)
		);

		$this->add_control(
			'field_type',
			array(
				'label'   => __( 'Tipo do campo', 'geolang-multilingual' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'text'  => __( 'Texto', 'geolang-multilingual' ),
					'url'   => __( 'URL', 'geolang-multilingual' ),
					'image' => __( 'Imagem', 'geolang-multilingual' ),
				),
				'default' => 'text',
			)
		);

		$this->add_control(
			'content_pt',
			array(
				'label'       => __( '🇧🇷 PT/BR', 'geolang-multilingual' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'placeholder' => __( 'Texto em português…', 'geolang-multilingual' ),
			)
		);

		$this->add_control(
			'content_en',
			array(
				'label'       => __( '🇺🇸 EN', 'geolang-multilingual' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'placeholder' => __( 'Text in English…', 'geolang-multilingual' ),
			)
		);

		$this->add_control(
			'content_es',
			array(
				'label'       => __( '🇪🇸 ES', 'geolang-multilingual' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'placeholder' => __( 'Texto en español…', 'geolang-multilingual' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Generate a stable field key from PT text + Elementor element ID.
	 * Used both in render() and in sync_field_manager() so the key is consistent.
	 *
	 * @param string $pt_text   Raw PT content (may contain HTML).
	 * @param string $element_id Elementor element unique ID (6-char hash).
	 * @return string  Sanitized key, e.g. "bem_vindo_ao_nosso_site_a1b2c3", or empty string.
	 */
	public static function auto_key( $pt_text, $element_id ) {
		$text = wp_strip_all_tags( $pt_text );
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );

		if ( $text === '' ) {
			return $element_id ? 'field_' . $element_id : '';
		}

		$slug = sanitize_title( mb_substr( $text, 0, 35 ) );
		$slug = preg_replace( '/[^a-z0-9_]/', '_', $slug );
		$slug = trim( preg_replace( '/_+/', '_', $slug ), '_' );

		return $element_id ? $slug . '_' . $element_id : $slug;
	}

	protected function render() {
		$settings  = $this->get_settings_for_display();
		$field_key = strtolower( sanitize_text_field( $settings['field_key'] ?? '' ) );
		$field_key = preg_replace( '/[^a-z0-9_\-]/', '', $field_key );

		// Auto-generate key from PT text + element ID when the user left field_key blank.
		if ( ! $field_key ) {
			$field_key = self::auto_key( $settings['content_pt'] ?? '', $this->get_id() );
		}

		if ( ! $field_key ) {
			if ( \Elementor\Plugin::instance()->editor->is_edit_mode() ) {
				echo '<div class="geolang-widget-placeholder">';
				esc_html_e( 'GeoLang Field Manager: preencha o texto PT acima.', 'geolang-multilingual' );
				echo '</div>';
			}
			return;
		}

		$post_id  = absint( get_the_ID() );
		$col_map  = array( 'pt' => 'content_pt', 'en' => 'content_en', 'es' => 'content_es' );
		$all_vals = array();

		// Build the value for each language, preferring DB then widget settings.
		foreach ( array( 'pt', 'en', 'es' ) as $l ) {
			$v = GeoLang_Core::get_field( $post_id, $field_key, $l, '' );
			if ( '' === $v ) {
				$v = $settings[ $col_map[ $l ] ] ?? '';
			}
			$all_vals[ $l ] = $v;
		}

		// Fallback chain: current lang → pt → any non-empty.
		$lang    = GeoLang_Session::current();
		$display = $all_vals[ $lang ] ?? '';
		if ( '' === $display ) {
			$display = $all_vals['pt'];
		}

		// Output span with all three lang data-attributes so frontend.js can
		// swap the content instantly when the visitor clicks a language flag.
		printf(
			'<span class="geolang-field" data-lang-pt="%s" data-lang-en="%s" data-lang-es="%s">%s</span>',
			esc_attr( $all_vals['pt'] ),
			esc_attr( $all_vals['en'] ),
			esc_attr( $all_vals['es'] ),
			wp_kses_post( $display )
		);
	}
}

// =============================================================================
// Widget: GeoLang Switcher
// =============================================================================

class GeoLang_Switcher_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'geolang-switcher';
	}

	public function get_title() {
		return __( 'GeoLang Switcher', 'geolang-multilingual' );
	}

	public function get_icon() {
		return 'eicon-globe';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'geolang', 'switcher', 'language', 'flags', 'lang' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_switcher',
			array(
				'label' => __( 'Switcher de Idiomas', 'geolang-multilingual' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'style',
			array(
				'label'   => __( 'Estilo', 'geolang-multilingual' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'flags'      => __( 'Só bandeiras', 'geolang-multilingual' ),
					'text'       => __( 'Só texto (PT/BR · EN · ES)', 'geolang-multilingual' ),
					'flags-text' => __( 'Bandeiras + texto', 'geolang-multilingual' ),
				),
				'default' => 'flags',
			)
		);

		$this->add_control(
			'size',
			array(
				'label'   => __( 'Tamanho', 'geolang-multilingual' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'sm' => __( 'Pequeno (24 px)', 'geolang-multilingual' ),
					'md' => __( 'Médio (32 px)', 'geolang-multilingual' ),
					'lg' => __( 'Grande (40 px)', 'geolang-multilingual' ),
				),
				'default' => 'md',
			)
		);

		$this->add_control(
			'flag_style',
			array(
				'label'   => __( 'Estilo da bandeira', 'geolang-multilingual' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'flat'  => __( 'Flat (lisa)', 'geolang-multilingual' ),
					'shiny' => __( 'Shiny (brilhante)', 'geolang-multilingual' ),
				),
				'default' => 'flat',
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings   = $this->get_settings_for_display();
		$style      = sanitize_key( $settings['style']      ?? 'flags' );
		$size       = sanitize_key( $settings['size']       ?? 'md' );
		$flag_style = sanitize_key( $settings['flag_style'] ?? 'flat' );

		echo do_shortcode(
			sprintf(
				'[geolang_switcher style="%s" size="%s" flag_style="%s"]',
				esc_attr( $style ),
				esc_attr( $size ),
				esc_attr( $flag_style )
			)
		);
	}
}
