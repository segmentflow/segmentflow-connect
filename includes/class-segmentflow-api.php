<?php
/**
 * Segmentflow API client.
 *
 * Handles HTTP communication with the Segmentflow API.
 *
 * @package Segmentflow_Connect
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
	 * Check connection status with the Segmentflow API.
	 *
	 * Polls the API to check if the connection flow has completed and
	 * a write key is available.
	 *
	 * @param string $poll_token Temporary poll token from the auth flow.
	 * @return array{connected: bool, write_key?: string, organization_name?: string, error?: string}
	 */
	public function check_status( string $poll_token ): array {
		$response = $this->request(
			'GET',
			'/api/v1/integrations/connect/status',
			[],
			[
				'X-Poll-Token' => $poll_token,
			]
		);

		if ( ! $response['success'] ) {
			return [
				'connected' => false,
				'error'     => $response['error'] ?? __( 'Failed to check connection status.', 'segmentflow-connect' ),
			];
		}

		return $response['data'] ?? [ 'connected' => false ];
	}

	/**
	 * Notify the Segmentflow API of a disconnection.
	 *
	 * Best-effort -- if the API is unreachable, we still clean up locally.
	 *
	 * @return bool Whether the API acknowledged the disconnection.
	 */
	public function disconnect(): bool {
		$write_key = $this->options->get_write_key();

		if ( empty( $write_key ) ) {
			return true;
		}

		$response = $this->request(
			'DELETE',
			'/api/v1/integrations/connect/disconnect',
			[],
			[
				'X-Write-Key' => $write_key,
			]
		);

		return $response['success'];
	}

	/**
	 * Make an HTTP request to the Segmentflow API.
	 *
	 * @param string               $method   HTTP method (GET, POST, DELETE).
	 * @param string               $endpoint API endpoint path (e.g., '/api/v1/integrations/connect/status').
	 * @param array<string, mixed> $body     Request body (for POST/PUT).
	 * @param array<string, string>$headers  Additional headers.
	 * @return array{success: bool, data?: array<string, mixed>, error?: string}
	 */
	public function request( string $method, string $endpoint, array $body = [], array $headers = [] ): array {
		$url = $this->options->get_api_host() . $endpoint;

		$default_headers = [
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'User-Agent'   => 'SegmentflowConnect/' . SEGMENTFLOW_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
		];

		$args = [
			'method'  => strtoupper( $method ),
			'headers' => array_merge( $default_headers, $headers ),
			'timeout' => 15,
		];

		if ( ! empty( $body ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		if ( $status_code >= 200 && $status_code < 300 ) {
			return [
				'success' => true,
				'data'    => is_array( $data ) ? $data : [],
			];
		}

		return [
			'success' => false,
			'error'   => $data['error'] ?? $data['message'] ?? "HTTP {$status_code}",
		];
	}
}
