<?php
/**
 * Tests for WooCommerce tracking data extraction.
 *
 * Tests the data injection methods added to class-segmentflow-wc-tracking.php
 * and the helper extraction methods in class-segmentflow-wc-helper.php.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_WC_Tracking_Data
 *
 * Tests product, cart, and order data extraction for the window.__sf_wc
 * page data object.
 */
class Test_WC_Tracking_Data extends WP_UnitTestCase {

	/**
	 * Skip if WooCommerce is not active.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active in this environment.' );
		}
	}

	/**
	 * Test that get_product_data returns all required keys.
	 */
	public function test_get_product_data_shape(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( '29.99' );
		$product->set_sku( 'TEST-SKU-001' );
		$product->save();

		$data = Segmentflow_WC_Helper::get_product_data( $product );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'name', $data );
		$this->assertArrayHasKey( 'price', $data );
		$this->assertArrayHasKey( 'sku', $data );
		$this->assertArrayHasKey( 'categories', $data );
		$this->assertArrayHasKey( 'image_url', $data );
		$this->assertArrayHasKey( 'url', $data );
		$this->assertArrayHasKey( 'type', $data );

		$this->assertEquals( 'Test Product', $data['name'] );
		$this->assertEquals( '29.99', $data['price'] );
		$this->assertEquals( 'TEST-SKU-001', $data['sku'] );
		$this->assertEquals( 'simple', $data['type'] );
		$this->assertIsArray( $data['categories'] );

		$product->delete( true );
	}

	/**
	 * Test that get_product_data includes product categories.
	 */
	public function test_get_product_data_with_categories(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Categorized Product' );
		$product->set_regular_price( '19.99' );
		$product->save();

		// Assign a product category.
		$term = wp_insert_term( 'Test Category', 'product_cat' );
		if ( ! is_wp_error( $term ) ) {
			wp_set_object_terms( $product->get_id(), $term['term_id'], 'product_cat' );
		}

		$data = Segmentflow_WC_Helper::get_product_data( $product );

		$this->assertIsArray( $data['categories'] );
		if ( ! is_wp_error( $term ) ) {
			$this->assertContains( 'Test Category', $data['categories'] );
		}

		$product->delete( true );
		if ( ! is_wp_error( $term ) ) {
			wp_delete_term( $term['term_id'], 'product_cat' );
		}
	}

	/**
	 * Test that get_cart_data returns null when cart is empty.
	 */
	public function test_get_cart_data_null_empty(): void {
		// Ensure the cart is initialized and empty.
		WC()->cart->empty_cart();

		$data = Segmentflow_WC_Helper::get_cart_data();

		$this->assertNull( $data );
	}

	/**
	 * Test that get_cart_data returns correct shape with items.
	 */
	public function test_get_cart_data_shape(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Cart Product' );
		$product->set_regular_price( '15.00' );
		$product->set_sku( 'CART-001' );
		$product->save();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 2 );

		$data = Segmentflow_WC_Helper::get_cart_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'subtotal', $data );
		$this->assertArrayHasKey( 'item_count', $data );
		$this->assertArrayHasKey( 'cart_hash', $data );

		$this->assertCount( 1, $data['items'] );
		$this->assertEquals( 2, $data['item_count'] );

		$item = $data['items'][0];
		$this->assertArrayHasKey( 'product_id', $item );
		$this->assertArrayHasKey( 'variation_id', $item );
		$this->assertArrayHasKey( 'name', $item );
		$this->assertArrayHasKey( 'quantity', $item );
		$this->assertArrayHasKey( 'price', $item );
		$this->assertArrayHasKey( 'sku', $item );
		$this->assertArrayHasKey( 'image_url', $item );
		$this->assertArrayHasKey( 'url', $item );

		$this->assertEquals( $product->get_id(), $item['product_id'] );
		$this->assertEquals( 'Cart Product', $item['name'] );
		$this->assertEquals( 2, $item['quantity'] );
		$this->assertEquals( 'CART-001', $item['sku'] );

		WC()->cart->empty_cart();
		$product->delete( true );
	}

	/**
	 * Test that get_order_data returns all required keys.
	 */
	public function test_get_order_data_shape(): void {
		$order   = wc_create_order();
		$product = new WC_Product_Simple();
		$product->set_name( 'Order Product' );
		$product->set_regular_price( '50.00' );
		$product->set_sku( 'ORD-001' );
		$product->save();

		$order->add_product( $product, 1 );
		$order->set_currency( 'USD' );
		$order->set_payment_method_title( 'Cash on delivery' );
		$order->calculate_totals();
		$order->save();

		$data = Segmentflow_WC_Helper::get_order_data( $order );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'number', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'subtotal', $data );
		$this->assertArrayHasKey( 'tax', $data );
		$this->assertArrayHasKey( 'shipping', $data );
		$this->assertArrayHasKey( 'discount', $data );
		$this->assertArrayHasKey( 'payment_method', $data );
		$this->assertArrayHasKey( 'currency', $data );
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'coupon_codes', $data );
		$this->assertArrayHasKey( 'already_tracked', $data );

		$this->assertEquals( $order->get_id(), $data['id'] );
		$this->assertEquals( 'USD', $data['currency'] );
		$this->assertEquals( 'Cash on delivery', $data['payment_method'] );
		$this->assertIsArray( $data['items'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertIsArray( $data['coupon_codes'] );

		$item = $data['items'][0];
		$this->assertArrayHasKey( 'product_id', $item );
		$this->assertArrayHasKey( 'name', $item );
		$this->assertArrayHasKey( 'quantity', $item );
		$this->assertArrayHasKey( 'price', $item );
		$this->assertArrayHasKey( 'sku', $item );

		$order->delete( true );
		$product->delete( true );
	}

	/**
	 * Test the already_tracked deduplication flag.
	 *
	 * First call should return already_tracked: false and set the meta.
	 * Second call should return already_tracked: true.
	 */
	public function test_get_order_data_dedup_flag(): void {
		$order = wc_create_order();
		$order->save();

		// First call: not yet tracked.
		$data_first = Segmentflow_WC_Helper::get_order_data( $order );
		$this->assertFalse( $data_first['already_tracked'] );

		// Second call: should now be marked as tracked.
		// Re-read the order to pick up the saved meta.
		$order       = wc_get_order( $order->get_id() );
		$data_second = Segmentflow_WC_Helper::get_order_data( $order );
		$this->assertTrue( $data_second['already_tracked'] );

		$order->delete( true );
	}

	/**
	 * Test that inject_page_data renders a script tag with valid JSON.
	 *
	 * Uses output buffering to capture the rendered output.
	 * On a non-WooCommerce page, it should still render with page type "other".
	 */
	public function test_inject_page_data_renders_script(): void {
		$options     = new Segmentflow_Options();
		$tracking    = new Segmentflow_Tracking( $options );
		$wc_tracking = new Segmentflow_WC_Tracking( $options, $tracking );

		ob_start();
		$wc_tracking->inject_page_data();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<script>window.__sf_wc = ', $output );
		$this->assertStringContainsString( '</script>', $output );

		// Extract the JSON from the script tag.
		$json_str = preg_replace(
			'/^<script>window\.__sf_wc = (.+?);<\/script>\s*$/',
			'$1',
			trim( $output )
		);
		$data     = json_decode( $json_str, true );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'page', $data );
		$this->assertArrayHasKey( 'currency', $data );
	}

	/**
	 * Test that get_currency returns a valid currency code.
	 */
	public function test_get_currency_returns_string(): void {
		$currency = Segmentflow_WC_Helper::get_currency();

		$this->assertIsString( $currency );
		$this->assertNotEmpty( $currency );
		// Default WooCommerce currency is typically USD or GBP.
		$this->assertMatchesRegularExpression( '/^[A-Z]{3}$/', $currency );
	}
}
