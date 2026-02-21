<?php
/**
 * Segmentflow helper utilities.
 *
 * Static methods for integration detection and common utilities.
 * Follows the Smaily Connect pattern: each integration has a detection method.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Helper
 *
 * Provides static helper methods for detecting active integrations
 * and common utility functions used throughout the plugin.
 */
class Segmentflow_Helper {

	/**
	 * Check if WooCommerce is active.
	 *
	 * Uses is_plugin_active() when available (admin context), falls back to
	 * class_exists() for front-end and REST API contexts.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active(): bool {
		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( 'woocommerce/woocommerce.php' );
		}
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Get the active platform identifier.
	 *
	 * Returns the most specific platform identifier for the current environment.
	 * Used to determine user ID prefix and connection flow behavior.
	 *
	 * @return string 'woocommerce' | 'wordpress'
	 */
	public static function get_platform(): string {
		return self::is_woocommerce_active() ? 'woocommerce' : 'wordpress';
	}

	/**
	 * Sanitize a URL for storage.
	 *
	 * Ensures the URL has a scheme and removes trailing slashes.
	 *
	 * @param string $url The URL to sanitize.
	 * @return string The sanitized URL.
	 */
	public static function sanitize_url( string $url ): string {
		$url = trim( $url );

		// Add https:// if no scheme is present.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		return untrailingslashit( esc_url_raw( $url ) );
	}

	// Future detection methods:
	// public static function is_cf7_active(): bool { ... }
	// public static function is_elementor_active(): bool { ... }
	// public static function is_gravity_forms_active(): bool { ... }
	// public static function is_wpforms_active(): bool { ... }
}
