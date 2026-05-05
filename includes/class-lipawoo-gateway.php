<?php
/**
 * LipaWoo Gateway — Lite (Sandbox Only)
 *
 * Production environment is locked. Attempting to switch to production
 * shows an upgrade modal instead.
 *
 * @package LipaWoo
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class LipaWoo_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'lipawoo';
		$this->icon               = LIPAWOO_PLUGIN_URL . 'public/images/mpesa-logo.png';
		$this->has_fields         = true;
		$this->method_title       = __( 'M-Pesa — LipaWoo Lite', 'lipawoo' );
		$this->method_description = sprintf(
			/* translators: 1: max free transactions number, 2: upgrade URL */
			__( 'Accept M-Pesa STK Push payments via Safaricom Daraja API. <strong>%1$d free production transactions included.</strong> <a href="%2$s" target="_blank">Upgrade to LipaWoo Pro</a> for unlimited production payments.', 'lipawoo' ),
			LIPAWOO_MAX_FREE_TXNS,
			LIPAWOO_UPGRADE_URL
		);
		$this->supports           = [ 'products' ];

		add_filter( 'woocommerce_gateway_icon', [ $this, 'filter_icon_html' ], 10, 2 );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'clear_api_token_cache' ] );
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
		add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
	}

	public function init_form_fields() {
		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'lipawoo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable LipaWoo M-Pesa', 'lipawoo' ),
				'default' => 'no',
			],
			'title' => [
				'title'   => __( 'Title', 'lipawoo' ),
				'type'    => 'text',
				'default' => __( 'M-Pesa', 'lipawoo' ),
				'desc_tip' => true,
				'description' => __( 'Payment method title shown at checkout.', 'lipawoo' ),
			],
			'description' => [
				'title'   => __( 'Description', 'lipawoo' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely using M-Pesa. You will receive an STK push on your phone.', 'lipawoo' ),
				'desc_tip' => true,
				'description' => __( 'Payment method description shown at checkout.', 'lipawoo' ),
			],
			'sandbox_notice' => [
				'title' => __( 'Lite Plan', 'lipawoo' ),
				'type'  => 'title',
				'description' => self::get_limit_notice_html(),
			],
			'environment' => [
				'title'   => __( 'Environment', 'lipawoo' ),
				'type'    => 'select',
				'options' => [
					'sandbox'    => __( 'Sandbox (Testing — unlimited)', 'lipawoo' ),
					'production' => sprintf( __( 'Production (Live — %d free transactions)', 'lipawoo' ), LIPAWOO_MAX_FREE_TXNS ),
				],
				'default'     => 'sandbox',
				'description' => __( 'Sandbox uses Safaricom test credentials. Production uses live credentials for real M-Pesa payments.', 'lipawoo' ),
			],
			'api_credentials' => [
				'title'       => __( 'API Credentials', 'lipawoo' ),
				'type'        => 'title',
				'description' => __( 'Enter your Safaricom Daraja API credentials. Get them from <a href="https://developer.safaricom.co.ke" target="_blank">developer.safaricom.co.ke</a>. Enable <strong>Lipa Na M-Pesa Online</strong>.', 'lipawoo' ),
			],
			'consumer_key' => [
				'title'    => __( 'Consumer Key', 'lipawoo' ),
				'type'     => 'password',
				'default'  => '',
				'desc_tip' => true,
				'description' => __( 'Consumer Key from the Safaricom Developer Portal.', 'lipawoo' ),
			],
			'consumer_secret' => [
				'title'    => __( 'Consumer Secret', 'lipawoo' ),
				'type'     => 'password',
				'default'  => '',
				'desc_tip' => true,
				'description' => __( 'Consumer Secret from the Safaricom Developer Portal.', 'lipawoo' ),
			],
			'shortcode' => [
				'title'       => __( 'Business Shortcode', 'lipawoo' ),
				'type'        => 'text',
				'default'     => '174379',
				'description' => __( 'Your Paybill or Till number. Sandbox default: <code>174379</code>.', 'lipawoo' ),
			],
			'till_number' => [
				'title'       => __( 'Till Number (Buy Goods only)', 'lipawoo' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'Your Till number',
				'description' => __( 'For Buy Goods transactions only. Leave blank for Paybill.', 'lipawoo' ),
			],
			'passkey' => [
				'title'    => __( 'Lipa Na M-Pesa Passkey', 'lipawoo' ),
				'type'     => 'password',
				'default'  => '',
				'desc_tip' => true,
				'description' => __( 'Passkey from the Safaricom Developer Portal.', 'lipawoo' ),
			],
			'shortcode_type' => [
				'title'   => __( 'Account Type', 'lipawoo' ),
				'type'    => 'select',
				'options' => [
					'paybill' => __( 'Paybill (CustomerPayBillOnline)', 'lipawoo' ),
					'till'    => __( 'Till / Buy Goods (CustomerBuyGoodsOnline)', 'lipawoo' ),
				],
				'default' => 'paybill',
			],
			'account_reference' => [
				'title'       => __( 'Account Reference', 'lipawoo' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'Your business name',
				'description' => __( 'Label shown on the M-Pesa prompt (max 12 chars). Leave blank to use order number.', 'lipawoo' ),
			],
			'advanced' => [
				'title' => __( 'Advanced', 'lipawoo' ),
				'type'  => 'title',
			],
			'instructions' => [
				'title'   => __( 'Thank You Instructions', 'lipawoo' ),
				'type'    => 'textarea',
				'default' => __( 'Your M-Pesa payment has been received. Thank you for your order!', 'lipawoo' ),
				'desc_tip' => true,
			],
			'polling_interval' => [
				'title'   => __( 'STK Query Interval (ms)', 'lipawoo' ),
				'type'    => 'number',
				'default' => '5000',
				'desc_tip' => true,
				'description' => __( 'How often to check payment status. Default: 5000ms.', 'lipawoo' ),
				'custom_attributes' => [ 'min' => '3000', 'max' => '15000', 'step' => '1000' ],
			],
			'polling_timeout' => [
				'title'   => __( 'Payment Timeout (seconds)', 'lipawoo' ),
				'type'    => 'number',
				'default' => '120',
				'desc_tip' => true,
				'description' => __( 'How long to poll before timing out.', 'lipawoo' ),
				'custom_attributes' => [ 'min' => '60', 'max' => '300' ],
			],
			'debug' => [
				'title'   => __( 'Debug Log', 'lipawoo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'lipawoo' ),
				'default' => 'no',
				'description' => sprintf( __( 'Log events. View in <a href="%s">WooCommerce > Status > Logs</a>.', 'lipawoo' ), admin_url( 'admin.php?page=wc-status&tab=logs' ) ),
			],
		];
	}

	public function payment_fields() {
		if ( $this->description ) {
			echo '<p class="lipawoo-description">' . wp_kses_post( $this->description ) . '</p>';
		}
		?>
		<div class="lipawoo-payment-fields">
			<div class="lipawoo-field-group">
				<label for="lipawoo_phone">
					<?php esc_html_e( 'M-Pesa Phone Number', 'lipawoo' ); ?>
					<abbr class="required" title="required">*</abbr>
				</label>
				<div class="lipawoo-phone-input-wrapper">
					<span class="lipawoo-country-code">+254</span>
					<input type="tel" id="lipawoo_phone" name="lipawoo_phone" class="lipawoo-phone-input"
						placeholder="7XX XXX XXX" maxlength="12" autocomplete="tel"
						aria-label="<?php esc_attr_e( 'M-Pesa phone number', 'lipawoo' ); ?>" />
				</div>
				<p class="lipawoo-field-hint">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
					<?php esc_html_e( 'Enter the Safaricom number registered with M-Pesa.', 'lipawoo' ); ?>
				</p>
			</div>
			<div class="lipawoo-stk-info">
				<div class="lipawoo-stk-steps">
					<div class="lipawoo-step"><div class="lipawoo-step-icon">1</div><div class="lipawoo-step-text"><?php esc_html_e( 'Place your order', 'lipawoo' ); ?></div></div>
					<div class="lipawoo-step-arrow">→</div>
					<div class="lipawoo-step"><div class="lipawoo-step-icon">2</div><div class="lipawoo-step-text"><?php esc_html_e( 'Receive M-Pesa prompt', 'lipawoo' ); ?></div></div>
					<div class="lipawoo-step-arrow">→</div>
					<div class="lipawoo-step"><div class="lipawoo-step-icon">3</div><div class="lipawoo-step-text"><?php esc_html_e( 'Enter PIN & confirm', 'lipawoo' ); ?></div></div>
				</div>
			</div>
		</div>
		<?php
	}

	public function validate_fields() {
		$phone = sanitize_text_field( $_POST['lipawoo_phone'] ?? '' );
		if ( empty( $phone ) ) { wc_add_notice( __( 'Please enter your M-Pesa phone number.', 'lipawoo' ), 'error' ); return false; }
		if ( ! LipaWoo_API::validate_phone( $phone ) ) { wc_add_notice( __( 'Please enter a valid M-Pesa phone number (e.g. 0712345678).', 'lipawoo' ), 'error' ); return false; }
		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$phone = LipaWoo_API::format_phone( sanitize_text_field( $_POST['lipawoo_phone'] ?? '' ) );
		$order->update_meta_data( '_lipawoo_phone', $phone );
		$order->save();

		$result = $this->trigger_stk_push( $order, $phone );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( sprintf( __( 'M-Pesa payment failed: %s', 'lipawoo' ), $result->get_error_message() ), 'error' );
			return [ 'result' => 'failure' ];
		}

		wc_reduce_stock_levels( $order_id );
		WC()->cart->empty_cart();
		$order->update_status( 'pending', __( 'Awaiting M-Pesa STK Query confirmation.', 'lipawoo' ) );

		return [ 'result' => 'success', 'redirect' => $order->get_checkout_payment_url( true ) ];
	}

	public function trigger_stk_push( $order, $phone = '' ) {
		$environment = $this->get_option( 'environment', 'sandbox' );

		if ( 'production' === $environment && ! LipaWoo::is_production_allowed() ) {
			return new WP_Error( 'limit_reached',
				sprintf( __( 'You have reached the %d free production transaction limit. Upgrade to LipaWoo Pro for unlimited payments: %s', 'lipawoo' ), LIPAWOO_MAX_FREE_TXNS, LIPAWOO_UPGRADE_URL )
			);
		}

		if ( ! $phone ) $phone = $order->get_meta( '_lipawoo_phone' );

		$api = $this->get_api_client();
		if ( is_wp_error( $api ) ) return $api;

		$shortcode_type  = $this->get_option( 'shortcode_type', 'paybill' );
		$till_number     = trim( $this->get_option( 'till_number', '' ) );
		$account_ref_opt = trim( $this->get_option( 'account_reference', '' ) );

		$transaction_type = ( 'till' === $shortcode_type ) ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline';
		$party_b          = ( 'till' === $shortcode_type && ! empty( $till_number ) ) ? $till_number : null;
		$account_ref      = ! empty( $account_ref_opt ) ? substr( $account_ref_opt, 0, 12 ) : substr( 'Order' . $order->get_order_number(), 0, 12 );
		$callback_url     = rest_url( 'lipawoo/v1/callback' );

		$response = $api->stk_push( $phone, (float) $order->get_total(), $account_ref, $callback_url, 'Payment', $transaction_type, $party_b );
		if ( is_wp_error( $response ) ) return $response;

		$order->update_meta_data( '_lipawoo_checkout_request_id', $response['CheckoutRequestID'] );
		$order->update_meta_data( '_lipawoo_merchant_request_id', $response['MerchantRequestID'] );
		$order->update_meta_data( '_lipawoo_stk_sent_at', time() );
		$order->update_meta_data( '_lipawoo_environment', $environment );
		$order->save();

		$this->save_transaction([
			'order_id'            => $order->get_id(),
			'checkout_request_id' => $response['CheckoutRequestID'],
			'merchant_request_id' => $response['MerchantRequestID'],
			'phone_number'        => $phone,
			'amount'              => (float) $order->get_total(),
			'status'              => 'pending',
		]);

		return $response;
	}

	public function query_and_update( $order ) {
		$checkout_request_id = $order->get_meta( '_lipawoo_checkout_request_id' );
		if ( ! $checkout_request_id ) return new WP_Error( 'no_request_id', 'No checkout request ID on this order.' );

		$api = $this->get_api_client();
		if ( is_wp_error( $api ) ) return $api;

		$result = $api->stk_query( $checkout_request_id );
		if ( is_wp_error( $result ) ) return [ 'payment_status' => 'pending', 'user_message' => '' ];

		$result_code = isset( $result['ResultCode'] ) ? (int) $result['ResultCode'] : -1;
		$result_desc = $result['ResultDesc'] ?? '';

		LipaWoo_Logger::info( "STK Query order={$order->get_id()} code={$result_code} desc={$result_desc}" );

		if ( 0 === $result_code ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete();
				$desired = $this->get_option( 'order_status_after_payment', 'processing' );
				if ( $desired && 'processing' !== $desired ) $order->update_status( $desired );
				$order->add_order_note( sprintf( __( 'M-Pesa payment confirmed via STK Query. %s', 'lipawoo' ), $result_desc ) );
				$this->update_transaction_status( $checkout_request_id, 'completed', $result_code, $result_desc );

				$environment = $this->get_option( 'environment', 'sandbox' );
				if ( 'production' === $environment ) {
					LipaWoo::increment_production_txn_count();
				}
			}
			return [ 'payment_status' => 'paid', 'result' => $result ];
		}

		// Pending phrases
		$pending_indicators = [ -1, 500 ];
		$pending_phrases    = [ 'being processed', 'still under processing', 'in progress', 'pending', 'initiated', 'transaction is being', 'request is being' ];
		if ( in_array( $result_code, $pending_indicators, true ) ) return [ 'payment_status' => 'pending', 'user_message' => '' ];
		foreach ( $pending_phrases as $phrase ) {
			if ( stripos( $result_desc, $phrase ) !== false ) return [ 'payment_status' => 'pending', 'user_message' => '' ];
		}

		// Terminal failures
		$user_messages = [
			1032 => 'You cancelled the payment request.',
			1    => 'Your M-Pesa balance is insufficient. Please top up and try again.',
			2001 => 'Incorrect M-Pesa PIN entered. Please try again.',
			1019 => 'The payment request expired. Please try again.',
			1001 => 'Your SIM has an active USSD session. Close it and try again.',
			1037 => 'Your phone could not be reached. Check your network and try again.',
			1025 => 'Safaricom system error. Please try again.',
			9999 => 'Safaricom system error. Please try again.',
		];
		$user_msg = $user_messages[ $result_code ] ?? ( $result_desc ?: 'Payment was not completed. Please try again.' );

		if ( ! in_array( $order->get_status(), [ 'failed', 'cancelled', 'processing', 'completed' ], true ) ) {
			$order->update_status( 'failed', "M-Pesa: {$result_desc} (code {$result_code})" );
			$this->update_transaction_status( $checkout_request_id, 'failed', $result_code, $result_desc );
		}

		return [ 'payment_status' => 'failed', 'user_message' => $user_msg, 'result_code' => $result_code, 'result' => $result ];
	}

	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;
		$polling_interval = (int) $this->get_option( 'polling_interval', 5000 );
		$polling_timeout  = (int) $this->get_option( 'polling_timeout', 120 );
		include LIPAWOO_PLUGIN_DIR . 'templates/payment-waiting.php';
	}

	public function thankyou_page( $order_id ) {
		$instructions = $this->get_option( 'instructions' );
		if ( $instructions ) echo '<div class="lipawoo-thankyou-notice">' . wp_kses_post( $instructions ) . '</div>';
	}

	public function admin_options() {
		?>
		<div class="lipawoo-admin-header">
			<div class="lipawoo-admin-logo">
				<img src="<?php echo esc_url( LIPAWOO_PLUGIN_URL . 'public/images/mpesa-logo.webp' ); ?>" alt="M-Pesa" height="40" style="border-radius:6px;" />
				<div>
					<h2>LipaWoo <span style="font-weight:400;font-size:0.85em;">for WooCommerce</span></h2>
					<span class="lipawoo-version-badge">v<?php echo LIPAWOO_VERSION; ?></span>
					<span class="lipawoo-lite-badge">LITE — <?php printf( esc_html__( '%d/%d Free', 'lipawoo' ), LipaWoo::get_production_txn_count(), LIPAWOO_MAX_FREE_TXNS ); ?></span>
				</div>
			</div>
			<a href="<?php echo esc_url( LIPAWOO_UPGRADE_URL ); ?>" target="_blank" class="lipawoo-upgrade-btn">
				⚡ Upgrade to Pro
			</a>
		</div>

		<div class="lipawoo-admin-status">
			<div class="lipawoo-status-card">
				<h3><?php esc_html_e( 'Callback URL', 'lipawoo' ); ?></h3>
				<div class="lipawoo-copy-url">
					<code id="lipawoo-callback-url"><?php echo esc_html( rest_url( 'lipawoo/v1/callback' ) ); ?></code>
					<button type="button" class="lipawoo-copy-btn" data-target="lipawoo-callback-url">Copy</button>
				</div>
				<p class="lipawoo-hint"><?php esc_html_e( 'Payments confirm via STK Query — callback is optional.', 'lipawoo' ); ?></p>
			</div>
			<div class="lipawoo-status-card">
				<h3><?php esc_html_e( 'Connection Test', 'lipawoo' ); ?></h3>
				<button type="button" id="lipawoo-test-connection" class="button button-secondary">Test API Connection</button>
				<span id="lipawoo-test-result" class="lipawoo-test-result"></span>
			</div>
		</div>

		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table>
		<?php
	}

	private static function get_limit_notice_html() {
		$used  = LipaWoo::get_production_txn_count();
		$max   = LIPAWOO_MAX_FREE_TXNS;
		$left  = max( 0, $max - $used );

		if ( $used >= $max ) {
			return '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 16px;">
				<strong>&#x26A0; You have used all ' . $max . ' free production transactions.</strong><br>
				Upgrade to <a href="' . esc_url( LIPAWOO_UPGRADE_URL ) . '" target="_blank" style="font-weight:600;">LipaWoo Pro</a> for unlimited production payments.
			</div>';
		}

		return '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;">
			<strong>LipaWoo Lite</strong> — ' . sprintf( __( '%d of %d free production transactions used.', 'lipawoo' ), $used, $max ) . '<br>
			' . sprintf( __( '%d transactions remaining. Need more? <a href="%s" target="_blank">Upgrade to Pro</a>.', 'lipawoo' ), $left, esc_url( LIPAWOO_UPGRADE_URL ) ) . '
		</div>';
	}

	public function get_api_client() {
		$consumer_key    = $this->get_option( 'consumer_key' );
		$consumer_secret = $this->get_option( 'consumer_secret' );
		$shortcode       = $this->get_option( 'shortcode' );
		$passkey         = $this->get_option( 'passkey' );
		$environment     = $this->get_option( 'environment', 'sandbox' );
		if ( empty( $consumer_key ) || empty( $consumer_secret ) || empty( $shortcode ) || empty( $passkey ) ) {
			return new WP_Error( 'missing_credentials', __( 'M-Pesa API credentials are incomplete. Please check your settings.', 'lipawoo' ) );
		}
		return new LipaWoo_API( $consumer_key, $consumer_secret, $shortcode, $passkey, $environment );
	}

	public function clear_api_token_cache() {
		$key = $this->get_option( 'consumer_key' );
		if ( $key ) delete_transient( 'lipawoo_token_' . md5( $key ) );
	}

	public function filter_icon_html( $icon_html, $gateway_id ) {
		if ( $gateway_id !== $this->id ) return $icon_html;
		return str_replace( '<img ', '<img class="lipawoo-gateway-icon" style="height:24px!important;width:auto!important;max-width:80px!important;vertical-align:middle!important;background:transparent!important;" ', $icon_html );
	}

	private function save_transaction( $data ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'lipawoo_transactions', [
			'order_id'            => $data['order_id'],
			'checkout_request_id' => $data['checkout_request_id'],
			'merchant_request_id' => $data['merchant_request_id'],
			'phone_number'        => $data['phone_number'],
			'amount'              => $data['amount'],
			'status'              => $data['status'],
		], [ '%d', '%s', '%s', '%s', '%f', '%s' ] );
	}

	private function update_transaction_status( $checkout_request_id, $status, $result_code, $result_desc ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'lipawoo_transactions',
			[ 'status' => $status, 'result_code' => $result_code, 'result_desc' => $result_desc ],
			[ 'checkout_request_id' => $checkout_request_id ],
			[ '%s', '%d', '%s' ], [ '%s' ]
		);
	}
}
