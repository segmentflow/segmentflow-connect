<?php
/**
 * Plugin Name: Segmentflow for WooCommerce
 * Plugin URI:  https://segmentflow.ai/integrations/woocommerce
 * Description: Connect your WooCommerce store to Segmentflow for AI-powered email marketing and customer segmentation.
 * Version:     1.0.0
 * Requires at least: 5.8
 * Requires PHP: 8.2
 * Author:      Segmentflow
 * Author URI:  https://segmentflow.ai
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: segmentflow-woocommerce
 * Domain Path: /languages
 *
 * WC requires at least: 5.0
 * WC tested up to:      9.5
 *
 * @package Segmentflow_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
define( 'SEGMENTFLOW_WC_VERSION', '1.0.0' );
define( 'SEGMENTFLOW_WC_PLUGIN_FILE', __FILE__ );
define( 'SEGMENTFLOW_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEGMENTFLOW_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SEGMENTFLOW_WC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function segmentflow_wc_is_woocommerce_active(): bool {
	return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true );
}

/**
 * Display an admin notice if WooCommerce is not active.
 */
function segmentflow_wc_woocommerce_missing_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: WooCommerce plugin name */
				esc_html__( 'Segmentflow for WooCommerce requires %s to be installed and activated.', 'segmentflow-woocommerce' ),
				'<strong>WooCommerce</strong>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Initialize the plugin.
 */
function segmentflow_wc_init(): void {
	// Bail early if WooCommerce is not active.
	if ( ! segmentflow_wc_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'segmentflow_wc_woocommerce_missing_notice' );
		return;
	}

	// Load plugin classes.
	require_once SEGMENTFLOW_WC_PLUGIN_DIR . 'includes/class-segmentflow-api.php';
	require_once SEGMENTFLOW_WC_PLUGIN_DIR . 'includes/class-segmentflow-auth.php';
	require_once SEGMENTFLOW_WC_PLUGIN_DIR . 'includes/class-segmentflow-admin.php';
	require_once SEGMENTFLOW_WC_PLUGIN_DIR . 'includes/class-segmentflow-tracking.php';

	// Initialize components.
	Segmentflow_Admin::init();
	Segmentflow_Tracking::init();
}
add_action( 'plugins_loaded', 'segmentflow_wc_init' );

/**
 * Plugin activation hook.
 */
function segmentflow_wc_activate(): void {
	if ( ! segmentflow_wc_is_woocommerce_active() ) {
		deactivate_plugins( SEGMENTFLOW_WC_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'Segmentflow for WooCommerce requires WooCommerce to be installed and activated.', 'segmentflow-woocommerce' ),
			'Plugin Activation Error',
			[ 'back_link' => true ]
		);
	}

	// Set default options.
	add_option( 'segmentflow_debug_mode', false );
	add_option( 'segmentflow_consent_required', false );
	add_option( 'segmentflow_api_host', 'https://api.segmentflow.ai' );
}
register_activation_hook( __FILE__, 'segmentflow_wc_activate' );

/**
 * Plugin deactivation hook.
 *
 * Stops SDK injection only. Webhooks and API connection remain active.
 * Full cleanup happens on uninstall (uninstall.php).
 */
function segmentflow_wc_deactivate(): void {
	// Nothing to do -- deactivation simply stops wp_head hook from firing.
	// The write key and connection settings are preserved for reactivation.
}
register_deactivation_hook( __FILE__, 'segmentflow_wc_deactivate' );

/**
 * Declare HPOS compatibility for WooCommerce.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
