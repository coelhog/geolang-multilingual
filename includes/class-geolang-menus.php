<?php
defined( 'ABSPATH' ) || exit;

/**
 * GeoLang – WordPress nav menu translation.
 *
 * Translates menu item labels via PHP filter on wp_nav_menu_objects.
 * Translations are stored in the geolang_strings table using:
 *   post_id   = nav_menu_item post ID
 *   field_key = '_menu_label'
 *   field_type = 'text'
 *
 * Admin UI: GeoLang → Menus submenu page.
 */
class GeoLang_Menus {

	public function __construct() {
		// Frontend PHP filter.
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_menu_items' ), 10, 2 );

		// AJAX save for menu translations (GeoLang → Menus page).
		add_action( 'wp_ajax_geolang_save_menu_labels', array( $this, 'ajax_save_labels' ) );

		// ── Inline integration in WP Appearance → Menus ──────────────────────
		// Inject EN/ES fields inside each nav-menu-item editor row.
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'menu_item_custom_fields' ), 10, 4 );

		// Save when the user saves a menu (Appearance → Menus → Save).
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_menu_item_fields' ), 10, 3 );

		// Enqueue a small CSS snippet for the Menus screen.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_menus_assets' ) );
	}

	// -----------------------------------------------------------------------
	// Frontend filter
	// -----------------------------------------------------------------------

	/**
	 * Replace menu item titles when lang ≠ default.
	 *
	 * @param array    $items
	 * @param stdClass $args
	 * @return array
	 */
	public function filter_menu_items( $items, $args ) {
		$lang    = GeoLang_Session::current();
		$default = get_option( 'geolang_default_lang', 'pt' );

		if ( $lang === $default ) {
			return $items;
		}

		foreach ( $items as &$item ) {
			$translated = GeoLang_Core::get_field( $item->ID, '_menu_label', $lang );
			if ( $translated ) {
				$item->title = $translated;
			}
		}
		unset( $item );

		return $items;
	}

	// -----------------------------------------------------------------------
	// Inline fields: Appearance → Menus
	// -----------------------------------------------------------------------

	/**
	 * Render EN/ES input fields inside each nav-menu-item row.
	 * Hook: wp_nav_menu_item_custom_fields
	 *
	 * @param int     $item_id  Nav menu item post ID.
	 * @param WP_Post $item     Nav menu item post object.
	 * @param int     $depth    Menu depth.
	 * @param object  $args     Menu args.
	 */
	public function menu_item_custom_fields( $item_id, $item, $depth, $args ) {
		global $wpdb;
		$table = GeoLang_Core::table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT lang_en, lang_es FROM {$table} WHERE post_id = %d AND field_key = '_menu_label'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$item_id
			)
		);

		$en = $row ? $row->lang_en : '';
		$es = $row ? $row->lang_es : '';

		wp_nonce_field( 'geolang_menu_item_' . $item_id, '_geolang_nonce_' . $item_id );
		?>
		<div class="geolang-menu-item-fields field-geolang_translations" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;align-items:center;">
			<span style="font-size:11px;font-weight:600;color:#646970;flex-basis:100%;margin-bottom:2px;">🌐 GeoLang</span>
			<label style="display:flex;flex-direction:column;gap:3px;flex:1;min-width:140px;">
				<span style="font-size:11px;">🇺🇸 EN</span>
				<input type="text"
					name="geolang_menu_en[<?php echo esc_attr( $item_id ); ?>]"
					value="<?php echo esc_attr( $en ); ?>"
					placeholder="<?php echo esc_attr( $item->title ); ?>"
					class="widefat geolang-menu-inline-en"
					style="font-size:12px;" />
			</label>
			<label style="display:flex;flex-direction:column;gap:3px;flex:1;min-width:140px;">
				<span style="font-size:11px;">🇪🇸 ES</span>
				<input type="text"
					name="geolang_menu_es[<?php echo esc_attr( $item_id ); ?>]"
					value="<?php echo esc_attr( $es ); ?>"
					placeholder="<?php echo esc_attr( $item->title ); ?>"
					class="widefat geolang-menu-inline-es"
					style="font-size:12px;" />
			</label>
		</div>
		<?php
	}

	/**
	 * Save EN/ES translations when a nav menu item is saved.
	 * Hook: wp_update_nav_menu_item
	 *
	 * @param int   $menu_id         Nav menu ID.
	 * @param int   $menu_item_db_id Nav menu item post ID.
	 * @param array $args            Menu item update args.
	 */
	public function save_menu_item_fields( $menu_id, $menu_item_db_id, $args ) {
		// Nonce check.
		$nonce_key = '_geolang_nonce_' . $menu_item_db_id;
		if ( ! isset( $_POST[ $nonce_key ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_key ] ) ), 'geolang_menu_item_' . $menu_item_db_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		// Retrieve submitted values.
		$lang_en = isset( $_POST['geolang_menu_en'][ $menu_item_db_id ] )
			? sanitize_text_field( wp_unslash( $_POST['geolang_menu_en'][ $menu_item_db_id ] ) )
			: '';

		$lang_es = isset( $_POST['geolang_menu_es'][ $menu_item_db_id ] )
			? sanitize_text_field( wp_unslash( $_POST['geolang_menu_es'][ $menu_item_db_id ] ) )
			: '';

		// Use the menu item's current title as PT source.
		$post    = get_post( $menu_item_db_id );
		$lang_pt = $post ? $post->post_title : '';

		global $wpdb;
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			GeoLang_Core::table(),
			array(
				'post_id'    => $menu_item_db_id,
				'field_key'  => '_menu_label',
				'field_type' => 'text',
				'lang_pt'    => $lang_pt,
				'lang_en'    => $lang_en,
				'lang_es'    => $lang_es,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Enqueue tiny CSS on the nav-menus admin screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_menus_assets( $hook ) {
		if ( 'nav-menus.php' !== $hook ) {
			return;
		}
		// Inline style — no extra file needed.
		$css = '
			.geolang-menu-item-fields { padding: 4px 0 8px; border-top: 1px dashed #e0e0e0; margin-top: 6px; }
			.geolang-menu-item-fields input[type="text"] { height: 26px; padding: 3px 6px; }
		';
		wp_add_inline_style( 'nav-menus', $css );
	}

	// -----------------------------------------------------------------------
	// AJAX save
	// -----------------------------------------------------------------------

	public function ajax_save_labels() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissão negada.', 'geolang-multilingual' ) ), 403 );
		}

		$items = isset( $_POST['items'] ) && is_array( $_POST['items'] )
			? wp_unslash( $_POST['items'] )
			: array();

		global $wpdb;
		$table = GeoLang_Core::table();
		$saved = 0;

		foreach ( $items as $item ) {
			$item_id = absint( $item['item_id'] ?? 0 );
			$lang_en  = sanitize_text_field( $item['lang_en'] ?? '' );
			$lang_es  = sanitize_text_field( $item['lang_es'] ?? '' );

			if ( ! $item_id ) {
				continue;
			}

			// Fetch existing PT (original label) so the row is complete.
			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT lang_pt FROM {$table} WHERE post_id = %d AND field_key = '_menu_label'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$item_id
				)
			);

			if ( ! $existing ) {
				// Use the actual menu item title as PT fallback.
				$post      = get_post( $item_id );
				$lang_pt   = $post ? $post->post_title : '';
			} else {
				$lang_pt = $existing->lang_pt;
			}

			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'post_id'    => $item_id,
					'field_key'  => '_menu_label',
					'field_type' => 'text',
					'lang_pt'    => $lang_pt,
					'lang_en'    => $lang_en,
					'lang_es'    => $lang_es,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			$saved++;
		}

		wp_send_json_success( array( 'saved' => $saved ) );
	}

	// -----------------------------------------------------------------------
	// Admin page render (called from GeoLang_Admin)
	// -----------------------------------------------------------------------

	public function render_admin_page() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'Permissão negada.', 'geolang-multilingual' ) );
		}

		$menus = wp_get_nav_menus();

		global $wpdb;
		$table = GeoLang_Core::table();

		?>
		<div class="wrap geolang-menus">
			<h1><?php esc_html_e( '🌐 GeoLang – Menus de Navegação', 'geolang-multilingual' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Defina os labels EN e ES para cada item de menu. Deixe em branco para usar o texto original (PT).', 'geolang-multilingual' ); ?></p>

			<?php if ( empty( $menus ) ) : ?>
				<p><?php esc_html_e( 'Nenhum menu de navegação encontrado. Crie menus em Aparência → Menus.', 'geolang-multilingual' ); ?></p>
			<?php else : ?>

			<div id="geolang-menu-status" style="display:none;margin:8px 0;padding:8px 12px;border-radius:4px;"></div>

			<?php foreach ( $menus as $menu ) :
				$items = wp_get_nav_menu_items( $menu->term_id );
				if ( ! $items ) continue;

				// Pre-load existing translations for these items.
				$item_ids = array_map( function( $i ) { return $i->ID; }, $items );
				$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
				$saved_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT post_id, lang_en, lang_es FROM {$table} WHERE post_id IN ({$placeholders}) AND field_key = '_menu_label'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						...$item_ids
					),
					OBJECT_K
				);
			?>
			<div class="geolang-menu-section" data-menu-id="<?php echo esc_attr( $menu->term_id ); ?>">
				<h2 style="margin-top:20px;font-size:1rem;border-bottom:1px solid #c3c4c7;padding-bottom:6px;">
					📋 <?php echo esc_html( $menu->name ); ?>
				</h2>

				<table class="wp-list-table widefat fixed striped" style="max-width:960px;">
					<thead>
						<tr>
							<th style="width:30%"><?php esc_html_e( '🇧🇷 Label original (PT)', 'geolang-multilingual' ); ?></th>
							<th style="width:30%"><?php esc_html_e( '🇺🇸 Inglês (EN)', 'geolang-multilingual' ); ?></th>
							<th style="width:30%"><?php esc_html_e( '🇪🇸 Espanhol (ES)', 'geolang-multilingual' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $item ) :
							$en = $saved_rows[ $item->ID ]->lang_en ?? '';
							$es = $saved_rows[ $item->ID ]->lang_es ?? '';
						?>
						<tr class="geolang-menu-item-row" data-item-id="<?php echo esc_attr( $item->ID ); ?>">
							<td>
								<strong><?php echo esc_html( $item->title ); ?></strong>
								<?php if ( $item->menu_item_parent > 0 ) : ?>
									<span style="color:#8c8f94;font-size:11px;"> ↳ subnível</span>
								<?php endif; ?>
							</td>
							<td>
								<input type="text"
									class="regular-text geolang-menu-en"
									value="<?php echo esc_attr( $en ); ?>"
									placeholder="<?php echo esc_attr( $item->title ); ?>" />
							</td>
							<td>
								<input type="text"
									class="regular-text geolang-menu-es"
									value="<?php echo esc_attr( $es ); ?>"
									placeholder="<?php echo esc_attr( $item->title ); ?>" />
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin-top:8px;">
					<button class="button button-primary geolang-save-menu"
						data-menu-id="<?php echo esc_attr( $menu->term_id ); ?>">
						💾 <?php esc_html_e( 'Salvar este menu', 'geolang-multilingual' ); ?>
					</button>
				</p>
			</div>
			<?php endforeach; ?>

			<script>
			(function($) {
				var nonce = <?php echo wp_json_encode( wp_create_nonce( 'geolang_admin' ) ); ?>;
				var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var $status = $('#geolang-menu-status');

				$(document).on('click', '.geolang-save-menu', function() {
					var $btn = $(this);
					var $section = $btn.closest('.geolang-menu-section');
					var items = [];

					$section.find('.geolang-menu-item-row').each(function() {
						items.push({
							item_id: $(this).data('item-id'),
							lang_en: $(this).find('.geolang-menu-en').val(),
							lang_es: $(this).find('.geolang-menu-es').val(),
						});
					});

					$btn.prop('disabled', true).text('Salvando…');

					$.post(ajaxUrl, {
						action: 'geolang_save_menu_labels',
						nonce: nonce,
						items: items,
					}, function(res) {
						if (res.success) {
							$status.text('✅ Salvo! ' + res.data.saved + ' itens atualizados.')
								.css({display:'block', background:'#edfaef', color:'#00a32a', border:'1px solid #b5e6be', borderRadius:'4px', padding:'8px 12px'});
						} else {
							$status.text('❌ Erro ao salvar.').css({display:'block', background:'#fef0f0', color:'#d63638'});
						}
						setTimeout(function() { $status.fadeOut(); }, 3000);
					}).always(function() {
						$btn.prop('disabled', false).text('💾 Salvar este menu');
					});
				});
			})(jQuery);
			</script>

			<?php endif; ?>
		</div>
		<?php
	}
}
