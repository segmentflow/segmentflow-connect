<?php
/**
 * Mock API client for testing.
 *
 * Captures requests instead of sending HTTP calls, allowing tests
 * to assert on the payloads sent by server-side event classes.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Mock_Segmentflow_API
 *
 * Extends Segmentflow_API to intercept the request() method.
 */
class Mock_Segmentflow_API extends Segmentflow_API {

	/**
	 * Captured requests.
	 *
	 * @var array<int, array{method: string, endpoint: string, body: array, headers: array, options: array}>
	 */
	public array $requests = [];

	/**
	 * Intercept HTTP requests and store them for assertion.
	 *
	 * @param string                $method   HTTP method.
	 * @param string                $endpoint API endpoint.
	 * @param array<string, mixed>  $body     Request body.
	 * @param array<string, string> $headers  Additional headers.
	 * @param array<string, mixed>  $options  Request options.
	 * @return array{success: bool}
	 */
	public function request( string $method, string $endpoint, array $body = [], array $headers = [], array $options = [] ): array {
		$this->requests[] = [
			'method'   => $method,
			'endpoint' => $endpoint,
			'body'     => $body,
			'headers'  => $headers,
			'options'  => $options,
		];

		return [ 'success' => true ];
	}
}
