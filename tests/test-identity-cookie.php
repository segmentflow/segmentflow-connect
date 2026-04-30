<?php
/**
 * Tests for the unified identity cookie.
 *
 * @package Segmentflow_Connect
 */

/**
 * Class Test_Identity_Cookie
 *
 * Tests the Segmentflow_Identity_Cookie class: read/write round-trip,
 * UUIDv7 generation, merge-on-write semantics, and legacy migration.
 */
class Test_Identity_Cookie extends WP_UnitTestCase {

	/**
	 * Reset cookie state before each test.
	 *
	 * Most existing tests assume a consenting visitor — they exercise the
	 * round-trip behavior of `Identity_Cookie::write()` which is gated on
	 * `sf_consent` since #105. Tests that need to exercise the gate
	 * itself (no-consent path) call `clear_consent()` explicitly.
	 */
	public function set_up(): void {
		parent::set_up();
		Segmentflow_Identity_Cookie::reset_cache();
		Segmentflow_Consent_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] );
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::LEGACY_ANON_COOKIE ] );
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::LEGACY_USER_COOKIE ] );
		unset( $_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] );
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => true,
			]
		);
	}

	/**
	 * Tear-down: clear sf_consent cookie state. Tests that disable consent
	 * mid-test should reset both caches afterwards via clear_consent().
	 */
	private function clear_consent(): void {
		Segmentflow_Consent_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] );
	}

	/**
	 * Test that read() returns null when no cookie exists.
	 */
	public function test_read_returns_null_when_empty(): void {
		$this->assertNull( Segmentflow_Identity_Cookie::read() );
	}

	/**
	 * Test write() then read() round-trip.
	 */
	public function test_write_read_round_trip(): void {
		Segmentflow_Identity_Cookie::write( [ 'a' => 'test-anon-id' ] );

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertNotNull( $data );
		$this->assertSame( 'test-anon-id', $data['a'] );
	}

	/**
	 * Test that write() merges fields (does not overwrite).
	 */
	public function test_write_merges_fields(): void {
		Segmentflow_Identity_Cookie::write( [ 'a' => 'anon-123' ] );
		Segmentflow_Identity_Cookie::write( [ 'e' => 'test@example.com' ] );

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertSame( 'anon-123', $data['a'] );
		$this->assertSame( 'test@example.com', $data['e'] );
	}

	/**
	 * Test that write() overwrites conflicting fields.
	 */
	public function test_write_overwrites_conflicts(): void {
		Segmentflow_Identity_Cookie::write(
			[
				'a' => 'anon-123',
				'e' => 'old@example.com',
			]
		);
		Segmentflow_Identity_Cookie::write( [ 'e' => 'new@example.com' ] );

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertSame( 'anon-123', $data['a'] );
		$this->assertSame( 'new@example.com', $data['e'] );
	}

	/**
	 * Test that write() strips empty values.
	 */
	public function test_write_strips_empty_values(): void {
		Segmentflow_Identity_Cookie::write(
			[
				'a' => 'anon-123',
				'e' => 'test@example.com',
			]
		);
		Segmentflow_Identity_Cookie::write( [ 'e' => '' ] );

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertSame( 'anon-123', $data['a'] );
		$this->assertArrayNotHasKey( 'e', $data );
	}

	/**
	 * Test ensure_anonymous_id() generates UUIDv7 when cookie is missing.
	 */
	public function test_ensure_anonymous_id_generates_uuidv7(): void {
		$anon_id = Segmentflow_Identity_Cookie::ensure_anonymous_id();

		$this->assertNotEmpty( $anon_id );
		// UUIDv7 format: 8-4-4-4-12 hex characters.
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$anon_id
		);
	}

	/**
	 * Test ensure_anonymous_id() reuses existing anonymous ID.
	 */
	public function test_ensure_anonymous_id_reuses_existing(): void {
		Segmentflow_Identity_Cookie::write( [ 'a' => 'existing-anon-id' ] );

		$anon_id = Segmentflow_Identity_Cookie::ensure_anonymous_id();
		$this->assertSame( 'existing-anon-id', $anon_id );
	}

	/**
	 * Test legacy sf_anon_id cookie migration.
	 */
	public function test_legacy_anon_cookie_migration(): void {
		$_COOKIE[ Segmentflow_Identity_Cookie::LEGACY_ANON_COOKIE ] = 'legacy-anon-123';

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertNotNull( $data );
		$this->assertSame( 'legacy-anon-123', $data['a'] );
	}

	/**
	 * Test legacy sf_anon_id + sf_user_id cookie migration.
	 */
	public function test_legacy_both_cookies_migration(): void {
		$_COOKIE[ Segmentflow_Identity_Cookie::LEGACY_ANON_COOKIE ] = 'legacy-anon-456';
		$_COOKIE[ Segmentflow_Identity_Cookie::LEGACY_USER_COOKIE ] = 'wc_42';

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertNotNull( $data );
		$this->assertSame( 'legacy-anon-456', $data['a'] );
		$this->assertSame( 'wc_42', $data['u'] );
	}

	/**
	 * Test that unified cookie takes precedence over legacy cookies.
	 */
	public function test_unified_cookie_takes_precedence(): void {
		// Set both: unified and legacy.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test cookie simulation.
		$unified = base64_encode( wp_json_encode( [ 'a' => 'unified-anon' ] ) );
		$_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ]        = $unified;
		$_COOKIE[ Segmentflow_Identity_Cookie::LEGACY_ANON_COOKIE ] = 'legacy-anon';

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertSame( 'unified-anon', $data['a'] );
	}

	/**
	 * Test set_email() merges email into cookie.
	 */
	public function test_set_email_merges(): void {
		Segmentflow_Identity_Cookie::write( [ 'a' => 'anon-xyz' ] );
		Segmentflow_Identity_Cookie::set_email( 'user@example.com' );

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertSame( 'anon-xyz', $data['a'] );
		$this->assertSame( 'user@example.com', $data['e'] );
	}

	/**
	 * Test set_email() ignores invalid email.
	 */
	public function test_set_email_ignores_invalid(): void {
		Segmentflow_Identity_Cookie::write( [ 'a' => 'anon-xyz' ] );
		Segmentflow_Identity_Cookie::set_email( 'not-an-email' );

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertArrayNotHasKey( 'e', $data );
	}

	/**
	 * Test set_phone() merges phone into cookie.
	 */
	public function test_set_phone_merges(): void {
		Segmentflow_Identity_Cookie::write( [ 'a' => 'anon-xyz' ] );
		Segmentflow_Identity_Cookie::set_phone( '+1234567890' );

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertSame( 'anon-xyz', $data['a'] );
		$this->assertSame( '+1234567890', $data['p'] );
	}

	/**
	 * Test set_phone() ignores empty phone.
	 */
	public function test_set_phone_ignores_empty(): void {
		Segmentflow_Identity_Cookie::write( [ 'a' => 'anon-xyz' ] );
		Segmentflow_Identity_Cookie::set_phone( '' );

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertArrayNotHasKey( 'p', $data );
	}

	/**
	 * Test that read() handles corrupt base64 gracefully.
	 */
	public function test_read_handles_corrupt_cookie(): void {
		$_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] = '!!!not-base64!!!';

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertNull( $data );
	}

	/**
	 * Test that read() handles valid base64 but invalid JSON gracefully.
	 */
	public function test_read_handles_invalid_json(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] = base64_encode( 'not-json' );

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertNull( $data );
	}

	/**
	 * Test that read() rejects cookie without anonymous ID.
	 */
	public function test_read_rejects_missing_anonymous_id(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] = base64_encode(
			wp_json_encode( [ 'e' => 'user@example.com' ] )
		);

		$data = Segmentflow_Identity_Cookie::read();
		$this->assertNull( $data );
	}

	/**
	 * Test get_user_id() returns null for anonymous visitors.
	 */
	public function test_get_user_id_returns_null_for_anonymous(): void {
		$this->assertNull( Segmentflow_Identity_Cookie::get_user_id() );
	}

	/**
	 * Test get_user_id() returns cookie value when present.
	 */
	public function test_get_user_id_returns_cookie_value(): void {
		Segmentflow_Identity_Cookie::write(
			[
				'a' => 'anon-123',
				'u' => 'wc_99',
			]
		);

		$this->assertSame( 'wc_99', Segmentflow_Identity_Cookie::get_user_id() );
	}

	/**
	 * Test that multiple UUIDv7 generations produce unique values.
	 */
	public function test_uuidv7_uniqueness(): void {
		$ids = [];
		for ( $i = 0; $i < 10; $i++ ) {
			Segmentflow_Identity_Cookie::reset_cache();
			unset( $_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] );
			$ids[] = Segmentflow_Identity_Cookie::ensure_anonymous_id();
		}

		$unique = array_unique( $ids );
		$this->assertCount( 10, $unique, 'All generated UUIDs should be unique' );
	}

	// -------------------------------------------------------------------------
	// Consent gate tests (#105)
	// -------------------------------------------------------------------------

	/**
	 * Test ensure_anonymous_id returns empty string and does not write a
	 * cookie when sf_consent is absent.
	 */
	public function test_ensure_anonymous_id_skips_without_consent(): void {
		$this->clear_consent();
		Segmentflow_Identity_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] );

		$result = Segmentflow_Identity_Cookie::ensure_anonymous_id();

		$this->assertSame( '', $result );
		$this->assertNull( Segmentflow_Identity_Cookie::read() );
		$this->assertArrayNotHasKey(
			Segmentflow_Identity_Cookie::COOKIE_NAME,
			$_COOKIE
		);
	}

	/**
	 * Test that ensure_anonymous_id resumes writing once sf_consent is set.
	 */
	public function test_ensure_anonymous_id_writes_after_consent_granted(): void {
		$this->clear_consent();
		Segmentflow_Identity_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] );

		// First call: no consent → no cookie.
		$this->assertSame( '', Segmentflow_Identity_Cookie::ensure_anonymous_id() );

		// Now grant consent and try again.
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => true,
			]
		);
		Segmentflow_Identity_Cookie::reset_cache();

		$anon_id = Segmentflow_Identity_Cookie::ensure_anonymous_id();
		$this->assertNotEmpty( $anon_id );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$anon_id
		);
	}

	/**
	 * Test that write() is a no-op without sf_consent.
	 */
	public function test_write_is_noop_without_consent(): void {
		$this->clear_consent();
		Segmentflow_Identity_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] );

		Segmentflow_Identity_Cookie::write( [ 'a' => 'should-not-be-written' ] );

		$this->assertNull( Segmentflow_Identity_Cookie::read() );
	}

	/**
	 * Test that ensure_anonymous_id reuses an existing sf_id even when
	 * sf_consent is absent — the gate only blocks new writes, not reads.
	 */
	public function test_ensure_anonymous_id_reuses_existing_without_consent(): void {
		// Seed sf_id with consent first.
		Segmentflow_Identity_Cookie::write( [ 'a' => 'pre-existing-anon' ] );

		// Now revoke (clear) consent and call ensure.
		$this->clear_consent();
		Segmentflow_Identity_Cookie::reset_cache();

		$anon_id = Segmentflow_Identity_Cookie::ensure_anonymous_id();
		$this->assertSame( 'pre-existing-anon', $anon_id );
	}
}
