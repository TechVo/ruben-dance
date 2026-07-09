<?php
/**
 * Tests for enrollment validation, create orchestration, capacity/duplicate
 * handling and the status-transition state machine.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use RubenDance\Repositories\Duplicate_Key_Exception;
use RubenDance\Services\Due_Date_Calculator;
use RubenDance\Services\Duplicate_Enrollment_Exception;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Illegal_Status_Transition_Exception;
use RubenDance\Services\Pricing_Service;
use RubenDance\Services\Variable_Symbol_Generator;

/**
 * Class EnrollmentServiceTest.
 *
 * `Enrollment_Service` is deliberately WordPress-agnostic (every database and
 * clock touchpoint is an injected callable, mirroring `Term_Service`), so
 * create-enrollment orchestration and the status state machine — the
 * highest-risk logic in the project — are exercised here with plain PHPUnit
 * and in-memory fakes, no WordPress bootstrap needed.
 */
class EnrollmentServiceTest extends TestCase {

	/**
	 * An open term with no discounts and unlimited capacity, as a baseline
	 * every test tweaks from.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function open_term( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 1,
				'status'         => 'open',
				'capacity'       => null,
				'price'          => '2400.00',
				'discount_early' => null,
				'early_until'    => null,
				'discount_pair'  => null,
			),
			$overrides
		);
	}

	/**
	 * A valid enrollment submission, as a baseline every test tweaks from.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_data(): array {
		return array(
			'term_id'           => 1,
			'user_id'           => 7,
			'participant_name'  => '',
			'role'              => Enrollment_Service::ROLE_SOLO,
			'partner_name'      => '',
			'payment_method'    => Enrollment_Service::PAYMENT_BANK_TRANSFER,
			'customer_note'     => '',
		);
	}

	/**
	 * Build a service wired to simple in-memory fakes.
	 *
	 * @param array<string, mixed> $options {
	 *     @type array|null    $term             Term `find_term()` returns for ID 1 (null = "not found").
	 *     @type int           $active_count     Value `count_active_enrollments()` returns.
	 *     @type int           $insert_id        ID the fake insert returns.
	 *     @type \Throwable|null $insert_exception Exception the fake insert throws instead of returning.
	 *     @type array<int, array<string, mixed>> $enrollments Enrollment rows `find_enrollment()` can return, keyed by ID.
	 *     @type string        $now              Fixed "current" datetime.
	 *     @type int           $due_date_days    Value `due_date_days()` returns.
	 * }
	 * @return array{0: Enrollment_Service, 1: ArrayObject} [service, calls].
	 */
	private function make_service( array $options = array() ): array {
		$term             = array_key_exists( 'term', $options ) ? $options['term'] : $this->open_term();
		$active_count     = $options['active_count'] ?? 0;
		$insert_id        = $options['insert_id'] ?? 42;
		$insert_exception = $options['insert_exception'] ?? null;
		$enrollments      = $options['enrollments'] ?? array();
		$now              = $options['now'] ?? '2025-08-01 12:00:00';
		$due_date_days    = $options['due_date_days'] ?? 7;

		$calls = new ArrayObject(
			array(
				'insert' => array(),
				'update' => array(),
			)
		);

		$service = new Enrollment_Service(
			static function ( int $term_id ) use ( $term ): ?array {
				return ( null !== $term && $term_id === (int) $term['id'] ) ? $term : null;
			},
			static function ( int $id ) use ( $enrollments ): ?array {
				return $enrollments[ $id ] ?? null;
			},
			static function ( int $term_id ) use ( $active_count ): int {
				unset( $term_id );

				return $active_count;
			},
			static function ( array $data ) use ( $calls, $insert_id, $insert_exception ): int {
				$calls['insert'] = array_merge( $calls['insert'], array( $data ) );

				if ( null !== $insert_exception ) {
					throw $insert_exception;
				}

				return $insert_id;
			},
			static function ( int $id, array $data ) use ( $calls ): bool {
				$calls['update'] = array_merge( $calls['update'], array( array( $id, $data ) ) );

				return true;
			},
			static function () use ( $due_date_days ): int {
				return $due_date_days;
			},
			static function () use ( $now ): string {
				return $now;
			},
			new Pricing_Service(),
			new Variable_Symbol_Generator(),
			new Due_Date_Calculator()
		);

		return array( $service, $calls );
	}

	// -- validate() -----------------------------------------------------

	/**
	 * A fully valid submission produces no errors.
	 */
	public function test_validate_accepts_valid_data(): void {
		list( $service ) = $this->make_service();

		$this->assertSame( array(), $service->validate( $this->valid_data() ) );
	}

	/**
	 * A missing term_id is rejected distinctly from a nonexistent one.
	 */
	public function test_validate_rejects_missing_term(): void {
		list( $service ) = $this->make_service();

		$data            = $this->valid_data();
		$data['term_id'] = 0;

		$errors = $service->validate( $data );

		$this->assertSame( Enrollment_Service::ERROR_TERM_REQUIRED, $errors['term_id'] );
	}

	/**
	 * A term_id that doesn't exist is rejected distinctly from a missing one.
	 */
	public function test_validate_rejects_nonexistent_term(): void {
		list( $service ) = $this->make_service();

		$data            = $this->valid_data();
		$data['term_id'] = 999;

		$errors = $service->validate( $data );

		$this->assertSame( Enrollment_Service::ERROR_TERM_NOT_FOUND, $errors['term_id'] );
	}

	/**
	 * A term that exists but isn't `open` (draft/closed/cancelled) is rejected.
	 */
	public function test_validate_rejects_term_not_open(): void {
		list( $service ) = $this->make_service( array( 'term' => $this->open_term( array( 'status' => 'closed' ) ) ) );

		$errors = $service->validate( $this->valid_data() );

		$this->assertSame( Enrollment_Service::ERROR_TERM_NOT_OPEN, $errors['term_id'] );
	}

	/**
	 * A missing user_id is rejected.
	 */
	public function test_validate_rejects_missing_user(): void {
		list( $service ) = $this->make_service();

		$data            = $this->valid_data();
		$data['user_id'] = 0;

		$errors = $service->validate( $data );

		$this->assertSame( Enrollment_Service::ERROR_USER_REQUIRED, $errors['user_id'] );
	}

	/**
	 * A participant_name over 190 characters is rejected.
	 */
	public function test_validate_rejects_participant_name_too_long(): void {
		list( $service ) = $this->make_service();

		$data                      = $this->valid_data();
		$data['participant_name']  = str_repeat( 'a', 191 );

		$errors = $service->validate( $data );

		$this->assertSame( Enrollment_Service::ERROR_PARTICIPANT_TOO_LONG, $errors['participant_name'] );
	}

	/**
	 * An invalid role is rejected.
	 */
	public function test_validate_rejects_invalid_role(): void {
		list( $service ) = $this->make_service();

		$data         = $this->valid_data();
		$data['role'] = 'not-a-role';

		$errors = $service->validate( $data );

		$this->assertSame( Enrollment_Service::ERROR_ROLE_INVALID, $errors['role'] );
	}

	/**
	 * A partner_name over 190 characters is rejected.
	 */
	public function test_validate_rejects_partner_name_too_long(): void {
		list( $service ) = $this->make_service();

		$data                 = $this->valid_data();
		$data['partner_name'] = str_repeat( 'a', 191 );

		$errors = $service->validate( $data );

		$this->assertSame( Enrollment_Service::ERROR_PARTNER_NAME_TOO_LONG, $errors['partner_name'] );
	}

	// -- create() ---------------------------------------------------------

	/**
	 * `create()` inserts a confirmed enrollment with the server-computed
	 * price, then stamps the variable symbol in a second update once the ID
	 * is known.
	 */
	public function test_create_inserts_enrollment_and_stamps_variable_symbol(): void {
		list( $service, $calls ) = $this->make_service( array( 'now' => '2025-08-01 12:00:00' ) );

		$id = $service->create( $this->valid_data() );

		$this->assertSame( 42, $id );
		$this->assertCount( 1, $calls['insert'] );

		$row = $calls['insert'][0];
		$this->assertSame( Enrollment_Service::STATUS_CONFIRMED, $row['status'] );
		$this->assertSame( '2400.00', $row['price'] );
		$this->assertNull( $row['discount_note'] );
		$this->assertSame( '2025-08-08', $row['due_date'] ); // +7 default days.
		$this->assertSame( 0, $row['over_capacity'] );
		$this->assertNull( $row['paid_at'] );
		$this->assertNull( $row['paid_marked_by'] );

		$this->assertCount( 1, $calls['update'] );
		list( $updated_id, $update_data ) = $calls['update'][0];
		$this->assertSame( 42, $updated_id );
		$this->assertSame( '2025000042', $update_data['variable_symbol'] );
	}

	/**
	 * `create()` uses the configured due-date setting, not always the default.
	 */
	public function test_create_uses_configured_due_date_days(): void {
		list( $service, $calls ) = $this->make_service(
			array(
				'now'           => '2025-08-01 12:00:00',
				'due_date_days' => 14,
			)
		);

		$service->create( $this->valid_data() );

		$this->assertSame( '2025-08-15', $calls['insert'][0]['due_date'] );
	}

	/**
	 * Over-capacity boundary: exactly at capacity (active enrollments ==
	 * capacity) sets the flag.
	 */
	public function test_create_sets_over_capacity_flag_at_boundary(): void {
		list( $service, $calls ) = $this->make_service(
			array(
				'term'         => $this->open_term( array( 'capacity' => 5 ) ),
				'active_count' => 5,
			)
		);

		$service->create( $this->valid_data() );

		$this->assertSame( 1, $calls['insert'][0]['over_capacity'] );
	}

	/**
	 * One below the boundary does not set the flag.
	 */
	public function test_create_does_not_set_over_capacity_flag_below_boundary(): void {
		list( $service, $calls ) = $this->make_service(
			array(
				'term'         => $this->open_term( array( 'capacity' => 5 ) ),
				'active_count' => 4,
			)
		);

		$service->create( $this->valid_data() );

		$this->assertSame( 0, $calls['insert'][0]['over_capacity'] );
	}

	/**
	 * An unlimited-capacity term (`capacity` NULL) is never over capacity,
	 * regardless of the active count.
	 */
	public function test_create_never_over_capacity_when_capacity_is_unlimited(): void {
		list( $service, $calls ) = $this->make_service(
			array(
				'term'         => $this->open_term( array( 'capacity' => null ) ),
				'active_count' => 999,
			)
		);

		$service->create( $this->valid_data() );

		$this->assertSame( 0, $calls['insert'][0]['over_capacity'] );
	}

	/**
	 * A duplicate `(term_id, user_id, participant_name)` — the DB unique key
	 * rejecting the insert — is translated into a friendly
	 * `Duplicate_Enrollment_Exception`, not a raw database error.
	 */
	public function test_create_translates_duplicate_key_violation(): void {
		list( $service ) = $this->make_service(
			array( 'insert_exception' => new Duplicate_Key_Exception( 'duplicate' ) )
		);

		$this->expectException( Duplicate_Enrollment_Exception::class );

		$service->create( $this->valid_data() );
	}

	/**
	 * The same user enrolling a *different* participant in the same term is
	 * accepted — only the exact (term_id, user_id, participant_name) tuple is
	 * a duplicate. Exercised end-to-end through `create()` against a fake
	 * insert callable that models the real unique-key behaviour.
	 */
	public function test_create_accepts_same_user_with_different_participant(): void {
		$existing = array();
		$next_id  = 1;

		$calls = new ArrayObject( array( 'insert' => array() ) );

		$service = new Enrollment_Service(
			static fn( int $term_id ): ?array => 1 === $term_id ? array(
				'id'             => 1,
				'status'         => 'open',
				'capacity'       => null,
				'price'          => '2400.00',
				'discount_early' => null,
				'early_until'    => null,
				'discount_pair'  => null,
			) : null,
			static fn( int $id ): ?array => null,
			static fn( int $term_id ): int => 0,
			static function ( array $data ) use ( &$existing, &$next_id, $calls ): int {
				$calls['insert'] = array_merge( $calls['insert'], array( $data ) );

				$key = $data['term_id'] . '|' . $data['user_id'] . '|' . $data['participant_name'];

				if ( isset( $existing[ $key ] ) ) {
					throw new Duplicate_Key_Exception( 'duplicate' );
				}

				$existing[ $key ] = true;

				return $next_id++;
			},
			static fn( int $id, array $data ): bool => true,
			static fn(): int => 7,
			static fn(): string => '2025-08-01 12:00:00',
			new Pricing_Service(),
			new Variable_Symbol_Generator(),
			new Due_Date_Calculator()
		);

		$data                     = $this->valid_data();
		$data['participant_name'] = 'Anna';
		$first_id                 = $service->create( $data );

		$data['participant_name'] = 'Petra';
		$second_id                = $service->create( $data );

		$this->assertSame( 1, $first_id );
		$this->assertSame( 2, $second_id );

		// Re-submitting the exact same tuple is now a duplicate.
		$data['participant_name'] = 'Anna';
		$this->expectException( Duplicate_Enrollment_Exception::class );
		$service->create( $data );
	}

	// -- status transitions -----------------------------------------------

	/**
	 * Build an enrollment fixture with the given status.
	 *
	 * @param string $status One of `Enrollment_Service::STATUSES`.
	 * @return array<string, mixed>
	 */
	private function enrollment_with_status( string $status ): array {
		return array(
			'id'             => 5,
			'status'         => $status,
			'paid_at'        => Enrollment_Service::STATUS_PAID === $status ? '2025-08-02 10:00:00' : null,
			'paid_marked_by' => Enrollment_Service::STATUS_PAID === $status ? 3 : null,
		);
	}

	/**
	 * confirmed → paid is legal and stamps paid_at/paid_marked_by.
	 */
	public function test_mark_paid_from_confirmed_succeeds(): void {
		list( $service, $calls ) = $this->make_service(
			array(
				'enrollments' => array( 5 => $this->enrollment_with_status( Enrollment_Service::STATUS_CONFIRMED ) ),
				'now'         => '2025-08-10 09:00:00',
			)
		);

		$service->mark_paid( 5, 9 );

		list( $id, $data ) = $calls['update'][0];
		$this->assertSame( 5, $id );
		$this->assertSame( Enrollment_Service::STATUS_PAID, $data['status'] );
		$this->assertSame( '2025-08-10 09:00:00', $data['paid_at'] );
		$this->assertSame( 9, $data['paid_marked_by'] );
	}

	/**
	 * paid → paid (mark_paid on an already-paid enrollment) is illegal.
	 */
	public function test_mark_paid_from_paid_throws(): void {
		list( $service ) = $this->make_service(
			array( 'enrollments' => array( 5 => $this->enrollment_with_status( Enrollment_Service::STATUS_PAID ) ) )
		);

		$this->expectException( Illegal_Status_Transition_Exception::class );
		$service->mark_paid( 5, 9 );
	}

	/**
	 * cancelled → paid is illegal (the milestone's explicit example case).
	 */
	public function test_mark_paid_from_cancelled_throws(): void {
		list( $service ) = $this->make_service(
			array( 'enrollments' => array( 5 => $this->enrollment_with_status( Enrollment_Service::STATUS_CANCELLED ) ) )
		);

		$this->expectException( Illegal_Status_Transition_Exception::class );
		$service->mark_paid( 5, 9 );
	}

	/**
	 * paid → confirmed (unmark) is legal and clears paid_at/paid_marked_by.
	 */
	public function test_unmark_paid_from_paid_succeeds(): void {
		list( $service, $calls ) = $this->make_service(
			array( 'enrollments' => array( 5 => $this->enrollment_with_status( Enrollment_Service::STATUS_PAID ) ) )
		);

		$service->unmark_paid( 5 );

		list( $id, $data ) = $calls['update'][0];
		$this->assertSame( 5, $id );
		$this->assertSame( Enrollment_Service::STATUS_CONFIRMED, $data['status'] );
		$this->assertNull( $data['paid_at'] );
		$this->assertNull( $data['paid_marked_by'] );
	}

	/**
	 * confirmed → confirmed (unmark on a not-yet-paid enrollment) is illegal.
	 */
	public function test_unmark_paid_from_confirmed_throws(): void {
		list( $service ) = $this->make_service(
			array( 'enrollments' => array( 5 => $this->enrollment_with_status( Enrollment_Service::STATUS_CONFIRMED ) ) )
		);

		$this->expectException( Illegal_Status_Transition_Exception::class );
		$service->unmark_paid( 5 );
	}

	/**
	 * cancelled → confirmed (unmark) is illegal.
	 */
	public function test_unmark_paid_from_cancelled_throws(): void {
		list( $service ) = $this->make_service(
			array( 'enrollments' => array( 5 => $this->enrollment_with_status( Enrollment_Service::STATUS_CANCELLED ) ) )
		);

		$this->expectException( Illegal_Status_Transition_Exception::class );
		$service->unmark_paid( 5 );
	}

	/**
	 * confirmed → cancelled is legal.
	 */
	public function test_cancel_from_confirmed_succeeds(): void {
		list( $service, $calls ) = $this->make_service(
			array( 'enrollments' => array( 5 => $this->enrollment_with_status( Enrollment_Service::STATUS_CONFIRMED ) ) )
		);

		$service->cancel( 5 );

		list( $id, $data ) = $calls['update'][0];
		$this->assertSame( 5, $id );
		$this->assertSame( Enrollment_Service::STATUS_CANCELLED, $data['status'] );
	}

	/**
	 * paid → cancelled is legal.
	 */
	public function test_cancel_from_paid_succeeds(): void {
		list( $service, $calls ) = $this->make_service(
			array( 'enrollments' => array( 5 => $this->enrollment_with_status( Enrollment_Service::STATUS_PAID ) ) )
		);

		$service->cancel( 5 );

		list( $id, $data ) = $calls['update'][0];
		$this->assertSame( 5, $id );
		$this->assertSame( Enrollment_Service::STATUS_CANCELLED, $data['status'] );
	}

	/**
	 * cancelled → cancelled (cancelling twice) is illegal — cancelled is terminal.
	 */
	public function test_cancel_from_cancelled_throws(): void {
		list( $service ) = $this->make_service(
			array( 'enrollments' => array( 5 => $this->enrollment_with_status( Enrollment_Service::STATUS_CANCELLED ) ) )
		);

		$this->expectException( Illegal_Status_Transition_Exception::class );
		$service->cancel( 5 );
	}

	/**
	 * Every transition method fails loudly for a nonexistent enrollment ID.
	 */
	public function test_transition_on_missing_enrollment_throws(): void {
		list( $service ) = $this->make_service( array( 'enrollments' => array() ) );

		$this->expectException( \InvalidArgumentException::class );
		$service->cancel( 999 );
	}
}
