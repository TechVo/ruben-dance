<?php
/**
 * Repository for the `wp_rd_retention_log` table.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Retention_Log_Repository.
 *
 * One row per `Services\Retention_Service::run()` call, dry-run and real
 * alike (spec §6.1: "every run logged (what, how many)"). `insert()` is
 * inherited from `Repository`; this class only owns the table name and a
 * read-back helper for the WP-CLI command / any future admin screen.
 */
class Retention_Log_Repository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'rd_retention_log';
	}

	/**
	 * The most recent runs, newest first.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function latest( int $limit ): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY run_at DESC, id DESC LIMIT %d', $this->table(), $limit ),
			ARRAY_A
		);

		return null === $rows ? array() : $rows;
	}
}
