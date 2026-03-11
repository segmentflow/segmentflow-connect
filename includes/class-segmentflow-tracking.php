<?php
/**
 * Segmentflow storefront tracking.
 *
 * Injects the Segmentflow CDN SDK into the storefront via wp_enqueue_scripts.
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
	 * The script handle used for the Segmentflow SDK.
	 *
	 * @var string
	 */
	const SDK_HANDLE = 'segmentflow-sdk';

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

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_sdk' ], 5 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_storefront_assets' ] );
	}

	/**
	 * Enqueue the Segmentflow SDK and attach the inline initialization script.
	 *
	 * Registers the CDN SDK as an external script via wp_enqueue_script() and
	 * attaches the initialization code via wp_add_inline_script() so WordPress
	 * manages script loading. The inline script runs after the SDK loads.
	 */
	public function enqueue_sdk(): void {
		$write_key = $this->options->get_write_key();

		// Don't inject if not connected.
		if ( empty( $write_key ) ) {
			return;
		}

		$api_host         = $this->options->get_api_host();
		$debug_mode       = $this->options->is_debug_mode();
		$consent_required = $this->options->is_consent_required();

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

		// Read identity from the unified sf_id cookie (set server-side on init).
		$sf_identity = Segmentflow_Identity_Cookie::read();

		// User identity: prefer cookie, fall back to WordPress session.
		$user_id    = null;
		$user_email = null;

		if ( is_user_logged_in() ) {
			$user_id    = $prefix . get_current_user_id();
			$user_email = wp_get_current_user()->user_email;

			// Enrich the cookie with the logged-in user's identity.
			Segmentflow_Identity_Cookie::write(
				[
					'u' => $user_id,
					'e' => $user_email,
				]
			);
		} elseif ( $sf_identity ) {
			// Anonymous or previously-identified visitor.
			$user_id    = $sf_identity['u'] ?? null;
			$user_email = $sf_identity['e'] ?? null;
		}

		// Anonymous ID is always available (ensure_anonymous_id ran on init).
		$anonymous_id = $sf_identity['a'] ?? null;

		// Register and enqueue the CDN SDK as an external script.
		// Security: all PHP values injected into JavaScript below use wp_json_encode(),
		// which produces valid JSON literals and escapes characters that could break
		// out of a <script> context (e.g., </script>, HTML entities). This is the
		// WordPress-recommended approach for safe PHP-to-JS data transfer.
		// Note: no 'strategy' key here. WordPress automatically demotes any
		// async/defer strategy to blocking when an inline after-script is
		// attached (wp_add_inline_script position='after'), which is exactly
		// the case below. Setting a strategy would be dead code and misleading.
		// See: https://make.wordpress.org/core/2023/07/14/registering-scripts-with-async-and-defer-attributes-in-wordpress-6-3/
		wp_enqueue_script(
			self::SDK_HANDLE,
			'https://cdn.cloud.segmentflow.ai/sdk.js',
			[],
			SEGMENTFLOW_VERSION,
			[
				'in_footer' => false,
			]
		);

		// Build the inline initialization script.
		// Security: all PHP values use wp_json_encode() for safe JS injection.
		$inline_js  = '/* Segmentflow Connect v' . esc_js( SEGMENTFLOW_VERSION ) . " */\n";
		$inline_js .= '(function() {' . "\n";
		$inline_js .= "\tif (typeof window.segmentflow === 'undefined') return;\n\n";

		$inline_js .= "\tvar config = {\n";
		$inline_js .= "\t\twriteKey: " . wp_json_encode( $write_key ) . ",\n";
		$inline_js .= "\t\thost: " . wp_json_encode( $api_host ) . ",\n";
		$inline_js .= "\t\tdebug: " . wp_json_encode( $debug_mode ) . ",\n";
		$inline_js .= "\t\tconsentRequired: " . wp_json_encode( $consent_required ) . "\n";
		$inline_js .= "\t};\n\n";

		$inline_js .= "\tvar wpContext = {\n";
		$inline_js .= "\t\tsiteUrl: " . wp_json_encode( home_url() ) . ",\n";
		$inline_js .= "\t\tuserId: " . wp_json_encode( $user_id ) . ",\n";
		$inline_js .= "\t\tuserEmail: " . wp_json_encode( $user_email ) . ",\n";
		$inline_js .= "\t\tanonymousId: " . wp_json_encode( $anonymous_id ) . ",\n";
		$inline_js .= "\t\tlocale: " . wp_json_encode( get_locale() ) . "\n";
		$inline_js .= "\t};\n\n";

		if ( $has_extra ) {
			$inline_js .= "\tvar integrationContext = " . wp_json_encode( $extra_context ) . ";\n";
			$inline_js .= "\twindow.__segmentflow_integration_context = integrationContext;\n\n";
		}

		$inline_js .= "\twindow.segmentflow.init(config);\n\n";

		$inline_js .= "\tif (wpContext.userId) {\n";

		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			// On thankyou page: webhook-based identity handles this via appendIdentitySignal().
			// Skipping PHP identify to prevent overwriting the billing email with the WP user email.
		} else {
			$inline_js .= "\t\tvar traits = {};\n";

			if ( ! ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
				$inline_js .= "\t\tif (wpContext.userEmail) { traits.email = wpContext.userEmail; }\n";
			}

			if ( $has_extra ) {
				$inline_js .= "\t\tif (typeof integrationContext !== 'undefined' && integrationContext.traits) {\n";
				$inline_js .= "\t\t\tObject.assign(traits, integrationContext.traits);\n";
				$inline_js .= "\t\t}\n";
			}

			$inline_js .= "\t\tvar identifyParams = { userId: wpContext.userId, traits: traits };\n";

			if ( $has_extra ) {
				$inline_js .= "\t\tif (typeof integrationContext !== 'undefined' && integrationContext.context) {\n";
				$inline_js .= "\t\t\tidentifyParams.context = integrationContext.context;\n";
				$inline_js .= "\t\t}\n";
			}

			$inline_js .= "\t\twindow.segmentflow.identify(identifyParams);\n";
		}

		$inline_js .= "\t}\n";
		$inline_js .= '})();';

		// Attach inline init script to run after the SDK loads.
		wp_add_inline_script( self::SDK_HANDLE, $inline_js );
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
			[ self::SDK_HANDLE ],
			(string) filemtime( $script_path ),
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
	}
}
