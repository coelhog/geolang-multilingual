<?php
defined( 'ABSPATH' ) || exit;

/**
 * GeoLang – GitHub Releases Auto-updater.
 *
 * Polls the GitHub Releases API for new versions.
 * When a newer tag is found, WordPress shows the standard "Update available"
 * notice and handles download + install natively.
 *
 * Setup:
 *   1. Create a GitHub repo (public or private).
 *   2. Push the plugin code.
 *   3. When releasing: create a GitHub Release tagged vX.Y.Z and attach
 *      geolang-multilingual.zip as a release asset.
 *   4. Fill in GeoLang → Configurações → Atualizações Automáticas.
 *
 * Works with public repos (no token needed) and private repos (token required).
 */
class GeoLang_Updater {

	/** Plugin basename: geolang-multilingual/geolang-multilingual.php */
	private $basename;

	/** GitHub owner/repo — público, sem necessidade de token. */
	const GITHUB_REPO = 'coelhog/geolang-multilingual';

	/** Optional GitHub personal access token (override via GeoLang settings for private forks). */
	private $github_token;

	/** Transient key for caching the API response. */
	const TRANSIENT = 'geolang_github_release_cache';

	public function __construct() {
		$this->basename     = GEOLANG_BASENAME;
		$this->github_token = get_option( 'geolang_github_token', '' );

		// Hook into WordPress update system.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete',             array( $this, 'purge_cache' ), 10, 2 );

		// Add Authorization header when WordPress downloads the asset (private fork support).
		add_filter( 'http_request_args', array( $this, 'maybe_add_auth_header' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// GitHub API
	// -------------------------------------------------------------------------

	/**
	 * Fetch latest release from GitHub, cached for 6 hours.
	 *
	 * @return array|null  Decoded release JSON or null on failure.
	 */
	private function get_latest_release() {
		$cached = get_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return $cached;
		}

		$url  = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'User-Agent' => 'GeoLang-Updater/' . GEOLANG_VERSION,
				'Accept'     => 'application/vnd.github+json',
			),
		);

		if ( $this->github_token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->github_token;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache a short negative result so we don't hammer the API on errors.
			set_transient( self::TRANSIENT, null, 30 * MINUTE_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['tag_name'] ) ) {
			set_transient( self::TRANSIENT, null, 30 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( self::TRANSIENT, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Find the zip download URL in a release's assets.
	 * Looks for an asset ending in .zip; falls back to the GitHub-generated zipball.
	 *
	 * @param array $release
	 * @return string|null
	 */
	private function get_download_url( array $release ) {
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
					// For private repos WordPress will need auth — we handle that
					// via the http_request_args filter below.
					return $asset['browser_download_url'];
				}
			}
		}

		// Fallback: auto-generated source zip (works for public repos).
		return $release['zipball_url'] ?? null;
	}

	// -------------------------------------------------------------------------
	// WordPress update hooks
	// -------------------------------------------------------------------------

	/**
	 * Inject update data into the WordPress plugin update transient.
	 *
	 * @param object $transient
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release        = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest_version = ltrim( $release['tag_name'], 'vV' );

		if ( ! version_compare( $latest_version, GEOLANG_VERSION, '>' ) ) {
			// Already up to date.
			return $transient;
		}

		$download_url = $this->get_download_url( $release );
		if ( ! $download_url ) {
			return $transient;
		}

		$transient->response[ $this->basename ] = (object) array(
			'slug'          => 'geolang-multilingual',
			'plugin'        => $this->basename,
			'new_version'   => $latest_version,
			'url'           => 'https://github.com/' . self::GITHUB_REPO,
			'package'       => $download_url,
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'tested'        => '6.5',
			'requires_php'  => '7.4',
			'compatibility' => new stdClass(),
		);

		return $transient;
	}

	/**
	 * Provide plugin details popup (shown in wp-admin → Plugins → "View version X.X details").
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || 'geolang-multilingual' !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$latest_version = ltrim( $release['tag_name'], 'vV' );
		$download_url   = $this->get_download_url( $release );
		$changelog      = isset( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : '';

		return (object) array(
			'name'              => 'GeoLang – Multilingual Manager',
			'slug'              => 'geolang-multilingual',
			'version'           => $latest_version,
			'author'            => 'GeoLang',
			'homepage'          => 'https://github.com/' . self::GITHUB_REPO,
			'requires'          => '6.0',
			'tested'            => '6.5',
			'requires_php'      => '7.4',
			'downloaded'        => 0,
			'last_updated'      => $release['published_at'] ?? '',
			'sections'          => array(
				'changelog' => $changelog ?: '<p>Ver <a href="https://github.com/' . esc_attr( self::GITHUB_REPO ) . '/releases" target="_blank">GitHub Releases</a>.</p>',
			),
			'download_link'     => $download_url,
			'short_description' => 'Multilingual manager for Elementor (PT / EN / ES).',
		);
	}

	/**
	 * Purge the release cache after a successful plugin update.
	 *
	 * @param \WP_Upgrader $upgrader
	 * @param array        $options
	 */
	public function purge_cache( $upgrader, $options ) {
		if (
			isset( $options['action'], $options['type'] ) &&
			'update' === $options['action'] &&
			'plugin' === $options['type']
		) {
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * Add Authorization header when WordPress fetches the plugin zip from GitHub.
	 * Required for private repo asset downloads.
	 *
	 * @param array  $args  wp_remote_get / wp_remote_post args.
	 * @param string $url   Request URL.
	 * @return array
	 */
	public function maybe_add_auth_header( $args, $url ) {
		if ( $this->github_token && false !== strpos( $url, 'github.com' ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->github_token;
		}
		return $args;
	}

	// -------------------------------------------------------------------------
	// Admin helpers
	// -------------------------------------------------------------------------

	/**
	 * Force an immediate re-check by clearing the cached release data.
	 * Called from the settings page "Verificar agora" button.
	 */
	public static function clear_cache() {
		delete_transient( self::TRANSIENT );
	}
}
