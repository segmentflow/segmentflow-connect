<?php
/**
 * Segmentflow unified identity cookie.
 *
 * Manages a single base64-encoded JSON cookie (`sf_id`) that bridges
 * PHP and browser SDK identity. Both writers use merge-on-write semantics
 * to preserve fields set by the other.
 *
 * Cookie format:
 *   sf_id = base64(JSON({ "a": "anonymousId", "u": "userId", "e": "email", "p": "phone" }))
 *
 * Short keys minimize cookie size (4 KB browser limit).
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Identity_Cookie
 *
 * Static helper for reading and writing the unified identity cookie.
 * Called from the `init` hook (early priority) to guarantee the cookie
 * exists before any other hook needs it.
 */
class Segmentflow_Identity_Cookie {

	/**
	 * Cookie name.
	 */
	const COOKIE_NAME = 'sf_id';

	/**
	 * Legacy cookie names (for migration from pre-M2 SDK).
	 */
	const LEGACY_ANON_COOKIE = 'sf_anon_id';
	const LEGACY_USER_COOKIE = 'sf_user_id';

	/**
	 * Cookie lifetime in seconds (2 years).
	 */
	const COOKIE_LIFETIME = 63072000; // 730 days

	/**
	 * In-memory cache of the decoded cookie for the current request.
	 *
	 * Prevents repeated base64_decode + json_decode on every read within
	 * the same PHP request.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $cache = null;

	/**
	 * Read the identity cookie.
	 *
	 * Decodes the base64 JSON cookie and returns an associative array.
	 * Falls back to legacy `sf_anon_id` cookie for migration.
	 *
	 * @return array<string, string>|null Decoded cookie data, or null if absent.
	 */
	public static function read(): ?array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		// Try the unified cookie first.
		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$raw = $_COOKIE[ self::COOKIE_NAME ];
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for cookie format.
			$json = base64_decode( $raw, true );
			if ( false !== $json ) {
				$data = json_decode( $json, true );
				if ( is_array( $data ) && ! empty( $data['a'] ) ) {
					self::$cache = $data;
					return $data;
				}
			}
		}

		// Fallback: migrate from legacy sf_anon_id cookie.
		if ( ! empty( $_COOKIE[ self::LEGACY_ANON_COOKIE ] ) ) {
			$legacy_anon = sanitize_text_field( wp_unslash( $_COOKIE[ self::LEGACY_ANON_COOKIE ] ) );
			if ( $legacy_anon ) {
				$data = [ 'a' => $legacy_anon ];

				// Also pick up legacy user ID if present.
				if ( ! empty( $_COOKIE[ self::LEGACY_USER_COOKIE ] ) ) {
					$data['u'] = sanitize_text_field( wp_unslash( $_COOKIE[ self::LEGACY_USER_COOKIE ] ) );
				}

				self::$cache = $data;
				return $data;
			}
		}

		return null;
	}

	/**
	 * Write / merge fields into the identity cookie.
	 *
	 * Uses merge-on-write semantics: reads the existing cookie, merges
	 * new fields (new fields win on conflict), and re-encodes.
	 *
	 * @param array<string, string> $fields Key-value pairs to merge (e.g. ['e' => 'email@example.com']).
	 */
	public static function write( array $fields ): void {
		$existing = self::read() ?? [];
		$merged   = array_merge( $existing, $fields );

		// Remove empty values to keep cookie small.
		$merged = array_filter(
			$merged,
			function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for cookie format.
		$encoded = base64_encode( (string) wp_json_encode( $merged ) );

		$secure = is_ssl();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- setcookie() may fail in test/CLI contexts where headers are already sent. The $_COOKIE superglobal update below ensures the value is still available for the current request.
		@setcookie(
			self::COOKIE_NAME,
			$encoded,
			[
				'expires'  => time() + self::COOKIE_LIFETIME,
				'path'     => '/',
				'domain'   => '',
				'secure'   => $secure,
				'httponly' => false, // JS must read this cookie.
				'samesite' => 'Lax',
			]
		);

		// Update the superglobal so subsequent reads in this request see the new value.
		$_COOKIE[ self::COOKIE_NAME ] = $encoded;

		// Update in-memory cache.
		self::$cache = $merged;
	}

	/**
	 * Ensure an anonymous ID exists in the cookie.
	 *
	 * If the `sf_id` cookie is missing or has no `a` field, generates a
	 * UUIDv7 and sets the cookie server-side. Server-set cookies are
	 * immune to Safari ITP 7-day limits.
	 *
	 * Hooked to `init` at priority 1 (very early).
	 *
	 * @return string The anonymous ID (existing or newly generated).
	 */
	public static function ensure_anonymous_id(): string {
		$data = self::read();

		if ( $data && ! empty( $data['a'] ) ) {
			return $data['a'];
		}

		$anonymous_id = self::generate_uuidv7();
		self::write( [ 'a' => $anonymous_id ] );

		return $anonymous_id;
	}

	/**
	 * Get the best available user ID from the cookie.
	 *
	 * @param string $prefix User ID prefix (default 'wc_').
	 * @return string|null The prefixed user ID, or null if not set.
	 */
	public static function get_user_id( string $prefix = 'wc_' ): ?string {
		$data = self::read();

		if ( $data && ! empty( $data['u'] ) ) {
			return $data['u'];
		}

		// Fall back to WordPress session if logged in.
		if ( is_user_logged_in() ) {
			return $prefix . get_current_user_id();
		}

		return null;
	}

	/**
	 * Merge an email address into the cookie.
	 *
	 * @param string $email Email address.
	 */
	public static function set_email( string $email ): void {
		if ( ! is_email( $email ) ) {
			return;
		}
		self::write( [ 'e' => sanitize_email( $email ) ] );
	}

	/**
	 * Merge a phone number into the cookie.
	 *
	 * @param string $phone Phone number.
	 */
	public static function set_phone( string $phone ): void {
		$phone = sanitize_text_field( $phone );
		if ( empty( $phone ) ) {
			return;
		}
		self::write( [ 'p' => $phone ] );
	}

	/**
	 * Reset the in-memory cache.
	 *
	 * Useful for testing or when the cookie is cleared externally.
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * Generate a UUIDv7 (timestamp-based, sortable).
	 *
	 * UUIDv7 format (RFC 9562):
	 *   48 bits: Unix timestamp in milliseconds
	 *    4 bits: version (0111 = 7)
	 *   12 bits: random
	 *    2 bits: variant (10)
	 *   62 bits: random
	 *
	 * No Composer dependency needed — this is ~20 lines.
	 *
	 * @return string UUID v7 string (e.g. "01926a3b-4c5d-7e8f-9a0b-1c2d3e4f5a6b").
	 */
	private static function generate_uuidv7(): string {
		// 48-bit millisecond timestamp.
		$ms = (int) ( microtime( true ) * 1000 );

		// 10 bytes of randomness.
		$rand = random_bytes( 10 );

		// Pack timestamp into first 6 bytes.
		$time_hex = str_pad( dechex( $ms ), 12, '0', STR_PAD_LEFT );

		// Build the 16-byte UUID.
		// Bytes 0-5: timestamp
		// Byte 6:    version (high nibble = 7) + rand_a (low nibble)
		// Byte 7:    rand_a continued
		// Byte 8:    variant (high 2 bits = 10) + rand_b (low 6 bits)
		// Bytes 9-15: rand_b continued
		$hex  = $time_hex;
		$hex .= bin2hex( $rand );

		// Set version: byte 6 high nibble = 7.
		$hex[12] = '7';

		// Set variant: byte 8 high 2 bits = 10.
		$byte8   = hexdec( $hex[16] . $hex[17] );
		$byte8   = ( $byte8 & 0x3F ) | 0x80;
		$hex[16] = dechex( ( $byte8 >> 4 ) & 0xF );
		$hex[17] = dechex( $byte8 & 0xF );

		// Format as UUID string.
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
