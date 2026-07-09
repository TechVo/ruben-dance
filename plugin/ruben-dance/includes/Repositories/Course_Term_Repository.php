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
}
