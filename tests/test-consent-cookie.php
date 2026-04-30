<?php
/**
 * Tests for the cookie-consent module.
 *
 * Covers `Segmentflow_Consent_Cookie`: cookie format round-trip, the
 * `is_set` / `has_consent` / `set_consent` / `clear` surface, and the
 * malformed-cookie defenses that the PHP gate relies on.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Consent_Cookie
 */
class Test_Consent_Cookie extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Segmentflow_Consent_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] );
	}

	public function tear_down(): void {
		Segmentflow_Consent_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// is_set + has_consent
	// ---------------------------------------------------------------------

	public function test_is_set_returns_false_when_cookie_absent(): void {
		$this->assertFalse( Segmentflow_Consent_Cookie::is_set() );
	}

	public function test_has_consent_returns_false_when_cookie_absent(): void {
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'analytics' ) );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'marketing' ) );
	}

	public function test_has_consent_rejects_unknown_categories(): void {
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => true,
			]
		);
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'fingerprinting' ) );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( '' ) );
	}

	// ---------------------------------------------------------------------
	// set_consent round-trips
	// ---------------------------------------------------------------------

	public function test_set_consent_grants_both_categories(): void {
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => true,
			]
		);

		$this->assertTrue( Segmentflow_Consent_Cookie::is_set() );
		$this->assertTrue( Segmentflow_Consent_Cookie::has_consent( 'analytics' ) );
		$this->assertTrue( Segmentflow_Consent_Cookie::has_consent( 'marketing' ) );
	}

	public function test_set_consent_records_explicit_denial(): void {
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => false,
				'marketing' => false,
			]
		);

		// Visitor refused — cookie is still considered set.
		$this->assertTrue( Segmentflow_Consent_Cookie::is_set() );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'analytics' ) );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'marketing' ) );
	}

	public function test_set_consent_supports_per_category_split(): void {
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => false,
			]
		);

		$this->assertTrue( Segmentflow_Consent_Cookie::has_consent( 'analytics' ) );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'marketing' ) );
	}

	public function test_set_consent_merges_partial_updates(): void {
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => true,
			]
		);
		// User later revokes only marketing.
		Segmentflow_Consent_Cookie::set_consent( [ 'marketing' => false ] );

		$this->assertTrue( Segmentflow_Consent_Cookie::has_consent( 'analytics' ) );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'marketing' ) );
	}

	public function test_set_consent_ignores_unknown_categories(): void {
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics'      => true,
				'fingerprinting' => true, // bogus
				'marketing'      => false,
			]
		);

		$this->assertTrue( Segmentflow_Consent_Cookie::has_consent( 'analytics' ) );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'marketing' ) );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'fingerprinting' ) );
	}

	// ---------------------------------------------------------------------
	// clear
	// ---------------------------------------------------------------------

	public function test_clear_removes_cookie(): void {
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => true,
			]
		);
		$this->assertTrue( Segmentflow_Consent_Cookie::is_set() );

		Segmentflow_Consent_Cookie::clear();
		$this->assertFalse( Segmentflow_Consent_Cookie::is_set() );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'analytics' ) );
	}

	// ---------------------------------------------------------------------
	// Malformed cookie defenses (gate relies on these to fail closed)
	// ---------------------------------------------------------------------

	public function test_garbage_cookie_is_treated_as_unset(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture, not a real request.
		$_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] = '!!!not-base64!!!';

		$this->assertFalse( Segmentflow_Consent_Cookie::is_set() );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'analytics' ) );
	}

	public function test_invalid_json_cookie_is_treated_as_unset(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] = base64_encode( 'not-json' );
		Segmentflow_Consent_Cookie::reset_cache();

		$this->assertFalse( Segmentflow_Consent_Cookie::is_set() );
	}

	public function test_cookie_without_recognized_keys_is_rejected(): void {
		// Tests defensive parsing — a cookie with neither `a` nor `m` is malformed.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$encoded = base64_encode( wp_json_encode( [ 'foo' => 'bar' ] ) );
		$_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] = $encoded;
		Segmentflow_Consent_Cookie::reset_cache();

		$this->assertFalse( Segmentflow_Consent_Cookie::is_set() );
	}

	// ---------------------------------------------------------------------
	// Cross-platform format compatibility (PHP <-> JS round-trip)
	// ---------------------------------------------------------------------

	public function test_php_writes_format_browser_can_read(): void {
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => false,
			]
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = $_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ];

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = json_decode( base64_decode( $raw ), true );

		$this->assertIsArray( $decoded );
		$this->assertSame( 1, $decoded['a'] );
		$this->assertSame( 0, $decoded['m'] );
	}

	public function test_browser_format_is_readable_by_php(): void {
		// Simulate the cookie shape the Browser Consent SDK writes.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$browser_cookie = base64_encode( '{"a":1,"m":0}' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] = $browser_cookie;
		Segmentflow_Consent_Cookie::reset_cache();

		$this->assertTrue( Segmentflow_Consent_Cookie::is_set() );
		$this->assertTrue( Segmentflow_Consent_Cookie::has_consent( 'analytics' ) );
		$this->assertFalse( Segmentflow_Consent_Cookie::has_consent( 'marketing' ) );
	}
}
