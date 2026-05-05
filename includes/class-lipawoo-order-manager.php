<?php
/**
 * M-Pesa Order Manager
 *
 * Adds M-Pesa transaction info to WooCommerce order admin pages
 *
 * @package LipaWoo
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class LipaWoo_Order_Manager {

	public function __construct() {
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_order_meta' ] );
		add_filter( 'woocommerce_order_details_after_order_table', [ $this, 'display_customer_order_meta' ] );
	}

	/**
	 * Display M-Pesa transaction info in admin order detail
	 */
	public function display_order_meta( WC_Order $order ): void {
		if ( 'lipawoo' !== $order->get_payment_method() ) {
			return;
		}

		$receipt     = $order->get_meta( '_lipawoo_receipt_number' );
		$phone       = $order->get_meta( '_lipawoo_phone' );
		$checkout_id = $order->get_meta( '_lipawoo_checkout_request_id' );
		$amount      = $order->get_meta( '_lipawoo_confirmed_amount' );
		$tx_date     = $order->get_meta( '_lipawoo_transaction_date' );

		?>
		<div class="mpesa-order-meta">
			<h3><?php esc_html_e( 'M-Pesa Transaction Details', 'lipawoo' ); ?></h3>
			<table class="mpesa-meta-table">
				<?php if ( $phone ) : ?>
				<tr>
					<td><?php esc_html_e( 'Phone Number', 'lipawoo' ); ?></td>
					<td><?php echo esc_html( $phone ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $receipt ) : ?>
				<tr>
					<td><?php esc_html_e( 'M-Pesa Receipt', 'lipawoo' ); ?></td>
					<td><strong><?php echo esc_html( $receipt ); ?></strong></td>
				</tr>
				<?php endif; ?>
				<?php if ( $amount ) : ?>
				<tr>
					<td><?php esc_html_e( 'Confirmed Amount', 'lipawoo' ); ?></td>
					<td>KES <?php echo esc_html( number_format( (float) $amount, 2 ) ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $tx_date ) : ?>
				<tr>
					<td><?php esc_html_e( 'Transaction Date', 'lipawoo' ); ?></td>
					<td><?php echo esc_html( $tx_date ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $checkout_id ) : ?>
				<tr>
					<td><?php esc_html_e( 'Checkout Request ID', 'lipawoo' ); ?></td>
					<td><small><?php echo esc_html( $checkout_id ); ?></small></td>
				</tr>
				<?php endif; ?>
			</table>

			<?php if ( $order->get_status() === 'pending' ) : ?>
			<div class="mpesa-admin-actions">
				<button type="button" class="button mpesa-query-btn" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
					<?php esc_html_e( 'Query Payment Status', 'lipawoo' ); ?>
				</button>
				<span class="mpesa-query-result"></span>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Display M-Pesa info on customer's order view
	 */
	public function display_customer_order_meta( WC_Order $order ): void {
		if ( 'lipawoo' !== $order->get_payment_method() ) {
			return;
		}

		$receipt = $order->get_meta( '_lipawoo_receipt_number' );
		if ( ! $receipt ) {
			return;
		}

		?>
		<section class="woocommerce-customer-details mpesa-customer-details">
			<h2><?php esc_html_e( 'M-Pesa Payment Details', 'lipawoo' ); ?></h2>
			<table>
				<tr>
					<th><?php esc_html_e( 'Receipt Number', 'lipawoo' ); ?></th>
					<td><?php echo esc_html( $receipt ); ?></td>
				</tr>
			</table>
		</section>
		<?php
	}
}

new LipaWoo_Order_Manager();
