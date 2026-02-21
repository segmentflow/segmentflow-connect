<?php
/**
 * Tests for WooCommerce-specific tracking enrichment.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Tracking_WooCommerce
 *
 * Tests the WooCommerce context enrichment via the segmentflow_tracking_context filter.
 */
class Test_Tracking_WooCommerce extends WP_UnitTestCase {

	/**
	 * Test that WC tracking context is not present when WooCommerce is not active.
	 */
	public function test_no_wc_context_without_woocommerce(): void {
		if ( Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is active in this environment.' );
		}

		$context = apply_filters( 'segmentflow_tracking_context', [] );
		$this->assertEmpty( $context );
	}

	/**
	 * Test that WC tracking adds platform identifier when WooCommerce is active.
	 */
	public function test_wc_context_sets_platform(): void {
		if ( ! Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active in this environment.' );
		}

		// The WC tracking class should be loaded and its filter registered.
		$context = apply_filters( 'segmentflow_tracking_context', [] );
		$this->assertEquals( 'woocommerce', $context['platform'] ?? '' );
	}

	/**
	 * Test that WC tracking adds store context when WooCommerce is active.
	 */
	public function test_wc_context_has_currency(): void {
		if ( ! Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active in this environment.' );
		}

		$context = apply_filters( 'segmentflow_tracking_context', [] );
		$this->assertArrayHasKey( 'context', $context );
		$this->assertArrayHasKey( 'currency', $context['context'] );
	}
}
