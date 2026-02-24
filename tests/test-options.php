<?php
/**
 * Tests for the options manager.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Options
 *
 * Tests the Segmentflow_Options class: get, set, delete, normalization,
 * defaults management, and convenience getters.
 */
class Test_Options extends WP_UnitTestCase {

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * Set up each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->options = new Segmentflow_Options();
	}

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
		parent::tear_down();
	}

	/**
	 * Test that get() returns the fallback default when option is not set.
	 */
	public function test_get_returns_default_when_not_set(): void {
		delete_option( 'segmentflow_write_key' );
		$this->assertSame( '', $this->options->get( 'write_key' ) );
	}

	/**
	 * Test that set() stores a value with the segmentflow_ prefix.
	 */
	public function test_set_stores_value_with_prefix(): void {
		$this->options->set( 'write_key', 'abc123' );
		$this->assertEquals( 'abc123', get_option( 'segmentflow_write_key' ) );
	}

	/**
	 * Test that delete() removes the prefixed option.
	 */
	public function test_delete_removes_prefixed_option(): void {
		update_option( 'segmentflow_write_key', 'abc123' );
		$this->options->delete( 'write_key' );
		$this->assertFalse( get_option( 'segmentflow_write_key', false ) );
	}

	/**
	 * Test that keys without the prefix are normalized correctly.
	 */
	public function test_normalize_key_adds_prefix(): void {
		$this->options->set( 'write_key', 'via_short_key' );
		$this->assertEquals( 'via_short_key', get_option( 'segmentflow_write_key' ) );
	}

	/**
	 * Test that keys already including the prefix are not double-prefixed.
	 */
	public function test_normalize_key_does_not_double_prefix(): void {
		$this->options->set( 'segmentflow_write_key', 'via_full_key' );

		// Should be stored under 'segmentflow_write_key', not 'segmentflow_segmentflow_write_key'.
		$this->assertEquals( 'via_full_key', get_option( 'segmentflow_write_key' ) );
		$this->assertFalse( get_option( 'segmentflow_segmentflow_write_key', false ) );
	}

	/**
	 * Test that is_connected() returns true when a write key exists.
	 */
	public function test_is_connected_true_with_write_key(): void {
		$this->options->set( 'write_key', 'test_key' );
		$this->assertTrue( $this->options->is_connected() );
	}

	/**
	 * Test that is_connected() returns false when no write key exists.
	 */
	public function test_is_connected_false_without_write_key(): void {
		delete_option( 'segmentflow_write_key' );
		$this->assertFalse( $this->options->is_connected() );
	}

	/**
	 * Test that create_defaults() uses add_option() which does NOT overwrite.
	 */
	public function test_create_defaults_does_not_overwrite_existing(): void {
		update_option( 'segmentflow_api_host', 'https://custom.api.host' );
		$this->options->create_defaults();
		$this->assertEquals( 'https://custom.api.host', get_option( 'segmentflow_api_host' ) );
	}

	/**
	 * Test that delete_all() removes all segmentflow options.
	 */
	public function test_delete_all_removes_all_options(): void {
		$this->options->set( 'write_key', 'key' );
		$this->options->set( 'organization_name', 'org' );
		$this->options->set( 'api_host', 'https://api.example.com' );

		Segmentflow_Options::delete_all();

		$this->assertFalse( get_option( 'segmentflow_write_key', false ) );
		$this->assertFalse( get_option( 'segmentflow_organization_name', false ) );
		$this->assertFalse( get_option( 'segmentflow_api_host', false ) );
	}

	/**
	 * Test that get_app_host() returns the stored value.
	 */
	public function test_get_app_host_returns_stored_value(): void {
		update_option( 'segmentflow_app_host', 'https://custom.dashboard.host' );
		$this->assertEquals( 'https://custom.dashboard.host', $this->options->get_app_host() );
	}

	/**
	 * Test that get_connected_platform() returns the stored value.
	 */
	public function test_get_connected_platform_returns_stored_value(): void {
		$this->options->set( 'connected_platform', 'woocommerce' );
		$this->assertEquals( 'woocommerce', $this->options->get_connected_platform() );
	}
}
