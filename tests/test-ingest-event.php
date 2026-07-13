<?php
/**
 * Tests for Segmentflow_Ingest_Event builders.
 *
 * @package Segmentflow_Connect
 */

// phpcs:disable WordPress.WP.CapitalPDangit -- 'wordpress' is a frozen platform/source identifier.

/**
 * Class Test_Ingest_Event
 */
class Test_Ingest_Event extends WP_UnitTestCase {

	/**
	 * Identify rejects missing email.
	 */
	public function test_identify_rejects_missing_email(): void {
		$this->expectException( InvalidArgumentException::class );
		Segmentflow_Ingest_Event::identify( '', 'sfc:v1:test:abc' );
	}

	/**
	 * Identify rejects invalid email.
	 */
	public function test_identify_rejects_invalid_email(): void {
		$this->expectException( InvalidArgumentException::class );
		Segmentflow_Ingest_Event::identify( 'not-an-email', 'sfc:v1:test:abc' );
	}

	/**
	 * Identify requires messageId.
	 */
	public function test_identify_requires_message_id(): void {
		$this->expectException( InvalidArgumentException::class );
		Segmentflow_Ingest_Event::identify( 'a@example.com', '' );
	}

	/**
	 * Identify places email top-level and preserves optional fields.
	 */
	public function test_identify_shape_matches_frozen_contract(): void {
		$item = Segmentflow_Ingest_Event::identify(
			'  Alice@Example.COM ',
			'sfc:v1:wordpress:user_registered:identify:hash',
			'wp_42',
			[
				'email'      => 'alice@example.com',
				'first_name' => 'Alice',
			],
			'2026-07-13T12:00:00+00:00',
			'wordpress'
		);

		$this->assertSame(
			[
				'type'      => 'identify',
				'messageId' => 'sfc:v1:wordpress:user_registered:identify:hash',
				'email'     => 'alice@example.com',
				'userId'    => 'wp_42',
				'traits'    => [
					'email'      => 'alice@example.com',
					'first_name' => 'Alice',
				],
				'timestamp' => '2026-07-13T12:00:00+00:00',
				'source'    => 'wordpress',
			],
			$item
		);
		$this->assertArrayNotHasKey( 'anonymousId', $item );
		$this->assertArrayNotHasKey( 'organizationId', $item );
		$this->assertArrayNotHasKey( 'sourceInstanceId', $item );
		$this->assertArrayNotHasKey( 'identityNamespace', $item );
		$this->assertArrayNotHasKey( 'profileId', $item );
		$this->assertArrayNotHasKey( 'occurrenceKey', $item );
	}

	/**
	 * Track rejects when both email and userId are absent.
	 */
	public function test_track_rejects_missing_identity(): void {
		$this->expectException( InvalidArgumentException::class );
		Segmentflow_Ingest_Event::track( 'add_to_cart', 'sfc:v1:test:abc' );
	}

	/**
	 * Track accepts email-only identity.
	 */
	public function test_track_accepts_email_only(): void {
		$item = Segmentflow_Ingest_Event::track(
			'form_submission',
			'sfc:v1:wordpress:form:track:uuid',
			'guest@example.com',
			null,
			[ 'form_id' => 'contact' ],
			null,
			'wordpress'
		);

		$this->assertSame( 'track', $item['type'] );
		$this->assertSame( 'guest@example.com', $item['email'] );
		$this->assertArrayNotHasKey( 'userId', $item );
		$this->assertSame( 'form_submission', $item['event'] );
		$this->assertSame( [ 'form_id' => 'contact' ], $item['properties'] );
	}

	/**
	 * Track accepts userId-only identity.
	 */
	public function test_track_accepts_user_id_only(): void {
		$item = Segmentflow_Ingest_Event::track(
			'add_to_cart',
			'sfc:v1:woocommerce:cart:uuid',
			null,
			'wc_42',
			[ 'product_id' => 9 ]
		);

		$this->assertSame( 'wc_42', $item['userId'] );
		$this->assertArrayNotHasKey( 'email', $item );
	}

	/**
	 * Page rejects when both email and userId are absent.
	 */
	public function test_page_rejects_missing_identity(): void {
		$this->expectException( InvalidArgumentException::class );
		Segmentflow_Ingest_Event::page( 'sfc:v1:test:abc' );
	}

	/**
	 * Page preserves optional name/properties/source/timestamp.
	 */
	public function test_page_shape_matches_frozen_contract(): void {
		$item = Segmentflow_Ingest_Event::page(
			'sfc:v1:page:uuid',
			'reader@example.com',
			'wp_7',
			'Home',
			[ 'path' => '/' ],
			'2026-07-13T12:00:00+00:00',
			'wordpress'
		);

		$this->assertSame(
			[
				'type'       => 'page',
				'messageId'  => 'sfc:v1:page:uuid',
				'email'      => 'reader@example.com',
				'userId'     => 'wp_7',
				'name'       => 'Home',
				'properties' => [ 'path' => '/' ],
				'timestamp'  => '2026-07-13T12:00:00+00:00',
				'source'     => 'wordpress',
			],
			$item
		);
	}

	/**
	 * wp_* and wc_* remain external user IDs only.
	 */
	public function test_external_user_ids_are_opaque_strings(): void {
		$wp = Segmentflow_Ingest_Event::identify( 'a@example.com', 'sfc:v1:a', 'wp_42' );
		$wc = Segmentflow_Ingest_Event::identify( 'b@example.com', 'sfc:v1:b', 'wc_42' );

		$this->assertSame( 'wp_42', $wp['userId'] );
		$this->assertSame( 'wc_42', $wc['userId'] );
	}

	/**
	 * Deterministic message IDs hash the canonical input.
	 */
	public function test_deterministic_message_id(): void {
		$input = 'wordpress:user_registered:identify:42';
		$id    = Segmentflow_Ingest_Event::deterministic_message_id( 'wordpress:user_registered:identify', $input );

		$this->assertSame(
			'sfc:v1:wordpress:user_registered:identify:' . hash( 'sha256', $input ),
			$id
		);
	}

	/**
	 * Occurrence message IDs embed the pre-generated UUID.
	 */
	public function test_occurrence_message_id(): void {
		$uuid = '01926a3b-4c5d-7e8f-9a0b-1c2d3e4f5a6b';
		$id   = Segmentflow_Ingest_Event::occurrence_message_id( 'wordpress:login', $uuid );
		$this->assertSame( 'sfc:v1:wordpress:login:' . $uuid, $id );
	}
}
