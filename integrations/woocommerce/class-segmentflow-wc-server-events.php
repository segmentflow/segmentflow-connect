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
		$identity = $this->get_identity();
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
		$identity = $this->get_identity();
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
		$identity = $this->get_identity();
		if ( ! $identity ) {
			return;
		}

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
		$customer_id = $order->get_customer_id();
		if ( $customer_id ) {
			Segmentflow_Identity_Cookie::write( [ 'u' => 'wc_' . $customer_id ] );
		}

		// Re-read identity after cookie updates to get the most complete picture.
		$updated_identity = Segmentflow_Identity_Cookie::read();
		if ( ! $updated_identity ) {
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
			'type'        => 'identify',
			'userId'      => $updated_identity['u'] ?? null,
			'anonymousId' => $updated_identity['a'],
			'traits'      => $traits,
			'source'      => self::EVENT_SOURCE,
			'timestamp'   => gmdate( 'c' ),
		];

		// Remove null userId to keep payload clean.
		if ( null === $event['userId'] ) {
			unset( $event['userId'] );
		}

		$this->send_event( $event );
	}

	/**
	 * Read identity from the sf_id cookie.
	 *
	 * Returns null if the cookie is missing or has no anonymous ID,
	 * which means the visitor is unidentifiable. Events are silently
	 * dropped in this case (same behavior as Klaviyo).
	 *
	 * @return array<string, string>|null Identity data, or null if unavailable.
	 */
	private function get_identity(): ?array {
		$identity = Segmentflow_Identity_Cookie::read();

		if ( ! $identity || empty( $identity['a'] ) ) {
			return null;
		}

		return $identity;
	}

	/**
	 * Build a track event payload.
	 *
	 * @param string               $event_name Event name (e.g. 'add_to_cart').
	 * @param array<string, string> $identity   Identity data from sf_id cookie.
	 * @param array<string, mixed>  $properties Event properties.
	 * @return array<string, mixed> The event payload.
	 */
	private function build_track_event( string $event_name, array $identity, array $properties ): array {
		$event = [
			'type'        => 'track',
			'event'       => $event_name,
			'userId'      => $identity['u'] ?? null,
			'anonymousId' => $identity['a'],
			'properties'  => $properties,
			'source'      => self::EVENT_SOURCE,
			'timestamp'   => gmdate( 'c' ),
		];

		// Remove null userId to keep payload clean.
		if ( null === $event['userId'] ) {
			unset( $event['userId'] );
		}

		return $event;
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

		$this->api->request(
			'POST',
			'/api/v1/ingest/batch',
			[
				'writeKey' => $write_key,
				'batch'    => [ $event ],
			],
			[],
			[ 'blocking' => false ]
		);
	}
}
