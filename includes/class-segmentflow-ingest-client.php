<?php
/**
 * Identified ingest HTTP client.
 *
 * Owns batch request construction, response parsing, and bounded retry
 * (at most one retry) for lost responses, HTTP 503, and item outcomes
 * with retryable: true. No durable queue or cron worker.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Ingest_Client
 *
 * Sends identified batches to POST /api/v1/ingest/batch via Segmentflow_API.
 */
class Segmentflow_Ingest_Client {

	/**
	 * Per-attempt timeout in seconds.
	 */
	const TIMEOUT_SECONDS = 1.5;

	/**
	 * Ingest batch endpoint.
	 */
	const BATCH_ENDPOINT = '/api/v1/ingest/batch';

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * HTTP adapter.
	 *
	 * @var Segmentflow_API
	 */
	private Segmentflow_API $api;

	/**
	 * Constructor.
	 *
	 * @param Segmentflow_Options $options Options instance.
	 * @param Segmentflow_API     $api     HTTP adapter.
	 */
	public function __construct( Segmentflow_Options $options, Segmentflow_API $api ) {
		$this->options = $options;
		$this->api     = $api;
	}

	/**
	 * Send an identified batch.
	 *
	 * Retries once for network failure, HTTP 503, or items marked
	 * retryable. Accepted / duplicate / dropped / rejected / HTTP 400 /
	 * HTTP 409 outcomes are terminal. A mixed 200 response retries only
	 * retryable items, preserving original order and message IDs.
	 *
	 * @param array<int, array<string, mixed>>             $items   Pre-built ingest items.
	 * @param array{analytics: bool, marketing: bool}|null $consent Optional consent snapshot.
	 * @return array{
	 *   ok: bool,
	 *   transport_error?: string,
	 *   status_code?: int,
	 *   results?: array<int, array<string, mixed>>,
	 *   summary?: array<string, int>,
	 *   attempts: int
	 * }
	 */
	public function send_batch( array $items, ?array $consent = null ): array {
		if ( empty( $items ) ) {
			return [
				'ok'       => true,
				'results'  => [],
				'summary'  => self::empty_summary(),
				'attempts' => 0,
			];
		}

		$write_key = $this->options->get_write_key();
		if ( empty( $write_key ) ) {
			return [
				'ok'              => false,
				'transport_error' => 'missing_write_key',
				'attempts'        => 0,
			];
		}

		$original_items            = array_values( $items );
		$pending                   = $original_items;
		$pending_original_indexes  = array_keys( $original_items );
		$final_results_by_original = [];
		$attempts                  = 0;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			++$attempts;
			$response = $this->post_batch( $write_key, $pending, $consent );

			if ( ! empty( $response['network_error'] ) ) {
				if ( 0 === $attempt ) {
					continue;
				}
				return [
					'ok'              => false,
					'transport_error' => (string) $response['network_error'],
					'attempts'        => $attempts,
				];
			}

			$status_code = (int) ( $response['status_code'] ?? 0 );

			if ( 503 === $status_code ) {
				if ( 0 === $attempt ) {
					continue;
				}
				return [
					'ok'              => false,
					'transport_error' => 'http_503',
					'status_code'     => 503,
					'attempts'        => $attempts,
				];
			}

			if ( 400 === $status_code || 409 === $status_code ) {
				return [
					'ok'              => false,
					'transport_error' => 'http_' . $status_code,
					'status_code'     => $status_code,
					'attempts'        => $attempts,
				];
			}

			if ( 200 !== $status_code ) {
				if ( 0 === $attempt ) {
					continue;
				}
				return [
					'ok'              => false,
					'transport_error' => 'http_' . $status_code,
					'status_code'     => $status_code,
					'attempts'        => $attempts,
				];
			}

			$parsed = $this->parse_batch_response( $response['data'] ?? null, $pending );
			if ( ! empty( $parsed['transport_error'] ) ) {
				if ( 0 === $attempt ) {
					continue;
				}
				return [
					'ok'              => false,
					'transport_error' => (string) $parsed['transport_error'],
					'status_code'     => 200,
					'attempts'        => $attempts,
				];
			}

			$next_pending          = [];
			$next_original_indexes = [];

			foreach ( $parsed['results'] as $result ) {
				$pending_index  = (int) $result['index'];
				$original_index = (int) $pending_original_indexes[ $pending_index ];

				$result['index']                              = $original_index;
				$final_results_by_original[ $original_index ] = $result;

				if ( ! empty( $result['retryable'] ) ) {
					$next_pending[]          = $pending[ $pending_index ];
					$next_original_indexes[] = $original_index;
				}
			}

			if ( empty( $next_pending ) || 1 === $attempt ) {
				ksort( $final_results_by_original );
				$results = array_values( $final_results_by_original );
				return [
					'ok'          => true,
					'status_code' => 200,
					'results'     => $results,
					'summary'     => self::summarize_results( $results ),
					'attempts'    => $attempts,
				];
			}

			$pending                  = $next_pending;
			$pending_original_indexes = $next_original_indexes;
		}

		return [
			'ok'              => false,
			'transport_error' => 'retry_exhausted',
			'attempts'        => $attempts,
		];
	}

	/**
	 * POST one batch attempt.
	 *
	 * @param string                                       $write_key Write key.
	 * @param array<int, array<string, mixed>>             $items     Items for this attempt.
	 * @param array{analytics: bool, marketing: bool}|null $consent   Consent snapshot.
	 * @return array{network_error?: string, status_code?: int, data?: mixed}
	 */
	private function post_batch( string $write_key, array $items, ?array $consent ): array {
		$payload = [
			'writeKey' => $write_key,
			'batch'    => array_values( $items ),
		];
		if ( null !== $consent ) {
			$payload['consent'] = [
				'analytics' => ! empty( $consent['analytics'] ),
				'marketing' => ! empty( $consent['marketing'] ),
			];
		}

		$response = $this->api->request(
			'POST',
			self::BATCH_ENDPOINT,
			$payload,
			[],
			[
				'blocking' => true,
				'timeout'  => self::TIMEOUT_SECONDS,
			]
		);

		if ( empty( $response['success'] ) && empty( $response['status_code'] ) ) {
			return [
				'network_error' => (string) ( $response['error'] ?? 'network_error' ),
			];
		}

		return [
			'status_code' => (int) ( $response['status_code'] ?? ( ! empty( $response['success'] ) ? 200 : 0 ) ),
			'data'        => $response['data'] ?? null,
		];
	}

	/**
	 * Parse and validate a successful batch response body.
	 *
	 * Rejects missing / duplicate / reordered index or mismatched messageId
	 * as a transport failure.
	 *
	 * @param mixed                            $data    Decoded response body.
	 * @param array<int, array<string, mixed>> $pending Items sent in this attempt.
	 * @return array{transport_error?: string, results?: array<int, array<string, mixed>>}
	 */
	private function parse_batch_response( $data, array $pending ): array {
		if ( ! is_array( $data ) || empty( $data['success'] ) || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return [ 'transport_error' => 'invalid_response_shape' ];
		}

		$results = $data['results'];
		if ( count( $results ) !== count( $pending ) ) {
			return [ 'transport_error' => 'result_count_mismatch' ];
		}

		$seen_indexes = [];
		$normalized   = [];

		foreach ( $results as $position => $result ) {
			if ( ! is_array( $result ) ) {
				return [ 'transport_error' => 'invalid_result_item' ];
			}
			if ( ! array_key_exists( 'index', $result ) ) {
				return [ 'transport_error' => 'missing_index' ];
			}
			if ( ! is_int( $result['index'] ) && ! ctype_digit( (string) $result['index'] ) ) {
				return [ 'transport_error' => 'missing_index' ];
			}

			$index = (int) $result['index'];
			if ( $index !== (int) $position ) {
				return [ 'transport_error' => 'reordered_index' ];
			}
			if ( isset( $seen_indexes[ $index ] ) ) {
				return [ 'transport_error' => 'duplicate_index' ];
			}
			if ( ! array_key_exists( $index, $pending ) ) {
				return [ 'transport_error' => 'out_of_range_index' ];
			}
			$seen_indexes[ $index ] = true;

			$expected_message_id = (string) ( $pending[ $index ]['messageId'] ?? '' );
			$actual_message_id   = (string) ( $result['messageId'] ?? '' );
			if ( '' === $actual_message_id || $actual_message_id !== $expected_message_id ) {
				return [ 'transport_error' => 'message_id_mismatch' ];
			}

			$status = (string) ( $result['status'] ?? '' );
			if ( ! in_array( $status, [ 'accepted', 'duplicate', 'dropped', 'rejected', 'failed' ], true ) ) {
				return [ 'transport_error' => 'invalid_status' ];
			}

			// Trust the contract's retryable flag; failed outcomes are retryable.
			$retryable = array_key_exists( 'retryable', $result )
				? (bool) $result['retryable']
				: ( 'failed' === $status );

			$normalized_result = [
				'index'     => $index,
				'messageId' => $actual_message_id,
				'status'    => $status,
				'duplicate' => ! empty( $result['duplicate'] ),
				'retryable' => $retryable,
			];

			if ( isset( $result['eventId'] ) ) {
				$normalized_result['eventId'] = (string) $result['eventId'];
			}
			if ( isset( $result['reason'] ) ) {
				$normalized_result['reason'] = (string) $result['reason'];
			}

			$normalized[] = $normalized_result;
		}

		return [ 'results' => $normalized ];
	}

	/**
	 * Empty summary counters.
	 *
	 * @return array<string, int>
	 */
	private static function empty_summary(): array {
		return [
			'accepted'  => 0,
			'duplicate' => 0,
			'dropped'   => 0,
			'rejected'  => 0,
			'failed'    => 0,
		];
	}

	/**
	 * Summarize normalized item outcomes.
	 *
	 * @param array<int, array<string, mixed>> $results Normalized results.
	 * @return array<string, int>
	 */
	private static function summarize_results( array $results ): array {
		$summary = self::empty_summary();
		foreach ( $results as $result ) {
			$status = (string) ( $result['status'] ?? '' );
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
		}
		return $summary;
	}
}
