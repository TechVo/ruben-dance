<?php
/**
 * Repository for the `wp_rd_location` table.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Location_Repository.
 */
class Location_Repository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'rd_location';
	}

	/**
	 * Every location (active and inactive), for the admin list table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$wpdb = $this->wpdb;

		// Custom plugin table: no object-cache group exists, direct prepared
		// query is the standard approach (see Repository::find()).
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', $this->table() ),
			ARRAY_A
		);

		return null === $rows ? array() : $rows;
	}

	/**
	 * Active locations only. No term-admin UI reads this yet (M03 does not
	 * build the term dropdown), but the milestone requires inactive
	 * locations to be excludable from it once it exists (M05).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function active(): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE is_active = 1 ORDER BY name ASC', $this->table() ),
			ARRAY_A
		);

		return null === $rows ? array() : $rows;
	}

	/**
	 * Find a location by its exact name. Used by `wp rd seed` to insert
	 * fixture locations idempotently.
	 *
	 * @param string $name Location name.
	 * @return array<string, mixed>|null
	 */
	public function find_by_name( string $name ): ?array {
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE name = %s', $this->table(), $name ),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}
}
