<?php
/**
 * Segmentflow WooCommerce auto-auth handler.
 *
 * Handles the WooCommerce-specific auto-auth endpoint (/wc-auth/v1/authorize)
 * for automated API key generation during the connection flow.
 *
 * This file is only loaded when WooCommerce is active.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_WC_Auth
 *
 * Manages the WooCommerce auto-auth flow. This is the WC-specific part of
 * the connection process that generates consumer key + secret automatically.
 *
 * Two entry points:
 * 1. From plugin settings: Plugin redirects to Segmentflow dashboard, which
 *    redirects to WC consent screen, which POSTs credentials to Segmentflow API.
 * 2. From Segmentflow dashboard: User enters store URL, dashboard redirects
 *    to WC consent screen.
 *
 * In both cases, WooCommerce POSTs the credentials to the Segmentflow API
 * callback, not to this plugin directly.
 */
class Segmentflow_WC_Auth {

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
		// No hooks needed in the plugin itself for auto-auth.
		// The WC auto-auth flow is handled entirely via HTTP redirects:
		// 1. Plugin redirects to Segmentflow dashboard
		// 2. Dashboard generates WC auto-auth URL
		// 3. User approves on WC consent screen
		// 4. WC POSTs credentials to Segmentflow API callback
		// 5. API stores credentials and generates write key
		// 6. User is redirected back to plugin settings
		//
		// The plugin only needs to handle the final redirect back (which is
		// done in Segmentflow_Auth::maybe_handle_return()).
	}

	/**
	 * Build the WooCommerce auto-auth URL.
	 *
	 * This URL is used by the Segmentflow dashboard to redirect the user
	 * to the WC consent screen. The plugin itself doesn't call this directly --
	 * it's used by the Segmentflow API to construct the redirect.
	 *
	 * @param string $store_url    The WooCommerce store URL.
	 * @param string $callback_url The Segmentflow API callback URL.
	 * @param string $user_id      The organization ID (used as user_id in WC auth).
	 * @param string $return_url   The URL to redirect to after approval.
	 * @return string The WC auto-auth URL.
	 */
	public static function build_auth_url(
		string $store_url,
		string $callback_url,
		string $user_id,
		string $return_url,
	): string {
		return add_query_arg(
			[
				'app_name'     => 'Segmentflow',
				'scope'        => 'read_write',
				'user_id'      => $user_id,
				'return_url'   => rawurlencode( $return_url ),
				'callback_url' => rawurlencode( $callback_url ),
			],
			trailingslashit( $store_url ) . 'wc-auth/v1/authorize'
		);
	}

	/**
	 * Get WooCommerce store metadata.
	 *
	 * Collects metadata about the WooCommerce store for display in the
	 * Segmentflow dashboard and for the connection record.
	 *
	 * @return array{store_url: string, store_name: string, wc_version: string|null, wp_version: string, currency: string|null}
	 */
	public static function get_store_metadata(): array {
		return [
			'store_url'  => home_url(),
			'store_name' => get_bloginfo( 'name' ),
			'wc_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'wp_version' => get_bloginfo( 'version' ),
			'currency'   => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
		];
	}
}
