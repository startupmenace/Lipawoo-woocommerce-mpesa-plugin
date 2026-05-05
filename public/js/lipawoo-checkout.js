/**
 * M-Pesa Daraja WooCommerce - Frontend JS
 *
 * Each poll calls STK Query via AJAX. Polling STOPS immediately on any
 * terminal state: paid, cancelled, wrong PIN, insufficient funds, expired, etc.
 * Only 'pending' (transaction still in progress) keeps the poll going.
 */

(function ($) {
	'use strict';

	// ── Checkout phone input formatting ────────────────────────────────────
	$(document).on('input', '#lipawoo_phone', function () {
		let val = this.value.replace(/\D/g, '');
		val = val.replace(/^254/, '').replace(/^0/, '');
		this.value = val;
	});

	// ── Payment waiting page ───────────────────────────────────────────────
	const $container = $('#lipawoo-payment-waiting');
	if (!$container.length) return;

	const orderId         = $container.data('order-id');
	const pollingInterval = parseInt($container.data('polling-interval'), 10) || 5000;
	const pollingTimeout  = parseInt($container.data('polling-timeout'), 10) || 120;
	const successUrl      = $container.data('success-url');

	let pollTimer   = null;
	let hasFinished = false;
	let pollCount   = 0;
	const maxPolls  = Math.floor((pollingTimeout * 1000) / pollingInterval);

	// Progress bar drains over timeout
	$('#lipawoo-progress-fill').css('animation-duration', pollingTimeout + 's');

	// Countdown display
	let secondsLeft = pollingTimeout;
	const $timerEl  = $('#lipawoo-timer');
	const countdown = setInterval(function () {
		secondsLeft = Math.max(0, secondsLeft - 1);
		$timerEl.text(secondsLeft + 's');
		if (secondsLeft === 0) clearInterval(countdown);
	}, 1000);

	/**
	 * Poll once — asks server to call STK Query and return the result.
	 *
	 * payment_status values:
	 *   'paid'    → success, redirect
	 *   'pending' → still waiting, poll again
	 *   'failed'  → terminal (cancelled / wrong PIN / no balance / expired / etc.)
	 */
	function poll() {
		if (hasFinished) return;

		$.ajax({
			url:  lipawoo_params.ajax_url,
			type: 'POST',
			data: {
				action:   'lipawoo_check_payment_status',
				nonce:    lipawoo_params.nonce,
				order_id: orderId,
			},
			success: function (response) {
				if (!response.success) {
					// Unexpected server-side error — keep polling, don't stop
					scheduleNext();
					return;
				}

				const { payment_status, redirect_url, user_message } = response.data;

				if (payment_status === 'paid') {
					finish('success', redirect_url || successUrl);

				} else if (payment_status === 'failed') {
					// Terminal — stop immediately and show the specific reason
					finish('failed', null, user_message || lipawoo_params.i18n.payment_failed);

				} else {
					// 'pending' — Safaricom says transaction is still in progress
					scheduleNext();
				}
			},
			error: function () {
				// Network/server error — keep trying until timeout
				scheduleNext();
			},
		});
	}

	function scheduleNext() {
		if (hasFinished) return;
		pollCount++;
		if (pollCount >= maxPolls) {
			finish('timeout', null, lipawoo_params.i18n.timeout);
			return;
		}
		pollTimer = setTimeout(poll, pollingInterval);
	}

	/**
	 * Terminal outcome — stop everything, show appropriate UI.
	 */
	function finish(outcome, redirectUrl, message) {
		if (hasFinished) return;
		hasFinished = true;

		clearTimeout(pollTimer);
		clearInterval(countdown);

		$('.lipawoo-status-area, .lipawoo-instructions-card').fadeOut(300);

		if (outcome === 'success') {
			$('#lipawoo-success-state').fadeIn(500);
			setTimeout(function () {
				window.location.href = redirectUrl;
			}, 1800);

		} else {
			$('#lipawoo-status-badge').hide();

			// Show specific message from server (e.g. "Wrong PIN", "Cancelled", "No balance")
			if (message) {
				$('#lipawoo-failed-msg').text(message);
			}
			if (outcome === 'timeout') {
				$('#lipawoo-failed-title').text(lipawoo_params.i18n.timeout);
				$('#lipawoo-failed-msg').text(lipawoo_params.i18n.timeout_msg || message);
			}

			$('#lipawoo-failed-state').fadeIn(500);
		}
	}

	// ── Resend STK Push ────────────────────────────────────────────────────
	$(document).on('click', '#lipawoo-resend-btn', function () {
		const $btn = $(this);
		$btn.prop('disabled', true).text('Sending...');

		$.ajax({
			url:  lipawoo_params.ajax_url,
			type: 'POST',
			data: {
				action:   'lipawoo_resend_stk',
				nonce:    lipawoo_params.nonce,
				order_id: orderId,
			},
			success: function (response) {
				if (response.success) {
					// Reset state and start polling fresh
					hasFinished  = false;
					pollCount    = 0;
					secondsLeft  = pollingTimeout;
					$timerEl.text(secondsLeft + 's');

					$('#lipawoo-failed-state').fadeOut(300, function () {
						$('.lipawoo-status-area, .lipawoo-instructions-card').fadeIn(300);
					});

					// Restart countdown
					const cd = setInterval(function () {
						secondsLeft = Math.max(0, secondsLeft - 1);
						$timerEl.text(secondsLeft + 's');
						if (secondsLeft === 0) clearInterval(cd);
					}, 1000);

					// Give Safaricom a moment to register the new request before querying
					pollTimer = setTimeout(poll, pollingInterval);

				} else {
					alert(response.data.message || 'Failed to resend. Please try again.');
					$btn.prop('disabled', false).text(lipawoo_params.i18n.resend_stk);
				}
			},
			error: function () {
				$btn.prop('disabled', false).text(lipawoo_params.i18n.resend_stk);
			},
		});
	});

	// Start first poll after one interval (give Safaricom time to process the STK)
	pollTimer = setTimeout(poll, pollingInterval);

}(jQuery));
