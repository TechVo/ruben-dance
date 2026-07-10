<?php
/**
 * Tests for the roster's header-stats aggregation and overdue decision.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Roster_Stats;

/**
 * Class RosterStatsTest.
 *
 * `Roster_Stats` is deliberately WordPress-agnostic (no `current_time()`/
 * `$wpdb` touchpoints), the same way `Term_Presenter` is, so it is exercised
 * here with plain PHPUnit, no WordPress bootstrap needed.
 */
class RosterStatsTest extends TestCase {

	/**
	 * Cancelled enrollments are excluded from every count and sum (spec M11
	 * acceptance criteria: "cancelled enrollments excluded from sums").
	 */
	public function test_compute_excludes_cancelled_from_counts_and_sums(): void {
		$stats = new Roster_Stats();

		$result = $stats->compute(
			array(
				array(
					'status' => 'confirmed',
					'role'   => 'solo',
					'price'  => '2400.00',
				),
				array(
					'status' => 'cancelled',
					'role'   => 'solo',
					'price'  => '2400.00',
				),
			),
			10
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 0, $result['paid'] );
		$this->assertSame( 1, $result['solo'] );
		$this->assertSame( 2400.0, $result['expected'] );
		$this->assertSame( 0.0, $result['collected'] );
	}

	/**
	 * `paid` counts toward both the paid tally and the collected sum;
	 * `confirmed` counts toward `expected` only.
	 */
	public function test_compute_paid_vs_confirmed_sums(): void {
		$stats = new Roster_Stats();

		$result = $stats->compute(
			array(
				array(
					'status' => 'paid',
					'role'   => 'leader',
					'price'  => '2600.00',
				),
				array(
					'status' => 'confirmed',
					'role'   => 'follower',
					'price'  => '2600.00',
				),
			),
			null
		);

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1, $result['paid'] );
		$this->assertSame( 1, $result['leader'] );
		$this->assertSame( 1, $result['follower'] );
		$this->assertSame( 0, $result['solo'] );
		$this->assertSame( 5200.0, $result['expected'] );
		$this->assertSame( 2600.0, $result['collected'] );
		$this->assertNull( $result['capacity'] );
	}

	/**
	 * An unrecognized/empty role bucket falls back to "solo" rather than
	 * being silently dropped from every role count.
	 */
	public function test_compute_unknown_role_counts_as_solo(): void {
		$stats = new Roster_Stats();

		$result = $stats->compute(
			array(
				array(
					'status' => 'confirmed',
					'role'   => '',
					'price'  => '100.00',
				),
			),
			null
		);

		$this->assertSame( 1, $result['solo'] );
	}

	/**
	 * Capacity passes through untouched (this class never compares it to
	 * the counts itself, that's the caller's display job).
	 */
	public function test_compute_passes_capacity_through(): void {
		$stats = new Roster_Stats();

		$result = $stats->compute( array(), 6 );

		$this->assertSame( 6, $result['capacity'] );
		$this->assertSame( 0, $result['total'] );
	}

	/**
	 * Overdue: confirmed (unpaid), due date strictly before today.
	 */
	public function test_is_overdue_true_for_unpaid_past_due(): void {
		$stats = new Roster_Stats();

		$this->assertTrue(
			$stats->is_overdue(
				array(
					'status'   => 'confirmed',
					'due_date' => '2026-01-01',
				),
				'2026-07-10'
			)
		);
	}

	/**
	 * Not overdue: due date is today (the deadline has not yet passed).
	 */
	public function test_is_overdue_false_when_due_today(): void {
		$stats = new Roster_Stats();

		$this->assertFalse(
			$stats->is_overdue(
				array(
					'status'   => 'confirmed',
					'due_date' => '2026-07-10',
				),
				'2026-07-10'
			)
		);
	}

	/**
	 * Not overdue: due date is in the future.
	 */
	public function test_is_overdue_false_when_due_in_future(): void {
		$stats = new Roster_Stats();

		$this->assertFalse(
			$stats->is_overdue(
				array(
					'status'   => 'confirmed',
					'due_date' => '2026-12-31',
				),
				'2026-07-10'
			)
		);
	}

	/**
	 * A paid enrollment is never overdue, no matter how old its due date.
	 */
	public function test_is_overdue_false_when_paid(): void {
		$stats = new Roster_Stats();

		$this->assertFalse(
			$stats->is_overdue(
				array(
					'status'   => 'paid',
					'due_date' => '2020-01-01',
				),
				'2026-07-10'
			)
		);
	}

	/**
	 * A cancelled enrollment is never overdue — there is nothing left to
	 * chase.
	 */
	public function test_is_overdue_false_when_cancelled(): void {
		$stats = new Roster_Stats();

		$this->assertFalse(
			$stats->is_overdue(
				array(
					'status'   => 'cancelled',
					'due_date' => '2020-01-01',
				),
				'2026-07-10'
			)
		);
	}
}
