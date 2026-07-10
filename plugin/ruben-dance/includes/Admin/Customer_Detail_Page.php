<?php
/**
 * Customer detail screen (F12): "who is this person calling me".
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Lang;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Roles;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Registration_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Customer_Detail_Page.
 *
 * Reached from `Customers_List_Table`'s "View details" row action and from
 * `Enrollment_Detail_Page`'s "Account holder" link (replacing that page's
 * placeholder link straight to WP core's `user-edit.php`, per the M12
 * milestone note). Not itself a visible submenu entry (null parent, the same
 * `Enrollment_Detail_Page`/`Term_Lessons_Page` pattern). Read-only: contact
 * data, locale, marketing consent and the full enrollment history (spec F12)
 * — no state-changing actions live here; those stay on the roster/enrollment
 * detail screens this page links out to.
 */
class Customer_Detail_Page {

	const SLUG = 'ruben-dance-customer-detail';

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
			null, // A null parent is the documented way to register an admin page hook without adding a sidebar menu entry (see Enrollment_Detail_Page::add_menu()).
			__( 'Customer', 'ruben-dance' ),
			__( 'Customer', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * URL to one customer's detail screen.
	 *
	 * @param int $user_id WP user ID.
	 * @return string
	 */
	public static function url( int $user_id ): string {
		return add_query_arg(
			array(
				'page'    => self::SLUG,
				'user_id' => $user_id,
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
				esc_html__( 'You do not have permission to view customers.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which customer to load, no state change.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$user    = get_userdata( $user_id );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Customer', 'ruben-dance' ) . '</h1>';

		if ( false === $user ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Customer not found.', 'ruben-dance' ) . '</p></div>';
			echo '<p><a href="' . esc_url( Customers_Page::url() ) . '">' . esc_html__( 'Back to customers', 'ruben-dance' ) . '</a></p>';
			echo '</div>';
			return;
		}

		self::render_contact( $user );
		self::render_enrollment_history( $user_id );

		echo '<p><a href="' . esc_url( Customers_Page::url() ) . '">' . esc_html__( 'Back to customers', 'ruben-dance' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Contact data, locale, marketing consent (spec F12: "contact data,
	 * preferred language, marketing-consent status").
	 *
	 * @param \WP_User $user Customer account.
	 */
	private static function render_contact( \WP_User $user ): void {
		echo '<h2>' . esc_html__( 'Contact', 'ruben-dance' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:800px;"><tbody>';

		self::row( __( 'Name', 'ruben-dance' ), esc_html( $user->display_name ) );
		self::row( __( 'Email', 'ruben-dance' ), esc_html( $user->user_email ) );

		$phone = get_user_meta( $user->ID, Registration_Service::META_PHONE, true );
		self::row( __( 'Phone', 'ruben-dance' ), esc_html( is_string( $phone ) && '' !== $phone ? $phone : '—' ) );

		$locale = get_user_meta( $user->ID, Registration_Service::META_LOCALE, true );
		self::row( __( 'Preferred language', 'ruben-dance' ), esc_html( Lang::EN === $locale ? __( 'English', 'ruben-dance' ) : __( 'Czech', 'ruben-dance' ) ) );

		$verified = '1' === get_user_meta( $user->ID, Registration_Service::META_EMAIL_VERIFIED, true );
		self::row( __( 'Email verified', 'ruben-dance' ), esc_html( $verified ? __( 'Yes', 'ruben-dance' ) : __( 'No', 'ruben-dance' ) ) );

		$consent    = '1' === get_user_meta( $user->ID, Registration_Service::META_MARKETING_CONSENT, true );
		$consent_at = get_user_meta( $user->ID, Registration_Service::META_MARKETING_CONSENT_AT, true );

		$consent_html = $consent ? esc_html__( 'Yes', 'ruben-dance' ) : esc_html__( 'No', 'ruben-dance' );

		if ( is_string( $consent_at ) && '' !== $consent_at ) {
			$consent_html .= ' <span class="description">(' . sprintf(
				/* translators: %s: date/time the consent status last changed. */
				esc_html__( 'since %s', 'ruben-dance' ),
				esc_html( mysql2date( 'j M Y H:i', $consent_at ) )
			) . ')</span>';
		}

		self::row( __( 'Marketing consent', 'ruben-dance' ), $consent_html, true );

		echo '</tbody></table>';
	}

	/**
	 * Full enrollment history (spec F12: "full enrollment history (course,
	 * term, participant, price, paid status) with links back to the
	 * rosters"). Uses `Enrollment_Repository::for_user()` — the exact same
	 * ownership-safe lookup the front-end "My enrollments" tab (spec F5)
	 * uses — so this admin view and the customer's own view can never
	 * disagree about which enrollments belong to this account.
	 *
	 * @param int $user_id WP user ID.
	 */
	private static function render_enrollment_history( int $user_id ): void {
		$enrollments = ( new Enrollment_Repository() )->for_user( $user_id );

		echo '<h2>' . esc_html__( 'Enrollment history', 'ruben-dance' ) . '</h2>';

		if ( array() === $enrollments ) {
			echo '<p>' . esc_html__( 'No enrollments yet.', 'ruben-dance' ) . '</p>';
			return;
		}

		$term_ids = array();
		foreach ( $enrollments as $enrollment ) {
			$term_ids[] = (int) $enrollment['term_id'];
		}
		$terms_by_id = ( new Course_Term_Repository() )->find_many( array_values( array_unique( $term_ids ) ) );

		echo '<table class="widefat striped" style="max-width:1000px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Term', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Participant', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Price', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Links', 'ruben-dance' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $enrollments as $enrollment ) {
			$term_id = (int) $enrollment['term_id'];
			$term    = $terms_by_id[ $term_id ] ?? null;

			echo '<tr>';
			echo '<td>' . ( null === $term
				? esc_html__( '(term no longer exists)', 'ruben-dance' )
				: esc_html( (string) $term['season_label_cs'] . ' — ' . get_the_title( (int) $term['course_id'] ) )
			) . '</td>';

			$participant_name = trim( (string) $enrollment['participant_name'] );
			echo '<td>' . esc_html( '' !== $participant_name ? $participant_name : __( '(account holder)', 'ruben-dance' ) ) . '</td>';

			echo '<td>' . esc_html( number_format( (float) $enrollment['price'], 2 ) . ' Kč' ) . '</td>';
			echo '<td>' . esc_html( self::status_label( (string) $enrollment['status'] ) ) . '</td>';

			echo '<td>';
			echo '<a href="' . esc_url( Enrollment_Detail_Page::url( (int) $enrollment['id'] ) ) . '">' . esc_html__( 'Enrollment', 'ruben-dance' ) . '</a>';

			if ( null !== $term ) {
				echo ' | <a href="' . esc_url( Roster_Page::url( $term_id ) ) . '">' . esc_html__( 'Roster', 'ruben-dance' ) . '</a>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
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
				return __( 'Unpaid', 'ruben-dance' );
		}
	}

	/**
	 * One `<tr><th>label</th><td>value</td></tr>` row (mirrors
	 * `Enrollment_Detail_Page::row()`).
	 *
	 * @param string $label        Already-translated label.
	 * @param string $value_html   Value markup — already escaped by the caller.
	 * @param bool   $value_is_raw Set true when `$value_html` legitimately contains markup.
	 */
	private static function row( string $label, string $value_html, bool $value_is_raw = false ): void {
		unset( $value_is_raw ); // Documents intent at call sites; every value above is already individually escaped/composed of esc_*() calls.

		echo '<tr><th scope="row" style="width:220px;">' . esc_html( $label ) . '</th><td>' . $value_html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $value_html is always pre-built from esc_html()/esc_attr() calls at each call site (see method docblock).
	}
}
