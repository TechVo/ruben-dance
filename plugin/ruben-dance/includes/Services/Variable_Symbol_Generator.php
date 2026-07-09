<?php
/**
 * Pure `variable_symbol` formatting logic for an enrollment.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Variable_Symbol_Generator.
 *
 * Deliberately pure PHP with zero WordPress touchpoints, the same way
 * `Lesson_Generator` is. Builds the bank variable symbol customers quote on
 * their transfer (spec §3.2: `variable_symbol` — "unique, generated (e.g.
 * `{year}{enrollment_id}`)"), a plain string concatenation of a 4-digit year
 * and a 6-digit, zero-padded enrollment ID (10 digits total — the maximum a
 * Czech bank variable symbol accepts, per §3.2's "≤ 10 digits").
 *
 * Uniqueness is guaranteed by construction, not by anything this class does:
 * `enrollment_id` is `wp_rd_enrollment.id`, an auto-increment primary key
 * that is already unique across the entire table regardless of year. Two
 * different enrollment IDs zero-padded to the same width always produce two
 * different 6-digit strings, so the resulting 10-digit symbol can never
 * collide — the year prefix exists purely so a human glancing at a bank
 * statement can tell when the enrollment happened, not for uniqueness.
 */
class Variable_Symbol_Generator {

	/**
	 * Digits reserved for the year component.
	 *
	 * @var int
	 */
	const YEAR_DIGITS = 4;

	/**
	 * Digits reserved for the zero-padded enrollment ID component. Combined
	 * with `YEAR_DIGITS`, this adds up to the 10-digit maximum a Czech bank
	 * variable symbol accepts.
	 *
	 * @var int
	 */
	const ID_DIGITS = 6;

	/**
	 * Build the variable symbol for one enrollment.
	 *
	 * @param int $year           Calendar year the enrollment was created in (e.g. 2025).
	 * @param int $enrollment_id  `wp_rd_enrollment.id` — must already exist (assigned by the database on insert).
	 * @return string Exactly `YEAR_DIGITS + ID_DIGITS` (10) digits.
	 * @throws \InvalidArgumentException When `$year` doesn't fit `YEAR_DIGITS` digits, `$enrollment_id` is not positive, or it doesn't fit `ID_DIGITS` digits (see class docblock: this would be the only way uniqueness could theoretically break).
	 */
	public function generate( int $year, int $enrollment_id ): string {
		if ( $year < 0 || strlen( (string) $year ) > self::YEAR_DIGITS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception message, never echoed to a page.
			throw new \InvalidArgumentException( sprintf( 'Year must fit %d digits.', self::YEAR_DIGITS ) );
		}

		if ( $enrollment_id <= 0 ) {
			throw new \InvalidArgumentException( 'Enrollment ID must be a positive integer.' );
		}

		if ( strlen( (string) $enrollment_id ) > self::ID_DIGITS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception message, never echoed to a page.
			throw new \InvalidArgumentException( sprintf( 'Enrollment ID exceeds the %d-digit capacity of a variable symbol.', self::ID_DIGITS ) );
		}

		$year_part = str_pad( (string) $year, self::YEAR_DIGITS, '0', STR_PAD_LEFT );
		$id_part   = str_pad( (string) $enrollment_id, self::ID_DIGITS, '0', STR_PAD_LEFT );

		return $year_part . $id_part;
	}
}
