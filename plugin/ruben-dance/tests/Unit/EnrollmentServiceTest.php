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

	// -- manual price (M12: manual enrollment) -----------------------------

	/**
	 * `validate()` accepts a blank manual_price (normal server-computed price).
	 */
	public function test_validate_accepts_blank_manual_price(): void {
		list( $service ) = $this->make_service();

		$this->assertSame( array(), $service->validate( $this->valid_data() ) );
	}

	/**
	 * `validate()` rejects a malformed manual_price.
	 */
	public function test_validate_rejects_invalid_manual_price(): void {
		list( $service ) = $this->make_service();

		$data                 = $this->valid_data();
		$data['manual_price'] = 'not-a-number';

		$errors = $service->validate( $data );

		$this->assertSame( Enrollment_Service::ERROR_MANUAL_PRICE_INVALID, $errors['manual_price'] );
	}

	/**
	 * `create()` uses the manual price instead of the computed one, and
	 * records the override in `admin_note`, when `manual_price` is set.
	 */
	public function test_create_uses_manual_price_when_given(): void {
		list( $service, $calls ) = $this->make_service();

		$data                 = $this->valid_data();
		$data['manual_price'] = '999';

		$service->create( $data );

		$row = $calls['insert'][0];
		$this->assertSame( '999.00', $row['price'] );
		$this->assertStringContainsString( '999.00', (string) $row['admin_note'] );
		$this->assertStringContainsString( '2400.00', (string) $row['admin_note'] ); // The computed price is still recorded for audit.
	}

	/**
	 * `create()` uses the server-computed price when `manual_price` is blank.
	 */
	public function test_create_uses_computed_price_when_manual_price_blank(): void {
		list( $service, $calls ) = $this->make_service();

		$service->create( $this->valid_data() );

		$row = $calls['insert'][0];
		$this->assertSame( '2400.00', $row['price'] );
		$this->assertNull( $row['admin_note'] );
	}

	// -- validate_role_partner()/update_role_partner() ---------------------

	/**
	 * A valid role/partner submission produces no errors.
	 */
	public function test_validate_role_partner_accepts_valid_data(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_role_partner(
			array(
				'role'         => Enrollment_Service::ROLE_LEADER,
				'partner_name' => 'Anna',
			)
		);

		$this->assertSame( array(), $errors );
	}

	/**
	 * An invalid role is rejected.
	 */
	public function test_validate_role_partner_rejects_invalid_role(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_role_partner( array( 'role' => 'not-a-role', 'partner_name' => '' ) );

		$this->assertSame( Enrollment_Service::ERROR_ROLE_INVALID, $errors['role'] );
	}

	/**
	 * `update_role_partner()` writes the new role/partner_name and blanks a
	 * cleared partner_name to null.
	 */
	public function test_update_role_partner_writes_fields(): void {
		list( $service, $calls ) = $this->make_service(
			array( 'enrollments' => array( 5 => $this->enrollment_with_status( Enrollment_Service::STATUS_CONFIRMED ) ) )
		);

		$service->update_role_partner( 5, Enrollment_Service::ROLE_FOLLOWER, '' );

		list( $id, $data ) = $calls['update'][0];
		$this->assertSame( 5, $id );
		$this->assertSame( Enrollment_Service::ROLE_FOLLOWER, $data['role'] );
		$this->assertNull( $data['partner_name'] );
	}

	// -- validate_price_edit()/edit_price() ---------------------------------

	/**
	 * A price edit without a reason is rejected (spec F11b acceptance
	 * criterion: "Price edit without a reason is rejected").
	 */
	public function test_validate_price_edit_requires_reason(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_price_edit( array( 'price' => '1000', 'reason' => '' ) );

		$this->assertSame( Enrollment_Service::ERROR_REASON_REQUIRED, $errors['reason'] );
	}

	/**
	 * An invalid price amount is rejected.
	 */
	public function test_validate_price_edit_rejects_invalid_price(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_price_edit( array( 'price' => 'abc', 'reason' => 'Goodwill discount' ) );

		$this->assertSame( Enrollment_Service::ERROR_PRICE_INVALID, $errors['price'] );
	}

	/**
	 * `edit_price()` writes the new price and appends the reason to
	 * `admin_note`, leaving `discount_note` untouched (spec F11b acceptance
	 * criterion: "reason lands in admin_note"; "discount_note preserved").
	 */
	public function test_edit_price_updates_price_and_appends_reason_to_admin_note(): void {
		$enrollment            = $this->enrollment_with_status( Enrollment_Service::STATUS_CONFIRMED );
		$enrollment['price']   = '2400.00';
		$enrollment['admin_note'] = null;

		list( $service, $calls ) = $this->make_service( array( 'enrollments' => array( 5 => $enrollment ) ) );

		$service->edit_price( 5, '2000', 'Goodwill discount agreed by phone', 'Jane Admin' );

		list( $id, $data ) = $calls['update'][0];
		$this->assertSame( 5, $id );
		$this->assertSame( '2000.00', $data['price'] );
		$this->assertStringContainsString( 'Goodwill discount agreed by phone', $data['admin_note'] );
		$this->assertStringContainsString( 'Jane Admin', $data['admin_note'] );
		$this->assertArrayNotHasKey( 'discount_note', $data ); // Never touched.
	}

	/**
	 * A second `edit_price()` call appends to, rather than overwrites, the
	 * existing admin_note.
	 */
	public function test_edit_price_appends_to_existing_admin_note(): void {
		$enrollment               = $this->enrollment_with_status( Enrollment_Service::STATUS_CONFIRMED );
		$enrollment['price']      = '2400.00';
		$enrollment['admin_note'] = 'Earlier note.';

		list( $service, $calls ) = $this->make_service( array( 'enrollments' => array( 5 => $enrollment ) ) );

		$service->edit_price( 5, '2000', 'Discount', 'Jane Admin' );

		list( , $data ) = $calls['update'][0];
		$this->assertStringContainsString( 'Earlier note.', $data['admin_note'] );
		$this->assertStringContainsString( 'Discount', $data['admin_note'] );
	}

	// -- validate_admin_note()/add_admin_note() -----------------------------

	/**
	 * A blank note is rejected.
	 */
	public function test_validate_admin_note_requires_note(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_admin_note( array( 'note' => '  ' ) );

		$this->assertSame( Enrollment_Service::ERROR_NOTE_REQUIRED, $errors['note'] );
	}

	/**
	 * `add_admin_note()` appends the note (prefixed with the actor label) to
	 * the existing admin_note.
	 */
	public function test_add_admin_note_appends(): void {
		$enrollment               = $this->enrollment_with_status( Enrollment_Service::STATUS_CONFIRMED );
		$enrollment['admin_note'] = 'Existing note.';

		list( $service, $calls ) = $this->make_service( array( 'enrollments' => array( 5 => $enrollment ) ) );

		$service->add_admin_note( 5, 'Called, asked to move to Tuesday group.', 'Jane Admin' );

		list( , $data ) = $calls['update'][0];
		$this->assertStringContainsString( 'Existing note.', $data['admin_note'] );
		$this->assertStringContainsString( 'Jane Admin: Called, asked to move to Tuesday group.', $data['admin_note'] );
	}

	// -- move_to_term() ------------------------------------------------------

	/**
	 * Build a service wired for `move_to_term()` tests: two terms (source id
	 * 1, target id 2) and one enrollment currently in the source term.
	 *
	 * @param array<string, mixed> $target_overrides Overrides for the target term (id 2).
	 * @param array<string, mixed> $enrollment_overrides Overrides for the enrollment fixture.
	 * @param int                  $active_count     Value `count_active_enrollments()` returns for the target term.
	 * @return array{0: Enrollment_Service, 1: ArrayObject}
	 */
	private function make_move_service( array $target_overrides = array(), array $enrollment_overrides = array(), int $active_count = 0 ): array {
		$terms = array(
			1 => $this->open_term( array( 'id' => 1 ) ),
			2 => $this->open_term( array_merge( array( 'id' => 2 ), $target_overrides ) ),
		);

		$enrollment = array_merge(
			array(
				'id'         => 5,
				'status'     => Enrollment_Service::STATUS_CONFIRMED,
				'term_id'    => 1,
				'admin_note' => null,
			),
			$enrollment_overrides
		);

		$calls = new ArrayObject( array( 'update' => array() ) );

		$service = new Enrollment_Service(
			static function ( int $term_id ) use ( $terms ): ?array {
				return $terms[ $term_id ] ?? null;
			},
			static function ( int $id ) use ( $enrollment ): ?array {
				return $id === (int) $enrollment['id'] ? $enrollment : null;
			},
			static function ( int $term_id ) use ( $active_count ): int {
				unset( $term_id );

				return $active_count;
			},
			static fn( array $data ): int => 0,
			static function ( int $id, array $data ) use ( $calls ): bool {
				$calls['update'] = array_merge( $calls['update'], array( array( $id, $data ) ) );

				return true;
			},
			static fn(): int => 7,
			static fn(): string => '2025-08-10 09:00:00',
			new Pricing_Service(),
			new Variable_Symbol_Generator(),
			new Due_Date_Calculator()
		);

		return array( $service, $calls );
	}

	/**
	 * A move to an open term with room re-evaluates over_capacity to false
	 * and records the move in admin_note (spec F11b acceptance criterion:
	 * "history traceable via admin note").
	 */
	public function test_move_to_term_succeeds_and_records_note(): void {
		list( $service, $calls ) = $this->make_move_service( array( 'capacity' => 10 ), array(), 2 );

		$service->move_to_term( 5, 2, 'Jane Admin' );

		list( $id, $data ) = $calls['update'][0];
		$this->assertSame( 5, $id );
		$this->assertSame( 2, $data['term_id'] );
		$this->assertSame( 0, $data['over_capacity'] );
		$this->assertStringContainsString( 'term #1', $data['admin_note'] );
		$this->assertStringContainsString( 'term #2', $data['admin_note'] );
		$this->assertStringContainsString( 'Jane Admin', $data['admin_note'] );
	}

	/**
	 * Moving into a full target term sets over_capacity true.
	 */
	public function test_move_to_term_sets_over_capacity_when_target_full(): void {
		list( $service, $calls ) = $this->make_move_service( array( 'capacity' => 2 ), array(), 2 );

		$service->move_to_term( 5, 2, 'Jane Admin' );

		list( , $data ) = $calls['update'][0];
		$this->assertSame( 1, $data['over_capacity'] );
	}

	/**
	 * Moving a cancelled enrollment is illegal.
	 */
	public function test_move_to_term_rejects_cancelled_enrollment(): void {
		list( $service ) = $this->make_move_service( array(), array( 'status' => Enrollment_Service::STATUS_CANCELLED ) );

		$this->expectException( Illegal_Status_Transition_Exception::class );
		$service->move_to_term( 5, 2, 'Jane Admin' );
	}

	/**
	 * Moving to the enrollment's current term is rejected.
	 */
	public function test_move_to_term_rejects_same_term(): void {
		list( $service ) = $this->make_move_service();

		$this->expectException( \InvalidArgumentException::class );
		$service->move_to_term( 5, 1, 'Jane Admin' );
	}

	/**
	 * Moving to a nonexistent term is rejected.
	 */
	public function test_move_to_term_rejects_missing_target_term(): void {
		list( $service ) = $this->make_move_service();

		$this->expectException( \InvalidArgumentException::class );
		$service->move_to_term( 5, 999, 'Jane Admin' );
	}

	/**
	 * A duplicate key violation on the update (the account/participant
	 * already has an enrollment in the target term) is translated into a
	 * friendly `Duplicate_Enrollment_Exception` (spec F11b: "duplicate rule
	 * still enforced").
	 */
	public function test_move_to_term_translates_duplicate_key_violation(): void {
		$terms = array(
			1 => $this->open_term( array( 'id' => 1 ) ),
			2 => $this->open_term( array( 'id' => 2 ) ),
		);

		$enrollment = array(
			'id'         => 5,
			'status'     => Enrollment_Service::STATUS_CONFIRMED,
			'term_id'    => 1,
			'admin_note' => null,
		);

		$service = new Enrollment_Service(
			static function ( int $term_id ) use ( $terms ): ?array {
				return $terms[ $term_id ] ?? null;
			},
			static function ( int $id ) use ( $enrollment ): ?array {
				return $id === (int) $enrollment['id'] ? $enrollment : null;
			},
			static fn( int $term_id ): int => 0,
			static fn( array $data ): int => 0,
			static function ( int $id, array $data ): bool {
				unset( $id, $data );

				throw new Duplicate_Key_Exception( 'duplicate' );
			},
			static fn(): int => 7,
			static fn(): string => '2025-08-10 09:00:00',
			new Pricing_Service(),
			new Variable_Symbol_Generator(),
			new Due_Date_Calculator()
		);

		$this->expectException( Duplicate_Enrollment_Exception::class );
		$service->move_to_term( 5, 2, 'Jane Admin' );
	}
}
