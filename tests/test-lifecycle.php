<?php
/**
 * Tests for plugin lifecycle management.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Lifecycle
 *
 * Tests the Segmentflow_Lifecycle class: activation, deactivation,
 * and late dependency detection (WooCommerce installed after plugin).
 */
class Test_Lifecycle extends WP_UnitTestCase {

	/**
	 * Tear down each test.
	 */
	public function tear_down(): void {
		delete_option( 'segmentflow_write_key' );
		delete_option( 'segmentflow_organization_name' );
		delete_option( 'segmentflow_api_host' );
		delete_option( 'segmentflow_app_host' );
		delete_option( 'segmentflow_debug_mode' );
		delete_option( 'segmentflow_consent_required' );
		delete_option( 'segmentflow_connected_platform' );
		delete_option( 'segmentflow_activated_at' );
		delete_transient( 'segmentflow_wc_upgrade_notice' );
		parent::tear_down();
	}

	/**
	 * Test that activate() creates all default options.
	 */
	public function test_activate_creates_default_options(): void {
		$lifecycle = new Segmentflow_Lifecycle();
		$lifecycle->activate();

		// All default option keys should exist (may be empty strings or false).
		$this->assertNotFalse( get_option( 'segmentflow_write_key' ) );
		$this->assertNotFalse( get_option( 'segmentflow_api_host' ) );
		$this->assertNotFalse( get_option( 'segmentflow_app_host' ) );
		$this->assertNotFalse( get_option( 'segmentflow_activated_at' ) );
	}

	/**
	 * Test that activate() does not overwrite existing option values.
	 *
	 * create_defaults() uses add_option() which does NOT overwrite.
	 */
	public function test_activate_does_not_overwrite_existing_options(): void {
		update_option( 'segmentflow_write_key', 'existing_key_123' );

		$lifecycle = new Segmentflow_Lifecycle();
		$lifecycle->activate();

		$this->assertEquals( 'existing_key_123', get_option( 'segmentflow_write_key' ) );
	}

	/**
	 * Test that check_for_dependency() sets a transient when WooCommerce is
	 * activated and the plugin is connected as plain WordPress.
	 */
	public function test_check_for_dependency_sets_transient_for_woocommerce(): void {
		// Simulate being connected as plain WordPress.
		update_option( 'segmentflow_write_key', 'test_key_123' );
		update_option( 'segmentflow_connected_platform', 'wordpress' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText

		$lifecycle = new Segmentflow_Lifecycle();
		$lifecycle->check_for_dependency( 'woocommerce/woocommerce.php', false );

		$this->assertTrue( (bool) get_transient( 'segmentflow_wc_upgrade_notice' ) );
	}

	/**
	 * Test that check_for_dependency() ignores unrelated plugins.
	 */
	public function test_check_for_dependency_ignores_unrelated_plugins(): void {
		update_option( 'segmentflow_write_key', 'test_key_123' );
		update_option( 'segmentflow_connected_platform', 'wordpress' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText

		$lifecycle = new Segmentflow_Lifecycle();
		$lifecycle->check_for_dependency( 'some-plugin/some-plugin.php', false );

		$this->assertFalse( get_transient( 'segmentflow_wc_upgrade_notice' ) );
	}

	/**
	 * Test that check_for_dependency() does nothing when not connected.
	 */
	public function test_check_for_dependency_ignores_when_not_connected(): void {
		delete_option( 'segmentflow_write_key' );

		$lifecycle = new Segmentflow_Lifecycle();
		$lifecycle->check_for_dependency( 'woocommerce/woocommerce.php', false );

		$this->assertFalse( get_transient( 'segmentflow_wc_upgrade_notice' ) );
	}

	/**
	 * Test that check_for_dependency() does nothing when already connected
	 * as WooCommerce (no need to upgrade).
	 */
	public function test_check_for_dependency_ignores_when_connected_as_woocommerce(): void {
		update_option( 'segmentflow_write_key', 'test_key_123' );
		update_option( 'segmentflow_connected_platform', 'woocommerce' );

		$lifecycle = new Segmentflow_Lifecycle();
		$lifecycle->check_for_dependency( 'woocommerce/woocommerce.php', false );

		$this->assertFalse( get_transient( 'segmentflow_wc_upgrade_notice' ) );
	}
}
