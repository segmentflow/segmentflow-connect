<?php
/**
 * Tests for the Segmentflow API client.
 *
 * Uses the `pre_http_request` filter to intercept HTTP calls and verify
 * that the API client sends the correct endpoints, methods, and headers.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_API
 *
 * Tests the Segmentflow_API class.
 */
class Test_API extends WP_UnitTestCase {

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * API instance.
	 *
	 * @var Segmentflow_API
	 */
	private Segmentflow_API $api;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->options = new Segmentflow_Options();
		$this->api     = new Segmentflow_API( $this->options );

		// Set a known API host for predictable URLs.
		update_option( 'segmentflow_api_host', 'https://api.test.segmentflow.ai' );
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		delete_option( 'segmentflow_api_host' );
		delete_option( 'segmentflow_write_key' );
		parent::tear_down();
	}

	// =========================================================================
	// check_status
	// =========================================================================

	/**
	 * Test that check_status sends GET to the correct endpoint with poll token header.
	 */
	public function test_check_status_sends_correct_request(): void {
		$captured_args = null;
		$captured_url  = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args, &$captured_url ) {
				$captured_args = $args;
				$captured_url  = $url;

				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'connected'         => true,
							'write_key'         => 'sf_test_key',
							'organization_name' => 'Test Org',
						]
					),
				];
			},
			10,
			3
		);

		$result = $this->api->check_status( 'poll-token-123' );

		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( '/api/v1/integrations/connect/status', $captured_url );
		$this->assertEquals( 'https://api.test.segmentflow.ai/api/v1/integrations/connect/status', $captured_url );
		$this->assertEquals( 'GET', $captured_args['method'] );
		$this->assertArrayHasKey( 'X-Poll-Token', $captured_args['headers'] );
		$this->assertEquals( 'poll-token-123', $captured_args['headers']['X-Poll-Token'] );

		// Verify response is parsed correctly.
		$this->assertTrue( $result['connected'] );
		$this->assertEquals( 'sf_test_key', $result['write_key'] );
		$this->assertEquals( 'Test Org', $result['organization_name'] );
	}

	/**
	 * Test that check_status returns connected=false on HTTP error.
	 */
	public function test_check_status_handles_http_error(): void {
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Connection timed out' );
			}
		);

		$result = $this->api->check_status( 'any-token' );

		$this->assertFalse( $result['connected'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Test that check_status returns connected=false on non-200 status.
	 */
	public function test_check_status_handles_non_200(): void {
		add_filter(
			'pre_http_request',
			function () {
				return [
					'response' => [ 'code' => 404 ],
					'body'     => wp_json_encode( [ 'error' => 'Not found' ] ),
				];
			}
		);

		$result = $this->api->check_status( 'expired-token' );

		$this->assertFalse( $result['connected'] );
	}

	/**
	 * Test that check_status sends correct Content-Type and Accept headers.
	 */
	public function test_check_status_sends_json_headers(): void {
		$captured_args = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [ 'connected' => false ] ),
				];
			},
			10,
			3
		);

		$this->api->check_status( 'token' );

		$this->assertEquals( 'application/json', $captured_args['headers']['Content-Type'] );
		$this->assertEquals( 'application/json', $captured_args['headers']['Accept'] );
	}

	// =========================================================================
	// disconnect
	// =========================================================================

	/**
	 * Test that disconnect sends DELETE with X-Write-Key header.
	 */
	public function test_disconnect_sends_correct_request(): void {
		update_option( 'segmentflow_write_key', 'sf_my_write_key' );

		$captured_args = null;
		$captured_url  = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_args, &$captured_url ) {
				$captured_args = $args;
				$captured_url  = $url;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [ 'success' => true ] ),
				];
			},
			10,
			3
		);

		$result = $this->api->disconnect();

		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( '/api/v1/integrations/connect/disconnect', $captured_url );
		$this->assertEquals( 'DELETE', $captured_args['method'] );
		$this->assertArrayHasKey( 'X-Write-Key', $captured_args['headers'] );
		$this->assertEquals( 'sf_my_write_key', $captured_args['headers']['X-Write-Key'] );
		$this->assertTrue( $result );
	}

	/**
	 * Test that disconnect returns true when no write key exists (nothing to do).
	 */
	public function test_disconnect_returns_true_without_write_key(): void {
		delete_option( 'segmentflow_write_key' );

		$http_called = false;
		add_filter(
			'pre_http_request',
			function () use ( &$http_called ) {
				$http_called = true;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [ 'success' => true ] ),
				];
			}
		);

		$result = $this->api->disconnect();

		$this->assertTrue( $result );
		$this->assertFalse( $http_called, 'No HTTP request should be made when write key is empty' );
	}

	/**
	 * Test that disconnect returns false on API error.
	 */
	public function test_disconnect_handles_api_error(): void {
		update_option( 'segmentflow_write_key', 'sf_some_key' );

		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Network error' );
			}
		);

		$result = $this->api->disconnect();

		$this->assertFalse( $result );
	}

	/**
	 * Test that disconnect includes User-Agent header.
	 */
	public function test_disconnect_includes_user_agent(): void {
		update_option( 'segmentflow_write_key', 'sf_ua_key' );

		$captured_args = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [ 'success' => true ] ),
				];
			},
			10,
			3
		);

		$this->api->disconnect();

		$this->assertArrayHasKey( 'User-Agent', $captured_args['headers'] );
		$this->assertStringContainsString( 'SegmentflowConnect/', $captured_args['headers']['User-Agent'] );
	}
}
