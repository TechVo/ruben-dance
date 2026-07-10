<?php
/**
 * Repository for the `wp_rd_course_term` table.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Course_Term_Repository.
 */
class Course_Term_Repository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'rd_course_term';
	}

	/**
	 * Count course terms referencing a given location.
	 *
	 * Used by `Location_Service` for the delete-vs-deactivate referential
	 * integrity check: `wp_rd_course_term.location_id` has no FK constraint
	 * (see Schema), so the application is the only thing enforcing it.
	 *
	 * @param int $location_id Location ID.
	 * @return int
	 */
	public function count_for_location( int $location_id ): int {
		$wpdb = $this->wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE location_id = %d', $this->table(), $location_id )
		);
	}

	/**
	 * Terms for the admin list screen, optionally filtered by season/status/
	 * location (F9: "list (filter by season/status/location)").
	 *
	 * @param array{status?: string, location_id?: int, season_label_cs?: string} $filters Optional filter values; empty/absent means "no filter" on that column.
	 * @return array<int, array<string, mixed>>
	 */
	public function all_with_filters( array $filters = array() ): array {
		$wpdb = $this->wpdb;

		$where  = array();
		$params = array( $this->table() );

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}

		if ( ! empty( $filters['location_id'] ) ) {
			$where[]  = 'location_id = %d';
			$params[] = (int) $filters['location_id'];
		}

		if ( ! empty( $filters['season_label_cs'] ) ) {
			$where[]  = 'season_label_cs = %s';
			$params[] = (string) $filters['season_label_cs'];
		}

		$sql = 'SELECT * FROM %i';
		if ( array() !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY date_from DESC, id DESC';

		// Custom plugin table: no object-cache group exists, direct prepared
		// query is the standard approach (see Repository::find()). The WHERE
		// clause above is built from a fixed set of literal fragments, never
		// from user input, so it is safe to splice into the query string
		// ahead of $wpdb->prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return null === $rows ? array() : $rows;
	}

	/**
	 * Find a term by its course + season label. Used by `wp rd seed` to
	 * insert fixture terms idempotently (a course can have several terms
	 * across seasons, so the pair together is the natural key).
	 *
	 * @param int    $course_id       Course post ID.
	 * @param string $season_label_cs Czech season label.
	 * @return array<string, mixed>|null
	 */
	public function find_by_course_and_season( int $course_id, string $season_label_cs ): ?array {
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE course_id = %d AND season_label_cs = %s',
				$this->table(),
				$course_id,
				$season_label_cs
			),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Open terms for a set of (Czech/canonical) course IDs, optionally
	 * filtered by location/weekday, for the public catalog (spec F1). "Open"
	 * here means `status = 'open'` only — closed/draft/cancelled terms never
	 * appear in the catalog (F3: "Visitor clicks 'Enroll' on an *open*
	 * term").
	 *
	 * @param int[]                                   $course_ids Course post IDs to include.
	 * @param array{location_id?: int, weekday?: int} $filters    Optional filter values; empty/absent means "no filter" on that column.
	 * @return array<int, array<string, mixed>>
	 */
	public function open_for_courses( array $course_ids, array $filters = array() ): array {
		$course_ids = array_values( array_unique( array_map( 'intval', $course_ids ) ) );

		if ( array() === $course_ids ) {
			return array();
		}

		$wpdb = $this->wpdb;

		// Placeholder count is derived from a server-side array of already-
		// cast integers, never from raw user input, so building the
		// IN (%d, %d, ...) fragment this way stays safe to splice into the
		// query ahead of $wpdb->prepare() (same reasoning as
		// Lesson_Repository::delete_many()).
		$placeholders = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );

		$where  = array( 'status = %s', "course_id IN ({$placeholders})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params = array_merge( array( $this->table(), 'open' ), $course_ids );

		if ( ! empty( $filters['location_id'] ) ) {
			$where[]  = 'location_id = %d';
			$params[] = (int) $filters['location_id'];
		}

		if ( ! empty( $filters['weekday'] ) ) {
			$where[]  = 'weekday = %d';
			$params[] = (int) $filters['weekday'];
		}

		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY course_id ASC, date_from ASC';

		// Custom plugin table: no object-cache group exists, direct prepared
		// query is the standard approach (see Repository::find()). The WHERE
		// clause above is built from a fixed set of literal fragments, never
		// from user input.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return null === $rows ? array() : $rows;
	}

	/**
	 * Distinct `season_label_cs` values in use, for the admin list's season
	 * filter dropdown.
	 *
	 * @return string[]
	 */
	public function distinct_seasons(): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT DISTINCT season_label_cs FROM %i ORDER BY season_label_cs ASC', $this->table() )
		);

		return null === $rows ? array() : $rows;
	}
}
