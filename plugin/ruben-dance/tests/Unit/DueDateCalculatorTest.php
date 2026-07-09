<?php
/**
 * Tests for due-date arithmetic.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Due_Date_Calculator;

/**
 * Class DueDateCalculatorTest.
 *
 * `Due_Date_Calculator` is deliberately pure PHP, so `created_at` + N days is
 * exercised here with plain PHPUnit, no WordPress bootstrap needed.
 */
class DueDateCalculatorTest extends TestCase {

	/**
	 * The default N (7) is used when no setting override is given.
	 */
	public function test_default_days_is_seven(): void {
		$calculator = new Due_Date_Calculator();

		$this->assertSame( 7, Due_Date_Calculator::DEFAULT_DAYS );
	}

	/**
	 * Basic arithmetic: created_at + N days.
	 */
	public function test_adds_configured_days(): void {
		$calculator = new Due_Date_Calculator();

		$this->assertSame( '2025-09-08', $calculator->calculate( '2025-09-01 12:34:56', 7 ) );
	}

	/**
	 * A different N produces a different due date.
	 */
	public function test_respects_a_non_default_setting(): void {
		$calculator = new Due_Date_Calculator();

		$this->assertSame( '2025-09-15', $calculator->calculate( '2025-09-01 12:34:56', 14 ) );
	}

	/**
	 * Crossing a month boundary is handled correctly (plain +N days, not
	 * "same day next month").
	 */
	public function test_crosses_month_boundary(): void {
		$calculator = new Due_Date_Calculator();

		$this->assertSame( '2025-10-03', $calculator->calculate( '2025-09-29 00:00:00', 4 ) );
	}

	/**
	 * A zero or negative days value falls back to the default rather than
	 * producing a due date on or before created_at.
	 */
	public function test_non_positive_days_falls_back_to_default(): void {
		$calculator = new Due_Date_Calculator();

		$this->assertSame(
			$calculator->calculate( '2025-09-01 00:00:00', Due_Date_Calculator::DEFAULT_DAYS ),
			$calculator->calculate( '2025-09-01 00:00:00', 0 )
		);

		$this->assertSame(
			$calculator->calculate( '2025-09-01 00:00:00', Due_Date_Calculator::DEFAULT_DAYS ),
			$calculator->calculate( '2025-09-01 00:00:00', -3 )
		);
	}
}
