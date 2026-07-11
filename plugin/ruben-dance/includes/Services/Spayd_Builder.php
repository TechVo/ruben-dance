<?php
/**
 * Pure Czech QR-platba "SPAYD" string builder.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Spayd_Builder.
 *
 * Deliberately pure PHP with zero WordPress touchpoints, the same way
 * `Due_Date_Calculator`/`Variable_Symbol_Generator` are (milestone M14
 * acceptance criteria explicitly calls for unit tests of "the SPAYD string
 * for normal case, diacritics in course name, price with halere, VS length").
 * Builds the "Short Payment Descriptor" (SPD) string a Czech banking app
 * decodes from a QR platba code:
 * `SPD*1.0*ACC:<IBAN>*AM:<amount>*CC:CZK*X-VS:<vs>*MSG:<message>` (spec §4.5).
 *
 * Deliberately does *not* validate the IBAN's checksum itself — that is
 * `Iban_Validator`'s job, called once by `Settings::validate()` when the
 * value is saved; by the time a string reaches here the IBAN is assumed
 * already-valid, and this class only normalizes its on-the-wire form.
 */
class Spayd_Builder {

	/**
	 * Maximum `MSG` field length. The SPD 1.0 specification itself
	 * recommends keeping the whole record well under the ~300-byte practical
	 * ceiling most banking-app QR readers tolerate; 60 characters is the
	 * limit the reference qr-platba.cz implementation and most Czech banking
	 * apps use for the message field specifically, so this stays inside every
	 * app's tested limit rather than merely the theoretical maximum.
	 *
	 * @var int
	 */
	const MAX_MESSAGE_LENGTH = 60;

	/**
	 * Maximum `X-VS` (variable symbol) length: a Czech bank variable symbol
	 * is at most 10 digits (spec §3.2, matches `Variable_Symbol_Generator`).
	 *
	 * @var int
	 */
	const MAX_VS_LENGTH = 10;

	/**
	 * Characters that are structurally significant in the SPD grammar
	 * (`*` separates fields, `+` separates an IBAN from a BIC in `ACC`) and
	 * so must never appear inside a field's own value — stripped from `MSG`
	 * rather than escaped, since SPD has no escaping mechanism.
	 *
	 * @var string[]
	 */
	const RESERVED_CHARACTERS = array( '*', '+' );

	/**
	 * Build the SPAYD string.
	 *
	 * @param string $iban            IBAN, with or without spaces/lower-case letters (normalized here).
	 * @param string $amount          Decimal amount as stored (e.g. `1500`, `1500.5`, `1500.50`).
	 * @param string $variable_symbol Variable symbol; non-digit characters are dropped, the result truncated to `MAX_VS_LENGTH` digits.
	 * @param string $message         Free-text message (e.g. course name); transliterated to ASCII, sanitized, and truncated to `MAX_MESSAGE_LENGTH`.
	 * @return string
	 */
	public static function build( string $iban, string $amount, string $variable_symbol, string $message ): string {
		return sprintf(
			'SPD*1.0*ACC:%s*AM:%s*CC:CZK*X-VS:%s*MSG:%s',
			self::normalize_iban( $iban ),
			self::format_amount( $amount ),
			self::format_variable_symbol( $variable_symbol ),
			self::sanitize_message( $message )
		);
	}

	/**
	 * Strip spaces and upper-case the IBAN (SPAYD's `ACC` field carries no
	 * spaces, unlike an IBAN's conventional 4-char-grouped display form).
	 *
	 * @param string $iban Raw IBAN.
	 * @return string
	 */
	public static function normalize_iban( string $iban ): string {
		return strtoupper( preg_replace( '/\s+/', '', $iban ) ?? $iban );
	}

	/**
	 * Format an amount as SPAYD's `AM` field expects: a decimal point (never
	 * a comma), no thousands separator, exactly two decimal places — so a
	 * price with halere (e.g. `1500.5`) comes out as `1500.50`, never
	 * truncated or rounded away.
	 *
	 * @param string $amount Decimal amount as stored.
	 * @return string
	 */
	public static function format_amount( string $amount ): string {
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Keep only digits (a variable symbol is never signed/decimal, and
	 * leading zeros must survive — this never round-trips through `(int)`),
	 * truncated to `MAX_VS_LENGTH`.
	 *
	 * @param string $variable_symbol Raw variable symbol.
	 * @return string
	 */
	public static function format_variable_symbol( string $variable_symbol ): string {
		$digits = preg_replace( '/\D+/', '', $variable_symbol ) ?? '';

		return substr( $digits, 0, self::MAX_VS_LENGTH );
	}

	/**
	 * Transliterate `$message` to plain ASCII, strip the SPD grammar's
	 * reserved characters and any other remaining non-ASCII byte, then
	 * truncate to `MAX_MESSAGE_LENGTH`.
	 *
	 * @param string $message Raw message (e.g. a course title, possibly with Czech diacritics).
	 * @return string
	 */
	public static function sanitize_message( string $message ): string {
		$transliterated = strtr( $message, self::transliteration_map() );

		// Drop the SPD-reserved characters, then anything still outside
		// printable ASCII (0x20-0x7E) — a deliberately silent fallback for
		// any character the map above doesn't cover, rather than emitting
		// mangled multi-byte remnants into the QR payload.
		$ascii_only = preg_replace( '/[^\x20-\x7E]/', '', str_replace( self::RESERVED_CHARACTERS, '', $transliterated ) ) ?? '';

		return substr( trim( $ascii_only ), 0, self::MAX_MESSAGE_LENGTH );
	}

	/**
	 * Diacritic => plain-ASCII replacement map. Czech first (the primary
	 * need — course names are Czech by default), then the common Western
	 * European Latin-1 diacritics a course/partner name might also contain.
	 * A fixed, explicit map rather than `iconv(...,'ASCII//TRANSLIT',...)`:
	 * `iconv`'s transliteration table depends on the host's locale data and
	 * is not guaranteed identical across environments, which would make this
	 * class's unit tests non-deterministic.
	 *
	 * @return array<string, string>
	 */
	private static function transliteration_map(): array {
		return array(
			'á' => 'a',
			'Á' => 'A',
			'č' => 'c',
			'Č' => 'C',
			'ď' => 'd',
			'Ď' => 'D',
			'é' => 'e',
			'É' => 'E',
			'ě' => 'e',
			'Ě' => 'E',
			'í' => 'i',
			'Í' => 'I',
			'ň' => 'n',
			'Ň' => 'N',
			'ó' => 'o',
			'Ó' => 'O',
			'ř' => 'r',
			'Ř' => 'R',
			'š' => 's',
			'Š' => 'S',
			'ť' => 't',
			'Ť' => 'T',
			'ú' => 'u',
			'Ú' => 'U',
			'ů' => 'u',
			'Ů' => 'U',
			'ý' => 'y',
			'Ý' => 'Y',
			'ž' => 'z',
			'Ž' => 'Z',
			'à' => 'a',
			'À' => 'A',
			'â' => 'a',
			'Â' => 'A',
			'ä' => 'a',
			'Ä' => 'A',
			'ã' => 'a',
			'Ã' => 'A',
			'å' => 'a',
			'Å' => 'A',
			'ç' => 'c',
			'Ç' => 'C',
			'è' => 'e',
			'È' => 'E',
			'ê' => 'e',
			'Ê' => 'E',
			'ë' => 'e',
			'Ë' => 'E',
			'î' => 'i',
			'Î' => 'I',
			'ï' => 'i',
			'Ï' => 'I',
			'ì' => 'i',
			'Ì' => 'I',
			'ñ' => 'n',
			'Ñ' => 'N',
			'ô' => 'o',
			'Ô' => 'O',
			'ö' => 'o',
			'Ö' => 'O',
			'õ' => 'o',
			'Õ' => 'O',
			'ò' => 'o',
			'Ò' => 'O',
			'ù' => 'u',
			'Ù' => 'U',
			'û' => 'u',
			'Û' => 'U',
			'ü' => 'u',
			'Ü' => 'U',
			'ÿ' => 'y',
			'Ÿ' => 'Y',
			'ß' => 'ss',
			'ł' => 'l',
			'Ł' => 'L',
			'ń' => 'n',
			'Ń' => 'N',
			'ś' => 's',
			'Ś' => 'S',
			'ź' => 'z',
			'Ź' => 'Z',
			'ż' => 'z',
			'Ż' => 'Z',
			'ą' => 'a',
			'Ą' => 'A',
			'ę' => 'e',
			'Ę' => 'E',
		);
	}
}
