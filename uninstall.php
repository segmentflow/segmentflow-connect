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

// Read values needed for API notification BEFORE deleting options.
$segmentflow_api_host  = get_option( 'segmentflow_api_host', 'https://api.segmentflow.ai' );
$segmentflow_write_key = get_option( 'segmentflow_write_key', '' );

// Load the options class for cleanup.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-segmentflow-options.php';

Segmentflow_Options::delete_all();
delete_option( 'segmentflow_activated_at' );

// Best-effort: notify the Segmentflow API of the disconnection.
if ( ! empty( $segmentflow_write_key ) ) {
	wp_remote_request(
		$segmentflow_api_host . '/api/v1/integrations/connect/disconnect',
		[
			'method'  => 'DELETE',
			'headers' => [
				'X-Write-Key'  => $segmentflow_write_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'timeout' => 5,
		]
	);
}
