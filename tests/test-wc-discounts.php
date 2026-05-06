<?php
/**
 * Tests for the WooCommerce discount REST endpoints.
 *
 * Verifies the five endpoints under /wp-json/segmentflow/v1/discounts and the
 * HMAC permission_callback. Uses WP_REST_Server::dispatch() so the full route
 * matching + permission_callback path is exercised.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_WC_Discounts
 *
 * Covers create / get-by-code / get / delete / redemptions plus HMAC auth
 * on every endpoint.
 */
class Test_WC_Discounts extends WP_UnitTestCase {

	/**
	 * Shared HMAC secret used in tests.
	 */
	private const SECRET = 'test_webhook_secret_value';

	/**
	 * REST server instance.
	 */
	private WP_REST_Server $server;

	/**
	 * Skip if WooCommerce isn't loaded.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( 'WC_Coupon' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this environment.' );
		}
		if ( ! class_exists( 'Segmentflow_WC_Discounts' ) ) {
			$this->markTestSkipped( 'Discount integration class not loaded — plugin not connected in test bootstrap.' );
		}

		// Wire the secret so HMAC verify has something to compare against.
		update_option( 'segmentflow_webhook_secret', self::SECRET );

		// Force-register the routes for tests — in production, registration is
		// gated by `is_connected() && webhook_secret`, neither of which is set
		// during the WP test suite bootstrap.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;

		$options   = new Segmentflow_Options();
		$discounts = new Segmentflow_WC_Discounts( $options );
		$discounts->register_routes();

		do_action( 'rest_api_init' );
	}

	/**
	 * Clean up.
	 */
	public function tear_down(): void {
		delete_option( 'segmentflow_webhook_secret' );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	// =========================================================================
	// HMAC verification
	// =========================================================================

	/**
	 * 401 when both signature headers are missing.
	 */
	public function test_request_without_signature_is_rejected(): void {
		$request  = new WP_REST_Request( 'GET', '/segmentflow/v1/discounts/by-code/SUMMER20' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() );
		$this->assertEquals( 'segmentflow_missing_signature', $response->get_data()['code'] ?? '' );
	}

	/**
	 * 401 when timestamp is outside the tolerance window.
	 */
	public function test_stale_timestamp_is_rejected(): void {
		$request  = $this->build_signed_request(
			'GET',
			'/segmentflow/v1/discounts/by-code/SUMMER20',
			'',
			(string) ( time() - 600 )
		);
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() );
		$this->assertEquals( 'segmentflow_stale_signature', $response->get_data()['code'] ?? '' );
	}

	/**
	 * 401 when signature is wrong.
	 */
	public function test_bad_signature_is_rejected(): void {
		$request = new WP_REST_Request( 'GET', '/segmentflow/v1/discounts/by-code/SUMMER20' );
		$request->set_header( 'x-segmentflow-timestamp', (string) time() );
		$request->set_header( 'x-segmentflow-signature', 'deadbeef' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() );
		$this->assertEquals( 'segmentflow_bad_signature', $response->get_data()['code'] ?? '' );
	}

	/**
	 * 401 when the plugin has no shared secret stored.
	 */
	public function test_missing_secret_is_rejected(): void {
		delete_option( 'segmentflow_webhook_secret' );

		$request  = $this->build_signed_request( 'GET', '/segmentflow/v1/discounts/by-code/SUMMER20', '' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() );
		$this->assertEquals( 'segmentflow_not_connected', $response->get_data()['code'] ?? '' );
	}

	// =========================================================================
	// POST /discounts
	// =========================================================================

	/**
	 * Happy path: create a percentage coupon.
	 */
	public function test_create_coupon_succeeds(): void {
		$body = wp_json_encode(
			[
				'code'              => 'SUMMER20',
				'kind'              => 'percentage',
				'amount'            => 20,
				'minPurchaseAmount' => 50,
				'expiresAt'         => '2099-12-31T00:00:00Z',
				'currency'          => 'USD',
			]
		);

		$request  = $this->build_signed_request( 'POST', '/segmentflow/v1/discounts', $body );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'providerId', $data );
		$this->assertGreaterThan( 0, $data['providerId'] );
		$this->assertEquals( 'SUMMER20', $data['code'] );

		$coupon = new WC_Coupon( (int) $data['providerId'] );
		$this->assertEquals( 'percent', $coupon->get_discount_type() );
		$this->assertEquals( '20', $coupon->get_amount() );
		$this->assertEquals( '50', $coupon->get_minimum_amount() );
		$this->assertNotNull( $coupon->get_date_expires() );
	}

	/**
	 * Lower-case input is normalized to upper-case.
	 */
	public function test_create_coupon_uppercases_code(): void {
		$body     = wp_json_encode(
			[
				'code'   => 'welcome10',
				'kind'   => 'percentage',
				'amount' => 10,
			]
		);
		$request  = $this->build_signed_request( 'POST', '/segmentflow/v1/discounts', $body );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'WELCOME10', $response->get_data()['code'] );
	}

	/**
	 * Reject an invalid code (too short).
	 */
	public function test_create_coupon_rejects_invalid_code(): void {
		$body     = wp_json_encode(
			[
				'code'   => 'AB',
				'kind'   => 'percentage',
				'amount' => 10,
			]
		);
		$request  = $this->build_signed_request( 'POST', '/segmentflow/v1/discounts', $body );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'segmentflow_invalid_code', $response->get_data()['code'] );
	}

	/**
	 * Reject unsupported `kind`.
	 */
	public function test_create_coupon_rejects_non_percentage_kind(): void {
		$body     = wp_json_encode(
			[
				'code'   => 'FIXED10',
				'kind'   => 'fixed',
				'amount' => 10,
			]
		);
		$request  = $this->build_signed_request( 'POST', '/segmentflow/v1/discounts', $body );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'segmentflow_unsupported_kind', $response->get_data()['code'] );
	}

	/**
	 * Reject a percentage > 100.
	 */
	public function test_create_coupon_rejects_amount_over_100(): void {
		$body     = wp_json_encode(
			[
				'code'   => 'OVER100',
				'kind'   => 'percentage',
				'amount' => 150,
			]
		);
		$request  = $this->build_signed_request( 'POST', '/segmentflow/v1/discounts', $body );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'segmentflow_invalid_amount', $response->get_data()['code'] );
	}

	/**
	 * 409 if the code already exists.
	 */
	public function test_create_coupon_returns_409_on_existing_code(): void {
		$existing = new WC_Coupon();
		$existing->set_code( 'EXISTING' );
		$existing->set_discount_type( 'percent' );
		$existing->set_amount( '10' );
		$existing->save();

		$body     = wp_json_encode(
			[
				'code'   => 'EXISTING',
				'kind'   => 'percentage',
				'amount' => 15,
			]
		);
		$request  = $this->build_signed_request( 'POST', '/segmentflow/v1/discounts', $body );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 409, $response->get_status() );
		$this->assertEquals( 'segmentflow_coupon_exists', $response->get_data()['code'] );
	}

	// =========================================================================
	// GET /discounts/by-code/{code}
	// =========================================================================

	/**
	 * Fetch by code returns the saved coupon.
	 */
	public function test_get_coupon_by_code_returns_serialized_coupon(): void {
		$this->create_coupon_via_api( 'BYCODE15', 15, 0 );

		$request  = $this->build_signed_request( 'GET', '/segmentflow/v1/discounts/by-code/BYCODE15', '' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'BYCODE15', $data['code'] );
		$this->assertEquals( 'percentage', $data['kind'] );
		$this->assertEquals( 15.0, $data['amount'] );
	}

	/**
	 * 404 when code is unknown.
	 */
	public function test_get_coupon_by_code_returns_404_when_missing(): void {
		$request  = $this->build_signed_request( 'GET', '/segmentflow/v1/discounts/by-code/UNKNOWN', '' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
		$this->assertEquals( 'segmentflow_coupon_not_found', $response->get_data()['code'] );
	}

	// =========================================================================
	// GET /discounts/{id}
	// =========================================================================

	/**
	 * Fetch by ID returns the saved coupon.
	 */
	public function test_get_coupon_by_id_returns_serialized_coupon(): void {
		$id = $this->create_coupon_via_api( 'BYID25', 25, 100 );

		$request  = $this->build_signed_request( 'GET', '/segmentflow/v1/discounts/' . $id, '' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( $id, $data['providerId'] );
		$this->assertEquals( 'BYID25', $data['code'] );
		$this->assertEquals( 100.0, $data['minPurchaseAmount'] );
	}

	/**
	 * 404 when id is unknown.
	 */
	public function test_get_coupon_by_id_returns_404_when_missing(): void {
		$request  = $this->build_signed_request( 'GET', '/segmentflow/v1/discounts/9999999', '' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
		$this->assertEquals( 'segmentflow_coupon_not_found', $response->get_data()['code'] );
	}

	// =========================================================================
	// DELETE /discounts/{id}
	// =========================================================================

	/**
	 * Hard-delete removes the coupon from the DB.
	 */
	public function test_delete_coupon_succeeds(): void {
		$id = $this->create_coupon_via_api( 'DELME10', 10, 0 );

		$request  = $this->build_signed_request( 'DELETE', '/segmentflow/v1/discounts/' . $id, '' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertEquals( 0, wc_get_coupon_id_by_code( 'DELME10' ) );
	}

	/**
	 * 404 when deleting an unknown id.
	 */
	public function test_delete_coupon_returns_404_when_missing(): void {
		$request  = $this->build_signed_request( 'DELETE', '/segmentflow/v1/discounts/9999999', '' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	// =========================================================================
	// GET /discounts/redemptions
	// =========================================================================

	/**
	 * `since` is required.
	 */
	public function test_redemptions_requires_since(): void {
		$request  = $this->build_signed_request( 'GET', '/segmentflow/v1/discounts/redemptions', '' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'segmentflow_missing_since', $response->get_data()['code'] );
	}

	/**
	 * Returns redemption rows for orders that used a coupon since the cursor.
	 */
	public function test_redemptions_returns_orders_with_coupons(): void {
		$this->create_coupon_via_api( 'REDEEM20', 20, 0 );

		// Build an order with a coupon line item directly so the test doesn't
		// depend on a real product/cart — apply_coupon() needs items to act on.
		$order = wc_create_order();
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_currency( 'USD' );

		$coupon_item = new WC_Order_Item_Coupon();
		$coupon_item->set_code( 'REDEEM20' );
		$coupon_item->set_discount( 20 );
		$order->add_item( $coupon_item );
		$order->set_total( 80 );
		$order->save();

		$since = gmdate( 'c', time() - 3600 );
		$route = '/segmentflow/v1/discounts/redemptions?since=' . rawurlencode( $since );

		$request  = $this->build_signed_request( 'GET', $route, '' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'redemptions', $data );

		$found = false;
		foreach ( $data['redemptions'] as $row ) {
			if ( (string) $order->get_id() === $row['providerOrderId'] && 'REDEEM20' === $row['code'] ) {
				$found = true;
				$this->assertEquals( 'buyer@example.com', $row['email'] );
				$this->assertEquals( 'USD', $row['currency'] );
				$this->assertEquals( 20.0, $row['amount'] );
				break;
			}
		}
		$this->assertTrue( $found, 'Expected to see the redemption for REDEEM20 in the response.' );
	}

	// =========================================================================
	// helpers
	// =========================================================================

	/**
	 * Sign a request with the test secret.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route (e.g. "/segmentflow/v1/discounts/by-code/X").
	 * @param string $body   Raw JSON body, or empty.
	 * @param string $ts     Optional unix timestamp override.
	 * @return WP_REST_Request
	 */
	private function build_signed_request( string $method, string $route, string $body, string $ts = '' ): WP_REST_Request {
		if ( '' === $ts ) {
			$ts = (string) time();
		}

		// During dispatch(), Segmentflow_WC_Discounts reads $_SERVER['REQUEST_URI']
		// so it can sign over the on-the-wire URL. Mirror that here.
		$_SERVER['REQUEST_URI'] = $route;

		$signature = Segmentflow_WC_Discounts::compute_signature(
			self::SECRET,
			$ts,
			$method,
			$route,
			$body
		);

		// Strip the query string from the route before passing it to
		// WP_REST_Request — WP wants just the path; query params get parsed
		// from $_GET / set_query_params.
		$route_path  = $route;
		$query_pairs = [];
		$qpos        = strpos( $route, '?' );
		if ( false !== $qpos ) {
			$route_path = substr( $route, 0, $qpos );
			parse_str( substr( $route, $qpos + 1 ), $query_pairs );
		}

		$request = new WP_REST_Request( $method, $route_path );
		$request->set_header( 'x-segmentflow-timestamp', $ts );
		$request->set_header( 'x-segmentflow-signature', $signature );

		if ( '' !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( $body );
		}

		foreach ( $query_pairs as $k => $v ) {
			$request->set_param( $k, $v );
		}

		return $request;
	}

	/**
	 * Create a coupon via the REST endpoint (for fixture setup).
	 *
	 * @param string $code         Coupon code.
	 * @param float  $amount       Percentage amount.
	 * @param float  $min_purchase Minimum purchase, or 0 for none.
	 * @return int The provider ID of the created coupon.
	 */
	private function create_coupon_via_api( string $code, float $amount, float $min_purchase ): int {
		$payload = [
			'code'   => $code,
			'kind'   => 'percentage',
			'amount' => $amount,
		];
		if ( $min_purchase > 0 ) {
			$payload['minPurchaseAmount'] = $min_purchase;
		}

		$body     = wp_json_encode( $payload );
		$request  = $this->build_signed_request( 'POST', '/segmentflow/v1/discounts', $body );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 201, $response->get_status(), 'Fixture creation should succeed.' );
		return (int) $response->get_data()['providerId'];
	}
}
