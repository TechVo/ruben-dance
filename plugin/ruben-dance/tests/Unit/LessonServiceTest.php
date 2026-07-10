<?php
/**
 * Tests for single-lesson validation, save, and the notify-enrollees stub.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use RubenDance\Services\Lesson_Service;

/**
 * Class LessonServiceTest.
 *
 * `Lesson_Service` is deliberately WordPress-agnostic (every touchpoint is
 * an injected callable, mirroring `Location_Service`/`Term_Service`), so
 * validation and the notify-trigger decision are exercised here with plain
 * PHPUnit and in-memory fakes.
 */
class LessonServiceTest extends TestCase {

	/**
	 * Build a service wired to simple in-memory fakes.
	 *
	 * @return array{0: Lesson_Service, 1: ArrayObject} [service, calls] where
	 *               `calls` holds 'update', 'notify' and 'on_saved' call records.
	 */
	private function make_service(): array {
		$calls = new ArrayObject(
			array(
				'update'   => array(),
				'notify'   => array(),
				'on_saved' => array(),
			)
		);

		$service = new Lesson_Service(
			static function ( int $id, array $data ) use ( $calls ): bool {
				$calls['update'] = array_merge( $calls['update'], array( array( $id, $data ) ) );

				return true;
			},
			static function ( int $lesson_id, string $status ) use ( $calls ): void {
				$calls['notify'] = array_merge( $calls['notify'], array( array( $lesson_id, $status ) ) );
			},
			static function ( int $lesson_id ) use ( $calls ): void {
				$calls['on_saved'] = array_merge( $calls['on_saved'], array( $lesson_id ) );
			}
		);

		return array( $service, $calls );
	}

	/**
	 * A valid submission produces no errors.
	 */
	public function test_validate_accepts_valid_data(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate(
			array(
				'start_time' => '18:00',
				'end_time'   => '19:00',
				'status'     => Lesson_Service::STATUS_SCHEDULED,
				'note'       => '',
			)
		);

		$this->assertSame( array(), $errors );
	}

	/**
	 * end_time must be strictly after start_time.
	 */
	public function test_validate_rejects_end_time_not_after_start_time(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate(
			array(
				'start_time' => '19:00',
				'end_time'   => '18:00',
				'status'     => Lesson_Service::STATUS_SCHEDULED,
				'note'       => '',
			)
		);

		$this->assertSame( Lesson_Service::ERROR_END_TIME_BEFORE_START, $errors['end_time'] );
	}

	/**
	 * An unrecognised status is rejected.
	 */
	public function test_validate_rejects_invalid_status(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate(
			array(
				'start_time' => '18:00',
				'end_time'   => '19:00',
				'status'     => 'bogus',
				'note'       => '',
			)
		);

		$this->assertSame( Lesson_Service::ERROR_STATUS_INVALID, $errors['status'] );
	}

	/**
	 * `save()` fires the notify hook when the checkbox is set and the new
	 * status is cancelled.
	 */
	public function test_save_notifies_on_cancel_when_requested(): void {
		list( $service, $calls ) = $this->make_service();

		$service->save(
			10,
			array(
				'start_time' => '18:00',
				'end_time'   => '19:00',
				'status'     => Lesson_Service::STATUS_CANCELLED,
				'note'       => 'Holiday',
			),
			true
		);

		$this->assertSame( array( array( 10, Lesson_Service::STATUS_CANCELLED ) ), $calls['notify'] );
	}

	/**
	 * `save()` never fires the notify hook when the checkbox is unset, even
	 * for a cancel.
	 */
	public function test_save_does_not_notify_when_not_requested(): void {
		list( $service, $calls ) = $this->make_service();

		$service->save(
			10,
			array(
				'start_time' => '18:00',
				'end_time'   => '19:00',
				'status'     => Lesson_Service::STATUS_CANCELLED,
				'note'       => '',
			),
			false
		);

		$this->assertSame( array(), $calls['notify'] );
	}

	/**
	 * `save()` never fires the notify hook for a plain reschedule back to
	 * scheduled, even if the checkbox was left checked — there is nothing to
	 * notify enrollees about ("your class is back on" is not the same signal
	 * as a cancellation and would be a confusing default to auto-send).
	 */
	public function test_save_does_not_notify_for_scheduled_status(): void {
		list( $service, $calls ) = $this->make_service();

		$service->save(
			10,
			array(
				'start_time' => '18:00',
				'end_time'   => '19:00',
				'status'     => Lesson_Service::STATUS_SCHEDULED,
				'note'       => '',
			),
			true
		);

		$this->assertSame( array(), $calls['notify'] );
	}

	/**
	 * `save()` always writes the row via the injected update callable,
	 * mapping a blank note to null.
	 */
	public function test_save_writes_row_and_nulls_blank_note(): void {
		list( $service, $calls ) = $this->make_service();

		$service->save(
			10,
			array(
				'start_time' => '18:00',
				'end_time'   => '19:00',
				'status'     => Lesson_Service::STATUS_SCHEDULED,
				'note'       => '   ',
			),
			false
		);

		$this->assertSame(
			array(
				array(
					10,
					array(
						'start_time' => '18:00',
						'end_time'   => '19:00',
						'status'     => Lesson_Service::STATUS_SCHEDULED,
						'note'       => null,
					),
				),
			),
			$calls['update']
		);
	}

	/**
	 * `save()` fires `on_saved` (M10's cache-invalidation hook) on every
	 * save, unlike `notify` — regardless of the notify checkbox or the new
	 * status, since any change to a lesson row must invalidate the public
	 * calendar's cached REST response.
	 */
	public function test_save_always_fires_on_saved(): void {
		list( $service, $calls ) = $this->make_service();

		$service->save(
			10,
			array(
				'start_time' => '18:00',
				'end_time'   => '19:00',
				'status'     => Lesson_Service::STATUS_SCHEDULED,
				'note'       => '',
			),
			false
		);

		$this->assertSame( array( 10 ), $calls['on_saved'] );
	}
}
