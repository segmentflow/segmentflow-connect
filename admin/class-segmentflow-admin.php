<?php
/**
 * Segmentflow admin page.
 *
 * Registers a top-level "Segmentflow" menu item in WordPress admin
 * with dynamic tabs based on active integrations.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Admin
 *
 * Manages the Segmentflow admin settings page as a top-level menu item.
 * Tabs adapt based on active integrations:
 *
 * - Connection: Always shown. Connect/disconnect to Segmentflow.
 * - Settings: Shown when connected. Debug mode, consent, API host.
 * - WooCommerce: Shown when WooCommerce is active AND connected. WC-specific settings.
 */
class Segmentflow_Admin {

	/**
	 * Options instance.
	 */
	private Segmentflow_Options $options;

	/**
	 * Constructor.
	 *
	 * @param Segmentflow_Options $options The options instance.
	 */
	public function __construct( Segmentflow_Options $options ) {
		$this->options = $options;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		// Show WooCommerce upgrade notice if WC was activated after plugin.
		add_action( 'admin_notices', [ $this, 'maybe_show_wc_upgrade_notice' ] );
	}

	/**
	 * Add the Segmentflow top-level menu page.
	 */
	public function add_menu_page(): void {
		// Use a custom icon for the sidebar menu if it exists (SVG preferred, PNG fallback).
		$icon = 'dashicons-email-alt';

		$svg_path = SEGMENTFLOW_PATH . 'assets/images/icon.svg';
		$png_path = SEGMENTFLOW_PATH . 'assets/images/icon.png';

		if ( file_exists( $svg_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
			$svg_content = file_get_contents( $svg_path );
			if ( false !== $svg_content ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for WP menu icon data URI.
				$icon = 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
			}
		} elseif ( file_exists( $png_path ) ) {
			$icon = SEGMENTFLOW_URL . 'assets/images/icon.png';
		}

		add_menu_page(
			__( 'Segmentflow', 'segmentflow-connect' ),
			__( 'Segmentflow', 'segmentflow-connect' ),
			'manage_options',
			'segmentflow',
			[ $this, 'render_page' ],
			$icon,
			58
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Determine current tab.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation only.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection';
		$tabs        = $this->get_tabs();

		// Ensure tab exists.
		if ( ! isset( $tabs[ $current_tab ] ) ) {
			$current_tab = 'connection';
		}

		include SEGMENTFLOW_PATH . 'admin/partials/admin-page.php';
	}

	/**
	 * Get available tabs based on connection state and active integrations.
	 *
	 * @return array<string, string> Tab slug => Tab label.
	 */
	public function get_tabs(): array {
		$tabs = [
			'connection' => __( 'Connection', 'segmentflow-connect' ),
		];

		if ( $this->options->is_connected() ) {
			$tabs['settings'] = __( 'Settings', 'segmentflow-connect' );

			if ( Segmentflow_Helper::is_woocommerce_active() ) {
				$tabs['woocommerce'] = __( 'WooCommerce', 'segmentflow-connect' );
			}
		}

		return $tabs;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		// Only load on our settings page.
		if ( 'toplevel_page_segmentflow' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'segmentflow-admin',
			SEGMENTFLOW_URL . 'assets/css/admin.css',
			[],
			SEGMENTFLOW_VERSION
		);

		wp_enqueue_script(
			'segmentflow-admin',
			SEGMENTFLOW_URL . 'assets/js/admin.iife.js',
			[],
			SEGMENTFLOW_VERSION,
			true
		);

		$auth = new Segmentflow_Auth( $this->options );

		// Read poll token from URL if present (auth return redirect).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Auth return from external redirect.
		$poll_token = isset( $_GET['poll_token'] ) ? sanitize_text_field( wp_unslash( $_GET['poll_token'] ) ) : '';

		wp_localize_script(
			'segmentflow-admin',
			'segmentflowAdmin',
			[
				'connectUrl'  => $auth->get_connect_url(),
				'isConnected' => $auth->is_connected(),
				'nonce'       => wp_create_nonce( 'segmentflow-admin' ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'apiHost'     => $this->options->get_api_host(),
				'pollToken'   => $poll_token,
			]
		);
	}

	/**
	 * Show admin notice if WooCommerce was activated after plugin connection.
	 */
	public function maybe_show_wc_upgrade_notice(): void {
		if ( ! get_transient( 'segmentflow_wc_upgrade_notice' ) ) {
			return;
		}

		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: Segmentflow settings page URL */
					esc_html__( 'WooCommerce detected! Visit the %s to upgrade your Segmentflow connection for order tracking, customer sync, and revenue attribution.', 'segmentflow-connect' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=segmentflow' ) ) . '">' . esc_html__( 'Segmentflow settings', 'segmentflow-connect' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php

		delete_transient( 'segmentflow_wc_upgrade_notice' );
	}
}
