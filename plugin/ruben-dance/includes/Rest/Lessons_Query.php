<?php
/**
 * Pure validation for `GET /rd/v1/lessons` query parameters.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Rest;

use RubenDance\Lang;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Lessons_Query.
 *
 * Deliberately WordPress-agnostic (no `$wpdb`, no `sanitize_*()`, nothing)
 * the same way `Services\Term_Service`'s own date/time validators are, so the
 * rules a hostile query string most needs to survive — malformed dates, an
 * inverted or absurdly wide range, a non-numeric ID — are unit-testable with
 * plain PHPUnit. `Lessons_Controller` is the only caller; it wires these as
 * `register_rest_route()` `validate_callback`s, which is how WordPress core
 * itself turns a `false` return into a clean `400 rest_invalid_param`
 * response (see `WP_REST_Request::has_valid_params()`) without this class
 * ever needing to know about HTTP.
 */
class Lessons_Query {

	/**
	 * Widest date range a single request may cover. Generous enough for any
	 * legitimate calendar view (a year of month-view paging) while still
	 * rejecting a deliberately huge range aimed at forcing an expensive scan
	 * (spec M10 verification: "huge ranges ... clean 400s").
	 *
	 * @var int
	 */
	const MAX_RANGE_DAYS = 366;

	/**
	 * Whether a string is a real calendar date in `Y-m-d` form (rejects
	 * malformed input and impossible dates like `2025-02-30`). Mirrors
	 * `Services\Term_Service::is_valid_date()`.
	 *
	 * @param string $date Candidate date string.
	 * @return bool
	 */
	public static function is_valid_date( string $date ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );

		return checkdate( $month, $day, $year );
	}

	/**
	 * Whether `$to` is a valid end of a range starting at `$from`: both valid
	 * dates, `$to` on or after `$from`, and the span no wider than
	 * `self::MAX_RANGE_DAYS`.
	 *
	 * @param string $from Range start, `Y-m-d`.
	 * @param string $to   Range end, `Y-m-d`.
	 * @return bool
	 */
	public static function is_valid_range( string $from, string $to ): bool {
		if ( ! self::is_valid_date( $from ) || ! self::is_valid_date( $to ) ) {
			return false;
		}

		$from_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from );
		$to_date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to );

		if ( false === $from_date || false === $to_date || $to_date < $from_date ) {
			return false;
		}

		$days = (int) $to_date->diff( $from_date )->days;

		return $days <= self::MAX_RANGE_DAYS;
	}

	/**
	 * Whether a string is empty (meaning "no filter") or a positive integer
	 * ID, for the `style`/`location` parameters.
	 *
	 * @param string $value Candidate value.
	 * @return bool
	 */
	public static function is_valid_optional_id( string $value ): bool {
		return '' === $value || ( ctype_digit( $value ) && (int) $value > 0 );
	}

	/**
	 * Whether a string is a known language slug, for the `lang` parameter.
	 *
	 * @param string $value Candidate value.
	 * @return bool
	 */
	public static function is_valid_lang( string $value ): bool {
		return in_array( $value, array( Lang::CS, Lang::EN ), true );
	}
}
