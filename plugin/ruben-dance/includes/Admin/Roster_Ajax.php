<?php
/**
 * AJAX handlers for the roster's paid toggle (F11a).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Email_Log_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Roles;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Illegal_Status_Transition_Exception;
use RubenDance\Services\Plain_Mailer;
use RubenDance\Services\Roster_Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Roster_Ajax.
 *
 * Every handler enforces nonce + `rd_manage` capability before touching
 * anything (`guard()`, called first in every handler — spec overview
 * convention: "every admin action nonce + `rd_manage` capability checked").
 * `handle_mark()`/`handle_unmark()` do nothing but call
 * `Services\Enrollment_Service::mark_paid()`/`unmark_paid()` — the state
 * machine itself, including "unmark never emails", already lives there
 * (spec §3.2); this class is only the AJAX transport plus the *separate*,
 * explicitly-requested `handle_send_email()` action a "mark" can optionally
 * trigger (spec F11a: "asks whether to send the E4 ... email (default
 * yes)").
 */
class Roster_Ajax {

	/**
	 * Nonce action shared by every action below.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'rd_roster_ajax';

	const ACTION_MARK       = 'rd_roster_mark_paid';
	const ACTION_UNMARK     = 'rd_roster_unmark_paid';
	const ACTION_SEND_EMAIL = 'rd_roster_send_paid_email';

	/**
	 * Hook registration. `wp_ajax_*` only (never `wp_ajax_nopriv_*`) — every
	 * action here is manager-only, so a logged-out request has nothing to
	 * hook into and WordPress core answers it with its own "no such action"
	 * `-1` response before this class ever runs.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_MARK, array( self::class, 'handle_mark' ) );
		add_action( 'wp_ajax_' . self::ACTION_UNMARK, array( self::class, 'handle_unmark' ) );
		add_action( 'wp_ajax_' . self::ACTION_SEND_EMAIL, array( self::class, 'handle_send_email' ) );
	}

	/**
	 * Mark an enrollment paid, then respond with the updated row + header
	 * stats (spec F11a: "single click, AJAX, no page reload").
	 */
	public static function handle_mark(): void {
		$enrollment_id = self::guard();

		try {
			Enrollment_Service::create_default()->mark_paid( $enrollment_id, get_current_user_id() );
		} catch ( Illegal_Status_Transition_Exception | \InvalidArgumentException $e ) {
			wp_send_json_error( array( 'message' => __( 'Could not mark this enrollment paid — it may already be paid or cancelled.', 'ruben-dance' ) ), 409 );
		}

		self::respond_with_row( $enrollment_id );
	}

	/**
	 * Unmark an enrollment as paid, then respond with the updated row +
	 * header stats. Never touches `Mailer`/`Email_Log_Repository` — spec
	 * F11a: "unmark ... never sends an email" — there is simply no email
	 * code path reachable from here at all.
	 */
	public static function handle_unmark(): void {
		$enrollment_id = self::guard();

		try {
			Enrollment_Service::create_default()->unmark_paid( $enrollment_id );
		} catch ( Illegal_Status_Transition_Exception | \InvalidArgumentException $e ) {
			wp_send_json_error( array( 'message' => __( 'Could not unmark this enrollment — it may not currently be paid.', 'ruben-dance' ) ), 409 );
		}

		self::respond_with_row( $enrollment_id );
	}

	/**
	 * Send the E4 payment-confirmation email for an already-paid enrollment,
	 * a *separate*, explicitly-requested action from `handle_mark()` (spec
	 * F11a: the confirm prompt after marking paid). Real CS/EN templates are
	 * M13's job (spec M11 "Out of scope": "real E4 email"); this sends a
	 * minimal placeholder body through the same `Mailer` interface M13 will
	 * still use, so no call site changes when the real templates land.
	 */
	public static function handle_send_email(): void {
		$enrollment_id = self::guard();

		$enrollment = ( new Enrollment_Repository() )->find( $enrollment_id );

		if ( null === $enrollment || Enrollment_Service::STATUS_PAID !== (string) $enrollment['status'] ) {
			wp_send_json_error( array( 'message' => __( 'This enrollment is not currently marked paid.', 'ruben-dance' ) ), 409 );
		}

		$user = get_userdata( (int) $enrollment['user_id'] );

		if ( false === $user ) {
			wp_send_json_error( array( 'message' => __( 'Customer account not found.', 'ruben-dance' ) ), 404 );
		}

		$term = ( new Course_Term_Repository() )->find( (int) $enrollment['term_id'] );

		$subject = __( 'Payment received — Ruben Dance', 'ruben-dance' );
		$body    = sprintf(
			/* translators: 1: course/term season label, 2: amount in CZK, 3: variable symbol. */
			__( "We have received your payment.\n\nCourse: %1\$s\nAmount: %2\$s Kč\nVariable symbol: %3\$s\n\nSee you in class!", 'ruben-dance' ),
			null === $term ? '' : (string) $term['season_label_cs'],
			number_format( (float) $enrollment['price'], 2 ),
			(string) $enrollment['variable_symbol']
		);

		$sent = ( new Plain_Mailer() )->send( $user->user_email, $subject, $body );

		( new Email_Log_Repository() )->insert(
			array(
				'enrollment_id' => $enrollment_id,
				'user_id'       => $user->ID,
				'type'          => 'E4',
				'recipient'     => $user->user_email,
				'subject'       => $subject,
				'sent_at'       => current_time( 'mysql' ),
				'status'        => $sent ? 'sent' : 'failed',
			)
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'The email could not be sent.', 'ruben-dance' ) ), 500 );
		}

		wp_send_json_success( array( 'recipient' => $user->user_email ) );
	}

	/**
	 * Capability + nonce check shared by every handler, then the validated
	 * `enrollment_id`. Every failure path calls `wp_send_json_error()`,
	 * which itself calls `wp_die()` — nothing after this method ever runs
	 * once a check fails, so callers can treat its return value as
	 * unconditionally valid.
	 *
	 * @return int
	 */
	private static function guard(): int {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'ruben-dance' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed — please reload the page.', 'ruben-dance' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified explicitly above via check_ajax_referer(); this only reads the target ID.
		$enrollment_id = isset( $_POST['enrollment_id'] ) ? absint( $_POST['enrollment_id'] ) : 0;

		if ( 0 === $enrollment_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing enrollment ID.', 'ruben-dance' ) ), 400 );
		}

		return $enrollment_id;
	}

	/**
	 * Build and send the `{ enrollment, stats }` JSON response `handle_mark()`/
	 * `handle_unmark()` share, using `Roster_Page::stat_fragments()` for the
	 * header text so the AJAX response and the initial page render can never
	 * disagree on formatting.
	 *
	 * @param int $enrollment_id Enrollment ID, already known to exist (just
	 *                           written to by the caller).
	 */
	private static function respond_with_row( int $enrollment_id ): void {
		$enrollment = ( new Enrollment_Repository() )->find( $enrollment_id );

		if ( null === $enrollment ) {
			wp_send_json_error( array( 'message' => __( 'Enrollment not found.', 'ruben-dance' ) ), 404 );
		}

		$term     = ( new Course_Term_Repository() )->find( (int) $enrollment['term_id'] );
		$capacity = ( null === $term || null === $term['capacity'] || '' === (string) $term['capacity'] ) ? null : (int) $term['capacity'];

		$enrollments          = ( new Enrollment_Repository() )->for_term( (int) $enrollment['term_id'] );
		$roster_stats_service = new Roster_Stats();
		$stats                = $roster_stats_service->compute( $enrollments, $capacity );

		$is_paid = Enrollment_Service::STATUS_PAID === (string) $enrollment['status'];

		wp_send_json_success(
			array(
				'enrollment' => array(
					'id'          => $enrollment_id,
					'status'      => (string) $enrollment['status'],
					'paid'        => $is_paid,
					// Recomputed fresh rather than merely cleared/left alone:
					// an unmark can leave a `due_date` that is already in the
					// past, in which case the row must show the overdue badge
					// again immediately, without a page reload (spec M11
					// acceptance criteria: "Overdue badge appears exactly
					// when unpaid and due_date past").
					'overdue'     => $roster_stats_service->is_overdue( $enrollment, current_time( 'Y-m-d' ) ),
					'paidAtLabel' => ( $is_paid && null !== $enrollment['paid_at'] )
						? sprintf(
							/* translators: %s: date/time the enrollment was marked paid. */
							__( 'on %s', 'ruben-dance' ),
							mysql2date( 'j M Y H:i', (string) $enrollment['paid_at'] )
						)
						: null,
				),
				'stats'      => Roster_Page::stat_fragments( $stats ),
			)
		);
	}
}
