<?php
/**
 * Tests for WordPress core server-side events.
 *
 * Tests the Segmentflow_Server_Events class: user_register, wp_login,
 * wp_insert_comment, and form submission handlers under the identified-only
 * ingest contract.
 *
 * @package Segmentflow_Connect
 */

// phpcs:disable WordPress.WP.CapitalPDangit -- 'wordpress' is a frozen platform/source identifier.

require_once __DIR__ . '/helpers/class-mock-segmentflow-api.php';

/**
 * Class Test_Server_Events
 *
 * Uses a mock API client to capture payloads sent by the server event hooks.
 */
class Test_Server_Events extends WP_UnitTestCase {

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * Mock API instance that captures requests.
	 *
	 * @var Mock_Segmentflow_API
	 */
	private Mock_Segmentflow_API $mock_api;

	/**
	 * Identified ingest client.
	 *
	 * @var Segmentflow_Ingest_Client
	 */
	private Segmentflow_Ingest_Client $ingest_client;

	/**
	 * The server events instance under test.
	 *
	 * @var Segmentflow_Server_Events
	 */
	private Segmentflow_Server_Events $server_events;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'segmentflow_write_key', 'test-write-key-456' );

		$this->options       = new Segmentflow_Options();
		$this->mock_api      = new Mock_Segmentflow_API( $this->options );
		$this->ingest_client = new Segmentflow_Ingest_Client( $this->options, $this->mock_api );
		$this->server_events = new Segmentflow_Server_Events( $this->options, $this->ingest_client );

		Segmentflow_Consent_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		delete_option( 'segmentflow_write_key' );
		Segmentflow_Consent_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Consent_Cookie::COOKIE_NAME ] );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// user_register tests
	// -------------------------------------------------------------------------

	/**
	 * Test on_user_register sends ordered identify then user_registered track.
	 */
	public function test_user_register_sends_identify_and_track(): void {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'newuser@example.com',
				'first_name' => 'Alice',
				'last_name'  => 'Smith',
			]
		);

		$user = get_userdata( $user_id );
		$this->server_events->on_user_register( $user_id, (array) $user->data );

		$this->assertCount( 1, $this->mock_api->requests );

		$request = $this->mock_api->requests[0];
		$batch   = $request['body']['batch'];
		$this->assertCount( 2, $batch );

		$prefix   = Segmentflow_Helper::is_woocommerce_active() ? 'wc_' : 'wp_';
		$expected = $prefix . $user_id;

		$identify = $batch[0];
		$this->assertSame( 'identify', $identify['type'] );
		$this->assertSame( 'newuser@example.com', $identify['email'] );
		$this->assertSame( $expected, $identify['userId'] );
		$this->assertSame( 'wordpress', $identify['source'] );
		$this->assertSame( 'newuser@example.com', $identify['traits']['email'] );
		$this->assertSame( 'Alice', $identify['traits']['first_name'] );
		$this->assertSame( 'Smith', $identify['traits']['last_name'] );
		$this->assertSame(
			Segmentflow_Ingest_Event::deterministic_message_id(
				'wordpress:user_registered:identify',
				'wordpress:user_registered:identify:' . $user_id
			),
			$identify['messageId']
		);
		$this->assert_no_anonymous_fields( $identify );

		$track = $batch[1];
		$this->assertSame( 'track', $track['type'] );
		$this->assertSame( 'user_registered', $track['event'] );
		$this->assertSame( 'newuser@example.com', $track['email'] );
		$this->assertSame( $expected, $track['userId'] );
		$this->assertSame( 'wordpress', $track['source'] );
		$this->assertSame( $user_id, $track['properties']['user_id'] );
		$this->assertSame( 'newuser@example.com', $track['properties']['email'] );
		$this->assertSame(
			Segmentflow_Ingest_Event::deterministic_message_id(
				'wordpress:user_registered:track',
				'wordpress:user_registered:track:' . $user_id
			),
			$track['messageId']
		);
		$this->assert_no_anonymous_fields( $track );

		$this->assertTrue( $request['options']['blocking'] );
		$this->assertSame( 1.5, $request['options']['timeout'] );
	}

	/**
	 * Test registration uses wc_ prefix when WooCommerce is active.
	 */
	public function test_user_register_uses_wc_prefix_when_woocommerce_active(): void {
		if ( ! Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is not active in this environment.' );
		}

		$user_id = self::factory()->user->create( [ 'user_email' => 'wcuser@example.com' ] );
		$user    = get_userdata( $user_id );
		$this->server_events->on_user_register( $user_id, (array) $user->data );

		$identify = $this->mock_api->requests[0]['body']['batch'][0];
		$this->assertSame( 'wc_' . $user_id, $identify['userId'] );
	}

	/**
	 * Test registration uses wp_ prefix when WooCommerce is inactive.
	 */
	public function test_user_register_uses_wp_prefix_when_woocommerce_inactive(): void {
		if ( Segmentflow_Helper::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is active in this environment.' );
		}

		$user_id = self::factory()->user->create( [ 'user_email' => 'wpuser@example.com' ] );
		$user    = get_userdata( $user_id );
		$this->server_events->on_user_register( $user_id, (array) $user->data );

		$identify = $this->mock_api->requests[0]['body']['batch'][0];
		$this->assertSame( 'wp_' . $user_id, $identify['userId'] );
	}

	// -------------------------------------------------------------------------
	// wp_login tests
	// -------------------------------------------------------------------------

	/**
	 * Test on_login sends identify with occurrence messageId prefix.
	 */
	public function test_login_sends_identify(): void {
		$user_id = self::factory()->user->create(
			[
				'user_login' => 'loginuser',
				'user_email' => 'loginuser@example.com',
				'first_name' => 'Bob',
				'last_name'  => 'Jones',
			]
		);
		$user    = get_userdata( $user_id );

		$this->server_events->on_login( 'loginuser', $user );

		$this->assertCount( 1, $this->mock_api->requests );

		$identify = $this->mock_api->requests[0]['body']['batch'][0];
		$prefix   = Segmentflow_Helper::is_woocommerce_active() ? 'wc_' : 'wp_';

		$this->assertSame( 'identify', $identify['type'] );
		$this->assertSame( 'loginuser@example.com', $identify['email'] );
		$this->assertSame( $prefix . $user_id, $identify['userId'] );
		$this->assertSame( 'wordpress', $identify['source'] );
		$this->assertSame( 'loginuser@example.com', $identify['traits']['email'] );
		$this->assertSame( 'Bob', $identify['traits']['first_name'] );
		$this->assertSame( 'Jones', $identify['traits']['last_name'] );
		$this->assertStringStartsWith( 'sfc:v1:wordpress:login:', $identify['messageId'] );
		$this->assert_no_anonymous_fields( $identify );
	}

	/**
	 * Test login retries reuse the same occurrence messageId after HTTP 503.
	 */
	public function test_login_reuses_message_id_across_retry(): void {
		$user_id = self::factory()->user->create(
			[
				'user_login' => 'retryuser',
				'user_email' => 'retry@example.com',
			]
		);
		$user    = get_userdata( $user_id );

		$this->mock_api->queued_responses[] = [
			'success'     => false,
			'status_code' => 503,
			'data'        => [
				'error' => 'unavailable',
			],
		];

		$this->server_events->on_login( 'retryuser', $user );

		$this->assertCount( 2, $this->mock_api->requests );

		$first_id  = $this->mock_api->requests[0]['body']['batch'][0]['messageId'];
		$second_id = $this->mock_api->requests[1]['body']['batch'][0]['messageId'];

		$this->assertStringStartsWith( 'sfc:v1:wordpress:login:', $first_id );
		$this->assertSame( $first_id, $second_id );
	}

	// -------------------------------------------------------------------------
	// wp_insert_comment tests
	// -------------------------------------------------------------------------

	/**
	 * Test on_comment sends identify + comment_posted for approved human comments.
	 */
	public function test_comment_sends_identify_and_track(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title' => 'Test Blog Post',
				'post_type'  => 'post',
			]
		);

		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Commenter Jane',
				'comment_author_email' => 'jane@example.com',
				'comment_content'      => 'Great post!',
				'comment_approved'     => 1,
			]
		);

		$comment = get_comment( $comment_id );
		$this->server_events->on_comment( $comment_id, $comment );

		$this->assertCount( 1, $this->mock_api->requests );

		$batch = $this->mock_api->requests[0]['body']['batch'];
		$this->assertCount( 2, $batch );

		$identify = $batch[0];
		$this->assertSame( 'identify', $identify['type'] );
		$this->assertSame( 'jane@example.com', $identify['email'] );
		$this->assertArrayNotHasKey( 'userId', $identify );
		$this->assertSame( 'jane@example.com', $identify['traits']['email'] );
		$this->assertSame( 'Commenter Jane', $identify['traits']['name'] );
		$this->assertSame(
			Segmentflow_Ingest_Event::deterministic_message_id(
				'wordpress:comment:identify',
				'wordpress:comment:identify:' . $comment_id
			),
			$identify['messageId']
		);
		$this->assert_no_anonymous_fields( $identify );

		$track = $batch[1];
		$this->assertSame( 'track', $track['type'] );
		$this->assertSame( 'comment_posted', $track['event'] );
		$this->assertSame( 'jane@example.com', $track['email'] );
		$this->assertArrayNotHasKey( 'userId', $track );
		$this->assertEquals( $comment_id, $track['properties']['comment_id'] );
		$this->assertEquals( $post_id, $track['properties']['post_id'] );
		$this->assertSame( 'Test Blog Post', $track['properties']['post_title'] );
		$this->assertSame( 'post', $track['properties']['post_type'] );
		$this->assertSame(
			Segmentflow_Ingest_Event::deterministic_message_id(
				'wordpress:comment:track',
				'wordpress:comment:track:' . $comment_id
			),
			$track['messageId']
		);
		$this->assert_no_anonymous_fields( $track );
	}

	/**
	 * Test logged-in comment includes prefixed userId.
	 */
	public function test_comment_logged_in_user_includes_user_id(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'commenter@example.com' ] );
		$post_id = self::factory()->post->create();

		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Logged In User',
				'comment_author_email' => 'commenter@example.com',
				'comment_content'      => 'Authenticated comment',
				'comment_approved'     => 1,
				'user_id'              => $user_id,
			]
		);

		$comment = get_comment( $comment_id );
		$this->server_events->on_comment( $comment_id, $comment );

		$prefix   = Segmentflow_Helper::is_woocommerce_active() ? 'wc_' : 'wp_';
		$identify = $this->mock_api->requests[0]['body']['batch'][0];
		$track    = $this->mock_api->requests[0]['body']['batch'][1];

		$this->assertSame( $prefix . $user_id, $identify['userId'] );
		$this->assertSame( $prefix . $user_id, $track['userId'] );
	}

	/**
	 * Test on_comment skips unapproved (pending) comments.
	 */
	public function test_comment_skips_unapproved(): void {
		$post_id    = self::factory()->post->create();
		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'      => $post_id,
				'comment_author_email' => 'pending@example.com',
				'comment_content'      => 'Pending comment',
				'comment_approved'     => 0,
			]
		);

		$comment = get_comment( $comment_id );
		$this->server_events->on_comment( $comment_id, $comment );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	/**
	 * Test on_comment skips spam comments.
	 */
	public function test_comment_skips_spam(): void {
		$post_id    = self::factory()->post->create();
		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'      => $post_id,
				'comment_author_email' => 'spam@example.com',
				'comment_content'      => 'Buy cheap stuff',
				'comment_approved'     => 'spam',
			]
		);

		$comment = get_comment( $comment_id );
		$this->server_events->on_comment( $comment_id, $comment );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	/**
	 * Test on_comment requires email and drops email-less comments.
	 */
	public function test_comment_requires_email(): void {
		$post_id    = self::factory()->post->create();
		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Anonymous',
				'comment_author_email' => '',
				'comment_content'      => 'Truly anonymous',
				'comment_approved'     => 1,
			]
		);

		$comment = get_comment( $comment_id );
		$this->server_events->on_comment( $comment_id, $comment );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	// -------------------------------------------------------------------------
	// Form submission tests
	// -------------------------------------------------------------------------

	/**
	 * Test form submission sends ordered identify then form_submission track.
	 */
	public function test_form_submission_sends_identify_and_track(): void {
		$reflection = new \ReflectionMethod( $this->server_events, 'handle_form_submission' );
		$reflection->invoke(
			$this->server_events,
			'form@example.com',
			'Contact Form',
			12,
			'cf7',
			[
				'your-email' => 'form@example.com',
				'your-name'  => 'Form User',
			]
		);

		$this->assertCount( 1, $this->mock_api->requests );

		$batch = $this->mock_api->requests[0]['body']['batch'];
		$this->assertCount( 2, $batch );

		$identify = $batch[0];
		$track    = $batch[1];

		$this->assertSame( 'identify', $identify['type'] );
		$this->assertSame( 'form@example.com', $identify['email'] );
		$this->assertArrayNotHasKey( 'userId', $identify );
		$this->assertSame( 'wordpress', $identify['source'] );
		$this->assertSame( 'Form User', $identify['traits']['name'] );
		$this->assertStringStartsWith( 'sfc:v1:wordpress:form:identify:', $identify['messageId'] );
		$this->assert_no_anonymous_fields( $identify );

		$this->assertSame( 'track', $track['type'] );
		$this->assertSame( 'form_submission', $track['event'] );
		$this->assertSame( 'form@example.com', $track['email'] );
		$this->assertArrayNotHasKey( 'userId', $track );
		$this->assertSame( 'cf7', $track['properties']['form_type'] );
		$this->assertSame( 'Contact Form', $track['properties']['form_title'] );
		$this->assertSame( 12, $track['properties']['form_id'] );
		$this->assertStringStartsWith( 'sfc:v1:wordpress:form:track:', $track['messageId'] );
		$this->assert_no_anonymous_fields( $track );

		$identify_uuid = substr( $identify['messageId'], strlen( 'sfc:v1:wordpress:form:identify:' ) );
		$track_uuid    = substr( $track['messageId'], strlen( 'sfc:v1:wordpress:form:track:' ) );
		$this->assertSame( $identify_uuid, $track_uuid );
	}

	/**
	 * Test Elementor form submission also shares one base UUID across items.
	 */
	public function test_elementor_form_submission_shares_base_uuid(): void {
		$reflection = new \ReflectionMethod( $this->server_events, 'handle_form_submission' );
		$reflection->invoke(
			$this->server_events,
			'elementor@example.com',
			'Lead Form',
			0,
			'elementor',
			[ 'email' => 'elementor@example.com' ]
		);

		$batch      = $this->mock_api->requests[0]['body']['batch'];
		$identify   = $batch[0];
		$track      = $batch[1];
		$id_uuid    = substr( $identify['messageId'], strlen( 'sfc:v1:wordpress:form:identify:' ) );
		$track_uuid = substr( $track['messageId'], strlen( 'sfc:v1:wordpress:form:track:' ) );

		$this->assertSame( 'elementor', $track['properties']['form_type'] );
		$this->assertArrayNotHasKey( 'userId', $identify );
		$this->assertArrayNotHasKey( 'userId', $track );
		$this->assertSame( $id_uuid, $track_uuid );
	}

	/**
	 * Test form submission is skipped without a valid email.
	 */
	public function test_form_submission_skips_without_email(): void {
		$reflection = new \ReflectionMethod( $this->server_events, 'handle_form_submission' );
		$reflection->invoke(
			$this->server_events,
			'',
			'No Email Form',
			1,
			'cf7',
			[ 'your-name' => 'Nobody' ]
		);

		$this->assertCount( 0, $this->mock_api->requests );
	}

	/**
	 * Test email extraction from common form fields.
	 */
	public function test_email_extraction_from_common_fields(): void {
		$reflection = new \ReflectionMethod( $this->server_events, 'extract_email_from_form_data' );

		$this->assertSame(
			'user@example.com',
			$reflection->invoke( $this->server_events, [ 'your-email' => 'user@example.com' ] )
		);
		$this->assertSame(
			'plain@example.com',
			$reflection->invoke( $this->server_events, [ 'email' => 'plain@example.com' ] )
		);
		$this->assertSame(
			'under@example.com',
			$reflection->invoke( $this->server_events, [ 'your_email' => 'under@example.com' ] )
		);
		$this->assertSame(
			'scan@example.com',
			$reflection->invoke(
				$this->server_events,
				[
					'some-field' => 'not-an-email',
					'other'      => 'scan@example.com',
				]
			)
		);
		$this->assertSame(
			'',
			$reflection->invoke(
				$this->server_events,
				[
					'name'    => 'John',
					'message' => 'Hello',
				]
			)
		);
	}

	/**
	 * Test name extraction from common form fields.
	 */
	public function test_name_extraction_from_common_fields(): void {
		$reflection = new \ReflectionMethod( $this->server_events, 'extract_name_from_form_data' );

		$this->assertSame(
			'Jane Doe',
			$reflection->invoke( $this->server_events, [ 'your-name' => 'Jane Doe' ] )
		);
		$this->assertSame(
			'John Smith',
			$reflection->invoke( $this->server_events, [ 'name' => 'John Smith' ] )
		);
		$this->assertSame(
			'',
			$reflection->invoke( $this->server_events, [ 'email' => 'test@example.com' ] )
		);
	}

	// -------------------------------------------------------------------------
	// Hook registration tests
	// -------------------------------------------------------------------------

	/**
	 * Test that register_hooks does nothing when not connected.
	 */
	public function test_hooks_not_registered_when_disconnected(): void {
		delete_option( 'segmentflow_write_key' );

		$options       = new Segmentflow_Options();
		$ingest_client = new Segmentflow_Ingest_Client( $options, $this->mock_api );
		$server_events = new Segmentflow_Server_Events( $options, $ingest_client );
		$server_events->register_hooks();

		$this->assertFalse( has_action( 'user_register', [ $server_events, 'on_user_register' ] ) );
		$this->assertFalse( has_action( 'wp_login', [ $server_events, 'on_login' ] ) );
		$this->assertFalse( has_action( 'wp_insert_comment', [ $server_events, 'on_comment' ] ) );
	}

	/**
	 * Test that register_hooks registers core actions when connected.
	 */
	public function test_hooks_registered_when_connected(): void {
		$server_events = new Segmentflow_Server_Events( $this->options, $this->ingest_client );
		$server_events->register_hooks();

		$this->assertSame( 10, has_action( 'user_register', [ $server_events, 'on_user_register' ] ) );
		$this->assertSame( 10, has_action( 'wp_login', [ $server_events, 'on_login' ] ) );
		$this->assertSame( 10, has_action( 'wp_insert_comment', [ $server_events, 'on_comment' ] ) );
	}

	/**
	 * Test that CF7 hook is not registered when CF7 is not active.
	 */
	public function test_cf7_hook_not_registered_without_cf7(): void {
		$server_events = new Segmentflow_Server_Events( $this->options, $this->ingest_client );
		$server_events->register_hooks();

		$this->assertFalse( has_action( 'wpcf7_mail_sent', [ $server_events, 'on_cf7_submit' ] ) );
	}

	/**
	 * Test that events are not sent when write key is empty.
	 */
	public function test_send_events_skips_without_write_key(): void {
		delete_option( 'segmentflow_write_key' );

		$options       = new Segmentflow_Options();
		$ingest_client = new Segmentflow_Ingest_Client( $options, $this->mock_api );
		$server_events = new Segmentflow_Server_Events( $options, $ingest_client );

		$user_id = self::factory()->user->create( [ 'user_email' => 'nokey@example.com' ] );
		$user    = get_userdata( $user_id );
		$server_events->on_user_register( $user_id, (array) $user->data );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	// -------------------------------------------------------------------------
	// Consent payload stamping
	// -------------------------------------------------------------------------

	/**
	 * Test that outgoing batches stamp consent flags when sf_consent is set.
	 */
	public function test_batch_includes_consent_when_cookie_set(): void {
		Segmentflow_Consent_Cookie::set_consent(
			[
				'analytics' => true,
				'marketing' => false,
			]
		);

		$user_id = self::factory()->user->create( [ 'user_email' => 'consenting@example.com' ] );
		$user    = get_userdata( $user_id );
		$this->server_events->on_user_register( $user_id, (array) $user->data );

		$this->assertCount( 1, $this->mock_api->requests );
		$body = $this->mock_api->requests[0]['body'];
		$this->assertArrayHasKey( 'consent', $body );
		$this->assertTrue( $body['consent']['analytics'] );
		$this->assertFalse( $body['consent']['marketing'] );
	}

	/**
	 * Test that consent is omitted when sf_consent is absent.
	 */
	public function test_batch_omits_consent_when_cookie_absent(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'no-consent@example.com' ] );
		$user    = get_userdata( $user_id );
		$this->server_events->on_user_register( $user_id, (array) $user->data );

		$this->assertCount( 1, $this->mock_api->requests );
		$body = $this->mock_api->requests[0]['body'];
		$this->assertArrayNotHasKey( 'consent', $body );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Assert forbidden anonymous / routing fields are absent.
	 *
	 * @param array<string, mixed> $item Ingest item.
	 */
	private function assert_no_anonymous_fields( array $item ): void {
		$this->assertArrayNotHasKey( 'anonymousId', $item );
		$this->assertArrayNotHasKey( 'organizationId', $item );
		$this->assertArrayNotHasKey( 'sourceInstanceId', $item );
		$this->assertArrayNotHasKey( 'identityNamespace', $item );
		$this->assertArrayNotHasKey( 'profileId', $item );
	}
}
