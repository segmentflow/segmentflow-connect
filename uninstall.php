<?php
/**
 * Segmentflow Connect uninstall handler.
 *
 * Fired when the plugin is deleted from WordPress admin.
 * Cleans up all plugin data from the database.
 *
 * @package Segmentflow_Connect
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Read the API host before deleting options so we notify the correct server.
$segmentflow_api_host = get_option( 'segmentflow_api_host', 'https://api.cloud.segmentflow.ai' );

// Load the options class for cleanup.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-segmentflow-options.php';

Segmentflow_Options::delete_all();
delete_option( 'segmentflow_activated_at' );

// Best-effort: notify the Segmentflow API of the disconnection.
wp_remote_request(
	$segmentflow_api_host . '/v1/integrations/disconnect',
	[
		'method'  => 'DELETE',
		'timeout' => 5,
	]
);
