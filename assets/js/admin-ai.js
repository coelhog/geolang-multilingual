/* GeoLang – AI Translation buttons (OpenRouter)
 * Adds ✨ IA button to each incomplete row in Gerenciar Traduções
 * and a "Traduzir tudo incompleto" batch button at the top.
 *
 * Depends on GeoLangAdmin (nonce, ajaxUrl) and GeoLangAI.hasApiKey being set.
 */
(function ($) {
	'use strict';

	var cfg     = window.GeoLangAdmin || {};
	var aiCfg   = window.GeoLangAI    || {};
	var ajaxUrl = cfg.ajaxUrl;
	var nonce   = cfg.nonce;

	if (!ajaxUrl || !nonce) return;

	// -----------------------------------------------------------------------
	// "Traduzir selecionados" — button is now static in PHP HTML
	// -----------------------------------------------------------------------

	$(function () {
		if (!$('#geolang-table-body').length) return;

		var $selBtn    = $('#geolang-ai-translate-selected');
		var $bulkStatus = $('#geolang-bulk-status');

		// Hide the translate button if no API key configured.
		if (!aiCfg.hasApiKey) {
			$selBtn.hide();
		}

		$selBtn.on('click', function () {
			var ids = [];
			$('#geolang-table-body .geolang-row-check:checked').each(function () {
				ids.push($(this).val());
			});

			if (!ids.length) {
				$bulkStatus.text('⚠️ Nenhuma linha selecionada.');
				setTimeout(function () { $bulkStatus.text(''); }, 3000);
				return;
			}

			if (!confirm('Traduzir ' + ids.length + ' item(s) selecionado(s)?')) return;

			$selBtn.prop('disabled', true).text('⏳ Traduzindo…');
			$bulkStatus.text('');

			$.post(ajaxUrl, {
				action: 'geolang_ai_translate_selected',
				nonce:  nonce,
				ids:    ids,
			}, function (res) {
				if (res.success) {
					var msg = '✅ ' + res.data.processed + ' campo(s) traduzido(s).';
					if (res.data.errors && res.data.errors.length) {
						msg += ' ⚠️ ' + res.data.errors.length + ' erro(s).';
					}
					$bulkStatus.text(msg);

					// Update rows inline.
					if (res.data.results) {
						$.each(res.data.results, function (rowId, vals) {
							var $tr = $('#geolang-table-body tr[data-id="' + rowId + '"]');
							if (!$tr.length) return;
							var cells = $tr.find('td');
							if (cells.length >= 7) {
								$(cells[4]).text(truncate(vals.lang_en, 60));
								$(cells[5]).text(truncate(vals.lang_es, 60));
							}
							$tr.find('.geolang-status--warn').replaceWith('<span class="geolang-status--ok" title="Completo">✅</span>');
							var $editBtn = $tr.find('.geolang-edit-btn');
							$editBtn.data('en', vals.lang_en).attr('data-en', vals.lang_en);
							$editBtn.data('es', vals.lang_es).attr('data-es', vals.lang_es);
							$tr.find('.geolang-ai-btn').remove();
						});
					}
				} else {
					$bulkStatus.text('❌ ' + (res.data && res.data.message ? res.data.message : 'Erro.'));
				}
			}).fail(function () {
				$bulkStatus.text('❌ Erro de rede.');
			}).always(function () {
				$selBtn.prop('disabled', false).text('✨ Traduzir selecionados');
				setTimeout(function () { $bulkStatus.text(''); }, 6000);
			});
		});
	});

	// -----------------------------------------------------------------------
	// Inject ✨ IA button into each row after table renders
	// -----------------------------------------------------------------------

	// We hook into the custom event fired by admin.js after table renders.
	// Since we can't modify admin.js directly, we use MutationObserver on #geolang-table-body.

	$(function () {
		var $tbody = $('#geolang-table-body');
		if (!$tbody.length || !aiCfg.hasApiKey) return;

		var observer = new MutationObserver(function () {
			injectAIButtons();
		});

		observer.observe($tbody[0], { childList: true });

		// Also inject on initial load (table may already be populated).
		setTimeout(injectAIButtons, 500);
	});

	function injectAIButtons() {
		$('#geolang-table-body tr').each(function () {
			var $tr = $(this);
			if ($tr.find('.geolang-ai-btn').length) return; // already injected

			var $editBtn = $tr.find('.geolang-edit-btn');
			if (!$editBtn.length) return;

			var fieldType = $editBtn.data('field-type') || 'text';
			var langEn    = $editBtn.data('en')  || '';
			var langEs    = $editBtn.data('es')  || '';
			var langPt    = $editBtn.data('pt')  || '';

			// URL / image: show badge instead of AI button.
			if (fieldType === 'url' || fieldType === 'image') {
				var badge = fieldType === 'url'
					? '<span class="geolang-type-badge geolang-type-badge--url">🔗 Link</span>'
					: '<span class="geolang-type-badge geolang-type-badge--image">🖼️ Imagem</span>';
				$editBtn.after(' ' + badge);
				return;
			}

			// Text fields: show AI button only when something is missing.
			var incomplete = !langEn.trim() || !langEs.trim();
			if (!incomplete || !langPt.trim()) return;

			var $aiBtn = $('<button class="button button-small geolang-ai-btn" title="Traduzir automaticamente com IA">✨ IA</button>');
			$editBtn.after(' ', $aiBtn);

			$aiBtn.on('click', function () {
				translateRow($tr, $editBtn, $aiBtn);
			});
		});
	}

	// -----------------------------------------------------------------------
	// Translate a single row
	// -----------------------------------------------------------------------

	function translateRow($tr, $editBtn, $aiBtn) {
		$aiBtn.prop('disabled', true).text('⏳');

		$.post(ajaxUrl, {
			action:     'geolang_ai_translate_field',
			nonce:      nonce,
			post_id:    $editBtn.data('post-id'),
			field_key:  $editBtn.data('field-key'),
			field_type: $editBtn.data('field-type'),
			lang_pt:    $editBtn.data('pt')  || '',
			lang_en:    $editBtn.data('en')  || '',
			lang_es:    $editBtn.data('es')  || '',
		}, function (res) {
			if (res.success) {
				// Update data attributes on edit button so modal reflects new values.
				$editBtn.data('en', res.data.lang_en).attr('data-en', res.data.lang_en);
				$editBtn.data('es', res.data.lang_es).attr('data-es', res.data.lang_es);

				// Update preview cells in the row (+1 for checkbox column).
				var cells = $tr.find('td');
				if (cells.length >= 7) {
					$(cells[3]).text(truncate(res.data.lang_pt, 60));
					$(cells[4]).text(truncate(res.data.lang_en, 60));
					$(cells[5]).text(truncate(res.data.lang_es, 60));
				}

				// Update status cell.
				$tr.find('.geolang-status--warn').replaceWith('<span class="geolang-status--ok" title="Completo">✅</span>');

				// Remove AI button since it's complete now.
				$aiBtn.remove();

				showRowFeedback($tr, '✅');
			} else {
				showRowFeedback($tr, '❌ ' + (res.data && res.data.message ? res.data.message : 'Erro'));
				$aiBtn.prop('disabled', false).text('✨ IA');
			}
		}).fail(function () {
			showRowFeedback($tr, '❌ Rede');
			$aiBtn.prop('disabled', false).text('✨ IA');
		});
	}

	// -----------------------------------------------------------------------
	// Modal AI buttons
	// -----------------------------------------------------------------------

	$(document).on('click', '#geolang-modal-save', function () {
		// After modal save, refresh AI button state (delegated via MutationObserver above).
	});

	// Add ✨ AI button inside the modal for each lang field.
	$(document).on('click', '.geolang-edit-btn', function () {
		if (!aiCfg.hasApiKey) return;

		setTimeout(function () {
			var $modal = $('#geolang-modal');
			if (!$modal.is(':visible')) return;
			if ($modal.find('.geolang-modal-ai-btn').length) return; // already added

			var $ptField = $('#geolang-edit-pt');
			var $enField = $('#geolang-edit-en');
			var $esField = $('#geolang-edit-es');
			var fieldType = $('#geolang-edit-field-type').val() || 'text';

			if (fieldType === 'url' || fieldType === 'image') return;

			// Check for HTML content.
			var ptVal = $ptField.val() || '';
			var hasHtml = /<[a-z][\s\S]*>/i.test(ptVal);
			if (hasHtml) {
				$ptField.closest('.geolang-modal__field').append(
					'<p class="geolang-html-note" style="color:#7f54b3;font-size:11px;margin:4px 0 0;">📝 Conteúdo HTML — a IA preserva as tags automaticamente.</p>'
				);
			}

			// Add "Traduzir tudo" button in modal actions.
			$('.geolang-modal__actions').prepend(
				'<button type="button" id="geolang-modal-ai" class="button geolang-modal-ai-btn" style="margin-right:8px;">✨ Traduzir com IA</button>'
			);

			$('#geolang-modal-ai').on('click', function () {
				var $btn = $(this);
				$btn.prop('disabled', true).text('⏳ Traduzindo…');

				$.post(ajaxUrl, {
					action:     'geolang_ai_translate_field',
					nonce:      nonce,
					post_id:    $('#geolang-edit-post-id').val(),
					field_key:  $('#geolang-edit-field-key').val(),
					field_type: fieldType,
					lang_pt:    $ptField.val(),
					lang_en:    $enField.val(),
					lang_es:    $esField.val(),
				}, function (res) {
					if (res.success) {
						if (!$enField.val().trim()) $enField.val(res.data.lang_en);
						if (!$esField.val().trim()) $esField.val(res.data.lang_es);
						$btn.text('✅ Traduzido!');
					} else {
						alert(res.data && res.data.message ? res.data.message : 'Erro ao traduzir.');
						$btn.prop('disabled', false).text('✨ Traduzir com IA');
					}
				}).fail(function () {
					alert('Erro de rede.');
					$btn.prop('disabled', false).text('✨ Traduzir com IA');
				});
			});
		}, 200);
	});

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	function truncate(str, len) {
		str = str || '';
		return str.length > len ? str.substring(0, len) + '…' : str;
	}

	function showRowFeedback($tr, msg) {
		var $fb = $('<td colspan="1" style="color:#646970;font-size:12px;">' + msg + '</td>');
		// Just flash the status cell briefly.
		var $statusCell = $tr.find('.geolang-status--ok, .geolang-status--warn').closest('td');
		var orig = $statusCell.html();
		$statusCell.html(msg);
		setTimeout(function () { $statusCell.html(orig); }, 2500);
	}

})(jQuery);
