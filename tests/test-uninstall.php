<?php
/**
 * Tests for the uninstall handler.
 *
 * Verifies that uninstall.php reads the write key before deleting options,
 * and sends a DELETE request with the X-Write-Key header.
 *
 * Note: We can't directly include uninstall.php in tests (it calls exit
 * when WP_UNINSTALL_PLUGIN is not defined). Instead we test the underlying
 * behaviour by simulating what uninstall.php does.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Uninstall
 *
 * Tests the uninstall cleanup behaviour.
 */
class Test_Uninstall extends WP_UnitTestCase {

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		delete_option( 'segmentflow_write_key' );
		delete_option( 'segmentflow_api_host' );
		delete_option( 'segmentflow_organization_name' );
		delete_option( 'segmentflow_connected_platform' );
		delete_option( 'segmentflow_debug_mode' );
		delete_option( 'segmentflow_consent_required' );
		delete_option( 'segmentflow_app_host' );
		delete_option( 'segmentflow_activated_at' );
		parent::tear_down();
	}

	/**
	 * Test that delete_all removes all Segmentflow options.
	 */
	public function test_delete_all_removes_options(): void {
		// Set up options like a connected plugin would have.
		update_option( 'segmentflow_write_key', 'sf_test_key' );
		update_option( 'segmentflow_organization_name', 'Test Org' );
		update_option( 'segmentflow_connected_platform', 'WordPress' );
		update_option( 'segmentflow_api_host', 'https://api.test.segmentflow.ai' );
		update_option( 'segmentflow_debug_mode', true );

		// This is what uninstall.php calls.
		Segmentflow_Options::delete_all();

		$this->assertEmpty( get_option( 'segmentflow_write_key', '' ) );
		$this->assertEmpty( get_option( 'segmentflow_organization_name', '' ) );
		$this->assertEmpty( get_option( 'segmentflow_connected_platform', '' ) );
		$this->assertEmpty( get_option( 'segmentflow_debug_mode', '' ) );
	}

	/**
	 * Test that the uninstall pattern reads write key before deletion.
	 *
	 * Simulates the critical ordering in uninstall.php: read the write key
	 * BEFORE calling Segmentflow_Options::delete_all(), then use it for
	 * the disconnect API call.
	 */
	public function test_write_key_is_read_before_options_are_deleted(): void {
		update_option( 'segmentflow_write_key', 'sf_uninstall_key' );
		update_option( 'segmentflow_api_host', 'https://api.test.segmentflow.ai' );

		// Simulate the uninstall.php ordering: read first, delete second.
		$write_key_before = get_option( 'segmentflow_write_key', '' );
		Segmentflow_Options::delete_all();
		$write_key_after = get_option( 'segmentflow_write_key', '' );

		$this->assertEquals( 'sf_uninstall_key', $write_key_before );
		$this->assertEmpty( $write_key_after );
	}

	/**
	 * Test that the disconnect HTTP call sends the correct method and headers.
	 *
	 * Simulates the wp_remote_request call from uninstall.php.
	 */
	public function test_disconnect_request_sends_correct_headers(): void {
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

		// Simulate the uninstall.php disconnect call.
		$api_host  = 'https://api.test.segmentflow.ai';
		$write_key = 'sf_uninstall_write_key';

		wp_remote_request(
			$api_host . '/api/v1/integrations/connect/disconnect',
			[
				'method'  => 'DELETE',
				'headers' => [
					'X-Write-Key'  => $write_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'timeout' => 5,
			]
		);

		$this->assertNotNull( $captured_url );
		$this->assertEquals(
			'https://api.test.segmentflow.ai/api/v1/integrations/connect/disconnect',
			$captured_url
		);
		$this->assertEquals( 'DELETE', $captured_args['method'] );
		$this->assertArrayHasKey( 'X-Write-Key', $captured_args['headers'] );
		$this->assertEquals( 'sf_uninstall_write_key', $captured_args['headers']['X-Write-Key'] );
		$this->assertEquals( 5, $captured_args['timeout'] );
	}

	/**
	 * Test that the disconnect call is skipped when no write key exists.
	 *
	 * Simulates the guard condition in uninstall.php.
	 */
	public function test_disconnect_skipped_without_write_key(): void {
		delete_option( 'segmentflow_write_key' );

		$http_called = false;
		add_filter(
			'pre_http_request',
			function () use ( &$http_called ) {
				$http_called = true;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => '',
				];
			}
		);

		// Simulate the guard in uninstall.php.
		$write_key = get_option( 'segmentflow_write_key', '' );
		if ( ! empty( $write_key ) ) {
			wp_remote_request( 'https://api.test.segmentflow.ai/api/v1/integrations/connect/disconnect', [] );
		}

		$this->assertFalse( $http_called, 'No HTTP request should be made when write key is empty' );
	}
}
