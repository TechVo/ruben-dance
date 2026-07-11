<?php
/**
 * Tests for IBAN mod-97 checksum validation.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Iban_Validator;

/**
 * Class IbanValidatorTest.
 *
 * `Iban_Validator` is deliberately pure PHP (mirroring `Spayd_Builder`), so
 * the ISO 7064 mod-97-10 checksum `Settings::validate()` relies on (spec F16:
 * "IBAN plugin setting (with basic checksum validation)") is exercised with
 * plain PHPUnit. Every IBAN below is either a well-known published example
 * (Czech, German, UK) or that same example with a digit flipped.
 */
class IbanValidatorTest extends TestCase {

	/**
	 * A real, checksum-correct Czech IBAN passes.
	 */
	public function test_accepts_valid_czech_iban(): void {
		$this->assertTrue( Iban_Validator::is_valid( 'CZ6508000000192000145399' ) );
	}

	/**
	 * The same IBAN in its conventional space-grouped display form also
	 * passes — spaces are cosmetic, not part of the checksum input.
	 */
	public function test_accepts_iban_with_spaces(): void {
		$this->assertTrue( Iban_Validator::is_valid( 'CZ65 0800 0000 1920 0014 5399' ) );
	}

	/**
	 * Lower-case letters are accepted the same as upper-case.
	 */
	public function test_accepts_lower_case_iban(): void {
		$this->assertTrue( Iban_Validator::is_valid( 'cz6508000000192000145399' ) );
	}

	/**
	 * Other countries' checksum-correct IBANs pass too — the algorithm is
	 * not Czech-specific.
	 */
	public function test_accepts_valid_german_and_uk_ibans(): void {
		$this->assertTrue( Iban_Validator::is_valid( 'DE89370400440532013000' ) );
		$this->assertTrue( Iban_Validator::is_valid( 'GB29NWBK60161331926819' ) );
	}

	/**
	 * A single flipped digit breaks the checksum.
	 */
	public function test_rejects_iban_with_a_single_wrong_digit(): void {
		$this->assertFalse( Iban_Validator::is_valid( 'CZ6508000000192000145398' ) );
	}

	/**
	 * Transposed digits (a common typo) also break the checksum.
	 */
	public function test_rejects_iban_with_transposed_digits(): void {
		$this->assertFalse( Iban_Validator::is_valid( 'CZ6508000000192000154399' ) );
	}

	/**
	 * Garbage input is rejected outright, before the checksum is even computed.
	 */
	public function test_rejects_non_iban_garbage(): void {
		$this->assertFalse( Iban_Validator::is_valid( 'not an iban' ) );
		$this->assertFalse( Iban_Validator::is_valid( '' ) );
	}

	/**
	 * A string too short to be any real IBAN is rejected.
	 */
	public function test_rejects_too_short_string(): void {
		$this->assertFalse( Iban_Validator::is_valid( 'CZ650800' ) );
	}

	/**
	 * A domestic Czech account number (not an IBAN at all) is rejected —
	 * this is exactly the mistake the checksum guards an admin against.
	 */
	public function test_rejects_domestic_account_number(): void {
		$this->assertFalse( Iban_Validator::is_valid( '19-2000145399/0800' ) );
	}

	/**
	 * `normalize()` strips spaces and upper-cases, without validating.
	 */
	public function test_normalize_strips_spaces_and_upper_cases(): void {
		$this->assertSame( 'CZ6508000000192000145399', Iban_Validator::normalize( 'cz65 0800 0000 1920 0014 5399' ) );
	}
}
