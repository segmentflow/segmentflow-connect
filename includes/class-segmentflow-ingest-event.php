<?php
/**
 * Identified ingest event builders.
 *
 * Pure helpers that produce the frozen identified-ingest item shapes.
 * Callers supply a retry-stable messageId; these builders never mint
 * identity, tenant routing fields, or occurrence keys.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.CapitalPDangit -- 'wordpress' is a frozen platform/source identifier.

/**
 * Class Segmentflow_Ingest_Event
 *
 * Builds identify / track / page items for POST /api/v1/ingest/batch.
 */
class Segmentflow_Ingest_Event {

	/**
	 * Build an identify item.
	 *
	 * Identify requires top-level canonical email. userId is optional
	 * (email-only form / guest checkout).
	 *
	 * @param string               $email      Canonical email address.
	 * @param string               $message_id Retry-stable message ID.
	 * @param string|null          $user_id    Optional external user ID (wp_* / wc_*).
	 * @param array<string, mixed> $traits     Optional traits.
	 * @param string|null          $timestamp  Optional ISO-8601 timestamp.
	 * @param string|null          $source     Optional origin metadata (wordpress|woocommerce).
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When email or messageId is invalid.
	 */
	public static function identify(
		string $email,
		string $message_id,
		?string $user_id = null,
		array $traits = [],
		?string $timestamp = null,
		?string $source = null
	): array {
		$message_id = self::require_message_id( $message_id );
		$email      = self::normalize_email( $email );
		if ( '' === $email ) {
			throw new InvalidArgumentException( 'identify requires a valid canonical email.' );
		}

		$item = [
			'type'      => 'identify',
			'messageId' => $message_id,
			'email'     => $email,
		];

		if ( null !== $user_id && '' !== $user_id ) {
			$item['userId'] = $user_id;
		}
		if ( ! empty( $traits ) ) {
			$item['traits'] = $traits;
		}
		if ( null !== $timestamp && '' !== $timestamp ) {
			$item['timestamp'] = $timestamp;
		}
		if ( null !== $source && '' !== $source ) {
			$item['source'] = $source;
		}

		return $item;
	}

	/**
	 * Build a track item.
	 *
	 * Track requires at least one of top-level email or userId.
	 *
	 * @param string               $event      Event name.
	 * @param string               $message_id Retry-stable message ID.
	 * @param string|null          $email      Optional canonical email.
	 * @param string|null          $user_id    Optional external user ID.
	 * @param array<string, mixed> $properties Optional properties.
	 * @param string|null          $timestamp  Optional ISO-8601 timestamp.
	 * @param string|null          $source     Optional origin metadata.
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When identity or messageId is invalid.
	 */
	public static function track(
		string $event,
		string $message_id,
		?string $email = null,
		?string $user_id = null,
		array $properties = [],
		?string $timestamp = null,
		?string $source = null
	): array {
		$message_id = self::require_message_id( $message_id );
		$event      = trim( $event );
		if ( '' === $event ) {
			throw new InvalidArgumentException( 'track requires a non-empty event name.' );
		}

		$normalized_email = null;
		if ( null !== $email && '' !== trim( $email ) ) {
			$normalized_email = self::normalize_email( $email );
			if ( '' === $normalized_email ) {
				throw new InvalidArgumentException( 'track email must be a valid canonical email when provided.' );
			}
		}

		$normalized_user_id = ( null !== $user_id && '' !== $user_id ) ? $user_id : null;
		if ( null === $normalized_email && null === $normalized_user_id ) {
			throw new InvalidArgumentException( 'track requires top-level email and/or userId.' );
		}

		$item = [
			'type'      => 'track',
			'messageId' => $message_id,
			'event'     => $event,
		];

		if ( null !== $normalized_email ) {
			$item['email'] = $normalized_email;
		}
		if ( null !== $normalized_user_id ) {
			$item['userId'] = $normalized_user_id;
		}
		if ( ! empty( $properties ) ) {
			$item['properties'] = $properties;
		}
		if ( null !== $timestamp && '' !== $timestamp ) {
			$item['timestamp'] = $timestamp;
		}
		if ( null !== $source && '' !== $source ) {
			$item['source'] = $source;
		}

		return $item;
	}

	/**
	 * Build a page item.
	 *
	 * Page requires at least one of top-level email or userId.
	 *
	 * @param string               $message_id Retry-stable message ID.
	 * @param string|null          $email      Optional canonical email.
	 * @param string|null          $user_id    Optional external user ID.
	 * @param string|null          $name       Optional page name.
	 * @param array<string, mixed> $properties Optional properties.
	 * @param string|null          $timestamp  Optional ISO-8601 timestamp.
	 * @param string|null          $source     Optional origin metadata.
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When identity or messageId is invalid.
	 */
	public static function page(
		string $message_id,
		?string $email = null,
		?string $user_id = null,
		?string $name = null,
		array $properties = [],
		?string $timestamp = null,
		?string $source = null
	): array {
		$message_id = self::require_message_id( $message_id );

		$normalized_email = null;
		if ( null !== $email && '' !== trim( $email ) ) {
			$normalized_email = self::normalize_email( $email );
			if ( '' === $normalized_email ) {
				throw new InvalidArgumentException( 'page email must be a valid canonical email when provided.' );
			}
		}

		$normalized_user_id = ( null !== $user_id && '' !== $user_id ) ? $user_id : null;
		if ( null === $normalized_email && null === $normalized_user_id ) {
			throw new InvalidArgumentException( 'page requires top-level email and/or userId.' );
		}

		$item = [
			'type'      => 'page',
			'messageId' => $message_id,
		];

		if ( null !== $normalized_email ) {
			$item['email'] = $normalized_email;
		}
		if ( null !== $normalized_user_id ) {
			$item['userId'] = $normalized_user_id;
		}
		if ( null !== $name && '' !== $name ) {
			$item['name'] = $name;
		}
		if ( ! empty( $properties ) ) {
			$item['properties'] = $properties;
		}
		if ( null !== $timestamp && '' !== $timestamp ) {
			$item['timestamp'] = $timestamp;
		}
		if ( null !== $source && '' !== $source ) {
			$item['source'] = $source;
		}

		return $item;
	}

	/**
	 * Build a deterministic retry-stable message ID.
	 *
	 * Format: sfc:v1:<event-kind>:<sha256(canonical-input)>
	 *
	 * @param string $event_kind       Opaque event-kind segment (no raw PII).
	 * @param string $canonical_input  Canonical occurrence input from the message-ID contract.
	 * @return string
	 */
	public static function deterministic_message_id( string $event_kind, string $canonical_input ): string {
		$event_kind = trim( $event_kind );
		if ( '' === $event_kind || '' === $canonical_input ) {
			throw new InvalidArgumentException( 'deterministic message IDs require event-kind and canonical input.' );
		}

		return 'sfc:v1:' . $event_kind . ':' . hash( 'sha256', $canonical_input );
	}

	/**
	 * Build a request-only message ID from a pre-generated UUIDv7.
	 *
	 * Format: sfc:v1:<event-kind>:<uuidv7>
	 *
	 * @param string $event_kind Opaque event-kind segment.
	 * @param string $uuid       UUIDv7 generated once before the HTTP attempt loop.
	 * @return string
	 */
	public static function occurrence_message_id( string $event_kind, string $uuid ): string {
		$event_kind = trim( $event_kind );
		$uuid       = trim( $uuid );
		if ( '' === $event_kind || '' === $uuid ) {
			throw new InvalidArgumentException( 'occurrence message IDs require event-kind and uuid.' );
		}

		return 'sfc:v1:' . $event_kind . ':' . $uuid;
	}

	/**
	 * Generate a UUIDv7 string.
	 *
	 * Callers must generate this once at hook entry and reuse it across
	 * identify/track pairs and client retries — never inside the HTTP loop.
	 *
	 * @return string
	 */
	public static function generate_uuidv7(): string {
		// 48-bit millisecond timestamp.
		$ms = (int) ( microtime( true ) * 1000 );

		// 10 bytes of randomness.
		$rand = random_bytes( 10 );

		// Pack timestamp into first 6 bytes.
		$time_hex = str_pad( dechex( $ms ), 12, '0', STR_PAD_LEFT );

		$hex  = $time_hex;
		$hex .= bin2hex( $rand );

		// Set version: byte 6 high nibble = 7.
		$hex[12] = '7';

		// Set variant: byte 8 high 2 bits = 10.
		$byte8   = hexdec( $hex[16] . $hex[17] );
		$byte8   = ( $byte8 & 0x3F ) | 0x80;
		$hex[16] = dechex( ( $byte8 >> 4 ) & 0xF );
		$hex[17] = dechex( $byte8 & 0xF );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}

	/**
	 * Normalize and validate an email address.
	 *
	 * @param string $email Raw email.
	 * @return string Normalized email, or empty string when invalid.
	 */
	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return '';
		}

		$sanitized = sanitize_email( $email );
		if ( ! is_email( $sanitized ) ) {
			return '';
		}

		return $sanitized;
	}

	/**
	 * Require a non-empty message ID.
	 *
	 * @param string $message_id Candidate message ID.
	 * @return string
	 *
	 * @throws InvalidArgumentException When empty.
	 */
	private static function require_message_id( string $message_id ): string {
		$message_id = trim( $message_id );
		if ( '' === $message_id ) {
			throw new InvalidArgumentException( 'messageId is required.' );
		}
		return $message_id;
	}
}
