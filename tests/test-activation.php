<?php
/**
 * Tests for plugin activation and deactivation.
 *
 * @package Segmentflow_WooCommerce
 */

/**
 * Class Test_Activation
 *
 * Tests plugin activation requirements and hooks.
 */
class Test_Activation extends WP_UnitTestCase {

	/**
	 * Test that the plugin constants are defined after activation.
	 */
	public function test_plugin_constants_defined(): void {
		$this->assertTrue( defined( 'SEGMENTFLOW_WC_VERSION' ) );
		$this->assertTrue( defined( 'SEGMENTFLOW_WC_PLUGIN_FILE' ) );
		$this->assertTrue( defined( 'SEGMENTFLOW_WC_PLUGIN_DIR' ) );
		$this->assertTrue( defined( 'SEGMENTFLOW_WC_PLUGIN_URL' ) );
		$this->assertTrue( defined( 'SEGMENTFLOW_WC_PLUGIN_BASENAME' ) );
	}

	/**
	 * Test that default options are set on activation.
	 */
	public function test_activation_sets_default_options(): void {
		// TODO: Implement once activation logic is testable.
		$this->markTestIncomplete( 'Activation test not yet implemented.' );
	}

	/**
	 * Test that deactivation does not remove options.
	 */
	public function test_deactivation_preserves_options(): void {
		// TODO: Implement once deactivation logic is testable.
		$this->markTestIncomplete( 'Deactivation test not yet implemented.' );
	}
}
