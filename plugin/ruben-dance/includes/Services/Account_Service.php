<?php
/**
 * Read-side orchestration for the `[rd_account]` page: "My enrollments" and
 * "My schedule" (spec F5, F6).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Repositories\Lesson_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Account_Service.
 *
 * Kept WordPress-agnostic the same way `Enrollment_Service` is: both database
 * touchpoints are injected callables (see `create_default()` for the real
 * wiring), so the ownership guarantee is unit-testable with plain PHPUnit and
 * fakes (spec §5: "Ownership enforcement ... checked in the repository
 * layer, not just the controller"). Every public method here takes exactly
 * one `$user_id` and nothing else identifying a row — no enrollment/term ID
 * ever comes in from outside — so there is no parameter a tampered request
 * could use to widen the result set; the real call site (`Front\Account_Page`)
 * always passes `get_current_user_id()`.
 */
class Account_Service {

	/**
	 * Every enrollment for a user, any status: function( int $user_id ): array<int, array<string, mixed>>.
	 *
	 * @var callable
	 */
	private $enrollments_for_user;

	/**
	 * Active (non-cancelled) enrollments for a user: function( int $user_id ): array<int, array<string, mixed>>.
	 *
	 * @var callable
	 */
	private $active_enrollments_for_user;

	/**
	 * Upcoming lessons for a set of term IDs: function( int[] $term_ids, string $from_date ): array<int, array<string, mixed>>.
	 *
	 * @var callable
	 */
	private $upcoming_lessons_for_terms;

	/**
	 * Constructor.
	 *
	 * @param callable $enrollments_for_user        function( int $user_id ): array.
	 * @param callable $active_enrollments_for_user function( int $user_id ): array.
	 * @param callable $upcoming_lessons_for_terms  function( int[] $term_ids, string $from_date ): array.
	 */
	public function __construct(
		callable $enrollments_for_user,
		callable $active_enrollments_for_user,
		callable $upcoming_lessons_for_terms
	) {
		$this->enrollments_for_user        = $enrollments_for_user;
		$this->active_enrollments_for_user = $active_enrollments_for_user;
		$this->upcoming_lessons_for_terms  = $upcoming_lessons_for_terms;
	}

	/**
	 * Wire the service to the real repositories.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		$enrollments = new Enrollment_Repository();
		$lessons     = new Lesson_Repository();

		return new self(
			static function ( int $user_id ) use ( $enrollments ): array {
				return $enrollments->for_user( $user_id );
			},
			static function ( int $user_id ) use ( $enrollments ): array {
				return $enrollments->active_for_user( $user_id );
			},
			static function ( array $term_ids, string $from_date ) use ( $lessons ): array {
				return $lessons->upcoming_for_terms( $term_ids, $from_date );
			}
		);
	}

	/**
	 * "My enrollments" (spec F5): every enrollment belonging to `$user_id`,
	 * across all statuses (a cancelled enrollment still needs to show the
	 * "cancelled" badge, per the acceptance criteria).
	 *
	 * @param int $user_id WP user ID — always `get_current_user_id()` at the real call site.
	 * @return array<int, array<string, mixed>>
	 */
	public function enrollments_for( int $user_id ): array {
		return ( $this->enrollments_for_user )( $user_id );
	}

	/**
	 * "My schedule" (spec F6): upcoming lessons of `$user_id`'s active
	 * (non-cancelled) enrollments, including cancelled/moved lesson dates —
	 * an enrollment the customer cancelled drops its term out of the
	 * schedule entirely, but a lesson the *admin* cancelled within a term
	 * the customer is still actively enrolled in still shows, marked.
	 *
	 * @param int    $user_id WP user ID — always `get_current_user_id()` at the real call site.
	 * @param string $today   `Y-m-d` date lessons must be on or after.
	 * @return array<int, array<string, mixed>>
	 */
	public function schedule_for( int $user_id, string $today ): array {
		$active = ( $this->active_enrollments_for_user )( $user_id );

		$term_ids = array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['term_id'], $active ) ) );

		if ( array() === $term_ids ) {
			return array();
		}

		return ( $this->upcoming_lessons_for_terms )( $term_ids, $today );
	}
}
