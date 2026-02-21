<?php
/**
 * Segmentflow WooCommerce settings tab template.
 *
 * Shows WooCommerce-specific settings and status information.
 * Only visible when WooCommerce is active AND the plugin is connected.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

$options            = new Segmentflow_Options();
$connected_platform = $options->get_connected_platform();
?>

<h2><?php esc_html_e( 'WooCommerce Integration', 'segmentflow-connect' ); ?></h2>

<?php if ( 'woocommerce' === $connected_platform ) : ?>
	<div class="segmentflow-connection-status segmentflow-connection-status--connected">
		<span class="dashicons dashicons-yes-alt"></span>
		<strong><?php esc_html_e( 'WooCommerce integration active', 'segmentflow-connect' ); ?></strong>
	</div>

	<table class="form-table">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Store URL', 'segmentflow-connect' ); ?></th>
				<td><?php echo esc_html( home_url() ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'WooCommerce Version', 'segmentflow-connect' ); ?></th>
				<td>
					<?php
					if ( defined( 'WC_VERSION' ) ) {
						echo esc_html( WC_VERSION );
					} else {
						esc_html_e( 'Unknown', 'segmentflow-connect' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Currency', 'segmentflow-connect' ); ?></th>
				<td>
					<?php
					if ( function_exists( 'get_woocommerce_currency' ) ) {
						echo esc_html( get_woocommerce_currency() );
					} else {
						echo '—';
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tracking', 'segmentflow-connect' ); ?></th>
				<td>
					<span class="segmentflow-badge segmentflow-badge--active">
						<?php esc_html_e( 'Full WooCommerce enrichment (cart, currency, customer ID)', 'segmentflow-connect' ); ?>
					</span>
				</td>
			</tr>
		</tbody>
	</table>

<?php else : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php esc_html_e( 'You are connected as a plain WordPress site. To enable WooCommerce features (order tracking, customer sync, revenue attribution), reconnect from the Connection tab.', 'segmentflow-connect' ); ?>
		</p>
	</div>
<?php endif; ?>
