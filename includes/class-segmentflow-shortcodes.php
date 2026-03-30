<?php
/**
 * Segmentflow Shortcodes.
 *
 * Registers shortcodes for embedding Segmentflow forms inline on any page.
 *
 * Usage:
 *   [segmentflow_form id="<websiteFormId>"]
 *
 * Renders a placeholder <div data-sf-form-id="..."> that the Segmentflow SDK
 * picks up and renders into using Shadow DOM.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

class Segmentflow_Shortcodes {

	/**
	 * Register all shortcodes.
	 */
	public static function register(): void {
		add_shortcode( 'segmentflow_form', [ __CLASS__, 'render_form' ] );
	}

	/**
	 * Render the [segmentflow_form] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_form( $atts ): string {
		$atts = shortcode_atts(
			[ 'id' => '' ],
			$atts,
			'segmentflow_form',
		);

		$id = sanitize_text_field( $atts['id'] );
		if ( empty( $id ) ) {
			return '';
		}

		return '<div data-sf-form-id="' . esc_attr( $id ) . '"></div>';
	}
}
