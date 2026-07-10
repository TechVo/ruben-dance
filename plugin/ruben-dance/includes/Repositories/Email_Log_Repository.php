<?php
/**
 * Repository for the `wp_rd_email_log` table.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Email_Log_Repository.
 */
class Email_Log_Repository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'rd_email_log';
	}

	/**
	 * Email history for one enrollment, most-recent first — the roster's
	 * enrollment detail screen (spec F11a: "email history from
	 * `wp_rd_email_log`"). The table may be empty until M13 wires up real
	 * sends; an empty array is the expected, valid result, not an error.
	 *
	 * @param int $enrollment_id Enrollment ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_enrollment( int $enrollment_id ): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE enrollment_id = %d ORDER BY sent_at DESC, id DESC', $this->table(), $enrollment_id ),
			ARRAY_A
		);

		return null === $rows ? array() : $rows;
	}

	/**
	 * One page of log rows, most recent first, optionally filtered by a
	 * free-text search across recipient/subject/type — the M13 email-log
	 * admin screen ("a simple log screen with search").
	 *
	 * @param string $search   Search text; `''` means no filter.
	 * @param int    $per_page Rows per page.
	 * @param int    $paged    1-based page number.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $search, int $per_page, int $paged ): array {
		$wpdb = $this->wpdb;

		list( $where, $params ) = $this->search_where( $search );

		$sql = 'SELECT * FROM %i' . $where . ' ORDER BY sent_at DESC, id DESC LIMIT %d OFFSET %d';

		$offset = max( 0, ( max( 1, $paged ) - 1 ) * $per_page );
		$params = array_merge( array( $this->table() ), $params, array( $per_page, $offset ) );

		// Custom plugin table: no object-cache group exists, direct prepared
		// query is the standard approach (see Repository::find()). The WHERE
		// clause is one of two literal fragments built above, never user input.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return null === $rows ? array() : $rows;
	}

	/**
	 * Total row count matching `search()`'s filter, for pagination.
	 *
	 * @param string $search Search text; `''` means no filter.
	 * @return int
	 */
	public function count_search( string $search ): int {
		$wpdb = $this->wpdb;

		list( $where, $params ) = $this->search_where( $search );

		$sql    = 'SELECT COUNT(*) FROM %i' . $where;
		$params = array_merge( array( $this->table() ), $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Shared WHERE-fragment/params builder for `search()`/`count_search()`
	 * (the same split `Enrollment_Repository::search_where()` uses).
	 *
	 * @param string $search Search text.
	 * @return array{0: string, 1: string[]}
	 */
	private function search_where( string $search ): array {
		$search = trim( $search );

		if ( '' === $search ) {
			return array( '', array() );
		}

		$like = '%' . $this->wpdb->esc_like( $search ) . '%';

		return array(
			' WHERE (recipient LIKE %s OR subject LIKE %s OR type LIKE %s)',
			array( $like, $like, $like ),
		);
	}
}
