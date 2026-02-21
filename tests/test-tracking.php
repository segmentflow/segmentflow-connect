<?php
/**
 * Tests for storefront tracking SDK injection.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Tracking
 *
 * Tests the Segmentflow SDK injection into the storefront.
 */
class Test_Tracking extends WP_UnitTestCase {

	/**
	 * Test that SDK is not injected when no write key is set.
	 */
	public function test_sdk_not_injected_without_write_key(): void {
		delete_option( 'segmentflow_write_key' );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );

		ob_start();
		$tracking->inject_sdk();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test that SDK is injected when a write key is configured.
	 */
	public function test_sdk_injected_with_write_key(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );

		ob_start();
		$tracking->inject_sdk();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Segmentflow Connect', $output );
		$this->assertStringContainsString( 'cdn.segmentflow.ai/sdk.js', $output );
		$this->assertStringContainsString( 'test_key_abc', $output );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
	}

	/**
	 * Test that SDK uses wp_ prefix when WooCommerce is not active.
	 */
	public function test_sdk_uses_wp_prefix_without_woocommerce(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );

		ob_start();
		$tracking->inject_sdk();
		$output = ob_get_clean();

		if ( ! Segmentflow_Helper::is_woocommerce_active() ) {
			$this->assertStringContainsString( 'wp_', $output );
			$this->assertStringNotContainsString( 'wc_', $output );
		}

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
	}

	/**
	 * Test that SDK includes locale in WordPress context.
	 */
	public function test_sdk_includes_locale(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );

		ob_start();
		$tracking->inject_sdk();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'locale', $output );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
	}
}
