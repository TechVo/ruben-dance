<?php
/**
 * Processes `[rd_enroll]` form submissions, before any page output is sent.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Course_Fields;
use RubenDance\Emails\Email_Sender;
use RubenDance\Emails\Email_Templates;
use RubenDance\Emails\Enrollment_Email_Data;
use RubenDance\Lang;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Services\Duplicate_Enrollment_Exception;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Rate_Limiter;
use RubenDance\Services\Registration_Service;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Enrollment_Form_Handler.
 *
 * Hooked to `template_redirect`, the same as M07's `Form_Handler` (a
 * deliberately separate hook registration rather than an addition to that
 * class — see the milestone note against touching other milestones' files):
 * the only point a redirect can still happen, well before `Enroll_Page::render()`
 * echoes any HTML. Every field the form submits about *price* is ignored —
 * `Enrollment_Service::create()` recomputes it from the term row it loads
 * itself (spec §3.2/§8: "prices recomputed server-side, never trust the
 * price shown in the form").
 */
class Enrollment_Form_Handler {

	const NONCE_ACTION_PREFIX = 'rd_enroll_';

	const MAX_ATTEMPTS   = 10;
	const WINDOW_SECONDS = 900; // 15 minutes.

	const ERROR_TC_REQUIRED               = 'tc_required';
	const ERROR_PARTICIPANT_NAME_REQUIRED = 'participant_name_required';

	/**
	 * Submission result for `Enroll_Page::render()`: null (untouched, GET
	 * request), or array{ state: 'success'|'form'|'rate_limited'|'duplicate',
	 * errors: array<string,string>, submitted: array<string,mixed>,
	 * enrollment?: array<string,mixed> }.
	 *
	 * @var array{state: string, errors: array<string,string>, submitted: array<string,mixed>, enrollment?: array<string,mixed>}|null
	 */
	public static ?array $result = null;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'handle_request' ) );
	}

	/**
	 * Single entry point.
	 */
	public static function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only; handle_submit() calls wp_verify_nonce() before reading/writing anything.
		if ( ! isset( $_POST['rd_enroll_action'] ) || 'submit' !== $_POST['rd_enroll_action'] ) {
			return;
		}

		self::handle_submit();
	}

	/**
	 * Handle `[rd_enroll]` form submission.
	 */
	private static function handle_submit(): void {
		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION_PREFIX . $term_id ) ) {
			self::$result = array(
				'state'     => 'form',
				'errors'    => array( '_form' => __( 'Your session expired, please try again.', 'ruben-dance' ) ),
				'submitted' => array(),
			);
			return;
		}

		// Logged-in users only (spec F3 step 2/M08 task list): the page
		// itself never shows this form to an anonymous visitor, but a
		// crafted direct POST must still be rejected server-side.
		if ( ! is_user_logged_in() ) {
			self::$result = array(
				'state'     => 'form',
				'errors'    => array( '_form' => __( 'Please log in to complete your enrollment.', 'ruben-dance' ) ),
				'submitted' => array(),
			);
			return;
		}

		$submitted = self::sanitized_submission();

		// Bot baseline (spec §5, same as M07's Form_Handler): a filled
		// honeypot or an instantly-submitted form is silently dropped — the
		// visitor sees the same success screen a real enrollment would, but
		// nothing is created and no email sent.
		if ( Bot_Guard::is_bot( wp_unslash( $_POST ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- Bot_Guard reads/validates its own signed fields; nonce already checked above.
			self::$result = array(
				'state'     => 'success',
				'errors'    => array(),
				'submitted' => array(),
			);
			return;
		}

		if ( Rate_Limiter::create_default()->too_many_attempts( 'enroll', self::client_ip(), self::MAX_ATTEMPTS, self::WINDOW_SECONDS ) ) {
			self::$result = array(
				'state'     => 'rate_limited',
				'errors'    => array(),
				'submitted' => $submitted,
			);
			return;
		}

		$term = ( new Course_Term_Repository() )->find( $term_id );

		$roles_relevant = null !== $term && Course_Fields::is_roles_relevant( (int) $term['course_id'] );
		$role           = $roles_relevant ? $submitted['role'] : Enrollment_Service::ROLE_SOLO;

		$errors = array();

		if ( empty( $submitted['tc_accepted'] ) ) {
			$errors['tc_accepted'] = self::ERROR_TC_REQUIRED;
		}

		if ( 'other' === $submitted['participant_type'] && '' === trim( $submitted['participant_name'] ) ) {
			$errors['participant_name'] = self::ERROR_PARTICIPANT_NAME_REQUIRED;
		}

		$data = array(
			'term_id'          => $term_id,
			'user_id'          => get_current_user_id(),
			'participant_name' => 'other' === $submitted['participant_type'] ? $submitted['participant_name'] : '',
			'role'             => $role,
			'partner_name'     => $submitted['partner_name'],
			'customer_note'    => $submitted['customer_note'],
			'payment_method'   => Enrollment_Service::PAYMENT_BANK_TRANSFER,
		);

		$service        = Enrollment_Service::create_default();
		$service_errors = $service->validate( $data );

		$errors = array_merge( $errors, $service_errors );

		if ( array() !== $errors ) {
			self::$result = array(
				'state'     => 'form',
				'errors'    => $errors,
				'submitted' => $submitted,
			);
			return;
		}

		try {
			$id = $service->create( $data );
		} catch ( Duplicate_Enrollment_Exception $e ) {
			self::$result = array(
				'state'     => 'duplicate',
				'errors'    => array(),
				'submitted' => $submitted,
			);
			return;
		}

		self::maybe_update_marketing_consent( (int) $data['user_id'], ! empty( $submitted['marketing_consent'] ) );

		$enrollment = ( new Enrollment_Repository() )->find( $id );

		if ( null !== $enrollment && null !== $term ) {
			self::send_enrollment_emails( $enrollment, $term );
		}

		self::$result = array(
			'state'      => 'success',
			'errors'     => array(),
			'submitted'  => array(),
			'enrollment' => $enrollment,
		);
	}

	/**
	 * Read and sanitize the fixed set of submitted fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function sanitized_submission(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
		$participant_type = isset( $_POST['participant_type'] ) ? sanitize_key( wp_unslash( $_POST['participant_type'] ) ) : 'me';
		$participant_type = in_array( $participant_type, array( 'me', 'other' ), true ) ? $participant_type : 'me';

		return array(
			'participant_type'  => $participant_type,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
			'participant_name'  => isset( $_POST['participant_name'] ) ? sanitize_text_field( wp_unslash( $_POST['participant_name'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
			'role'              => isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : Enrollment_Service::ROLE_SOLO,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
			'partner_name'      => isset( $_POST['partner_name'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_name'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
			'customer_note'     => isset( $_POST['customer_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['customer_note'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
			'tc_accepted'       => ! empty( $_POST['tc_accepted'] ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
			'marketing_consent' => ! empty( $_POST['marketing_consent'] ),
		);
	}

	/**
	 * Update the account's marketing-consent meta when the enrollee checked
	 * the (optional) box — spec F3 step 3: "a separate optional
	 * marketing-consent checkbox". The form only ever shows this checkbox
	 * when the account hasn't already consented (`Enroll_Page::render()`),
	 * but this is re-checked here too rather than trusted from the request,
	 * so a consent timestamp is never overwritten by a stale resubmission.
	 *
	 * @param int  $user_id Account ID.
	 * @param bool $checked Whether the marketing-consent checkbox was submitted checked.
	 */
	private static function maybe_update_marketing_consent( int $user_id, bool $checked ): void {
		if ( ! $checked ) {
			return;
		}

		if ( '1' === get_user_meta( $user_id, Registration_Service::META_MARKETING_CONSENT, true ) ) {
			return; // Already consented — nothing to do.
		}

		update_user_meta( $user_id, Registration_Service::META_MARKETING_CONSENT, '1' );
		update_user_meta( $user_id, Registration_Service::META_MARKETING_CONSENT_AT, current_time( 'mysql' ) );
	}

	/**
	 * Send the two "enrollment created" emails (spec F14): E2 to the customer
	 * in their stored locale (summary incl. participant + payment
	 * instructions), and E3 to the admin notification address, always in
	 * Czech ("admin notifications (E3) always CS"), skipped when no address
	 * is configured in Settings. Both are composed from the editable M13
	 * templates and logged by `Emails\Email_Sender`; a `wp_mail()` failure is
	 * recorded there with status `failed` (there is no admin screen on this
	 * front-end trigger to surface a notice on — the log screen carries it).
	 *
	 * @param array<string, mixed> $enrollment Enrollment row.
	 * @param array<string, mixed> $term       Term row.
	 */
	private static function send_enrollment_emails( array $enrollment, array $term ): void {
		$user = get_userdata( (int) $enrollment['user_id'] );

		if ( false === $user ) {
			return;
		}

		$sender        = Email_Sender::create_default();
		$enrollment_id = (int) $enrollment['id'];
		$lang          = Enrollment_Email_Data::user_lang( $user->ID );

		$sender->send(
			Email_Templates::TYPE_E2,
			$lang,
			$user->user_email,
			Enrollment_Email_Data::placeholders( $enrollment, $term, $user, $lang ),
			$enrollment_id,
			$user->ID
		);

		$admin_email = Settings::admin_notification_email();

		if ( '' !== $admin_email ) {
			$sender->send(
				Email_Templates::TYPE_E3,
				Lang::CS,
				$admin_email,
				Enrollment_Email_Data::placeholders( $enrollment, $term, $user, Lang::CS ),
				$enrollment_id,
				$user->ID
			);
		}
	}

	/**
	 * Request IP address, used only as a rate-limit bucket key (never stored).
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only as an opaque rate-limit bucket key (hashed by Rate_Limiter), never echoed or stored verbatim.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
	}
}
