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
	 * Test that inject_page_data attaches an inline script with valid JSON to the SDK handle.
	 *
	 * Enqueues the SDK first (required for wp_add_inline_script to attach),
	 * then calls inject_page_data() and inspects the script queue.
	 */
	public function test_inject_page_data_renders_script(): void {
		update_option( 'segmentflow_write_key', 'test_key_wc' );

		$options     = new Segmentflow_Options();
		$tracking    = new Segmentflow_Tracking( $options );
		$wc_tracking = new Segmentflow_WC_Tracking( $options, $tracking );

		// Enqueue the SDK handle first so wp_script_is() returns true.
		$tracking->enqueue_sdk();

		// Inject page data -- attaches inline script to the SDK handle.
		$wc_tracking->inject_page_data();

		// Retrieve the 'before' inline script attached to the SDK handle.
		$before_data = wp_scripts()->get_data( Segmentflow_Tracking::SDK_HANDLE, 'before' );
		$inline      = is_array( $before_data ) ? implode( "\n", $before_data ) : '';

		$this->assertStringContainsString( 'window.__sf_wc = ', $inline );

		// Extract and validate the JSON.
		$json_str = preg_replace(
			'/^window\.__sf_wc = (.+?);$/',
			'$1',
			trim( $inline )
		);
		$data     = json_decode( $json_str, true );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'page', $data );
		$this->assertArrayHasKey( 'currency', $data );

		// Cleanup.
		delete_option( 'segmentflow_write_key' );
		wp_dequeue_script( Segmentflow_Tracking::SDK_HANDLE );
		wp_deregister_script( Segmentflow_Tracking::SDK_HANDLE );
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
