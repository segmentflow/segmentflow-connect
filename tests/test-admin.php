<?php
/**
 * Tests for the admin settings page.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Admin
 *
 * Tests the Segmentflow admin page and dynamic tabs.
 */
class Test_Admin extends WP_UnitTestCase {

	/**
	 * Test that the Segmentflow menu page is registered.
	 */
	public function test_menu_page_registered(): void {
		$options = new Segmentflow_Options();
		$admin   = new Segmentflow_Admin( $options );
		$admin->register_hooks();

		// Trigger admin_menu to register the page.
		do_action( 'admin_menu' );

		// Check that the menu page exists.
		$this->assertNotEmpty( menu_page_url( 'segmentflow', false ) );
	}

	/**
	 * Test that Connection tab is always present.
	 */
	public function test_connection_tab_always_present(): void {
		$options = new Segmentflow_Options();
		$admin   = new Segmentflow_Admin( $options );
		$tabs    = $admin->get_tabs();

		$this->assertArrayHasKey( 'connection', $tabs );
	}

	/**
	 * Test that Settings tab only appears when connected.
	 */
	public function test_settings_tab_requires_connection(): void {
		$options = new Segmentflow_Options();

		// Not connected -- no settings tab.
		$admin = new Segmentflow_Admin( $options );
		$tabs  = $admin->get_tabs();
		$this->assertArrayNotHasKey( 'settings', $tabs );

		// Connected -- settings tab appears.
		$options->set( 'write_key', 'test_key_123' );
		$tabs = $admin->get_tabs();
		$this->assertArrayHasKey( 'settings', $tabs );

		// Cleanup.
		$options->delete( 'write_key' );
	}
}
