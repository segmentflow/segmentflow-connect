<?php
/**
 * Tests for the authentication flow.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Auth
 *
 * Tests the Segmentflow authentication flow.
 */
class Test_Auth extends WP_UnitTestCase {

	/**
	 * Test that the connect URL points to the correct dashboard endpoint.
	 */
	public function test_connect_url_for_wordpress(): void {
		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );
		$url     = $auth->get_connect_url();

		// Without WooCommerce, should point to /connect/wordpress.
		if ( ! Segmentflow_Helper::is_woocommerce_active() ) {
			$this->assertStringContainsString( '/connect/wordpress', $url );
			$this->assertStringContainsString( 'site_url=', $url );
		}
	}

	/**
	 * Test that is_connected returns false when no write key exists.
	 */
	public function test_is_connected_returns_false_without_key(): void {
		delete_option( 'segmentflow_write_key' );

		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );

		$this->assertFalse( $auth->is_connected() );
	}

	/**
	 * Test that is_connected returns true when a write key exists.
	 */
	public function test_is_connected_returns_true_with_key(): void {
		update_option( 'segmentflow_write_key', 'test_key_123' );

		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );

		$this->assertTrue( $auth->is_connected() );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
	}

	/**
	 * Test that the connect URL includes the return_url parameter.
	 */
	public function test_connect_url_includes_return_url(): void {
		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );
		$url     = $auth->get_connect_url();

		$this->assertStringContainsString( 'return_url=', $url );
	}

	/**
	 * Test that the connect URL uses /connect/woocommerce when WC is active.
	 */
	public function test_connect_url_for_woocommerce(): void {
		if ( ! Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active in this environment.' );
		}

		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );
		$url     = $auth->get_connect_url();

		$this->assertStringContainsString( '/connect/woocommerce', $url );
		$this->assertStringContainsString( 'store_url=', $url );
	}

	/**
	 * Test that disconnect removes the write key and related options.
	 */
	public function test_disconnect_removes_write_key(): void {
		update_option( 'segmentflow_write_key', 'test_key_123' );
		update_option( 'segmentflow_organization_name', 'Test Org' );
		update_option( 'segmentflow_connected_platform', 'wordpress' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText

		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );
		$auth->disconnect();

		$this->assertEmpty( get_option( 'segmentflow_write_key', '' ) );
		$this->assertEmpty( get_option( 'segmentflow_organization_name', '' ) );
		$this->assertEmpty( get_option( 'segmentflow_connected_platform', '' ) );
	}

	/**
	 * Test that disconnect calls the API before removing local options.
	 *
	 * The API disconnect is best-effort, so we just verify it attempts
	 * the HTTP call before deleting local data.
	 */
	public function test_disconnect_calls_api_before_removing_options(): void {
		update_option( 'segmentflow_write_key', 'test_key_for_api' );
		update_option( 'segmentflow_api_host', 'https://api.test.segmentflow.ai' );

		$api_called_with_key = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$api_called_with_key ) {
				if ( isset( $args['headers']['X-Write-Key'] ) ) {
					$api_called_with_key = $args['headers']['X-Write-Key'];
				}
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( [ 'success' => true ] ),
				];
			},
			10,
			3
		);

		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );
		$auth->disconnect();

		// Verify the API was called with the write key that existed before deletion.
		$this->assertEquals( 'test_key_for_api', $api_called_with_key );

		// Verify local options were removed after the API call.
		$this->assertEmpty( get_option( 'segmentflow_write_key', '' ) );

		// Cleanup.
		delete_option( 'segmentflow_api_host' );
	}

	/**
	 * Test that ajax_save_connection stores write key and organization name.
	 */
	public function test_ajax_save_connection_stores_write_key(): void {
		// Set up an admin user.
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// Set up the nonce and POST data.
		$_POST['nonce']             = wp_create_nonce( 'segmentflow-admin' );
		$_POST['write_key']         = 'wk_test_ajax_key_123';
		$_POST['organization_name'] = 'Test Organization';

		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );

		// wp_send_json_success() calls wp_die(), which throws WPDieException in tests.
		try {
			$auth->ajax_save_connection();
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected — wp_send_json calls wp_die in test context.
		}

		$this->assertEquals( 'wk_test_ajax_key_123', get_option( 'segmentflow_write_key', '' ) );
		$this->assertEquals( 'Test Organization', get_option( 'segmentflow_organization_name', '' ) );
		$this->assertNotEmpty( get_option( 'segmentflow_connected_platform', '' ) );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
		delete_option( 'segmentflow_organization_name' );
		delete_option( 'segmentflow_connected_platform' );
		unset( $_POST['nonce'], $_POST['write_key'], $_POST['organization_name'] );
	}

	/**
	 * Test that ajax_save_connection rejects requests without a valid nonce.
	 */
	public function test_ajax_save_connection_requires_nonce(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$_POST['write_key'] = 'wk_should_not_be_saved';
		// No nonce set.

		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );

		try {
			$auth->ajax_save_connection();
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected — check_ajax_referer calls wp_die on invalid nonce.
		}

		// Write key should NOT have been saved.
		$this->assertEmpty( get_option( 'segmentflow_write_key', '' ) );

		// Cleanup.
		unset( $_POST['write_key'] );
	}

	/**
	 * Test that ajax_save_connection rejects requests from non-admin users.
	 */
	public function test_ajax_save_connection_requires_capability(): void {
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$_POST['nonce']     = wp_create_nonce( 'segmentflow-admin' );
		$_POST['write_key'] = 'wk_should_not_be_saved';

		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );

		try {
			$auth->ajax_save_connection();
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected — wp_send_json_error calls wp_die.
		}

		// Write key should NOT have been saved.
		$this->assertEmpty( get_option( 'segmentflow_write_key', '' ) );

		// Cleanup.
		unset( $_POST['nonce'], $_POST['write_key'] );
	}

	/**
	 * Test that ajax_save_connection rejects requests with an empty write key.
	 */
	public function test_ajax_save_connection_requires_write_key(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$_POST['nonce']     = wp_create_nonce( 'segmentflow-admin' );
		$_POST['write_key'] = '';

		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );

		try {
			$auth->ajax_save_connection();
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected — wp_send_json_error calls wp_die.
		}

		// Write key should NOT have been saved.
		$this->assertEmpty( get_option( 'segmentflow_write_key', '' ) );

		// Cleanup.
		unset( $_POST['nonce'], $_POST['write_key'] );
	}
}
