<?php
/**
 * Segmentflow WooCommerce server-side events.
 *
 * Fires identified cart mutation and checkout identity events from PHP
 * hooks through Segmentflow_Ingest_Client. Guest cart activity before
 * email is skipped. Classic and Blocks checkout share one order-derived
 * identify occurrence.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_WC_Server_Events
 *
 * Hooks into WooCommerce actions:
 * - `woocommerce_add_to_cart`                         -> `add_to_cart` track
 * - `woocommerce_cart_item_removed`                   -> `remove_from_cart` track
 * - `woocommerce_checkout_order_processed`            -> checkout identify
 * - `woocommerce_store_api_checkout_order_processed`  -> checkout identify
 */
class Segmentflow_WC_Server_Events {

	/**
	 * Event source identifier for WooCommerce hook events.
	 */
	const EVENT_SOURCE = 'woocommerce';

	/**
	 * UTM cookie name written by storefront.ts.
	 */
	const UTM_COOKIE_NAME = 'sf_utm';

	/**
	 * Allowed UTM keys.
	 *
	 * @var string[]
	 */
	private const UTM_KEYS = [ 'source', 'medium', 'campaign', 'content', 'term' ];

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * Identified ingest client.
	 *
	 * @var Segmentflow_Ingest_Client
	 */
	private Segmentflow_Ingest_Client $ingest_client;

	/**
	 * Constructor.
	 *
	 * @param Segmentflow_Options       $options       The options instance.
	 * @param Segmentflow_Ingest_Client $ingest_client The identified ingest client.
	 */
	public function __construct( Segmentflow_Options $options, Segmentflow_Ingest_Client $ingest_client ) {
		$this->options       = $options;
		$this->ingest_client = $ingest_client;
	}

	/**
	 * Register WooCommerce action hooks.
	 */
	public function register_hooks(): void {
		if ( ! $this->options->is_connected() ) {
			return;
		}

		add_action( 'woocommerce_add_to_cart', [ $this, 'on_add_to_cart' ], 25, 6 );
		add_action( 'woocommerce_cart_item_removed', [ $this, 'on_remove_from_cart' ], 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_checkout' ], 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_blocks_checkout' ], 10, 1 );
	}

	/**
	 * Handle the `woocommerce_add_to_cart` action.
	 *
	 * @param string $cart_item_key  Cart item key.
	 * @param int    $product_id     Product ID.
	 * @param int    $quantity       Quantity added.
	 * @param int    $variation_id   Variation ID (0 if not a variation).
	 * @param array  $variation      Variation attributes (unused).
	 * @param array  $cart_item_data Additional cart item data (unused).
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook callback must accept all parameters from woocommerce_add_to_cart action.
	public function on_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
		$identity = $this->resolve_logged_in_customer();
		if ( null === $identity ) {
			return;
		}

		$occurrence_uuid     = Segmentflow_Ingest_Event::generate_uuidv7();
		$resolved_product_id = $variation_id ? $variation_id : $product_id;
		$product             = wc_get_product( $resolved_product_id );
		if ( ! $product ) {
			return;
		}

		$properties = [
			'product_id'    => $product_id,
			'name'          => $product->get_name(),
			'price'         => (float) $product->get_price(),
			'sku'           => $product->get_sku(),
			'quantity'      => $quantity,
			'currency'      => get_woocommerce_currency(),
			'cart_item_key' => $cart_item_key,
		];

		if ( $variation_id ) {
			$properties['variation_id'] = $variation_id;
		}

		$cart_hash = $this->get_cart_hash();
		if ( '' !== $cart_hash ) {
			$properties['cart_hash'] = $cart_hash;
		}

		$item = Segmentflow_Ingest_Event::track(
			'add_to_cart',
			Segmentflow_Ingest_Event::occurrence_message_id( 'woocommerce:cart:add', $occurrence_uuid ),
			$identity['email'],
			$identity['userId'],
			$properties,
			gmdate( 'c' ),
			self::EVENT_SOURCE
		);

		$this->send_items( [ $item ] );
	}

	/**
	 * Handle the `woocommerce_cart_item_removed` action.
	 *
	 * @param string $cart_item_key Cart item key of the removed item.
	 * @param object $cart          The WC_Cart instance.
	 */
	public function on_remove_from_cart( string $cart_item_key, object $cart ): void {
		$identity = $this->resolve_logged_in_customer();
		if ( null === $identity ) {
			return;
		}

		$occurrence_uuid = Segmentflow_Ingest_Event::generate_uuidv7();
		$removed_items   = method_exists( $cart, 'get_removed_cart_contents' ) ? $cart->get_removed_cart_contents() : [];
		if ( ! isset( $removed_items[ $cart_item_key ] ) ) {
			return;
		}

		$removed_item = $removed_items[ $cart_item_key ];
		$product_id   = (int) ( $removed_item['product_id'] ?? 0 );
		$product      = $product_id ? wc_get_product( $product_id ) : false;

		$properties = [
			'product_id'    => $product_id,
			'name'          => $product ? $product->get_name() : '',
			'quantity'      => $removed_item['quantity'] ?? 1,
			'currency'      => get_woocommerce_currency(),
			'cart_item_key' => $cart_item_key,
		];

		$variation_id = (int) ( $removed_item['variation_id'] ?? 0 );
		if ( $variation_id ) {
			$properties['variation_id'] = $variation_id;
		}

		if ( $product ) {
			$properties['price'] = (float) $product->get_price();
			$properties['sku']   = $product->get_sku();
		}

		$cart_hash = $this->get_cart_hash();
		if ( '' !== $cart_hash ) {
			$properties['cart_hash'] = $cart_hash;
		}

		$item = Segmentflow_Ingest_Event::track(
			'remove_from_cart',
			Segmentflow_Ingest_Event::occurrence_message_id( 'woocommerce:cart:remove', $occurrence_uuid ),
			$identity['email'],
			$identity['userId'],
			$properties,
			gmdate( 'c' ),
			self::EVENT_SOURCE
		);

		$this->send_items( [ $item ] );
	}

	/**
	 * Handle classic checkout order processed.
	 *
	 * @param int      $order_id    The order ID.
	 * @param array    $posted_data The posted checkout form data.
	 * @param WC_Order $order       The order object.
	 */
	public function on_checkout( int $order_id, array $posted_data, WC_Order $order ): void {
		$this->handle_checkout_identity( $order );
	}

	/**
	 * Handle Blocks / Store API checkout order processed.
	 *
	 * @param WC_Order $order The order object.
	 */
	public function on_blocks_checkout( WC_Order $order ): void {
		$this->handle_checkout_identity( $order );
	}

	/**
	 * Shared checkout identity + metadata path for classic and Blocks.
	 *
	 * @param WC_Order $order The order object.
	 */
	private function handle_checkout_identity( WC_Order $order ): void {
		// Session ID is order metadata for trusted order consumers only —
		// it is not a customer identity locator.
		if ( function_exists( 'WC' ) && WC()->session ) {
			$session_id = (string) WC()->session->get_customer_id();
			if ( $session_id ) {
				$order->update_meta_data( '_segmentflow_session_id', $session_id );
				$order->save_meta_data();
			}
		}

		$this->stamp_utm_meta( $order );
		$this->stamp_locale_meta( $order );

		$email = Segmentflow_Ingest_Event::normalize_email( (string) $order->get_billing_email() );
		if ( '' === $email ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		$user_id     = $customer_id > 0 ? 'wc_' . $customer_id : null;
		$order_id    = (int) $order->get_id();
		$canonical   = 'woocommerce:checkout:identify:' . $order_id;
		$timestamp   = gmdate( 'c' );

		$traits = [ 'email' => $email ];
		$phone  = $order->get_billing_phone();
		if ( $phone ) {
			$traits['phone'] = $phone;
		}
		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();
		if ( $first_name ) {
			$traits['first_name'] = $first_name;
		}
		if ( $last_name ) {
			$traits['last_name'] = $last_name;
		}

		$item = Segmentflow_Ingest_Event::identify(
			$email,
			Segmentflow_Ingest_Event::deterministic_message_id( 'woocommerce:checkout:identify', $canonical ),
			$user_id,
			$traits,
			$timestamp,
			self::EVENT_SOURCE
		);

		$this->send_items( [ $item ] );
	}

	/**
	 * Stamp UTM first-touch attribution onto the order.
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
	 * Stamp the resolved request locale onto the order.
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
	 * Resolve logged-in WooCommerce customer identity for cart mutations.
	 *
	 * @return array{email: string, userId: string}|null
	 */
	private function resolve_logged_in_customer(): ?array {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$user  = wp_get_current_user();
		$email = Segmentflow_Ingest_Event::normalize_email( (string) $user->user_email );
		if ( '' === $email ) {
			return null;
		}

		return [
			'email'  => $email,
			'userId' => 'wc_' . (int) $user->ID,
		];
	}

	/**
	 * Optional cart hash property (never used as identity).
	 *
	 * @return string
	 */
	private function get_cart_hash(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}
		return (string) WC()->cart->get_cart_hash();
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
	 * Send pre-built identified items through the ingest client.
	 *
	 * @param array<int, array<string, mixed>> $items Identified ingest items.
	 */
	private function send_items( array $items ): void {
		if ( empty( $items ) || empty( $this->options->get_write_key() ) ) {
			return;
		}

		$this->ingest_client->send_batch( $items, $this->get_consent_payload() );
	}
}
