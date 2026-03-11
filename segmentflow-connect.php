<?php
/**
 * Plugin Name: Segmentflow Connect
 * Plugin URI:  https://github.com/segmentflow/segmentflow-connect
 * Description: Connect your WordPress site or WooCommerce store to Segmentflow for AI-powered email marketing, customer segmentation, and revenue attribution.
 * Version:     1.0.5
 * Requires at least: 5.8
 * Requires PHP: 8.1
 * Author:      Segmentflow
 * Author URI:  https://segmentflow.ai
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: segmentflow-connect
 * Domain Path: /languages
 *
 * WC requires at least: 5.0
 * WC tested up to:      9.5
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
define( 'SEGMENTFLOW_VERSION', '1.0.5' );
define( 'SEGMENTFLOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'SEGMENTFLOW_URL', plugin_dir_url( __FILE__ ) );
define( 'SEGMENTFLOW_BASENAME', plugin_basename( __FILE__ ) );

// Ensure is_plugin_active() is available everywhere (front-end, cron, REST).
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Load lifecycle hooks (activation, deactivation).
// Uninstall is handled by uninstall.php (WordPress prioritizes it over register_uninstall_hook).
require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-lifecycle.php';
$segmentflow_lifecycle = new Segmentflow_Lifecycle();
register_activation_hook( __FILE__, [ $segmentflow_lifecycle, 'activate' ] );
register_deactivation_hook( __FILE__, [ $segmentflow_lifecycle, 'deactivate' ] );

// Load and instantiate the orchestrator.
require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow.php';
new Segmentflow();

/**
 * Declare HPOS compatibility for WooCommerce (if WooCommerce is active).
 *
 * This hook fires only when WooCommerce is loaded, so it's safe to register
 * unconditionally -- it simply won't fire on plain WordPress sites.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
