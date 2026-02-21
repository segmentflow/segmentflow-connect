<?php
/**
 * Segmentflow connection tab template.
 *
 * Shows the connect/disconnect UI based on connection state.
 * Always visible regardless of which integrations are active.
 *
 * @package Segmentflow_Connect
 *
 * @var Segmentflow_Admin $this The admin instance (available via include context).
 */

defined( 'ABSPATH' ) || exit;

$options  = new Segmentflow_Options();
$auth     = new Segmentflow_Auth( $options );
$platform = Segmentflow_Helper::get_platform();
?>

<?php if ( $auth->is_connected() ) : ?>
	<div class="segmentflow-connection-status segmentflow-connection-status--connected">
		<span class="dashicons dashicons-yes-alt"></span>
		<strong><?php esc_html_e( 'Connected to Segmentflow', 'segmentflow-connect' ); ?></strong>
	</div>

	<table class="form-table">
		<tbody>
			<?php
			$org_name = $options->get( 'organization_name' );
			if ( ! empty( $org_name ) ) :
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Organization', 'segmentflow-connect' ); ?></th>
					<td><?php echo esc_html( $org_name ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Platform', 'segmentflow-connect' ); ?></th>
				<td>
					<?php
					$connected_platform = $options->get_connected_platform();
					echo esc_html( 'woocommerce' === $connected_platform ? 'WooCommerce' : 'WordPress' );
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Write Key', 'segmentflow-connect' ); ?></th>
				<td>
					<code><?php echo esc_html( substr( $options->get_write_key(), 0, 8 ) . '...' ); ?></code>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tracking Status', 'segmentflow-connect' ); ?></th>
				<td>
					<span class="segmentflow-badge segmentflow-badge--active">
						<?php
						if ( Segmentflow_Helper::is_woocommerce_active() ) {
							esc_html_e( 'Active (full WooCommerce enrichment)', 'segmentflow-connect' );
						} else {
							esc_html_e( 'Active (page views + identify)', 'segmentflow-connect' );
						}
						?>
					</span>
				</td>
			</tr>
		</tbody>
	</table>

	<?php if ( ! Segmentflow_Helper::is_woocommerce_active() && 'wordpress' === $connected_platform ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'WooCommerce not detected. Install WooCommerce for order tracking, customer sync, and revenue attribution.', 'segmentflow-connect' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<p>
		<button type="button" id="segmentflow-disconnect" class="segmentflow-disconnect-btn">
			<?php esc_html_e( 'Disconnect', 'segmentflow-connect' ); ?>
		</button>
	</p>

<?php else : ?>
	<div class="segmentflow-connection-status segmentflow-connection-status--disconnected">
		<span class="dashicons dashicons-warning"></span>
		<strong><?php esc_html_e( 'Not connected to Segmentflow', 'segmentflow-connect' ); ?></strong>
	</div>

	<h2><?php esc_html_e( 'Connect to Segmentflow', 'segmentflow-connect' ); ?></h2>
	<p>
		<?php
		if ( Segmentflow_Helper::is_woocommerce_active() ) {
			esc_html_e( 'Connect your WooCommerce store to Segmentflow for AI-powered email marketing, customer segmentation, and revenue attribution.', 'segmentflow-connect' );
		} else {
			esc_html_e( 'Connect your WordPress site to Segmentflow for page view tracking and visitor identification.', 'segmentflow-connect' );
		}
		?>
	</p>

	<ul class="ul-disc">
		<?php if ( Segmentflow_Helper::is_woocommerce_active() ) : ?>
			<li><?php esc_html_e( 'Automatic customer and order data sync', 'segmentflow-connect' ); ?></li>
			<li><?php esc_html_e( 'Real-time webhooks for new orders and customer updates', 'segmentflow-connect' ); ?></li>
			<li><?php esc_html_e( 'Pre-built customer segments (Repeat Customers, Churning, etc.)', 'segmentflow-connect' ); ?></li>
			<li><?php esc_html_e( 'Revenue attribution for email campaigns', 'segmentflow-connect' ); ?></li>
		<?php else : ?>
			<li><?php esc_html_e( 'Page view tracking', 'segmentflow-connect' ); ?></li>
			<li><?php esc_html_e( 'Automatic visitor identification for logged-in users', 'segmentflow-connect' ); ?></li>
			<li><?php esc_html_e( 'Browser context and referrer tracking', 'segmentflow-connect' ); ?></li>
		<?php endif; ?>
	</ul>

	<p>
		<a href="#" id="segmentflow-connect" class="segmentflow-connect-btn">
			<?php esc_html_e( 'Connect to Segmentflow', 'segmentflow-connect' ); ?>
		</a>
	</p>

	<p class="description">
		<?php
		printf(
			/* translators: %s: Segmentflow signup URL */
			esc_html__( 'Don\'t have an account? %s', 'segmentflow-connect' ),
			'<a href="https://segmentflow.ai" target="_blank" rel="noopener">' . esc_html__( 'Sign up for free', 'segmentflow-connect' ) . '</a>'
		);
		?>
	</p>
<?php endif; ?>
