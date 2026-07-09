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
}
