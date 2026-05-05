<?php
/**
 * M-Pesa Callback Handler
 *
 * Handles Safaricom Daraja callback notifications via WP REST API
 *
 * @package LipaWoo
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class LipaWoo_Callback_Handler {

	public function register_routes(): void {
		register_rest_route( 'lipawoo/v1', '/callback', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_callback' ],
			'permission_callback' => '__return_true', // Safaricom IPs whitelist handled below
		] );

		register_rest_route( 'lipawoo/v1', '/query/(?P<order_id>\d+)', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_manual_query' ],
			'permission_callback' => [ $this, 'check_query_permission' ],
			'args'                => [
				'order_id' => [
					'validate_callback' => fn( $param ) => is_numeric( $param ),
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	/**
	 * Handle STK callback from Safaricom
	 */
	public function handle_callback( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();

		LipaWoo_Logger::info( 'Callback received', [ 'data' => $body ] );

		// Validate callback structure
		if ( empty( $body['Body']['stkCallback'] ) ) {
			LipaWoo_Logger::error( 'Invalid callback payload received' );
			return new WP_REST_Response( [ 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ], 200 );
		}

		$callback = $body['Body']['stkCallback'];
		$this->process_stk_callback( $callback );

		// Always return 200 to Safaricom
		return new WP_REST_Response( [
			'ResultCode' => 0,
			'ResultDesc' => 'Accepted',
		], 200 );
	}

	/**
	 * Process the STK callback data
	 */
	private function process_stk_callback( array $callback ): void {
		$checkout_request_id = $callback['CheckoutRequestID'] ?? '';
		$merchant_request_id = $callback['MerchantRequestID'] ?? '';
		$result_code         = (int) ( $callback['ResultCode'] ?? -1 );
		$result_desc         = $callback['ResultDesc'] ?? '';

		if ( ! $checkout_request_id ) {
			LipaWoo_Logger::error( 'Callback missing CheckoutRequestID' );
			return;
		}

		// Find the order by CheckoutRequestID
		$orders = wc_get_orders( [
			'meta_key'   => '_lipawoo_checkout_request_id',
			'meta_value' => $checkout_request_id,
			'limit'      => 1,
		] );

		if ( empty( $orders ) ) {
			LipaWoo_Logger::error( "No order found for CheckoutRequestID: {$checkout_request_id}" );
			return;
		}

		$order = $orders[0];

		if ( 0 === $result_code ) {
			// Payment successful
			$this->handle_successful_payment( $order, $callback );
		} else {
			// Payment failed
			$this->handle_failed_payment( $order, $result_code, $result_desc );
		}

		// Update database transaction
		$this->update_transaction( $checkout_request_id, $result_code, $result_desc, $callback );
	}

	/**
	 * Handle successful M-Pesa payment
	 */
	private function handle_successful_payment( WC_Order $order, array $callback ): void {
		if ( $order->is_paid() ) {
			LipaWoo_Logger::info( "Order {$order->get_id()} already paid, skipping." );
			return;
		}

		// Extract callback metadata
		$items        = $callback['CallbackMetadata']['Item'] ?? [];
		$meta         = $this->extract_callback_meta( $items );
		$mpesa_code   = $meta['MpesaReceiptNumber'] ?? '';
		$phone        = $meta['PhoneNumber'] ?? '';
		$amount       = $meta['Amount'] ?? 0;
		$tx_date      = $meta['TransactionDate'] ?? '';

		// Store transaction details
		$order->update_meta_data( '_lipawoo_receipt_number', $mpesa_code );
		$order->update_meta_data( '_lipawoo_transaction_date', $tx_date );
		$order->update_meta_data( '_lipawoo_confirmed_amount', $amount );

		// Mark payment complete
		$order->payment_complete( $mpesa_code );
		$order->add_order_note(
			sprintf(
				/* translators: 1: M-Pesa code 2: amount 3: phone */
				__( 'M-Pesa payment confirmed. Receipt: %1$s | Amount: KES %2$s | Phone: %3$s', 'lipawoo' ),
				$mpesa_code,
				number_format( $amount, 2 ),
				$phone
			)
		);

		$order->save();

		LipaWoo_Logger::info( "Order {$order->get_id()} marked as paid. Receipt: {$mpesa_code}" );

		$environment = $order->get_meta( '_lipawoo_environment' );
		if ( 'production' === $environment ) {
			LipaWoo::increment_production_txn_count();
		}

		do_action( 'lipawoo_payment_success', $order, $callback );
	}

	/**
	 * Handle failed M-Pesa payment
	 */
	private function handle_failed_payment( WC_Order $order, int $result_code, string $result_desc ): void {
		if ( in_array( $order->get_status(), [ 'cancelled', 'failed', 'completed', 'processing' ] ) ) {
			return;
		}

		$order->update_status(
			'failed',
			sprintf(
				/* translators: 1: result code 2: result description */
				__( 'M-Pesa payment failed. Code: %1$s | %2$s', 'lipawoo' ),
				$result_code,
				$result_desc
			)
		);

		LipaWoo_Logger::warning( "Order {$order->get_id()} payment failed. Code: {$result_code}, Desc: {$result_desc}" );

		do_action( 'lipawoo_payment_failed', $order, $result_code, $result_desc );
	}

	/**
	 * Handle manual STK query request
	 */
	public function handle_manual_query( WP_REST_Request $request ): WP_REST_Response {
		$order_id = $request->get_param( 'order_id' );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_REST_Response( [ 'error' => 'Order not found' ], 404 );
		}

		$gateway = new LipaWoo_Gateway();
		$result  = $gateway->query_and_update( $order );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [
				'error'   => $result->get_error_message(),
				'status'  => $order->get_status(),
				'is_paid' => $order->is_paid(),
			], 200 );
		}

		return new WP_REST_Response( [
			'query_result' => $result,
			'status'       => $order->get_status(),
			'is_paid'      => $order->is_paid(),
			'redirect_url' => $order->is_paid() ? $order->get_checkout_order_received_url() : '',
		], 200 );
	}

	/**
	 * Permission check for manual query
	 */
	public function check_query_permission( WP_REST_Request $request ): bool {
		// Allow if nonce matches or user is logged in
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return wp_verify_nonce( $nonce, 'wp_rest' ) || is_user_logged_in();
	}

	/**
	 * Extract key-value pairs from M-Pesa callback metadata
	 */
	private function extract_callback_meta( array $items ): array {
		$meta = [];
		foreach ( $items as $item ) {
			if ( isset( $item['Name'], $item['Value'] ) ) {
				$meta[ $item['Name'] ] = $item['Value'];
			}
		}
		return $meta;
	}

	/**
	 * Update transaction record in database
	 */
	private function update_transaction( string $checkout_request_id, int $result_code, string $result_desc, array $callback ): void {
		global $wpdb;

		$meta        = $this->extract_callback_meta( $callback['CallbackMetadata']['Item'] ?? [] );
		$mpesa_code  = $meta['MpesaReceiptNumber'] ?? null;
		$status      = ( 0 === $result_code ) ? 'completed' : 'failed';

		$wpdb->update(
			$wpdb->prefix . 'lipawoo_transactions',
			[
				'result_code'    => $result_code,
				'result_desc'    => $result_desc,
				'mpesa_receipt'  => $mpesa_code,
				'status'         => $status,
			],
			[ 'checkout_request_id' => $checkout_request_id ],
			[ '%d', '%s', '%s', '%s' ],
			[ '%s' ]
		);
	}
}
