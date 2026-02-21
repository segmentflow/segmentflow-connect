<?php
/**
 * Segmentflow admin settings page.
 *
 * Registers a settings tab under WooCommerce > Settings > Segmentflow.
 *
 * @package Segmentflow_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Admin
 *
 * Manages the Segmentflow settings tab under WooCommerce > Settings.
 * Uses the WooCommerce Settings API (WC_Settings_Page).
 *
 * States:
 * - Not connected: Shows "Connect to Segmentflow" button.
 * - Connected: Shows organization name, sync status, write key (masked), disconnect button.
 * - Settings: Debug mode toggle, consent required toggle, API host override.
 */
class Segmentflow_Admin {

	/**
	 * Initialize the admin settings.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_settings_tabs_array', [ __CLASS__, 'add_settings_tab' ], 50 );
		add_action( 'woocommerce_settings_tabs_segmentflow', [ __CLASS__, 'render_settings_tab' ] );
		add_action( 'woocommerce_update_options_segmentflow', [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	/**
	 * Add the Segmentflow tab to WooCommerce settings.
	 *
	 * @param array $tabs Existing WooCommerce settings tabs.
	 * @return array Modified tabs array.
	 */
	public static function add_settings_tab( array $tabs ): array {
		$tabs['segmentflow'] = __( 'Segmentflow', 'segmentflow-woocommerce' );
		return $tabs;
	}

	/**
	 * Render the Segmentflow settings tab content.
	 */
	public static function render_settings_tab(): void {
		// TODO: Implement settings tab rendering.
		// Show connect/disconnect UI based on connection state.
		// Show settings fields (debug mode, consent required, API host).
		woocommerce_admin_fields( self::get_settings() );
	}

	/**
	 * Save the Segmentflow settings.
	 */
	public static function save_settings(): void {
		woocommerce_update_options( self::get_settings() );
	}

	/**
	 * Get the settings fields for the Segmentflow tab.
	 *
	 * @return array WooCommerce settings fields.
	 */
	public static function get_settings(): array {
		return [
			'section_title' => [
				'name' => __( 'Segmentflow Settings', 'segmentflow-woocommerce' ),
				'type' => 'title',
				'desc' => __( 'Configure your Segmentflow integration for AI-powered email marketing.', 'segmentflow-woocommerce' ),
				'id'   => 'segmentflow_section_title',
			],
			'debug_mode'    => [
				'name'    => __( 'Debug Mode', 'segmentflow-woocommerce' ),
				'type'    => 'checkbox',
				'desc'    => __( 'Enable debug logging for the Segmentflow SDK.', 'segmentflow-woocommerce' ),
				'id'      => 'segmentflow_debug_mode',
				'default' => 'no',
			],
			'consent_required' => [
				'name'    => __( 'Require Consent', 'segmentflow-woocommerce' ),
				'type'    => 'checkbox',
				'desc'    => __( 'Require user consent before tracking. Enable this if you need GDPR cookie consent.', 'segmentflow-woocommerce' ),
				'id'      => 'segmentflow_consent_required',
				'default' => 'no',
			],
			'api_host'      => [
				'name'    => __( 'API Host', 'segmentflow-woocommerce' ),
				'type'    => 'text',
				'desc'    => __( 'Override the Segmentflow API host URL. Leave default unless instructed.', 'segmentflow-woocommerce' ),
				'id'      => 'segmentflow_api_host',
				'default' => 'https://api.segmentflow.ai',
			],
			'section_end'   => [
				'type' => 'sectionend',
				'id'   => 'segmentflow_section_end',
			],
		];
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public static function enqueue_scripts( string $hook_suffix ): void {
		// Only load on WooCommerce settings page with Segmentflow tab.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab check.
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix || ! isset( $_GET['tab'] ) || 'segmentflow' !== $_GET['tab'] ) {
			return;
		}

		wp_enqueue_style(
			'segmentflow-admin',
			SEGMENTFLOW_WC_PLUGIN_URL . 'assets/css/admin.css',
			[],
			SEGMENTFLOW_WC_VERSION
		);

		wp_enqueue_script(
			'segmentflow-admin',
			SEGMENTFLOW_WC_PLUGIN_URL . 'assets/js/admin.iife.js',
			[],
			SEGMENTFLOW_WC_VERSION,
			true
		);

		wp_localize_script(
			'segmentflow-admin',
			'segmentflowAdmin',
			[
				'connectUrl'  => Segmentflow_Auth::get_connect_url(),
				'isConnected' => Segmentflow_Auth::is_connected(),
				'nonce'       => wp_create_nonce( 'segmentflow-admin' ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			]
		);
	}
}
