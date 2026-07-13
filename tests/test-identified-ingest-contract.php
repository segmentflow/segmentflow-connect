<?php
/**
 * Local contract tests for the identified-ingest fixture.
 *
 * @package Segmentflow_Connect
 */

// phpcs:disable WordPress.WP.CapitalPDangit -- 'wordpress' is a frozen platform/source identifier.

/**
 * Class Test_Identified_Ingest_Contract
 */
class Test_Identified_Ingest_Contract extends WP_UnitTestCase {

	/**
	 * Loaded fixture document.
	 *
	 * @var array<string, mixed>
	 */
	private array $fixture;

	/**
	 * Set up fixture.
	 */
	public function set_up(): void {
		parent::set_up();
		$path = __DIR__ . '/fixtures/identified-ingest-payloads.json';
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $decoded );
		$this->fixture = $decoded;
	}

	/**
	 * Fixture covers each supported server producer and not order/refund invents.
	 */
	public function test_fixture_covers_supported_producers_only(): void {
		$batches = $this->fixture['batches'];
		$this->assertIsArray( $batches );

		$expected = [
			'wordpress_registration',
			'wordpress_login',
			'wordpress_comment',
			'wordpress_form_submission',
			'woocommerce_add_to_cart',
			'woocommerce_guest_checkout',
			'woocommerce_account_checkout',
		];
		$this->assertSame( $expected, array_keys( $batches ) );

		$notes = implode( ' ', $this->fixture['notes'] ?? [] );
		$this->assertStringContainsString( 'Order/refund', $notes );
	}

	/**
	 * Every item satisfies the frozen identified-ingest contract.
	 */
	public function test_every_item_matches_identified_contract(): void {
		$forbidden = [
			'anonymousId',
			'organizationId',
			'sourceInstanceId',
			'identityNamespace',
			'profileId',
			'occurrenceKey',
		];

		foreach ( $this->fixture['batches'] as $name => $envelope ) {
			$this->assertArrayHasKey( 'writeKey', $envelope, $name );
			$this->assertArrayHasKey( 'batch', $envelope, $name );
			$this->assertIsArray( $envelope['batch'], $name );
			$this->assertNotEmpty( $envelope['batch'], $name );

			foreach ( $forbidden as $key ) {
				$this->assertArrayNotHasKey( $key, $envelope, "{$name} envelope" );
			}

			$previous_index = -1;
			foreach ( $envelope['batch'] as $index => $item ) {
				$this->assertSame( $previous_index + 1, $index, "{$name} order" );
				$previous_index = $index;

				$this->assertIsArray( $item, $name );
				$this->assertNotEmpty( $item['messageId'] ?? '', "{$name}[{$index}] messageId" );
				$this->assertStringStartsWith( 'sfc:v1:', $item['messageId'] );

				foreach ( $forbidden as $key ) {
					$this->assertArrayNotHasKey( $key, $item, "{$name}[{$index}]" );
				}

				// Session / cart-hash must never be identity locators.
				$this->assertArrayNotHasKey( 'sessionId', $item, "{$name}[{$index}]" );
				if ( isset( $item['userId'] ) ) {
					$this->assertMatchesRegularExpression( '/^(wp_|wc_)\d+$/', (string) $item['userId'], "{$name}[{$index}] userId" );
					$this->assertStringNotContainsString( '@', (string) $item['userId'] );
				}

				$type = $item['type'] ?? '';
				if ( 'identify' === $type ) {
					$this->assertNotEmpty( $item['email'] ?? '', "{$name}[{$index}] identify email" );
				} elseif ( in_array( $type, [ 'track', 'page' ], true ) ) {
					$has_email   = ! empty( $item['email'] );
					$has_user_id = ! empty( $item['userId'] );
					$this->assertTrue( $has_email || $has_user_id, "{$name}[{$index}] track/page identity" );
				} else {
					$this->fail( "{$name}[{$index}] unexpected type {$type}" );
				}

				if ( isset( $item['properties']['cart_hash'] ) ) {
					$this->assertIsString( $item['properties']['cart_hash'] );
					$this->assertNotSame( $item['userId'] ?? null, $item['properties']['cart_hash'] );
				}
			}
		}
	}

	/**
	 * Form identify/track share the same base UUID occurrence.
	 */
	public function test_form_pair_shares_base_uuid(): void {
		$batch = $this->fixture['batches']['wordpress_form_submission']['batch'];
		$this->assertCount( 2, $batch );

		$identify_id = $batch[0]['messageId'];
		$track_id    = $batch[1]['messageId'];

		$this->assertStringStartsWith( 'sfc:v1:wordpress:form:identify:', $identify_id );
		$this->assertStringStartsWith( 'sfc:v1:wordpress:form:track:', $track_id );

		$identify_uuid = substr( $identify_id, strlen( 'sfc:v1:wordpress:form:identify:' ) );
		$track_uuid    = substr( $track_id, strlen( 'sfc:v1:wordpress:form:track:' ) );
		$this->assertSame( $identify_uuid, $track_uuid );
	}
}
