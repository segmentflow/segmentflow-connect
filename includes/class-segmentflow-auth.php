<?php
/**
 * Segmentflow authentication handler.
 *
 * Manages the connection flow between the WordPress plugin and the Segmentflow
 * dashboard. Supports three connection scenarios:
 *
 * 1. Plain WordPress (no WooCommerce): Write key only, no WC auto-auth.
 * 2. WordPress + WooCommerce (from plugin): Redirect through dashboard to WC auto-auth.
 * 3. WordPress + WooCommerce (from dashboard): Dashboard initiates WC auto-auth.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Auth
 *
 * Handles the connection flow for linking a WordPress site to Segmentflow.
 */
class Segmentflow_Auth {

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
		// Handle the auth return query parameter.
		add_action( 'admin_init', [ $this, 'maybe_handle_return' ] );

		// Register AJAX handler for disconnect.
		add_action( 'wp_ajax_segmentflow_disconnect', [ $this, 'ajax_disconnect' ] );
	}

	/**
	 * Get the Segmentflow dashboard URL for initiating connection.
	 *
	 * Detects whether WooCommerce is active and routes to the appropriate
	 * connection endpoint.
	 *
	 * @return string The redirect URL to the Segmentflow dashboard.
	 */
	public function get_connect_url(): string {
		$store_url  = home_url();
		$return_url = admin_url( 'admin.php?page=segmentflow&connected=1' );
		$app_host   = $this->options->get_app_host();

		// Route based on platform.
		if ( Segmentflow_Helper::is_woocommerce_active() ) {
			// Scenario 2: WooCommerce from plugin -- redirect to dashboard WC connect.
			return add_query_arg(
				[
					'store_url'  => rawurlencode( $store_url ),
					'return_url' => rawurlencode( $return_url ),
				],
				$app_host . '/connect/woocommerce'
			);
		}

		// Scenario 1: Plain WordPress -- redirect to dashboard WP connect.
		return add_query_arg(
			[
				'site_url'   => rawurlencode( $store_url ),
				'return_url' => rawurlencode( $return_url ),
			],
			$app_host . '/connect/wordpress'
		);
	}

	/**
	 * Check for and handle the auth return redirect.
	 *
	 * Called on admin_init. Detects the 'connected' query parameter and
	 * processes the poll token to retrieve the write key.
	 */
	public function maybe_handle_return(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Auth return from external redirect.
		if ( ! isset( $_GET['page'] ) || 'segmentflow' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Auth return from external redirect.
		if ( ! isset( $_GET['connected'] ) || '1' !== sanitize_text_field( wp_unslash( $_GET['connected'] ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Auth return from external redirect.
		$poll_token = isset( $_GET['poll_token'] ) ? sanitize_text_field( wp_unslash( $_GET['poll_token'] ) ) : '';

		if ( ! empty( $poll_token ) ) {
			$this->handle_return( $poll_token );
		}
	}

	/**
	 * Handle the return from the auth flow.
	 *
	 * Polls the Segmentflow API for connection status and stores the write key.
	 *
	 * @param string $poll_token The temporary token for polling connection status.
	 * @return bool Whether the write key was successfully retrieved and stored.
	 */
	public function handle_return( string $poll_token ): bool {
		$api    = new Segmentflow_API( $this->options );
		$status = $api->check_status( $poll_token );

		if ( ! empty( $status['connected'] ) && ! empty( $status['write_key'] ) ) {
			$this->options->set( 'write_key', sanitize_text_field( $status['write_key'] ) );

			if ( ! empty( $status['organization_name'] ) ) {
				$this->options->set( 'organization_name', sanitize_text_field( $status['organization_name'] ) );
			}

			// Store which platform was connected.
			$platform = Segmentflow_Helper::get_platform();
			$this->options->set( 'connected_platform', $platform );

			return true;
		}

		return false;
	}

	/**
	 * AJAX handler for disconnect.
	 */
	public function ajax_disconnect(): void {
		check_ajax_referer( 'segmentflow-admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'segmentflow-connect' ) ] );
		}

		$this->disconnect();
		wp_send_json_success( [ 'message' => __( 'Disconnected from Segmentflow.', 'segmentflow-connect' ) ] );
	}

	/**
	 * Check if the site is currently connected to Segmentflow.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return $this->options->is_connected();
	}

	/**
	 * Disconnect from Segmentflow.
	 *
	 * Removes the write key and connection settings. Notifies the Segmentflow
	 * API of the disconnection (best-effort).
	 *
	 * @return bool Whether the disconnection was successful.
	 */
	public function disconnect(): bool {
		// Notify the Segmentflow API (best-effort).
		$api = new Segmentflow_API( $this->options );
		$api->disconnect();

		// Remove local connection data.
		$this->options->delete( 'write_key' );
		$this->options->delete( 'organization_name' );
		$this->options->delete( 'connected_platform' );

		return true;
	}
}
