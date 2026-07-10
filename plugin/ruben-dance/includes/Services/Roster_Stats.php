<?php
/**
 * Pure aggregation logic for the admin term roster's header stats and the
 * per-row "overdue" decision.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Roster_Stats.
 *
 * Deliberately pure PHP with zero WordPress touchpoints, the same way
 * `Term_Presenter`/`Due_Date_Calculator` are: `Admin\Roster_Page` and
 * `Admin\Roster_Ajax` both need the exact same numbers (initial page render
 * and the AJAX response after a mark/unmark toggle must agree), so the
 * aggregation lives here once and is unit-testable with plain PHPUnit,
 * fed nothing but the enrollment rows a repository already returns.
 */
class Roster_Stats {

	/**
	 * Header stats for a term's roster (spec F11a: "paid/total count vs.
	 * capacity, leader/follower/solo breakdown ..., sum collected vs.
	 * expected"). Cancelled enrollments are excluded from every count and
	 * sum here (spec M11 acceptance criteria: "cancelled enrollments
	 * excluded from sums") — they still appear as roster rows (see
	 * `Repositories\Enrollment_Repository::for_term()`), just not in these
	 * numbers.
	 *
	 * @param array<int, array<string, mixed>> $enrollments Every enrollment row for the term (any status).
	 * @param int|null                         $capacity    The term's capacity, or null for unlimited.
	 * @return array{paid: int, total: int, capacity: int|null, solo: int, leader: int, follower: int, collected: float, expected: float}
	 */
	public function compute( array $enrollments, ?int $capacity ): array {
		$paid      = 0;
		$total     = 0;
		$solo      = 0;
		$leader    = 0;
		$follower  = 0;
		$collected = 0.0;
		$expected  = 0.0;

		foreach ( $enrollments as $enrollment ) {
			$status = (string) ( $enrollment['status'] ?? '' );

			if ( 'cancelled' === $status ) {
				continue;
			}

			++$total;

			$price     = (float) ( $enrollment['price'] ?? 0 );
			$expected += $price;

			if ( 'paid' === $status ) {
				++$paid;
				$collected += $price;
			}

			switch ( (string) ( $enrollment['role'] ?? '' ) ) {
				case 'leader':
					++$leader;
					break;

				case 'follower':
					++$follower;
					break;

				default:
					++$solo;
			}
		}

		return array(
			'paid'      => $paid,
			'total'     => $total,
			'capacity'  => $capacity,
			'solo'      => $solo,
			'leader'    => $leader,
			'follower'  => $follower,
			'collected' => $collected,
			'expected'  => $expected,
		);
	}

	/**
	 * Whether one enrollment row should show the "overdue" badge (spec
	 * F11a/§3.2: "overdue = unpaid past `due_date`"). Only a `confirmed`
	 * (unpaid, not cancelled) enrollment can be overdue: `paid` has nothing
	 * left to chase, `cancelled` was never going to be paid.
	 *
	 * @param array{status?: mixed, due_date?: mixed} $enrollment Enrollment row.
	 * @param string                                  $today      `Y-m-d` date to compare `due_date` against.
	 * @return bool
	 */
	public function is_overdue( array $enrollment, string $today ): bool {
		if ( 'confirmed' !== (string) ( $enrollment['status'] ?? '' ) ) {
			return false;
		}

		$due_date = (string) ( $enrollment['due_date'] ?? '' );

		return '' !== $due_date && $due_date < $today;
	}
}
