<?php
/**
 * Tests for the WooCommerce auto-auth flow.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_WC_Auth
 *
 * Tests the WooCommerce auto-auth URL generation and store metadata.
 */
class Test_WC_Auth extends WP_UnitTestCase {

	/**
	 * Test that the WC auto-auth URL is correctly generated.
	 */
	public function test_build_auth_url(): void {
		if ( ! class_exists( 'Segmentflow_WC_Auth' ) ) {
			$this->markTestSkipped( 'WooCommerce integration classes not loaded.' );
		}

		$url = Segmentflow_WC_Auth::build_auth_url(
			'https://mystore.com',
			'https://api.cloud.segmentflow.ai/callback',
			'org_123',
			'https://mystore.com/wp-admin'
		);

		$this->assertStringContainsString( 'wc-auth/v1/authorize', $url );
		$this->assertStringContainsString( 'app_name=Segmentflow', $url );
		$this->assertStringContainsString( 'scope=read_write', $url );
		$this->assertStringContainsString( 'user_id=org_123', $url );
	}

	/**
	 * Test that store metadata is returned correctly.
	 */
	public function test_get_store_metadata(): void {
		if ( ! class_exists( 'Segmentflow_WC_Auth' ) ) {
			$this->markTestSkipped( 'WooCommerce integration classes not loaded.' );
		}

		$metadata = Segmentflow_WC_Auth::get_store_metadata();

		$this->assertArrayHasKey( 'store_url', $metadata );
		$this->assertArrayHasKey( 'store_name', $metadata );
		$this->assertArrayHasKey( 'wp_version', $metadata );
		$this->assertNotEmpty( $metadata['store_url'] );
	}
}
