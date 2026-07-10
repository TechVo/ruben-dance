<?php
/**
 * Tests for the [rd_account] page's ownership guarantee: every method takes
 * a single user_id and only ever returns rows belonging to that user.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Account_Service;

/**
 * Class AccountServiceTest.
 *
 * `Account_Service` is deliberately WordPress-agnostic (every database
 * touchpoint is an injected callable, mirroring `Enrollment_Service`), so
 * the milestone's ownership acceptance criterion ("Customer sees exactly
 * their own enrollments ... verify by URL/id tampering") is exercised here
 * with plain PHPUnit and in-memory fakes standing in for two different
 * customers' data — the same substitute for a real wp-phpunit/DB
 * integration test the rest of this codebase already uses.
 */
class AccountServiceTest extends TestCase {

	/**
	 * A small in-memory "database": enrollments (two customers, one of them
	 * with a cancelled enrollment) and their terms' lessons.
	 *
	 * @return Account_Service
	 */
	private function make_service(): Account_Service {
		$enrollments = array(
			1 => array(
				'id'      => 1,
				'user_id' => 5,
				'term_id' => 10,
				'status'  => 'confirmed',
			),
			2 => array(
				'id'      => 2,
				'user_id' => 5,
				'term_id' => 11,
				'status'  => 'cancelled',
			),
			3 => array(
				'id'      => 3,
				'user_id' => 6,
				'term_id' => 12,
				'status'  => 'paid',
			),
		);

		$lessons_by_term = array(
			10 => array( array( 'id' => 100, 'term_id' => 10, 'lesson_date' => '2099-01-01' ) ),
			11 => array( array( 'id' => 101, 'term_id' => 11, 'lesson_date' => '2099-01-01' ) ),
			12 => array( array( 'id' => 102, 'term_id' => 12, 'lesson_date' => '2099-01-01' ) ),
		);

		return new Account_Service(
			static function ( int $user_id ) use ( $enrollments ): array {
				return array_values( array_filter( $enrollments, static fn( array $row ): bool => $row['user_id'] === $user_id ) );
			},
			static function ( int $user_id ) use ( $enrollments ): array {
				return array_values(
					array_filter(
						$enrollments,
						static fn( array $row ): bool => $row['user_id'] === $user_id && 'cancelled' !== $row['status']
					)
				);
			},
			static function ( array $term_ids, string $from_date ) use ( $lessons_by_term ): array {
				unset( $from_date );

				$out = array();

				foreach ( $term_ids as $term_id ) {
					foreach ( $lessons_by_term[ $term_id ] ?? array() as $lesson ) {
						$out[] = $lesson;
					}
				}

				return $out;
			}
		);
	}

	/**
	 * A customer only ever sees their own enrollments.
	 */
	public function test_enrollments_for_only_returns_the_given_users_rows(): void {
		$service = $this->make_service();

		$rows = $service->enrollments_for( 5 );

		$this->assertCount( 2, $rows );

		foreach ( $rows as $row ) {
			$this->assertSame( 5, $row['user_id'] );
		}
	}

	/**
	 * Two different customers' enrollment sets are disjoint — the core
	 * "tamper with an id and see someone else's data" defence, proven at
	 * this layer rather than via SQL against a real DB.
	 */
	public function test_enrollments_for_a_different_user_returns_a_disjoint_set(): void {
		$service = $this->make_service();

		$user5_ids = array_column( $service->enrollments_for( 5 ), 'id' );
		$user6_ids = array_column( $service->enrollments_for( 6 ), 'id' );

		$this->assertSame( array(), array_intersect( $user5_ids, $user6_ids ) );
		$this->assertSame( array( 3 ), $user6_ids );
	}

	/**
	 * The schedule only includes terms from *active* (non-cancelled)
	 * enrollments (spec F6: "active courses") — a cancelled enrollment's
	 * term must not surface any lessons.
	 */
	public function test_schedule_for_excludes_cancelled_enrollments_terms(): void {
		$service = $this->make_service();

		$schedule = $service->schedule_for( 5, '2000-01-01' );

		$this->assertCount( 1, $schedule );
		$this->assertSame( 10, $schedule[0]['term_id'] );
	}

	/**
	 * A user with no active enrollments gets an empty schedule, not every
	 * lesson in the system.
	 */
	public function test_schedule_for_a_user_with_no_active_enrollments_is_empty(): void {
		$service = $this->make_service();

		$this->assertSame( array(), $service->schedule_for( 999, '2000-01-01' ) );
	}

	/**
	 * A schedule never leaks another customer's term into the result.
	 */
	public function test_schedule_for_never_includes_another_users_term(): void {
		$service = $this->make_service();

		$schedule = $service->schedule_for( 5, '2000-01-01' );

		foreach ( $schedule as $lesson ) {
			$this->assertNotSame( 12, $lesson['term_id'] ); // Term 12 belongs to user 6 only.
		}
	}
}
