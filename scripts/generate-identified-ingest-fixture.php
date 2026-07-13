<?php
/**
 * Generate deterministic identified-ingest fixtures from the real builder.
 *
 * Usage: php scripts/generate-identified-ingest-fixture.php
 *
 * Does not boot WordPress. Relies on lightweight stubs for sanitize_email /
 * is_email so the pure builder can normalize emails.
 *
 * @package Segmentflow_Connect
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.CapitalPDangit -- 'wordpress' is a frozen platform/source identifier.

// ---------------------------------------------------------------------------
// Minimal WordPress function stubs for the pure builder.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return string
	 */
	function sanitize_email( $email ) {
		return strtolower( trim( (string) $email ) );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return bool
	 */
	function is_email( $email ) {
		return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../includes/class-segmentflow-ingest-event.php';

const FIXTURE_TIMESTAMP  = '2026-07-13T12:00:00+00:00';
const FIXTURE_LOGIN_UUID = '01926a3b-4c5d-7e8f-9a0b-1c2d3e4f5a6b';
const FIXTURE_FORM_UUID  = '01926a3b-4c5d-7e8f-9a0b-1c2d3e4f5a6c';
const FIXTURE_CART_UUID  = '01926a3b-4c5d-7e8f-9a0b-1c2d3e4f5a6d';

/**
 * Build the fixture document.
 *
 * @return array<string, mixed>
 */
function segmentflow_build_identified_ingest_fixture(): array {
	$batches = [];

	// Plain WordPress registration: identify → track.
	$reg_user_id                       = 42;
	$batches['wordpress_registration'] = [
		'writeKey' => 'wk_fixture',
		'consent'  => [
			'analytics' => true,
			'marketing' => false,
		],
		'batch'    => [
			Segmentflow_Ingest_Event::identify(
				'alice@example.com',
				Segmentflow_Ingest_Event::deterministic_message_id(
					'wordpress:user_registered:identify',
					'wordpress:user_registered:identify:' . $reg_user_id
				),
				'wp_' . $reg_user_id,
				[
					'email'      => 'alice@example.com',
					'first_name' => 'Alice',
				],
				FIXTURE_TIMESTAMP,
				'wordpress'
			),
			Segmentflow_Ingest_Event::track(
				'user_registered',
				Segmentflow_Ingest_Event::deterministic_message_id(
					'wordpress:user_registered:track',
					'wordpress:user_registered:track:' . $reg_user_id
				),
				'alice@example.com',
				'wp_' . $reg_user_id,
				[
					'user_id' => $reg_user_id,
					'email'   => 'alice@example.com',
				],
				FIXTURE_TIMESTAMP,
				'wordpress'
			),
		],
	];

	// WordPress login identify.
	$batches['wordpress_login'] = [
		'writeKey' => 'wk_fixture',
		'batch'    => [
			Segmentflow_Ingest_Event::identify(
				'alice@example.com',
				Segmentflow_Ingest_Event::occurrence_message_id( 'wordpress:login', FIXTURE_LOGIN_UUID ),
				'wp_42',
				[ 'email' => 'alice@example.com' ],
				FIXTURE_TIMESTAMP,
				'wordpress'
			),
		],
	];

	// WordPress comment: identify → track.
	$comment_id                   = 99;
	$batches['wordpress_comment'] = [
		'writeKey' => 'wk_fixture',
		'batch'    => [
			Segmentflow_Ingest_Event::identify(
				'commenter@example.com',
				Segmentflow_Ingest_Event::deterministic_message_id(
					'wordpress:comment:identify',
					'wordpress:comment:identify:' . $comment_id
				),
				null,
				[
					'email' => 'commenter@example.com',
					'name'  => 'Pat',
				],
				FIXTURE_TIMESTAMP,
				'wordpress'
			),
			Segmentflow_Ingest_Event::track(
				'comment_posted',
				Segmentflow_Ingest_Event::deterministic_message_id(
					'wordpress:comment:track',
					'wordpress:comment:track:' . $comment_id
				),
				'commenter@example.com',
				null,
				[
					'comment_id' => $comment_id,
					'post_id'    => 7,
					'email'      => 'commenter@example.com',
				],
				FIXTURE_TIMESTAMP,
				'wordpress'
			),
		],
	];

	// Form submission: identify → track (shared base UUID).
	$batches['wordpress_form_submission'] = [
		'writeKey' => 'wk_fixture',
		'batch'    => [
			Segmentflow_Ingest_Event::identify(
				'form@example.com',
				Segmentflow_Ingest_Event::occurrence_message_id( 'wordpress:form:identify', FIXTURE_FORM_UUID ),
				null,
				[ 'email' => 'form@example.com' ],
				FIXTURE_TIMESTAMP,
				'wordpress'
			),
			Segmentflow_Ingest_Event::track(
				'form_submission',
				Segmentflow_Ingest_Event::occurrence_message_id( 'wordpress:form:track', FIXTURE_FORM_UUID ),
				'form@example.com',
				null,
				[
					'form_type'  => 'cf7',
					'form_title' => 'Contact',
					'email'      => 'form@example.com',
				],
				FIXTURE_TIMESTAMP,
				'wordpress'
			),
		],
	];

	// WooCommerce identified cart mutation.
	$batches['woocommerce_add_to_cart'] = [
		'writeKey' => 'wk_fixture',
		'batch'    => [
			Segmentflow_Ingest_Event::track(
				'add_to_cart',
				Segmentflow_Ingest_Event::occurrence_message_id( 'woocommerce:cart:add', FIXTURE_CART_UUID ),
				'buyer@example.com',
				'wc_15',
				[
					'product_id' => 10,
					'quantity'   => 1,
					'currency'   => 'USD',
					'cart_hash'  => 'hash-not-identity',
				],
				FIXTURE_TIMESTAMP,
				'woocommerce'
			),
		],
	];

	// Guest checkout identify (email only).
	$guest_order_id                        = 1001;
	$batches['woocommerce_guest_checkout'] = [
		'writeKey' => 'wk_fixture',
		'batch'    => [
			Segmentflow_Ingest_Event::identify(
				'guest@example.com',
				Segmentflow_Ingest_Event::deterministic_message_id(
					'woocommerce:checkout:identify',
					'woocommerce:checkout:identify:' . $guest_order_id
				),
				null,
				[ 'email' => 'guest@example.com' ],
				FIXTURE_TIMESTAMP,
				'woocommerce'
			),
		],
	];

	// Account checkout identify.
	$account_order_id                        = 1002;
	$batches['woocommerce_account_checkout'] = [
		'writeKey' => 'wk_fixture',
		'batch'    => [
			Segmentflow_Ingest_Event::identify(
				'buyer@example.com',
				Segmentflow_Ingest_Event::deterministic_message_id(
					'woocommerce:checkout:identify',
					'woocommerce:checkout:identify:' . $account_order_id
				),
				'wc_15',
				[
					'email'      => 'buyer@example.com',
					'first_name' => 'Buyer',
				],
				FIXTURE_TIMESTAMP,
				'woocommerce'
			),
		],
	];

	return [
		'contractVersion' => 'identified-ingest-v1',
		'generatedAt'     => FIXTURE_TIMESTAMP,
		'notes'           => [
			'Generated by scripts/generate-identified-ingest-fixture.php from Segmentflow_Ingest_Event.',
			'Order/refund lifecycle events are intentionally absent — they remain trusted webhook/API behavior.',
		],
		'batches'         => $batches,
	];
}

$fixture  = segmentflow_build_identified_ingest_fixture();
$out_path = __DIR__ . '/../tests/fixtures/identified-ingest-payloads.json';

if ( ! is_dir( dirname( $out_path ) ) ) {
	mkdir( dirname( $out_path ), 0777, true );
}

$json = wp_json_encode_fixture( $fixture );
if ( false === file_put_contents( $out_path, $json ) ) {
	fwrite( STDERR, "Failed to write {$out_path}\n" );
	exit( 1 );
}

fwrite( STDOUT, "Wrote {$out_path}\n" );

/**
 * Stable JSON encode with pretty print and trailing newline.
 *
 * @param mixed $data Data.
 * @return string
 */
function wp_json_encode_fixture( $data ): string {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		throw new RuntimeException( 'Failed to encode fixture JSON.' );
	}
	return $json . "\n";
}
