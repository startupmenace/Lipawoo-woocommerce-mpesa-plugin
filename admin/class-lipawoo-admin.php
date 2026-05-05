<?php
/**
 * LipaWoo Admin
 *
 * @package LipaWoo
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class LipaWoo_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'wp_ajax_lipawoo_admin_query',           [ $this, 'ajax_admin_query' ] );
		add_action( 'wp_ajax_lipawoo_admin_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_lipawoo_admin_delete',          [ $this, 'ajax_delete_transaction' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( LIPAWOO_PLUGIN_FILE ), [ $this, 'add_plugin_links' ] );

		// Show upgrade notice in WooCommerce settings when production is attempted
		add_action( 'admin_footer', [ $this, 'render_upgrade_modal' ] );
	}

	public function add_admin_menu() {
		add_submenu_page( 'woocommerce', __( 'LipaWoo Transactions', 'lipawoo' ), __( 'LipaWoo', 'lipawoo' ), 'manage_woocommerce', 'lipawoo-transactions', [ $this, 'transactions_page' ] );
	}

	public function transactions_page() {
		global $wpdb;
		$status   = sanitize_text_field( $_GET['status'] ?? '' );
		$search   = sanitize_text_field( $_GET['search'] ?? '' );
		$per_page = 20;
		$page     = absint( $_GET['paged'] ?? 1 );
		$offset   = ( $page - 1 ) * $per_page;

		if ( $status && $search ) {
			$transactions = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}lipawoo_transactions WHERE status = %s AND (mpesa_receipt LIKE %s OR phone_number LIKE %s OR order_id = %d) ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$status, '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%', (int) $search, $per_page, $offset
			) );
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}lipawoo_transactions WHERE status = %s AND (mpesa_receipt LIKE %s OR phone_number LIKE %s OR order_id = %d)",
				$status, '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%', (int) $search
			) );
		} elseif ( $status ) {
			$transactions = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}lipawoo_transactions WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$status, $per_page, $offset
			) );
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}lipawoo_transactions WHERE status = %s",
				$status
			) );
		} elseif ( $search ) {
			$transactions = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}lipawoo_transactions WHERE (mpesa_receipt LIKE %s OR phone_number LIKE %s OR order_id = %d) ORDER BY created_at DESC LIMIT %d OFFSET %d",
				'%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%', (int) $search, $per_page, $offset
			) );
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}lipawoo_transactions WHERE (mpesa_receipt LIKE %s OR phone_number LIKE %s OR order_id = %d)",
				'%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%', (int) $search
			) );
		} else {
			$transactions = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}lipawoo_transactions ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page, $offset
			) );
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lipawoo_transactions" );
		}

		$total_pages = (int) ceil( $total / $per_page );
		$stats       = $wpdb->get_row( "SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN amount ELSE 0 END) as total_amount, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed FROM {$wpdb->prefix}lipawoo_transactions" );

		include LIPAWOO_PLUGIN_DIR . 'templates/admin-transactions.php';
	}

	public function ajax_admin_query() {
		check_ajax_referer( 'lipawoo_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( [ 'message' => 'Unauthorized' ] ); die(); }
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
		if ( ! $order ) { wp_send_json_error( [ 'message' => 'Order not found' ] ); die(); }
		$gateway = new LipaWoo_Gateway();
		$result  = $gateway->query_and_update( $order );
		if ( is_wp_error( $result ) ) { wp_send_json_error( [ 'message' => $result->get_error_message() ] ); die(); }
		wp_send_json_success( [ 'result' => $result['result'] ?? [], 'order_status' => $order->get_status() ] );
		die();
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'lipawoo_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( [ 'message' => 'Unauthorized' ] ); die(); }
		$gateway = new LipaWoo_Gateway();
		$api     = $gateway->get_api_client();
		if ( is_wp_error( $api ) ) { wp_send_json_error( [ 'message' => $api->get_error_message() ] ); die(); }
		$token = $api->get_access_token();
		if ( is_wp_error( $token ) ) { wp_send_json_error( [ 'message' => $token->get_error_message() ] ); die(); }
		wp_send_json_success( [ 'message' => 'Connected successfully! Sandbox credentials are valid.' ] );
		die();
	}

	public function ajax_delete_transaction() {
		check_ajax_referer( 'lipawoo_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( [ 'message' => 'Unauthorized' ] ); die(); }
		$id = absint( $_POST['transaction_id'] ?? 0 );
		if ( ! $id ) { wp_send_json_error( [ 'message' => 'Invalid ID.' ] ); die(); }
		global $wpdb;
		$deleted = $wpdb->delete( $wpdb->prefix . 'lipawoo_transactions', [ 'id' => $id ], [ '%d' ] );
		if ( false === $deleted || 0 === $deleted ) { wp_send_json_error( [ 'message' => 'Could not delete.' ] ); die(); }
		wp_send_json_success( [ 'message' => 'Deleted.', 'id' => $id ] );
		die();
	}

	public function render_upgrade_modal() {
		$used = LipaWoo::get_production_txn_count();
		$max  = LIPAWOO_MAX_FREE_TXNS;
		?>
		<div id="lipawoo-upgrade-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.7);align-items:center;justify-content:center;">
			<div style="background:#fff;border-radius:16px;padding:40px;max-width:480px;width:90%;text-align:center;position:relative;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
				<button onclick="document.getElementById('lipawoo-upgrade-modal').style.display='none'" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:1.5rem;cursor:pointer;color:#9ca3af;">&times;</button>
				<div style="width:64px;height:64px;background:linear-gradient(135deg,#00a651,#007a3d);border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:1.8rem;">⚡</div>
				<h2 style="margin:0 0 8px;color:#111;font-size:1.4rem;">Upgrade to LipaWoo Pro</h2>
				<p style="color:#6b7280;margin:0 0 8px;font-size:0.95rem;">
					<?php printf( esc_html__( 'You have used %d of %d free production transactions.', 'lipawoo' ), $used, $max ); ?>
				</p>
				<p style="color:#374151;margin:0 0 24px;font-size:0.95rem;">To accept <strong>unlimited real M-Pesa payments</strong>, upgrade to LipaWoo Pro — full production support, priority support, and more.</p>
				<a href="<?php echo esc_url( LIPAWOO_UPGRADE_URL ); ?>" target="_blank"
					style="display:inline-block;background:linear-gradient(135deg,#00a651,#007a3d);color:#fff;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:700;font-size:1rem;margin-bottom:12px;">
					Get LipaWoo Pro ↗
				</a>
				<br>
				<small style="color:#9ca3af;">You will be redirected to <?php echo esc_html( LIPAWOO_UPGRADE_URL ); ?></small>
			</div>
		</div>
		<script>
		(function($){
			$(document).on('change', '#woocommerce_lipawoo_environment', function(){
				if ( $(this).val() === 'production' && <?php echo (int) ! LipaWoo::is_production_allowed(); ?> ) {
					$(this).val('sandbox');
					document.getElementById('lipawoo-upgrade-modal').style.display = 'flex';
				}
			});

			$('#woocommerce_lipawoo_environment').on('change', function(){
				var $desc = $(this).closest('table').find('.lipawoo-env-hint');
				if ( $(this).val() === 'production' ) {
					$desc.show();
				} else {
					$desc.hide();
				}
			}).trigger('change');
		}(jQuery));
		</script>
		<?php
	}

	public function add_plugin_links( $links ) {
		return array_merge([
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=lipawoo' ) . '">Settings</a>',
			'<a href="' . esc_url( LIPAWOO_UPGRADE_URL ) . '" target="_blank" style="color:#00a651;font-weight:600;">⚡ Pro</a>',
		], $links );
	}
}

new LipaWoo_Admin();
