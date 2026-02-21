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
}
