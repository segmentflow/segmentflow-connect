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
		if ( ! class_exists( 'Segmentflow_Helper' ) ) {
			require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-helper.php';
		}

		$options = new Segmentflow_Options();
		$options->create_defaults();

		// Store activation timestamp for diagnostics.
		add_option( 'segmentflow_activated_at', current_time( 'mysql', true ) );

		// Re-register WooCommerce webhooks if previously connected.
		if ( Segmentflow_Helper::is_woocommerce_active() && $options->is_connected() ) {
			if ( ! class_exists( 'Segmentflow_WC_Webhooks' ) ) {
				require_once SEGMENTFLOW_PATH . 'integrations/woocommerce/class-segmentflow-wc-webhooks.php';
			}
			$wc_webhooks = new Segmentflow_WC_Webhooks( $options );
			$wc_webhooks->register_webhooks();
		}
	}

	/**
	 * Plugin deactivation handler.
	 *
	 * Stops SDK injection only. Connection settings and write key are preserved
	 * for reactivation. Webhooks and API connection remain active on the
	 * Segmentflow side.
	 */
	public function deactivate(): void {
		// Remove WooCommerce webhooks on deactivation so they don't fire
		// while the plugin is inactive (webhooks re-register on reconnect).
		if ( Segmentflow_Helper::is_woocommerce_active() && class_exists( 'Segmentflow_WC_Webhooks' ) ) {
			$options     = new Segmentflow_Options();
			$wc_webhooks = new Segmentflow_WC_Webhooks( $options );
			$wc_webhooks->remove_webhooks();
		}
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
		$options = new Segmentflow_Options();

		// If already connected as WooCommerce with webhook credentials,
		// register webhooks now that WC is available.
		if ( $options->is_connected() && $options->get( 'webhook_secret' ) ) {
			if ( ! class_exists( 'Segmentflow_WC_Webhooks' ) ) {
				require_once SEGMENTFLOW_PATH . 'integrations/woocommerce/class-segmentflow-wc-webhooks.php';
			}
			$wc_webhooks = new Segmentflow_WC_Webhooks( $options );
			$wc_webhooks->register_webhooks();
		}

		// If connected as a plain WordPress site, show an admin notice
		// suggesting WC connection upgrade.
		if ( $options->is_connected() && 'wordpress' === $options->get_connected_platform() ) { // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Platform identifier.
			set_transient( 'segmentflow_wc_upgrade_notice', true, 0 );
		}
	}
}
