<?php
/**
 * Plugin Name: LipaWoo — M-Pesa STK Push Gateway (Lite)
 * Plugin URI: https://mpesa-woocommerce.wasmer.app
 * Description: Accept M-Pesa STK Push payments in WooCommerce via Safaricom Daraja API. Includes 5 free production transactions. Upgrade to LipaWoo Pro for unlimited.
 * Version: 1.0.0
 * Author: Fortune Dev Solutions
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lipawoo
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package LipaWoo
 */

defined( 'ABSPATH' ) || exit;

define( 'LIPAWOO_VERSION', '1.0.0' );
define( 'LIPAWOO_PLUGIN_FILE', __FILE__ );
define( 'LIPAWOO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIPAWOO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LIPAWOO_UPGRADE_URL', 'https://mpesa-woocommerce.wasmer.app' );
define( 'LIPAWOO_MAX_FREE_TXNS', 5 );
define( 'LIPAWOO_SANDBOX_URL', 'https://sandbox.safaricom.co.ke' );
define( 'LIPAWOO_PRODUCTION_URL', 'https://api.safaricom.co.ke' );

// HPOS compatibility
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

final class LipaWoo {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->hooks();
	}

	private function includes() {
		require_once LIPAWOO_PLUGIN_DIR . 'includes/class-lipawoo-logger.php';
		require_once LIPAWOO_PLUGIN_DIR . 'includes/class-lipawoo-api.php';
		require_once LIPAWOO_PLUGIN_DIR . 'includes/class-lipawoo-gateway.php';
		require_once LIPAWOO_PLUGIN_DIR . 'includes/class-lipawoo-callback-handler.php';
		require_once LIPAWOO_PLUGIN_DIR . 'includes/class-lipawoo-order-manager.php';
		require_once LIPAWOO_PLUGIN_DIR . 'admin/class-lipawoo-admin.php';
	}

	public static function get_production_txn_count() {
		$stored = absint( get_option( 'lipawoo_prod_txn_count', 0 ) );
		return $stored;
	}

	public static function increment_production_txn_count() {
		$count = self::get_production_txn_count();
		update_option( 'lipawoo_prod_txn_count', $count + 1 );
		return $count + 1;
	}

	public static function is_production_allowed() {
		return self::get_production_txn_count() < LIPAWOO_MAX_FREE_TXNS;
	}

	public static function get_api_base_url( $environment ) {
		return 'production' === $environment ? LIPAWOO_PRODUCTION_URL : LIPAWOO_SANDBOX_URL;
	}

	private function hooks() {
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
		add_filter( 'woocommerce_payment_gateways', [ $this, 'add_gateway' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// AJAX
		add_action( 'wp_ajax_lipawoo_check_payment_status',        [ $this, 'ajax_check_payment_status' ] );
		add_action( 'wp_ajax_nopriv_lipawoo_check_payment_status', [ $this, 'ajax_check_payment_status' ] );
		add_action( 'wp_ajax_lipawoo_resend_stk',                  [ $this, 'ajax_resend_stk' ] );
		add_action( 'wp_ajax_nopriv_lipawoo_resend_stk',           [ $this, 'ajax_resend_stk' ] );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'lipawoo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public function add_gateway( $gateways ) {
		$gateways[] = 'LipaWoo_Gateway';
		return $gateways;
	}

	public function enqueue_scripts() {
		if ( is_checkout() || is_wc_endpoint_url( 'order-pay' ) ) {
			wp_enqueue_style(
				'lipawoo-checkout',
				LIPAWOO_PLUGIN_URL . 'public/css/lipawoo-checkout.css',
				[],
				filemtime( LIPAWOO_PLUGIN_DIR . 'public/css/lipawoo-checkout.css' )
			);
			wp_enqueue_script(
				'lipawoo-checkout',
				LIPAWOO_PLUGIN_URL . 'public/js/lipawoo-checkout.js',
				[ 'jquery' ],
				filemtime( LIPAWOO_PLUGIN_DIR . 'public/js/lipawoo-checkout.js' ),
				true
			);
			wp_localize_script( 'lipawoo-checkout', 'lipawoo_params', [
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'lipawoo_nonce' ),
				'upgrade_url' => LIPAWOO_UPGRADE_URL,
				'i18n'        => [
					'waiting'         => __( 'Waiting for payment...', 'lipawoo' ),
					'check_phone'     => __( 'Please check your phone for the M-Pesa prompt.', 'lipawoo' ),
					'payment_success' => __( 'Payment received! Redirecting...', 'lipawoo' ),
					'payment_failed'  => __( 'Payment not completed. Please try again.', 'lipawoo' ),
					'timeout'         => __( 'Payment Timed Out', 'lipawoo' ),
					'timeout_msg'     => __( 'No payment received within the allowed time. Please try again.', 'lipawoo' ),
					'resend_stk'      => __( 'Resend Payment Request', 'lipawoo' ),
				],
			] );
		}
	}

	public function enqueue_admin_scripts( $hook ) {
		if ( strpos( $hook, 'lipawoo' ) !== false || 'post.php' === $hook || 'post-new.php' === $hook ) {
			wp_enqueue_style(
				'lipawoo-admin',
				LIPAWOO_PLUGIN_URL . 'admin/css/lipawoo-admin.css',
				[],
				filemtime( LIPAWOO_PLUGIN_DIR . 'admin/css/lipawoo-admin.css' )
			);
			wp_enqueue_script(
				'lipawoo-admin',
				LIPAWOO_PLUGIN_URL . 'admin/js/lipawoo-admin.js',
				[ 'jquery' ],
				filemtime( LIPAWOO_PLUGIN_DIR . 'admin/js/lipawoo-admin.js' ),
				true
			);
			wp_localize_script( 'lipawoo-admin', 'lipawoo_admin_params', [
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'lipawoo_admin_nonce' ),
				'upgrade_url' => LIPAWOO_UPGRADE_URL,
			] );
		}
	}

	public function register_rest_routes() {
		$handler = new LipaWoo_Callback_Handler();
		$handler->register_routes();
	}

	public function ajax_check_payment_status() {
		check_ajax_referer( 'lipawoo_nonce', 'nonce' );
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order.', 'lipawoo' ) ] );
			die();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'lipawoo' ) ] );
			die();
		}

		if ( $order->is_paid() ) {
			wp_send_json_success( [
				'payment_status' => 'paid',
				'redirect_url'   => $order->get_checkout_order_received_url(),
			] );
			die();
		}

		$gateway = new LipaWoo_Gateway();
		$result  = $gateway->query_and_update( $order );

		if ( is_wp_error( $result ) ) {
			wp_send_json_success( [ 'payment_status' => 'pending' ] );
			die();
		}

		wp_send_json_success( [
			'payment_status' => $result['payment_status'],
			'redirect_url'   => 'paid' === $result['payment_status'] ? $order->get_checkout_order_received_url() : '',
			'user_message'   => $result['user_message'] ?? '',
		] );
		die();
	}

	public function ajax_resend_stk() {
		check_ajax_referer( 'lipawoo_nonce', 'nonce' );
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order.', 'lipawoo' ) ] );
			die();
		}

		$gateway = new LipaWoo_Gateway();
		$result  = $gateway->trigger_stk_push( wc_get_order( $order_id ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			die();
		}

		wp_send_json_success( [ 'message' => __( 'Payment request sent! Check your phone.', 'lipawoo' ) ] );
		die();
	}
}

// Activation
register_activation_hook( __FILE__, 'lipawoo_activate' );
register_deactivation_hook( __FILE__, 'lipawoo_deactivate' );

function lipawoo_activate() {
	lipawoo_create_tables();
	flush_rewrite_rules();
}

function lipawoo_deactivate() {
	flush_rewrite_rules();
}

function lipawoo_create_tables() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = $wpdb->prefix . 'lipawoo_transactions';

	$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		order_id bigint(20) NOT NULL,
		checkout_request_id varchar(100) NOT NULL,
		merchant_request_id varchar(100) NOT NULL,
		phone_number varchar(20) NOT NULL,
		amount decimal(10,2) NOT NULL,
		mpesa_receipt varchar(50) DEFAULT NULL,
		result_code int(5) DEFAULT NULL,
		result_desc text DEFAULT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY order_id (order_id),
		KEY checkout_request_id (checkout_request_id),
		KEY status (status)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

// Boot
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'LipaWoo requires WooCommerce to be installed and active.', 'lipawoo' ) .
				'</p></div>';
		} );
		return;
	}
	LipaWoo::instance();
}, 0 );
