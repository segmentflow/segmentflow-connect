<?php
/**
 * Tests for WooCommerce server-side events.
 *
 * Tests the Segmentflow_WC_Server_Events class: add_to_cart, remove_from_cart,
 * and checkout identity stitching events.
 *
 * @package Segmentflow_Connect
 */

require_once __DIR__ . '/helpers/class-mock-segmentflow-api.php';

/**
 * Class Test_WC_Server_Events
 *
 * Uses a mock API client to capture payloads sent by the server event hooks,
 * allowing assertion on event structure without making real HTTP requests.
 */
class Test_WC_Server_Events extends WP_UnitTestCase {

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * Mock API instance that captures requests.
	 *
	 * @var Mock_Segmentflow_API
	 */
	private Mock_Segmentflow_API $mock_api;

	/**
	 * The server events instance under test.
	 *
	 * @var Segmentflow_WC_Server_Events
	 */
	private Segmentflow_WC_Server_Events $server_events;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active in this environment.' );
		}

		// Ensure a write key exists so hooks register.
		update_option( 'segmentflow_write_key', 'test-write-key-123' );

		$this->options       = new Segmentflow_Options();
		$this->mock_api      = new Mock_Segmentflow_API( $this->options );
		$this->server_events = new Segmentflow_WC_Server_Events( $this->options, $this->mock_api );

		// Reset cookie state.
		Segmentflow_Identity_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		delete_option( 'segmentflow_write_key' );
		Segmentflow_Identity_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// add_to_cart tests
	// -------------------------------------------------------------------------

	/**
	 * Test on_add_to_cart sends correct event payload.
	 */
	public function test_add_to_cart_sends_track_event(): void {
		$this->set_identity(
			[
				'a' => 'anon-123',
				'u' => 'wc_1',
			]
		);

		$product = $this->create_product( 'Test Widget', '29.99', 'WIDGET-001' );

		$this->server_events->on_add_to_cart( 'cart-key-1', $product->get_id(), 2, 0, [], [] );

		$this->assertCount( 1, $this->mock_api->requests );

		$body  = $this->mock_api->requests[0]['body'];
		$event = $body['batch'][0];

		$this->assertSame( 'test-write-key-123', $body['writeKey'] );
		$this->assertSame( 'track', $event['type'] );
		$this->assertSame( 'add_to_cart', $event['event'] );
		$this->assertSame( 'wc_1', $event['userId'] );
		$this->assertSame( 'anon-123', $event['anonymousId'] );
		$this->assertSame( 'WordPress', $event['source'] );
		$this->assertArrayHasKey( 'timestamp', $event );

		$props = $event['properties'];
		$this->assertSame( $product->get_id(), $props['product_id'] );
		$this->assertSame( 'Test Widget', $props['name'] );
		$this->assertSame( 29.99, $props['price'] );
		$this->assertSame( 'WIDGET-001', $props['sku'] );
		$this->assertSame( 2, $props['quantity'] );
		$this->assertArrayNotHasKey( 'variation_id', $props );

		// Verify non-blocking option was used.
		$this->assertFalse( $this->mock_api->requests[0]['options']['blocking'] );

		$product->delete( true );
	}

	/**
	 * Test on_add_to_cart includes variation_id when present.
	 */
	public function test_add_to_cart_includes_variation_id(): void {
		$this->set_identity( [ 'a' => 'anon-456' ] );

		$product = $this->create_product( 'Variable Widget', '39.99', 'VAR-001' );

		$this->server_events->on_add_to_cart( 'cart-key-2', $product->get_id(), 1, 999, [ 'attribute_color' => 'red' ], [] );

		$this->assertCount( 1, $this->mock_api->requests );

		$props = $this->mock_api->requests[0]['body']['batch'][0]['properties'];
		$this->assertSame( 999, $props['variation_id'] );

		$product->delete( true );
	}

	/**
	 * Test on_add_to_cart skips when cookie has no anonymous ID.
	 */
	public function test_add_to_cart_skips_without_identity(): void {
		// No cookie set — identity is null.
		$product = $this->create_product( 'Ghost Widget', '10.00', 'GHOST-001' );

		$this->server_events->on_add_to_cart( 'cart-key-3', $product->get_id(), 1, 0, [], [] );

		$this->assertCount( 0, $this->mock_api->requests );

		$product->delete( true );
	}

	/**
	 * Test on_add_to_cart skips when product is invalid.
	 */
	public function test_add_to_cart_skips_invalid_product(): void {
		$this->set_identity( [ 'a' => 'anon-789' ] );

		$this->server_events->on_add_to_cart( 'cart-key-4', 999999, 1, 0, [], [] );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	/**
	 * Test on_add_to_cart uses anonymousId as fallback when no userId.
	 */
	public function test_add_to_cart_anonymous_user(): void {
		$this->set_identity( [ 'a' => 'anon-guest' ] );

		$product = $this->create_product( 'Guest Widget', '15.00', 'GUEST-001' );

		$this->server_events->on_add_to_cart( 'cart-key-5', $product->get_id(), 1, 0, [], [] );

		$event = $this->mock_api->requests[0]['body']['batch'][0];
		$this->assertNull( $event['userId'] );
		$this->assertSame( 'anon-guest', $event['anonymousId'] );

		$product->delete( true );
	}

	// -------------------------------------------------------------------------
	// remove_from_cart tests
	// -------------------------------------------------------------------------

	/**
	 * Test on_remove_from_cart sends correct event payload.
	 */
	public function test_remove_from_cart_sends_track_event(): void {
		$this->set_identity(
			[
				'a' => 'anon-rem-1',
				'u' => 'wc_2',
			]
		);

		$product = $this->create_product( 'Remove Widget', '19.99', 'REM-001' );

		// Simulate a removed cart item by adding it to cart then removing.
		WC()->cart->empty_cart();
		$cart_item_key = WC()->cart->add_to_cart( $product->get_id(), 3 );
		WC()->cart->remove_cart_item( $cart_item_key );

		// Now manually call the handler (the hook fires WC()->cart state).
		$this->server_events->on_remove_from_cart( $cart_item_key, WC()->cart );

		$this->assertCount( 1, $this->mock_api->requests );

		$event = $this->mock_api->requests[0]['body']['batch'][0];
		$this->assertSame( 'track', $event['type'] );
		$this->assertSame( 'remove_from_cart', $event['event'] );
		$this->assertSame( 'wc_2', $event['userId'] );
		$this->assertSame( 'anon-rem-1', $event['anonymousId'] );
		$this->assertSame( 'WordPress', $event['source'] );

		$props = $event['properties'];
		$this->assertSame( $product->get_id(), $props['product_id'] );
		$this->assertSame( 'Remove Widget', $props['name'] );
		$this->assertSame( 3, $props['quantity'] );
		$this->assertSame( 19.99, $props['price'] );
		$this->assertSame( 'REM-001', $props['sku'] );

		WC()->cart->empty_cart();
		$product->delete( true );
	}

	/**
	 * Test on_remove_from_cart skips without identity.
	 */
	public function test_remove_from_cart_skips_without_identity(): void {
		$product = $this->create_product( 'Ghost Remove', '5.00', 'GHOST-REM' );

		WC()->cart->empty_cart();
		$cart_item_key = WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->remove_cart_item( $cart_item_key );

		$this->server_events->on_remove_from_cart( $cart_item_key, WC()->cart );

		$this->assertCount( 0, $this->mock_api->requests );

		WC()->cart->empty_cart();
		$product->delete( true );
	}

	/**
	 * Test on_remove_from_cart skips when cart item not found in removed contents.
	 */
	public function test_remove_from_cart_skips_unknown_item(): void {
		$this->set_identity( [ 'a' => 'anon-rem-2' ] );

		WC()->cart->empty_cart();

		$this->server_events->on_remove_from_cart( 'nonexistent-key', WC()->cart );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	// -------------------------------------------------------------------------
	// checkout (identity stitching) tests
	// -------------------------------------------------------------------------

	/**
	 * Test on_checkout sends identify event with billing traits.
	 */
	public function test_checkout_sends_identify_event(): void {
		$this->set_identity( [ 'a' => 'anon-checkout-1' ] );

		$order = wc_create_order();
		$order->set_billing_email( 'customer@example.com' );
		$order->set_billing_phone( '+1234567890' );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Doe' );
		$order->set_customer_id( 42 );
		$order->save();

		$this->server_events->on_checkout( $order->get_id(), [], $order );

		$this->assertCount( 1, $this->mock_api->requests );

		$event = $this->mock_api->requests[0]['body']['batch'][0];
		$this->assertSame( 'identify', $event['type'] );
		$this->assertSame( 'wc_42', $event['userId'] );
		$this->assertSame( 'anon-checkout-1', $event['anonymousId'] );
		$this->assertSame( 'WordPress', $event['source'] );

		$traits = $event['traits'];
		$this->assertSame( 'customer@example.com', $traits['email'] );
		$this->assertSame( '+1234567890', $traits['phone'] );
		$this->assertSame( 'Jane', $traits['first_name'] );
		$this->assertSame( 'Doe', $traits['last_name'] );

		// Verify identity cookie was updated.
		$cookie = Segmentflow_Identity_Cookie::read();
		$this->assertSame( 'customer@example.com', $cookie['e'] );
		$this->assertSame( '+1234567890', $cookie['p'] );
		$this->assertSame( 'wc_42', $cookie['u'] );

		$order->delete( true );
	}

	/**
	 * Test on_checkout handles guest checkout (no customer ID).
	 */
	public function test_checkout_guest_has_no_user_id(): void {
		$this->set_identity( [ 'a' => 'anon-guest-checkout' ] );

		$order = wc_create_order();
		$order->set_billing_email( 'guest@example.com' );
		$order->set_customer_id( 0 ); // Guest checkout.
		$order->save();

		$this->server_events->on_checkout( $order->get_id(), [], $order );

		$event = $this->mock_api->requests[0]['body']['batch'][0];
		$this->assertSame( 'identify', $event['type'] );
		$this->assertArrayNotHasKey( 'userId', $event );
		$this->assertSame( 'anon-guest-checkout', $event['anonymousId'] );

		$order->delete( true );
	}

	/**
	 * Test on_checkout skips without identity.
	 */
	public function test_checkout_skips_without_identity(): void {
		$order = wc_create_order();
		$order->set_billing_email( 'no-cookie@example.com' );
		$order->save();

		$this->server_events->on_checkout( $order->get_id(), [], $order );

		$this->assertCount( 0, $this->mock_api->requests );

		$order->delete( true );
	}

	/**
	 * Test that register_hooks does nothing when not connected.
	 */
	public function test_hooks_not_registered_when_disconnected(): void {
		delete_option( 'segmentflow_write_key' );

		$options       = new Segmentflow_Options();
		$server_events = new Segmentflow_WC_Server_Events( $options, $this->mock_api );
		$server_events->register_hooks();

		// Verify the hook was NOT added by checking action count.
		$this->assertFalse( has_action( 'woocommerce_add_to_cart', [ $server_events, 'on_add_to_cart' ] ) );
	}

	/**
	 * Test that register_hooks registers actions when connected.
	 */
	public function test_hooks_registered_when_connected(): void {
		$server_events = new Segmentflow_WC_Server_Events( $this->options, $this->mock_api );
		$server_events->register_hooks();

		$this->assertSame( 25, has_action( 'woocommerce_add_to_cart', [ $server_events, 'on_add_to_cart' ] ) );
		$this->assertSame( 10, has_action( 'woocommerce_cart_item_removed', [ $server_events, 'on_remove_from_cart' ] ) );
		$this->assertSame( 10, has_action( 'woocommerce_checkout_order_processed', [ $server_events, 'on_checkout' ] ) );
	}

	/**
	 * Test that events are not sent when write key is empty.
	 */
	public function test_send_event_skips_without_write_key(): void {
		delete_option( 'segmentflow_write_key' );
		$this->set_identity( [ 'a' => 'anon-no-key' ] );

		$options       = new Segmentflow_Options();
		$server_events = new Segmentflow_WC_Server_Events( $options, $this->mock_api );

		$product = $this->create_product( 'No Key Widget', '10.00', 'NOKEY-001' );
		$server_events->on_add_to_cart( 'cart-key-nokey', $product->get_id(), 1, 0, [], [] );

		$this->assertCount( 0, $this->mock_api->requests );

		$product->delete( true );
	}

	// -------------------------------------------------------------------------
	// Anonymous userId handling tests
	// -------------------------------------------------------------------------

	/**
	 * Test WC build_track_event omits null userId for anonymous visitors.
	 *
	 * The WC build_track_event had the same bug as the base class: it sent
	 * "userId": null for anonymous visitors. This test verifies the fix.
	 */
	public function test_wc_build_track_event_omits_null_user_id(): void {
		$reflection = new \ReflectionMethod( $this->server_events, 'build_track_event' );

		// Anonymous visitor: identity has 'a' (anonymousId) but no 'u' (userId).
		$identity = [ 'a' => 'anon-wc-track-1' ];
		$result   = $reflection->invoke(
			$this->server_events,
			'add_to_cart',
			$identity,
			[
				'product_id' => 42,
				'quantity'   => 1,
			]
		);

		$this->assertSame( 'track', $result['type'] );
		$this->assertSame( 'add_to_cart', $result['event'] );
		$this->assertSame( 'anon-wc-track-1', $result['anonymousId'] );
		$this->assertArrayNotHasKey( 'userId', $result, 'userId should be omitted when null' );
	}

	/**
	 * Test WC build_track_event includes userId for logged-in WC customers.
	 */
	public function test_wc_build_track_event_includes_user_id_for_customer(): void {
		$reflection = new \ReflectionMethod( $this->server_events, 'build_track_event' );

		$identity = [
			'a' => 'anon-wc-track-2',
			'u' => 'wc_99',
		];
		$result   = $reflection->invoke(
			$this->server_events,
			'add_to_cart',
			$identity,
			[
				'product_id' => 42,
				'quantity'   => 1,
			]
		);

		$this->assertSame( 'wc_99', $result['userId'] );
		$this->assertSame( 'anon-wc-track-2', $result['anonymousId'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Set the sf_id identity cookie for the current test.
	 *
	 * @param array<string, string> $data Identity fields.
	 */
	private function set_identity( array $data ): void {
		Segmentflow_Identity_Cookie::reset_cache();
		Segmentflow_Identity_Cookie::write( $data );
	}

	/**
	 * Create a simple WC product for testing.
	 *
	 * @param string $name  Product name.
	 * @param string $price Product price.
	 * @param string $sku   Product SKU.
	 * @return WC_Product_Simple
	 */
	private function create_product( string $name, string $price, string $sku ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_sku( $sku );
		$product->save();
		return $product;
	}
}
