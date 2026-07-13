<?php
/**
 * Segmentflow cookie-consent state.
 *
 * Source of truth for the visitor's two-category cookie consent
 * (analytics + marketing). Both PHP and the browser write/read the
 * same `sf_consent` cookie so server hooks and the SDK reach the
 * same decision without a round-trip.
 *
 * Cookie format:
 *   sf_consent = base64(JSON({ "a": 1, "m": 1 }))
 *
 * Short keys keep the cookie under 100 bytes; values are 1 or 0.
 *
 * Lifetime is 1 year (Article 5(3) ePrivacy ceiling for non-essential
 * cookies). Re-prompt cadence is the banner's responsibility.
 *
 * Cookie is JS-readable (`HttpOnly=false`) because the Browser Consent
 * SDK has to flip its own state from a banner CTA without a server
 * round-trip.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Consent_Cookie
 *
 * Static helper. The class never enforces consent itself — callers
 * (server event owners, the storefront SDK) read its decisions and
 * decide what to do. Consent is permission state only; it never creates
 * identity.
 */
class Segmentflow_Consent_Cookie {

	/**
	 * Cookie name. Must match the JS Browser Consent SDK constant.
	 */
	const COOKIE_NAME = 'sf_consent';

	/**
	 * Cookie lifetime in seconds (1 year). Matches the ePrivacy ceiling
	 * for non-essential cookies; banner re-prompt cadence is independent.
	 */
	const COOKIE_LIFETIME = 31536000;

	/**
	 * Allowed consent categories. Anything outside this list is rejected
	 * by has_consent() / set_consent() to keep the cookie schema closed.
	 *
	 * @var string[]
	 */
	private const CATEGORIES = [ 'analytics', 'marketing' ];

	/**
	 * Map between category name and cookie short key (kept private so
	 * the JSON shape stays an implementation detail).
	 *
	 * @var array<string, string>
	 */
	private const CATEGORY_KEYS = [
		'analytics' => 'a',
		'marketing' => 'm',
	];

	/**
	 * In-memory cache of the decoded cookie for the current request.
	 *
	 * @var array<string, bool>|null
	 */
	private static ?array $cache = null;

	/**
	 * Has the visitor recorded any consent decision?
	 *
	 * Returns true when a (well-formed) `sf_consent` cookie is present —
	 * regardless of whether categories are granted or denied. Callers
	 * gate cookie-write side effects on this rather than on per-category
	 * state, so a visitor who refuses everything still doesn't trigger
	 * a banner re-prompt.
	 *
	 * @return bool
	 */
	public static function is_set(): bool {
		return null !== self::read();
	}

	/**
	 * Has the visitor granted consent for the given category?
	 *
	 * Returns false when the cookie is absent (no decision yet) or the
	 * category was explicitly denied.
	 *
	 * @param string $category One of self::CATEGORIES.
	 * @return bool
	 */
	public static function has_consent( string $category ): bool {
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			return false;
		}
		$data = self::read();
		if ( null === $data ) {
			return false;
		}
		return ! empty( $data[ $category ] );
	}

	/**
	 * Persist a consent decision for one or more categories.
	 *
	 * Merge semantics: only the keys passed are written; existing keys
	 * survive. Pass all categories to record a full accept/reject.
	 *
	 * @param array<string, bool> $categories Map of category => granted.
	 */
	public static function set_consent( array $categories ): void {
		$existing = self::read() ?? [];
		$merged   = $existing;

		foreach ( $categories as $category => $granted ) {
			if ( ! in_array( $category, self::CATEGORIES, true ) ) {
				continue;
			}
			$merged[ $category ] = (bool) $granted;
		}

		// Rewrite cookie keyed on short keys to keep payload tiny.
		$compact = [];
		foreach ( self::CATEGORIES as $category ) {
			if ( array_key_exists( $category, $merged ) ) {
				$compact[ self::CATEGORY_KEYS[ $category ] ] = $merged[ $category ] ? 1 : 0;
			}
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for cookie format.
		$encoded = base64_encode( (string) wp_json_encode( $compact ) );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- setcookie() may fail in CLI/test contexts.
		@setcookie(
			self::COOKIE_NAME,
			$encoded,
			[
				'expires'  => time() + self::COOKIE_LIFETIME,
				'path'     => '/',
				'domain'   => '',
				'secure'   => is_ssl(),
				'httponly' => false, // JS must read this cookie.
				'samesite' => 'Lax',
			]
		);

		$_COOKIE[ self::COOKIE_NAME ] = $encoded;
		self::$cache                  = $merged;
	}

	/**
	 * Clear the consent cookie. Mirrors the browser revoke path so server
	 * hooks see the same revoked state on the next request.
	 */
	public static function clear(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@setcookie(
			self::COOKIE_NAME,
			'',
			[
				'expires'  => time() - 3600,
				'path'     => '/',
				'domain'   => '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			]
		);
		unset( $_COOKIE[ self::COOKIE_NAME ] );
		self::$cache = null;
	}

	/**
	 * Reset the in-memory cache (testing hook).
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * Decode the cookie. Returns null when absent or malformed so callers
	 * can distinguish "no decision yet" from "decision = denied".
	 *
	 * @return array<string, bool>|null Map of category => granted.
	 */
	private static function read(): ?array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$json = base64_decode( $raw, true );
		if ( false === $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$expanded = [];
		foreach ( self::CATEGORY_KEYS as $category => $short_key ) {
			if ( array_key_exists( $short_key, $decoded ) ) {
				$expanded[ $category ] = (bool) $decoded[ $short_key ];
			}
		}

		// Refuse to honor a cookie that has neither key — it's malformed.
		if ( empty( $expanded ) ) {
			return null;
		}

		self::$cache = $expanded;
		return $expanded;
	}
}
