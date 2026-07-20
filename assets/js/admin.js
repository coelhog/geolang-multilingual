/* GeoLang – Admin JS
 * Powers the Translations management page: load table, filters, modal edit/save, delete, pagination.
 */
(function ($) {
	'use strict';

	var cfg      = window.GeoLangAdmin || {};
	var ajaxUrl  = cfg.ajaxUrl;
	var nonce    = cfg.nonce;
	var i18n     = cfg.i18n || {};

	var state = {
		page:     1,
		postId:   '',
		type:     '',
		search:   '',
		total:    0,
		totalPgs: 1,
	};

	// -----------------------------------------------------------------------
	// Init
	// -----------------------------------------------------------------------

	// -----------------------------------------------------------------------
	// Copy-to-clipboard for shortcode buttons (Dashboard page)
	// -----------------------------------------------------------------------
	$(function () {
		$(document).on('click', '.geolang-copy-btn', function () {
			var text = $(this).data('copy');
			var $btn = $(this);

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function () {
					showCopied($btn);
				});
			} else {
				// Fallback for older browsers.
				var $tmp = $('<textarea>').val(text).appendTo('body').select();
				document.execCommand('copy');
				$tmp.remove();
				showCopied($btn);
			}
		});

		function showCopied($btn) {
			var original = $btn.text();
			$btn.text('Copiado!').addClass('copied');
			setTimeout(function () {
				$btn.text(original).removeClass('copied');
			}, 2000);
		}
	});

	// -----------------------------------------------------------------------
	// Translations table (only runs on that page)
	// -----------------------------------------------------------------------
	$(function () {
		if (!$('#geolang-table-body').length) return;

		loadRows();

		$('#geolang-filter-btn').on('click', function () {
			state.page   = 1;
			state.postId = $('#geolang-filter-post').val();
			state.type   = $('#geolang-filter-type').val();
			state.search = $('#geolang-filter-search').val();
			loadRows();
		});

		$('#geolang-filter-search').on('keydown', function (e) {
			if (e.key === 'Enter') $('#geolang-filter-btn').trigger('click');
		});

		// Modal events.
		$(document).on('click', '.geolang-edit-btn', openModal);
		$('#geolang-modal-save').on('click', saveModal);
		$('#geolang-modal-cancel, .geolang-modal__close, .geolang-modal__overlay').on('click', closeModal);
		$(document).on('keydown', function (e) { if (e.key === 'Escape') { closeModal(); closeNewModal(); } });

		// New field modal.
		$('#geolang-new-field-btn').on('click', openNewModal);
		$('#geolang-new-save').on('click', saveNewField);
		$('#geolang-new-shortcode-copy').on('click', function () {
			var code = $('#geolang-new-shortcode-value').text();
			if (navigator.clipboard) {
				navigator.clipboard.writeText(code).then(function () {
					$('#geolang-new-shortcode-copy').text('✅ Copiado!');
					setTimeout(function () { $('#geolang-new-shortcode-copy').text('📋 Copiar'); }, 2000);
				});
			} else {
				var $tmp = $('<textarea>').val(code).appendTo('body').select();
				document.execCommand('copy');
				$tmp.remove();
				$('#geolang-new-shortcode-copy').text('✅ Copiado!');
				setTimeout(function () { $('#geolang-new-shortcode-copy').text('📋 Copiar'); }, 2000);
			}
		});

		// Delete.
		$(document).on('click', '.geolang-delete-btn', deleteRow);

		// Select-all checkbox.
		$(document).on('change', '#geolang-check-all', function () {
			var checked = $(this).is(':checked');
			$('#geolang-table-body .geolang-row-check').prop('checked', checked);
		});

		// Re-sync Elementor button.
		$('#geolang-resync-all').on('click', function () {
			var $btn    = $(this);
			var $status = $('#geolang-resync-status');

			$btn.prop('disabled', true).text('⏳ Sincronizando…');
			$status.hide();

			$.post(ajaxUrl, { action: 'geolang_resync_all', nonce: nonce }, function (res) {
				if (res.success) {
					$status.text('✅ ' + res.data.message).show();
					loadRows(); // Reload table to show newly synced fields.
				} else {
					$status.text('❌ ' + (res.data && res.data.message ? res.data.message : 'Erro.')).show();
				}
			}).fail(function () {
				$status.text('❌ Erro de rede.').show();
			}).always(function () {
				$btn.prop('disabled', false).text('🔄 Re-sincronizar Elementor');
				setTimeout(function () { $status.fadeOut(); }, 5000);
			});
		});

		// Excluir selecionados.
		$('#geolang-delete-selected').on('click', function () {
			var ids = [];
			$('#geolang-table-body .geolang-row-check:checked').each(function () {
				ids.push($(this).val());
			});

			if (!ids.length) {
				$('#geolang-bulk-status').text('⚠️ Nenhuma linha selecionada.');
				setTimeout(function () { $('#geolang-bulk-status').text(''); }, 3000);
				return;
			}

			if (!confirm('Excluir ' + ids.length + ' item(s) selecionado(s)?\nEsta ação não pode ser desfeita.')) return;

			var $btn    = $(this);
			var $status = $('#geolang-bulk-status');
			$btn.prop('disabled', true).text('⏳ Excluindo…');

			$.post(ajaxUrl, { action: 'geolang_delete_selected', nonce: nonce, ids: ids }, function (res) {
				if (res.success) {
					$status.text('✅ ' + res.data.message);
					// Remove rows from DOM instantly.
					ids.forEach(function (id) {
						$('#geolang-table-body tr[data-id="' + id + '"]').remove();
					});
					// Update select-all state.
					$('#geolang-check-all').prop('checked', false);
				} else {
					$status.text('❌ ' + (res.data && res.data.message ? res.data.message : 'Erro.'));
				}
			}).fail(function () {
				$status.text('❌ Erro de rede.');
			}).always(function () {
				$btn.prop('disabled', false).text('🗑️ Excluir selecionados');
				setTimeout(function () { $status.text(''); }, 5000);
			});
		});

		// Delete all auto-imported (elem_*) entries.
		$('#geolang-delete-imported').on('click', function () {
			if ( ! confirm('Remover todos os campos importados automaticamente (chaves "elem_...")?\n\nIsso NÃO apaga campos criados manualmente com Dynamic Tags.') ) {
				return;
			}
			var $btn    = $(this);
			var $status = $('#geolang-resync-status');

			$btn.prop('disabled', true).text('⏳ Removendo…');
			$status.hide();

			$.post(ajaxUrl, { action: 'geolang_delete_imported', nonce: nonce }, function (res) {
				if (res.success) {
					$status.text('✅ ' + res.data.message).show();
					loadRows();
				} else {
					$status.text('❌ ' + (res.data && res.data.message ? res.data.message : 'Erro.')).show();
				}
			}).fail(function () {
				$status.text('❌ Erro de rede.').show();
			}).always(function () {
				$btn.prop('disabled', false).text('🗑️ Limpar importados');
				setTimeout(function () { $status.fadeOut(); }, 6000);
			});
		});

		// Import all Elementor widget texts button.
		$('#geolang-import-elementor').on('click', function () {
			var $btn    = $(this);
			var $status = $('#geolang-resync-status');

			$btn.prop('disabled', true).text('⏳ Importando…');
			$status.hide();

			$.post(ajaxUrl, { action: 'geolang_import_elementor_text', nonce: nonce }, function (res) {
				if (res.success) {
					$status.text('✅ ' + res.data.message).show();
					loadRows();
				} else {
					$status.text('❌ ' + (res.data && res.data.message ? res.data.message : 'Erro.')).show();
				}
			}).fail(function () {
				$status.text('❌ Erro de rede.').show();
			}).always(function () {
				$btn.prop('disabled', false).text('📥 Importar textos do Elementor');
				setTimeout(function () { $status.fadeOut(); }, 8000);
			});
		});
	});

	// -----------------------------------------------------------------------
	// Load rows
	// -----------------------------------------------------------------------

	function loadRows() {
		$('#geolang-table-body').html('<tr><td colspan="8" class="geolang-loading">' + escHtml('Carregando…') + '</td></tr>');
		$('#geolang-pagination').empty();

		$.ajax({
			url: ajaxUrl,
			method: 'POST',
			data: {
				action:     'geolang_get_fields',
				nonce:      nonce,
				post_id:    state.postId,
				field_type: state.type,
				search:     state.search,
				page:       state.page,
				per_page:   20,
			},
			success: function (res) {
				if (!res.success) {
					showTableError(res.data && res.data.message ? res.data.message : 'Erro.');
					return;
				}

				var data = res.data;
				state.total    = data.total;
				state.totalPgs = data.total_pages;

				renderTable(data.rows);
				renderPagination(data.page, data.total_pages, data.total);
			},
			error: function () {
				showTableError('Erro de rede.');
			},
		});
	}

	// -----------------------------------------------------------------------
	// Render table
	// -----------------------------------------------------------------------

	function renderTable(rows) {
		if (!rows || !rows.length) {
			$('#geolang-table-body').html('<tr><td colspan="8" class="geolang-loading">Nenhum campo encontrado.</td></tr>');
			return;
		}

		var html = '';
		rows.forEach(function (row) {
			var complete = row.lang_pt && row.lang_en && row.lang_es;
			var status   = complete
				? '<span class="geolang-status--ok" title="Completo">✅</span>'
				: '<span class="geolang-status--warn" title="Incompleto">⚠️</span>';

			// Show actual post title when available; fall back to ID.
			var postTitle = (row.post_title && row.post_title.trim())
				? escHtml(row.post_title)
				: 'Post #' + escHtml(String(row.post_id));

			html += '<tr data-id="' + escAttr(String(row.id)) + '">';
			// Checkbox column
			html += '<td style="text-align:center;"><input type="checkbox" class="geolang-row-check" value="' + escAttr(String(row.id)) + '" /></td>';
			// Post / page column (no field_key — it's an implementation detail)
			html += '<td><span title="' + escAttr(row.field_key) + '">' + postTitle + '</span></td>';
			html += '<td>' + escHtml(row.field_type) + '</td>';
			html += '<td class="col-preview">' + escHtml(truncate(row.lang_pt, 60)) + '</td>';
			html += '<td class="col-preview">' + escHtml(truncate(row.lang_en, 60)) + '</td>';
			html += '<td class="col-preview">' + escHtml(truncate(row.lang_es, 60)) + '</td>';
			html += '<td>' + status + '</td>';
			html += '<td>'
				+ '<button class="button button-small geolang-edit-btn" '
				+ 'data-id="' + escAttr(String(row.id)) + '" '
				+ 'data-post-id="' + escAttr(String(row.post_id)) + '" '
				+ 'data-field-key="' + escAttr(row.field_key) + '" '
				+ 'data-field-type="' + escAttr(row.field_type) + '" '
				+ 'data-pt="' + escAttr(row.lang_pt || '') + '" '
				+ 'data-en="' + escAttr(row.lang_en || '') + '" '
				+ 'data-es="' + escAttr(row.lang_es || '') + '">'
				+ 'Editar</button> '
				+ '<button class="button button-small geolang-delete-btn" data-id="' + escAttr(String(row.id)) + '">Excluir</button>'
				+ '</td>';
			html += '</tr>';
		});

		$('#geolang-table-body').html(html);
	}

	// -----------------------------------------------------------------------
	// Pagination
	// -----------------------------------------------------------------------

	function renderPagination(current, total, totalRows) {
		if (total <= 1) return;

		var html = '<span>Página ' + escHtml(String(current)) + ' de ' + escHtml(String(total)) + ' (' + escHtml(String(totalRows)) + ' itens)</span> ';

		if (current > 1) {
			html += '<button class="button geolang-page-btn" data-page="' + (current - 1) + '">‹ Anterior</button> ';
		}

		// Page numbers (show at most 7 around current).
		var start = Math.max(1, current - 3);
		var end   = Math.min(total, current + 3);

		for (var p = start; p <= end; p++) {
			var cls = p === current ? 'button current' : 'button';
			html += '<button class="' + cls + ' geolang-page-btn" data-page="' + p + '">' + p + '</button> ';
		}

		if (current < total) {
			html += '<button class="button geolang-page-btn" data-page="' + (current + 1) + '">Próximo ›</button>';
		}

		var $pag = $('#geolang-pagination').html(html);

		$pag.on('click', '.geolang-page-btn', function () {
			state.page = parseInt($(this).data('page'), 10);
			loadRows();
		});
	}

	// -----------------------------------------------------------------------
	// New field modal
	// -----------------------------------------------------------------------

	function openNewModal() {
		$('#geolang-new-key').val('');
		$('#geolang-new-pt').val('');
		$('#geolang-new-en').val('');
		$('#geolang-new-es').val('');
		$('#geolang-new-shortcode').hide();
		$('#geolang-new-modal').fadeIn(150);
		$('#geolang-new-key').focus();
	}

	function closeNewModal() {
		$('#geolang-new-modal').fadeOut(150);
	}

	function saveNewField() {
		var key = $('#geolang-new-key').val().trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
		if (!key) {
			alert('Digite uma chave para o campo.');
			$('#geolang-new-key').focus();
			return;
		}

		var $btn = $('#geolang-new-save');
		$btn.prop('disabled', true).text('Salvando…');

		$.post(ajaxUrl, {
			action:     'geolang_save_field',
			nonce:      nonce,
			post_id:    0,
			field_key:  key,
			field_type: 'text',
			lang_pt:    $('#geolang-new-pt').val(),
			lang_en:    $('#geolang-new-en').val(),
			lang_es:    $('#geolang-new-es').val(),
		})
		.done(function (res) {
			if (res.success) {
				var shortcode = '[geolang key="' + key + '"]';
				$('#geolang-new-shortcode-value').text(shortcode);
				$('#geolang-new-shortcode').show();
				loadRows(); // refresh table
			} else {
				alert((res.data && res.data.message) || 'Erro ao salvar.');
			}
		})
		.fail(function () { alert('Erro de conexão.'); })
		.always(function () { $btn.prop('disabled', false).text('Salvar campo'); });
	}

	// Modal
	// -----------------------------------------------------------------------

	function openModal() {
		var $btn = $(this);

		$('#geolang-edit-id').val($btn.data('id'));
		$('#geolang-edit-post-id').val($btn.data('post-id'));
		$('#geolang-edit-field-key').val($btn.data('field-key'));
		$('#geolang-edit-field-type').val($btn.data('field-type'));
		$('#geolang-edit-pt').val($btn.data('pt'));
		$('#geolang-edit-en').val($btn.data('en'));
		$('#geolang-edit-es').val($btn.data('es'));

		$('#geolang-modal-title').text('Editar: ' + $btn.data('field-key'));
		$('#geolang-modal').fadeIn(150);
		$('#geolang-edit-pt').focus();
	}

	function closeModal(e) {
		// If triggered by a close button with data-target, close that modal.
		var target = e && $(e.currentTarget).data('target');
		if (target === 'geolang-new-modal') {
			closeNewModal();
			return;
		}
		$('#geolang-modal').fadeOut(150);
	}

	function saveModal() {
		var $btn = $('#geolang-modal-save');
		$btn.text(i18n.saving || 'Salvando…').prop('disabled', true);

		$.ajax({
			url: ajaxUrl,
			method: 'POST',
			data: {
				action:     'geolang_save_field',
				nonce:      nonce,
				post_id:    $('#geolang-edit-post-id').val(),
				field_key:  $('#geolang-edit-field-key').val(),
				field_type: $('#geolang-edit-field-type').val(),
				lang_pt:    $('#geolang-edit-pt').val(),
				lang_en:    $('#geolang-edit-en').val(),
				lang_es:    $('#geolang-edit-es').val(),
			},
			success: function (res) {
				if (res.success) {
					closeModal();
					loadRows();
				} else {
					alert(res.data && res.data.message ? res.data.message : (i18n.error || 'Erro ao salvar.'));
				}
			},
			error: function () {
				alert(i18n.error || 'Erro ao salvar.');
			},
			complete: function () {
				$btn.text(i18n.saved || 'Salvar').prop('disabled', false);
			},
		});
	}

	// -----------------------------------------------------------------------
	// Delete
	// -----------------------------------------------------------------------

	function deleteRow() {
		if (!confirm(i18n.confirmDelete || 'Tem certeza?')) return;

		var id  = $(this).data('id');
		var $tr = $(this).closest('tr');

		$.ajax({
			url: ajaxUrl,
			method: 'POST',
			data: { action: 'geolang_delete_field', nonce: nonce, id: id },
			success: function (res) {
				if (res.success) {
					$tr.fadeOut(200, function () { $tr.remove(); });
				} else {
					alert(res.data && res.data.message ? res.data.message : 'Erro ao excluir.');
				}
			},
		});
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	function showTableError(msg) {
		$('#geolang-table-body').html('<tr><td colspan="8" class="geolang-loading" style="color:#d63638;">' + escHtml(msg) + '</td></tr>');
	}

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function escAttr(str) {
		return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}

	function truncate(str, len) {
		str = str || '';
		return str.length > len ? str.substring(0, len) + '…' : str;
	}

})(jQuery);
