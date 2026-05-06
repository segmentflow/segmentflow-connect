<?php
/**
 * Segmentflow WooCommerce discount endpoints.
 *
 * Exposes a versioned REST contract under /wp-json/segmentflow/v1/discounts so
 * the Segmentflow backend can drive coupon CRUD on the WooCommerce store
 * without speaking direct WP REST. All endpoints are HMAC-authenticated using
 * the same shared `webhook_secret` that signs outgoing WC webhooks.
 *
 * Slice 1.3 scope (issue #179):
 *   - kind: 'percentage' only on create. fixed/free_shipping deferred to #3.1.
 *   - hard delete only.
 *   - redemptions endpoint walks orders since a cursor and emits one row per
 *     (order, coupon) pair as the safety net for missed redemption webhooks.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_WC_Discounts
 *
 * REST controller for discount CRUD + redemption listing. Backed by native
 * WC_Coupon and wc_get_orders so the plugin stays inside WooCommerce's
 * supported APIs (HPOS-safe via wc_get_orders).
 */
class Segmentflow_WC_Discounts {

	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'segmentflow/v1';

	/**
	 * Maximum age (in seconds) accepted for the X-Segmentflow-Timestamp header.
	 * Mirrors Stripe's 5-minute replay window.
	 */
	public const SIGNATURE_TOLERANCE_SECONDS = 300;

	/**
	 * Maximum number of orders scanned per /redemptions call. Callers paginate
	 * by re-querying with the last `createdAt` they saw as the new `since`.
	 */
	public const REDEMPTIONS_PAGE_SIZE = 500;

	/**
	 * Allowed coupon code charset and length (intersection of Shopify and Woo).
	 */
	private const CODE_PATTERN     = '/^[A-Z0-9_\-]{3,20}$/';
	private const CODE_REGEX_PARAM = '[A-Za-z0-9_\-]{3,20}';

	/**
	 * Options instance.
	 */
	private Segmentflow_Options $options;

	/**
	 * Constructor.
	 *
	 * @param Segmentflow_Options $options The options instance.
	 */
	public function __construct( Segmentflow_Options $options ) {
		$this->options = $options;
	}

	/**
	 * Register the rest_api_init hook.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register all five discount endpoints.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/discounts',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_coupon' ],
				'permission_callback' => [ $this, 'verify_hmac' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/discounts/by-code/(?P<code>' . self::CODE_REGEX_PARAM . ')',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_coupon_by_code' ],
				'permission_callback' => [ $this, 'verify_hmac' ],
			]
		);

		// /redemptions must be registered before /(?P<id>\d+) so the literal
		// path doesn't get swallowed by the numeric capture. WP routes are
		// matched in registration order within a namespace.
		register_rest_route(
			self::REST_NAMESPACE,
			'/discounts/redemptions',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_redemptions' ],
				'permission_callback' => [ $this, 'verify_hmac' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/discounts/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_coupon' ],
					'permission_callback' => [ $this, 'verify_hmac' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_coupon' ],
					'permission_callback' => [ $this, 'verify_hmac' ],
				],
			]
		);
	}

	/**
	 * Verify the inbound HMAC signature.
	 *
	 * Signature scheme (Stripe-style, plus method + path so a leaked sig can't
	 * be replayed against a different endpoint within the tolerance window):
	 *
	 *   signed_payload = "{timestamp}\n{METHOD}\n{path-with-query}\n{raw_body}"
	 *   signature      = hex(HMAC-SHA256(webhook_secret, signed_payload))
	 *
	 * Headers:
	 *   X-Segmentflow-Timestamp: unix seconds
	 *   X-Segmentflow-Signature: hex string
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error True if valid, WP_Error with 401 otherwise.
	 */
	public function verify_hmac( WP_REST_Request $request ): bool|WP_Error {
		$secret = (string) $this->options->get( 'webhook_secret' );
		if ( '' === $secret ) {
			return new WP_Error(
				'segmentflow_not_connected',
				__( 'Plugin is not connected to Segmentflow.', 'segmentflow-connect' ),
				[ 'status' => 401 ]
			);
		}

		$timestamp = (string) $request->get_header( 'x-segmentflow-timestamp' );
		$signature = (string) $request->get_header( 'x-segmentflow-signature' );

		if ( '' === $timestamp || '' === $signature ) {
			return new WP_Error(
				'segmentflow_missing_signature',
				__( 'Missing Segmentflow signature headers.', 'segmentflow-connect' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! ctype_digit( $timestamp ) ) {
			return new WP_Error(
				'segmentflow_invalid_timestamp',
				__( 'Invalid Segmentflow timestamp.', 'segmentflow-connect' ),
				[ 'status' => 401 ]
			);
		}

		$skew = abs( time() - (int) $timestamp );
		if ( $skew > self::SIGNATURE_TOLERANCE_SECONDS ) {
			return new WP_Error(
				'segmentflow_stale_signature',
				__( 'Segmentflow signature timestamp is outside the tolerance window.', 'segmentflow-connect' ),
				[ 'status' => 401 ]
			);
		}

		$expected = self::compute_signature(
			$secret,
			$timestamp,
			$request->get_method(),
			self::path_with_query( $request ),
			(string) $request->get_body()
		);

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error(
				'segmentflow_bad_signature',
				__( 'Segmentflow signature mismatch.', 'segmentflow-connect' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Compute the canonical HMAC signature for a request.
	 *
	 * Exposed publicly so backend integration tests and the #1.4 adapter can
	 * sign requests with the exact same canonicalization the plugin verifies.
	 *
	 * @param string $secret    Shared HMAC secret.
	 * @param string $timestamp Unix seconds, as a decimal string.
	 * @param string $method    HTTP method (will be uppercased).
	 * @param string $path      Path with query string (e.g. "/wp-json/segmentflow/v1/discounts/redemptions?since=...").
	 * @param string $body      Raw request body (empty string for GET/DELETE).
	 * @return string Hex-encoded HMAC-SHA256.
	 */
	public static function compute_signature(
		string $secret,
		string $timestamp,
		string $method,
		string $path,
		string $body
	): string {
		$payload = $timestamp . "\n" . strtoupper( $method ) . "\n" . $path . "\n" . $body;
		return hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Best-effort recovery of the request path + query string from $_SERVER.
	 *
	 * REST_REQUEST is dispatched after WordPress strips the rewrite prefix, so
	 * $request->get_route() only gives us the namespaced path. We sign over
	 * the actual on-the-wire URI so the backend doesn't have to know whether
	 * the site is using pretty permalinks or ?rest_route=.
	 *
	 * @param WP_REST_Request $request The request (unused; reserved for future canonicalization).
	 * @return string The path-with-query portion of REQUEST_URI, or '' if unavailable.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	private static function path_with_query( WP_REST_Request $request ): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		// REQUEST_URI is the full request line (e.g. "/wp-json/segmentflow/v1/...?since=...").
		return (string) wp_unslash( $_SERVER['REQUEST_URI'] );
	}

	// =========================================================================
	// POST /discounts
	// =========================================================================

	/**
	 * Create a coupon.
	 *
	 * Body:
	 *   {
	 *     "code": "SUMMER20",
	 *     "kind": "percentage",
	 *     "amount": 20,
	 *     "minPurchaseAmount": 50,        // optional
	 *     "expiresAt": "2026-09-01T00:00:00Z", // optional, ISO 8601
	 *     "currency": "USD"               // advisory; Woo coupons are not currency-typed
	 *   }
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_coupon( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return new WP_Error(
				'segmentflow_woocommerce_inactive',
				__( 'WooCommerce is not active on this site.', 'segmentflow-connect' ),
				[ 'status' => 503 ]
			);
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		$code         = isset( $params['code'] ) ? strtoupper( (string) $params['code'] ) : '';
		$kind         = isset( $params['kind'] ) ? (string) $params['kind'] : '';
		$amount       = $params['amount'] ?? null;
		$min_purchase = $params['minPurchaseAmount'] ?? null;
		$expires_at   = isset( $params['expiresAt'] ) ? (string) $params['expiresAt'] : '';

		if ( '' === $code || 1 !== preg_match( self::CODE_PATTERN, $code ) ) {
			return new WP_Error(
				'segmentflow_invalid_code',
				__( 'Coupon code must be 3-20 chars of [A-Z0-9_-].', 'segmentflow-connect' ),
				[ 'status' => 400 ]
			);
		}

		if ( 'percentage' !== $kind ) {
			return new WP_Error(
				'segmentflow_unsupported_kind',
				/* translators: %s: discount kind */
				sprintf( __( 'Discount kind "%s" is not supported in this plugin version. Only "percentage" is supported.', 'segmentflow-connect' ), $kind ),
				[ 'status' => 400 ]
			);
		}

		if ( ! is_numeric( $amount ) || (float) $amount <= 0 || (float) $amount > 100 ) {
			return new WP_Error(
				'segmentflow_invalid_amount',
				__( 'Amount must be a number between 0 (exclusive) and 100 for percentage discounts.', 'segmentflow-connect' ),
				[ 'status' => 400 ]
			);
		}

		if ( null !== $min_purchase && ( ! is_numeric( $min_purchase ) || (float) $min_purchase < 0 ) ) {
			return new WP_Error(
				'segmentflow_invalid_min_purchase',
				__( 'minPurchaseAmount must be a non-negative number.', 'segmentflow-connect' ),
				[ 'status' => 400 ]
			);
		}

		$expires_timestamp = null;
		if ( '' !== $expires_at ) {
			$expires_timestamp = strtotime( $expires_at );
			if ( false === $expires_timestamp ) {
				return new WP_Error(
					'segmentflow_invalid_expires_at',
					__( 'expiresAt must be a parseable ISO 8601 timestamp.', 'segmentflow-connect' ),
					[ 'status' => 400 ]
				);
			}
		}

		$existing_id = wc_get_coupon_id_by_code( $code );
		if ( $existing_id > 0 ) {
			return new WP_Error(
				'segmentflow_coupon_exists',
				/* translators: %s: coupon code */
				sprintf( __( 'A coupon with code "%s" already exists.', 'segmentflow-connect' ), $code ),
				[ 'status' => 409 ]
			);
		}

		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( (string) $amount );

		if ( null !== $min_purchase ) {
			$coupon->set_minimum_amount( (string) $min_purchase );
		}

		if ( null !== $expires_timestamp ) {
			$coupon->set_date_expires( $expires_timestamp );
		}

		$saved_id = $coupon->save();
		if ( ! $saved_id ) {
			return new WP_Error(
				'segmentflow_save_failed',
				__( 'Failed to save the coupon.', 'segmentflow-connect' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'providerId' => (int) $saved_id,
				'code'       => $coupon->get_code(),
			],
			201
		);
	}

	// =========================================================================
	// GET /discounts/by-code/{code}
	// =========================================================================

	/**
	 * Fetch a coupon by code.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_coupon_by_code( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return new WP_Error(
				'segmentflow_woocommerce_inactive',
				__( 'WooCommerce is not active on this site.', 'segmentflow-connect' ),
				[ 'status' => 503 ]
			);
		}

		$code = strtoupper( (string) $request['code'] );
		$id   = wc_get_coupon_id_by_code( $code );
		if ( 0 === $id ) {
			return new WP_Error(
				'segmentflow_coupon_not_found',
				__( 'Coupon not found.', 'segmentflow-connect' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->serialize_coupon( new WC_Coupon( $id ) ), 200 );
	}

	// =========================================================================
	// GET /discounts/{id}
	// =========================================================================

	/**
	 * Fetch a coupon by provider ID.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_coupon( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return new WP_Error(
				'segmentflow_woocommerce_inactive',
				__( 'WooCommerce is not active on this site.', 'segmentflow-connect' ),
				[ 'status' => 503 ]
			);
		}

		$id     = (int) $request['id'];
		$coupon = new WC_Coupon( $id );
		if ( 0 === $coupon->get_id() ) {
			return new WP_Error(
				'segmentflow_coupon_not_found',
				__( 'Coupon not found.', 'segmentflow-connect' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->serialize_coupon( $coupon ), 200 );
	}

	// =========================================================================
	// DELETE /discounts/{id}
	// =========================================================================

	/**
	 * Hard-delete a coupon.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_coupon( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return new WP_Error(
				'segmentflow_woocommerce_inactive',
				__( 'WooCommerce is not active on this site.', 'segmentflow-connect' ),
				[ 'status' => 503 ]
			);
		}

		$id     = (int) $request['id'];
		$coupon = new WC_Coupon( $id );
		if ( 0 === $coupon->get_id() ) {
			return new WP_Error(
				'segmentflow_coupon_not_found',
				__( 'Coupon not found.', 'segmentflow-connect' ),
				[ 'status' => 404 ]
			);
		}

		$coupon->delete( true );

		return new WP_REST_Response(
			[
				'providerId' => $id,
				'deleted'    => true,
			],
			200
		);
	}

	// =========================================================================
	// GET /discounts/redemptions?since=...
	// =========================================================================

	/**
	 * List orders that used a coupon since the given cursor.
	 *
	 * Returns one row per (order, coupon) pair. Orders are returned in
	 * ascending `createdAt` order so callers can advance the `since` cursor
	 * to the last seen `createdAt` and resume cleanly.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_redemptions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error(
				'segmentflow_woocommerce_inactive',
				__( 'WooCommerce is not active on this site.', 'segmentflow-connect' ),
				[ 'status' => 503 ]
			);
		}

		$since_param = (string) $request->get_param( 'since' );
		if ( '' === $since_param ) {
			return new WP_Error(
				'segmentflow_missing_since',
				__( '`since` query parameter is required (ISO 8601).', 'segmentflow-connect' ),
				[ 'status' => 400 ]
			);
		}

		$since_timestamp = strtotime( $since_param );
		if ( false === $since_timestamp ) {
			return new WP_Error(
				'segmentflow_invalid_since',
				__( '`since` must be a parseable ISO 8601 timestamp.', 'segmentflow-connect' ),
				[ 'status' => 400 ]
			);
		}

		$orders = wc_get_orders(
			[
				'limit'        => self::REDEMPTIONS_PAGE_SIZE,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'date_created' => '>' . $since_timestamp,
				'type'         => 'shop_order',
				'status'       => array_keys( wc_get_order_statuses() ),
			]
		);

		$redemptions = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$coupon_items = $order->get_items( 'coupon' );
			if ( empty( $coupon_items ) ) {
				continue;
			}

			$created    = $order->get_date_created();
			$created_at = $created ? $created->format( DATE_ATOM ) : null;
			$customer   = $order->get_customer_id();

			foreach ( $coupon_items as $coupon_item ) {
				if ( ! $coupon_item instanceof WC_Order_Item_Coupon ) {
					continue;
				}
				$redemptions[] = [
					'providerOrderId' => (string) $order->get_id(),
					'code'            => strtoupper( (string) $coupon_item->get_code() ),
					'customerId'      => $customer > 0 ? (string) $customer : null,
					'email'           => $order->get_billing_email(),
					'amount'          => (float) $coupon_item->get_discount(),
					'currency'        => $order->get_currency(),
					'createdAt'       => $created_at,
				];
			}
		}

		return new WP_REST_Response(
			[
				'redemptions' => $redemptions,
				'hasMore'     => count( $orders ) >= self::REDEMPTIONS_PAGE_SIZE,
			],
			200
		);
	}

	// =========================================================================
	// helpers
	// =========================================================================

	/**
	 * Serialize a WC_Coupon to the API contract shape.
	 *
	 * @param WC_Coupon $coupon The coupon.
	 * @return array<string, mixed>
	 */
	private function serialize_coupon( WC_Coupon $coupon ): array {
		$expires    = $coupon->get_date_expires();
		$min_amount = $coupon->get_minimum_amount();

		return [
			'providerId'        => (int) $coupon->get_id(),
			'code'              => $coupon->get_code(),
			'kind'              => self::map_discount_type( (string) $coupon->get_discount_type() ),
			'amount'            => (float) $coupon->get_amount(),
			'minPurchaseAmount' => '' === $min_amount || null === $min_amount ? null : (float) $min_amount,
			'expiresAt'         => $expires ? $expires->format( DATE_ATOM ) : null,
			'currency'          => Segmentflow_WC_Helper::get_currency(),
		];
	}

	/**
	 * Map a WooCommerce discount_type to the Segmentflow `kind` vocabulary.
	 *
	 * Slice 1.3 only writes 'percent', but reads may surface coupons created
	 * outside the plugin — we map them honestly so the backend can decide
	 * whether to expose them.
	 *
	 * @param string $wc_type WooCommerce discount type.
	 * @return string
	 */
	private static function map_discount_type( string $wc_type ): string {
		return match ( $wc_type ) {
			'percent'                       => 'percentage',
			'fixed_cart', 'fixed_product'   => 'fixed',
			default                         => 'unsupported',
		};
	}
}
