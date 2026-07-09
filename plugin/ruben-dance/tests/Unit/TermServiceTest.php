<?php
/**
 * Tests for term validation, save orchestration and duplication.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use RubenDance\Services\Lesson_Generator;
use RubenDance\Services\Term_Service;

/**
 * Class TermServiceTest.
 *
 * `Term_Service` is deliberately WordPress-agnostic (every database and
 * clock touchpoint is an injected callable, mirroring `Location_Service`),
 * so both field validation and the save/duplicate orchestration around
 * `Lesson_Generator` are exercised here with plain PHPUnit and in-memory
 * fakes, no WordPress bootstrap needed.
 */
class TermServiceTest extends TestCase {

	/**
	 * A complete, valid course-term submission, as a baseline every test
	 * tweaks from.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_data(): array {
		return array(
			'course_id'       => 1,
			'location_id'     => 1,
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => 1,
			'start_time'      => '18:00',
			'end_time'        => '19:00',
			'date_from'       => '2025-09-01',
			'date_to'         => '2025-09-15',
			'instructor'      => 'Ruben',
			'capacity'        => '20',
			'price'           => '1500',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_OPEN,
			'season_label_cs' => 'Podzim 2025',
			'season_label_en' => 'Autumn 2025',
			'note_public_cs'  => '',
			'note_public_en'  => '',
		);
	}

	/**
	 * Build a service wired to simple in-memory fakes, existing lessons
	 * always empty and course/location IDs 1 always considered valid.
	 *
	 * @param array<int, array<string, mixed>> $existing_lessons Lessons `existing_lessons()` returns.
	 * @return array{0: Term_Service, 1: ArrayObject} [service, calls].
	 */
	private function make_service( array $existing_lessons = array() ): array {
		$calls = new ArrayObject(
			array(
				'insert_term'    => array(),
				'update_term'    => array(),
				'insert_lessons' => array(),
				'delete_lessons' => array(),
			)
		);

		$service = new Term_Service(
			static fn( int $id ): bool => 1 === $id,
			static fn( int $id ): bool => 1 === $id,
			static fn( int $id ): ?array => null,
			static function ( array $data ) use ( $calls ): int {
				$calls['insert_term'] = array_merge( $calls['insert_term'], array( $data ) );

				return 42;
			},
			static function ( int $id, array $data ) use ( $calls ): bool {
				$calls['update_term'] = array_merge( $calls['update_term'], array( array( $id, $data ) ) );

				return true;
			},
			static fn( int $term_id ): array => $existing_lessons,
			static function ( int $term_id, array $rows ) use ( $calls ): void {
				$calls['insert_lessons'] = array_merge( $calls['insert_lessons'], array( array( $term_id, $rows ) ) );
			},
			static function ( array $ids ) use ( $calls ): void {
				$calls['delete_lessons'] = array_merge( $calls['delete_lessons'], array( $ids ) );
			},
			static fn(): string => '2025-08-01 12:00:00',
			new Lesson_Generator()
		);

		return array( $service, $calls );
	}

	/**
	 * A fully valid submission produces no errors.
	 */
	public function test_validate_accepts_valid_data(): void {
		list( $service ) = $this->make_service();

		$this->assertSame( array(), $service->validate( $this->valid_data() ) );
	}

	/**
	 * Missing course/location are rejected distinctly from invalid ones.
	 */
	public function test_validate_rejects_missing_course_and_location(): void {
		list( $service ) = $this->make_service();

		$data                 = $this->valid_data();
		$data['course_id']    = 0;
		$data['location_id']  = 0;
		$errors               = $service->validate( $data );

		$this->assertSame( Term_Service::ERROR_COURSE_REQUIRED, $errors['course_id'] );
		$this->assertSame( Term_Service::ERROR_LOCATION_REQUIRED, $errors['location_id'] );
	}

	/**
	 * A course/location ID that doesn't exist is rejected distinctly from a
	 * missing one.
	 */
	public function test_validate_rejects_nonexistent_course_and_location(): void {
		list( $service ) = $this->make_service();

		$data                = $this->valid_data();
		$data['course_id']   = 999;
		$data['location_id'] = 999;
		$errors              = $service->validate( $data );

		$this->assertSame( Term_Service::ERROR_COURSE_INVALID, $errors['course_id'] );
		$this->assertSame( Term_Service::ERROR_LOCATION_INVALID, $errors['location_id'] );
	}

	/**
	 * A course term (not a workshop) requires a weekday in 1-7.
	 */
	public function test_validate_rejects_invalid_weekday_for_course_type(): void {
		list( $service ) = $this->make_service();

		$data             = $this->valid_data();
		$data['weekday']  = 9;
		$errors           = $service->validate( $data );

		$this->assertSame( Term_Service::ERROR_WEEKDAY_INVALID, $errors['weekday'] );
	}

	/**
	 * A workshop needs no weekday at all — it's derived from date_from.
	 */
	public function test_validate_does_not_require_weekday_for_workshop(): void {
		list( $service ) = $this->make_service();

		$data              = $this->valid_data();
		$data['type']      = Term_Service::TYPE_WORKSHOP;
		$data['weekday']   = 0;
		$data['date_from'] = '2025-11-15';
		$data['date_to']   = '2025-11-15';
		$errors            = $service->validate( $data );

		$this->assertArrayNotHasKey( 'weekday', $errors );
	}

	/**
	 * A workshop needs no independently-valid date_to — it's forced to
	 * date_from regardless of what was submitted.
	 */
	public function test_validate_does_not_require_date_to_for_workshop(): void {
		list( $service ) = $this->make_service();

		$data              = $this->valid_data();
		$data['type']      = Term_Service::TYPE_WORKSHOP;
		$data['date_from'] = '2025-11-15';
		$data['date_to']   = ''; // Deliberately blank/mismatched.
		$errors            = $service->validate( $data );

		$this->assertArrayNotHasKey( 'date_to', $errors );
	}

	/**
	 * end_time must be strictly after start_time.
	 */
	public function test_validate_rejects_end_time_not_after_start_time(): void {
		list( $service ) = $this->make_service();

		$data               = $this->valid_data();
		$data['start_time'] = '19:00';
		$data['end_time']   = '18:00';
		$errors             = $service->validate( $data );

		$this->assertSame( Term_Service::ERROR_END_TIME_BEFORE_START, $errors['end_time'] );
	}

	/**
	 * date_to must not be before date_from for a course term.
	 */
	public function test_validate_rejects_date_to_before_date_from(): void {
		list( $service ) = $this->make_service();

		$data              = $this->valid_data();
		$data['date_from'] = '2025-09-15';
		$data['date_to']   = '2025-09-01';
		$errors            = $service->validate( $data );

		$this->assertSame( Term_Service::ERROR_DATE_TO_BEFORE_FROM, $errors['date_to'] );
	}

	/**
	 * discount_early and early_until must be given together, never just one.
	 */
	public function test_validate_rejects_discount_early_without_early_until(): void {
		list( $service ) = $this->make_service();

		$data                    = $this->valid_data();
		$data['discount_early']  = '300';
		$data['early_until']     = '';
		$errors                  = $service->validate( $data );

		$this->assertSame( Term_Service::ERROR_EARLY_UNTIL_REQUIRED, $errors['early_until'] );
	}

	/**
	 * The inverse: early_until without a discount_early amount is also rejected.
	 */
	public function test_validate_rejects_early_until_without_discount_early(): void {
		list( $service ) = $this->make_service();

		$data                = $this->valid_data();
		$data['early_until'] = '2025-08-15';
		$errors              = $service->validate( $data );

		$this->assertSame( Term_Service::ERROR_EARLY_UNTIL_REQUIRED, $errors['early_until'] );
	}

	/**
	 * Both discount_early and early_until present together is valid.
	 */
	public function test_validate_accepts_discount_early_with_early_until(): void {
		list( $service ) = $this->make_service();

		$data                   = $this->valid_data();
		$data['discount_early'] = '300';
		$data['early_until']    = '2025-08-15';
		$errors                 = $service->validate( $data );

		$this->assertArrayNotHasKey( 'early_until', $errors );
		$this->assertArrayNotHasKey( 'discount_early', $errors );
	}

	/**
	 * An empty season_label_cs is required; season_label_en is not.
	 */
	public function test_validate_requires_season_label_cs_only(): void {
		list( $service ) = $this->make_service();

		$data                     = $this->valid_data();
		$data['season_label_cs']  = '';
		$data['season_label_en']  = '';
		$errors                   = $service->validate( $data );

		$this->assertSame( Term_Service::ERROR_SEASON_LABEL_CS_REQUIRED, $errors['season_label_cs'] );
		$this->assertArrayNotHasKey( 'season_label_en', $errors );
	}

	/**
	 * `create()` inserts a term row stamped with created_at/updated_at, then
	 * generates lessons for the saved date range via `Lesson_Generator`.
	 */
	public function test_create_inserts_term_and_generates_lessons(): void {
		list( $service, $calls ) = $this->make_service();

		$term_id = $service->create( $this->valid_data() );

		$this->assertSame( 42, $term_id );
		$this->assertCount( 1, $calls['insert_term'] );
		$this->assertSame( '2025-08-01 12:00:00', $calls['insert_term'][0]['created_at'] );
		$this->assertSame( '2025-08-01 12:00:00', $calls['insert_term'][0]['updated_at'] );

		// Mondays 2025-09-01 through 2025-09-15: three lessons generated.
		$this->assertCount( 1, $calls['insert_lessons'] );
		list( $term_id_for_lessons, $rows ) = $calls['insert_lessons'][0];
		$this->assertSame( 42, $term_id_for_lessons );
		$this->assertCount( 3, $rows );
		$this->assertSame( array(), $calls['delete_lessons'] );
	}

	/**
	 * `update_details()` regenerates lessons against whatever the term's
	 * existing lessons already are, preserving/deleting per
	 * `Lesson_Generator`'s rules rather than blowing everything away.
	 */
	public function test_update_details_regenerates_lessons_against_existing_rows(): void {
		$existing = array(
			array(
				'id'          => 5,
				'lesson_date' => '2025-09-01',
				'start_time'  => '18:00',
				'end_time'    => '19:00',
				'status'      => 'scheduled',
				'note'        => null,
			),
			array(
				'id'          => 6,
				'lesson_date' => '2025-09-22', // Outside the new (shrunk) range, not preserved-status: deleted.
				'start_time'  => '18:00',
				'end_time'    => '19:00',
				'status'      => 'scheduled',
				'note'        => null,
			),
		);

		list( $service, $calls ) = $this->make_service( $existing );

		$data            = $this->valid_data();
		$data['date_to'] = '2025-09-08'; // Shrunk from 2025-09-15.

		$updated = $service->update_details( 7, $data );

		$this->assertTrue( $updated );
		$this->assertCount( 1, $calls['update_term'] );
		$this->assertSame( 7, $calls['update_term'][0][0] );

		// Only 2025-09-08 needs inserting (2025-09-01 already exists).
		list( $term_id_for_lessons, $rows ) = $calls['insert_lessons'][0];
		$this->assertSame( 7, $term_id_for_lessons );
		$this->assertSame( array( '2025-09-08' ), array_column( $rows, 'lesson_date' ) );

		// The stale, now-out-of-range scheduled lesson (id 6) is deleted.
		$this->assertSame( array( 6 ), $calls['delete_lessons'][0] );
	}

	/**
	 * `update_details()` never deletes a cancelled lesson just because a
	 * date-range edit moved its date out of the newly expected set — this is
	 * the milestone's explicit acceptance criterion, exercised at the
	 * `Term_Service` orchestration layer (not just inside `Lesson_Generator`
	 * directly).
	 */
	public function test_update_details_never_deletes_a_cancelled_lesson(): void {
		$existing = array(
			array(
				'id'          => 6,
				'lesson_date' => '2025-09-22',
				'start_time'  => '18:00',
				'end_time'    => '19:00',
				'status'      => 'cancelled',
				'note'        => 'Holiday',
			),
		);

		list( $service, $calls ) = $this->make_service( $existing );

		$data            = $this->valid_data();
		$data['date_to'] = '2025-09-08';

		$service->update_details( 7, $data );

		$this->assertSame( array(), $calls['delete_lessons'] );
	}

	/**
	 * `row()` forces a workshop's date_to to date_from and derives weekday
	 * from it, regardless of what was submitted for either field.
	 */
	public function test_row_forces_workshop_date_to_and_weekday(): void {
		list( $service ) = $this->make_service();

		$data              = $this->valid_data();
		$data['type']      = Term_Service::TYPE_WORKSHOP;
		$data['date_from'] = '2025-11-15'; // A Saturday.
		$data['date_to']   = '2099-01-01'; // Deliberately wrong: must be overridden.
		$data['weekday']   = 3; // Deliberately wrong: must be overridden.

		$row = $service->row( $data );

		$this->assertSame( '2025-11-15', $row['date_to'] );
		$this->assertSame( 6, $row['weekday'] ); // Saturday = ISO 6.
	}

	/**
	 * `row()` formats price/discount amounts to a fixed two-decimal string
	 * and stores blank optional amounts as null, not empty string.
	 */
	public function test_row_formats_amounts_and_nulls_blank_optionals(): void {
		list( $service ) = $this->make_service();

		$data                   = $this->valid_data();
		$data['price']          = '1500';
		$data['discount_early'] = '';
		$data['capacity']       = '';

		$row = $service->row( $data );

		$this->assertSame( '1500.00', $row['price'] );
		$this->assertNull( $row['discount_early'] );
		$this->assertNull( $row['capacity'] );
	}

	/**
	 * `duplicate()` copies every column from the source row, but forces
	 * status back to draft and stamps fresh timestamps — never a verbatim
	 * clone of those three.
	 */
	public function test_duplicate_copies_row_forces_draft_status(): void {
		$calls = new ArrayObject(
			array(
				'insert_term'    => array(),
				'insert_lessons' => array(),
				'delete_lessons' => array(),
			)
		);

		$source = array(
			'id'              => 3,
			'course_id'       => 1,
			'location_id'     => 1,
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => 1,
			'start_time'      => '18:00',
			'end_time'        => '19:00',
			'date_from'       => '2025-09-01',
			'date_to'         => '2025-09-15',
			'instructor'      => 'Ruben',
			'capacity'        => 20,
			'price'           => '1500.00',
			'status'          => Term_Service::STATUS_OPEN, // Must not survive the duplicate.
			'season_label_cs' => 'Podzim 2025',
			'season_label_en' => 'Autumn 2025',
			'created_at'      => '2025-01-01 00:00:00',
			'updated_at'      => '2025-01-01 00:00:00',
		);

		$service = new Term_Service(
			static fn( int $id ): bool => true,
			static fn( int $id ): bool => true,
			static fn( int $id ): ?array => 3 === $id ? $source : null,
			static function ( array $data ) use ( $calls ): int {
				$calls['insert_term'] = array_merge( $calls['insert_term'], array( $data ) );

				return 99;
			},
			static fn( int $id, array $data ): bool => true,
			static fn( int $term_id ): array => array(),
			static function ( int $term_id, array $rows ) use ( $calls ): void {
				$calls['insert_lessons'] = array_merge( $calls['insert_lessons'], array( array( $term_id, $rows ) ) );
			},
			static function ( array $ids ) use ( $calls ): void {
				$calls['delete_lessons'] = array_merge( $calls['delete_lessons'], array( $ids ) );
			},
			static fn(): string => '2025-08-01 12:00:00',
			new Lesson_Generator()
		);

		$new_id = $service->duplicate( 3 );

		$this->assertSame( 99, $new_id );
		$this->assertCount( 1, $calls['insert_term'] );

		$row = $calls['insert_term'][0];
		$this->assertArrayNotHasKey( 'id', $row );
		$this->assertSame( Term_Service::STATUS_DRAFT, $row['status'] );
		$this->assertSame( '2025-08-01 12:00:00', $row['created_at'] );
		$this->assertSame( '2025-08-01 12:00:00', $row['updated_at'] );
		$this->assertSame( 'Podzim 2025', $row['season_label_cs'] );
		$this->assertSame( '2025-09-01', $row['date_from'] );
	}

	/**
	 * `duplicate()` returns null for a nonexistent source term without
	 * touching any of the write callables.
	 */
	public function test_duplicate_returns_null_for_missing_source(): void {
		list( $service, $calls ) = $this->make_service();

		$result = $service->duplicate( 999 );

		$this->assertNull( $result );
		$this->assertSame( array(), $calls['insert_term'] );
	}
}
