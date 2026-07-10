<?php
/**
 * Builds the placeholder value set an enrollment-related email needs
 * (E2/E3/E4/E6/E7), in the recipient's language.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Emails;

use RubenDance\Lang;
use RubenDance\Services\Term_Service;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Enrollment_Email_Data.
 *
 * One shared "enrollment row + term row + account holder => placeholder
 * array" mapping, so E2/E3/E4/E6/E7 can never disagree about what
 * `{course}` or `{price}` means. Weekday/date/price formatting is done here
 * with per-language literals rather than through `__()`/`wp_date()`, for the
 * same reason `Email_Templates::default_template()` documents: the email
 * must come out in the *recipient's* language regardless of which locale the
 * triggering request (often a Czech admin screen) happens to be running in.
 */
class Enrollment_Email_Data {

	/**
	 * Placeholder values for one enrollment email.
	 *
	 * @param array<string, mixed>      $enrollment Enrollment row.
	 * @param array<string, mixed>|null $term       Term row, or null when the term no longer exists.
	 * @param \WP_User                  $user       Account holder.
	 * @param string                    $lang       Recipient language, `Lang::CS`/`Lang::EN`.
	 * @return array<string, string>
	 */
	public static function placeholders( array $enrollment, ?array $term, \WP_User $user, string $lang ): array {
		$participant = trim( (string) ( $enrollment['participant_name'] ?? '' ) );

		$bank_account = Settings::bank_account();

		if ( '' === $bank_account ) {
			$bank_account = Lang::EN === $lang ? '(to be confirmed)' : '(bude upřesněno)';
		}

		// The discount breakdown rides along with the amount (the M08 stub
		// did the same) so a discounted price is never presented as
		// unexplained — spec §3.2: "keeps the price auditable".
		$price         = self::format_price( (string) ( $enrollment['price'] ?? '0' ), $lang );
		$discount_note = trim( (string) ( $enrollment['discount_note'] ?? '' ) );

		if ( '' !== $discount_note ) {
			$price .= ' (' . $discount_note . ')';
		}

		return array(
			'first_name'      => (string) $user->first_name,
			'course'          => self::course_title( $term, $lang ),
			'participant'     => '' !== $participant ? $participant : (string) $user->display_name,
			'term_schedule'   => self::term_schedule( $term, $lang ),
			'price'           => $price,
			'account_number'  => $bank_account,
			'variable_symbol' => (string) ( $enrollment['variable_symbol'] ?? '' ),
			'due_date'        => self::format_date( (string) ( $enrollment['due_date'] ?? '' ), $lang ),
		);
	}

	/**
	 * The course title in the recipient's language, resolved through
	 * Polylang the same way the public catalog does (`Lang::resolve_post()`
	 * falls back to the canonical Czech post when no translation exists).
	 *
	 * @param array<string, mixed>|null $term Term row, or null.
	 * @param string                    $lang Recipient language.
	 * @return string
	 */
	public static function course_title( ?array $term, string $lang ): string {
		if ( null === $term ) {
			return '';
		}

		$course_id = Lang::create_default()->resolve_post( (int) $term['course_id'], $lang );

		return (string) get_the_title( $course_id );
	}

	/**
	 * The `{term_schedule}` value: season label (language-picked `_cs`/`_en`
	 * column) plus weekday + time for a course, or date + time for a
	 * workshop.
	 *
	 * @param array<string, mixed>|null $term Term row, or null.
	 * @param string                    $lang Recipient language.
	 * @return string
	 */
	public static function term_schedule( ?array $term, string $lang ): string {
		if ( null === $term ) {
			return '';
		}

		$season = Lang::create_default()->pick( $term, 'season_label', $lang );
		$time   = self::short_time( (string) $term['start_time'] ) . '–' . self::short_time( (string) $term['end_time'] );

		if ( Term_Service::TYPE_WORKSHOP === (string) $term['type'] ) {
			return trim( $season . ', ' . self::format_date( (string) $term['date_from'], $lang ) . ' ' . $time );
		}

		$weekday = self::weekday_label( (int) $term['weekday'], $lang );

		return trim( $season . ', ' . $weekday . ' ' . $time );
	}

	/**
	 * A price amount with currency, formatted per language (`1 500 Kč` vs
	 * `1,500 CZK` conventions, always two decimals).
	 *
	 * @param string $amount Decimal amount as stored (`1500.00`).
	 * @param string $lang   Recipient language.
	 * @return string
	 */
	public static function format_price( string $amount, string $lang ): string {
		if ( Lang::EN === $lang ) {
			return number_format( (float) $amount, 2, '.', ',' ) . ' CZK';
		}

		return number_format( (float) $amount, 2, ',', ' ' ) . ' Kč';
	}

	/**
	 * A `Y-m-d` date formatted per language: Czech numeric (`6. 10. 2026`),
	 * English with the month name (`6 Oct 2026`).
	 *
	 * @param string $ymd  `Y-m-d` date string.
	 * @param string $lang Recipient language.
	 * @return string
	 */
	public static function format_date( string $ymd, string $lang ): string {
		$timestamp = strtotime( $ymd );

		if ( false === $timestamp ) {
			return $ymd;
		}

		// gmdate() month names are always English, which is exactly right for
		// the EN format; the CS format is purely numeric so needs no names.
		return Lang::EN === $lang ? gmdate( 'j M Y', $timestamp ) : gmdate( 'j. n. Y', $timestamp );
	}

	/**
	 * ISO weekday number to its name in the recipient's language. Literal
	 * per-language maps, not `__()` — see the class doc comment.
	 *
	 * @param int    $weekday ISO weekday (1=Mon…7=Sun).
	 * @param string $lang    Recipient language.
	 * @return string
	 */
	public static function weekday_label( int $weekday, string $lang ): string {
		$labels = Lang::EN === $lang
			? array(
				1 => 'Monday',
				'Tuesday',
				'Wednesday',
				'Thursday',
				'Friday',
				'Saturday',
				'Sunday',
			)
			: array(
				1 => 'pondělí',
				'úterý',
				'středa',
				'čtvrtek',
				'pátek',
				'sobota',
				'neděle',
			);

		return $labels[ $weekday ] ?? '';
	}

	/**
	 * The stored locale of a WP user, defaulting to Czech when unset (the
	 * same fallback every M07+ flow uses).
	 *
	 * @param int $user_id WP user ID.
	 * @return string `Lang::CS` or `Lang::EN`.
	 */
	public static function user_lang( int $user_id ): string {
		$locale = (string) get_user_meta( $user_id, \RubenDance\Services\Registration_Service::META_LOCALE, true );

		return Lang::EN === $locale ? Lang::EN : Lang::CS;
	}

	/**
	 * Trim a `TIME` column's `HH:MM:SS` down to `HH:MM` (mirrors
	 * `Admin\Terms_List_Table::format_time()`, duplicated here so the Emails
	 * namespace never depends on an admin list table).
	 *
	 * @param string $time Raw `HH:MM:SS` (or already-short `HH:MM`) value.
	 * @return string
	 */
	private static function short_time( string $time ): string {
		return 8 === strlen( $time ) ? substr( $time, 0, 5 ) : $time;
	}
}
