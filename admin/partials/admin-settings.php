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
?>

<form method="post" action="options.php">
	<?php
	settings_fields( 'segmentflow-settings' );
	do_settings_sections( 'segmentflow-settings' );
	submit_button();
	?>
</form>
