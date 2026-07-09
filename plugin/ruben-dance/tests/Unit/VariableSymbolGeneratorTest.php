<?php
/**
 * Tests for variable symbol formatting and year rollover.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Variable_Symbol_Generator;

/**
 * Class VariableSymbolGeneratorTest.
 *
 * `Variable_Symbol_Generator` is deliberately pure PHP (mirroring
 * `Lesson_Generator`), so its format (`{year}{enrollment_id}`, zero-padded,
 * ≤ 10 digits) and the year-rollover behaviour the milestone calls out are
 * exercised here with plain PHPUnit, no WordPress bootstrap needed.
 */
class VariableSymbolGeneratorTest extends TestCase {

	/**
	 * The basic format: 4-digit year, enrollment ID zero-padded to 6 digits.
	 */
	public function test_generates_zero_padded_ten_digit_symbol(): void {
		$generator = new Variable_Symbol_Generator();

		$this->assertSame( '2025000042', $generator->generate( 2025, 42 ) );
	}

	/**
	 * A single-digit enrollment ID is padded the same way.
	 */
	public function test_pads_small_enrollment_id(): void {
		$generator = new Variable_Symbol_Generator();

		$this->assertSame( '2025000001', $generator->generate( 2025, 1 ) );
	}

	/**
	 * The largest enrollment ID that fits the 6-digit budget produces exactly 10 digits.
	 */
	public function test_largest_id_still_fits_ten_digits(): void {
		$generator = new Variable_Symbol_Generator();

		$symbol = $generator->generate( 2025, 999999 );

		$this->assertSame( '2025999999', $symbol );
		$this->assertSame( 10, strlen( $symbol ) );
	}

	/**
	 * Year rollover: the same enrollment ID in consecutive years produces
	 * distinct symbols that differ only in the year prefix.
	 */
	public function test_year_rollover_changes_only_the_year_prefix(): void {
		$generator = new Variable_Symbol_Generator();

		$this->assertSame( '2025000100', $generator->generate( 2025, 100 ) );
		$this->assertSame( '2026000100', $generator->generate( 2026, 100 ) );
	}

	/**
	 * Two different enrollment IDs in the same year never collide.
	 */
	public function test_different_ids_in_same_year_never_collide(): void {
		$generator = new Variable_Symbol_Generator();

		$this->assertNotSame( $generator->generate( 2025, 1 ), $generator->generate( 2025, 2 ) );
	}

	/**
	 * A non-positive enrollment ID is rejected.
	 */
	public function test_rejects_non_positive_enrollment_id(): void {
		$generator = new Variable_Symbol_Generator();

		$this->expectException( \InvalidArgumentException::class );

		$generator->generate( 2025, 0 );
	}

	/**
	 * An enrollment ID beyond the 6-digit budget is rejected rather than
	 * silently truncated or growing the symbol past 10 digits, which would
	 * threaten the uniqueness-by-construction guarantee.
	 */
	public function test_rejects_enrollment_id_beyond_capacity(): void {
		$generator = new Variable_Symbol_Generator();

		$this->expectException( \InvalidArgumentException::class );

		$generator->generate( 2025, 1000000 );
	}
}
