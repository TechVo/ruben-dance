<?php
/**
 * Repository for the `wp_rd_lesson` table.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Lesson_Repository.
 */
class Lesson_Repository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'rd_lesson';
	}

	/**
	 * All lessons for a term, ordered by date — both the F10 lessons
	 * sub-screen and `Term_Service::regenerate_lessons()` (matching existing
	 * rows by date) read through this.
	 *
	 * @param int $term_id Term ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function all_for_term( int $term_id ): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE term_id = %d ORDER BY lesson_date ASC', $this->table(), $term_id ),
			ARRAY_A
		);

		return null === $rows ? array() : $rows;
	}

	/**
	 * Insert several lesson rows for a term at once (the generator's
	 * `insert` plan entries, which don't carry `term_id` themselves).
	 *
	 * @param int                              $term_id Term ID.
	 * @param array<int, array<string, mixed>> $rows    Rows from `Lesson_Generator::plan()['insert']`.
	 */
	public function insert_many( int $term_id, array $rows ): void {
		foreach ( $rows as $row ) {
			$row['term_id'] = $term_id;
			$this->insert( $row );
		}
	}

	/**
	 * Delete several lesson rows by ID at once (the generator's `delete_ids`
	 * plan entries — stale, never-edited rows whose date fell out of a
	 * shrunk/moved term range).
	 *
	 * @param int[] $ids Lesson IDs.
	 */
	public function delete_many( array $ids ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );

		if ( array() === $ids ) {
			return;
		}

		$wpdb = $this->wpdb;

		// Placeholder count is derived from a server-side array of already-
		// cast integers, never from raw user input, so building the
		// IN (%d, %d, ...) fragment this way stays safe to splice into the
		// query ahead of $wpdb->prepare().
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( $this->table() ), $ids ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Upcoming lessons — any status, including `cancelled`/`moved` — across a
	 * set of terms, from a given date onward. Backs the `[rd_account]` "My
	 * schedule" tab (spec F6: "including any cancelled/moved dates"), which
	 * only ever passes term IDs already ownership-filtered by
	 * `Enrollment_Repository::active_for_user()` one call earlier.
	 *
	 * @param int[]  $term_ids  Term IDs.
	 * @param string $from_date `Y-m-d` — only lessons on or after this date.
	 * @return array<int, array<string, mixed>> Ordered by date/time ascending.
	 */
	public function upcoming_for_terms( array $term_ids, string $from_date ): array {
		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

		if ( array() === $term_ids ) {
			return array();
		}

		$wpdb = $this->wpdb;

		// Placeholder count is derived from a server-side array of already-
		// cast integers, never from raw user input (same reasoning as
		// delete_many() above).
		$placeholders = implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) );

		$sql = "SELECT * FROM %i WHERE term_id IN ({$placeholders}) AND lesson_date >= %s ORDER BY lesson_date ASC, start_time ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params = array_merge( array( $this->table() ), $term_ids, array( $from_date ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return null === $rows ? array() : $rows;
	}

	/**
	 * Lessons for a set of terms within a date range (inclusive), for the
	 * public calendar REST feed (spec F2). Any lesson status is included —
	 * cancelled lessons must still appear (struck-through or hidden is a
	 * front-end display choice, see `Settings::cancelled_lessons_display()`),
	 * not just `scheduled` ones.
	 *
	 * @param int[]  $term_ids Term IDs, already filtered to calendar-visible
	 *                         term statuses by the caller (see
	 *                         `Course_Term_Repository::visible_for_calendar()`).
	 * @param string $from     `Y-m-d`, inclusive.
	 * @param string $to       `Y-m-d`, inclusive.
	 * @return array<int, array<string, mixed>> Ordered by date/time ascending.
	 */
	public function for_terms_between( array $term_ids, string $from, string $to ): array {
		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

		if ( array() === $term_ids ) {
			return array();
		}

		$wpdb = $this->wpdb;

		// Placeholder count is derived from a server-side array of already-
		// cast integers, never from raw user input (same reasoning as
		// delete_many() above).
		$placeholders = implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) );

		$sql = "SELECT * FROM %i WHERE term_id IN ({$placeholders}) AND lesson_date >= %s AND lesson_date <= %s ORDER BY lesson_date ASC, start_time ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params = array_merge( array( $this->table() ), $term_ids, array( $from, $to ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return null === $rows ? array() : $rows;
	}
}
