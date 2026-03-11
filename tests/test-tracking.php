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

	/**
	 * Test that the SDK script has no loading strategy set.
	 *
	 * WordPress silently demotes async/defer to blocking when an inline
	 * after-script is attached (which is always the case for the SDK).
	 * Setting a strategy would be dead code, so we assert it is absent.
	 *
	 * @see https://make.wordpress.org/core/2023/07/14/registering-scripts-with-async-and-defer-attributes-in-wordpress-6-3/
	 */
	public function test_sdk_script_has_no_loading_strategy(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->enqueue_sdk();

		$registered = wp_scripts()->registered[ Segmentflow_Tracking::SDK_HANDLE ] ?? null;
		$this->assertNotNull( $registered, 'SDK script should be registered.' );

		// extra['strategy'] should be absent or empty — WordPress would strip
		// it anyway due to the inline after-script, so we must not set it.
		$strategy = $registered->extra['strategy'] ?? null;
		$this->assertNull( $strategy, 'SDK script must not have a loading strategy set — WordPress strips async/defer when inline after-scripts are present.' );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
	}

	/**
	 * Test that the SDK script is placed in the <head>, not the footer.
	 *
	 * group = 0 means head; group = 1 means footer.
	 */
	public function test_sdk_script_is_in_head(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->enqueue_sdk();

		$registered = wp_scripts()->registered[ Segmentflow_Tracking::SDK_HANDLE ] ?? null;
		$this->assertNotNull( $registered, 'SDK script should be registered.' );

		$group = $registered->extra['group'] ?? 0;
		$this->assertSame( 0, $group, 'SDK script must be placed in <head> (group 0), not the footer.' );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
	}

	/**
	 * Test that the storefront script declares the SDK as a dependency.
	 *
	 * This ensures storefront.iife.js always loads after the SDK, so
	 * window.segmentflow is available when form-tracking listeners attach.
	 */
	public function test_storefront_script_depends_on_sdk(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );

		$script_path = SEGMENTFLOW_PATH . 'assets/js/storefront.iife.js';
		if ( ! file_exists( $script_path ) ) {
			$this->markTestSkipped( 'storefront.iife.js not built.' );
		}

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->enqueue_storefront_assets();

		$registered = wp_scripts()->registered['segmentflow-storefront'] ?? null;
		$this->assertNotNull( $registered, 'Storefront script should be registered.' );
		$this->assertContains(
			Segmentflow_Tracking::SDK_HANDLE,
			$registered->deps,
			'Storefront script must declare the SDK handle as a dependency.'
		);

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
		wp_dequeue_script( 'segmentflow-storefront' );
		wp_deregister_script( 'segmentflow-storefront' );
	}

	/**
	 * Test that the storefront script uses the defer loading strategy.
	 *
	 * Unlike the SDK (which has an inline after-script and therefore cannot
	 * use defer/async), the storefront script has no inline scripts attached,
	 * so WordPress will honour the defer strategy and emit it on the tag.
	 */
	public function test_storefront_script_has_defer_strategy(): void {
		update_option( 'segmentflow_write_key', 'test_key_abc' );

		$script_path = SEGMENTFLOW_PATH . 'assets/js/storefront.iife.js';
		if ( ! file_exists( $script_path ) ) {
			$this->markTestSkipped( 'storefront.iife.js not built.' );
		}

		$options  = new Segmentflow_Options();
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->enqueue_storefront_assets();

		$registered = wp_scripts()->registered['segmentflow-storefront'] ?? null;
		$this->assertNotNull( $registered, 'Storefront script should be registered.' );

		$strategy = $registered->extra['strategy'] ?? null;
		$this->assertSame( 'defer', $strategy, 'Storefront script must use defer strategy.' );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
		wp_dequeue_script( 'segmentflow-storefront' );
		wp_deregister_script( 'segmentflow-storefront' );
	}
}
