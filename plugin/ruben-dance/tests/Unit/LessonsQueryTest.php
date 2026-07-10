<?php
/**
 * Tests for RubenDance\Rest\Lessons_Query.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Rest\Lessons_Query;

/**
 * Class LessonsQueryTest.
 */
class LessonsQueryTest extends TestCase {

	/**
	 * @dataProvider valid_date_provider
	 */
	public function test_is_valid_date_accepts_real_calendar_dates( string $date ): void {
		$this->assertTrue( Lessons_Query::is_valid_date( $date ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function valid_date_provider(): array {
		return array(
			'ordinary date' => array( '2025-09-01' ),
			'leap day'      => array( '2024-02-29' ),
			'year boundary' => array( '2025-12-31' ),
		);
	}

	/**
	 * @dataProvider invalid_date_provider
	 */
	public function test_is_valid_date_rejects_hostile_input( string $date ): void {
		$this->assertFalse( Lessons_Query::is_valid_date( $date ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function invalid_date_provider(): array {
		return array(
			'empty string'          => array( '' ),
			'garbage'                => array( 'not-a-date' ),
			'impossible day'         => array( '2025-02-30' ),
			'non-leap Feb 29'        => array( '2025-02-29' ),
			'wrong separator'        => array( '2025/09/01' ),
			'missing zero padding'   => array( '2025-9-1' ),
			'sql injection attempt'  => array( "2025-09-01' OR '1'='1" ),
			'trailing time'          => array( '2025-09-01 00:00:00' ),
		);
	}

	public function test_is_valid_range_accepts_same_day(): void {
		$this->assertTrue( Lessons_Query::is_valid_range( '2025-09-01', '2025-09-01' ) );
	}

	public function test_is_valid_range_accepts_range_within_limit(): void {
		$this->assertTrue( Lessons_Query::is_valid_range( '2025-01-01', '2025-12-31' ) );
	}

	public function test_is_valid_range_rejects_inverted_range(): void {
		$this->assertFalse( Lessons_Query::is_valid_range( '2025-09-30', '2025-09-01' ) );
	}

	public function test_is_valid_range_rejects_huge_range(): void {
		$this->assertFalse( Lessons_Query::is_valid_range( '2020-01-01', '2030-01-01' ) );
	}

	public function test_is_valid_range_rejects_malformed_from(): void {
		$this->assertFalse( Lessons_Query::is_valid_range( 'garbage', '2025-09-01' ) );
	}

	public function test_is_valid_range_rejects_malformed_to(): void {
		$this->assertFalse( Lessons_Query::is_valid_range( '2025-09-01', 'garbage' ) );
	}

	public function test_is_valid_optional_id_accepts_empty_string(): void {
		$this->assertTrue( Lessons_Query::is_valid_optional_id( '' ) );
	}

	public function test_is_valid_optional_id_accepts_positive_integer_string(): void {
		$this->assertTrue( Lessons_Query::is_valid_optional_id( '42' ) );
	}

	/**
	 * @dataProvider invalid_optional_id_provider
	 */
	public function test_is_valid_optional_id_rejects_hostile_input( string $value ): void {
		$this->assertFalse( Lessons_Query::is_valid_optional_id( $value ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function invalid_optional_id_provider(): array {
		return array(
			'zero'          => array( '0' ),
			'negative'      => array( '-1' ),
			'float'         => array( '1.5' ),
			'non-numeric'   => array( 'abc' ),
			'leading space' => array( ' 1' ),
			'sql injection' => array( '1 OR 1=1' ),
		);
	}

	public function test_is_valid_lang_accepts_cs_and_en(): void {
		$this->assertTrue( Lessons_Query::is_valid_lang( 'cs' ) );
		$this->assertTrue( Lessons_Query::is_valid_lang( 'en' ) );
	}

	/**
	 * @dataProvider invalid_lang_provider
	 */
	public function test_is_valid_lang_rejects_anything_else( string $value ): void {
		$this->assertFalse( Lessons_Query::is_valid_lang( $value ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function invalid_lang_provider(): array {
		return array(
			'empty string' => array( '' ),
			'uppercase'    => array( 'CS' ),
			'unsupported'  => array( 'de' ),
			'garbage'      => array( '<script>' ),
		);
	}
}
