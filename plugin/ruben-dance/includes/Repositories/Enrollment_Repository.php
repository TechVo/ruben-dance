<?php
/**
 * Repository for the `wp_rd_enrollment` table.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Enrollment_Repository.
 */
class Enrollment_Repository extends Repository {

	/**
	 * Enrollment statuses that count toward a term's capacity. `cancelled`
	 * intentionally excluded (spec §3.2: "active (non-cancelled)
	 * enrollments").
	 *
	 * @var string[]
	 */
	const ACTIVE_STATUSES = array( 'confirmed', 'paid' );

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'rd_enrollment';
	}

	/**
	 * Insert a new row, raising a typed exception when the failure is the
	 * `term_id`/`user_id`/`participant_name` unique key rejecting a
	 * duplicate (spec §3.3), so callers can tell that case apart from any
	 * other insert failure.
	 *
	 * @param array<string, mixed> $data Column => value pairs.
	 * @return int Insert ID.
	 * @throws Duplicate_Key_Exception When the unique key rejects the row.
	 */
	public function insert_unique( array $data ): int {
		$wpdb = $this->wpdb;

		$result = $wpdb->insert( $this->table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $result ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
				throw new Duplicate_Key_Exception( 'Duplicate enrollment: term_id/user_id/participant_name already exists.' );
			}

			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an enrollment row, raising `Duplicate_Key_Exception` when the
	 * `(term_id, user_id, participant_name)` unique key would be violated by
	 * this update — the only write this class makes that can change
	 * `term_id` is `Services\Enrollment_Service::move_to_term()` (spec F11b:
	 * "duplicate rule still enforced"), and without this override
	 * `Repository::update()`'s `$wpdb->update()` would just report a silent
	 * `false`, indistinguishable from any other failure. Mirrors
	 * `insert_unique()`'s reasoning for inserts.
	 *
	 * @param int                  $id   Row ID.
	 * @param array<string, mixed> $data Column => value pairs.
	 * @return bool
	 * @throws Duplicate_Key_Exception When the unique key rejects the update.
	 */
	public function update( int $id, array $data ): bool {
		$wpdb = $this->wpdb;

		$result = $wpdb->update( $this->table(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $result ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
				throw new Duplicate_Key_Exception( 'Duplicate enrollment: term_id/user_id/participant_name already exists.' );
			}

			return false;
		}

		return true;
	}

	/**
	 * Count active (non-cancelled) enrollments for a term, for the
	 * `over_capacity` decision (spec §3.2: "active (non-cancelled)
	 * enrollments ... capacity").
	 *
	 * @param int $term_id Term ID.
	 * @return int
	 */
	public function count_active_for_term( int $term_id ): int {
		$wpdb = $this->wpdb;

		// The two `%s` placeholders are intentionally hardcoded to match
		// self::ACTIVE_STATUSES's fixed 2 entries, rather than built up
		// dynamically, so `$wpdb->prepare()`'s placeholder count stays
		// statically verifiable. Custom plugin table: no object-cache group
		// exists, direct prepared query is the standard approach (see
		// Repository::find()).
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE term_id = %d AND status IN (%s, %s)',
				$this->table(),
				$term_id,
				self::ACTIVE_STATUSES[0],
				self::ACTIVE_STATUSES[1]
			)
		);
	}

	/**
	 * Every enrollment belonging to one customer, across every status and
	 * term — the `[rd_account]` "My enrollments" tab (spec F5). Ownership is
	 * enforced *here*, in the repository layer, not merely by the caller
	 * (spec §5: "checked in the repository layer, not just the controller"):
	 * the `user_id = %d` clause is baked into the SQL itself, so nothing
	 * above this method — however a request was tampered with — could ever
	 * widen the result set to another customer's rows.
	 *
	 * @param int $user_id WP user ID; the real call site (`Services\Account_Service`)
	 *                      only ever passes `get_current_user_id()`, never a
	 *                      value read from the request.
	 * @return array<int, array<string, mixed>> Ordered by created_at DESC (most recent first).
	 */
	public function for_user( int $user_id ): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY created_at DESC', $this->table(), $user_id ),
			ARRAY_A
		);

		return null === $rows ? array() : $rows;
	}

	/**
	 * A single enrollment, but only when it belongs to `$user_id` — the
	 * QR-payment-code endpoint's ownership check (spec F16: "must refuse ...
	 * foreign-enrollment requests"). Ownership is baked into the SQL itself,
	 * the same reasoning `for_user()` documents: whatever `enrollment_id` a
	 * tampered request supplies, a row belonging to a different customer can
	 * never be returned.
	 *
	 * @param int $id      Enrollment ID.
	 * @param int $user_id WP user ID; the real call site (`Front\Qr_Code_Ajax`)
	 *                      only ever passes `get_current_user_id()`.
	 * @return array<string, mixed>|null
	 */
	public function find_for_user( int $id, int $user_id ): ?array {
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND user_id = %d', $this->table(), $id, $user_id ),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * A customer's active (non-cancelled) enrollments — the term set the
	 * `[rd_account]` "My schedule" tab (spec F6: "active courses") pulls
	 * upcoming lessons for. Same ownership reasoning as `for_user()`.
	 *
	 * @param int $user_id WP user ID; see `for_user()`.
	 * @return array<int, array<string, mixed>>
	 */
	public function active_for_user( int $user_id ): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND status IN (%s, %s)',
				$this->table(),
				$user_id,
				self::ACTIVE_STATUSES[0],
				self::ACTIVE_STATUSES[1]
			),
			ARRAY_A
		);

		return null === $rows ? array() : $rows;
	}

	/**
	 * Every enrollment for a term, every status included — the admin term
	 * roster (spec F11a: "one row per enrollment", including the cancelled
	 * badge, so cancelled rows must stay visible here even though they are
	 * excluded from the header sums by `Services\Roster_Stats::compute()`).
	 * Ordered oldest-first so the roster reads in the order people signed up.
	 *
	 * @param int $term_id Term ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_term( int $term_id ): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE term_id = %d ORDER BY created_at ASC, id ASC', $this->table(), $term_id ),
			ARRAY_A
		);

		return null === $rows ? array() : $rows;
	}

	/**
	 * Filtered/searched page of enrollments across every term — the F11b
	 * cross-term list. Joined against `wp_users` (LEFT, not INNER: an
	 * enrollment whose account was since deleted must still show up rather
	 * than silently vanishing from the list) so `search` can match the
	 * account holder's name/email/login as well as the participant name
	 * (spec F11b: "search by name/email/participant name").
	 *
	 * @param array{term_id?: int, status?: string, overdue?: bool, over_capacity?: bool, search?: string, today?: string} $filters Filter values; empty/absent means "no filter" on that column.
	 * @param int                                                                                                          $per_page Rows per page.
	 * @param int                                                                                                          $paged    1-based page number.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( array $filters, int $per_page, int $paged ): array {
		$wpdb = $this->wpdb;

		list( $where, $params ) = $this->search_where( $filters );

		$sql = 'SELECT e.* FROM %i e LEFT JOIN %i u ON u.ID = e.user_id';

		if ( array() !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY e.created_at DESC, e.id DESC LIMIT %d OFFSET %d';

		$offset = max( 0, ( max( 1, $paged ) - 1 ) * $per_page );
		$params = array_merge( array( $this->table(), $wpdb->users ), $params, array( $per_page, $offset ) );

		// Custom plugin table joined against wp_users: no object-cache group
		// exists, direct prepared query is the standard approach (see
		// Repository::find()). The WHERE clause above is built from a fixed
		// set of literal fragments, never from user input.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return null === $rows ? array() : $rows;
	}

	/**
	 * Total row count matching `search()`'s filters, for `WP_List_Table`
	 * pagination.
	 *
	 * @param array{term_id?: int, status?: string, overdue?: bool, over_capacity?: bool, search?: string, today?: string} $filters Same shape as `search()`.
	 * @return int
	 */
	public function count_search( array $filters ): int {
		$wpdb = $this->wpdb;

		list( $where, $params ) = $this->search_where( $filters );

		$sql = 'SELECT COUNT(*) FROM %i e LEFT JOIN %i u ON u.ID = e.user_id';

		if ( array() !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$params = array_merge( array( $this->table(), $wpdb->users ), $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Shared WHERE-fragment/params builder for `search()`/`count_search()`,
	 * the same split `Course_Term_Repository::all_with_filters()` uses.
	 *
	 * @param array{term_id?: int, status?: string, overdue?: bool, over_capacity?: bool, search?: string, today?: string} $filters Filter values.
	 * @return array{0: string[], 1: array<int, string|int>}
	 */
	private function search_where( array $filters ): array {
		$where  = array();
		$params = array();

		if ( ! empty( $filters['term_id'] ) ) {
			$where[]  = 'e.term_id = %d';
			$params[] = (int) $filters['term_id'];
		}

		$status = (string) ( $filters['status'] ?? '' );

		if ( in_array( $status, array( 'confirmed', 'paid', 'cancelled' ), true ) ) {
			$where[]  = 'e.status = %s';
			$params[] = $status;
		}

		if ( ! empty( $filters['over_capacity'] ) ) {
			$where[] = 'e.over_capacity = 1';
		}

		if ( ! empty( $filters['overdue'] ) ) {
			// "Overdue" only ever means unpaid-past-due (spec §3.2), so this
			// bakes status = confirmed into the fragment itself rather than
			// requiring the caller to also pass status=confirmed.
			$where[]  = "e.status = 'confirmed' AND e.due_date < %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = (string) ( $filters['today'] ?? gmdate( 'Y-m-d' ) );
		}

		$search = trim( (string) ( $filters['search'] ?? '' ) );

		if ( '' !== $search ) {
			$like     = '%' . $this->wpdb->esc_like( $search ) . '%';
			$where[]  = '(e.participant_name LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		return array( $where, $params );
	}
}
