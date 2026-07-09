<?php
/**
 * Base repository shared by every `wp_rd_*` table.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Repository.
 *
 * Minimal CRUD building block: `find()`, `insert()`, `update()`. All SQL
 * goes through `$wpdb->prepare()` (directly or via `$wpdb->insert()`/
 * `update()`, which prepare internally). Concrete repositories only need to
 * declare which table they own.
 */
abstract class Repository {

	/**
	 * WordPress database access object.
	 *
	 * @var \wpdb
	 */
	protected \wpdb $wpdb;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Table name suffix, without the `$wpdb->prefix`, e.g. `rd_location`.
	 *
	 * @return string
	 */
	abstract protected function table_suffix(): string;

	/**
	 * Fully-prefixed table name, e.g. `wp_rd_location`.
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->wpdb->prefix . $this->table_suffix();
	}

	/**
	 * Find a single row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$wpdb = $this->wpdb;

		// Custom plugin table, not a WP core table: no object-cache group exists
		// for it, and a direct prepared query is the standard approach (see
		// WooCommerce/EDD custom order/table repositories for precedent).
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Insert a new row.
	 *
	 * @param array<string, mixed> $data Column => value pairs.
	 * @return int Insert ID (0 on failure).
	 */
	public function insert( array $data ): int {
		$result = $this->wpdb->insert( $this->table(), $data );

		return false === $result ? 0 : (int) $this->wpdb->insert_id;
	}

	/**
	 * Update an existing row by primary key.
	 *
	 * @param int                  $id   Row ID.
	 * @param array<string, mixed> $data Column => value pairs to update.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		$result = $this->wpdb->update( $this->table(), $data, array( 'id' => $id ) );

		return false !== $result;
	}

	/**
	 * Delete a row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$result = $this->wpdb->delete( $this->table(), array( 'id' => $id ) );

		return false !== $result;
	}
}
