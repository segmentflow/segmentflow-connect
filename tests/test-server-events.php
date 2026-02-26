<?php
/**
 * Tests for WordPress core server-side events.
 *
 * Tests the Segmentflow_Server_Events class: user_register, wp_login,
 * wp_insert_comment, and form submission handlers.
 *
 * @package Segmentflow_Connect
 */

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

		// Ensure a write key exists so hooks register.
		update_option( 'segmentflow_write_key', 'test-write-key-456' );

		$this->options       = new Segmentflow_Options();
		$this->mock_api      = new Mock_Segmentflow_API( $this->options );
		$this->server_events = new Segmentflow_Server_Events( $this->options, $this->mock_api );

		// Reset cookie state.
		Segmentflow_Identity_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		delete_option( 'segmentflow_write_key' );
		Segmentflow_Identity_Cookie::reset_cache();
		unset( $_COOKIE[ Segmentflow_Identity_Cookie::COOKIE_NAME ] );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// user_register tests
	// -------------------------------------------------------------------------

	/**
	 * Test on_user_register sends identify + user_registered events.
	 */
	public function test_user_register_sends_identify_and_track(): void {
		$this->set_identity( [ 'a' => 'anon-reg-1' ] );

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

		$batch = $this->mock_api->requests[0]['body']['batch'];
		$this->assertCount( 2, $batch );

		// First event: identify.
		$identify = $batch[0];
		$this->assertSame( 'identify', $identify['type'] );
		$this->assertSame( 'anon-reg-1', $identify['anonymousId'] );
		$this->assertSame( 'WordPress', $identify['source'] );
		$this->assertSame( 'newuser@example.com', $identify['traits']['email'] );
		$this->assertSame( 'Alice', $identify['traits']['first_name'] );
		$this->assertSame( 'Smith', $identify['traits']['last_name'] );
		// userId should be set (wp_ prefix since WC is not active in test env).
		$this->assertStringContainsString( (string) $user_id, $identify['userId'] );

		// Second event: user_registered track.
		$track = $batch[1];
		$this->assertSame( 'track', $track['type'] );
		$this->assertSame( 'user_registered', $track['event'] );
		$this->assertSame( $user_id, $track['properties']['user_id'] );
		$this->assertSame( 'newuser@example.com', $track['properties']['email'] );

		// Verify non-blocking.
		$this->assertFalse( $this->mock_api->requests[0]['options']['blocking'] );

		// Verify cookie was updated.
		$cookie = Segmentflow_Identity_Cookie::read();
		$this->assertSame( 'newuser@example.com', $cookie['e'] );
	}

	/**
	 * Test on_user_register skips without identity cookie.
	 */
	public function test_user_register_skips_without_identity(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'ghost@example.com' ] );
		$user    = get_userdata( $user_id );

		$this->server_events->on_user_register( $user_id, (array) $user->data );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	// -------------------------------------------------------------------------
	// wp_login tests
	// -------------------------------------------------------------------------

	/**
	 * Test on_login sends identify event and updates cookie.
	 */
	public function test_login_sends_identify(): void {
		$this->set_identity( [ 'a' => 'anon-login-1' ] );

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

		$batch = $this->mock_api->requests[0]['body']['batch'];
		$this->assertCount( 1, $batch );

		$identify = $batch[0];
		$this->assertSame( 'identify', $identify['type'] );
		$this->assertSame( 'anon-login-1', $identify['anonymousId'] );
		$this->assertSame( 'loginuser@example.com', $identify['traits']['email'] );
		$this->assertSame( 'Bob', $identify['traits']['first_name'] );
		$this->assertSame( 'Jones', $identify['traits']['last_name'] );
		$this->assertStringContainsString( (string) $user_id, $identify['userId'] );

		// Verify cookie updated.
		$cookie = Segmentflow_Identity_Cookie::read();
		$this->assertSame( 'loginuser@example.com', $cookie['e'] );
		$this->assertStringContainsString( (string) $user_id, $cookie['u'] );
	}

	/**
	 * Test on_login skips without identity cookie.
	 */
	public function test_login_skips_without_identity(): void {
		$user_id = self::factory()->user->create( [ 'user_login' => 'ghostlogin' ] );
		$user    = get_userdata( $user_id );

		$this->server_events->on_login( 'ghostlogin', $user );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	// -------------------------------------------------------------------------
	// wp_insert_comment tests
	// -------------------------------------------------------------------------

	/**
	 * Test on_comment sends identify + comment_posted for approved comment.
	 */
	public function test_comment_sends_identify_and_track(): void {
		$this->set_identity( [ 'a' => 'anon-comment-1' ] );

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

		// Identify event.
		$identify = $batch[0];
		$this->assertSame( 'identify', $identify['type'] );
		$this->assertSame( 'jane@example.com', $identify['traits']['email'] );
		$this->assertSame( 'Commenter Jane', $identify['traits']['name'] );

		// Track event.
		$track = $batch[1];
		$this->assertSame( 'track', $track['type'] );
		$this->assertSame( 'comment_posted', $track['event'] );
		$this->assertEquals( $comment_id, $track['properties']['comment_id'] );
		$this->assertEquals( $post_id, $track['properties']['post_id'] );
		$this->assertSame( 'Test Blog Post', $track['properties']['post_title'] );
		$this->assertSame( 'post', $track['properties']['post_type'] );

		// Verify cookie updated with email.
		$cookie = Segmentflow_Identity_Cookie::read();
		$this->assertSame( 'jane@example.com', $cookie['e'] );
	}

	/**
	 * Test on_comment skips unapproved (pending) comments.
	 */
	public function test_comment_skips_unapproved(): void {
		$this->set_identity( [ 'a' => 'anon-comment-2' ] );

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
		$this->set_identity( [ 'a' => 'anon-comment-3' ] );

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
	 * Test on_comment skips without identity cookie.
	 */
	public function test_comment_skips_without_identity(): void {
		$post_id    = self::factory()->post->create();
		$comment_id = wp_insert_comment(
			[
				'comment_post_ID'      => $post_id,
				'comment_author_email' => 'noidentity@example.com',
				'comment_content'      => 'No cookie comment',
				'comment_approved'     => 1,
			]
		);

		$comment = get_comment( $comment_id );
		$this->server_events->on_comment( $comment_id, $comment );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	/**
	 * Test on_comment with logged-in user updates userId in cookie.
	 */
	public function test_comment_logged_in_user_updates_cookie(): void {
		$this->set_identity( [ 'a' => 'anon-comment-4' ] );

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

		// Verify cookie has userId.
		$cookie = Segmentflow_Identity_Cookie::read();
		$this->assertStringContainsString( (string) $user_id, $cookie['u'] );
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
		$server_events = new Segmentflow_Server_Events( $options, $this->mock_api );
		$server_events->register_hooks();

		$this->assertFalse( has_action( 'user_register', [ $server_events, 'on_user_register' ] ) );
		$this->assertFalse( has_action( 'wp_login', [ $server_events, 'on_login' ] ) );
		$this->assertFalse( has_action( 'wp_insert_comment', [ $server_events, 'on_comment' ] ) );
	}

	/**
	 * Test that register_hooks registers core actions when connected.
	 */
	public function test_hooks_registered_when_connected(): void {
		$server_events = new Segmentflow_Server_Events( $this->options, $this->mock_api );
		$server_events->register_hooks();

		$this->assertSame( 10, has_action( 'user_register', [ $server_events, 'on_user_register' ] ) );
		$this->assertSame( 10, has_action( 'wp_login', [ $server_events, 'on_login' ] ) );
		$this->assertSame( 10, has_action( 'wp_insert_comment', [ $server_events, 'on_comment' ] ) );
	}

	/**
	 * Test that CF7 hook is not registered when CF7 is not active.
	 */
	public function test_cf7_hook_not_registered_without_cf7(): void {
		// WPCF7_VERSION should not be defined in the test environment.
		$server_events = new Segmentflow_Server_Events( $this->options, $this->mock_api );
		$server_events->register_hooks();

		$this->assertFalse( has_action( 'wpcf7_mail_sent', [ $server_events, 'on_cf7_submit' ] ) );
	}

	/**
	 * Test that events are not sent when write key is empty.
	 */
	public function test_send_events_skips_without_write_key(): void {
		delete_option( 'segmentflow_write_key' );
		$this->set_identity( [ 'a' => 'anon-no-key' ] );

		$options       = new Segmentflow_Options();
		$server_events = new Segmentflow_Server_Events( $options, $this->mock_api );

		$user_id = self::factory()->user->create( [ 'user_email' => 'nokey@example.com' ] );
		$user    = get_userdata( $user_id );
		$server_events->on_user_register( $user_id, (array) $user->data );

		$this->assertCount( 0, $this->mock_api->requests );
	}

	// -------------------------------------------------------------------------
	// Form submission tests (using the shared handler directly)
	// -------------------------------------------------------------------------

	/**
	 * Test on_cf7_submit extracts email and sends events.
	 *
	 * Since WPCF7_Submission is not available in the test environment,
	 * we test the shared handle_form_submission logic indirectly through
	 * on_user_register (which exercises the same build/send patterns).
	 * The CF7-specific email extraction is tested via the extract helper.
	 */
	public function test_email_extraction_from_common_fields(): void {
		// Test the email extraction logic that CF7/Elementor handlers use.
		// We use reflection to access the private method.
		$reflection = new \ReflectionMethod( $this->server_events, 'extract_email_from_form_data' );

		// Match on 'your-email' (CF7 default).
		$result = $reflection->invoke(
			$this->server_events,
			[ 'your-email' => 'user@example.com' ]
		);
		$this->assertSame( 'user@example.com', $result );

		// Match on 'email'.
		$result = $reflection->invoke(
			$this->server_events,
			[ 'email' => 'plain@example.com' ]
		);
		$this->assertSame( 'plain@example.com', $result );

		// Match on 'your_email'.
		$result = $reflection->invoke(
			$this->server_events,
			[ 'your_email' => 'under@example.com' ]
		);
		$this->assertSame( 'under@example.com', $result );

		// Fallback: scan all fields.
		$result = $reflection->invoke(
			$this->server_events,
			[
				'some-field' => 'not-an-email',
				'other'      => 'scan@example.com',
			]
		);
		$this->assertSame( 'scan@example.com', $result );

		// No email found.
		$result = $reflection->invoke(
			$this->server_events,
			[
				'name'    => 'John',
				'message' => 'Hello',
			]
		);
		$this->assertSame( '', $result );
	}

	/**
	 * Test name extraction from common form fields.
	 */
	public function test_name_extraction_from_common_fields(): void {
		$reflection = new \ReflectionMethod( $this->server_events, 'extract_name_from_form_data' );

		// Match on 'your-name' (CF7 default).
		$result = $reflection->invoke(
			$this->server_events,
			[ 'your-name' => 'Jane Doe' ]
		);
		$this->assertSame( 'Jane Doe', $result );

		// Match on 'name'.
		$result = $reflection->invoke(
			$this->server_events,
			[ 'name' => 'John Smith' ]
		);
		$this->assertSame( 'John Smith', $result );

		// No name found.
		$result = $reflection->invoke(
			$this->server_events,
			[ 'email' => 'test@example.com' ]
		);
		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Set the sf_id identity cookie for the current test.
	 *
	 * @param array<string, string> $data Identity fields.
	 */
	private function set_identity( array $data ): void {
		Segmentflow_Identity_Cookie::reset_cache();
		Segmentflow_Identity_Cookie::write( $data );
	}
}
