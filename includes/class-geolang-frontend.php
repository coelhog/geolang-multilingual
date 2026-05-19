<?php
defined( 'ABSPATH' ) || exit;

/**
 * Frontend asset enqueueing and JS localisation.
 */
class GeoLang_Frontend {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Emit a tiny inline script in <head> (priority 1 = before any CSS/JS)
		// that sets data-geolang on <html> synchronously — zero FOUC for
		// .geolang-only-* visibility rules that reference that attribute.
		add_action( 'wp_head', array( $this, 'emit_lang_attribute_script' ), 1 );
	}

	/**
	 * Inline <script> emitted at the very top of <head>.
	 *
	 * Reads the GeoLang cookie synchronously (before the body renders) and sets
	 * data-geolang="<lang>" on <html>.  The CSS rules for .geolang-only-* rely on
	 * this attribute — setting it here eliminates any flash of wrong content.
	 *
	 * This output is tiny (< 300 bytes) and intentionally does NOT depend on any
	 * other script.  It duplicates the cookie name / default lang as literals so
	 * it can run before wp_localize_script data is available.
	 */
	public function emit_lang_attribute_script() {
		$default      = esc_js( get_option( 'geolang_default_lang', 'pt' ) );
		$cookie_name  = 'geolang_lang';
		$active_langs = get_option( 'geolang_active_langs', array( 'pt', 'en', 'es' ) );
		$langs_json   = wp_json_encode( array_values( $active_langs ) );
		?>
<script>
(function(){
	var active = <?php echo $langs_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	var def    = '<?php echo $default; ?>';
	var m      = document.cookie.match(/(?:^|; )geolang_lang=([^;]*)/);
	var lang   = m ? decodeURIComponent(m[1]) : def;
	if (active.indexOf(lang) === -1) lang = def;
	document.documentElement.setAttribute('data-geolang', lang);
})();
</script>
		<?php
	}

	public function enqueue_assets() {
		wp_enqueue_style(
			'geolang-frontend',
			GEOLANG_URL . 'assets/css/frontend.css',
			array(),
			GEOLANG_VERSION
		);

		wp_enqueue_script(
			'geolang-frontend',
			GEOLANG_URL . 'assets/js/frontend.js',
			array(),
			GEOLANG_VERSION,
			true
		);

		$current_lang = GeoLang_Session::current();
		$default_lang = get_option( 'geolang_default_lang', 'pt' );
		$active_langs = get_option( 'geolang_active_langs', array( 'pt', 'en', 'es' ) );

		wp_localize_script(
			'geolang-frontend',
			'GeoLang',
			array(
				'currentLang' => $current_lang,
				'defaultLang' => $default_lang,
				'activeLangs' => $active_langs,
				'cookieName'  => 'geolang_lang',
				'cookieDays'  => 30,
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'geolang_frontend' ),
			)
		);
	}
}
