<?php
defined( 'ABSPATH' ) || exit;

/**
 * [geolang_switcher] shortcode — language switcher with flag buttons.
 *
 * Flag images are bundled locally (downloaded from flagsapi.com at build time).
 * No external requests at runtime.
 *
 * Attributes:
 *   style      = flags | text | flags-text  (default: flags)
 *   size       = sm | md | lg               (default: md)
 *   flag_style = flat | shiny               (default: flat)
 */
class GeoLang_Shortcode {

	public function __construct() {
		add_shortcode( 'geolang_switcher', array( $this, 'render_switcher' ) );
	}

	public function render_switcher( $atts ) {
		$atts = shortcode_atts(
			array(
				'style'      => 'flags',  // flags | text | flags-text
				'size'       => 'md',     // sm | md | lg
				'flag_style' => 'flat',   // flat | shiny
			),
			$atts,
			'geolang_switcher'
		);

		$style       = in_array( $atts['style'],      array( 'flags', 'text', 'flags-text' ), true ) ? $atts['style']      : 'flags';
		$size        = in_array( $atts['size'],        array( 'sm', 'md', 'lg' ),              true ) ? $atts['size']        : 'md';
		$flag_style  = in_array( $atts['flag_style'],  array( 'flat', 'shiny' ),               true ) ? $atts['flag_style']  : 'flat';

		$current      = GeoLang_Session::current();
		$active_langs = get_option( 'geolang_active_langs', array( 'pt', 'en', 'es' ) );

		$lang_meta = array(
			'pt' => array( 'label' => 'PT/BR', 'aria' => __( 'Português (Brasil)', 'geolang-multilingual' ) ),
			'en' => array( 'label' => 'EN',    'aria' => __( 'English',            'geolang-multilingual' ) ),
			'es' => array( 'label' => 'ES',    'aria' => __( 'Español',            'geolang-multilingual' ) ),
		);

		$show_flags = in_array( $style, array( 'flags', 'flags-text' ), true );
		$show_text  = in_array( $style, array( 'text', 'flags-text' ),  true );
		$px         = $this->flag_size( $size );

		ob_start();
		?>
		<div class="geolang-switcher geolang-switcher--<?php echo esc_attr( $size ); ?> geolang-switcher--<?php echo esc_attr( $style ); ?>"
			data-current="<?php echo esc_attr( $current ); ?>">

			<?php foreach ( $active_langs as $lang ) :
				if ( ! isset( $lang_meta[ $lang ] ) ) {
					continue;
				}
				$meta      = $lang_meta[ $lang ];
				$is_active = ( $lang === $current );
				$classes   = 'geolang-flag geolang-flag--' . $lang . ( $is_active ? ' geolang-flag--active' : '' );

				// Resolve local flag file: prefer PNG from flagsapi, fall back to bundled SVG.
				$flag_file_png = GEOLANG_PATH . 'assets/flags/' . $lang . ( 'shiny' === $flag_style ? '-shiny' : '' ) . '.png';
				$flag_file_svg = GEOLANG_PATH . 'assets/flags/' . $lang . '.svg';

				if ( file_exists( $flag_file_png ) ) {
					$suffix = ( 'shiny' === $flag_style ? '-shiny' : '' );
					$flag_src = GEOLANG_URL . 'assets/flags/' . $lang . $suffix . '.png';
				} else {
					$flag_src = GEOLANG_URL . 'assets/flags/' . $lang . '.svg';
				}
			?>
			<button
				class="<?php echo esc_attr( $classes ); ?>"
				data-lang="<?php echo esc_attr( $lang ); ?>"
				aria-label="<?php echo esc_attr( $meta['aria'] ); ?>"
				aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
				type="button">
				<?php if ( $show_flags ) : ?>
					<img
						src="<?php echo esc_url( $flag_src ); ?>"
						alt="<?php echo esc_attr( $meta['label'] ); ?>"
						width="<?php echo esc_attr( $px ); ?>"
						height="<?php echo esc_attr( $px ); ?>"
						loading="lazy"
						decoding="async" />
				<?php endif; ?>
				<?php if ( $show_text ) : ?>
					<span class="geolang-lang-label"><?php echo esc_html( $meta['label'] ); ?></span>
				<?php endif; ?>
			</button>
			<?php endforeach; ?>

		</div>
		<?php
		return ob_get_clean();
	}

	private function flag_size( $size ) {
		return array( 'sm' => 24, 'md' => 32, 'lg' => 40 )[ $size ] ?? 32;
	}
}
