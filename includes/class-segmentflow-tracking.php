<?php
/**
 * Segmentflow storefront tracking.
 *
 * Injects the Segmentflow CDN SDK into the storefront via wp_head.
 *
 * @package Segmentflow_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Tracking
 *
 * Handles injection of the Segmentflow CDN SDK script into the storefront.
 * The SDK is loaded from cdn.segmentflow.ai and initialized with the store's
 * write key, customer identity, and WooCommerce context.
 *
 * Only active when a write key is configured (i.e., the store is connected).
 */
class Segmentflow_Tracking {

	/**
	 * Initialize tracking hooks.
	 */
	public static function init(): void {
		// Only inject on the frontend, not in admin.
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_head', [ __CLASS__, 'inject_sdk' ], 1 );
	}

	/**
	 * Inject the Segmentflow SDK script into the page head.
	 *
	 * Outputs the SDK loader script with WooCommerce context including:
	 * - Write key from wp_options
	 * - Customer identity (if logged in)
	 * - Cart hash
	 * - Store currency
	 */
	public static function inject_sdk(): void {
		$write_key = get_option( 'segmentflow_write_key', '' );

		// Don't inject if not connected.
		if ( empty( $write_key ) ) {
			return;
		}

		$api_host         = get_option( 'segmentflow_api_host', 'https://api.segmentflow.ai' );
		$debug_mode       = get_option( 'segmentflow_debug_mode', false );
		$consent_required = get_option( 'segmentflow_consent_required', false );

		// Build WooCommerce context data.
		$customer_id    = is_user_logged_in() ? get_current_user_id() : null;
		$customer_email = is_user_logged_in() ? wp_get_current_user()->user_email : null;
		$cart_hash      = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_hash() : null;
		$currency       = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

		// TODO: Implement full SDK injection script.
		// This stub outputs the basic structure. The full implementation will include
		// the async script loader, identify call, and context enrichment.
		?>
		<!-- Segmentflow for WooCommerce v<?php echo esc_html( SEGMENTFLOW_WC_VERSION ); ?> -->
		<script>
		(function() {
			var config = {
				writeKey: <?php echo wp_json_encode( $write_key ); ?>,
				host: <?php echo wp_json_encode( $api_host ); ?>,
				debug: <?php echo $debug_mode ? 'true' : 'false'; ?>,
				consentRequired: <?php echo $consent_required ? 'true' : 'false'; ?>
			};

			var wcContext = {
				storeUrl: <?php echo wp_json_encode( home_url() ); ?>,
				customerId: <?php echo wp_json_encode( $customer_id ); ?>,
				customerEmail: <?php echo wp_json_encode( $customer_email ); ?>,
				cartHash: <?php echo wp_json_encode( $cart_hash ); ?>,
				currency: <?php echo wp_json_encode( $currency ); ?>
			};

			var script = document.createElement('script');
			script.src = config.host.replace('api.', 'cdn.') + '/sdk.js';
			script.async = true;
			script.onload = function() {
				if (typeof window.segmentflow === 'undefined') return;

				window.segmentflow.init(config);

				if (wcContext.customerId) {
					window.segmentflow.identify('wc_' + wcContext.customerId, {
						email: wcContext.customerEmail,
						woocommerce_customer_id: wcContext.customerId
					});
				}

				if (wcContext.cartHash) {
					window.segmentflow.setContext({
						cart_hash: wcContext.cartHash,
						store_url: wcContext.storeUrl,
						currency: wcContext.currency
					});
				}
			};
			document.head.appendChild(script);
		})();
		</script>
		<?php
	}
}
