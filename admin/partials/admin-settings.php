<?php
/**
 * Segmentflow settings tab template.
 *
 * Shows general plugin settings (debug mode, consent, API host).
 * Only visible when connected.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

// Register settings on this page load.
Segmentflow_Admin_Settings::register();
?>

<form method="post" action="options.php">
	<?php
	settings_fields( 'segmentflow-settings' );
	do_settings_sections( 'segmentflow-settings' );
	submit_button();
	?>
</form>
