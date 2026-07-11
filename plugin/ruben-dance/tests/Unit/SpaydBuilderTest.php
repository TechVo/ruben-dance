<?php
/**
 * Tests for the SPAYD (QR-platba) string builder.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Spayd_Builder;

/**
 * Class SpaydBuilderTest.
 *
 * `Spayd_Builder` is deliberately pure PHP (mirroring `Due_Date_Calculator`/
 * `Variable_Symbol_Generator`), so the exact SPAYD grammar (spec F16/§4.5:
 * `SPD*1.0*ACC:<IBAN>*AM:<amount>*CC:CZK*X-VS:<vs>*MSG:<course>`) is exercised
 * with plain PHPUnit — no WordPress bootstrap needed. Covers the milestone's
 * explicitly-named acceptance criterion cases: normal case, diacritics in the
 * course name, a price with halere, and variable-symbol length.
 */
class SpaydBuilderTest extends TestCase {

	/**
	 * The normal case: every field formatted exactly per the spec grammar.
	 */
	public function test_builds_the_normal_case(): void {
		$spayd = Spayd_Builder::build( 'CZ6508000000192000145399', '1500.00', '2025000042', 'Salsa kurz' );

		$this->assertSame(
			'SPD*1.0*ACC:CZ6508000000192000145399*AM:1500.00*CC:CZK*X-VS:2025000042*MSG:Salsa kurz',
			$spayd
		);
	}

	/**
	 * Czech diacritics in the course name are transliterated to plain ASCII
	 * — SPAYD readers are not guaranteed to handle non-ASCII bytes.
	 */
	public function test_transliterates_diacritics_in_course_name(): void {
		$spayd = Spayd_Builder::build( 'CZ6508000000192000145399', '1500.00', '2025000042', 'Tancování pro pokročilé — Čtvrtek' );

		$this->assertStringContainsString( 'MSG:Tancovani pro pokrocile', $spayd );
		$this->assertStringNotContainsString( 'á', $spayd );
		$this->assertStringNotContainsString( 'ě', $spayd );
		$this->assertStringNotContainsString( 'Č', $spayd );
		// The whole string must be pure ASCII — no leftover multi-byte bytes.
		$this->assertMatchesRegularExpression( '/^[\x20-\x7E]+$/', $spayd );
	}

	/**
	 * A price with halere (non-zero cents) keeps two decimal places, with a
	 * dot, never a comma or a rounded-away fraction.
	 */
	public function test_formats_price_with_halere(): void {
		$spayd = Spayd_Builder::build( 'CZ6508000000192000145399', '1349.5', '2025000042', 'Kurz' );

		$this->assertStringContainsString( 'AM:1349.50*', $spayd );
	}

	/**
	 * A whole-number price still gets exactly two decimal places.
	 */
	public function test_formats_whole_number_price_with_two_decimals(): void {
		$this->assertStringContainsString( 'AM:2000.00*', Spayd_Builder::build( 'CZ6508000000192000145399', '2000', '2025000042', 'Kurz' ) );
	}

	/**
	 * The variable symbol is carried through unchanged when it is already
	 * exactly the 10-digit SPAYD/Czech-bank maximum, leading zeros intact.
	 */
	public function test_keeps_ten_digit_variable_symbol_with_leading_zeros(): void {
		$spayd = Spayd_Builder::build( 'CZ6508000000192000145399', '500.00', '0025000007', 'Kurz' );

		$this->assertStringContainsString( 'X-VS:0025000007*', $spayd );
	}

	/**
	 * A variable symbol longer than the 10-digit maximum is truncated, never
	 * rejected outright (the caller — `Variable_Symbol_Generator` — never
	 * actually produces one this long, but the builder must not emit an
	 * invalid, over-length X-VS field regardless).
	 */
	public function test_truncates_variable_symbol_beyond_ten_digits(): void {
		$spayd = Spayd_Builder::build( 'CZ6508000000192000145399', '500.00', '12345678901234', 'Kurz' );

		$this->assertStringContainsString( 'X-VS:1234567890*', $spayd );
	}

	/**
	 * Non-digit characters (e.g. a stray space) are stripped from the
	 * variable symbol.
	 */
	public function test_strips_non_digits_from_variable_symbol(): void {
		$spayd = Spayd_Builder::build( 'CZ6508000000192000145399', '500.00', '2025 000042', 'Kurz' );

		$this->assertStringContainsString( 'X-VS:2025000042*', $spayd );
	}

	/**
	 * The IBAN is normalized: spaces stripped, upper-cased.
	 */
	public function test_normalizes_iban_spacing_and_case(): void {
		$spayd = Spayd_Builder::build( 'cz65 0800 0000 1920 0014 5399', '500.00', '2025000042', 'Kurz' );

		$this->assertStringContainsString( 'ACC:CZ6508000000192000145399*', $spayd );
	}

	/**
	 * A message longer than the SPAYD `MSG` limit is truncated, never left
	 * to overflow into an invalid record.
	 */
	public function test_truncates_overlong_message(): void {
		$long_message = str_repeat( 'Kurz tance ', 10 ); // 110 chars.

		$spayd = Spayd_Builder::build( 'CZ6508000000192000145399', '500.00', '2025000042', $long_message );

		preg_match( '/MSG:(.*)$/', $spayd, $matches );

		$this->assertLessThanOrEqual( Spayd_Builder::MAX_MESSAGE_LENGTH, strlen( $matches[1] ) );
	}

	/**
	 * The SPD grammar's own reserved characters (`*`, `+`) are stripped from
	 * the message so they can never be mistaken for field delimiters.
	 */
	public function test_strips_reserved_characters_from_message(): void {
		$spayd = Spayd_Builder::build( 'CZ6508000000192000145399', '500.00', '2025000042', 'Kurz + Workshop * Praha' );

		preg_match( '/MSG:(.*)$/', $spayd, $matches );

		$this->assertStringNotContainsString( '*', $matches[1] );
		$this->assertStringNotContainsString( '+', $matches[1] );
	}
}
