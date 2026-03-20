<?php
/**
 * Segmentflow WooCommerce webhook registration.
 *
 * Programmatically registers WooCommerce webhooks via the WC_Webhook class
 * so order lifecycle events (created, updated, deleted, restored) are delivered
 * to the Segmentflow API. This replaces the previous REST API-based webhook
 * registration that required WooCommerce consumer key/secret credentials.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_WC_Webhooks
 *
 * Manages WooCommerce webhook registration and removal using the native
 * WC_Webhook class. Webhooks are registered during the plugin connection
 * flow and removed on disconnect or uninstall.
 *
 * History: Prior to March 2026, WC webhooks were registered by the backend
 * via the WC REST API during dashboard auto-auth (which provided consumer
 * key/secret credentials). When the plugin connection flow was added
 * (96f8ab8f), it created "bridge" integration records without API credentials,
 * so the backend could no longer register webhooks. This class was introduced
 * to register webhooks locally on the WordPress side, using WC_Webhook
 * directly and bypassing the REST API entirely.
 */
class Segmentflow_WC_Webhooks {

	/**
	 * Webhook topics to register.
	 *
	 * These match the topics the backend WooCommerce webhook handler expects.
	 *
	 * @var string[]
	 */
	private const WEBHOOK_TOPICS = [
		'order.created',
		'order.updated',
		'order.deleted',
		'order.restored',
		'customer.created',
		'customer.updated',
		'customer.deleted',
		'product.created',
		'product.updated',
		'product.deleted',
	];

	/**
	 * Webhook name prefix for identification.
	 */
	private const WEBHOOK_NAME_PREFIX = 'Segmentflow: ';

	/**
	 * Options instance.
	 */
	private Segmentflow_Options $options;

	/**
	 * Constructor.
	 *
	 * @param Segmentflow_Options $options The options instance.
	 */
	public function __construct( Segmentflow_Options $options ) {
		$this->options = $options;
	}

	/**
	 * Register all WooCommerce webhooks.
	 *
	 * Creates a WC_Webhook for each topic using the delivery URL and webhook
	 * secret stored during the connection flow. Skips topics that already have
	 * a Segmentflow webhook registered.
	 *
	 * @return int Number of webhooks created.
	 */
	public function register_webhooks(): int {
		if ( ! class_exists( 'WC_Webhook' ) ) {
			return 0;
		}

		$delivery_url   = $this->options->get( 'webhook_delivery_url' );
		$webhook_secret = $this->options->get( 'webhook_secret' );

		if ( empty( $delivery_url ) || empty( $webhook_secret ) ) {
			return 0;
		}

		$existing_topics = $this->get_existing_topics();
		$registered      = 0;

		foreach ( self::WEBHOOK_TOPICS as $topic ) {
			// Skip if a Segmentflow webhook for this topic already exists.
			if ( in_array( $topic, $existing_topics, true ) ) {
				continue;
			}

			$webhook = new \WC_Webhook();
			$webhook->set_name( self::WEBHOOK_NAME_PREFIX . $topic );
			$webhook->set_topic( $topic );
			$webhook->set_delivery_url( $delivery_url );
			$webhook->set_secret( $webhook_secret );
			$webhook->set_status( 'active' );
			$webhook->set_api_version( 'wp_api_v2' );
			$webhook->set_user_id( get_current_user_id() );
			$webhook->save();

			++$registered;
		}

		return $registered;
	}

	/**
	 * Update the secret and delivery URL on all existing Segmentflow webhooks.
	 *
	 * Called on reconnect when the backend generates a new webhook secret.
	 * Without this, existing webhooks would keep the old secret while the
	 * backend DB has the new one — HMAC verification would fail silently.
	 *
	 * @return int Number of webhooks updated.
	 */
	public function update_webhook_credentials(): int {
		if ( ! class_exists( 'WC_Webhook' ) ) {
			return 0;
		}

		$delivery_url   = $this->options->get( 'webhook_delivery_url' );
		$webhook_secret = $this->options->get( 'webhook_secret' );

		if ( empty( $delivery_url ) || empty( $webhook_secret ) ) {
			return 0;
		}

		$webhook_ids = $this->get_segmentflow_webhook_ids();
		$updated     = 0;

		foreach ( $webhook_ids as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( $webhook ) {
				$webhook->set_secret( $webhook_secret );
				$webhook->set_delivery_url( $delivery_url );
				$webhook->set_status( 'active' );
				$webhook->save();
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * Remove all Segmentflow-registered WooCommerce webhooks.
	 *
	 * Finds webhooks by the name prefix and permanently deletes them.
	 *
	 * @return int Number of webhooks removed.
	 */
	public function remove_webhooks(): int {
		if ( ! class_exists( 'WC_Webhook' ) ) {
			return 0;
		}

		$webhook_ids = $this->get_segmentflow_webhook_ids();
		$removed     = 0;

		foreach ( $webhook_ids as $webhook_id ) {
			$webhook = wc_get_webhook( $webhook_id );
			if ( $webhook ) {
				$webhook->delete( true );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Get topics that already have a Segmentflow webhook registered.
	 *
	 * @return string[] Array of topic strings.
	 */
	private function get_existing_topics(): array {
		global $wpdb;

		$prefix  = self::WEBHOOK_NAME_PREFIX;
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT topic FROM {$wpdb->prefix}wc_webhooks WHERE name LIKE %s AND status = 'active'",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return array_map(
			static function ( $row ) {
				return $row->topic;
			},
			$results ?: []
		);
	}

	/**
	 * Get IDs of all Segmentflow-registered webhooks.
	 *
	 * @return int[] Array of webhook IDs.
	 */
	private function get_segmentflow_webhook_ids(): array {
		global $wpdb;

		$prefix  = self::WEBHOOK_NAME_PREFIX;
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT webhook_id FROM {$wpdb->prefix}wc_webhooks WHERE name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return array_map( 'intval', $results ?: [] );
	}
}
