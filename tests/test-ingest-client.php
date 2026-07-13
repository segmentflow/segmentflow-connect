<?php
/**
 * Tests for Segmentflow_Ingest_Client retry and response parsing.
 *
 * @package Segmentflow_Connect
 */

// phpcs:disable WordPress.WP.CapitalPDangit -- 'wordpress' is a frozen platform/source identifier.

require_once __DIR__ . '/helpers/class-mock-segmentflow-api.php';

/**
 * Class Test_Ingest_Client
 */
class Test_Ingest_Client extends WP_UnitTestCase {

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * Mock API.
	 *
	 * @var Mock_Segmentflow_API
	 */
	private Mock_Segmentflow_API $mock_api;

	/**
	 * Client under test.
	 *
	 * @var Segmentflow_Ingest_Client
	 */
	private Segmentflow_Ingest_Client $client;

	/**
	 * Set up fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'segmentflow_write_key', 'wk_test_ingest' );
		$this->options  = new Segmentflow_Options();
		$this->mock_api = new Mock_Segmentflow_API( $this->options );
		$this->client   = new Segmentflow_Ingest_Client( $this->options, $this->mock_api );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tear_down(): void {
		delete_option( 'segmentflow_write_key' );
		parent::tear_down();
	}

	/**
	 * Accepted items receive one attempt.
	 */
	public function test_accepted_items_are_not_retried(): void {
		$items                              = [ $this->track_item( 'msg-1' ) ];
		$this->mock_api->queued_responses[] = $this->success_response(
			[
				$this->accepted_result( 0, 'msg-1' ),
			]
		);

		$result = $this->client->send_batch( $items );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['attempts'] );
		$this->assertCount( 1, $this->mock_api->requests );
		$this->assertSame( 1.5, $this->mock_api->requests[0]['options']['timeout'] );
		$this->assertTrue( $this->mock_api->requests[0]['options']['blocking'] );
	}

	/**
	 * Duplicate / dropped / rejected outcomes are terminal.
	 */
	public function test_terminal_item_outcomes_are_not_retried(): void {
		$items                              = [
			$this->track_item( 'msg-dup' ),
			$this->track_item( 'msg-drop' ),
			$this->track_item( 'msg-rej' ),
		];
		$this->mock_api->queued_responses[] = $this->success_response(
			[
				[
					'index'     => 0,
					'messageId' => 'msg-dup',
					'status'    => 'duplicate',
					'reason'    => 'duplicate_occurrence',
					'eventId'   => 'evt_dup',
					'duplicate' => true,
					'retryable' => false,
				],
				[
					'index'     => 1,
					'messageId' => 'msg-drop',
					'status'    => 'dropped',
					'reason'    => 'profile_not_found',
					'duplicate' => false,
					'retryable' => false,
				],
				[
					'index'     => 2,
					'messageId' => 'msg-rej',
					'status'    => 'rejected',
					'reason'    => 'invalid_input',
					'duplicate' => false,
					'retryable' => false,
				],
			]
		);

		$result = $this->client->send_batch( $items );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['attempts'] );
		$this->assertCount( 1, $this->mock_api->requests );
	}

	/**
	 * Network failure retries once with byte-equivalent payload.
	 */
	public function test_network_failure_retries_once_with_same_payload(): void {
		$items                              = [ $this->track_item( 'msg-net' ) ];
		$this->mock_api->queued_responses[] = [
			'success' => false,
			'error'   => 'cURL error 28: Timeout',
		];
		$this->mock_api->queued_responses[] = $this->success_response(
			[
				$this->accepted_result( 0, 'msg-net' ),
			]
		);

		$result = $this->client->send_batch( $items );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['attempts'] );
		$this->assertCount( 2, $this->mock_api->requests );
		$this->assertSame(
			$this->mock_api->requests[0]['body'],
			$this->mock_api->requests[1]['body']
		);
		$this->assertSame( 'msg-net', $this->mock_api->requests[1]['body']['batch'][0]['messageId'] );
	}

	/**
	 * HTTP 503 retries once.
	 */
	public function test_http_503_retries_once(): void {
		$items                              = [ $this->track_item( 'msg-503' ) ];
		$this->mock_api->queued_responses[] = [
			'success'     => false,
			'status_code' => 503,
			'error'       => 'HTTP 503',
		];
		$this->mock_api->queued_responses[] = $this->success_response(
			[
				$this->accepted_result( 0, 'msg-503' ),
			]
		);

		$result = $this->client->send_batch( $items );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['attempts'] );
	}

	/**
	 * Mixed response retries only retryable items in original order.
	 */
	public function test_mixed_response_retries_only_retryable_items(): void {
		$items                              = [
			$this->track_item( 'msg-a' ),
			$this->track_item( 'msg-b' ),
			$this->track_item( 'msg-c' ),
		];
		$this->mock_api->queued_responses[] = $this->success_response(
			[
				$this->accepted_result( 0, 'msg-a' ),
				[
					'index'     => 1,
					'messageId' => 'msg-b',
					'status'    => 'failed',
					'reason'    => 'event_write_failed',
					'duplicate' => false,
					'retryable' => true,
				],
				$this->accepted_result( 2, 'msg-c' ),
			]
		);
		$this->mock_api->queued_responses[] = $this->success_response(
			[
				$this->accepted_result( 0, 'msg-b' ),
			]
		);

		$result = $this->client->send_batch( $items );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['attempts'] );
		$this->assertCount( 2, $this->mock_api->requests );
		$this->assertCount( 1, $this->mock_api->requests[1]['body']['batch'] );
		$this->assertSame( 'msg-b', $this->mock_api->requests[1]['body']['batch'][0]['messageId'] );
		$this->assertSame(
			[ 'msg-a', 'msg-b', 'msg-c' ],
			array_column( $result['results'], 'messageId' )
		);
	}

	/**
	 * Second failure returns without a third attempt.
	 */
	public function test_second_failure_is_terminal(): void {
		$items                              = [ $this->track_item( 'msg-fail' ) ];
		$this->mock_api->queued_responses[] = [
			'success'     => false,
			'status_code' => 503,
			'error'       => 'HTTP 503',
		];
		$this->mock_api->queued_responses[] = [
			'success'     => false,
			'status_code' => 503,
			'error'       => 'HTTP 503',
		];

		$result = $this->client->send_batch( $items );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 2, $result['attempts'] );
		$this->assertCount( 2, $this->mock_api->requests );
		$this->assertSame( 'http_503', $result['transport_error'] );
	}

	/**
	 * Mismatched messageId is a transport failure.
	 */
	public function test_mismatched_message_id_is_transport_failure(): void {
		$items                              = [ $this->track_item( 'msg-expected' ) ];
		$this->mock_api->queued_responses[] = $this->success_response(
			[
				$this->accepted_result( 0, 'msg-wrong' ),
			]
		);
		$this->mock_api->queued_responses[] = $this->success_response(
			[
				$this->accepted_result( 0, 'msg-wrong' ),
			]
		);

		$result = $this->client->send_batch( $items );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'message_id_mismatch', $result['transport_error'] );
		$this->assertSame( 2, $result['attempts'] );
	}

	/**
	 * Reordered index is a transport failure.
	 */
	public function test_reordered_index_is_transport_failure(): void {
		$items                              = [
			$this->track_item( 'msg-0' ),
			$this->track_item( 'msg-1' ),
		];
		$bad                                = $this->success_response(
			[
				$this->accepted_result( 1, 'msg-1' ),
				$this->accepted_result( 0, 'msg-0' ),
			]
		);
		$this->mock_api->queued_responses[] = $bad;
		$this->mock_api->queued_responses[] = $bad;

		$result = $this->client->send_batch( $items );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'reordered_index', $result['transport_error'] );
	}

	/**
	 * Consent snapshot is attached when provided.
	 */
	public function test_consent_snapshot_is_attached(): void {
		$items                              = [ $this->track_item( 'msg-consent' ) ];
		$this->mock_api->queued_responses[] = $this->success_response(
			[
				$this->accepted_result( 0, 'msg-consent' ),
			]
		);

		$this->client->send_batch(
			$items,
			[
				'analytics' => true,
				'marketing' => false,
			]
		);

		$this->assertSame(
			[
				'analytics' => true,
				'marketing' => false,
			],
			$this->mock_api->requests[0]['body']['consent']
		);
	}

	/**
	 * Build a minimal track item.
	 *
	 * @param string $message_id Message ID.
	 * @return array<string, mixed>
	 */
	private function track_item( string $message_id ): array {
		return Segmentflow_Ingest_Event::track(
			'test_event',
			$message_id,
			'test@example.com',
			null,
			[],
			'2026-07-13T12:00:00+00:00',
			'wordpress'
		);
	}

	/**
	 * Build an accepted result row.
	 *
	 * @param int    $index      Result index.
	 * @param string $message_id Message ID.
	 * @return array<string, mixed>
	 */
	private function accepted_result( int $index, string $message_id ): array {
		return [
			'index'     => $index,
			'messageId' => $message_id,
			'status'    => 'accepted',
			'eventId'   => 'evt_' . $index,
			'duplicate' => false,
			'retryable' => false,
		];
	}

	/**
	 * Build a successful batch API response.
	 *
	 * @param array<int, array<string, mixed>> $results Result rows.
	 * @return array{success: bool, status_code: int, data: array<string, mixed>}
	 */
	private function success_response( array $results ): array {
		$summary = [
			'accepted'  => 0,
			'duplicate' => 0,
			'dropped'   => 0,
			'rejected'  => 0,
			'failed'    => 0,
		];
		foreach ( $results as $result ) {
			++$summary[ $result['status'] ];
		}

		return [
			'success'     => true,
			'status_code' => 200,
			'data'        => [
				'success' => true,
				'results' => $results,
				'summary' => $summary,
			],
		];
	}
}
