<?php
/**
 * Segmentflow for WooCommerce uninstall handler.
 *
 * Fired when the plugin is deleted from WordPress admin.
 * Cleans up all plugin data from the database.
 *
 * @package Segmentflow_WooCommerce
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove all Segmentflow options.
delete_option( 'segmentflow_write_key' );
delete_option( 'segmentflow_organization_name' );
delete_option( 'segmentflow_debug_mode' );
delete_option( 'segmentflow_consent_required' );
delete_option( 'segmentflow_api_host' );

// Optionally notify the Segmentflow API of the disconnection.
// This is best-effort -- if the API is unreachable, we still clean up locally.
$api_host = 'https://api.segmentflow.ai';
wp_remote_request(
	$api_host . '/v1/integrations/woocommerce/disconnect',
	[
		'method'  => 'DELETE',
		'timeout' => 5,
	]
);
