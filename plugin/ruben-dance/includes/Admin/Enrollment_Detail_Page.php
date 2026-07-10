<?php
/**
 * Enrollment detail screen, reached by clicking a roster row (F11a).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Email_Log_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Roles;
use RubenDance\Services\Enrollment_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Enrollment_Detail_Page.
 *
 * Reached from `Roster_List_Table`'s participant link/"View details" row
 * action; not itself a top-level or visible submenu entry (registered with
 * a null parent, the same `Term_Lessons_Page` pattern — a single
 * enrollment's detail only ever makes sense in the context of a roster row).
 * Read-only: all state-changing roster actions (mark/unmark paid) live on
 * `Roster_Page`/`Roster_Ajax`; this screen only adds the link to the
 * customer detail screen `Roster_Page`'s docblock and the milestone file
 * both call out as "built in M12 — plain link now" (spec F11a/F12): until
 * that screen exists, the link goes straight to the WP core user profile.
 */
class Enrollment_Detail_Page {

	const SLUG = 'ruben-dance-enrollment-detail';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the hidden (no-sidebar-entry) detail page.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			null, // A null parent is the documented way to register an admin page hook without adding a sidebar menu entry (see Term_Lessons_Page::add_menu()).
			__( 'Enrollment', 'ruben-dance' ),
			__( 'Enrollment', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
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
	 * Main entry point, wired as the submenu page callback.
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

		$term     = ( new Course_Term_Repository() )->find( (int) $enrollment['term_id'] );
		$location = null === $term ? null : ( new Location_Repository() )->find( (int) $term['location_id'] );
		$user     = get_userdata( (int) $enrollment['user_id'] );

		self::render_summary( $enrollment, $term, $location, $user );
		self::render_price_breakdown( $enrollment, $term );
		self::render_notes( $enrollment );
		self::render_email_history( $enrollment_id );

		echo '<p><a href="' . esc_url( Roster_Page::url( (int) $enrollment['term_id'] ) ) . '">' . esc_html__( 'Back to roster', 'ruben-dance' ) . '</a></p>';
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
			$phone       = get_user_meta( $user->ID, \RubenDance\Services\Registration_Service::META_PHONE, true );

			if ( is_string( $phone ) && '' !== $phone ) {
				$holder_html .= ' — ' . esc_html( $phone );
			}

			$holder_html .= ' &mdash; <a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $user->ID ) ) . '">' . esc_html__( 'View customer profile', 'ruben-dance' ) . '</a>';
			$holder_html .= ' <span class="description">(' . esc_html__( 'full customer detail screen coming in a later milestone', 'ruben-dance' ) . ')</span>';

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
	 * breakdown").
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
		echo '<p class="description">' . esc_html__( 'The price is auditable via the discount breakdown above; editing it is not available in this milestone.', 'ruben-dance' ) . '</p>';
	}

	/**
	 * Customer note + internal admin note.
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
	 * `wp_rd_email_log`"). The table may be empty until M13 wires up real
	 * sends (see `Repositories\Email_Log_Repository::for_enrollment()`);
	 * that is rendered as an explicit empty state, not hidden.
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
}
