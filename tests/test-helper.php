<?php
/**
 * Tests for the integration helper.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Helper
 *
 * Tests the Segmentflow_Helper integration detection methods.
 */
class Test_Helper extends WP_UnitTestCase {

	/**
	 * Test that is_woocommerce_active returns a boolean.
	 */
	public function test_is_woocommerce_active_returns_bool(): void {
		$result = Segmentflow_Helper::is_woocommerce_active();
		$this->assertIsBool( $result );
	}

	/**
	 * Test that get_platform returns expected values.
	 */
	public function test_get_platform_returns_valid_value(): void {
		$platform = Segmentflow_Helper::get_platform();
		$this->assertContains( $platform, [ 'wordpress', 'woocommerce' ] );
	}

	/**
	 * Test that get_platform returns the plain platform identifier when WooCommerce is not active.
	 */
	public function test_get_platform_without_woocommerce(): void {
		if ( Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is active in this environment.' );
		}

		$this->assertEquals( 'wordpress', Segmentflow_Helper::get_platform() ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
	}

	/**
	 * Test URL sanitization.
	 */
	public function test_sanitize_url(): void {
		// Adds https:// if missing.
		$this->assertStringStartsWith( 'https://', Segmentflow_Helper::sanitize_url( 'example.com' ) );

		// Removes trailing slash.
		$this->assertStringEndsNotWith( '/', Segmentflow_Helper::sanitize_url( 'https://example.com/' ) );

		// Preserves valid URLs.
		$this->assertEquals( 'https://example.com', Segmentflow_Helper::sanitize_url( 'https://example.com' ) );
	}
}
