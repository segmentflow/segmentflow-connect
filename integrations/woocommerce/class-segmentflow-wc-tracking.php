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
		add_filter( 'segmentflow_tracking_context', [ $this, 'add_woocommerce_context' ] );
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
}
