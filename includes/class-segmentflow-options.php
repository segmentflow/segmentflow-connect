<?php
/**
 * Segmentflow options manager.
 *
 * Centralized read/write access to wp_options for all Segmentflow settings.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Options
 *
 * Provides a typed interface over wp_options for Segmentflow plugin settings.
 * All option keys are prefixed with 'segmentflow_' to avoid collisions.
 */
class Segmentflow_Options {

	/**
	 * Default option values.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = [
		'segmentflow_write_key'          => '',
		'segmentflow_organization_name'  => '',
		'segmentflow_api_host'           => 'https://api.cloud.segmentflow.ai',
		'segmentflow_app_host'           => 'https://dashboard.segmentflow.ai',
		'segmentflow_debug_mode'         => false,
		'segmentflow_consent_required'   => false,
		'segmentflow_connected_platform' => '', // 'wordpress' or 'woocommerce'
	];

	/**
	 * Get an option value.
	 *
	 * @param string $key     The option key (with or without 'segmentflow_' prefix).
	 * @param mixed  $default Default value if option is not set.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$full_key = $this->normalize_key( $key );
		$fallback = $default ?? ( self::DEFAULTS[ $full_key ] ?? null );
		return get_option( $full_key, $fallback );
	}

	/**
	 * Set an option value.
	 *
	 * @param string $key   The option key (with or without 'segmentflow_' prefix).
	 * @param mixed  $value The value to store.
	 * @return bool Whether the option was updated.
	 */
	public function set( string $key, mixed $value ): bool {
		return update_option( $this->normalize_key( $key ), $value );
	}

	/**
	 * Delete an option.
	 *
	 * @param string $key The option key (with or without 'segmentflow_' prefix).
	 * @return bool Whether the option was deleted.
	 */
	public function delete( string $key ): bool {
		return delete_option( $this->normalize_key( $key ) );
	}

	/**
	 * Get the write key.
	 *
	 * @return string
	 */
	public function get_write_key(): string {
		return (string) $this->get( 'write_key', '' );
	}

	/**
	 * Get the API host URL.
	 *
	 * @return string
	 */
	public function get_api_host(): string {
		return (string) $this->get( 'api_host', 'https://api.cloud.segmentflow.ai' );
	}

	/**
	 * Get the app (dashboard) host URL.
	 *
	 * @return string
	 */
	public function get_app_host(): string {
		return (string) $this->get( 'app_host', 'https://dashboard.segmentflow.ai' );
	}

	/**
	 * Check if the plugin is connected (has a write key).
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return ! empty( $this->get_write_key() );
	}

	/**
	 * Check if debug mode is enabled.
	 *
	 * @return bool
	 */
	public function is_debug_mode(): bool {
		return (bool) $this->get( 'debug_mode', false );
	}

	/**
	 * Check if consent is required before tracking.
	 *
	 * @return bool
	 */
	public function is_consent_required(): bool {
		return (bool) $this->get( 'consent_required', false );
	}

	/**
	 * Get the connected platform.
	 *
	 * @return string 'WordPress' | 'woocommerce' | '' (not connected)
	 */
	public function get_connected_platform(): string {
		return (string) $this->get( 'connected_platform', '' );
	}

	/**
	 * Create default options on activation.
	 */
	public function create_defaults(): void {
		foreach ( self::DEFAULTS as $key => $value ) {
			add_option( $key, $value );
		}
	}

	/**
	 * Remove all options on uninstall.
	 */
	public static function delete_all(): void {
		$options = new self();
		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			$options->delete( $key );
		}
	}

	/**
	 * Normalize the option key to always include the 'segmentflow_' prefix.
	 *
	 * @param string $key The option key.
	 * @return string The normalized key.
	 */
	private function normalize_key( string $key ): string {
		if ( str_starts_with( $key, 'segmentflow_' ) ) {
			return $key;
		}
		return 'segmentflow_' . $key;
	}
}
