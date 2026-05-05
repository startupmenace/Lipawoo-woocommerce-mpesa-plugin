<?php
/**
 * Admin Transactions Template
 *
 * @var array $transactions
 * @var object $stats
 * @var int $total
 * @var int $total_pages
 * @var int $page
 * @var string $status
 * @var string $search
 *
 * @package LipaWoo
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap lipawoo-admin-wrap">
	<h1 class="wp-heading-inline">
		<img src="<?php echo esc_url( LIPAWOO_PLUGIN_URL . 'public/images/lipawoo-logo.svg' ); ?>" height="24" alt="M-Pesa" style="vertical-align:middle;margin-right:8px;" />
		<?php esc_html_e( 'M-Pesa Transactions', 'lipawoo' ); ?>
	</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=lipawoo' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Settings', 'lipawoo' ); ?>
	</a>

	<!-- Stats Cards -->
	<div class="lipawoo-stats-grid">
		<div class="lipawoo-stat-card lipawoo-stat-total">
			<div class="lipawoo-stat-value"><?php echo esc_html( number_format( $stats->total ) ); ?></div>
			<div class="lipawoo-stat-label"><?php esc_html_e( 'Total Transactions', 'lipawoo' ); ?></div>
		</div>
		<div class="lipawoo-stat-card lipawoo-stat-completed">
			<div class="lipawoo-stat-value"><?php echo esc_html( number_format( $stats->completed ) ); ?></div>
			<div class="lipawoo-stat-label"><?php esc_html_e( 'Completed', 'lipawoo' ); ?></div>
		</div>
		<div class="lipawoo-stat-card lipawoo-stat-pending">
			<div class="lipawoo-stat-value"><?php echo esc_html( number_format( $stats->pending ) ); ?></div>
			<div class="lipawoo-stat-label"><?php esc_html_e( 'Pending', 'lipawoo' ); ?></div>
		</div>
		<div class="lipawoo-stat-card lipawoo-stat-failed">
			<div class="lipawoo-stat-value"><?php echo esc_html( number_format( $stats->failed ) ); ?></div>
			<div class="lipawoo-stat-label"><?php esc_html_e( 'Failed', 'lipawoo' ); ?></div>
		</div>
		<div class="lipawoo-stat-card lipawoo-stat-revenue">
			<div class="lipawoo-stat-value">KES <?php echo esc_html( number_format( (float) $stats->total_amount, 2 ) ); ?></div>
			<div class="lipawoo-stat-label"><?php esc_html_e( 'Total Revenue', 'lipawoo' ); ?></div>
		</div>
	</div>

	<!-- Filters -->
	<div class="lipawoo-filters">
		<form method="get" action="">
			<input type="hidden" name="page" value="lipawoo-transactions" />
			<div class="lipawoo-filter-row">
				<input
					type="search"
					name="search"
					value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search receipt, phone, order ID...', 'lipawoo' ); ?>"
					class="lipawoo-search-input"
				/>
				<select name="status" class="lipawoo-status-filter">
					<option value=""><?php esc_html_e( 'All Statuses', 'lipawoo' ); ?></option>
					<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'lipawoo' ); ?></option>
					<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'lipawoo' ); ?></option>
					<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'lipawoo' ); ?></option>
				</select>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'lipawoo' ); ?></button>
				<?php if ( $search || $status ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lipawoo-transactions' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'lipawoo' ); ?></a>
				<?php endif; ?>
			</div>
		</form>
	</div>

	<!-- Bulk Actions Bar -->
	<div class="lipawoo-bulk-bar" id="lipawoo-bulk-bar" style="display:none;align-items:center;gap:12px;margin-bottom:8px;padding:10px 14px;background:#fff3f3;border:1px solid #fca5a5;border-radius:8px;">
		<span id="lipawoo-bulk-count" style="font-weight:600;color:#374151;"></span>
		<button type="button" id="lipawoo-bulk-delete" class="button" style="color:#dc2626;border-color:#dc2626;font-weight:600;">
			<?php esc_html_e( 'Delete Selected', 'lipawoo' ); ?>
		</button>
		<button type="button" id="lipawoo-bulk-cancel" class="button button-secondary">
			<?php esc_html_e( 'Cancel', 'lipawoo' ); ?>
		</button>
	</div>

	<!-- Table -->
	<table class="wp-list-table widefat fixed striped lipawoo-transactions-table">
		<thead>
			<tr>
				<th style="width:30px;"><input type="checkbox" id="lipawoo-select-all" title="Select all" /></th>
				<th><?php esc_html_e( 'Order', 'lipawoo' ); ?></th>
				<th><?php esc_html_e( 'Phone', 'lipawoo' ); ?></th>
				<th><?php esc_html_e( 'Amount (KES)', 'lipawoo' ); ?></th>
				<th><?php esc_html_e( 'M-Pesa Receipt', 'lipawoo' ); ?></th>
				<th><?php esc_html_e( 'Status', 'lipawoo' ); ?></th>
				<th><?php esc_html_e( 'Date', 'lipawoo' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'lipawoo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $transactions ) ) : ?>
			<tr>
				<td colspan="8" class="lipawoo-no-data">
					<?php esc_html_e( 'No transactions found.', 'lipawoo' ); ?>
				</td>
			</tr>
			<?php else : ?>
				<?php foreach ( $transactions as $tx ) : ?>
				<tr data-id="<?php echo esc_attr( $tx->id ); ?>">
					<td><input type="checkbox" class="lipawoo-row-check" value="<?php echo esc_attr( $tx->id ); ?>" /></td>
					<td>
						<a href="<?php echo esc_url( admin_url( "post.php?post={$tx->order_id}&action=edit" ) ); ?>" target="_blank">
							#<?php echo esc_html( $tx->order_id ); ?>
						</a>
					</td>
					<td><?php echo esc_html( $tx->phone_number ); ?></td>
					<td><?php echo esc_html( number_format( (float) $tx->amount, 2 ) ); ?></td>
					<td>
						<?php if ( $tx->mpesa_receipt ) : ?>
						<code><?php echo esc_html( $tx->mpesa_receipt ); ?></code>
						<?php else : ?>
						<span class="lipawoo-no-receipt">—</span>
						<?php endif; ?>
					</td>
					<td>
						<span class="lipawoo-status-badge lipawoo-status-<?php echo esc_attr( $tx->status ); ?>">
							<?php echo esc_html( ucfirst( $tx->status ) ); ?>
						</span>
					</td>
					<td>
						<span title="<?php echo esc_attr( $tx->created_at ); ?>">
							<?php echo esc_html( human_time_diff( strtotime( $tx->created_at ), time() ) . ' ago' ); ?>
						</span>
					</td>
					<td class="lipawoo-actions-cell">
						<?php if ( 'pending' === $tx->status ) : ?>
						<button class="button button-small lipawoo-query-btn" data-order-id="<?php echo esc_attr( $tx->order_id ); ?>">
							<?php esc_html_e( 'Query', 'lipawoo' ); ?>
						</button>
						<?php endif; ?>
						<button
							class="button button-small lipawoo-delete-btn"
							data-id="<?php echo esc_attr( $tx->id ); ?>"
							data-order="<?php echo esc_attr( $tx->order_id ); ?>"
							title="<?php esc_attr_e( 'Delete transaction record', 'lipawoo' ); ?>"
							style="color:#dc2626;border-color:#dc2626;margin-left:4px;"
						><?php esc_html_e( 'Delete', 'lipawoo' ); ?></button>
					</td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<!-- Pagination -->
	<?php if ( $total_pages > 1 ) : ?>
	<div class="lipawoo-pagination">
		<?php
		$base_url = add_query_arg( [
			'page'   => 'lipawoo-transactions',
			'status' => $status,
			'search' => $search,
		], admin_url( 'admin.php' ) );

		echo paginate_links( [
			'base'    => $base_url . '&paged=%#%',
			'format'  => '',
			'current' => $page,
			'total'   => $total_pages,
		] );
		?>
	</div>
	<?php endif; ?>
</div>
