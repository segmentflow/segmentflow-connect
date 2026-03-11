<?php
/**
 * Segmentflow Forms tab template.
 *
 * Guides the user through setting up lead magnets and popup forms
 * for this WordPress site. All form management happens in the
 * Segmentflow dashboard — no embed code is needed.
 *
 * @package Segmentflow_Connect
 *
 * @var Segmentflow_Admin $this The admin instance (available via include context).
 */

defined( 'ABSPATH' ) || exit;

$segmentflow_options  = new Segmentflow_Options();
$segmentflow_app_host = rtrim( $segmentflow_options->get_app_host(), '/' );

// The site URL the user must enter in the Segmentflow dashboard.
// Uses home_url() with no trailing slash and no path component.
$segmentflow_site_url = untrailingslashit( home_url() );

// Dashboard links.
$segmentflow_websites_url = $segmentflow_app_host . '/websites';
?>

<div class="segmentflow-forms-tab">

	<!-- Section 1: Your Site URL -->
	<div class="segmentflow-forms-section">
		<h2><?php esc_html_e( 'Your Site URL', 'segmentflow-connect' ); ?></h2>
		<p class="segmentflow-description">
			<?php esc_html_e( 'This URL must match your website record in Segmentflow exactly. Use the copy button to avoid typos.', 'segmentflow-connect' ); ?>
		</p>

		<div class="segmentflow-url-copy-row">
			<code class="segmentflow-site-url-display" id="segmentflow-site-url">
				<?php echo esc_html( $segmentflow_site_url ); ?>
			</code>
			<button
				type="button"
				class="button segmentflow-copy-btn"
				data-url="<?php echo esc_attr( $segmentflow_site_url ); ?>"
				aria-label="<?php esc_attr_e( 'Copy site URL to clipboard', 'segmentflow-connect' ); ?>"
			>
				<?php esc_html_e( 'Copy', 'segmentflow-connect' ); ?>
			</button>
		</div>
	</div>

	<hr class="segmentflow-divider" />

	<!-- Section 2: Get Started -->
	<div class="segmentflow-forms-section">
		<h2><?php esc_html_e( 'Get Started', 'segmentflow-connect' ); ?></h2>

		<div class="segmentflow-forms-actions">
			<div class="segmentflow-forms-action-card">
				<h3><?php esc_html_e( 'New to Segmentflow?', 'segmentflow-connect' ); ?></h3>
				<p class="description">
					<?php
					printf(
						/* translators: %s: site URL */
						esc_html__( 'Create a website in the Segmentflow dashboard, then paste your site URL (%s) when prompted.', 'segmentflow-connect' ),
						'<code>' . esc_html( $segmentflow_site_url ) . '</code>'
					);
					?>
				</p>
				<a
					href="<?php echo esc_url( $segmentflow_websites_url ); ?>"
					target="_blank"
					rel="noopener"
					class="button button-primary"
				>
					<?php esc_html_e( 'Create Website in Dashboard', 'segmentflow-connect' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in new tab)', 'segmentflow-connect' ); ?></span>
				</a>
			</div>

			<div class="segmentflow-forms-action-card">
				<h3><?php esc_html_e( 'Already have a website configured?', 'segmentflow-connect' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Go to your Segmentflow dashboard to create and manage lead magnets and popup forms for this site.', 'segmentflow-connect' ); ?>
				</p>
				<a
					href="<?php echo esc_url( $segmentflow_websites_url ); ?>"
					target="_blank"
					rel="noopener"
					class="button"
				>
					<?php esc_html_e( 'Manage Forms in Dashboard', 'segmentflow-connect' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in new tab)', 'segmentflow-connect' ); ?></span>
				</a>
			</div>
		</div>
	</div>

	<hr class="segmentflow-divider" />

	<!-- Section 3: How It Works -->
	<div class="segmentflow-forms-section">
		<h2><?php esc_html_e( 'How It Works', 'segmentflow-connect' ); ?></h2>

		<ol class="segmentflow-forms-steps">
			<li>
				<strong><?php esc_html_e( 'Create a website record', 'segmentflow-connect' ); ?></strong>
				<?php
				printf(
					/* translators: %s: site URL */
					' &mdash; ' . esc_html__( 'In the Segmentflow dashboard, add a website with the URL %s.', 'segmentflow-connect' ),
					'<code>' . esc_html( $segmentflow_site_url ) . '</code>'
				);
				?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Create a lead magnet or form', 'segmentflow-connect' ); ?></strong>
				<?php echo ' &mdash; ' . esc_html__( 'Use the form builder to design a popup, inline, or slide-in form and assign it to your website.', 'segmentflow-connect' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Publish the form', 'segmentflow-connect' ); ?></strong>
				<?php echo ' &mdash; ' . esc_html__( 'Set the form status to "Published". It will appear on your site automatically — no shortcode or embed code needed.', 'segmentflow-connect' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Collect leads and send emails', 'segmentflow-connect' ); ?></strong>
				<?php echo ' &mdash; ' . esc_html__( 'Submissions are automatically added to a segment and can trigger lead magnet delivery emails or journeys.', 'segmentflow-connect' ); ?>
			</li>
		</ol>

		<div class="notice notice-info inline segmentflow-forms-tip">
			<p>
				<strong><?php esc_html_e( 'Testing tip:', 'segmentflow-connect' ); ?></strong>
				<?php esc_html_e( 'Popup forms with frequency "once" store a flag in your browser\'s localStorage. To make a popup reappear during testing, open your browser console and run:', 'segmentflow-connect' ); ?>
				<br />
				<code>Object.keys(localStorage).filter(k =&gt; k.startsWith('sf_form_shown_')).forEach(k =&gt; localStorage.removeItem(k))</code>
			</p>
		</div>
	</div>

</div>
