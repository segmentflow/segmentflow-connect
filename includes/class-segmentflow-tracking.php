<?php
/**
 * Segmentflow storefront tracking.
 *
 * Injects the Segmentflow CDN SDK into the storefront via wp_head.
 * Works on ANY WordPress site -- page views, identify for logged-in users.
 * WooCommerce-specific context is added via the 'segmentflow_tracking_context' filter.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Tracking
 *
 * Handles injection of the Segmentflow CDN SDK script into the storefront.
 * The SDK is loaded from cdn.cloud.segmentflow.ai and initialized with the site's
 * write key and WordPress user context.
 *
 * Integration-specific context (WooCommerce cart, currency, etc.) is added
 * via the 'segmentflow_tracking_context' filter, keeping this class
 * platform-agnostic.
 */
class Segmentflow_Tracking {

	/**
	 * Options instance.
	 */
	private Segmentflow_Options $options;

	/**
	 * Constructor.
	 *
	 * @param Segmentflow_Options $options The options instance.
	 */
	public function __construct( Segmentflow_Options $options ) {
		$this->options = $options;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		// Only inject on the frontend, not in admin.
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_head', [ $this, 'inject_sdk' ], 1 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_storefront_assets' ] );
	}

	/**
	 * Inject the Segmentflow SDK script into the page head.
	 *
	 * Outputs the SDK loader script with WordPress context including:
	 * - Write key from wp_options
	 * - WordPress user identity (if logged in)
	 * - Site URL and locale
	 *
	 * Integration-specific context (WooCommerce cart, currency, etc.) is
	 * injected via the 'segmentflow_tracking_context' filter.
	 */
	public function inject_sdk(): void {
		$write_key = $this->options->get_write_key();

		// Don't inject if not connected.
		if ( empty( $write_key ) ) {
			return;
		}

		$api_host         = $this->options->get_api_host();
		$debug_mode       = $this->options->is_debug_mode();
		$consent_required = $this->options->is_consent_required();

		// WordPress user context (available on ANY WordPress site).
		$user_id    = is_user_logged_in() ? get_current_user_id() : null;
		$user_email = is_user_logged_in() ? wp_get_current_user()->user_email : null;

		/**
		 * Filter the tracking context.
		 *
		 * Allows integrations (WooCommerce, CF7, etc.) to add their own
		 * context data to the SDK initialization.
		 *
		 * @param array $context The tracking context array.
		 */
		$extra_context = apply_filters( 'segmentflow_tracking_context', [] );
		$has_extra     = ! empty( $extra_context );

		// Determine user ID prefix based on active integrations.
		$prefix = 'wp_';
		if ( $has_extra && ! empty( $extra_context['platform'] ) && 'woocommerce' === $extra_context['platform'] ) {
			$prefix = 'wc_';
		}

		// Security: all PHP values injected into JavaScript below use wp_json_encode(),
		// which produces valid JSON literals and escapes characters that could break
		// out of a <script> context (e.g., </script>, HTML entities). This is the
		// WordPress-recommended approach for safe PHP-to-JS data transfer.
		?>
		<!-- Segmentflow Connect v<?php echo esc_html( SEGMENTFLOW_VERSION ); ?> -->
		<script>
		(function() {
			var config = {
				writeKey: <?php echo wp_json_encode( $write_key ); ?>,
				host: <?php echo wp_json_encode( $api_host ); ?>,
			debug: <?php echo wp_json_encode( $debug_mode ); ?>,
			consentRequired: <?php echo wp_json_encode( $consent_required ); ?>
			};

			var wpContext = {
				siteUrl: <?php echo wp_json_encode( home_url() ); ?>,
				userId: <?php echo wp_json_encode( $user_id ); ?>,
				userEmail: <?php echo wp_json_encode( $user_email ); ?>,
				locale: <?php echo wp_json_encode( get_locale() ); ?>
			};

		<?php if ( $has_extra ) : ?>
		var integrationContext = <?php echo wp_json_encode( $extra_context ); ?>;
		window.__segmentflow_integration_context = integrationContext;
		<?php endif; ?>

			var script = document.createElement('script');
			script.src = 'https://cdn.cloud.segmentflow.ai/sdk.js';
			script.async = true;
			script.onload = function() {
				if (typeof window.segmentflow === 'undefined') return;

				window.segmentflow.init(config);

			if (wpContext.userId) {
				<?php if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) : ?>
				// On thankyou page: webhook-based identity handles this via appendIdentitySignal().
				// Skipping PHP identify to prevent overwriting the billing email with the WP user email.
				<?php else : ?>
				var traits = { email: wpContext.userEmail };
					<?php if ( $has_extra ) : ?>
				if (typeof integrationContext !== 'undefined' && integrationContext.traits) {
					Object.assign(traits, integrationContext.traits);
				}
				<?php endif; ?>

				var identifyParams = {
					userId: <?php echo wp_json_encode( $prefix ); ?> + wpContext.userId,
					traits: traits
				};

					<?php if ( $has_extra ) : ?>
				if (typeof integrationContext !== 'undefined' && integrationContext.context) {
					identifyParams.context = integrationContext.context;
				}
				<?php endif; ?>

				window.segmentflow.identify(identifyParams);
				<?php endif; ?>
			}
			};
			document.head.appendChild(script);
		})();
		</script>
		<?php
	}

	/**
	 * Enqueue the storefront form-tracking script.
	 *
	 * Loads storefront.js on all frontend pages. The script listens for
	 * Contact Form 7 and Elementor Pro form submission events and forwards
	 * them to the SDK via window.segmentflow.track().
	 */
	public function enqueue_storefront_assets(): void {
		$write_key = $this->options->get_write_key();
		if ( empty( $write_key ) ) {
			return;
		}

		$script_path = SEGMENTFLOW_PATH . 'assets/js/storefront.iife.js';
		if ( ! file_exists( $script_path ) ) {
			return;
		}

		wp_enqueue_script(
			'segmentflow-storefront',
			SEGMENTFLOW_URL . 'assets/js/storefront.iife.js',
			[],
			(string) filemtime( $script_path ),
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
	}
}
