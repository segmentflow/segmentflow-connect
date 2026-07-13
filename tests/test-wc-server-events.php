<?php
/**
 * Tests for WooCommerce server-side events.
 *
 * Tests the Segmentflow_WC_Server_Events class: add_to_cart, remove_from_cart,
 * and checkout identity events under the identified-only ingest contract.
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
	 * Identified ingest client.
	 *
	 * @var Segmentflow_Ingest_Client
	 */
	private Segmentflow_Ingest_Client $ingest_client;

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

		update_option( 'segmentflow_write_key', 'test-write-key-123' );

		$this->options       = new Segmentflow_Options();
		$this->mock_api      = new Mock_Segmentflow_API( $this->options );
		$this->ingest_client = new Segmentflow_Ingest_Client( $this->options, $this->mock_api );
		$this->server_events = new Segmentflow_WC_Server_Events( $this->options, $this->ingest_client );

		Segmentflow_Consent_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] );
		unset( $_COOKIE['sf_utm'] );
		wp_set_current_user( 0 );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		delete_option( 'segmentflow_write_key' );
		Segmentflow_Consent_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] );
		unset( $_COOKIE['sf_utm'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// add_to_cart tests
	// -------------------------------------------------------------------------

	/**
	 * Test logged-in add_to_cart sends identified track with top-level email.
	 */
	public function test_add_to_cart_sends_track_event(): void {
		$user_id = $this->login_as_customer( 'cartuser@example.com' );
		$product = $this->create_product( 'Test Widget', '29.99', 'WIDGET-001' );

		$this->server_events->on_add_to_cart( 'cart-key-1', $product->get_id(), 2, 0, [], [] );

		$this->assertCount( 1, $this->mock_api->requests );

		$body  = $this->mock_api->requests[0]['body'];
		$event = $body['batch'][0];

		$this->assertSame( 'test-write-key-123', $body['writeKey'] );
		$this->assertSame( 'track', $event['type'] );
		$this->assertSame( 'add_to_cart', $event['event'] );
		$this->assertSame( 'cartuser@example.com', $event['email'] );
		$this->assertSame( 'wc_' . $user_id, $event['userId'] );
		$this->assertSame( 'woocommerce', $event['source'] );
		$this->assertArrayHasKey( 'timestamp', $event );
		$this->assertStringStartsWith( 'sfc:v1:woocommerce:cart:add:', $event['messageId'] );
		$this->assert_no_anonymous_fields( $event );

		$props = $event['properties'];
		$this->assertSame( $product->get_id(), $props['product_id'] );
		$this->assertSame( 'Test Widget', $props['name'] );
		$this->assertSame( 29.99, $props['price'] );
		$this->assertSame( 'WIDGET-001', $props['sku'] );
		$this->assertSame( 2, $props['quantity'] );
		$this->assertSame( 'cart-key-1', $props['cart_item_key'] );
		$this->assertArrayNotHasKey( 'variation_id', $props );
		$this->assertArrayNotHasKey( 'cart_hash', $event );
		$this->assertArrayNotHasKey( 'cart_item_key', $event );

		$this->assertTrue( $this->mock_api->requests[0]['options']['blocking'] );
		$this->assertSame( 1.5, $this->mock_api->requests[0]['options']['timeout'] );

		$product->delete( true );
	}

	/**
	 * Test on_add_to_cart includes variation_id when present.
	 */
	public function test_add_to_cart_includes_variation_id(): void {
		$this->login_as_customer( 'variation@example.com' );

		$product   = $this->create_product( 'Variable Widget', '39.99', 'VAR-001' );
		$variation = $this->create_product( 'Variable Widget Red', '39.99', 'VAR-001-RED' );

		$this->server_events->on_add_to_cart(
			'cart-key-2',
			$product->get_id(),
			1,
			$variation->get_id(),
			[ 'attribute_color' => 'red' ],
			[]
		);

		$this->assertCount( 1, $this->mock_api->requests );

		$props = $this->mock_api->requests[0]['body']['batch'][0]['properties'];
		$this->assertSame( $variation->get_id(), $props['variation_id'] );
		$this->assertSame( 'Variable Widget Red', $props['name'] );

		$product->delete( true );
		$variation->delete( true );
	}

	/**
	 * Test guest add_to_cart before email sends nothing.
	 */
	public function test_add_to_cart_skips_guest_before_email(): void {
		wp_set_current_user( 0 );
		$product = $this->create_product( 'Ghost Widget', '10.00', 'GHOST-001' );

		$this->server_events->on_add_to_cart( 'cart-key-3', $product->get_id(), 1, 0, [], [] );

		$this->assertCount( 0, $this->mock_api->requests );

		$product->delete( true );
	}

	/**
	 * Test on_add_to_cart skips when product is invalid.
	 */
	public function test_add_to_cart_skips_invalid_product(): void {
		$this->login_as_customer( 'invalid@example.com' );

		$this->server_events->on_add_to_cart( 'cart-key-4', 999999, 1, 0, [], [] );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	/**
	 * Test add_to_cart messageId is stable for a single callback invocation.
	 */
	public function test_add_to_cart_message_id_stable_within_callback(): void {
		$this->login_as_customer( 'stable@example.com' );
		$product = $this->create_product( 'Stable Widget', '12.00', 'STABLE-001' );

		$this->mock_api->queued_responses[] = [
			'success'     => false,
			'status_code' => 503,
			'data'        => [
				'error' => 'unavailable',
			],
		];

		$this->server_events->on_add_to_cart( 'cart-key-stable', $product->get_id(), 1, 0, [], [] );

		$this->assertCount( 2, $this->mock_api->requests );
		$first_id  = $this->mock_api->requests[0]['body']['batch'][0]['messageId'];
		$second_id = $this->mock_api->requests[1]['body']['batch'][0]['messageId'];
		$this->assertStringStartsWith( 'sfc:v1:woocommerce:cart:add:', $first_id );
		$this->assertSame( $first_id, $second_id );

		$product->delete( true );
	}

	// -------------------------------------------------------------------------
	// remove_from_cart tests
	// -------------------------------------------------------------------------

	/**
	 * Test logged-in remove_from_cart sends identified track event.
	 */
	public function test_remove_from_cart_sends_track_event(): void {
		$user_id = $this->login_as_customer( 'remove@example.com' );
		$product = $this->create_product( 'Remove Widget', '19.99', 'REM-001' );

		WC()->cart->empty_cart();
		$cart_item_key = WC()->cart->add_to_cart( $product->get_id(), 3 );
		WC()->cart->remove_cart_item( $cart_item_key );

		$this->server_events->on_remove_from_cart( $cart_item_key, WC()->cart );

		$this->assertCount( 1, $this->mock_api->requests );

		$event = $this->mock_api->requests[0]['body']['batch'][0];
		$this->assertSame( 'track', $event['type'] );
		$this->assertSame( 'remove_from_cart', $event['event'] );
		$this->assertSame( 'remove@example.com', $event['email'] );
		$this->assertSame( 'wc_' . $user_id, $event['userId'] );
		$this->assertSame( 'woocommerce', $event['source'] );
		$this->assertStringStartsWith( 'sfc:v1:woocommerce:cart:remove:', $event['messageId'] );
		$this->assert_no_anonymous_fields( $event );

		$props = $event['properties'];
		$this->assertSame( $product->get_id(), $props['product_id'] );
		$this->assertSame( 'Remove Widget', $props['name'] );
		$this->assertSame( 3, $props['quantity'] );
		$this->assertSame( 19.99, $props['price'] );
		$this->assertSame( 'REM-001', $props['sku'] );
		$this->assertSame( $cart_item_key, $props['cart_item_key'] );
		$this->assertArrayNotHasKey( 'cart_hash', $event );
		$this->assertArrayNotHasKey( 'cart_item_key', $event );

		WC()->cart->empty_cart();
		$product->delete( true );
	}

	/**
	 * Test guest remove_from_cart sends nothing.
	 */
	public function test_remove_from_cart_skips_guest_before_email(): void {
		wp_set_current_user( 0 );
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
		$this->login_as_customer( 'unknown-remove@example.com' );

		WC()->cart->empty_cart();
		$this->server_events->on_remove_from_cart( 'nonexistent-key', WC()->cart );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	// -------------------------------------------------------------------------
	// checkout (identity) tests
	// -------------------------------------------------------------------------

	/**
	 * Test on_checkout sends identify with billing traits and wc_ userId.
	 */
	public function test_checkout_sends_identify_event(): void {
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
		$this->assertSame( 'customer@example.com', $event['email'] );
		$this->assertSame( 'wc_42', $event['userId'] );
		$this->assertSame( 'woocommerce', $event['source'] );
		$this->assertSame(
			Segmentflow_Ingest_Event::deterministic_message_id(
				'woocommerce:checkout:identify',
				'woocommerce:checkout:identify:' . $order->get_id()
			),
			$event['messageId']
		);
		$this->assert_no_anonymous_fields( $event );

		$traits = $event['traits'];
		$this->assertSame( 'customer@example.com', $traits['email'] );
		$this->assertSame( '+1234567890', $traits['phone'] );
		$this->assertSame( 'Jane', $traits['first_name'] );
		$this->assertSame( 'Doe', $traits['last_name'] );

		$order->delete( true );
	}

	/**
	 * Test guest checkout may omit userId while keeping top-level email.
	 */
	public function test_checkout_guest_omits_user_id(): void {
		$order = wc_create_order();
		$order->set_billing_email( 'guest@example.com' );
		$order->set_customer_id( 0 );
		$order->save();

		$this->server_events->on_checkout( $order->get_id(), [], $order );

		$event = $this->mock_api->requests[0]['body']['batch'][0];
		$this->assertSame( 'identify', $event['type'] );
		$this->assertSame( 'guest@example.com', $event['email'] );
		$this->assertArrayNotHasKey( 'userId', $event );
		$this->assert_no_anonymous_fields( $event );

		$order->delete( true );
	}

	/**
	 * Test checkout requires a valid billing email.
	 */
	public function test_checkout_requires_valid_billing_email(): void {
		$order = wc_create_order();
		$order->set_billing_email( '' );
		$order->set_customer_id( 7 );
		$order->save();

		$this->server_events->on_checkout( $order->get_id(), [], $order );

		$this->assertCount( 0, $this->mock_api->requests );

		$order->delete( true );
	}

	/**
	 * Test classic and Blocks checkout share the same deterministic messageId.
	 */
	public function test_classic_and_blocks_checkout_share_message_id(): void {
		$order = wc_create_order();
		$order->set_billing_email( 'shared@example.com' );
		$order->set_customer_id( 0 );
		$order->save();

		$expected = Segmentflow_Ingest_Event::deterministic_message_id(
			'woocommerce:checkout:identify',
			'woocommerce:checkout:identify:' . $order->get_id()
		);

		$this->server_events->on_checkout( $order->get_id(), [], $order );
		$this->server_events->on_blocks_checkout( $order );

		$this->assertCount( 2, $this->mock_api->requests );
		$this->assertSame( $expected, $this->mock_api->requests[0]['body']['batch'][0]['messageId'] );
		$this->assertSame( $expected, $this->mock_api->requests[1]['body']['batch'][0]['messageId'] );

		$order->delete( true );
	}

	/**
	 * Test on_checkout stamps UTM meta from the sf_utm cookie.
	 */
	public function test_checkout_stamps_utm_meta_when_cookie_present(): void {
		$_COOKIE['sf_utm'] = wp_json_encode(
			[
				'source'   => 'segmentflow',
				'medium'   => 'email',
				'campaign' => 'spring-sale',
			]
		);

		$order = wc_create_order();
		$order->set_billing_email( 'utm@example.com' );
		$order->save();

		$this->server_events->on_checkout( $order->get_id(), [], $order );

		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( 'segmentflow', $reloaded->get_meta( '_segmentflow_utm_source' ) );
		$this->assertSame( 'email', $reloaded->get_meta( '_segmentflow_utm_medium' ) );
		$this->assertSame( 'spring-sale', $reloaded->get_meta( '_segmentflow_utm_campaign' ) );
		$this->assertSame( '', $reloaded->get_meta( '_segmentflow_utm_content' ) );
		$this->assertSame( '', $reloaded->get_meta( '_segmentflow_utm_term' ) );

		unset( $_COOKIE['sf_utm'] );
		$order->delete( true );
	}

	/**
	 * Test on_checkout does not stamp UTM meta when cookie is absent.
	 */
	public function test_checkout_no_utm_meta_when_cookie_absent(): void {
		unset( $_COOKIE['sf_utm'] );

		$order = wc_create_order();
		$order->set_billing_email( 'no-utm@example.com' );
		$order->save();

		$this->server_events->on_checkout( $order->get_id(), [], $order );

		$reloaded = wc_get_order( $order->get_id() );
		foreach ( [ 'source', 'medium', 'campaign', 'content', 'term' ] as $key ) {
			$this->assertSame( '', $reloaded->get_meta( '_segmentflow_utm_' . $key ) );
		}

		$order->delete( true );
	}

	/**
	 * Test that a malformed sf_utm cookie is silently ignored.
	 */
	public function test_checkout_ignores_malformed_utm_cookie(): void {
		$_COOKIE['sf_utm'] = 'not-valid-json{{{';

		$order = wc_create_order();
		$order->set_billing_email( 'malformed@example.com' );
		$order->save();

		$this->server_events->on_checkout( $order->get_id(), [], $order );

		$reloaded = wc_get_order( $order->get_id() );
		foreach ( [ 'source', 'medium', 'campaign', 'content', 'term' ] as $key ) {
			$this->assertSame( '', $reloaded->get_meta( '_segmentflow_utm_' . $key ) );
		}

		unset( $_COOKIE['sf_utm'] );
		$order->delete( true );
	}

	/**
	 * Test consent snapshot is attached when sf_consent is present.
	 */
	public function test_checkout_includes_consent_when_cookie_set(): void {
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => false,
			]
		);

		$order = wc_create_order();
		$order->set_billing_email( 'consent@example.com' );
		$order->save();

		$this->server_events->on_checkout( $order->get_id(), [], $order );

		$body = $this->mock_api->requests[0]['body'];
		$this->assertArrayHasKey( 'consent', $body );
		$this->assertTrue( $body['consent']['analytics'] );
		$this->assertFalse( $body['consent']['marketing'] );

		$order->delete( true );
	}

	/**
	 * Test that register_hooks does nothing when not connected.
	 */
	public function test_hooks_not_registered_when_disconnected(): void {
		delete_option( 'segmentflow_write_key' );

		$options       = new Segmentflow_Options();
		$ingest_client = new Segmentflow_Ingest_Client( $options, $this->mock_api );
		$server_events = new Segmentflow_WC_Server_Events( $options, $ingest_client );
		$server_events->register_hooks();

		$this->assertFalse( has_action( 'woocommerce_add_to_cart', [ $server_events, 'on_add_to_cart' ] ) );
	}

	/**
	 * Test that register_hooks registers actions when connected.
	 */
	public function test_hooks_registered_when_connected(): void {
		$server_events = new Segmentflow_WC_Server_Events( $this->options, $this->ingest_client );
		$server_events->register_hooks();

		$this->assertSame( 25, has_action( 'woocommerce_add_to_cart', [ $server_events, 'on_add_to_cart' ] ) );
		$this->assertSame( 10, has_action( 'woocommerce_cart_item_removed', [ $server_events, 'on_remove_from_cart' ] ) );
		$this->assertSame( 10, has_action( 'woocommerce_checkout_order_processed', [ $server_events, 'on_checkout' ] ) );
		$this->assertSame( 10, has_action( 'woocommerce_store_api_checkout_order_processed', [ $server_events, 'on_blocks_checkout' ] ) );
	}

	/**
	 * Test that events are not sent when write key is empty.
	 */
	public function test_send_event_skips_without_write_key(): void {
		delete_option( 'segmentflow_write_key' );
		$this->login_as_customer( 'nokey@example.com' );

		$options       = new Segmentflow_Options();
		$ingest_client = new Segmentflow_Ingest_Client( $options, $this->mock_api );
		$server_events = new Segmentflow_WC_Server_Events( $options, $ingest_client );

		$product = $this->create_product( 'No Key Widget', '10.00', 'NOKEY-001' );
		$server_events->on_add_to_cart( 'cart-key-nokey', $product->get_id(), 1, 0, [], [] );

		$this->assertCount( 0, $this->mock_api->requests );

		$product->delete( true );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Log in a WooCommerce customer for cart mutation tests.
	 *
	 * @param string $email Customer email.
	 * @return int User ID.
	 */
	private function login_as_customer( string $email ): int {
		$user_id = self::factory()->user->create( [ 'user_email' => $email ] );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Assert forbidden anonymous / routing fields are absent.
	 *
	 * @param array<string, mixed> $item Ingest item.
	 */
	private function assert_no_anonymous_fields( array $item ): void {
		$this->assertArrayNotHasKey( 'anonymousId', $item );
		$this->assertArrayNotHasKey( 'organizationId', $item );
		$this->assertArrayNotHasKey( 'sourceInstanceId', $item );
		$this->assertArrayNotHasKey( 'identityNamespace', $item );
		$this->assertArrayNotHasKey( 'profileId', $item );
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
