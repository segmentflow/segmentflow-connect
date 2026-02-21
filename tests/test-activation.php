<?php
/**
 * Tests for plugin activation and deactivation.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Activation
 *
 * Tests plugin activation requirements and lifecycle hooks.
 */
class Test_Activation extends WP_UnitTestCase {

	/**
	 * Test that the plugin constants are defined after activation.
	 */
	public function test_plugin_constants_defined(): void {
		$this->assertTrue( defined( 'SEGMENTFLOW_VERSION' ) );
		$this->assertTrue( defined( 'SEGMENTFLOW_PATH' ) );
		$this->assertTrue( defined( 'SEGMENTFLOW_URL' ) );
		$this->assertTrue( defined( 'SEGMENTFLOW_BASENAME' ) );
	}

	/**
	 * Test that the plugin does not require WooCommerce to activate.
	 */
	public function test_activates_without_woocommerce(): void {
		// The plugin should load without errors even without WooCommerce.
		$this->assertTrue( class_exists( 'Segmentflow' ) );
		$this->assertTrue( class_exists( 'Segmentflow_Options' ) );
		$this->assertTrue( class_exists( 'Segmentflow_Helper' ) );
		$this->assertTrue( class_exists( 'Segmentflow_Tracking' ) );
		$this->assertTrue( class_exists( 'Segmentflow_Auth' ) );
	}

	/**
	 * Test that default options are set on activation.
	 */
	public function test_activation_sets_default_options(): void {
		$lifecycle = new Segmentflow_Lifecycle();
		$lifecycle->activate();

		$this->assertNotFalse( get_option( 'segmentflow_activated_at' ) );
	}

	/**
	 * Test that deactivation does not remove options.
	 */
	public function test_deactivation_preserves_options(): void {
		update_option( 'segmentflow_write_key', 'test_key_123' );

		$lifecycle = new Segmentflow_Lifecycle();
		$lifecycle->deactivate();

		$this->assertEquals( 'test_key_123', get_option( 'segmentflow_write_key' ) );
	}
}
