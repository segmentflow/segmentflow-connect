<?php
/**
 * Segmentflow WooCommerce server-side events.
 *
 * Fires cart and checkout events from PHP hooks, sent to the ingest API
 * as fire-and-forget batch requests. Follows the Klaviyo architecture
 * pattern: cart mutations from PHP, order lifecycle from webhooks.
 *
 * Identity is read from the unified `sf_id` cookie (see class-segmentflow-identity-cookie.php).
 * Events are skipped when the cookie has no anonymous ID (visitor unidentifiable).
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_WC_Server_Events
 *
 * Hooks into WooCommerce action hooks to fire server-side events:
 * - `woocommerce_add_to_cart`              -> `add_to_cart` track event
 * - `woocommerce_cart_item_removed`        -> `remove_from_cart` track event
 * - `woocommerce_checkout_order_processed` -> identify (identity stitching)
 *
 * All events use fire-and-forget POST to /api/v1/ingest/batch with source: "WordPress".
 */
class Segmentflow_WC_Server_Events {

	/**
	 * Event source identifier for PHP-originated events.
	 */
	const EVENT_SOURCE = 'wordpress';

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * API client instance.
	 *
	 * @var Segmentflow_API
	 */
	private Segmentflow_API $api;

	/**
	 * Constructor.
	 *
	 * @param Segmentflow_Options $options The options instance.
	 * @param Segmentflow_API     $api     The API client instance.
	 */
	public function __construct( Segmentflow_Options $options, Segmentflow_API $api ) {
		$this->options = $options;
		$this->api     = $api;
	}

	/**
	 * Register WooCommerce action hooks.
	 *
	 * Only registers hooks on frontend requests when the plugin is connected.
	 */
	public function register_hooks(): void {
		if ( ! $this->options->is_connected() ) {
			return;
		}

		// Priority 25 matches Klaviyo — fires after WC's own hooks (priority 20)
		// to ensure cart state is complete.
		add_action( 'woocommerce_add_to_cart', [ $this, 'on_add_to_cart' ], 25, 6 );

		add_action( 'woocommerce_cart_item_removed', [ $this, 'on_remove_from_cart' ], 10, 2 );

		// Identity stitching at checkout: merges billing email/phone into cookie
		// and sends identify to bridge anonymous browsing to known customer.
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_checkout' ], 10, 3 );

		// Blocks / Store API checkout uses a separate hook from classic shortcode checkout.
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_blocks_checkout' ], 10, 1 );
	}

	/**
	 * Handle the `woocommerce_add_to_cart` action.
	 *
	 * Fires an `add_to_cart` track event with product details.
	 *
	 * @param string $cart_item_key  Cart item key.
	 * @param int    $product_id     Product ID.
	 * @param int    $quantity       Quantity added.
	 * @param int    $variation_id   Variation ID (0 if not a variation).
	 * @param array  $variation      Variation attributes (unused, required by hook signature).
	 * @param array  $cart_item_data Additional cart item data (unused, required by hook signature).
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- Hook callback must match woocommerce_add_to_cart signature.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook callback must accept all parameters from woocommerce_add_to_cart action.
	public function on_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
		// `add_to_cart` requires a stable visitor anchor — there is no
		// hook-supplied email or user ID for guests, so we genuinely
		// can't fire this event without `sf_id`. Skipping silently
		// matches Klaviyo and is the safe behavior pre-consent.
		$identity = $this->resolve_identity( null, null );
		if ( ! $identity ) {
			return;
		}

		// Use the variation product if this is a variable product.
		$resolved_product_id = $variation_id ? $variation_id : $product_id;
		$product             = wc_get_product( $resolved_product_id );
		if ( ! $product ) {
			return;
		}

		$properties = [
			'product_id' => $product_id,
			'name'       => $product->get_name(),
			'price'      => (float) $product->get_price(),
			'sku'        => $product->get_sku(),
			'quantity'   => $quantity,
			'currency'   => get_woocommerce_currency(),
		];

		if ( $variation_id ) {
			$properties['variation_id'] = $variation_id;
		}

		$this->send_event(
			$this->build_track_event( 'add_to_cart', $identity, $properties )
		);
	}

	/**
	 * Handle the `woocommerce_cart_item_removed` action.
	 *
	 * Fires a `remove_from_cart` track event with product details.
	 *
	 * @param string $cart_item_key Cart item key of the removed item.
	 * @param object $cart          The WC_Cart instance.
	 */
	public function on_remove_from_cart( string $cart_item_key, object $cart ): void {
		// Same reasoning as `on_add_to_cart`: requires an `sf_id` anchor.
		$identity = $this->resolve_identity( null, null );
		if ( ! $identity ) {
			return;
		}

		// The removed item data is still available via get_removed_cart_contents().
		$removed_items = $cart->get_removed_cart_contents();
		if ( ! isset( $removed_items[ $cart_item_key ] ) ) {
			return;
		}

		$removed_item = $removed_items[ $cart_item_key ];
		$product_id   = $removed_item['product_id'] ?? 0;
		$product      = wc_get_product( $product_id );

		$properties = [
			'product_id' => $product_id,
			'name'       => $product ? $product->get_name() : '',
			'quantity'   => $removed_item['quantity'] ?? 1,
			'currency'   => get_woocommerce_currency(),
		];

		$variation_id = $removed_item['variation_id'] ?? 0;
		if ( $variation_id ) {
			$properties['variation_id'] = $variation_id;
		}

		if ( $product ) {
			$properties['price'] = (float) $product->get_price();
			$properties['sku']   = $product->get_sku();
		}

		$this->send_event(
			$this->build_track_event( 'remove_from_cart', $identity, $properties )
		);
	}

	/**
	 * Handle the `woocommerce_checkout_order_processed` action.
	 *
	 * This is an identity stitching event, not a track event. It fires at the
	 * moment of purchase (before the async webhook) and bridges anonymous
	 * browsing to the known customer by:
	 *
	 * 1. Merging billing email + phone into the sf_id cookie.
	 * 2. Sending an identify event with the billing identity.
	 *
	 * @param int      $order_id    The order ID.
	 * @param array    $posted_data The posted checkout form data.
	 * @param WC_Order $order       The order object.
	 */
	public function on_checkout( int $order_id, array $posted_data, WC_Order $order ): void {
		// Stamp the WC session ID onto the order as meta so it is included
		// in the REST API / webhook payload (meta_data array).  This is
		// the WooCommerce equivalent of Shopify's cart_token — it links
		// the anonymous browsing session to the specific order.
		if ( function_exists( 'WC' ) && WC()->session ) {
			$session_id = (string) WC()->session->get_customer_id();
			if ( $session_id ) {
				$order->update_meta_data( '_segmentflow_session_id', $session_id );
				$order->save_meta_data();
			}
		}

		// Stamp UTM first-touch attribution onto the order as meta so the
		// Segmentflow backend can read it from the webhook payload.  The
		// `sf_utm` cookie is set client-side by storefront.ts on the
		// landing page; we read it here at checkout.
		$this->stamp_utm_meta( $order );
		$this->stamp_locale_meta( $order );

		// Extract billing identity from the order.
		$billing_email = $order->get_billing_email();
		$billing_phone = $order->get_billing_phone();

		// Merge billing identity into the cookie for subsequent requests.
		if ( $billing_email ) {
			Segmentflow_Identity_Cookie::set_email( $billing_email );
		}
		if ( $billing_phone ) {
			Segmentflow_Identity_Cookie::set_phone( $billing_phone );
		}

		// If the customer has a WP account, update userId in cookie.
		$customer_id      = $order->get_customer_id();
		$user_id_prefixed = $customer_id ? 'wc_' . $customer_id : null;
		if ( $user_id_prefixed ) {
			Segmentflow_Identity_Cookie::write( [ 'u' => $user_id_prefixed ] );
		}

		// Resolve identity: cookie when consent is granted, billing data
		// from the order itself when the visitor checked out before
		// answering the banner.
		$identity = $this->resolve_identity( $billing_email, $user_id_prefixed );
		if ( ! $identity ) {
			return;
		}

		// Build identify event with billing traits.
		$traits = [];
		if ( $billing_email ) {
			$traits['email'] = $billing_email;
		}
		if ( $billing_phone ) {
			$traits['phone'] = $billing_phone;
		}

		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();
		if ( $first_name ) {
			$traits['first_name'] = $first_name;
		}
		if ( $last_name ) {
			$traits['last_name'] = $last_name;
		}

		$event = [
			'type'      => 'identify',
			'traits'    => $traits,
			'source'    => self::EVENT_SOURCE,
			'timestamp' => gmdate( 'c' ),
		];
		if ( ! empty( $identity['a'] ) ) {
			$event['anonymousId'] = $identity['a'];
		}
		if ( ! empty( $identity['u'] ) ) {
			$event['userId'] = $identity['u'];
		}

		$this->send_event( $event );
	}

	/**
	 * Handle the `woocommerce_store_api_checkout_order_processed` action.
	 *
	 * Blocks checkout does not always fire `woocommerce_checkout_order_processed`,
	 * so locale meta is stamped here as well. Identity stitching remains on the
	 * classic hook only.
	 *
	 * @param WC_Order $order The order object.
	 */
	public function on_blocks_checkout( WC_Order $order ): void {
		$this->stamp_locale_meta( $order );
	}

	/**
	 * UTM cookie name written by storefront.ts.
	 */
	const UTM_COOKIE_NAME = 'sf_utm';

	/**
	 * Allowed UTM keys. Match the five standard UTM parameters.
	 *
	 * @var string[]
	 */
	private const UTM_KEYS = [ 'source', 'medium', 'campaign', 'content', 'term' ];

	/**
	 * Stamp UTM first-touch attribution from the `sf_utm` cookie onto the
	 * order as `_segmentflow_utm_*` meta keys. These meta fields are then
	 * included automatically in the WC REST / webhook payload and parsed
	 * by the Segmentflow backend.
	 *
	 * Silently no-ops if the cookie is absent or malformed.
	 *
	 * @param WC_Order $order The order being processed at checkout.
	 */
	private function stamp_utm_meta( WC_Order $order ): void {
		if ( empty( $_COOKIE[ self::UTM_COOKIE_NAME ] ) ) {
			return;
		}

		$raw     = sanitize_text_field( wp_unslash( $_COOKIE[ self::UTM_COOKIE_NAME ] ) );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return;
		}

		$changed = false;
		foreach ( self::UTM_KEYS as $key ) {
			if ( empty( $decoded[ $key ] ) || ! is_string( $decoded[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( $decoded[ $key ] );
			if ( '' === $value ) {
				continue;
			}
			$order->update_meta_data( '_segmentflow_utm_' . $key, $value );
			$changed = true;
		}

		if ( $changed ) {
			$order->save_meta_data();
		}
	}

	/**
	 * Stamp the resolved request locale onto the order as `_segmentflow_locale`.
	 *
	 * Idempotent: does not overwrite an existing non-empty value or write empty data.
	 *
	 * @param WC_Order $order The order being processed at checkout.
	 */
	private function stamp_locale_meta( WC_Order $order ): void {
		$existing = $order->get_meta( Segmentflow_WC_Helper::LOCALE_META_KEY );
		if ( is_string( $existing ) && '' !== $existing ) {
			return;
		}

		$customer_id = $order->get_customer_id();
		$user_id     = $customer_id > 0 ? $customer_id : null;
		$locale      = Segmentflow_WC_Helper::resolve_locale( $user_id );
		if ( '' === $locale ) {
			return;
		}

		$order->update_meta_data( Segmentflow_WC_Helper::LOCALE_META_KEY, $locale );
		$order->save_meta_data();
	}

	/**
	 * Resolve identity for a server-fired event.
	 *
	 * Cart events (add/remove) need the `sf_id` anchor since the only
	 * available identity at those hooks is anonymous browse identity.
	 * Checkout has explicit billing data, so callers pass the email and
	 * (optionally) prefixed user ID and we fall back to those when the
	 * cookie is absent — order placement is a contractual basis under
	 * GDPR Art. 6(1)(b) so it fires regardless of cookie consent.
	 *
	 * @param string|null $hook_email   Email surfaced by the hook (billing email).
	 * @param string|null $hook_user_id Already-prefixed user ID (e.g. "wc_42").
	 * @return array<string, string>|null
	 */
	private function resolve_identity( ?string $hook_email, ?string $hook_user_id ): ?array {
		$cookie = Segmentflow_Identity_Cookie::read();
		if ( $cookie && ! empty( $cookie['a'] ) ) {
			return $cookie;
		}

		$fallback = [];
		if ( $hook_user_id ) {
			$fallback['u'] = $hook_user_id;
		}
		if ( $hook_email && is_email( $hook_email ) ) {
			$fallback['e'] = sanitize_email( $hook_email );
			if ( empty( $fallback['u'] ) ) {
				$fallback['u'] = $fallback['e'];
			}
		}

		return empty( $fallback['u'] ) ? null : $fallback;
	}

	/**
	 * Build a track event payload.
	 *
	 * @param string                $event_name Event name (e.g. 'add_to_cart').
	 * @param array<string, string> $identity   Identity data from sf_id cookie or hook fallback.
	 * @param array<string, mixed>  $properties Event properties.
	 * @return array<string, mixed> The event payload.
	 */
	private function build_track_event( string $event_name, array $identity, array $properties ): array {
		$event = [
			'type'       => 'track',
			'event'      => $event_name,
			'properties' => $properties,
			'source'     => self::EVENT_SOURCE,
			'timestamp'  => gmdate( 'c' ),
		];

		if ( ! empty( $identity['a'] ) ) {
			$event['anonymousId'] = $identity['a'];
		}
		if ( ! empty( $identity['u'] ) ) {
			$event['userId'] = $identity['u'];
		}

		return $event;
	}

	/**
	 * Read consent flags currently recorded in `sf_consent`.
	 *
	 * @return array{analytics: bool, marketing: bool}|null
	 */
	private function get_consent_payload(): ?array {
		if ( ! Segmentflow_Consent_Cookie::is_set() ) {
			return null;
		}
		return [
			'analytics' => Segmentflow_Consent_Cookie::has_consent( 'analytics' ),
			'marketing' => Segmentflow_Consent_Cookie::has_consent( 'marketing' ),
		];
	}

	/**
	 * Send an event to the ingest API.
	 *
	 * Uses fire-and-forget (non-blocking) POST to /api/v1/ingest/batch.
	 * The 0.5s timeout is a safety net — the response is not awaited.
	 *
	 * @param array<string, mixed> $event The event payload.
	 */
	private function send_event( array $event ): void {
		$write_key = $this->options->get_write_key();
		if ( empty( $write_key ) ) {
			return;
		}

		$payload = [
			'writeKey' => $write_key,
			'batch'    => [ $event ],
		];

		$consent = $this->get_consent_payload();
		if ( null !== $consent ) {
			$payload['consent'] = $consent;
		}

		$this->api->request(
			'POST',
			'/api/v1/ingest/batch',
			$payload,
			[],
			[ 'blocking' => false ]
		);
	}
}
