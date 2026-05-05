<?php
/**
 * Payment Waiting Template
 *
 * Shown after order is placed, waiting for M-Pesa payment
 *
 * @var WC_Order $order
 * @var int $polling_interval
 * @var int $polling_timeout
 *
 * @package LipaWoo
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

$phone = $order->get_meta( '_lipawoo_phone' );
$formatted_phone = substr( $phone, 0, 3 ) . 'XXXXXX' . substr( $phone, -3 );
?>
<div class="lipawoo-waiting-container" id="lipawoo-payment-waiting"
	data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
	data-polling-interval="<?php echo esc_attr( $polling_interval ); ?>"
	data-polling-timeout="<?php echo esc_attr( $polling_timeout ); ?>"
	data-success-url="<?php echo esc_url( $order->get_checkout_order_received_url() ); ?>"
>
	<!-- Phone Icon & Title -->
	<div class="lipawoo-waiting-header">
		<div class="lipawoo-phone-icon">
			<div class="lipawoo-phone-screen">
				<div class="lipawoo-phone-notification">
					<div class="lipawoo-lipawoo-logo-sm">M</div>
					<div class="lipawoo-notif-text">
						<strong>M-PESA</strong>
						<span>Payment request sent</span>
					</div>
				</div>
			</div>
		</div>
		<h2 class="lipawoo-waiting-title">
			<?php esc_html_e( 'Complete Your Payment', 'lipawoo' ); ?>
		</h2>
	</div>

	<!-- Status & Progress -->
	<div class="lipawoo-status-area">
		<div class="lipawoo-status-badge" id="lipawoo-status-badge">
			<div class="lipawoo-spinner"></div>
			<span id="lipawoo-status-text">
				<?php esc_html_e( 'Waiting for payment...', 'lipawoo' ); ?>
			</span>
		</div>

		<div class="lipawoo-progress-bar">
			<div class="lipawoo-progress-fill" id="lipawoo-progress-fill"></div>
		</div>
		<div class="lipawoo-timer-display">
			<span id="lipawoo-timer"><?php echo esc_html( $polling_timeout ); ?>s</span>
			<?php esc_html_e( 'remaining', 'lipawoo' ); ?>
		</div>
	</div>

	<!-- Instructions -->
	<div class="lipawoo-instructions-card">
		<h3><?php esc_html_e( 'Follow these steps on your phone:', 'lipawoo' ); ?></h3>
		<ol class="lipawoo-steps-list">
			<li>
				<span class="lipawoo-step-num">1</span>
				<span>
					<?php
					printf(
						/* translators: %s: masked phone number */
						esc_html__( 'Check for an M-Pesa prompt on %s', 'lipawoo' ),
						'<strong>' . esc_html( $formatted_phone ) . '</strong>'
					);
					?>
				</span>
			</li>
			<li>
				<span class="lipawoo-step-num">2</span>
				<span>
					<?php
					printf(
						/* translators: %s: order amount */
						esc_html__( 'Confirm the payment of %s', 'lipawoo' ),
						'<strong>KES ' . esc_html( number_format( (float) $order->get_total(), 2 ) ) . '</strong>'
					);
					?>
				</span>
			</li>
			<li>
				<span class="lipawoo-step-num">3</span>
				<span><?php esc_html_e( 'Enter your M-Pesa PIN to complete payment', 'lipawoo' ); ?></span>
			</li>
		</ol>
	</div>

	<!-- Success State (hidden initially) -->
	<div class="lipawoo-success-state" id="lipawoo-success-state" style="display:none;">
		<div class="lipawoo-success-icon">
			<svg viewBox="0 0 52 52" fill="none">
				<circle cx="26" cy="26" r="25" stroke="#22c55e" stroke-width="2"/>
				<path d="M14 26l8 8 16-16" stroke="#22c55e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
		</div>
		<h3><?php esc_html_e( 'Payment Received!', 'lipawoo' ); ?></h3>
		<p><?php esc_html_e( 'Your M-Pesa payment was successful. Redirecting...', 'lipawoo' ); ?></p>
	</div>

	<!-- Failed State (hidden initially) -->
	<div class="lipawoo-failed-state" id="lipawoo-failed-state" style="display:none;">
		<div class="lipawoo-failed-icon">
			<svg viewBox="0 0 52 52" fill="none">
				<circle cx="26" cy="26" r="25" stroke="#ef4444" stroke-width="2"/>
				<path d="M18 18l16 16M34 18l-16 16" stroke="#ef4444" stroke-width="3" stroke-linecap="round"/>
			</svg>
		</div>
		<h3 id="lipawoo-failed-title"><?php esc_html_e( 'Payment Not Completed', 'lipawoo' ); ?></h3>
		<p id="lipawoo-failed-msg"><?php esc_html_e( 'Your M-Pesa payment was not received. Please try again.', 'lipawoo' ); ?></p>
		<button type="button" class="lipawoo-btn lipawoo-btn-primary" id="lipawoo-resend-btn">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				<path d="M1 4v6h6M23 20v-6h-6"/>
				<path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15"/>
			</svg>
			<?php esc_html_e( 'Resend Payment Request', 'lipawoo' ); ?>
		</button>
	</div>

	<!-- Order Summary -->
	<div class="lipawoo-order-summary">
		<div class="lipawoo-order-summary-row">
			<span><?php esc_html_e( 'Order Number', 'lipawoo' ); ?></span>
			<span><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong></span>
		</div>
		<div class="lipawoo-order-summary-row">
			<span><?php esc_html_e( 'Amount Due', 'lipawoo' ); ?></span>
			<span><strong>KES <?php echo esc_html( number_format( (float) $order->get_total(), 2 ) ); ?></strong></span>
		</div>
	</div>
</div>
