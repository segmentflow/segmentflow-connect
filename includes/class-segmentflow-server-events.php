<?php
/**
 * Segmentflow WordPress core server-side events.
 *
 * Fires identified identity and tracking events from WordPress core hooks
 * (registration, login, comments) and form plugins (CF7, Elementor Pro).
 * Events are sent through Segmentflow_Ingest_Client with retry-stable
 * message IDs. No anonymous cookie or queue exists.
 *
 * @package Segmentflow_Connect
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.CapitalPDangit -- 'wordpress' is a frozen platform/source identifier.

/**
 * Class Segmentflow_Server_Events
 *
 * Hooks into WordPress core actions to fire server-side events:
 * - `user_register`                       -> identify + `user_registered` track
 * - `wp_login`                            -> identify
 * - `wp_insert_comment`                   -> identify + `comment_posted` track
 * - `wpcf7_mail_sent` (CF7)              -> identify + `form_submission` track
 * - `elementor_pro/forms/new_record`      -> identify + `form_submission` track
 */
class Segmentflow_Server_Events {

	/**
	 * Event source identifier for plain WordPress hook events.
	 */
	const EVENT_SOURCE = 'wordpress';

	/**
	 * Options instance.
	 *
	 * @var Segmentflow_Options
	 */
	private Segmentflow_Options $options;

	/**
	 * Identified ingest client.
	 *
	 * @var Segmentflow_Ingest_Client
	 */
	private Segmentflow_Ingest_Client $ingest_client;

	/**
	 * Constructor.
	 *
	 * @param Segmentflow_Options       $options       The options instance.
	 * @param Segmentflow_Ingest_Client $ingest_client The identified ingest client.
	 */
	public function __construct( Segmentflow_Options $options, Segmentflow_Ingest_Client $ingest_client ) {
		$this->options       = $options;
		$this->ingest_client = $ingest_client;
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

		add_action( 'user_register', [ $this, 'on_user_register' ], 10, 2 );
		add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
		add_action( 'wp_insert_comment', [ $this, 'on_comment' ], 10, 2 );

		if ( defined( 'WPCF7_VERSION' ) ) {
			add_action( 'wpcf7_mail_sent', [ $this, 'on_cf7_submit' ], 10, 1 );
		}

		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			add_action( 'elementor_pro/forms/new_record', [ $this, 'on_elementor_submit' ], 10, 2 );
		}
	}

	/**
	 * Handle the `user_register` action.
	 *
	 * @param int   $user_id  The new user's ID.
	 * @param array $userdata The raw user data array (unused, required by hook signature).
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook callback must accept all parameters from user_register action.
	public function on_user_register( int $user_id, array $userdata ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$email = Segmentflow_Ingest_Event::normalize_email( (string) $user->user_email );
		if ( '' === $email ) {
			return;
		}

		$user_id_prefixed = $this->get_prefixed_user_id( $user_id );
		$timestamp        = gmdate( 'c' );

		$identify_input = 'wordpress:user_registered:identify:' . $user_id;
		$track_input    = 'wordpress:user_registered:track:' . $user_id;

		$traits = [ 'email' => $email ];
		if ( ! empty( $user->first_name ) ) {
			$traits['first_name'] = $user->first_name;
		}
		if ( ! empty( $user->last_name ) ) {
			$traits['last_name'] = $user->last_name;
		}

		$items = [
			Segmentflow_Ingest_Event::identify(
				$email,
				Segmentflow_Ingest_Event::deterministic_message_id( 'wordpress:user_registered:identify', $identify_input ),
				$user_id_prefixed,
				$traits,
				$timestamp,
				self::EVENT_SOURCE
			),
			Segmentflow_Ingest_Event::track(
				'user_registered',
				Segmentflow_Ingest_Event::deterministic_message_id( 'wordpress:user_registered:track', $track_input ),
				$email,
				$user_id_prefixed,
				[
					'user_id' => $user_id,
					'email'   => $email,
				],
				$timestamp,
				self::EVENT_SOURCE
			),
		];

		$this->send_items( $items );
	}

	/**
	 * Handle the `wp_login` action.
	 *
	 * @param string  $user_login The username.
	 * @param WP_User $user       The WP_User object.
	 */
	public function on_login( string $user_login, WP_User $user ): void {
		$email = Segmentflow_Ingest_Event::normalize_email( (string) $user->user_email );
		if ( '' === $email ) {
			return;
		}

		$user_id_prefixed = $this->get_prefixed_user_id( $user->ID );
		$occurrence_uuid  = Segmentflow_Ingest_Event::generate_uuidv7();
		$timestamp        = gmdate( 'c' );

		$traits = [ 'email' => $email ];
		if ( ! empty( $user->first_name ) ) {
			$traits['first_name'] = $user->first_name;
		}
		if ( ! empty( $user->last_name ) ) {
			$traits['last_name'] = $user->last_name;
		}

		$item = Segmentflow_Ingest_Event::identify(
			$email,
			Segmentflow_Ingest_Event::occurrence_message_id( 'wordpress:login', $occurrence_uuid ),
			$user_id_prefixed,
			$traits,
			$timestamp,
			self::EVENT_SOURCE
		);

		$this->send_items( [ $item ] );
	}

	/**
	 * Handle the `wp_insert_comment` action.
	 *
	 * @param int        $comment_id The comment ID.
	 * @param WP_Comment $comment    The comment object.
	 */
	public function on_comment( int $comment_id, WP_Comment $comment ): void {
		$approved = $comment->comment_approved;
		if ( '1' !== (string) $approved && 'approve' !== $approved ) {
			return;
		}

		$post = get_post( $comment->comment_post_ID );
		if ( $post && in_array( $post->post_type, [ 'shop_order', 'shop_order_placehold' ], true ) ) {
			return;
		}

		$email = Segmentflow_Ingest_Event::normalize_email( (string) $comment->comment_author_email );
		if ( '' === $email ) {
			return;
		}

		$comment_user_id  = (int) $comment->user_id;
		$user_id_prefixed = $comment_user_id ? $this->get_prefixed_user_id( $comment_user_id ) : null;
		$timestamp        = gmdate( 'c' );

		$identify_input = 'wordpress:comment:identify:' . $comment_id;
		$track_input    = 'wordpress:comment:track:' . $comment_id;

		$traits = [ 'email' => $email ];
		if ( ! empty( $comment->comment_author ) ) {
			$traits['name'] = $comment->comment_author;
		}

		$items = [
			Segmentflow_Ingest_Event::identify(
				$email,
				Segmentflow_Ingest_Event::deterministic_message_id( 'wordpress:comment:identify', $identify_input ),
				$user_id_prefixed,
				$traits,
				$timestamp,
				self::EVENT_SOURCE
			),
			Segmentflow_Ingest_Event::track(
				'comment_posted',
				Segmentflow_Ingest_Event::deterministic_message_id( 'wordpress:comment:track', $track_input ),
				$email,
				$user_id_prefixed,
				[
					'comment_id' => $comment_id,
					'post_id'    => $comment->comment_post_ID,
					'post_title' => $post ? $post->post_title : '',
					'post_type'  => $post ? $post->post_type : '',
					'email'      => $email,
				],
				$timestamp,
				self::EVENT_SOURCE
			),
		];

		$this->send_items( $items );
	}

	/**
	 * Handle the `wpcf7_mail_sent` action (Contact Form 7).
	 *
	 * @param object $contact_form The WPCF7_ContactForm instance.
	 */
	public function on_cf7_submit( object $contact_form ): void {
		$submission = class_exists( 'WPCF7_Submission' ) ? \WPCF7_Submission::get_instance() : null;
		if ( ! $submission ) {
			return;
		}

		$posted_data = $submission->get_posted_data();
		$email       = $this->extract_email_from_form_data( is_array( $posted_data ) ? $posted_data : [] );
		$form_title  = method_exists( $contact_form, 'title' ) ? (string) $contact_form->title() : '';
		$form_id     = method_exists( $contact_form, 'id' ) ? (int) $contact_form->id() : 0;

		$this->handle_form_submission( $email, $form_title, $form_id, 'cf7', is_array( $posted_data ) ? $posted_data : [] );
	}

	/**
	 * Handle the `elementor_pro/forms/new_record` action (Elementor Pro).
	 *
	 * @param object $record       The Elementor form record.
	 * @param object $ajax_handler The Elementor AJAX handler (unused, required by hook signature).
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook callback must accept all parameters.
	public function on_elementor_submit( object $record, object $ajax_handler ): void {
		$raw_fields = method_exists( $record, 'get' ) ? $record->get( 'fields' ) : [];
		$email      = '';
		$form_data  = [];

		if ( is_array( $raw_fields ) ) {
			foreach ( $raw_fields as $field_id => $field ) {
				$value                  = is_array( $field ) ? ( $field['value'] ?? '' ) : '';
				$form_data[ $field_id ] = $value;

				if ( empty( $email ) && $value && is_array( $field ) ) {
					$type = $field['type'] ?? '';
					$id   = $field['id'] ?? $field_id;

					if ( 'email' === $type || in_array( strtolower( (string) $id ), [ 'email', 'your-email', 'your_email' ], true ) ) {
						$normalized = Segmentflow_Ingest_Event::normalize_email( (string) $value );
						if ( '' !== $normalized ) {
							$email = $normalized;
						}
					}
				}
			}
		}

		$form_name = method_exists( $record, 'get_form_settings' )
			? (string) ( $record->get_form_settings( 'form_name' ) ?? '' )
			: '';

		$this->handle_form_submission( $email, $form_name, 0, 'elementor', $form_data );
	}

	/**
	 * Handle a confirmed form submission.
	 *
	 * @param string               $email      Extracted email (may be empty).
	 * @param string               $form_title The form title/name.
	 * @param int                  $form_id    The form ID (0 if unknown).
	 * @param string               $form_type  Form plugin type ('cf7' or 'elementor').
	 * @param array<string, mixed> $form_data  The submitted form data.
	 */
	private function handle_form_submission( string $email, string $form_title, int $form_id, string $form_type, array $form_data ): void {
		$email = Segmentflow_Ingest_Event::normalize_email( $email );
		if ( '' === $email ) {
			return;
		}

		$base_uuid = Segmentflow_Ingest_Event::generate_uuidv7();
		$timestamp = gmdate( 'c' );

		$traits = [ 'email' => $email ];
		$name   = $this->extract_name_from_form_data( $form_data );
		if ( $name ) {
			$traits['name'] = $name;
		}

		$properties = [
			'form_type' => $form_type,
			'email'     => $email,
		];
		if ( $form_title ) {
			$properties['form_title'] = $form_title;
		}
		if ( $form_id ) {
			$properties['form_id'] = $form_id;
		}

		$items = [
			Segmentflow_Ingest_Event::identify(
				$email,
				Segmentflow_Ingest_Event::occurrence_message_id( 'wordpress:form:identify', $base_uuid ),
				null,
				$traits,
				$timestamp,
				self::EVENT_SOURCE
			),
			Segmentflow_Ingest_Event::track(
				'form_submission',
				Segmentflow_Ingest_Event::occurrence_message_id( 'wordpress:form:track', $base_uuid ),
				$email,
				null,
				$properties,
				$timestamp,
				self::EVENT_SOURCE
			),
		];

		$this->send_items( $items );
	}

	/**
	 * Extract an email from form data.
	 *
	 * @param array<string, mixed> $data The submitted form data.
	 * @return string
	 */
	private function extract_email_from_form_data( array $data ): string {
		$email_fields = [ 'your-email', 'email', 'your_email', 'user_email', 'contact-email' ];

		foreach ( $email_fields as $field_name ) {
			if ( empty( $data[ $field_name ] ) ) {
				continue;
			}
			$value      = is_array( $data[ $field_name ] ) ? $data[ $field_name ][0] : $data[ $field_name ];
			$normalized = Segmentflow_Ingest_Event::normalize_email( (string) $value );
			if ( '' !== $normalized ) {
				return $normalized;
			}
		}

		foreach ( $data as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$normalized = Segmentflow_Ingest_Event::normalize_email( $value );
			if ( '' !== $normalized ) {
				return $normalized;
			}
		}

		return '';
	}

	/**
	 * Extract a name from form data.
	 *
	 * @param array<string, mixed> $data The submitted form data.
	 * @return string
	 */
	private function extract_name_from_form_data( array $data ): string {
		$name_fields = [ 'your-name', 'name', 'your_name', 'full-name', 'full_name', 'contact-name' ];

		foreach ( $name_fields as $field_name ) {
			if ( empty( $data[ $field_name ] ) ) {
				continue;
			}
			$value = is_array( $data[ $field_name ] ) ? $data[ $field_name ][0] : $data[ $field_name ];
			return sanitize_text_field( (string) $value );
		}

		return '';
	}

	/**
	 * Get a prefixed user ID string.
	 *
	 * Uses 'wc_' when WooCommerce is active, 'wp_' otherwise.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return string
	 */
	private function get_prefixed_user_id( int $user_id ): string {
		$prefix = Segmentflow_Helper::is_woocommerce_active() ? 'wc_' : 'wp_';
		return $prefix . $user_id;
	}

	/**
	 * Read consent flags currently recorded in `sf_consent`.
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
	 * Send pre-built identified items through the ingest client.
	 *
	 * @param array<int, array<string, mixed>> $items Identified ingest items.
	 */
	private function send_items( array $items ): void {
		if ( empty( $items ) || empty( $this->options->get_write_key() ) ) {
			return;
		}

		$this->ingest_client->send_batch( $items, $this->get_consent_payload() );
	}
}
