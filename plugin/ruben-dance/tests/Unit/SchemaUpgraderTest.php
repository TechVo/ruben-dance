<?php
/**
 * Tests for the schema-version upgrade decision logic.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Schema_Upgrader;

/**
 * Class SchemaUpgraderTest.
 *
 * `Schema_Upgrader` is deliberately WordPress-agnostic (every WP touchpoint
 * is an injected callable), so its upgrade-or-skip decision can be exercised
 * here with plain PHPUnit — no `wp-phpunit` bootstrap needed (see M02
 * acceptance criterion: "schema version upgrade path runs dbDelta once").
 */
class SchemaUpgraderTest extends TestCase {

	/**
	 * Build an upgrader wired to simple in-memory fakes.
	 *
	 * @param string|null $stored_version Version already "persisted".
	 * @param int         $delta_calls    Counter incremented on each dbDelta run, by reference.
	 * @param string|null $persisted      Version written by write_version, by reference.
	 * @return Schema_Upgrader
	 */
	private function make_upgrader( ?string $stored_version, int &$delta_calls, ?string &$persisted ): Schema_Upgrader {
		$delta_calls = 0;
		$persisted   = $stored_version;

		return new Schema_Upgrader(
			'1.0.0',
			static function () use ( &$persisted ): ?string {
				return $persisted;
			},
			static function ( string $version ) use ( &$persisted ): void {
				$persisted = $version;
			},
			static function ( array $statements ) use ( &$delta_calls ): void {
				unset( $statements );
				++$delta_calls;
			},
			static function (): array {
				return array( 'CREATE TABLE wp_rd_location (id BIGINT UNSIGNED NOT NULL) charset;' );
			}
		);
	}

	/**
	 * When the stored version differs from the target, dbDelta runs exactly
	 * once and the new version is persisted.
	 */
	public function test_upgrade_runs_once_when_version_differs(): void {
		$delta_calls = 0;
		$persisted   = null;
		$upgrader    = $this->make_upgrader( null, $delta_calls, $persisted );

		$ran = $upgrader->upgrade_if_needed();

		$this->assertTrue( $ran );
		$this->assertSame( 1, $delta_calls );
		$this->assertSame( '1.0.0', $persisted );
	}

	/**
	 * When the stored version already matches the target, the upgrade is a
	 * no-op: dbDelta must not run again (idempotency, per acceptance criteria).
	 */
	public function test_upgrade_is_skipped_when_version_already_current(): void {
		$delta_calls = 0;
		$persisted   = '1.0.0';
		$upgrader    = $this->make_upgrader( '1.0.0', $delta_calls, $persisted );

		$ran = $upgrader->upgrade_if_needed();

		$this->assertFalse( $ran );
		$this->assertSame( 0, $delta_calls );
		$this->assertSame( '1.0.0', $persisted );
	}

	/**
	 * A repeated call after a successful upgrade does not run dbDelta again.
	 */
	public function test_second_call_after_upgrade_does_not_run_delta_again(): void {
		$delta_calls = 0;
		$persisted   = null;
		$upgrader    = $this->make_upgrader( null, $delta_calls, $persisted );

		$upgrader->upgrade_if_needed();
		$ran_again = $upgrader->upgrade_if_needed();

		$this->assertFalse( $ran_again );
		$this->assertSame( 1, $delta_calls );
	}

	/**
	 * `apply()` always runs dbDelta, regardless of the stored version
	 * (used on activation, where re-applying is intentional).
	 */
	public function test_apply_always_runs_delta(): void {
		$delta_calls = 0;
		$persisted   = '1.0.0';
		$upgrader    = $this->make_upgrader( '1.0.0', $delta_calls, $persisted );

		$upgrader->apply();

		$this->assertSame( 1, $delta_calls );
		$this->assertSame( '1.0.0', $persisted );
	}
}
