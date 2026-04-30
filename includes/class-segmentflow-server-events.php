<?php
/**
 * Segmentflow WordPress core server-side events.
 *
 * Fires identity and tracking events from WordPress core hooks (user
 * registration, login, comments) and popular form plugins (CF7, Elementor Pro).
 * Events are sent to the ingest API as fire-and-forget batch requests.
 *
 * Identity is read from the unified `sf_id` cookie (see class-segmentflow-identity-cookie.php).
 * When new identity information is captured (e.g. email from a form), it is
 * merged into the cookie before sending the event.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Segmentflow_Server_Events
 *
 * Hooks into WordPress core actions to fire server-side events:
 * - `user_register`                       -> identify + `user_registered` track
 * - `wp_login`                            -> identify
 * - `wp_insert_comment`                   -> identify + `comment_posted` track
 * - `wpcf7_mail_sent` (CF7)              -> identify + `form_submission` track
 * - `elementor_pro/forms/new_record`      -> identify + `form_submission` track
 *
 * All events use fire-and-forget POST to /api/v1/ingest/batch with source: "WordPress".
 */
class Segmentflow_Server_Events {

	/**
	 * Event source identifier for PHP-originated events.
	 */
	const EVENT_SOURCE = 'wordpress';

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * API client instance.
	 *
	 * @var Segmentflow_API
	 */
	private Segmentflow_API $api;

	/**
	 * Constructor.
	 *
	 * @param Segmentflow_Options $options The options instance.
	 * @param Segmentflow_API     $api     The API client instance.
	 */
	public function __construct( Segmentflow_Options $options, Segmentflow_API $api ) {
		$this->options = $options;
		$this->api     = $api;
	}

	/**
	 * Register WordPress action hooks.
	 *
	 * Only registers hooks when the plugin is connected. Form plugin hooks
	 * are conditionally registered based on whether the plugin is active.
	 */
	public function register_hooks(): void {
		if ( ! $this->options->is_connected() ) {
			return;
		}

		// WordPress core identity events.
		add_action( 'user_register', [ $this, 'on_user_register' ], 10, 2 );
		add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
		add_action( 'wp_insert_comment', [ $this, 'on_comment' ], 10, 2 );

		// Contact Form 7 (only if active).
		if ( defined( 'WPCF7_VERSION' ) ) {
			add_action( 'wpcf7_mail_sent', [ $this, 'on_cf7_submit' ], 10, 1 );
		}

		// Elementor Pro forms (only if active).
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			add_action( 'elementor_pro/forms/new_record', [ $this, 'on_elementor_submit' ], 10, 2 );
		}
	}

	/**
	 * Handle the `user_register` action.
	 *
	 * Fires an identify event and a `user_registered` track event when a new
	 * WordPress user is created. Merges the user's email and ID into the cookie.
	 *
	 * @param int   $user_id  The new user's ID.
	 * @param array $userdata The raw user data array passed to wp_insert_user() (unused, required by hook signature).
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook callback must accept all parameters from user_register action.
	public function on_user_register( int $user_id, array $userdata ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$email            = $user->user_email;
		$user_id_prefixed = $this->get_prefixed_user_id( $user_id );

		// Merge identity into cookie (no-op without consent — see Identity_Cookie::write).
		$cookie_fields = [ 'u' => $user_id_prefixed ];
		if ( $email ) {
			$cookie_fields['e'] = $email;
		}
		Segmentflow_Identity_Cookie::write( $cookie_fields );

		$identity = $this->resolve_identity( $email, $user_id_prefixed );
		if ( ! $identity ) {
			return;
		}

		// Build traits.
		$traits = [ 'email' => $email ];
		if ( ! empty( $user->first_name ) ) {
			$traits['first_name'] = $user->first_name;
		}
		if ( ! empty( $user->last_name ) ) {
			$traits['last_name'] = $user->last_name;
		}

		// Send identify + track as a batch.
		$this->send_events(
			[
				$this->build_identify_event( $identity, $traits ),
				$this->build_track_event(
					'user_registered',
					$identity,
					[
						'user_id' => $user_id,
						'email'   => $email,
					]
				),
			]
		);
	}

	/**
	 * Handle the `wp_login` action.
	 *
	 * Fires an identify event when a user logs in. Merges the user's email
	 * and ID into the cookie, bridging any anonymous browsing session to
	 * the authenticated user.
	 *
	 * @param string  $user_login The username.
	 * @param WP_User $user       The WP_User object.
	 */
	public function on_login( string $user_login, WP_User $user ): void {
		$email            = $user->user_email;
		$user_id_prefixed = $this->get_prefixed_user_id( $user->ID );

		// Merge identity into cookie (no-op without consent).
		$cookie_fields = [ 'u' => $user_id_prefixed ];
		if ( $email ) {
			$cookie_fields['e'] = $email;
		}
		Segmentflow_Identity_Cookie::write( $cookie_fields );

		$identity = $this->resolve_identity( $email, $user_id_prefixed );
		if ( ! $identity ) {
			return;
		}

		// Build traits.
		$traits = [ 'email' => $email ];
		if ( ! empty( $user->first_name ) ) {
			$traits['first_name'] = $user->first_name;
		}
		if ( ! empty( $user->last_name ) ) {
			$traits['last_name'] = $user->last_name;
		}

		$this->send_event( $this->build_identify_event( $identity, $traits ) );
	}

	/**
	 * Handle the `wp_insert_comment` action.
	 *
	 * Fires an identify event and a `comment_posted` track event when a comment
	 * is posted. Captures the comment author's email into the cookie.
	 *
	 * Only fires for approved comments (status = 1 or 'approve') to avoid
	 * capturing identity from spam.
	 *
	 * @param int        $comment_id The comment ID.
	 * @param WP_Comment $comment    The comment object.
	 */
	public function on_comment( int $comment_id, WP_Comment $comment ): void {
		// Only process approved comments. Skip spam, trash, and pending.
		$approved = $comment->comment_approved;
		if ( '1' !== (string) $approved && 'approve' !== $approved ) {
			return;
		}

		// Skip WooCommerce order notes — they are internal system comments
		// (e.g. "Order status changed to processing"), not user-generated content.
		// Processing them would create noisy comment_posted events and pollute
		// identity resolution with the store's system email.
		$post = get_post( $comment->comment_post_ID );
		if ( $post && in_array( $post->post_type, [ 'shop_order', 'shop_order_placehold' ], true ) ) {
			return;
		}

		$email       = $comment->comment_author_email;
		$author_name = $comment->comment_author;

		// Merge email into cookie if present (no-op without consent).
		if ( $email && is_email( $email ) ) {
			Segmentflow_Identity_Cookie::set_email( $email );
		}

		// If comment was by a logged-in user, merge userId too.
		$comment_user_id  = (int) $comment->user_id;
		$user_id_prefixed = $comment_user_id ? $this->get_prefixed_user_id( $comment_user_id ) : null;
		if ( $user_id_prefixed ) {
			Segmentflow_Identity_Cookie::write( [ 'u' => $user_id_prefixed ] );
		}

		$identity = $this->resolve_identity( $email, $user_id_prefixed );
		if ( ! $identity ) {
			return;
		}

		// Build events.
		$events = [];

		// Identify with email if available.
		if ( $email ) {
			$traits = [ 'email' => $email ];
			if ( $author_name ) {
				$traits['name'] = $author_name;
			}
			$events[] = $this->build_identify_event( $identity, $traits );
		}

		// Track comment_posted (reuse $post from the guard above).
		$events[] = $this->build_track_event(
			'comment_posted',
			$identity,
			[
				'comment_id' => $comment_id,
				'post_id'    => $comment->comment_post_ID,
				'post_title' => $post ? $post->post_title : '',
				'post_type'  => $post ? $post->post_type : '',
			]
		);

		$this->send_events( $events );
	}

	/**
	 * Handle the `wpcf7_mail_sent` action (Contact Form 7).
	 *
	 * Fires an identify event and a `form_submission` track event.
	 * Extracts email from common CF7 field names: your-email, email, your_email.
	 *
	 * @param object $contact_form The WPCF7_ContactForm instance.
	 */
	public function on_cf7_submit( object $contact_form ): void {
		// Get the submitted form data.
		$submission = class_exists( 'WPCF7_Submission' ) ? \WPCF7_Submission::get_instance() : null;
		if ( ! $submission ) {
			return;
		}

		$posted_data = $submission->get_posted_data();
		$email       = $this->extract_email_from_form_data( $posted_data );
		$form_title  = method_exists( $contact_form, 'title' ) ? $contact_form->title() : '';
		$form_id     = method_exists( $contact_form, 'id' ) ? $contact_form->id() : 0;

		$this->handle_form_submission( $email, $form_title, $form_id, 'cf7', $posted_data );
	}

	/**
	 * Handle the `elementor_pro/forms/new_record` action (Elementor Pro).
	 *
	 * Fires an identify event and a `form_submission` track event.
	 * Extracts email from Elementor form fields by type or ID.
	 *
	 * @param object $record      The Elementor form record.
	 * @param object $ajax_handler The Elementor AJAX handler (unused, required by hook signature).
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- Hook callback must match elementor_pro/forms/new_record signature.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook callback must accept all parameters.
	public function on_elementor_submit( object $record, object $ajax_handler ): void {
		// Get the form fields.
		$raw_fields = method_exists( $record, 'get' ) ? $record->get( 'fields' ) : [];
		$email      = '';
		$form_data  = [];

		foreach ( $raw_fields as $field_id => $field ) {
			$value                  = $field['value'] ?? '';
			$form_data[ $field_id ] = $value;

			// Match by field type or common field IDs.
			if ( empty( $email ) && $value ) {
				$type = $field['type'] ?? '';
				$id   = $field['id'] ?? $field_id;

				if ( 'email' === $type || in_array( strtolower( $id ), [ 'email', 'your-email', 'your_email' ], true ) ) {
					if ( is_email( $value ) ) {
						$email = $value;
					}
				}
			}
		}

		$form_name = method_exists( $record, 'get_form_settings' )
			? ( $record->get_form_settings( 'form_name' ) ?? '' )
			: '';

		$this->handle_form_submission( $email, $form_name, 0, 'elementor', $form_data );
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Handle a form submission (shared by CF7 and Elementor handlers).
	 *
	 * Merges captured email into the cookie (no-op without consent), then
	 * sends identify + form_submission track events as a batch.
	 *
	 * @param string               $email      Extracted email (may be empty).
	 * @param string               $form_title The form title/name.
	 * @param int                  $form_id    The form ID (0 if unknown).
	 * @param string               $form_type  Form plugin type ('cf7' or 'elementor').
	 * @param array<string, mixed> $form_data  The submitted form data.
	 */
	private function handle_form_submission( string $email, string $form_title, int $form_id, string $form_type, array $form_data ): void {
		// Merge email into cookie if found (no-op without consent).
		if ( $email && is_email( $email ) ) {
			Segmentflow_Identity_Cookie::set_email( $email );
		}

		$identity = $this->resolve_identity( $email, null );
		if ( ! $identity ) {
			return;
		}

		$events = [];

		// Identify with email if captured.
		if ( $email ) {
			$traits = [ 'email' => $email ];

			// Try to extract name from common form fields.
			$name = $this->extract_name_from_form_data( $form_data );
			if ( $name ) {
				$traits['name'] = $name;
			}

			$events[] = $this->build_identify_event( $identity, $traits );
		}

		// Track form_submission.
		$properties = [
			'form_type' => $form_type,
		];
		if ( $form_title ) {
			$properties['form_title'] = $form_title;
		}
		if ( $form_id ) {
			$properties['form_id'] = $form_id;
		}

		$events[] = $this->build_track_event( 'form_submission', $identity, $properties );

		$this->send_events( $events );
	}

	/**
	 * Extract an email address from form data.
	 *
	 * Looks for common email field names used by form plugins.
	 *
	 * @param array<string, mixed> $data The submitted form data.
	 * @return string The email address, or empty string if not found.
	 */
	private function extract_email_from_form_data( array $data ): string {
		// Common email field names across form plugins.
		$email_fields = [ 'your-email', 'email', 'your_email', 'e-mail', 'user_email', 'contact-email' ];

		foreach ( $email_fields as $field_name ) {
			if ( ! empty( $data[ $field_name ] ) ) {
				$value = is_array( $data[ $field_name ] ) ? $data[ $field_name ][0] : $data[ $field_name ];
				if ( is_email( $value ) ) {
					return sanitize_email( $value );
				}
			}
		}

		// Fallback: scan all fields for anything that looks like an email.
		foreach ( $data as $value ) {
			if ( is_string( $value ) && is_email( $value ) ) {
				return sanitize_email( $value );
			}
		}

		return '';
	}

	/**
	 * Extract a name from form data.
	 *
	 * Looks for common name field names used by form plugins.
	 *
	 * @param array<string, mixed> $data The submitted form data.
	 * @return string The name, or empty string if not found.
	 */
	private function extract_name_from_form_data( array $data ): string {
		// Common name field names across form plugins.
		$name_fields = [ 'your-name', 'name', 'your_name', 'full-name', 'full_name', 'contact-name' ];

		foreach ( $name_fields as $field_name ) {
			if ( ! empty( $data[ $field_name ] ) ) {
				$value = is_array( $data[ $field_name ] ) ? $data[ $field_name ][0] : $data[ $field_name ];
				return sanitize_text_field( $value );
			}
		}

		return '';
	}

	/**
	 * Get a prefixed user ID string.
	 *
	 * Uses 'wc_' prefix when WooCommerce is active, 'wp_' otherwise.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return string The prefixed user ID (e.g. 'wc_42' or 'wp_42').
	 */
	private function get_prefixed_user_id( int $user_id ): string {
		$prefix = Segmentflow_Helper::is_woocommerce_active() ? 'wc_' : 'wp_';
		return $prefix . $user_id;
	}

	/**
	 * Resolve identity for a server-fired event.
	 *
	 * Prefers the `sf_id` cookie when consent is granted (that's the
	 * stitching anchor between anonymous browse history and the action
	 * we're about to record). Falls back to identity supplied by the
	 * hook itself (form email, WP user ID) when the cookie is absent —
	 * server hooks for confirmed user actions (login, order, form
	 * submission) ride on a legitimate-interest / contract basis under
	 * GDPR Art. 6, so they fire even before the visitor accepts cookies.
	 *
	 * Returns null only when neither path produces a stable identifier
	 * the API can attach to a profile.
	 *
	 * @param string|null $hook_email      Email surfaced by the hook (form, billing, comment).
	 * @param string|null $hook_user_id    Already-prefixed WP/WC user ID surfaced by the hook.
	 * @return array<string, string>|null  Identity shaped like the cookie payload (a,u,e,p).
	 */
	private function resolve_identity( ?string $hook_email, ?string $hook_user_id ): ?array {
		$cookie = Segmentflow_Identity_Cookie::read();
		if ( $cookie && ! empty( $cookie['a'] ) ) {
			return $cookie;
		}

		// Hook fallback. The API requires either userId or anonymousId,
		// so we anchor on the prefixed user ID when available, then fall
		// back to the email address — the only stable identifier left
		// for an anonymous form/comment submitter.
		$fallback = [];
		if ( $hook_user_id ) {
			$fallback['u'] = $hook_user_id;
		}
		if ( $hook_email && is_email( $hook_email ) ) {
			$fallback['e'] = sanitize_email( $hook_email );
			if ( empty( $fallback['u'] ) ) {
				$fallback['u'] = $fallback['e'];
			}
		}

		return empty( $fallback['u'] ) ? null : $fallback;
	}

	/**
	 * Build an identify event payload.
	 *
	 * @param array<string, string> $identity Identity data from sf_id cookie or hook fallback.
	 * @param array<string, mixed>  $traits   User traits (email, name, etc.).
	 * @return array<string, mixed> The event payload.
	 */
	private function build_identify_event( array $identity, array $traits ): array {
		$event = [
			'type'      => 'identify',
			'traits'    => $traits,
			'source'    => self::EVENT_SOURCE,
			'timestamp' => gmdate( 'c' ),
		];

		if ( ! empty( $identity['a'] ) ) {
			$event['anonymousId'] = $identity['a'];
		}
		if ( ! empty( $identity['u'] ) ) {
			$event['userId'] = $identity['u'];
		}

		return $event;
	}

	/**
	 * Build a track event payload.
	 *
	 * @param string                $event_name Event name (e.g. 'user_registered').
	 * @param array<string, string> $identity   Identity data from sf_id cookie or hook fallback.
	 * @param array<string, mixed>  $properties Event properties.
	 * @return array<string, mixed> The event payload.
	 */
	private function build_track_event( string $event_name, array $identity, array $properties ): array {
		$event = [
			'type'       => 'track',
			'event'      => $event_name,
			'properties' => $properties,
			'source'     => self::EVENT_SOURCE,
			'timestamp'  => gmdate( 'c' ),
		];

		if ( ! empty( $identity['a'] ) ) {
			$event['anonymousId'] = $identity['a'];
		}
		if ( ! empty( $identity['u'] ) ) {
			$event['userId'] = $identity['u'];
		}

		return $event;
	}

	/**
	 * Read consent flags currently recorded in `sf_consent`. Returns
	 * null when the visitor has not yet decided — the API's own gate
	 * treats absent consent as "fully backward-compatible" so server
	 * events keep flowing while the banner is still up.
	 *
	 * @return array{analytics: bool, marketing: bool}|null
	 */
	private function get_consent_payload(): ?array {
		if ( ! Segmentflow_Consent_Cookie::is_set() ) {
			return null;
		}
		return [
			'analytics' => Segmentflow_Consent_Cookie::has_consent( 'analytics' ),
			'marketing' => Segmentflow_Consent_Cookie::has_consent( 'marketing' ),
		];
	}

	/**
	 * Send a single event to the ingest API.
	 *
	 * Uses fire-and-forget (non-blocking) POST to /api/v1/ingest/batch.
	 *
	 * @param array<string, mixed> $event The event payload.
	 */
	private function send_event( array $event ): void {
		$this->send_events( [ $event ] );
	}

	/**
	 * Send multiple events to the ingest API in a single batch.
	 *
	 * Uses fire-and-forget (non-blocking) POST to /api/v1/ingest/batch.
	 * The 0.5s timeout is a safety net — the response is not awaited.
	 *
	 * @param array<int, array<string, mixed>> $events Array of event payloads.
	 */
	private function send_events( array $events ): void {
		$write_key = $this->options->get_write_key();
		if ( empty( $write_key ) ) {
			return;
		}

		$payload = [
			'writeKey' => $write_key,
			'batch'    => $events,
		];

		// Attach consent flags so the server-side gate (#104) records the
		// audit trail and skips marketing-tier forwarding when the visitor
		// has explicitly revoked it.
		$consent = $this->get_consent_payload();
		if ( null !== $consent ) {
			$payload['consent'] = $consent;
		}

		$this->api->request(
			'POST',
			'/api/v1/ingest/batch',
			$payload,
			[],
			[ 'blocking' => false ]
		);
	}
}
