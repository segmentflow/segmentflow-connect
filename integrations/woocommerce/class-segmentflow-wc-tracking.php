<?php
/**
 * Segmentflow WooCommerce tracking enrichment.
 *
 * Enriches the core SDK injection with WooCommerce-specific context
 * (cart hash, currency, WC customer ID) via the 'segmentflow_tracking_context' filter.
 *
 * This file is only loaded when WooCommerce is active (conditional loading
 * in the orchestrator).
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_WC_Tracking
 *
 * Adds WooCommerce context to the Segmentflow SDK injection.
 * Hooks into the 'segmentflow_tracking_context' filter to provide:
 * - Platform identifier ('woocommerce')
 * - WC customer ID in traits
 * - Cart hash, currency, and store URL in context
 */
class Segmentflow_WC_Tracking {

	/**
	 * Options instance.
	 */
	private Segmentflow_Options $options;

	/**
	 * Core tracking instance.
	 */
	private Segmentflow_Tracking $tracking;

	/**
	 * Constructor.
	 *
	 * @param Segmentflow_Options  $options  The options instance.
	 * @param Segmentflow_Tracking $tracking The core tracking instance.
	 */
	public function __construct( Segmentflow_Options $options, Segmentflow_Tracking $tracking ) {
		$this->options  = $options;
		$this->tracking = $tracking;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		// Enrich SDK context with WooCommerce data.
		add_filter( 'segmentflow_tracking_context', [ $this, 'add_woocommerce_context' ] );

		// Inject window.__sf_wc page data before the SDK loads (priority 0 < SDK priority 1).
		add_action( 'wp_head', [ $this, 'inject_page_data' ], 0 );
	}

	/**
	 * Add WooCommerce context to the tracking context.
	 *
	 * This filter is applied in class-segmentflow-tracking.php when building
	 * the SDK injection script. It adds WC-specific data that the core
	 * tracking class doesn't know about.
	 *
	 * @param array<string, mixed> $context The existing tracking context.
	 * @return array<string, mixed> The enriched tracking context.
	 */
	public function add_woocommerce_context( array $context ): array {
		$context['platform'] = 'woocommerce';

		// WooCommerce customer identity traits.
		$context['traits'] = [
			'woocommerce_customer_id' => get_current_user_id(),
		];

		// WooCommerce store context.
		$cart_hash = null;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$cart_hash = WC()->cart->get_cart_hash();
		}

		$context['context'] = [
			'store_url' => home_url(),
			'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
			'cart_hash' => $cart_hash,
		];

		return $context;
	}

	/**
	 * Inject WooCommerce page data into window.__sf_wc.
	 *
	 * Renders a <script> tag at wp_head priority 0 (before the SDK at priority 1)
	 * containing structured page data that the SDK's WooCommercePlugin reads
	 * to fire track events.
	 */
	public function inject_page_data(): void {
		// Only inject on the frontend.
		if ( is_admin() ) {
			return;
		}

		$page_type = $this->get_page_type();

		$data = [
			'page'     => $page_type,
			'currency' => Segmentflow_WC_Helper::get_currency(),
		];

		if ( 'product' === $page_type ) {
			$product_data = $this->get_product_data();
			if ( $product_data ) {
				$data['product'] = $product_data;
			}
		}

		if ( in_array( $page_type, [ 'cart', 'checkout' ], true ) ) {
			$cart_data = Segmentflow_WC_Helper::get_cart_data();
			if ( $cart_data ) {
				$data['cart'] = $cart_data;
			}
		}

		if ( 'thankyou' === $page_type ) {
			$order_data = $this->get_order_data();
			if ( $order_data ) {
				$data['order'] = $order_data;
			}
		}

		printf(
			'<script>window.__sf_wc = %s;</script>' . "\n",
			wp_json_encode( $data )
		);
	}

	/**
	 * Detect the current WooCommerce page type.
	 *
	 * @return string The page type identifier.
	 */
	private function get_page_type(): string {
		if ( is_product() ) {
			return 'product';
		}
		if ( is_cart() ) {
			return 'cart';
		}
		if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			return 'checkout';
		}
		if ( is_wc_endpoint_url( 'order-received' ) ) {
			return 'thankyou';
		}
		if ( is_shop() ) {
			return 'shop';
		}
		if ( is_product_category() || is_product_tag() ) {
			return 'category';
		}
		return 'other';
	}

	/**
	 * Get product data for the current single product page.
	 *
	 * @return array<string, mixed>|null Product data, or null if not on a product page.
	 */
	private function get_product_data(): ?array {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		return Segmentflow_WC_Helper::get_product_data( $product );
	}

	/**
	 * Get order data for the current thank-you page.
	 *
	 * @return array<string, mixed>|null Order data, or null if order not found.
	 */
	private function get_order_data(): ?array {
		global $wp;
		$order_id = absint( $wp->query_vars['order-received'] ?? 0 );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		return Segmentflow_WC_Helper::get_order_data( $order );
	}
}
