<?php
/**
 * Cross-term enrollments admin screen (F11b): all enrollments, filterable
 * and searchable, independent of any single term.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Roles;
use RubenDance\Services\Enrollment_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Enrollments_Page.
 *
 * Read-only navigation screen (spec F11b): term/status/overdue/over-capacity
 * filters plus a name/email/participant-name search, each combining as a
 * plain SQL `AND` (`Repositories\Enrollment_Repository::search()`), with
 * every row linking into `Enrollment_Detail_Page` where the real state-
 * changing actions live. No `load-{hook}` handler is needed — every request
 * this screen serves is a `GET`, the same "purely cosmetic, no state change"
 * reasoning `Terms_Page::read_filters()` documents.
 */
class Enrollments_Page {

	const SLUG = 'ruben-dance-enrollments';

	const PER_PAGE = 20;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the "Enrollments" submenu page under the Ruben Dance top-level menu.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			Menu::SLUG,
			__( 'Enrollments', 'ruben-dance' ),
			__( 'Enrollments', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * URL to this screen, optionally pre-filtered to one term (e.g. from a
	 * future "see all enrollments for this term across statuses" link).
	 *
	 * @param array<string, mixed> $args Extra query args (filters).
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
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

		$filters = self::read_filters();
		$paged   = self::read_paged();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Enrollments', 'ruben-dance' ) . '</h1> ';
		echo '<a href="' . esc_url( Manual_Enrollment_Page::url() ) . '" class="page-title-action">' . esc_html__( 'New Manual Enrollment', 'ruben-dance' ) . '</a>';
		echo '<hr class="wp-header-end">';

		self::render_filters( $filters );

		$repository = new Enrollment_Repository();
		$total      = $repository->count_search( $filters );
		$rows       = $repository->search( $filters, self::PER_PAGE, $paged );

		$terms_by_id = ( new Course_Term_Repository() )->find_many( self::term_ids( $rows ) );

		list( $users_by_id, $phones_by_id ) = Roster_Page::load_users( $rows );

		$table = new Enrollments_List_Table( $rows, $terms_by_id, $users_by_id, $phones_by_id, current_time( 'Y-m-d' ) );
		$table->prepare_items();
		$table->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
		$table->display();

		echo '</div>';
	}

	/**
	 * Distinct term IDs referenced by a set of enrollment rows, for the
	 * batch term lookup (`Course_Term_Repository::find_many()`).
	 *
	 * @param array<int, array<string, mixed>> $rows Enrollment rows.
	 * @return int[]
	 */
	private static function term_ids( array $rows ): array {
		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['term_id'];
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Read the active list filters from `$_GET`.
	 *
	 * @return array{term_id: int, status: string, overdue: bool, over_capacity: bool, search: string, today: string}
	 */
	private static function read_filters(): array {
		return array(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the list shows, no state change.
			'term_id'       => isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the list shows, no state change.
			'status'        => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the list shows, no state change.
			'overdue'       => isset( $_GET['overdue'] ) && '1' === $_GET['overdue'],
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the list shows, no state change.
			'over_capacity' => isset( $_GET['over_capacity'] ) && '1' === $_GET['over_capacity'],
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the list shows, no state change.
			'search'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'today'         => current_time( 'Y-m-d' ),
		);
	}

	/**
	 * Read the current page number from `$_GET['paged']`.
	 *
	 * @return int
	 */
	private static function read_paged(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which page of rows the list shows, no state change.
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

		return $paged > 0 ? $paged : 1;
	}

	/**
	 * Render the term/status/overdue/over-capacity/search filter form (spec
	 * F11b: "filters (term, status, paid/unpaid, overdue, over capacity) and
	 * search by name/email/participant name").
	 *
	 * @param array{term_id: int, status: string, overdue: bool, over_capacity: bool, search: string, today: string} $filters Active filter values.
	 */
	private static function render_filters( array $filters ): void {
		echo '<form method="get" style="margin:1em 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';

		echo '<select name="term_id">';
		echo '<option value="">' . esc_html__( 'All terms', 'ruben-dance' ) . '</option>';
		foreach ( ( new Course_Term_Repository() )->all_with_filters() as $term ) {
			printf(
				'<option value="%1$d"%2$s>%3$s — %4$s</option>',
				(int) $term['id'],
				selected( $filters['term_id'], (int) $term['id'], false ),
				esc_html( (string) $term['season_label_cs'] ),
				esc_html( get_the_title( (int) $term['course_id'] ) )
			);
		}
		echo '</select> ';

		echo '<select name="status">';
		$statuses = array(
			''                                   => __( 'All statuses', 'ruben-dance' ),
			Enrollment_Service::STATUS_CONFIRMED => __( 'Unpaid', 'ruben-dance' ),
			Enrollment_Service::STATUS_PAID      => __( 'Paid', 'ruben-dance' ),
			Enrollment_Service::STATUS_CANCELLED => __( 'Cancelled', 'ruben-dance' ),
		);
		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $filters['status'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select> ';

		echo '<label><input type="checkbox" name="overdue" value="1"' . checked( $filters['overdue'], true, false ) . '> ' . esc_html__( 'Overdue only', 'ruben-dance' ) . '</label> ';
		echo '<label><input type="checkbox" name="over_capacity" value="1"' . checked( $filters['over_capacity'], true, false ) . '> ' . esc_html__( 'Over capacity only', 'ruben-dance' ) . '</label> ';

		echo '<input type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search name, email or participant…', 'ruben-dance' ) . '"> ';

		submit_button( __( 'Filter', 'ruben-dance' ), '', '', false );
		echo '</form>';
	}
}
