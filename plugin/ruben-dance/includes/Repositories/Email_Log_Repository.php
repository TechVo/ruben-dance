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
}
