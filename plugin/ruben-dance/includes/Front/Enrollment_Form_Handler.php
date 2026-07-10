<?php
/**
 * Processes `[rd_enroll]` form submissions, before any page output is sent.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Course_Fields;
use RubenDance\Lang;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Email_Log_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Services\Duplicate_Enrollment_Exception;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Plain_Mailer;
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
			self::send_confirmation_email( $enrollment, $term );
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
	 * Send the E2 confirmation email (spec F14: "Enrollment created ...
	 * summary (incl. participant) + payment instructions") through the
	 * `Mailer` interface and record it in `wp_rd_email_log`. Deliberately
	 * plain text (spec M08 "Out of scope": real templates are M13), the same
	 * reasoning `Registration_Service::issue_verification_token()` uses for
	 * E1.
	 *
	 * @param array<string, mixed> $enrollment Enrollment row.
	 * @param array<string, mixed> $term       Term row.
	 */
	private static function send_confirmation_email( array $enrollment, array $term ): void {
		$user = get_userdata( (int) $enrollment['user_id'] );

		if ( false === $user ) {
			return;
		}

		$locale = (string) get_user_meta( $user->ID, Registration_Service::META_LOCALE, true );
		$locale = '' === $locale ? Lang::DEFAULT_LANGUAGE : $locale;

		$course_id    = (int) $term['course_id'];
		$course_title = get_the_title( $course_id );

		$is_en = Lang::EN === $locale;
		$who   = '' === trim( (string) $enrollment['participant_name'] ) ? $user->display_name : (string) $enrollment['participant_name'];

		$season = $is_en && '' !== trim( (string) $term['season_label_en'] ) ? (string) $term['season_label_en'] : (string) $term['season_label_cs'];

		$bank_account = Settings::bank_account();
		$bank_account = '' === $bank_account ? __( '(to be confirmed)', 'ruben-dance' ) : $bank_account;

		if ( $is_en ) {
			$subject = sprintf(
				/* translators: %s: course name. */
				__( 'Your enrollment: %s', 'ruben-dance' ),
				$course_title
			);

			$body = sprintf(
				/* translators: 1: participant name, 2: course name, 3: season label, 4: amount, 5: discount note or empty, 6: bank account, 7: variable symbol, 8: due date. */
				__( "Thanks for enrolling %1\$s in \"%2\$s\" (%3\$s).\n\nPayment instructions:\nAmount: %4\$s CZK%5\$s\nBank account: %6\$s\nVariable symbol: %7\$s\nDue date: %8\$s\n\nPlease use the variable symbol so we can match your payment. This email confirms your enrollment; our Terms & Conditions apply.", 'ruben-dance' ),
				$who,
				$course_title,
				$season,
				(string) $enrollment['price'],
				null === $enrollment['discount_note'] || '' === (string) $enrollment['discount_note'] ? '' : ' (' . (string) $enrollment['discount_note'] . ')',
				$bank_account,
				(string) $enrollment['variable_symbol'],
				(string) $enrollment['due_date']
			);
		} else {
			$subject = sprintf(
				/* translators: %s: course name. */
				__( 'Vaše přihláška: %s', 'ruben-dance' ),
				$course_title
			);

			$body = sprintf(
				/* translators: 1: participant name, 2: course name, 3: season label, 4: amount, 5: discount note or empty, 6: bank account, 7: variable symbol, 8: due date. */
				__( "Děkujeme za přihlášení (%1\$s) na kurz \"%2\$s\" (%3\$s).\n\nPlatební instrukce:\nČástka: %4\$s Kč%5\$s\nČíslo účtu: %6\$s\nVariabilní symbol: %7\$s\nSplatnost: %8\$s\n\nUveďte prosím variabilní symbol, ať platbu správně spárujeme. Tento email potvrzuje vaši přihlášku; platí naše obchodní podmínky.", 'ruben-dance' ),
				$who,
				$course_title,
				$season,
				(string) $enrollment['price'],
				null === $enrollment['discount_note'] || '' === (string) $enrollment['discount_note'] ? '' : ' (' . (string) $enrollment['discount_note'] . ')',
				$bank_account,
				(string) $enrollment['variable_symbol'],
				(string) $enrollment['due_date']
			);
		}

		$mailer = new Plain_Mailer();
		$sent   = $mailer->send( $user->user_email, $subject, $body );

		( new Email_Log_Repository() )->insert(
			array(
				'enrollment_id' => (int) $enrollment['id'],
				'user_id'       => $user->ID,
				'type'          => 'enrollment_confirmation',
				'recipient'     => $user->user_email,
				'subject'       => $subject,
				'sent_at'       => current_time( 'mysql' ),
				'status'        => $sent ? 'sent' : 'failed',
			)
		);
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
