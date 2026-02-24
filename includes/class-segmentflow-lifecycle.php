<?php
/**
 * Segmentflow lifecycle handler.
 *
 * Manages plugin activation, deactivation, uninstall, and late activation
 * detection (e.g., WooCommerce installed after this plugin).
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Lifecycle
 *
 * Handles lifecycle events for the Segmentflow Connect plugin.
 */
class Segmentflow_Lifecycle {

	/**
	 * Plugin activation handler.
	 *
	 * Sets default option values. Does NOT require WooCommerce -- the plugin
	 * works on any WordPress site. WooCommerce features activate conditionally
	 * when WooCommerce is detected.
	 */
	public function activate(): void {
		// Ensure dependencies are available.
		if ( ! class_exists( 'Segmentflow_Options' ) ) {
			require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-options.php';
		}

		$options = new Segmentflow_Options();
		$options->create_defaults();

		// Store activation timestamp for diagnostics.
		add_option( 'segmentflow_activated_at', current_time( 'mysql', true ) );
	}

	/**
	 * Plugin deactivation handler.
	 *
	 * Stops SDK injection only. Connection settings and write key are preserved
	 * for reactivation. Webhooks and API connection remain active on the
	 * Segmentflow side.
	 */
	public function deactivate(): void {
		// Nothing to do -- deactivation simply stops wp_head hook from firing.
		// The write key and connection settings are preserved for reactivation.
	}

	/**
	 * Register late activation detection hooks.
	 *
	 * Detects when WooCommerce is activated AFTER this plugin. Allows the
	 * orchestrator to initialize WooCommerce features dynamically.
	 */
	public function register_hooks(): void {
		add_action( 'activated_plugin', [ $this, 'check_for_dependency' ], 10, 2 );
	}

	/**
	 * Handle a plugin being activated after Segmentflow Connect.
	 *
	 * If WooCommerce was just activated and we're already connected, prompt
	 * the user to upgrade their connection with WC auto-auth.
	 *
	 * @param string $plugin  Path to the plugin file relative to the plugins directory.
	 * @param bool   $network Whether this is a network-wide activation.
	 */
	public function check_for_dependency( string $plugin, bool $network ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by activated_plugin hook signature.
		if ( 'woocommerce/woocommerce.php' !== $plugin ) {
			return;
		}

		// WooCommerce was just activated.
		// If we're already connected as a plain WordPress site, set a transient
		// to show an admin notice suggesting WC connection upgrade.
		$options = new Segmentflow_Options();
		if ( $options->is_connected() && 'wordpress' === $options->get_connected_platform() ) { // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Platform identifier.
			set_transient( 'segmentflow_wc_upgrade_notice', true, 0 );
		}
	}
}
