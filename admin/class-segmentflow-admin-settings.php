<?php
/**
 * Segmentflow admin settings registration.
 *
 * Registers settings fields using the WordPress Settings API.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Admin_Settings
 *
 * Handles WordPress Settings API registration for the Segmentflow plugin.
 * Settings are saved via wp_options using the standard WordPress settings mechanism.
 */
class Segmentflow_Admin_Settings {

	/**
	 * Register settings with WordPress.
	 *
	 * Called during admin_init to register all settings fields.
	 */
	public static function register(): void {
		// Settings section.
		add_settings_section(
			'segmentflow_settings_section',
			__( 'General Settings', 'segmentflow-connect' ),
			[ __CLASS__, 'render_section_description' ],
			'segmentflow-settings'
		);

		// Debug mode.
		register_setting( 'segmentflow-settings', 'segmentflow_debug_mode', [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );

		add_settings_field(
			'segmentflow_debug_mode',
			__( 'Debug Mode', 'segmentflow-connect' ),
			[ __CLASS__, 'render_checkbox_field' ],
			'segmentflow-settings',
			'segmentflow_settings_section',
			[
				'id'          => 'segmentflow_debug_mode',
				'description' => __( 'Enable debug logging for the Segmentflow SDK.', 'segmentflow-connect' ),
			]
		);

		// Consent required.
		register_setting( 'segmentflow-settings', 'segmentflow_consent_required', [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );

		add_settings_field(
			'segmentflow_consent_required',
			__( 'Require Consent', 'segmentflow-connect' ),
			[ __CLASS__, 'render_checkbox_field' ],
			'segmentflow-settings',
			'segmentflow_settings_section',
			[
				'id'          => 'segmentflow_consent_required',
				'description' => __( 'Require user consent before tracking. Enable this for GDPR cookie consent.', 'segmentflow-connect' ),
			]
		);

		// API host override.
		register_setting( 'segmentflow-settings', 'segmentflow_api_host', [
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => 'https://api.segmentflow.ai',
		] );

		add_settings_field(
			'segmentflow_api_host',
			__( 'API Host', 'segmentflow-connect' ),
			[ __CLASS__, 'render_text_field' ],
			'segmentflow-settings',
			'segmentflow_settings_section',
			[
				'id'          => 'segmentflow_api_host',
				'description' => __( 'Override the Segmentflow API host URL. Leave default unless instructed.', 'segmentflow-connect' ),
			]
		);
	}

	/**
	 * Render the section description.
	 */
	public static function render_section_description(): void {
		echo '<p>' . esc_html__( 'Configure your Segmentflow integration settings.', 'segmentflow-connect' ) . '</p>';
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array{id: string, description: string} $args Field arguments.
	 */
	public static function render_checkbox_field( array $args ): void {
		$value = get_option( $args['id'], false );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $args['id'] ); ?>" value="1" <?php checked( $value ); ?> />
			<?php echo esc_html( $args['description'] ); ?>
		</label>
		<?php
	}

	/**
	 * Render a text input field.
	 *
	 * @param array{id: string, description: string} $args Field arguments.
	 */
	public static function render_text_field( array $args ): void {
		$value = get_option( $args['id'], '' );
		?>
		<input type="text" name="<?php echo esc_attr( $args['id'] ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php
	}
}
