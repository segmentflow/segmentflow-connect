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

$segmentflow_options  = new Segmentflow_Options();
$segmentflow_auth     = new Segmentflow_Auth( $segmentflow_options );
$segmentflow_platform = Segmentflow_Helper::get_platform();
?>

<?php if ( $segmentflow_auth->is_connected() ) : ?>
	<div class="segmentflow-connection-status segmentflow-connection-status--connected">
		<span class="dashicons dashicons-yes-alt"></span>
		<strong><?php esc_html_e( 'Connected to Segmentflow', 'segmentflow-connect' ); ?></strong>
	</div>

	<table class="form-table">
		<tbody>
			<?php
			$segmentflow_org_name = $segmentflow_options->get( 'organization_name' );
			if ( ! empty( $segmentflow_org_name ) ) :
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Organization', 'segmentflow-connect' ); ?></th>
					<td><?php echo esc_html( $segmentflow_org_name ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Platform', 'segmentflow-connect' ); ?></th>
				<td>
					<?php
					$segmentflow_connected_platform = $segmentflow_options->get_connected_platform();
					echo esc_html( 'woocommerce' === $segmentflow_connected_platform ? 'WooCommerce' : 'WordPress' );
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Write Key', 'segmentflow-connect' ); ?></th>
				<td>
					<code><?php echo esc_html( substr( $segmentflow_options->get_write_key(), 0, 8 ) . '...' ); ?></code>
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
			<tr>
				<th scope="row"><?php esc_html_e( 'Lead Magnets &amp; Forms', 'segmentflow-connect' ); ?></th>
				<td>
					<?php $segmentflow_websites_url = rtrim( $segmentflow_options->get_app_host(), '/' ) . '/websites'; ?>
					<a href="<?php echo esc_url( $segmentflow_websites_url ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Manage in Segmentflow dashboard', 'segmentflow-connect' ); ?>
					</a>
					<p class="description">
						<?php esc_html_e( 'Published forms are automatically displayed on your site — no embed code needed.', 'segmentflow-connect' ); ?>
					</p>
				</td>
			</tr>
		</tbody>
	</table>

	<?php if ( ! Segmentflow_Helper::is_woocommerce_active() && 'WordPress' === $segmentflow_connected_platform ) : ?>
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
	<?php
	// Check for logo file: try SVG first, then PNG.
	$segmentflow_logo_path = SEGMENTFLOW_PATH . 'assets/images/logo.svg';
	$segmentflow_logo_url  = SEGMENTFLOW_URL . 'assets/images/logo.svg';

	if ( ! file_exists( $segmentflow_logo_path ) ) {
		$segmentflow_logo_path = SEGMENTFLOW_PATH . 'assets/images/logo.png';
		$segmentflow_logo_url  = SEGMENTFLOW_URL . 'assets/images/logo.png';
	}

	if ( file_exists( $segmentflow_logo_path ) ) :
		?>
		<div class="segmentflow-logo">
			<img src="<?php echo esc_url( $segmentflow_logo_url ); ?>" alt="<?php esc_attr_e( 'Segmentflow', 'segmentflow-connect' ); ?>" />
		</div>
	<?php endif; ?>

	<div class="segmentflow-connection-status segmentflow-connection-status--disconnected">
		<span class="dashicons dashicons-warning"></span>
		<strong><?php esc_html_e( 'Not connected to Segmentflow', 'segmentflow-connect' ); ?></strong>
	</div>

	<h2><?php esc_html_e( 'The 60-Second Marketing Team', 'segmentflow-connect' ); ?></h2>
	<p class="segmentflow-description">
		<?php
		if ( Segmentflow_Helper::is_woocommerce_active() ) {
			esc_html_e( 'Segmentflow does the work of a data analyst, a designer, and a copywriter in the time it takes to brew a cup of coffee. Stop wrestling with complex segment builders and clunky drag-and-drop editors. Segmentflow uses AI to turn your WooCommerce data into high-converting campaigns through simple conversation.', 'segmentflow-connect' );
		} else {
			esc_html_e( 'Segmentflow does the work of a data analyst, a designer, and a copywriter in the time it takes to brew a cup of coffee. Stop wrestling with complex segment builders and clunky drag-and-drop editors. Segmentflow uses AI to turn your WordPress data into high-converting campaigns through simple conversation.', 'segmentflow-connect' );
		}
		?>
	</p>

	<div class="segmentflow-features">
		<div class="segmentflow-feature">
			<h3><?php esc_html_e( 'Talk to your data', 'segmentflow-connect' ); ?></h3>
			<p>
				<?php esc_html_e( 'Stop clicking through dropdowns. Describe your audience in plain English — like "Customers who bought twice but haven\'t opened an email in a month" — and we\'ll build the segment instantly.', 'segmentflow-connect' ); ?>
			</p>
		</div>

		<div class="segmentflow-feature">
			<h3><?php esc_html_e( 'Brand-aware creative', 'segmentflow-connect' ); ?></h3>
			<p>
				<?php
				if ( Segmentflow_Helper::is_woocommerce_active() ) {
					esc_html_e( 'AI that actually knows your brand. We automatically pull your WooCommerce product photos, logos, and color palette to generate professional, ready-to-send templates. No designer or manual work required.', 'segmentflow-connect' );
				} else {
					esc_html_e( 'AI that actually knows your brand. We automatically pull your logos and color palette to generate professional, ready-to-send templates. No designer or manual work required.', 'segmentflow-connect' );
				}
				?>
			</p>
		</div>

		<div class="segmentflow-feature">
			<h3><?php esc_html_e( 'One-click campaign sending', 'segmentflow-connect' ); ?></h3>
			<p>
				<?php esc_html_e( 'A streamlined platform built for speed. Launch your marketing campaigns in seconds and get real-time delivery stats (opens, clicks, and bounces) without the enterprise complexity.', 'segmentflow-connect' ); ?>
			</p>
		</div>

		<?php if ( Segmentflow_Helper::is_woocommerce_active() ) : ?>
			<div class="segmentflow-feature">
				<h3><?php esc_html_e( 'Stop Guessing. Start Banking.', 'segmentflow-connect' ); ?></h3>
				<p>
					<?php esc_html_e( 'Open rates are a vanity metric; revenue is a reality check. Segmentflow bridges the gap between your sent folder and your WooCommerce checkout. Don\'t just track who clicked — track who paid. See the exact dollar amount every AI-generated campaign puts into your pocket.', 'segmentflow-connect' ); ?>
				</p>
			</div>
		<?php endif; ?>
	</div>

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
			'<a href="https://dashboard.segmentflow.ai/auth/signup" target="_blank" rel="noopener">' . esc_html__( 'Sign up for free', 'segmentflow-connect' ) . '</a>'
		);
		?>
	</p>
<?php endif; ?>
