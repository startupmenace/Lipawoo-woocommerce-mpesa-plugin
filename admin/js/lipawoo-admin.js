/**
 * M-Pesa Daraja WooCommerce - Admin JS
 *
 * Uses jQuery(function($){}) — the correct WordPress admin pattern.
 * This fires on DOM ready AND properly aliases jQuery as $ even in
 * WordPress no-conflict mode.
 */

jQuery(function ($) {
	'use strict';

	// ── Copy callback URL ──────────────────────────────────────────────────
	$(document).on('click', '.lipawoo-copy-btn', function () {
		var $btn   = $(this);
		var target = $btn.data('target');
		var text   = $('#' + target).text();

		navigator.clipboard.writeText(text).then(function () {
			$btn.addClass('copied').text('Copied!');
			setTimeout(function () {
				$btn.removeClass('copied').text('Copy');
			}, 2000);
		});
	});

	// ── Test API connection ────────────────────────────────────────────────
	$(document).on('click', '#lipawoo-test-connection', function () {
		var $btn    = $(this);
		var $result = $('#lipawoo-test-result');

		$btn.prop('disabled', true).text('Testing...');
		$result.removeClass('success error').text('');

		$.ajax({
			url:  lipawoo_admin_params.ajax_url,
			type: 'POST',
			data: {
				action: 'lipawoo_admin_test_connection',
				nonce:  lipawoo_admin_params.nonce,
			},
			success: function (response) {
				if (response.success) {
					$result.addClass('success').text('Connected successfully!');
				} else {
					$result.addClass('error').text('Failed: ' + response.data.message);
				}
			},
			error: function () {
				$result.addClass('error').text('Connection error. Please try again.');
			},
			complete: function () {
				$btn.prop('disabled', false).text('Test API Connection');
			},
		});
	});

	// ── Query transaction status ───────────────────────────────────────────
	$(document).on('click', '.lipawoo-query-btn', function () {
		var $btn    = $(this);
		var orderId = $btn.data('order-id');
		var $result = $btn.siblings('.lipawoo-query-result');

		$btn.prop('disabled', true).text('Querying...');

		$.ajax({
			url:  lipawoo_admin_params.ajax_url,
			type: 'POST',
			data: {
				action:   'lipawoo_admin_query',
				nonce:    lipawoo_admin_params.nonce,
				order_id: orderId,
			},
			success: function (response) {
				if (response.success) {
					var d          = response.data;
					var resultCode = (d.result && d.result.ResultCode !== undefined) ? d.result.ResultCode : 'N/A';
					var resultDesc = (d.result && d.result.ResultDesc) ? d.result.ResultDesc : 'N/A';
					var msg        = 'Code: ' + resultCode + ' | ' + resultDesc;
					var color      = (resultCode === 0 || d.order_status === 'processing') ? '#16a34a' : '#dc2626';
					$result.css('color', color).text(msg);
				} else {
					$result.css('color', '#dc2626').text(response.data.message);
				}
			},
			error: function () {
				$result.css('color', '#dc2626').text('Request failed.');
			},
			complete: function () {
				$btn.prop('disabled', false).text('Query');
			},
		});
	});

	// ── Highlight pending rows ─────────────────────────────────────────────
	$('.lipawoo-status-pending').closest('tr').css('background', '#fffbeb');

	// ── Single row delete ──────────────────────────────────────────────────
	$(document).on('click', '.lipawoo-delete-btn', function () {
		var $btn  = $(this);
		var id    = $btn.data('id');
		var order = $btn.data('order');

		if (!confirm('Delete transaction record for Order #' + order + '?\n\nThis only removes it from the M-Pesa log. The WooCommerce order is not affected.')) {
			return;
		}

		$btn.prop('disabled', true).text('Deleting...');

		$.ajax({
			url:  lipawoo_admin_params.ajax_url,
			type: 'POST',
			data: {
				action:         'lipawoo_admin_delete',
				nonce:          lipawoo_admin_params.nonce,
				transaction_id: id,
			},
			success: function (response) {
				if (response.success) {
					$btn.closest('tr').fadeOut(300, function () {
						$(this).remove();
					});
				} else {
					alert('Could not delete: ' + response.data.message);
					$btn.prop('disabled', false).text('Delete');
				}
			},
			error: function (xhr) {
				alert('Request failed (HTTP ' + xhr.status + '). Please try again.');
				$btn.prop('disabled', false).text('Delete');
			},
		});
	});

	// ── Checkbox: select all ───────────────────────────────────────────────
	$(document).on('change', '#lipawoo-select-all', function () {
		$('.lipawoo-row-check').prop('checked', this.checked);
		updateBulkBar();
	});

	$(document).on('change', '.lipawoo-row-check', function () {
		var total   = $('.lipawoo-row-check').length;
		var checked = $('.lipawoo-row-check:checked').length;
		$('#lipawoo-select-all')
			.prop('indeterminate', checked > 0 && checked < total)
			.prop('checked', checked === total && total > 0);
		updateBulkBar();
	});

	function updateBulkBar() {
		var count = $('.lipawoo-row-check:checked').length;
		if (count > 0) {
			$('#lipawoo-bulk-bar').css('display', 'flex');
			$('#lipawoo-bulk-count').text(count + ' transaction' + (count !== 1 ? 's' : '') + ' selected');
		} else {
			$('#lipawoo-bulk-bar').hide();
		}
	}

	// ── Bulk delete ────────────────────────────────────────────────────────
	$(document).on('click', '#lipawoo-bulk-cancel', function () {
		$('.lipawoo-row-check, #lipawoo-select-all').prop('checked', false);
		$('#lipawoo-bulk-bar').hide();
	});

	$(document).on('click', '#lipawoo-bulk-delete', function () {
		var ids = [];
		$('.lipawoo-row-check:checked').each(function () {
			ids.push($(this).val());
		});

		if (!ids.length) return;

		if (!confirm('Permanently delete ' + ids.length + ' transaction record(s)?\n\nWooCommerce orders are not affected.')) {
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text('Deleting...');
		var done = 0;

		$.each(ids, function (i, id) {
			$.ajax({
				url:  lipawoo_admin_params.ajax_url,
				type: 'POST',
				data: {
					action:         'lipawoo_admin_delete',
					nonce:          lipawoo_admin_params.nonce,
					transaction_id: id,
				},
				success: function (response) {
					if (response.success) {
						$('tr[data-id="' + id + '"]').fadeOut(200, function () {
							$(this).remove();
						});
					}
				},
				complete: function () {
					done++;
					if (done === ids.length) {
						$('#lipawoo-select-all').prop('checked', false);
						$('#lipawoo-bulk-bar').hide();
						$btn.prop('disabled', false).text('Delete Selected');
					}
				},
			});
		});
	});

});
