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
	 * Test that disconnect removes the write key and related options.
	 */
	public function test_disconnect_removes_write_key(): void {
		update_option( 'segmentflow_write_key', 'test_key_123' );
		update_option( 'segmentflow_organization_name', 'Test Org' );
		update_option( 'segmentflow_connected_platform', 'wordpress' );

		$options = new Segmentflow_Options();
		$auth    = new Segmentflow_Auth( $options );
		$auth->disconnect();

		$this->assertEmpty( get_option( 'segmentflow_write_key', '' ) );
		$this->assertEmpty( get_option( 'segmentflow_organization_name', '' ) );
		$this->assertEmpty( get_option( 'segmentflow_connected_platform', '' ) );
	}
}
