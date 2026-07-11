<?php
/**
 * Pure IBAN checksum validation (mod-97, ISO 7064).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Iban_Validator.
 *
 * Deliberately pure PHP with zero WordPress touchpoints, the same way
 * `Due_Date_Calculator`/`Variable_Symbol_Generator` are: `Settings::validate()`
 * needs a checksum check it can unit-test without a WordPress bootstrap
 * (milestone M14 task: "IBAN plugin setting (with basic checksum
 * validation)"). Implements the standard ISO 7064 mod-97-10 check every real
 * IBAN satisfies, using string-wise long division so no `bcmath`/`gmp`
 * extension is required for the arbitrarily large numeric string an IBAN's
 * letters-to-digits expansion produces.
 */
class Iban_Validator {

	/**
	 * Minimum total IBAN length (ISO 13616's shortest issued IBAN, Norway, is
	 * 15 characters).
	 *
	 * @var int
	 */
	const MIN_LENGTH = 15;

	/**
	 * Maximum total IBAN length (ISO 13616's longest issued IBAN, e.g.
	 * Malta/San Marino, is 31-34 characters; 34 is the hard ISO ceiling).
	 *
	 * @var int
	 */
	const MAX_LENGTH = 34;

	/**
	 * Whether `$iban` is a structurally valid, checksum-correct IBAN.
	 * Whitespace is tolerated (IBANs are conventionally displayed in 4-char
	 * groups) but must be stripped by the caller for any further use — this
	 * method only reports validity, it does not normalize.
	 *
	 * @param string $iban Candidate IBAN, with or without spaces.
	 * @return bool
	 */
	public static function is_valid( string $iban ): bool {
		$normalized = self::normalize( $iban );

		$length = strlen( $normalized );

		if ( $length < self::MIN_LENGTH || $length > self::MAX_LENGTH ) {
			return false;
		}

		// Two-letter ISO 3166-1 country code, two check digits, then up to 30
		// alphanumeric BBAN characters.
		if ( 1 !== preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $normalized ) ) {
			return false;
		}

		return 1 === self::mod97( $normalized );
	}

	/**
	 * Strip whitespace and upper-case, the canonical machine form of an IBAN
	 * (spec-adjacent: SPAYD's `ACC:` field takes the same normalized form —
	 * see `Spayd_Builder`).
	 *
	 * @param string $iban Raw input, with or without spaces.
	 * @return string
	 */
	public static function normalize( string $iban ): string {
		return strtoupper( preg_replace( '/\s+/', '', $iban ) ?? $iban );
	}

	/**
	 * ISO 7064 mod-97-10 check: move the first 4 characters (country code +
	 * check digits) to the end, expand every letter to its two-digit
	 * A=10..Z=35 value, then reduce the resulting digit string mod 97 one
	 * digit at a time (equivalent to, but without needing, big-integer
	 * arithmetic). A valid IBAN's remainder is always exactly 1.
	 *
	 * @param string $normalized Already-normalized (no spaces, upper-case) candidate.
	 * @return int Remainder, 1 for a valid checksum.
	 */
	private static function mod97( string $normalized ): int {
		$rearranged = substr( $normalized, 4 ) . substr( $normalized, 0, 4 );

		$remainder = 0;

		foreach ( str_split( $rearranged ) as $char ) {
			if ( ctype_alpha( $char ) ) {
				foreach ( str_split( (string) ( ord( $char ) - 55 ) ) as $digit ) {
					$remainder = ( $remainder * 10 + (int) $digit ) % 97;
				}
				continue;
			}

			$remainder = ( $remainder * 10 + (int) $char ) % 97;
		}

		return $remainder;
	}
}
