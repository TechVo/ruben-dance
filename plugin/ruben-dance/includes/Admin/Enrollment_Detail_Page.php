<?php
/**
 * Enrollment detail screen, reached by clicking a roster row (F11a) — and,
 * from M12 on, where every roster action beyond the paid toggle actually
 * lives (F11b).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Emails\Email_Sender;
use RubenDance\Emails\Email_Templates;
use RubenDance\Emails\Enrollment_Email_Data;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Email_Log_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Roles;
use RubenDance\Services\Duplicate_Enrollment_Exception;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Illegal_Status_Transition_Exception;
use RubenDance\Services\Registration_Service;
use RubenDance\Services\Term_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Enrollment_Detail_Page.
 *
 * Reached from `Roster_List_Table`'s/`Enrollments_List_Table`'s participant
 * link/"View details" row action; not itself a top-level or visible submenu
 * entry (registered with a null parent, the same `Term_Lessons_Page`
 * pattern — a single enrollment's detail only ever makes sense in the
 * context of a roster row). Every state-changing action (cancel, edit role/
 * partner, edit price, move to another term, add an admin note, send a
 * payment reminder — spec F11b) is its own small POST form on this one page,
 * sharing a single per-enrollment nonce and processed on `load-{hook}`
 * (never inside `render()`, the same reasoning `Locations_Page` documents),
 * each doing nothing but validate + delegate to `Services\Enrollment_Service`
 * and then POST-redirect-GET back here with a notice code — the same
 * pattern every other admin screen in this plugin follows. The link to the
 * customer's own detail screen (`Customer_Detail_Page`, spec F12) replaces
 * the placeholder link straight to WP core's `user-edit.php` that M11 left
 * here.
 */
class Enrollment_Detail_Page {

	const SLUG = 'ruben-dance-enrollment-detail';

	/**
	 * Nonce action prefix; suffixed with the enrollment ID so a nonce minted
	 * for one enrollment's actions can't be replayed against another.
	 *
	 * @var string
	 */
	const ACTION_NONCE_PREFIX = 'rd_enrollment_action_';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the hidden (no-sidebar-entry) detail page. Processing is wired to
	 * `load-{$hook_suffix}`, the same reasoning `Locations_Page::add_menu()`
	 * documents: it is the only point a save/action handler can still
	 * `wp_safe_redirect()`, before `admin-header.php` sends any output.
	 */
	public static function add_menu(): void {
		$hook_suffix = add_submenu_page(
			null, // A null parent is the documented way to register an admin page hook without adding a sidebar menu entry (see Term_Lessons_Page::add_menu()).
			__( 'Enrollment', 'ruben-dance' ),
			__( 'Enrollment', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( false !== $hook_suffix ) {
			add_action( "load-{$hook_suffix}", array( self::class, 'handle_request' ) );
		}
	}

	/**
	 * URL to one enrollment's detail screen.
	 *
	 * @param int $enrollment_id Enrollment ID.
	 * @return string
	 */
	public static function url( int $enrollment_id ): string {
		return add_query_arg(
			array(
				'page'          => self::SLUG,
				'enrollment_id' => $enrollment_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Nonce action string for a given enrollment's actions.
	 *
	 * @param int $enrollment_id Enrollment ID.
	 * @return string
	 */
	private static function nonce_action( int $enrollment_id ): string {
		return self::ACTION_NONCE_PREFIX . $enrollment_id;
	}

	/**
	 * Process an action for this screen, before any output is sent. Hooked
	 * to `load-{$hook_suffix}` (see `add_menu()`).
	 */
	public static function handle_request(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage enrollments.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only; every handler below calls check_admin_referer() before reading/writing anything.
		$action = isset( $_POST['rd_enrollment_action'] ) ? sanitize_key( wp_unslash( $_POST['rd_enrollment_action'] ) ) : '';

		switch ( $action ) {
			case 'cancel':
				self::handle_cancel();
				break;

			case 'role_partner':
				self::handle_role_partner();
				break;

			case 'price':
				self::handle_price_edit();
				break;

			case 'move':
				self::handle_move();
				break;

			case 'note':
				self::handle_add_note();
				break;

			case 'reminder':
				self::handle_send_reminder();
				break;
		}
	}

	/**
	 * The enrollment ID + nonce every handler shares, verified once.
	 *
	 * @return int
	 */
	private static function guarded_enrollment_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only reads the target ID to build the nonce action string; check_admin_referer() immediately below performs the real verification.
		$enrollment_id = isset( $_POST['enrollment_id'] ) ? absint( $_POST['enrollment_id'] ) : 0;

		check_admin_referer( self::nonce_action( $enrollment_id ) );

		return $enrollment_id;
	}

	/**
	 * Handle the "cancel" action, then send the E6 enrollment-cancelled
	 * email to the customer in their stored locale (spec F14: "Enrollment
	 * cancelled → customer"). The cancellation itself always stands — an
	 * email failure only changes which notice the redirect carries, so it is
	 * surfaced (spec M13: "never silently lost") without blocking the
	 * state change.
	 */
	private static function handle_cancel(): void {
		$enrollment_id = self::guarded_enrollment_id();

		try {
			Enrollment_Service::create_default()->cancel( $enrollment_id );
		} catch ( Illegal_Status_Transition_Exception | \InvalidArgumentException $e ) {
			self::redirect( $enrollment_id, 'cancel_failed' );
			return;
		}

		self::redirect( $enrollment_id, self::send_cancelled_email( $enrollment_id ) ? 'cancelled' : 'cancelled_email_failed' );
	}

	/**
	 * Send the E6 email for a just-cancelled enrollment.
	 *
	 * @param int $enrollment_id Enrollment ID (already cancelled).
	 * @return bool True when the email was sent (or skipped for a reason that
	 *              isn't a delivery failure, i.e. the account no longer exists).
	 */
	private static function send_cancelled_email( int $enrollment_id ): bool {
		$enrollment = ( new Enrollment_Repository() )->find( $enrollment_id );

		if ( null === $enrollment ) {
			return true;
		}

		$user = get_userdata( (int) $enrollment['user_id'] );

		if ( false === $user ) {
			// No account, no recipient — nothing to deliver or to log as failed.
			return true;
		}

		$term = ( new Course_Term_Repository() )->find( (int) $enrollment['term_id'] );
		$lang = Enrollment_Email_Data::user_lang( $user->ID );

		return Email_Sender::create_default()->send(
			Email_Templates::TYPE_E6,
			$lang,
			$user->user_email,
			Enrollment_Email_Data::placeholders( $enrollment, $term, $user, $lang ),
			$enrollment_id,
			$user->ID
		);
	}

	/**
	 * Handle the "edit role/partner" action.
	 */
	private static function handle_role_partner(): void {
		$enrollment_id = self::guarded_enrollment_id();

		$data = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by guarded_enrollment_id() above.
			'role'         => isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : Enrollment_Service::ROLE_SOLO,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by guarded_enrollment_id() above.
			'partner_name' => isset( $_POST['partner_name'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_name'] ) ) : '',
		);

		$service = Enrollment_Service::create_default();
		$errors  = $service->validate_role_partner( $data );

		if ( array() !== $errors ) {
			self::redirect( $enrollment_id, 'role_partner_invalid' );
			return;
		}

		try {
			$service->update_role_partner( $enrollment_id, $data['role'], $data['partner_name'] );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( $enrollment_id, 'not_found' );
			return;
		}

		self::redirect( $enrollment_id, 'role_partner_updated' );
	}

	/**
	 * Handle the "edit price" action (spec F11b: "requires a reason string
	 * appended to `admin_note`").
	 */
	private static function handle_price_edit(): void {
		$enrollment_id = self::guarded_enrollment_id();

		$data = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by guarded_enrollment_id() above.
			'price'  => isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by guarded_enrollment_id() above.
			'reason' => isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '',
		);

		$service = Enrollment_Service::create_default();
		$errors  = $service->validate_price_edit( $data );

		if ( array() !== $errors ) {
			self::redirect( $enrollment_id, isset( $errors['reason'] ) ? 'price_reason_required' : 'price_invalid' );
			return;
		}

		try {
			$service->edit_price( $enrollment_id, $data['price'], $data['reason'], wp_get_current_user()->display_name );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( $enrollment_id, 'not_found' );
			return;
		}

		self::redirect( $enrollment_id, 'price_updated' );
	}

	/**
	 * Handle the "move to another term" action (spec F11b: "recompute
	 * nothing — price travels unchanged ...; over-capacity flag re-evaluated
	 * on the target term").
	 */
	private static function handle_move(): void {
		$enrollment_id = self::guarded_enrollment_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by guarded_enrollment_id() above.
		$target_term_id = isset( $_POST['target_term_id'] ) ? absint( $_POST['target_term_id'] ) : 0;

		if ( 0 === $target_term_id ) {
			self::redirect( $enrollment_id, 'move_term_required' );
			return;
		}

		try {
			Enrollment_Service::create_default()->move_to_term( $enrollment_id, $target_term_id, wp_get_current_user()->display_name );
		} catch ( Illegal_Status_Transition_Exception $e ) {
			self::redirect( $enrollment_id, 'move_cancelled_blocked' );
			return;
		} catch ( Duplicate_Enrollment_Exception $e ) {
			self::redirect( $enrollment_id, 'move_duplicate' );
			return;
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( $enrollment_id, 'move_failed' );
			return;
		}

		self::redirect( $enrollment_id, 'moved' );
	}

	/**
	 * Handle the "add admin note" action.
	 */
	private static function handle_add_note(): void {
		$enrollment_id = self::guarded_enrollment_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by guarded_enrollment_id() above.
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$service = Enrollment_Service::create_default();
		$errors  = $service->validate_admin_note( array( 'note' => $note ) );

		if ( array() !== $errors ) {
			self::redirect( $enrollment_id, 'note_required' );
			return;
		}

		try {
			$service->add_admin_note( $enrollment_id, $note, wp_get_current_user()->display_name );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( $enrollment_id, 'not_found' );
			return;
		}

		self::redirect( $enrollment_id, 'note_added' );
	}

	/**
	 * Handle the "send payment reminder" action (spec F14 E7: "unpaid after
	 * N days ... manual 'send reminder' button in v1"). Only makes sense for
	 * a currently-unpaid, non-cancelled enrollment. Composed from the
	 * editable M13 template in the customer's stored locale and logged by
	 * `Emails\Email_Sender`; a `wp_mail()` failure redirects with the
	 * `reminder_failed` error notice.
	 */
	private static function handle_send_reminder(): void {
		$enrollment_id = self::guarded_enrollment_id();

		$enrollment = ( new Enrollment_Repository() )->find( $enrollment_id );

		if ( null === $enrollment || Enrollment_Service::STATUS_CONFIRMED !== (string) $enrollment['status'] ) {
			self::redirect( $enrollment_id, 'reminder_not_needed' );
			return;
		}

		$user = get_userdata( (int) $enrollment['user_id'] );

		if ( false === $user ) {
			self::redirect( $enrollment_id, 'reminder_failed' );
			return;
		}

		$term = ( new Course_Term_Repository() )->find( (int) $enrollment['term_id'] );
		$lang = Enrollment_Email_Data::user_lang( $user->ID );

		$sent = Email_Sender::create_default()->send(
			Email_Templates::TYPE_E7,
			$lang,
			$user->user_email,
			Enrollment_Email_Data::placeholders( $enrollment, $term, $user, $lang ),
			$enrollment_id,
			$user->ID
		);

		self::redirect( $enrollment_id, $sent ? 'reminder_sent' : 'reminder_failed' );
	}

	/**
	 * Redirect back to this enrollment's detail screen with a notice code
	 * (POST-redirect-GET, so a page reload never resubmits an action).
	 *
	 * @param int    $enrollment_id Enrollment ID.
	 * @param string $notice        Notice code.
	 */
	private static function redirect( int $enrollment_id, string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'rd_notice' => $notice ), self::url( $enrollment_id ) ) );
		exit;
	}

	/**
	 * Main entry point, wired as the submenu page callback. Runs after
	 * `handle_request()` (see `add_menu()`); output only, no state changes.
	 */
	public static function render(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view enrollments.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which enrollment to load, no state change.
		$enrollment_id = isset( $_GET['enrollment_id'] ) ? absint( $_GET['enrollment_id'] ) : 0;
		$enrollment    = ( new Enrollment_Repository() )->find( $enrollment_id );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Enrollment detail', 'ruben-dance' ) . '</h1>';

		if ( null === $enrollment ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Enrollment not found.', 'ruben-dance' ) . '</p></div>';
			echo '<p><a href="' . esc_url( Roster_Page::url( 0 ) ) . '">' . esc_html__( 'Back to roster', 'ruben-dance' ) . '</a></p>';
			echo '</div>';
			return;
		}

		self::render_notice_from_query();

		$term     = ( new Course_Term_Repository() )->find( (int) $enrollment['term_id'] );
		$location = null === $term ? null : ( new Location_Repository() )->find( (int) $term['location_id'] );
		$user     = get_userdata( (int) $enrollment['user_id'] );

		self::render_summary( $enrollment, $term, $location, $user );
		self::render_price_breakdown( $enrollment, $term );
		self::render_notes( $enrollment );
		self::render_email_history( $enrollment_id );

		if ( Enrollment_Service::STATUS_CANCELLED !== (string) $enrollment['status'] ) {
			self::render_actions( $enrollment, $term );
		}

		echo '<p><a href="' . esc_url( Roster_Page::url( (int) $enrollment['term_id'] ) ) . '">' . esc_html__( 'Back to roster', 'ruben-dance' ) . '</a>';
		echo ' | <a href="' . esc_url( Enrollments_Page::url() ) . '">' . esc_html__( 'All enrollments', 'ruben-dance' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Term/participant/account-holder/status field table.
	 *
	 * @param array<string, mixed>      $enrollment Enrollment row.
	 * @param array<string, mixed>|null $term       Term row, or null if the term no longer exists.
	 * @param array<string, mixed>|null $location   Location row, or null.
	 * @param \WP_User|false            $user       Account-holder user, or false if the account no longer exists.
	 */
	private static function render_summary( array $enrollment, ?array $term, ?array $location, $user ): void {
		echo '<h2>' . esc_html__( 'Summary', 'ruben-dance' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:800px;"><tbody>';

		self::row(
			__( 'Term', 'ruben-dance' ),
			null === $term ? esc_html__( '(term no longer exists)', 'ruben-dance' ) : sprintf(
				'%1$s — %2$s at %3$s',
				esc_html( (string) $term['season_label_cs'] ),
				esc_html( get_the_title( (int) $term['course_id'] ) ),
				esc_html( null === $location ? '' : (string) $location['name'] )
			)
		);

		$participant_name = trim( (string) $enrollment['participant_name'] );

		self::row( __( 'Participant', 'ruben-dance' ), '' !== $participant_name ? esc_html( $participant_name ) : esc_html__( '(account holder)', 'ruben-dance' ) );

		if ( false !== $user ) {
			$holder_html = esc_html( $user->display_name ) . ' — ' . esc_html( $user->user_email );
			$phone       = get_user_meta( $user->ID, Registration_Service::META_PHONE, true );

			if ( is_string( $phone ) && '' !== $phone ) {
				$holder_html .= ' — ' . esc_html( $phone );
			}

			$holder_html .= ' &mdash; <a href="' . esc_url( Customer_Detail_Page::url( $user->ID ) ) . '">' . esc_html__( 'View customer detail', 'ruben-dance' ) . '</a>';

			self::row( __( 'Account holder', 'ruben-dance' ), $holder_html, true );
		} else {
			self::row( __( 'Account holder', 'ruben-dance' ), esc_html__( '(account no longer exists)', 'ruben-dance' ) );
		}

		self::row( __( 'Role', 'ruben-dance' ), esc_html( self::role_label( (string) $enrollment['role'] ) ) );

		$partner_name = trim( (string) ( $enrollment['partner_name'] ?? '' ) );
		self::row( __( 'Partner', 'ruben-dance' ), '' !== $partner_name ? esc_html( $partner_name ) : esc_html__( '—', 'ruben-dance' ) );

		self::row( __( 'Status', 'ruben-dance' ), esc_html( self::status_label( (string) $enrollment['status'] ) ) );
		self::row( __( 'Over capacity', 'ruben-dance' ), ! empty( $enrollment['over_capacity'] ) ? esc_html__( 'Yes', 'ruben-dance' ) : esc_html__( 'No', 'ruben-dance' ) );
		self::row( __( 'Payment method', 'ruben-dance' ), esc_html( 'cash' === $enrollment['payment_method'] ? __( 'Cash', 'ruben-dance' ) : __( 'Bank transfer', 'ruben-dance' ) ) );
		self::row( __( 'Variable symbol', 'ruben-dance' ), esc_html( (string) $enrollment['variable_symbol'] ) );
		self::row( __( 'Due date', 'ruben-dance' ), esc_html( (string) $enrollment['due_date'] ) );

		if ( null !== $enrollment['paid_at'] ) {
			$marked_by = null;
			if ( null !== $enrollment['paid_marked_by'] ) {
				$marked_by = get_userdata( (int) $enrollment['paid_marked_by'] );
			}

			self::row(
				__( 'Paid at', 'ruben-dance' ),
				esc_html(
					mysql2date( 'j M Y H:i', (string) $enrollment['paid_at'] ) . ( ( null !== $marked_by && false !== $marked_by ) ? ' — ' . sprintf(
					/* translators: %s: admin display name. */
						__( 'marked by %s', 'ruben-dance' ),
						$marked_by->display_name
					) : '' )
				)
			);
		} else {
			self::row( __( 'Paid at', 'ruben-dance' ), esc_html__( 'Not paid', 'ruben-dance' ) );
		}

		self::row( __( 'Created', 'ruben-dance' ), esc_html( mysql2date( 'j M Y H:i', (string) $enrollment['created_at'] ) ) );
		self::row( __( 'Last updated', 'ruben-dance' ), esc_html( mysql2date( 'j M Y H:i', (string) $enrollment['updated_at'] ) ) );

		echo '</tbody></table>';
	}

	/**
	 * Price/discount breakdown (spec F11a: "all fields incl. price/discount
	 * breakdown"). Editing itself now lives in `render_actions()` (spec
	 * F11b: "edit price").
	 *
	 * @param array<string, mixed>      $enrollment Enrollment row.
	 * @param array<string, mixed>|null $term       Term row, or null.
	 */
	private static function render_price_breakdown( array $enrollment, ?array $term ): void {
		echo '<h2>' . esc_html__( 'Price', 'ruben-dance' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:800px;"><tbody>';

		if ( null !== $term ) {
			self::row( __( 'Term list price', 'ruben-dance' ), esc_html( number_format( (float) $term['price'], 2 ) . ' Kč' ) );
		}

		$discount_note = trim( (string) ( $enrollment['discount_note'] ?? '' ) );
		self::row( __( 'Discount breakdown', 'ruben-dance' ), '' !== $discount_note ? esc_html( $discount_note ) : esc_html__( 'None', 'ruben-dance' ) );

		self::row( __( 'Final price charged', 'ruben-dance' ), '<strong>' . esc_html( number_format( (float) $enrollment['price'], 2 ) . ' Kč' ) . '</strong>', true );

		echo '</tbody></table>';
	}

	/**
	 * Customer note + internal admin note (the latter now an accumulating
	 * audit trail — see `Services\Enrollment_Service::append_note()` — every
	 * price edit/move/manual add-note writes a new line here, never
	 * overwriting the previous ones).
	 *
	 * @param array<string, mixed> $enrollment Enrollment row.
	 */
	private static function render_notes( array $enrollment ): void {
		echo '<h2>' . esc_html__( 'Notes', 'ruben-dance' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:800px;"><tbody>';

		$customer_note = trim( (string) ( $enrollment['customer_note'] ?? '' ) );
		self::row( __( 'Customer note', 'ruben-dance' ), '' !== $customer_note ? nl2br( esc_html( $customer_note ) ) : esc_html__( '—', 'ruben-dance' ), true );

		$admin_note = trim( (string) ( $enrollment['admin_note'] ?? '' ) );
		self::row( __( 'Admin note', 'ruben-dance' ), '' !== $admin_note ? nl2br( esc_html( $admin_note ) ) : esc_html__( '—', 'ruben-dance' ), true );

		echo '</tbody></table>';
	}

	/**
	 * Email history for this enrollment (spec F11a: "email history from
	 * `wp_rd_email_log`").
	 *
	 * @param int $enrollment_id Enrollment ID.
	 */
	private static function render_email_history( int $enrollment_id ): void {
		$emails = ( new Email_Log_Repository() )->for_enrollment( $enrollment_id );

		echo '<h2>' . esc_html__( 'Email history', 'ruben-dance' ) . '</h2>';

		if ( array() === $emails ) {
			echo '<p>' . esc_html__( 'No emails logged for this enrollment yet.', 'ruben-dance' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Sent', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Recipient', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Subject', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ruben-dance' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $emails as $email ) {
			echo '<tr>';
			echo '<td>' . esc_html( mysql2date( 'j M Y H:i', (string) $email['sent_at'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $email['type'] ) . '</td>';
			echo '<td>' . esc_html( (string) $email['recipient'] ) . '</td>';
			echo '<td>' . esc_html( (string) $email['subject'] ) . '</td>';
			echo '<td>' . esc_html( (string) $email['status'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Roster actions (spec F11b): cancel, edit role/partner, edit price,
	 * move to another term, add admin note, send payment reminder. Each is
	 * its own tiny POST form sharing the one nonce this enrollment's page
	 * mints (see `nonce_action()`); every handler validates + delegates to
	 * `Services\Enrollment_Service`, never touching the database directly.
	 *
	 * @param array<string, mixed>      $enrollment Enrollment row.
	 * @param array<string, mixed>|null $term       Current term row, or null.
	 */
	private static function render_actions( array $enrollment, ?array $term ): void {
		$id = (int) $enrollment['id'];

		echo '<h2>' . esc_html__( 'Actions', 'ruben-dance' ) . '</h2>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:2em;">';

		self::render_role_partner_form( $enrollment );
		self::render_price_form( $enrollment );
		self::render_move_form( $id, $term );
		self::render_note_form( $id );

		echo '</div>';

		self::render_reminder_form( $enrollment );
		self::render_cancel_form( $id );
	}

	/**
	 * "Edit role/partner" form.
	 *
	 * @param array<string, mixed> $enrollment Enrollment row.
	 */
	private static function render_role_partner_form( array $enrollment ): void {
		$id = (int) $enrollment['id'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '" style="min-width:260px;">';
		self::render_hidden_fields( $id, 'role_partner' );

		echo '<h3>' . esc_html__( 'Role / partner', 'ruben-dance' ) . '</h3>';

		echo '<p><label for="rd_enrollment_role">' . esc_html__( 'Role', 'ruben-dance' ) . '</label><br>';
		echo '<select id="rd_enrollment_role" name="role">';
		foreach (
			array(
				Enrollment_Service::ROLE_SOLO     => __( 'Solo', 'ruben-dance' ),
				Enrollment_Service::ROLE_LEADER   => __( 'Leader', 'ruben-dance' ),
				Enrollment_Service::ROLE_FOLLOWER => __( 'Follower', 'ruben-dance' ),
			) as $value => $label
		) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $enrollment['role'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></p>';

		echo '<p><label for="rd_enrollment_partner_name">' . esc_html__( 'Partner name', 'ruben-dance' ) . '</label><br>';
		echo '<input type="text" id="rd_enrollment_partner_name" name="partner_name" value="' . esc_attr( (string) ( $enrollment['partner_name'] ?? '' ) ) . '"></p>';

		submit_button( __( 'Save role / partner', 'ruben-dance' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * "Edit price" form (spec F11b: "requires a reason string appended to
	 * `admin_note`").
	 *
	 * @param array<string, mixed> $enrollment Enrollment row.
	 */
	private static function render_price_form( array $enrollment ): void {
		$id = (int) $enrollment['id'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '" style="min-width:260px;">';
		self::render_hidden_fields( $id, 'price' );

		echo '<h3>' . esc_html__( 'Edit price', 'ruben-dance' ) . '</h3>';

		echo '<p><label for="rd_enrollment_price">' . esc_html__( 'New price (CZK)', 'ruben-dance' ) . '</label><br>';
		echo '<input type="number" step="0.01" min="0" id="rd_enrollment_price" name="price" required="required" value="' . esc_attr( (string) $enrollment['price'] ) . '"></p>';

		echo '<p><label for="rd_enrollment_price_reason">' . esc_html__( 'Reason (required)', 'ruben-dance' ) . '</label><br>';
		echo '<textarea id="rd_enrollment_price_reason" name="reason" rows="2" required="required" placeholder="' . esc_attr__( 'e.g. Goodwill discount agreed by phone', 'ruben-dance' ) . '"></textarea></p>';

		submit_button( __( 'Save price', 'ruben-dance' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * "Move to another term" form (spec F11b: "move enrollment to a
	 * different term (partner balancing)"). Only open terms other than the
	 * current one are offered — the same "open terms only" rule the public
	 * enrollment form and manual enrollment already follow.
	 *
	 * @param int                       $id   Enrollment ID.
	 * @param array<string, mixed>|null $term Current term row, or null.
	 */
	private static function render_move_form( int $id, ?array $term ): void {
		$current_term_id = null === $term ? 0 : (int) $term['id'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '" style="min-width:260px;">';
		self::render_hidden_fields( $id, 'move' );

		echo '<h3>' . esc_html__( 'Move to another term', 'ruben-dance' ) . '</h3>';

		echo '<p><label for="rd_enrollment_target_term">' . esc_html__( 'Target term', 'ruben-dance' ) . '</label><br>';
		echo '<select id="rd_enrollment_target_term" name="target_term_id" required="required">';
		echo '<option value="">' . esc_html__( '— select a term —', 'ruben-dance' ) . '</option>';

		foreach ( ( new Course_Term_Repository() )->all_with_filters( array( 'status' => Term_Service::STATUS_OPEN ) ) as $candidate ) {
			$candidate_id = (int) $candidate['id'];

			if ( $candidate_id === $current_term_id ) {
				continue;
			}

			printf(
				'<option value="%1$d">%2$s — %3$s</option>',
				(int) $candidate_id,
				esc_html( (string) $candidate['season_label_cs'] ),
				esc_html( get_the_title( (int) $candidate['course_id'] ) )
			);
		}

		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Price and variable symbol travel unchanged; over-capacity is re-checked on the target term.', 'ruben-dance' ) . '</p>';

		submit_button( __( 'Move', 'ruben-dance' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * "Add admin note" form.
	 *
	 * @param int $id Enrollment ID.
	 */
	private static function render_note_form( int $id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '" style="min-width:260px;">';
		self::render_hidden_fields( $id, 'note' );

		echo '<h3>' . esc_html__( 'Add admin note', 'ruben-dance' ) . '</h3>';

		echo '<p><textarea name="note" rows="3" required="required" placeholder="' . esc_attr__( 'Internal note…', 'ruben-dance' ) . '"></textarea></p>';

		submit_button( __( 'Add note', 'ruben-dance' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * "Send payment reminder" form (spec F14 E7).
	 *
	 * @param array<string, mixed> $enrollment Enrollment row.
	 */
	private static function render_reminder_form( array $enrollment ): void {
		if ( Enrollment_Service::STATUS_CONFIRMED !== (string) $enrollment['status'] ) {
			return; // Nothing to remind about: already paid, or cancelled.
		}

		$id = (int) $enrollment['id'];

		echo '<p style="margin-top:1.5em;"><form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '" style="display:inline;">';
		self::render_hidden_fields( $id, 'reminder' );
		submit_button( __( 'Send payment reminder', 'ruben-dance' ), 'secondary', '', false );
		echo '</form></p>';
	}

	/**
	 * "Cancel enrollment" form, with a client-side confirmation dialog —
	 * cancelling is the one action here with no corresponding "undo" action
	 * (spec §3.2: `cancelled` is terminal).
	 *
	 * @param int $id Enrollment ID.
	 */
	private static function render_cancel_form( int $id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Cancel this enrollment? This cannot be undone.', 'ruben-dance' ) ) . '\');" style="display:inline;">';
		self::render_hidden_fields( $id, 'cancel' );
		submit_button( __( 'Cancel enrollment', 'ruben-dance' ), 'delete', '', false );
		echo '</form>';
	}

	/**
	 * Nonce + hidden routing fields shared by every action form on this page.
	 *
	 * @param int    $id     Enrollment ID.
	 * @param string $action One of the `rd_enrollment_action` switch cases in `handle_request()`.
	 */
	private static function render_hidden_fields( int $id, string $action ): void {
		wp_nonce_field( self::nonce_action( $id ) );
		echo '<input type="hidden" name="rd_enrollment_action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="enrollment_id" value="' . esc_attr( (string) $id ) . '">';
	}

	/**
	 * One `<tr><th>label</th><td>value</td></tr>` row.
	 *
	 * @param string $label        Already-translated label.
	 * @param string $value_html   Value markup — already escaped by the caller.
	 * @param bool   $value_is_raw Set true when `$value_html` legitimately
	 *                             contains markup (links, `<strong>`, `<br>`)
	 *                             rather than a single `esc_html()` call.
	 */
	private static function row( string $label, string $value_html, bool $value_is_raw = false ): void {
		unset( $value_is_raw ); // Documents intent at call sites; every value above is already individually escaped/composed of esc_*() calls.

		echo '<tr><th scope="row" style="width:220px;">' . esc_html( $label ) . '</th><td>' . $value_html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $value_html is always pre-built from esc_html()/esc_attr()/esc_url() calls at each call site (see method docblock).
	}

	/**
	 * A role value to its translated label.
	 *
	 * @param string $role One of `Enrollment_Service::ROLES`.
	 * @return string
	 */
	private static function role_label( string $role ): string {
		switch ( $role ) {
			case Enrollment_Service::ROLE_LEADER:
				return __( 'Leader', 'ruben-dance' );

			case Enrollment_Service::ROLE_FOLLOWER:
				return __( 'Follower', 'ruben-dance' );

			default:
				return __( 'Solo', 'ruben-dance' );
		}
	}

	/**
	 * A status value to its translated label.
	 *
	 * @param string $status One of `Enrollment_Service::STATUSES`.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		switch ( $status ) {
			case Enrollment_Service::STATUS_PAID:
				return __( 'Paid', 'ruben-dance' );

			case Enrollment_Service::STATUS_CANCELLED:
				return __( 'Cancelled', 'ruben-dance' );

			default:
				return __( 'Confirmed (unpaid)', 'ruben-dance' );
		}
	}

	/**
	 * Read the `rd_notice` query arg left by a redirect and render it.
	 */
	private static function render_notice_from_query(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: purely cosmetic (which notice text to show after a redirect), no state change.
		$notice = isset( $_GET['rd_notice'] ) ? sanitize_key( wp_unslash( $_GET['rd_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'cancelled'              => array( 'success', __( 'Enrollment cancelled. The customer was notified by email.', 'ruben-dance' ) ),
			'cancelled_email_failed' => array( 'warning', __( 'Enrollment cancelled, but the notification email could not be sent (logged as failed).', 'ruben-dance' ) ),
			'cancel_failed'          => array( 'error', __( 'Could not cancel this enrollment — it may already be cancelled.', 'ruben-dance' ) ),
			'role_partner_updated'   => array( 'success', __( 'Role/partner updated.', 'ruben-dance' ) ),
			'role_partner_invalid'   => array( 'error', __( 'Please check the role/partner fields.', 'ruben-dance' ) ),
			'price_updated'          => array( 'success', __( 'Price updated.', 'ruben-dance' ) ),
			'price_invalid'          => array( 'error', __( 'Please enter a valid, non-negative price.', 'ruben-dance' ) ),
			'price_reason_required'  => array( 'error', __( 'A reason is required to change the price.', 'ruben-dance' ) ),
			'moved'                  => array( 'success', __( 'Enrollment moved to the selected term.', 'ruben-dance' ) ),
			'move_term_required'     => array( 'error', __( 'Please select a target term.', 'ruben-dance' ) ),
			'move_failed'            => array( 'error', __( 'Could not move this enrollment — the target term was not found, or it is already the current term.', 'ruben-dance' ) ),
			'move_cancelled_blocked' => array( 'error', __( 'A cancelled enrollment cannot be moved.', 'ruben-dance' ) ),
			'move_duplicate'         => array( 'error', __( 'This account/participant already has an enrollment in the target term.', 'ruben-dance' ) ),
			'note_added'             => array( 'success', __( 'Note added.', 'ruben-dance' ) ),
			'note_required'          => array( 'error', __( 'Please enter a note.', 'ruben-dance' ) ),
			'reminder_sent'          => array( 'success', __( 'Payment reminder sent.', 'ruben-dance' ) ),
			'reminder_failed'        => array( 'error', __( 'The payment reminder could not be sent.', 'ruben-dance' ) ),
			'reminder_not_needed'    => array( 'warning', __( 'This enrollment is not currently unpaid — no reminder was sent.', 'ruben-dance' ) ),
			'not_found'              => array( 'error', __( 'Enrollment not found.', 'ruben-dance' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $messages[ $notice ];

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
