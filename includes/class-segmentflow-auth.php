<?php
/**
 * Segmentflow authentication handler.
 *
 * Manages the auto-auth connection flow between the WordPress plugin
 * and the Segmentflow dashboard.
 *
 * @package Segmentflow_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Auth
 *
 * Handles the auto-auth flow for connecting a WooCommerce store to Segmentflow.
 *
 * Two entry points:
 * 1. From WordPress Admin: User clicks "Connect to Segmentflow" in plugin settings.
 *    Plugin redirects to Segmentflow dashboard, which creates a PendingConnection with nonce,
 *    then redirects to WC auto-auth consent screen.
 * 2. From Segmentflow Dashboard: User enters store URL, dashboard redirects to
 *    WC auto-auth consent screen with nonce as user_id.
 *
 * After approval, WooCommerce POSTs credentials to the Segmentflow API callback.
 * The plugin polls for the write key using a temporary token.
 */
class Segmentflow_Auth {

	/**
	 * Get the Segmentflow dashboard URL for initiating connection.
	 *
	 * @return string The redirect URL to the Segmentflow dashboard.
	 */
	public static function get_connect_url(): string {
		$store_url  = home_url();
		$return_url = admin_url( 'admin.php?page=wc-settings&tab=segmentflow&connected=1' );
		$api_host   = Segmentflow_API::get_api_host();
		$app_host   = str_replace( 'api.', 'app.', $api_host );

		return add_query_arg(
			[
				'store_url'  => rawurlencode( $store_url ),
				'return_url' => rawurlencode( $return_url ),
			],
			$app_host . '/integrations/woocommerce/connect'
		);
	}

	/**
	 * Handle the return from the auth flow.
	 *
	 * Called when the user is redirected back to WP admin after approving the connection.
	 * Extracts the poll token from the URL and begins polling for the write key.
	 *
	 * @param string $poll_token The temporary token for polling connection status.
	 * @return bool Whether the write key was successfully retrieved and stored.
	 */
	public static function handle_return( string $poll_token ): bool {
		// TODO: Implement polling for write key.
		// Poll GET /integrations/woocommerce/status with the temporary token.
		// On success, store write key in wp_options.
		$status = Segmentflow_API::check_status( $poll_token );

		if ( ! empty( $status['connected'] ) && ! empty( $status['write_key'] ) ) {
			update_option( 'segmentflow_write_key', sanitize_text_field( $status['write_key'] ) );

			if ( ! empty( $status['organization_name'] ) ) {
				update_option( 'segmentflow_organization_name', sanitize_text_field( $status['organization_name'] ) );
			}

			return true;
		}

		return false;
	}

	/**
	 * Check if the store is currently connected to Segmentflow.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		$write_key = get_option( 'segmentflow_write_key', '' );
		return ! empty( $write_key );
	}

	/**
	 * Disconnect from Segmentflow.
	 *
	 * Removes the write key and connection settings. Does not remove webhooks
	 * or API credentials on the Segmentflow side (that's handled by the API).
	 *
	 * @return bool Whether the disconnection was successful.
	 */
	public static function disconnect(): bool {
		// Notify the Segmentflow API.
		Segmentflow_API::disconnect();

		// Remove local connection data.
		delete_option( 'segmentflow_write_key' );
		delete_option( 'segmentflow_organization_name' );

		return true;
	}
}
