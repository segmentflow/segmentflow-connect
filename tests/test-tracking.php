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
	 * Dequeue and deregister the SDK script between tests.
	 */
	public function tearDown(): void {
		wp_dequeue_script( Segmentflow_Tracking::SDK_HANDLE );
		wp_deregister_script( Segmentflow_Tracking::SDK_HANDLE );
		parent::tearDown();
	}

	/**
	 * Helper: capture the inline script attached to the SDK handle.
	 *
	 * wp_scripts()->get_data() returns the 'after' inline scripts as an array
	 * of strings; we join them for assertion.
	 *
	 * @return string The combined inline script content, or empty string.
	 */
	private function get_sdk_inline_script(): string {
		$data = wp_scripts()->get_data( Segmentflow_Tracking::SDK_HANDLE, 'after' );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return '';
		}
		return implode( "\n", $data );
	}

	/**
	 * Test that SDK is not enqueued when no write key is set.
	 */
	public function test_sdk_not_injected_without_write_key(): void {
		delete_option( 'segmentflow_write_key' );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->enqueue_sdk();

		$this->assertFalse( wp_script_is( Segmentflow_Tracking::SDK_HANDLE, 'enqueued' ) );
	}

	/**
	 * Test that SDK is enqueued when a write key is configured.
	 */
	public function test_sdk_injected_with_write_key(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->enqueue_sdk();

		$this->assertTrue( wp_script_is( Segmentflow_Tracking::SDK_HANDLE, 'enqueued' ) );

		$inline = $this->get_sdk_inline_script();
		$this->assertStringContainsString( 'Segmentflow Connect', $inline );
		$this->assertStringContainsString( 'cdn.cloud.segmentflow.ai/sdk.js', wp_scripts()->registered[ Segmentflow_Tracking::SDK_HANDLE ]->src );
		$this->assertStringContainsString( 'test_key_abc', $inline );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
	}

	/**
	 * Test that SDK uses wp_ prefix when WooCommerce is not active and user is logged in.
	 */
	public function test_sdk_uses_wp_prefix_without_woocommerce(): void {
		if ( Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is active in this environment.' );
		}

		update_option( 'segmentflow_write_key', 'test_key_abc' );

		// Log in a user so the prefix is included in the inline script.
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->enqueue_sdk();

		$inline = $this->get_sdk_inline_script();
		$this->assertStringContainsString( '"wp_' . $user_id . '"', $inline );
		$this->assertStringNotContainsString( '"wc_', $inline );

		// Cleanup.
		wp_set_current_user( 0 );
		delete_option( 'segmentflow_write_key' );
	}

	/**
	 * Test that SDK includes locale in WordPress context.
	 */
	public function test_sdk_includes_locale(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->enqueue_sdk();

		$inline = $this->get_sdk_inline_script();
		$this->assertStringContainsString( 'locale', $inline );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
	}

	/**
	 * Test that SDK output includes consentRequired when enabled.
	 */
	public function test_sdk_includes_consent_required_when_enabled(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );
		update_option( 'segmentflow_consent_required', true );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->enqueue_sdk();

		$inline = $this->get_sdk_inline_script();
		$this->assertStringContainsString( 'consentRequired', $inline );
		$this->assertStringContainsString( 'consentRequired: true', $inline );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
		delete_option( 'segmentflow_consent_required' );
	}

	/**
	 * Test that SDK output includes debug mode when enabled.
	 */
	public function test_sdk_includes_debug_mode_when_enabled(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );
		update_option( 'segmentflow_debug_mode', true );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->enqueue_sdk();

		$inline = $this->get_sdk_inline_script();
		$this->assertStringContainsString( 'debug: true', $inline );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
		delete_option( 'segmentflow_debug_mode' );
	}
}
