<?php
/**
 * WordPress-agnostic schema upgrade decision logic.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Schema_Upgrader.
 *
 * Pure PHP: every WordPress touchpoint (reading/writing the stored version,
 * running `dbDelta()`) is injected as a callable, so the upgrade-or-skip
 * decision is unit-testable without loading WordPress. `Schema` wires this
 * up with the real `get_option()`/`update_option()`/`dbDelta()` calls.
 */
class Schema_Upgrader {

	/**
	 * Schema version this upgrader brings the site to.
	 *
	 * @var string
	 */
	private string $target_version;

	/**
	 * Reads the currently stored schema version, or null if none is stored.
	 *
	 * @var callable
	 */
	private $read_version;

	/**
	 * Persists the schema version after a successful upgrade.
	 *
	 * @var callable
	 */
	private $write_version;

	/**
	 * Runs the given `CREATE TABLE` statements (e.g. via `dbDelta()`).
	 *
	 * @var callable
	 */
	private $run_delta;

	/**
	 * Returns the `CREATE TABLE` statements to run.
	 *
	 * @var callable
	 */
	private $table_sql;

	/**
	 * Constructor.
	 *
	 * @param string   $target_version Schema version this upgrader targets.
	 * @param callable $read_version   function(): ?string.
	 * @param callable $write_version  function( string $version ): void.
	 * @param callable $run_delta      function( string[] $statements ): void.
	 * @param callable $table_sql      function(): string[].
	 */
	public function __construct(
		string $target_version,
		callable $read_version,
		callable $write_version,
		callable $run_delta,
		callable $table_sql
	) {
		$this->target_version = $target_version;
		$this->read_version   = $read_version;
		$this->write_version  = $write_version;
		$this->run_delta      = $run_delta;
		$this->table_sql      = $table_sql;
	}

	/**
	 * Apply the schema unconditionally (used on activation).
	 */
	public function apply(): void {
		( $this->run_delta )( ( $this->table_sql )() );
		( $this->write_version )( $this->target_version );
	}

	/**
	 * Apply the schema only if the stored version differs from the target.
	 *
	 * @return bool True if an upgrade ran, false if it was already current.
	 */
	public function upgrade_if_needed(): bool {
		if ( ( $this->read_version )() === $this->target_version ) {
			return false;
		}

		$this->apply();

		return true;
	}
}
