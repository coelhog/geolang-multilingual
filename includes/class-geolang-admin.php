<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin panel: menus, translation management table, settings.
 */
class GeoLang_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_geolang_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_geolang_check_updates_now', array( $this, 'ajax_check_updates_now' ) );
	}

	// -----------------------------------------------------------------------
	// Menus
	// -----------------------------------------------------------------------

	public function register_menus() {
		add_menu_page(
			__( 'GeoLang', 'geolang-multilingual' ),
			__( 'GeoLang', 'geolang-multilingual' ),
			'edit_posts',
			'geolang',
			array( $this, 'render_dashboard' ),
			'dashicons-translation',
			81
		);

		add_submenu_page(
			'geolang',
			__( 'Dashboard', 'geolang-multilingual' ),
			__( 'Dashboard', 'geolang-multilingual' ),
			'edit_posts',
			'geolang',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'geolang',
			__( 'Gerenciar Traduções', 'geolang-multilingual' ),
			__( 'Gerenciar Traduções', 'geolang-multilingual' ),
			'edit_posts',
			'geolang-translations',
			array( $this, 'render_translations' )
		);

		add_submenu_page(
			'geolang',
			__( 'Menus', 'geolang-multilingual' ),
			__( '🗂️ Menus', 'geolang-multilingual' ),
			'edit_theme_options',
			'geolang-menus',
			array( $this, 'render_menus' )
		);

		add_submenu_page(
			'geolang',
			__( 'Configurações', 'geolang-multilingual' ),
			__( 'Configurações', 'geolang-multilingual' ),
			'manage_options',
			'geolang-settings',
			array( $this, 'render_settings' )
		);
	}

	// -----------------------------------------------------------------------
	// Assets
	// -----------------------------------------------------------------------

	public function enqueue_assets( $hook ) {
		$geolang_pages = array(
			'toplevel_page_geolang',
			'geolang_page_geolang-translations',
			'geolang_page_geolang-menus',
			'geolang_page_geolang-settings',
		);

		if ( ! in_array( $hook, $geolang_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'geolang-admin',
			GEOLANG_URL . 'assets/css/admin.css',
			array(),
			GEOLANG_VERSION
		);

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

		// AI translation script (only on translations page).
		if ( 'geolang_page_geolang-translations' === $hook || 'toplevel_page_geolang' === $hook ) {
			wp_enqueue_script(
				'geolang-admin-ai',
				GEOLANG_URL . 'assets/js/admin-ai.js',
				array( 'jquery', 'geolang-admin' ),
				GEOLANG_VERSION,
				true
			);

			$has_key = '' !== get_option( 'geolang_openrouter_key', '' );
			wp_localize_script(
				'geolang-admin-ai',
				'GeoLangAI',
				array(
					'hasApiKey' => $has_key,
					'i18n'      => array(
						'translateAll'  => __( '✨ Traduzir tudo incompleto', 'geolang-multilingual' ),
						'confirmBatch'  => __( 'Traduzir todos os campos incompletos? Isso pode levar alguns segundos.', 'geolang-multilingual' ),
					),
				)
			);
		}
	}

	// -----------------------------------------------------------------------
	// Dashboard
	// -----------------------------------------------------------------------

	public function render_dashboard() {
		global $wpdb;

		$table        = GeoLang_Core::table();
		$total_fields = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL

		$complete = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
			"SELECT COUNT(*) FROM {$table} WHERE lang_pt != '' AND lang_en != '' AND lang_es != ''" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$incomplete = $total_fields - $complete;

		?>
		<div class="wrap geolang-dashboard">
			<h1><?php esc_html_e( 'GeoLang – Dashboard', 'geolang-multilingual' ); ?></h1>

			<!-- Stats -->
			<div class="geolang-stats">
				<div class="geolang-stat">
					<span class="geolang-stat__number"><?php echo esc_html( $total_fields ); ?></span>
					<span class="geolang-stat__label"><?php esc_html_e( 'Campos cadastrados', 'geolang-multilingual' ); ?></span>
				</div>
				<div class="geolang-stat geolang-stat--ok">
					<span class="geolang-stat__number"><?php echo esc_html( $complete ); ?></span>
					<span class="geolang-stat__label"><?php esc_html_e( 'Completos (3 idiomas)', 'geolang-multilingual' ); ?></span>
				</div>
				<div class="geolang-stat geolang-stat--warn">
					<span class="geolang-stat__number"><?php echo esc_html( $incomplete ); ?></span>
					<span class="geolang-stat__label"><?php esc_html_e( 'Incompletos', 'geolang-multilingual' ); ?></span>
				</div>
			</div>

			<!-- Shortcodes & usage -->
			<div class="geolang-howto">
				<h2><?php esc_html_e( 'Como adicionar o seletor de idiomas', 'geolang-multilingual' ); ?></h2>

				<div class="geolang-howto__cards">

					<div class="geolang-howto__card">
						<h3>&#127988; <?php esc_html_e( 'Opção 1 — Shortcode', 'geolang-multilingual' ); ?></h3>
						<p><?php esc_html_e( 'Cole em qualquer área de texto, HTML widget ou campo do Elementor:', 'geolang-multilingual' ); ?></p>
						<div class="geolang-shortcode-group">
							<?php
							$shortcodes = array(
								'[geolang_switcher]'                                         => __( 'Flat, só bandeiras (padrão)', 'geolang-multilingual' ),
								'[geolang_switcher flag_style="shiny"]'                      => __( 'Shiny, só bandeiras', 'geolang-multilingual' ),
								'[geolang_switcher style="flags-text"]'                      => __( 'Flat + PT/BR · EN · ES', 'geolang-multilingual' ),
								'[geolang_switcher style="flags-text" flag_style="shiny"]'   => __( 'Shiny + texto', 'geolang-multilingual' ),
								'[geolang_switcher style="text"]'                            => __( 'Só texto (PT/BR · EN · ES)', 'geolang-multilingual' ),
								'[geolang_switcher size="sm"]'                               => __( 'Pequenas (24 px)', 'geolang-multilingual' ),
								'[geolang_switcher size="lg"]'                               => __( 'Grandes (40 px)', 'geolang-multilingual' ),
							);
							foreach ( $shortcodes as $sc => $desc ) :
							?>
							<div class="geolang-sc-row">
								<code class="geolang-sc-code" title="<?php esc_attr_e( 'Clique para copiar', 'geolang-multilingual' ); ?>"><?php echo esc_html( $sc ); ?></code>
								<span class="geolang-sc-desc"><?php echo esc_html( $desc ); ?></span>
								<button class="button button-small geolang-copy-btn" data-copy="<?php echo esc_attr( $sc ); ?>"><?php esc_html_e( 'Copiar', 'geolang-multilingual' ); ?></button>
							</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="geolang-howto__card">
						<h3>&#9889; <?php esc_html_e( 'Opção 2 — Widget Elementor', 'geolang-multilingual' ); ?></h3>
						<p><?php esc_html_e( 'Abra qualquer página no Elementor, busque por "GeoLang Switcher" no painel de widgets e arraste para o local desejado (header, rodapé, etc.)', 'geolang-multilingual' ); ?></p>
						<p><strong><?php esc_html_e( 'Dica:', 'geolang-multilingual' ); ?></strong> <?php esc_html_e( 'Para colocar no cabeçalho global, edite o Header via Elementor → Templates.', 'geolang-multilingual' ); ?></p>
					</div>

				</div><!-- .geolang-howto__cards -->
			</div><!-- .geolang-howto -->

			<p style="margin-top:16px;">
				<?php
				printf(
					/* translators: %s: link */
					wp_kses_post( __( 'Gerencie todos os campos traduzidos em <a href="%s">Gerenciar Traduções</a>.', 'geolang-multilingual' ) ),
					esc_url( admin_url( 'admin.php?page=geolang-translations' ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Translations page
	// -----------------------------------------------------------------------

	public function render_translations() {
		global $wpdb;

		// Get all posts that have fields registered.
		$post_ids = $wpdb->get_col( 'SELECT DISTINCT post_id FROM ' . GeoLang_Core::table() . ' ORDER BY post_id ASC' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL

		$posts_for_filter = array();
		foreach ( $post_ids as $pid ) {
			$post = get_post( absint( $pid ) );
			if ( $post ) {
				$posts_for_filter[ $pid ] = $post->post_title ? $post->post_title : sprintf( __( '(sem título) #%d', 'geolang-multilingual' ), $pid );
			}
		}

		?>
		<div class="wrap geolang-translations">
			<h1><?php esc_html_e( 'Gerenciar Traduções', 'geolang-multilingual' ); ?></h1>

			<div class="geolang-filters">
				<select id="geolang-filter-post">
					<option value=""><?php esc_html_e( '— Todos os posts —', 'geolang-multilingual' ); ?></option>
					<?php foreach ( $posts_for_filter as $pid => $title ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $title ); ?> (ID: <?php echo esc_html( $pid ); ?>)</option>
					<?php endforeach; ?>
				</select>

				<select id="geolang-filter-type">
					<option value=""><?php esc_html_e( '— Todos os tipos —', 'geolang-multilingual' ); ?></option>
					<option value="text"><?php esc_html_e( 'Texto', 'geolang-multilingual' ); ?></option>
					<option value="url"><?php esc_html_e( 'URL', 'geolang-multilingual' ); ?></option>
					<option value="image"><?php esc_html_e( 'Imagem', 'geolang-multilingual' ); ?></option>
				</select>

				<input type="text" id="geolang-filter-search" placeholder="<?php esc_attr_e( 'Buscar por chave ou texto PT…', 'geolang-multilingual' ); ?>" />

				<button class="button" id="geolang-filter-btn"><?php esc_html_e( 'Filtrar', 'geolang-multilingual' ); ?></button>

				<button class="button" id="geolang-resync-all" title="<?php esc_attr_e( 'Lê os dados Elementor de todas as páginas e atualiza a lista de traduções', 'geolang-multilingual' ); ?>" style="margin-left:auto;">
					🔄 <?php esc_html_e( 'Re-sincronizar Elementor', 'geolang-multilingual' ); ?>
				</button>
				<span id="geolang-resync-status" style="font-style:italic;color:#646970;display:none;"></span>
			</div>

			<div id="geolang-table-wrap">
				<table class="wp-list-table widefat fixed striped geolang-table">
					<thead>
						<tr>
							<th style="width:32px;"><input type="checkbox" id="geolang-check-all" title="<?php esc_attr_e( 'Selecionar todos', 'geolang-multilingual' ); ?>" /></th>
							<th><?php esc_html_e( 'Post / Página', 'geolang-multilingual' ); ?></th>
							<th><?php esc_html_e( 'Tipo', 'geolang-multilingual' ); ?></th>
							<th>&#127463;&#127479; PT</th>
							<th>&#127482;&#127480; EN</th>
							<th>&#127466;&#127480; ES</th>
							<th><?php esc_html_e( 'Status', 'geolang-multilingual' ); ?></th>
							<th><?php esc_html_e( 'Ações', 'geolang-multilingual' ); ?></th>
						</tr>
					</thead>
					<tbody id="geolang-table-body">
						<tr><td colspan="8" class="geolang-loading"><?php esc_html_e( 'Carregando…', 'geolang-multilingual' ); ?></td></tr>
					</tbody>
				</table>
				<div id="geolang-pagination"></div>
			</div>

			<!-- Edit modal -->
			<div id="geolang-modal" class="geolang-modal" style="display:none;" role="dialog" aria-modal="true">
				<div class="geolang-modal__overlay"></div>
				<div class="geolang-modal__box">
					<button class="geolang-modal__close" aria-label="<?php esc_attr_e( 'Fechar', 'geolang-multilingual' ); ?>">&times;</button>
					<h2 id="geolang-modal-title"><?php esc_html_e( 'Editar Campo', 'geolang-multilingual' ); ?></h2>

					<input type="hidden" id="geolang-edit-id" />
					<input type="hidden" id="geolang-edit-post-id" />
					<input type="hidden" id="geolang-edit-field-key" />
					<input type="hidden" id="geolang-edit-field-type" />

					<div class="geolang-modal__fields">
						<div class="geolang-modal__field">
							<label><?php esc_html_e( '🇧🇷 Português (PT)', 'geolang-multilingual' ); ?></label>
							<textarea id="geolang-edit-pt" rows="4"></textarea>
						</div>
						<div class="geolang-modal__field">
							<label><?php esc_html_e( '🇺🇸 English (EN)', 'geolang-multilingual' ); ?></label>
							<textarea id="geolang-edit-en" rows="4"></textarea>
						</div>
						<div class="geolang-modal__field">
							<label><?php esc_html_e( '🇪🇸 Español (ES)', 'geolang-multilingual' ); ?></label>
							<textarea id="geolang-edit-es" rows="4"></textarea>
						</div>
					</div>

					<div class="geolang-modal__actions">
						<button id="geolang-modal-save" class="button button-primary"><?php esc_html_e( 'Salvar', 'geolang-multilingual' ); ?></button>
						<button id="geolang-modal-cancel" class="button"><?php esc_html_e( 'Cancelar', 'geolang-multilingual' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Menus page (delegate to GeoLang_Menus)
	// -----------------------------------------------------------------------

	public function render_menus() {
		if ( class_exists( 'GeoLang_Menus' ) ) {
			( new GeoLang_Menus() )->render_admin_page();
		}
	}

	// -----------------------------------------------------------------------
	// Settings page
	// -----------------------------------------------------------------------

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'geolang-multilingual' ) );
		}

		$default      = get_option( 'geolang_default_lang', 'pt' );
		$active_langs = get_option( 'geolang_active_langs', array( 'pt', 'en', 'es' ) );
		$or_key       = get_option( 'geolang_openrouter_key', '' );
		$or_model     = get_option( 'geolang_openrouter_model', 'openai/gpt-4o-mini' );

		$all_langs = array(
			'pt' => __( 'Português (PT/BR)', 'geolang-multilingual' ),
			'en' => __( 'English (EN)', 'geolang-multilingual' ),
			'es' => __( 'Español (ES)', 'geolang-multilingual' ),
		);

		$free_models = class_exists( 'GeoLang_OpenRouter' ) ? GeoLang_OpenRouter::free_models() : array();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GeoLang – Configurações', 'geolang-multilingual' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configurações salvas.', 'geolang-multilingual' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="geolang_save_settings" />
				<?php wp_nonce_field( 'geolang_settings', 'geolang_settings_nonce' ); ?>

				<h2 class="title"><?php esc_html_e( '🌐 Idiomas', 'geolang-multilingual' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="geolang_default_lang"><?php esc_html_e( 'Idioma padrão', 'geolang-multilingual' ); ?></label>
						</th>
						<td>
							<select name="geolang_default_lang" id="geolang_default_lang">
								<?php foreach ( $all_langs as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $default, $code ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Idiomas ativos', 'geolang-multilingual' ); ?></th>
						<td>
							<?php foreach ( $all_langs as $code => $label ) : ?>
								<label>
									<input type="checkbox" name="geolang_active_langs[]" value="<?php echo esc_attr( $code ); ?>"
										<?php checked( in_array( $code, $active_langs, true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label><br />
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Mínimo de 1 idioma obrigatório.', 'geolang-multilingual' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title">🤖 <?php esc_html_e( 'Tradução Automática (OpenRouter)', 'geolang-multilingual' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Configure a IA para traduzir campos automaticamente. Obtenha sua chave grátis em', 'geolang-multilingual' ); ?>
					<a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a>.
				</p>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="geolang_openrouter_key"><?php esc_html_e( 'API Key', 'geolang-multilingual' ); ?></label>
						</th>
						<td>
							<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
								<input type="text"
									name="geolang_openrouter_key"
									id="geolang_openrouter_key"
									value="<?php echo esc_attr( $or_key ); ?>"
									class="regular-text"
									autocomplete="off"
									spellcheck="false"
									style="font-family:monospace;font-size:12px;"
									placeholder="sk-or-v1-…" />
								<button type="button" id="geolang-test-ai" class="button">
									<?php esc_html_e( 'Testar conexão', 'geolang-multilingual' ); ?>
								</button>
							</div>
							<span id="geolang-test-ai-result" style="display:block;margin-top:4px;font-style:italic;color:#646970;"></span>
							<p class="description"><?php esc_html_e( 'Sua chave fica armazenada no banco de dados e nunca é exibida no frontend. Cole aqui e clique em Salvar configurações.', 'geolang-multilingual' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="geolang_openrouter_model"><?php esc_html_e( 'Modelo (gratuito)', 'geolang-multilingual' ); ?></label>
						</th>
						<td>
							<select name="geolang_openrouter_model" id="geolang_openrouter_model">
								<?php foreach ( $free_models as $model_id => $model_label ) : ?>
									<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $or_model, $model_id ); ?>><?php echo esc_html( $model_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Salvar configurações', 'geolang-multilingual' ) ); ?>
			</form>
		</div>

		<script>
		(function($) {
			$('#geolang-test-ai').on('click', function() {
				var $btn = $(this);
				var $result = $('#geolang-test-ai-result');
				$btn.prop('disabled', true).text('Testando…');
				$result.text('');

				$.post(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					action: 'geolang_ai_test_connection',
					nonce:  <?php echo wp_json_encode( wp_create_nonce( 'geolang_admin' ) ); ?>,
				}, function(res) {
					if (res.success) {
						$result.text('✅ OK! Amostra: "' + res.data.sample + '"').css('color', '#00a32a');
					} else {
						$result.text('❌ ' + (res.data && res.data.message ? res.data.message : 'Erro')).css('color', '#d63638');
					}
				}).fail(function() {
					$result.text('❌ Erro de rede.').css('color', '#d63638');
				}).always(function() {
					$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Testar conexão', 'geolang-multilingual' ) ); ?>);
				});

			$('#geolang-check-updates-now').on('click', function () {
				var $btn = $(this);
				var $result = $('#geolang-update-check-result');
				$btn.prop('disabled', true).text('Verificando…');
				$result.text('');

				$.post(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					action: 'geolang_check_updates_now',
					nonce:  <?php echo wp_json_encode( wp_create_nonce( 'geolang_admin' ) ); ?>,
				}, function(res) {
					if (res.success) {
						$result.text(res.data.message).css('color', '#00a32a');
					} else {
						$result.text('❌ ' + (res.data && res.data.message ? res.data.message : 'Erro')).css('color', '#d63638');
					}
				}).fail(function() {
					$result.text('❌ Erro de rede.').css('color', '#d63638');
				}).always(function() {
					$btn.prop('disabled', false).text('🔍 Verificar agora');
				});
			});
		})(jQuery);
		</script>

		<?php
		// ── GitHub auto-update section ────────────────────────────────────────
		?>
		<hr style="margin:32px 0 24px;" />

		<h2 style="margin-bottom:4px;">🔄 <?php esc_html_e( 'Atualizações Automáticas', 'geolang-multilingual' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'O plugin verifica atualizações automaticamente. Quando uma nova versão for publicada, o botão "Atualizar" aparece em Plugins — igual ao WordPress.org.', 'geolang-multilingual' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Versão instalada', 'geolang-multilingual' ); ?></th>
				<td>
					<code><?php echo esc_html( GEOLANG_VERSION ); ?></code>
					&nbsp;&nbsp;
					<button type="button" class="button" id="geolang-check-updates-now">🔍 <?php esc_html_e( 'Verificar agora', 'geolang-multilingual' ); ?></button>
					<span id="geolang-update-check-result" style="margin-left:10px;font-style:italic;"></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Repositório', 'geolang-multilingual' ); ?></th>
				<td>
					<a href="https://github.com/<?php echo esc_attr( GeoLang_Updater::GITHUB_REPO ); ?>" target="_blank">
						github.com/<?php echo esc_html( GeoLang_Updater::GITHUB_REPO ); ?>
					</a>
				</td>
			</tr>
		</table>

		<?php
	}

	// -----------------------------------------------------------------------
	// Settings save handler
	// -----------------------------------------------------------------------

	public function save_settings() {
		check_admin_referer( 'geolang_settings', 'geolang_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'geolang-multilingual' ) );
		}

		$allowed = array( 'pt', 'en', 'es' );

		$default = sanitize_key( wp_unslash( $_POST['geolang_default_lang'] ?? 'pt' ) );
		if ( ! in_array( $default, $allowed, true ) ) {
			$default = 'pt';
		}

		$active = array();
		if ( isset( $_POST['geolang_active_langs'] ) && is_array( $_POST['geolang_active_langs'] ) ) {
			foreach ( $_POST['geolang_active_langs'] as $lang ) {
				$lang = sanitize_key( wp_unslash( $lang ) );
				if ( in_array( $lang, $allowed, true ) ) {
					$active[] = $lang;
				}
			}
		}

		// At least one must be active.
		if ( empty( $active ) ) {
			$active = array( 'pt' );
		}

		// Default lang must be in active langs.
		if ( ! in_array( $default, $active, true ) ) {
			$default = $active[0];
		}

		update_option( 'geolang_default_lang', $default );
		update_option( 'geolang_active_langs', $active );

		// OpenRouter settings.
		if ( isset( $_POST['geolang_openrouter_key'] ) ) {
			$or_key = sanitize_text_field( wp_unslash( $_POST['geolang_openrouter_key'] ) );
			update_option( 'geolang_openrouter_key', $or_key );
		}

		$allowed_models = class_exists( 'GeoLang_OpenRouter' ) ? array_keys( GeoLang_OpenRouter::free_models() ) : array();
		if ( isset( $_POST['geolang_openrouter_model'] ) ) {
			$or_model = sanitize_text_field( wp_unslash( $_POST['geolang_openrouter_model'] ) );
			if ( in_array( $or_model, $allowed_models, true ) ) {
				update_option( 'geolang_openrouter_model', $or_model );
			}
		}

		// GitHub auto-update: clear cache so new check uses fresh data.
		if ( isset( $_POST['geolang_github_token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_POST['geolang_github_token'] ) );
			update_option( 'geolang_github_token', $token );
		}

		// Clear the cached release info so the new settings take effect immediately.
		GeoLang_Updater::clear_cache();

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=geolang-settings' ) ) );
		exit;
	}

	// -----------------------------------------------------------------------
	// AJAX: check for updates now
	// -----------------------------------------------------------------------

	public function ajax_check_updates_now() {
		check_ajax_referer( 'geolang_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permissão negada.' ), 403 );
		}

		// Clear cache so next check hits the API fresh.
		GeoLang_Updater::clear_cache();

		// Re-instantiate updater (it reads options fresh).
		$updater = new GeoLang_Updater();

		// Access the latest release via reflection to avoid duplicating logic.
		$method  = new ReflectionMethod( $updater, 'get_latest_release' );
		$method->setAccessible( true );
		$release = $method->invoke( $updater );

		if ( ! $release ) {
			wp_send_json_error( array( 'message' => 'Não foi possível verificar. Cheque o repositório e o token.' ) );
		}

		$latest = ltrim( $release['tag_name'], 'vV' );
		if ( version_compare( $latest, GEOLANG_VERSION, '>' ) ) {
			wp_send_json_success( array(
				'message' => '🆕 Nova versão disponível: v' . $latest . '. Acesse Plugins → Atualizações para instalar.',
			) );
		} else {
			wp_send_json_success( array(
				'message' => '✅ Você está na versão mais recente (v' . GEOLANG_VERSION . ').',
			) );
		}
	}
}
