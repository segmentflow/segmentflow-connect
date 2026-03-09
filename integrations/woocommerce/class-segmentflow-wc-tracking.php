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

		// Inject window.__sf_wc page data before the SDK initializes.
		// Runs during wp_enqueue_scripts at priority 6, after enqueue_sdk() at
		// priority 5, so wp_script_is() can confirm the SDK handle is registered.
		// wp_add_inline_script( ..., 'before' ) ensures the global is set before
		// the SDK's inline init script runs.
		add_action( 'wp_enqueue_scripts', [ $this, 'inject_page_data' ], 6 );
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
	 * Uses wp_add_inline_script() with position 'before' on the SDK handle so
	 * the window.__sf_wc global is set before the SDK's inline init script runs.
	 * Hooked to wp_enqueue_scripts at priority 5, after the SDK is enqueued at
	 * the default priority (10) via enqueue_sdk().
	 *
	 * Note: wp_add_inline_script() can be called any time before wp_head fires,
	 * even if the handle was enqueued at a later hook priority, because WordPress
	 * collects all inline scripts and outputs them alongside the registered handle.
	 */
	public function inject_page_data(): void {
		// Only inject on the frontend.
		if ( is_admin() ) {
			return;
		}

		// Only attach if the SDK handle is going to be enqueued (i.e. connected).
		if ( ! wp_script_is( Segmentflow_Tracking::SDK_HANDLE, 'enqueued' ) &&
			! wp_script_is( Segmentflow_Tracking::SDK_HANDLE, 'registered' ) ) {
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

		// Security: wp_json_encode() safely encodes PHP values for JS injection.
		$inline_js = 'window.__sf_wc = ' . wp_json_encode( $data ) . ';';

		wp_add_inline_script( Segmentflow_Tracking::SDK_HANDLE, $inline_js, 'before' );
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
}
