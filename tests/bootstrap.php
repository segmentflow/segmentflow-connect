<?php
/**
 * PHPUnit bootstrap file.
 *
 * Uses the WordPress test suite scaffolding.
 * See: https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/
 *
 * @package Segmentflow_Connect
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Path to WordPress test suite.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Load WordPress test framework.
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Have you run bin/install-wp-tests.sh?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce before the plugin (if available).
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		// Load WooCommerce if available.
		if ( defined( 'WC_ABSPATH' ) ) {
			require WC_ABSPATH . 'woocommerce.php';
		}
	}
);

/**
 * Load the plugin.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/segmentflow-connect.php';
	}
);

// Start the WP test suite.
require $_tests_dir . '/includes/bootstrap.php';
