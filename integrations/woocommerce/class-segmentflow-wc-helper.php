<?php
/**
 * Segmentflow WooCommerce helper utilities.
 *
 * WooCommerce-specific utility functions. Only loaded when WooCommerce is active.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_WC_Helper
 *
 * Provides WooCommerce-specific utility methods.
 */
class Segmentflow_WC_Helper {

	/**
	 * Get the WooCommerce version.
	 *
	 * @return string|null The WC version string, or null if not available.
	 */
	public static function get_wc_version(): ?string {
		if ( defined( 'WC_VERSION' ) ) {
			return WC_VERSION;
		}
		return null;
	}

	/**
	 * Check if WooCommerce meets the minimum version requirement.
	 *
	 * @param string $min_version Minimum required version (e.g., '5.0').
	 * @return bool
	 */
	public static function meets_min_version( string $min_version = '5.0' ): bool {
		$version = self::get_wc_version();
		if ( null === $version ) {
			return false;
		}
		return version_compare( $version, $min_version, '>=' );
	}

	/**
	 * Get the store currency.
	 *
	 * @return string The ISO 4217 currency code.
	 */
	public static function get_currency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			return get_woocommerce_currency();
		}
		return 'USD';
	}

	/**
	 * Get the current cart hash.
	 *
	 * @return string|null The cart hash, or null if cart is not available.
	 */
	public static function get_cart_hash(): ?string {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			return WC()->cart->get_cart_hash();
		}
		return null;
	}

	/**
	 * Check if HPOS (High-Performance Order Storage) is enabled.
	 *
	 * @return bool
	 */
	public static function is_hpos_enabled(): bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Extract product data for tracking.
	 *
	 * @param WC_Product $product The product object.
	 * @return array<string, mixed> Product data array.
	 */
	public static function get_product_data( WC_Product $product ): array {
		return [
			'id'         => $product->get_id(),
			'name'       => $product->get_name(),
			'price'      => $product->get_price(),
			'sku'        => $product->get_sku(),
			'categories' => wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
			'image_url'  => wp_get_attachment_url( $product->get_image_id() ) ? wp_get_attachment_url( $product->get_image_id() ) : '',
			'url'        => $product->get_permalink(),
			'type'       => $product->get_type(),
		];
	}

	/**
	 * Extract cart data for tracking.
	 *
	 * @return array<string, mixed>|null Cart data array, or null if cart is empty.
	 */
	public static function get_cart_data(): ?array {
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return null;
		}

		$items = [];
		foreach ( $cart->get_cart() as $cart_item_key => $item ) {
			$product = $item['data'];
			$items[] = [
				'cart_item_key' => $cart_item_key,
				'product_id'    => $item['product_id'],
				'variation_id'  => $item['variation_id'] ?? 0,
				'name'          => $product->get_name(),
				'quantity'      => $item['quantity'],
				'price'         => $product->get_price(),
				'sku'           => $product->get_sku(),
				'image_url'     => wp_get_attachment_url( $product->get_image_id() ) ? wp_get_attachment_url( $product->get_image_id() ) : '',
				'url'           => $product->get_permalink(),
			];
		}

		return [
			'items'      => $items,
			'total'      => $cart->get_total( 'raw' ),
			'subtotal'   => $cart->get_subtotal(),
			'item_count' => $cart->get_cart_contents_count(),
			'cart_hash'  => $cart->get_cart_hash(),
		];
	}

	/**
	 * Extract order data for tracking.
	 *
	 * Order lifecycle events (order_completed, order_paid, etc.) are handled
	 * server-side by WooCommerce webhooks with Redis-based deduplication.
	 * This method provides order data for any remaining client-side needs.
	 *
	 * @param WC_Order $order The order object.
	 * @return array<string, mixed> Order data array.
	 */
	public static function get_order_data( WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = [
				'product_id' => $item->get_product_id(),
				'name'       => $item->get_name(),
				'quantity'   => $item->get_quantity(),
				'price'      => $order->get_item_total( $item, false, true ),
				'sku'        => $product ? $product->get_sku() : '',
			];
		}

		return [
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'total'          => $order->get_total(),
			'subtotal'       => $order->get_subtotal(),
			'tax'            => $order->get_total_tax(),
			'shipping'       => $order->get_shipping_total(),
			'discount'       => $order->get_total_discount(),
			'payment_method' => $order->get_payment_method_title(),
			'currency'       => $order->get_currency(),
			'billing_email'  => $order->get_billing_email(),
			'items'          => $items,
			'coupon_codes'   => $order->get_coupon_codes(),
		];
	}
}
