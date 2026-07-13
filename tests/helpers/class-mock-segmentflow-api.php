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
	 * Queued responses consumed FIFO by request().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $queued_responses = [];

	/**
	 * Intercept HTTP requests and store them for assertion.
	 *
	 * When no queued response is available and the body contains a batch,
	 * auto-builds an accepted success response matching each item's
	 * messageId so Segmentflow_Ingest_Client::send_batch can succeed.
	 *
	 * @param string                $method   HTTP method.
	 * @param string                $endpoint API endpoint.
	 * @param array<string, mixed>  $body     Request body.
	 * @param array<string, string> $headers  Additional headers.
	 * @param array<string, mixed>  $options  Request options.
	 * @return array{success: bool, status_code?: int, data?: array<string, mixed>, error?: string}
	 */
	public function request( string $method, string $endpoint, array $body = [], array $headers = [], array $options = [] ): array {
		$this->requests[] = [
			'method'   => $method,
			'endpoint' => $endpoint,
			'body'     => $body,
			'headers'  => $headers,
			'options'  => $options,
		];

		if ( ! empty( $this->queued_responses ) ) {
			return array_shift( $this->queued_responses );
		}

		if ( isset( $body['batch'] ) && is_array( $body['batch'] ) ) {
			return $this->build_accepted_batch_response( $body['batch'] );
		}

		return [
			'success'     => true,
			'status_code' => 200,
			'data'        => [],
		];
	}

	/**
	 * Build an HTTP 200 accepted response for each batch item.
	 *
	 * @param array<int, array<string, mixed>> $batch Request batch items.
	 * @return array{success: bool, status_code: int, data: array<string, mixed>}
	 */
	private function build_accepted_batch_response( array $batch ): array {
		$results = [];
		foreach ( array_values( $batch ) as $index => $item ) {
			$message_id = (string) ( $item['messageId'] ?? '' );
			$results[]  = [
				'index'     => (int) $index,
				'messageId' => $message_id,
				'status'    => 'accepted',
				'eventId'   => 'evt_' . $index,
				'duplicate' => false,
				'retryable' => false,
			];
		}

		return [
			'success'     => true,
			'status_code' => 200,
			'data'        => [
				'success' => true,
				'results' => $results,
				'summary' => [
					'accepted'  => count( $results ),
					'duplicate' => 0,
					'dropped'   => 0,
					'rejected'  => 0,
					'failed'    => 0,
				],
			],
		];
	}
}
