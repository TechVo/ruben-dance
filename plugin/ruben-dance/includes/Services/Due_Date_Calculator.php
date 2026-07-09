<?php
/**
 * Pure `due_date` calculation logic for an enrollment.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Due_Date_Calculator.
 *
 * Deliberately pure PHP with zero WordPress touchpoints, the same way
 * `Lesson_Generator` is. Spec §3.2: `due_date` is `created_at` + N days, N
 * configurable (default 7, owned by `Settings`/`Enrollment_Service`, not by
 * this class — it only does the date arithmetic).
 */
class Due_Date_Calculator {

	/**
	 * Default number of days, used when no plugin setting overrides it (spec
	 * §3.2: "N configurable, default 7").
	 *
	 * @var int
	 */
	const DEFAULT_DAYS = 7;

	/**
	 * Compute the due date.
	 *
	 * @param string $created_at `Y-m-d H:i:s` (or any `strtotime()`-parseable) timestamp the enrollment was created at.
	 * @param int    $days       Number of days to add. Falls back to `DEFAULT_DAYS` when not a positive integer, so a misconfigured/blank setting never produces a due date in the past.
	 * @return string `Y-m-d` due date.
	 */
	public function calculate( string $created_at, int $days ): string {
		$days = $days > 0 ? $days : self::DEFAULT_DAYS;

		$created = new \DateTimeImmutable( $created_at, new \DateTimeZone( 'UTC' ) );

		return $created->modify( "+{$days} days" )->format( 'Y-m-d' );
	}
}
