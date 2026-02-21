<?php
/**
 * Segmentflow API client.
 *
 * Handles HTTP communication with the Segmentflow API.
 *
 * @package Segmentflow_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_API
 *
 * HTTP client for communicating with the Segmentflow API.
 * Used for status checks, write key retrieval, and connection management.
 */
class Segmentflow_API {

	/**
	 * Get the Segmentflow API host URL.
	 *
	 * @return string
	 */
	public static function get_api_host(): string {
		return get_option( 'segmentflow_api_host', 'https://api.segmentflow.ai' );
	}

	/**
	 * Check connection status with the Segmentflow API.
	 *
	 * @param string $poll_token Temporary poll token from the auth flow.
	 * @return array{connected: bool, write_key?: string, organization_name?: string, error?: string}
	 */
	public static function check_status( string $poll_token ): array {
		// TODO: Implement API status check.
		// GET /integrations/woocommerce/status with poll token.
		return [ 'connected' => false ];
	}

	/**
	 * Notify the Segmentflow API of a disconnection.
	 *
	 * @return bool Whether the disconnection was successful.
	 */
	public static function disconnect(): bool {
		// TODO: Implement API disconnect call.
		return true;
	}

	/**
	 * Make an authenticated request to the Segmentflow API.
	 *
	 * @param string $method   HTTP method (GET, POST, DELETE).
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Request body (for POST/PUT).
	 * @param array  $headers  Additional headers.
	 * @return array{success: bool, data?: array, error?: string}
	 */
	public static function request( string $method, string $endpoint, array $body = [], array $headers = [] ): array {
		// TODO: Implement HTTP request using wp_remote_request().
		return [ 'success' => false, 'error' => 'Not implemented' ];
	}
}
