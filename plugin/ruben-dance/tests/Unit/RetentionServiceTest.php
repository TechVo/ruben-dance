<?php
/**
 * Tests for the retention job's candidate-selection/dry-run decision logic.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use RubenDance\Services\Retention_Service;

/**
 * Class RetentionServiceTest.
 *
 * `Retention_Service` is deliberately WordPress-agnostic (every database/
 * clock/settings touchpoint is an injected callable, mirroring
 * `Enrollment_Service`/`Registration_Service`), so the "who is an inactive
 * candidate, what gets purged, and does dry-run really change nothing" logic
 * — the part spec §6.1's acceptance criteria actually cares about — is
 * exercised here with plain PHPUnit and in-memory fakes, no WordPress/
 * database needed.
 */
class RetentionServiceTest extends TestCase {

	/**
	 * Build a service wired to simple in-memory fakes.
	 *
	 * @param array<string, mixed> $options {
	 *     @type int[]  $customer_ids     Value `find_customer_ids()` returns.
	 *     @type int[]  $active_ids       Value `active_user_ids_since()` returns.
	 *     @type int    $email_log_count  Value the count/delete email-log callables return.
	 *     @type int    $enrollments_count Value the count/delete enrollment callables return.
	 *     @type int    $retention_years  Value `retention_years()` returns.
	 *     @type string $now              Value `now()` returns.
	 * }
	 * @return array{0: Retention_Service, 1: ArrayObject} [service, calls].
	 */
	private function make_service( array $options = array() ): array {
		$customer_ids      = $options['customer_ids'] ?? array();
		$active_ids        = $options['active_ids'] ?? array();
		$email_log_count   = $options['email_log_count'] ?? 0;
		$enrollments_count = $options['enrollments_count'] ?? 0;
		$retention_years   = $options['retention_years'] ?? 3;
		$now               = $options['now'] ?? '2026-07-09 12:00:00';

		$calls = new ArrayObject(
			array(
				'anonymized'                 => array(),
				'active_since_cutoffs'       => array(),
				'count_old_email_log'        => array(),
				'delete_old_email_log'       => array(),
				'count_old_cancelled_unpaid' => array(),
				'delete_old_cancelled_unpaid' => array(),
				'logged'                     => array(),
			)
		);

		$service = new Retention_Service(
			static function () use ( $customer_ids ): array {
				return $customer_ids;
			},
			static function ( string $cutoff ) use ( $active_ids, $calls ): array {
				$calls['active_since_cutoffs'] = array_merge( $calls['active_since_cutoffs'], array( $cutoff ) );

				return $active_ids;
			},
			static function ( int $user_id ) use ( $calls ): void {
				$calls['anonymized'] = array_merge( $calls['anonymized'], array( $user_id ) );
			},
			static function ( string $cutoff ) use ( $email_log_count, $calls ): int {
				$calls['count_old_email_log'] = array_merge( $calls['count_old_email_log'], array( $cutoff ) );

				return $email_log_count;
			},
			static function ( string $cutoff ) use ( $email_log_count, $calls ): int {
				$calls['delete_old_email_log'] = array_merge( $calls['delete_old_email_log'], array( $cutoff ) );

				return $email_log_count;
			},
			static function ( string $cutoff ) use ( $enrollments_count, $calls ): int {
				$calls['count_old_cancelled_unpaid'] = array_merge( $calls['count_old_cancelled_unpaid'], array( $cutoff ) );

				return $enrollments_count;
			},
			static function ( string $cutoff ) use ( $enrollments_count, $calls ): int {
				$calls['delete_old_cancelled_unpaid'] = array_merge( $calls['delete_old_cancelled_unpaid'], array( $cutoff ) );

				return $enrollments_count;
			},
			static function () use ( $retention_years ): int {
				return $retention_years;
			},
			static function () use ( $now ): string {
				return $now;
			},
			static function ( array $summary ) use ( $calls ): void {
				$calls['logged'] = array_merge( $calls['logged'], array( $summary ) );
			}
		);

		return array( $service, $calls );
	}

	/**
	 * A dry run reports the correct candidates/counts but never anonymizes
	 * or deletes anything (spec acceptance criterion: "dry-run reports
	 * correct candidates").
	 */
	public function test_dry_run_reports_candidates_without_mutating(): void {
		list( $service, $calls ) = $this->make_service(
			array(
				'customer_ids'      => array( 1, 2, 3 ),
				'active_ids'        => array( 2 ),
				'email_log_count'   => 5,
				'enrollments_count' => 2,
			)
		);

		$summary = $service->run( true );

		$this->assertTrue( $summary['dry_run'] );
		$this->assertSame( array( 1, 3 ), $summary['customer_ids'] );
		$this->assertSame( 2, $summary['customers_anonymized'] );
		$this->assertSame( 5, $summary['email_log_purged'] );
		$this->assertSame( 2, $summary['enrollments_purged'] );

		$this->assertSame( array(), $calls['anonymized'] );
		$this->assertSame( array(), $calls['delete_old_email_log'] );
		$this->assertSame( array(), $calls['delete_old_cancelled_unpaid'] );
		$this->assertCount( 1, $calls['count_old_email_log'] );
		$this->assertCount( 1, $calls['count_old_cancelled_unpaid'] );
	}

	/**
	 * A real run anonymizes every candidate and deletes (not just counts)
	 * the two purge sets.
	 */
	public function test_real_run_anonymizes_and_purges(): void {
		list( $service, $calls ) = $this->make_service(
			array(
				'customer_ids'      => array( 1, 2, 3 ),
				'active_ids'        => array( 2 ),
				'email_log_count'   => 5,
				'enrollments_count' => 2,
			)
		);

		$summary = $service->run( false );

		$this->assertFalse( $summary['dry_run'] );
		$this->assertSame( array( 1, 3 ), $calls['anonymized'] );
		$this->assertCount( 1, $calls['delete_old_email_log'] );
		$this->assertCount( 1, $calls['delete_old_cancelled_unpaid'] );
		$this->assertSame( array(), $calls['count_old_email_log'] );
		$this->assertSame( array(), $calls['count_old_cancelled_unpaid'] );
	}

	/**
	 * A customer with a recent non-cancelled enrollment is never a candidate,
	 * even if every other customer is inactive.
	 */
	public function test_active_customers_are_never_candidates(): void {
		list( $service ) = $this->make_service(
			array(
				'customer_ids' => array( 10, 20 ),
				'active_ids'   => array( 10, 20 ),
			)
		);

		$summary = $service->run( true );

		$this->assertSame( array(), $summary['customer_ids'] );
		$this->assertSame( 0, $summary['customers_anonymized'] );
	}

	/**
	 * The inactive-customer cutoff is `now` minus the configured retention
	 * window in years (spec §6.1: "default 3").
	 */
	public function test_inactive_cutoff_uses_configured_retention_years(): void {
		list( $service, $calls ) = $this->make_service(
			array(
				'retention_years' => 3,
				'now'             => '2026-07-09 12:00:00',
			)
		);

		$service->run( true );

		$this->assertSame( '2023-07-09 12:00:00', $calls['active_since_cutoffs'][0] );
	}

	/**
	 * The email-log/cancelled-unpaid purge cutoff is always exactly 1 year
	 * before `now`, regardless of the configured retention window (spec
	 * §6.1: these two rules are fixed at "1 year", independent of the
	 * customer-inactivity setting).
	 */
	public function test_purge_cutoff_is_always_one_year(): void {
		list( $service, $calls ) = $this->make_service(
			array(
				'retention_years' => 5,
				'now'             => '2026-07-09 12:00:00',
			)
		);

		$service->run( true );

		$this->assertSame( array( '2025-07-09 12:00:00' ), $calls['count_old_email_log'] );
		$this->assertSame( array( '2025-07-09 12:00:00' ), $calls['count_old_cancelled_unpaid'] );
	}

	/**
	 * Every call, dry-run or real, is logged (spec §6.1: "every run logged").
	 */
	public function test_every_run_is_logged(): void {
		list( $service, $calls ) = $this->make_service();

		$service->run( true );
		$service->run( false );

		$this->assertCount( 2, $calls['logged'] );
		$this->assertTrue( $calls['logged'][0]['dry_run'] );
		$this->assertFalse( $calls['logged'][1]['dry_run'] );
	}
}
