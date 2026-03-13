<?php
/**
 * Segmentflow orchestrator.
 *
 * Central class that loads dependencies and initializes components.
 * Uses conditional loading for integration-specific code (WooCommerce, etc.).
 *
 * Follows the Smaily Connect pattern: one plugin, conditional integration loading
 * via an integrations/ directory.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow
 *
 * The main orchestrator class. Instantiated from the plugin bootstrap file.
 * Loads core dependencies unconditionally and integration-specific code
 * conditionally based on which plugins are active.
 */
class Segmentflow {

	/**
	 * Constructor.
	 *
	 * Loads all dependencies and initializes classes.
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->init_classes();
	}

	/**
	 * Load all required class files.
	 *
	 * Core classes are ALWAYS loaded. Integration classes are loaded
	 * conditionally based on plugin detection.
	 */
	private function load_dependencies(): void {
		// ALWAYS: core classes.
		require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-helper.php';
		require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-options.php';
		require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-identity-cookie.php';
		require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-tracking.php';
		require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-auth.php';
		require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-api.php';
		require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-server-events.php';

		// ALWAYS: admin classes.
		require_once SEGMENTFLOW_PATH . 'admin/class-segmentflow-admin.php';
		require_once SEGMENTFLOW_PATH . 'admin/class-segmentflow-admin-settings.php';

		// CONDITIONAL: WooCommerce integration.
		if ( Segmentflow_Helper::is_woocommerce_active() ) {
			require_once SEGMENTFLOW_PATH . 'integrations/woocommerce/class-segmentflow-wc-tracking.php';
			require_once SEGMENTFLOW_PATH . 'integrations/woocommerce/class-segmentflow-wc-auth.php';
			require_once SEGMENTFLOW_PATH . 'integrations/woocommerce/class-segmentflow-wc-helper.php';
			require_once SEGMENTFLOW_PATH . 'integrations/woocommerce/class-segmentflow-wc-server-events.php';
		}

		// FUTURE: Contact Form 7
		// if ( Segmentflow_Helper::is_cf7_active() ) {
		//     require_once SEGMENTFLOW_PATH . 'integrations/cf7/class-segmentflow-cf7.php';
		// }

		// FUTURE: Elementor
		// if ( Segmentflow_Helper::is_elementor_active() ) {
		//     require_once SEGMENTFLOW_PATH . 'integrations/elementor/class-segmentflow-elementor.php';
		// }
	}

	/**
	 * Initialize all loaded classes and register their hooks.
	 */
	private function init_classes(): void {
		$options = new Segmentflow_Options();

		// ALWAYS: ensure identity cookie exists (server-set, immune to Safari ITP).
		// Priority 1 on `init` so the cookie is available for all subsequent hooks.
		if ( ! is_admin() ) {
			add_action( 'init', [ 'Segmentflow_Identity_Cookie', 'ensure_anonymous_id' ], 1 );
		}

		// ALWAYS: core tracking (works on any WordPress site).
		$tracking = new Segmentflow_Tracking( $options );
		$tracking->register_hooks();

		// ALWAYS: auth handler.
		$auth = new Segmentflow_Auth( $options );
		$auth->register_hooks();

		// ALWAYS: admin UI.
		$admin = new Segmentflow_Admin( $options );
		$admin->register_hooks();

		// ALWAYS: register settings on admin_init (required for options.php allowlist).
		add_action( 'admin_init', [ 'Segmentflow_Admin_Settings', 'register' ] );

		// CONDITIONAL: register WooCommerce settings on admin_init.
		if ( Segmentflow_Helper::is_woocommerce_active() ) {
			add_action( 'admin_init', [ 'Segmentflow_Admin_Settings', 'register_woocommerce' ] );
		}

		// ALWAYS: lifecycle hooks (late activation detection).
		$lifecycle = new Segmentflow_Lifecycle();
		$lifecycle->register_hooks();

		// ALWAYS: server-side identity and form events (fire-and-forget to ingest API).
		$api           = new Segmentflow_API( $options );
		$server_events = new Segmentflow_Server_Events( $options, $api );
		$server_events->register_hooks();

		// CONDITIONAL: WooCommerce enrichment.
		// Class files are always loaded when WC is active (see load_dependencies()),
		// but hooks are only registered when the user has enabled the integration.
		if ( Segmentflow_Helper::is_woocommerce_active() && $options->is_wc_enabled() ) {
			$wc_tracking = new Segmentflow_WC_Tracking( $options, $tracking );
			$wc_tracking->register_hooks();

			$wc_auth = new Segmentflow_WC_Auth( $options );
			$wc_auth->register_hooks();

			// Server-side cart and checkout events (shares API instance).
			$wc_server_events = new Segmentflow_WC_Server_Events( $options, $api );
			$wc_server_events->register_hooks();
		}
	}
}
