<?php
/**
 * Tests for WooCommerce locale resolution and order meta stamping.
 *
 * @package Segmentflow_Connect
 */

require_once __DIR__ . '/helpers/class-mock-segmentflow-api.php';

/**
 * Class Test_WC_Locale
 */
class Test_WC_Locale extends WP_UnitTestCase {

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * Mock API instance.
	 *
	 * @var Mock_Segmentflow_API
	 */
	private Mock_Segmentflow_API $mock_api;

	/**
	 * Server events instance under test.
	 *
	 * @var Segmentflow_WC_Server_Events
	 */
	private Segmentflow_WC_Server_Events $server_events;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active in this environment.' );
		}

		update_option( 'segmentflow_write_key', 'test-write-key-123' );

		$this->options       = new Segmentflow_Options();
		$this->mock_api      = new Mock_Segmentflow_API( $this->options );
		$this->server_events = new Segmentflow_WC_Server_Events( $this->options, $this->mock_api, new Segmentflow_Ingest_Client( $this->options, $this->mock_api ) );

		Segmentflow_Identity_Cookie::reset_cache();
		Segmentflow_Consent_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] );
		unset( $_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		remove_all_filters( 'determine_locale' );
		remove_all_filters( 'locale' );
		delete_option( 'segmentflow_write_key' );
		parent::tear_down();
	}

	/**
	 * Test resolve_locale prefers determine_locale() output.
	 */
	public function test_resolve_locale_uses_determine_locale(): void {
		add_filter(
			'determine_locale',
			static function (): string {
				return 'en_US';
			}
		);

		$user_id = $this->factory->user->create();
		update_user_meta( $user_id, 'locale', 'ja' );

		$this->assertSame( 'en_US', Segmentflow_WC_Helper::resolve_locale( $user_id ) );
	}

	/**
	 * Test resolve_locale falls back to the logged-in user locale.
	 */
	public function test_resolve_locale_falls_back_to_user_locale(): void {
		add_filter(
			'determine_locale',
			static function (): string {
				return '';
			}
		);

		$user_id = $this->factory->user->create();
		update_user_meta( $user_id, 'locale', 'ja' );

		$this->assertSame( 'ja', Segmentflow_WC_Helper::resolve_locale( $user_id ) );
	}

	/**
	 * Test resolve_locale falls back to the site default locale.
	 */
	public function test_resolve_locale_falls_back_to_site_locale(): void {
		add_filter(
			'determine_locale',
			static function (): string {
				return '';
			}
		);
		add_filter(
			'locale',
			static function (): string {
				return 'de_DE';
			}
		);

		$this->assertSame( 'de_DE', Segmentflow_WC_Helper::resolve_locale( null ) );
	}

	/**
	 * Test resolve_locale does not infer language from billing country.
	 */
	public function test_resolve_locale_does_not_infer_from_country(): void {
		add_filter(
			'determine_locale',
			static function (): string {
				return 'en_US';
			}
		);

		$order = wc_create_order();
		$order->set_billing_country( 'JP' );
		$order->save();

		$this->assertSame( 'en_US', Segmentflow_WC_Helper::resolve_locale( null ) );

		$order->delete( true );
	}

	/**
	 * Test classic checkout stamps _segmentflow_locale order meta.
	 */
	public function test_checkout_stamps_locale_meta(): void {
		add_filter(
			'determine_locale',
			static function (): string {
				return 'ja';
			}
		);
		$this->set_identity( [ 'a' => 'anon-locale-1' ] );

		$order = wc_create_order();
		$order->set_billing_email( 'locale@example.com' );
		$order->save();

		$this->server_events->on_checkout( $order->get_id(), [], $order );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'ja', $reloaded->get_meta( Segmentflow_WC_Helper::LOCALE_META_KEY ) );

		$order->delete( true );
	}

	/**
	 * Test Blocks checkout stamps _segmentflow_locale order meta.
	 */
	public function test_blocks_checkout_stamps_locale_meta(): void {
		add_filter(
			'determine_locale',
			static function (): string {
				return 'ja_JP';
			}
		);

		$order = wc_create_order();
		$order->save();

		$this->server_events->on_blocks_checkout( $order );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'ja_JP', $reloaded->get_meta( Segmentflow_WC_Helper::LOCALE_META_KEY ) );

		$order->delete( true );
	}

	/**
	 * Test locale meta is not overwritten when already present.
	 */
	public function test_locale_meta_is_idempotent(): void {
		add_filter(
			'determine_locale',
			static function (): string {
				return 'fr_FR';
			}
		);

		$order = wc_create_order();
		$order->update_meta_data( Segmentflow_WC_Helper::LOCALE_META_KEY, 'ja' );
		$order->save();

		$this->server_events->on_blocks_checkout( $order );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'ja', $reloaded->get_meta( Segmentflow_WC_Helper::LOCALE_META_KEY ) );

		$order->delete( true );
	}

	/**
	 * Test WooCommerce tracking context exposes context.locale.
	 */
	public function test_tracking_context_includes_locale(): void {
		add_filter(
			'determine_locale',
			static function (): string {
				return 'en_US';
			}
		);

		$options     = new Segmentflow_Options();
		$tracking    = new Segmentflow_Tracking( $options );
		$wc_tracking = new Segmentflow_WC_Tracking( $options, $tracking );

		$context = $wc_tracking->add_woocommerce_context( [] );

		$this->assertArrayHasKey( 'context', $context );
		$this->assertArrayHasKey( 'locale', $context['context'] );
		$this->assertSame( 'en_US', $context['context']['locale'] );
	}

	/**
	 * Test Blocks checkout hook is registered when connected.
	 */
	public function test_blocks_checkout_hook_registered_when_connected(): void {
		$this->server_events->register_hooks();

		$this->assertSame(
			10,
			has_action( 'woocommerce_store_api_checkout_order_processed', [ $this->server_events, 'on_blocks_checkout' ] )
		);
	}

	/**
	 * Set the sf_id identity cookie for the current test.
	 *
	 * @param array<string, string> $data Identity fields.
	 */
	private function set_identity( array $data ): void {
		Segmentflow_Identity_Cookie::reset_cache();
		Segmentflow_Consent_Cookie::reset_cache();
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => true,
			]
		);
		Segmentflow_Identity_Cookie::write( $data );
	}
}
