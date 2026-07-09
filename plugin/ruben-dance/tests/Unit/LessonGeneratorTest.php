<?php
/**
 * Tests for the lesson-date generation and regeneration-preservation logic.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Lesson_Generator;

/**
 * Class LessonGeneratorTest.
 *
 * `Lesson_Generator` is pure PHP (no WordPress touchpoints at all), so the
 * date-range/regeneration/workshop/DST rules the milestone calls out as
 * "unit tests first" are all exercised here directly, no WordPress bootstrap
 * or fakes needed.
 */
class LessonGeneratorTest extends TestCase {

	/**
	 * A weekly course term produces one lesson per occurrence of `weekday`
	 * between `date_from` and `date_to`, inclusive on both ends.
	 */
	public function test_course_term_generates_one_lesson_per_weekday_occurrence(): void {
		$generator = new Lesson_Generator();

		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 1, // Monday.
				'date_from'  => '2025-09-01', // A Monday.
				'date_to'    => '2025-09-29', // Also a Monday.
				'start_time' => '18:00',
				'end_time'   => '19:00',
			),
			array()
		);

		$dates = array_column( $plan['insert'], 'lesson_date' );

		$this->assertSame(
			array( '2025-09-01', '2025-09-08', '2025-09-15', '2025-09-22', '2025-09-29' ),
			$dates
		);
		$this->assertSame( array(), $plan['keep_ids'] );
		$this->assertSame( array(), $plan['delete_ids'] );
	}

	/**
	 * A realistic Mon 18:00 Sep-Dec season produces the ~15 weekly lessons
	 * the milestone's acceptance criteria calls out by name.
	 */
	public function test_september_to_december_monday_term_produces_expected_lesson_count(): void {
		$generator = new Lesson_Generator();

		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 1,
				'date_from'  => '2025-09-01',
				'date_to'    => '2025-12-15',
				'start_time' => '18:00',
				'end_time'   => '19:30',
			),
			array()
		);

		// Mondays from 2025-09-01 through 2025-12-15 inclusive: 16 of them.
		$this->assertCount( 16, $plan['insert'] );
		$this->assertSame( '2025-09-01', $plan['insert'][0]['lesson_date'] );
		$this->assertSame( '2025-12-15', $plan['insert'][ count( $plan['insert'] ) - 1 ]['lesson_date'] );

		foreach ( $plan['insert'] as $lesson ) {
			$this->assertSame( '18:00', $lesson['start_time'] );
			$this->assertSame( '19:30', $lesson['end_time'] );
			$this->assertSame( Lesson_Generator::STATUS_SCHEDULED, $lesson['status'] );
			$this->assertNull( $lesson['note'] );
		}
	}

	/**
	 * `date_from` need not itself land on `weekday`: generation starts at the
	 * first matching weekday on or after it.
	 */
	public function test_first_occurrence_is_found_when_date_from_is_not_the_target_weekday(): void {
		$generator = new Lesson_Generator();

		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 3, // Wednesday.
				'date_from'  => '2025-09-01', // A Monday.
				'date_to'    => '2025-09-10',
				'start_time' => '18:00',
				'end_time'   => '19:00',
			),
			array()
		);

		$this->assertSame(
			array( '2025-09-03', '2025-09-10' ),
			array_column( $plan['insert'], 'lesson_date' )
		);
	}

	/**
	 * A workshop (date_from === date_to) always produces exactly one lesson,
	 * regardless of `weekday`.
	 */
	public function test_workshop_produces_exactly_one_lesson(): void {
		$generator = new Lesson_Generator();

		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_WORKSHOP,
				'weekday'    => 6, // Deliberately mismatched: must not matter.
				'date_from'  => '2025-11-15',
				'date_to'    => '2025-11-15',
				'start_time' => '10:00',
				'end_time'   => '16:00',
			),
			array()
		);

		$this->assertCount( 1, $plan['insert'] );
		$this->assertSame( '2025-11-15', $plan['insert'][0]['lesson_date'] );
	}

	/**
	 * Regenerating over an unchanged date range with a manually edited lesson
	 * (different time than the term default) leaves that row alone — it is
	 * kept, not re-inserted with the term's current default time.
	 */
	public function test_regeneration_preserves_manually_edited_lesson(): void {
		$generator = new Lesson_Generator();

		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 1,
				'date_from'  => '2025-09-01',
				'date_to'    => '2025-09-15',
				'start_time' => '18:00',
				'end_time'   => '19:00',
			),
			array(
				array(
					'id'          => 101,
					'lesson_date' => '2025-09-08',
					'start_time'  => '19:00', // Edited: an hour later than the term default.
					'end_time'    => '20:00',
					'status'      => Lesson_Generator::STATUS_SCHEDULED,
					'note'        => null,
				),
			)
		);

		// Only the two dates without an existing row are (re)inserted.
		$this->assertSame(
			array( '2025-09-01', '2025-09-15' ),
			array_column( $plan['insert'], 'lesson_date' )
		);
		$this->assertSame( array( 101 ), $plan['keep_ids'] );
		$this->assertSame( array(), $plan['delete_ids'] );
	}

	/**
	 * Regenerating with a matching cancelled lesson keeps it cancelled — the
	 * generator never re-inserts or overwrites a row that already exists on
	 * an expected date, no matter its status.
	 */
	public function test_regeneration_preserves_cancelled_lesson_on_matching_date(): void {
		$generator = new Lesson_Generator();

		$existing = array(
			array(
				'id'          => 55,
				'lesson_date' => '2025-09-08',
				'start_time'  => '18:00',
				'end_time'    => '19:00',
				'status'      => Lesson_Generator::STATUS_CANCELLED,
				'note'        => 'State holiday — no class',
			),
		);

		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 1,
				'date_from'  => '2025-09-01',
				'date_to'    => '2025-09-15',
				'start_time' => '18:00',
				'end_time'   => '19:00',
			),
			$existing
		);

		$this->assertSame( array( 55 ), $plan['keep_ids'] );
		$this->assertSame( array(), $plan['delete_ids'] );
		$this->assertNotContains( '2025-09-08', array_column( $plan['insert'], 'lesson_date' ) );
	}

	/**
	 * Shrinking `date_to` so a date falls outside the new range deletes a
	 * plain scheduled lesson there (it's stale, the class is not happening),
	 * but a *cancelled* lesson on a now out-of-range date survives instead of
	 * being silently deleted — this is the milestone's explicit acceptance
	 * criterion: "editing the term's end date regenerates without touching a
	 * cancelled lesson".
	 */
	public function test_shrinking_date_range_deletes_stale_scheduled_lesson_but_keeps_cancelled_one(): void {
		$generator = new Lesson_Generator();

		$existing = array(
			array(
				'id'          => 1,
				'lesson_date' => '2025-09-01',
				'start_time'  => '18:00',
				'end_time'    => '19:00',
				'status'      => Lesson_Generator::STATUS_SCHEDULED,
				'note'        => null,
			),
			array(
				'id'          => 2,
				'lesson_date' => '2025-09-08',
				'start_time'  => '18:00',
				'end_time'    => '19:00',
				'status'      => Lesson_Generator::STATUS_CANCELLED,
				'note'        => 'Cancelled',
			),
			array(
				'id'          => 3,
				'lesson_date' => '2025-09-15',
				'start_time'  => '18:00',
				'end_time'    => '19:00',
				'status'      => Lesson_Generator::STATUS_SCHEDULED,
				'note'        => null,
			),
		);

		// Term end date shrunk from 2025-09-15 down to 2025-09-01: only the
		// first Monday is still in range.
		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 1,
				'date_from'  => '2025-09-01',
				'date_to'    => '2025-09-01',
				'start_time' => '18:00',
				'end_time'   => '19:00',
			),
			$existing
		);

		$this->assertSame( array(), $plan['insert'] );
		$this->assertContains( 1, $plan['keep_ids'] );
		$this->assertContains( 2, $plan['keep_ids'], 'the cancelled lesson must survive even though its date is now out of range' );
		$this->assertSame( array( 3 ), $plan['delete_ids'] );
	}

	/**
	 * An expected date with no existing row is inserted even when other
	 * dates already exist — the diff is per-date, not all-or-nothing.
	 */
	public function test_extending_date_range_only_inserts_the_new_dates(): void {
		$generator = new Lesson_Generator();

		$existing = array(
			array(
				'id'          => 1,
				'lesson_date' => '2025-09-01',
				'start_time'  => '18:00',
				'end_time'    => '19:00',
				'status'      => Lesson_Generator::STATUS_SCHEDULED,
				'note'        => null,
			),
		);

		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 1,
				'date_from'  => '2025-09-01',
				'date_to'    => '2025-09-15',
				'start_time' => '18:00',
				'end_time'   => '19:00',
			),
			$existing
		);

		$this->assertSame(
			array( '2025-09-08', '2025-09-15' ),
			array_column( $plan['insert'], 'lesson_date' )
		);
		$this->assertSame( array( 1 ), $plan['keep_ids'] );
		$this->assertSame( array(), $plan['delete_ids'] );
	}

	/**
	 * A term spanning the Czech spring-forward DST boundary (last Sunday of
	 * March) still produces one lesson per week with no skipped or duplicated
	 * date — date arithmetic is done in UTC precisely so this holds
	 * regardless of the site's local timezone DST rules.
	 */
	public function test_weekly_dates_are_unaffected_by_spring_dst_boundary(): void {
		$generator = new Lesson_Generator();

		// 2026-03-29 is the Czech spring-forward DST transition (Sunday).
		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 7, // Sunday.
				'date_from'  => '2026-03-01',
				'date_to'    => '2026-04-05',
				'start_time' => '10:00',
				'end_time'   => '11:00',
			),
			array()
		);

		$this->assertSame(
			array( '2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22', '2026-03-29', '2026-04-05' ),
			array_column( $plan['insert'], 'lesson_date' )
		);
	}

	/**
	 * Same guarantee across the autumn (fall-back) DST boundary (last Sunday
	 * of October).
	 */
	public function test_weekly_dates_are_unaffected_by_autumn_dst_boundary(): void {
		$generator = new Lesson_Generator();

		// 2026-10-25 is the Czech fall-back DST transition (Sunday).
		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 7,
				'date_from'  => '2026-10-11',
				'date_to'    => '2026-11-08',
				'start_time' => '10:00',
				'end_time'   => '11:00',
			),
			array()
		);

		$this->assertSame(
			array( '2026-10-11', '2026-10-18', '2026-10-25', '2026-11-01', '2026-11-08' ),
			array_column( $plan['insert'], 'lesson_date' )
		);
	}

	/**
	 * Times are copied verbatim as wall-clock strings — the generator never
	 * parses or re-derives them, so no DST shift can ever creep into a
	 * `start_time`/`end_time` value.
	 */
	public function test_times_are_carried_verbatim_as_wall_clock_strings(): void {
		$generator = new Lesson_Generator();

		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 7,
				'date_from'  => '2026-03-22',
				'date_to'    => '2026-03-29',
				'start_time' => '02:30', // Inside the "missing hour" on the transition date itself.
				'end_time'   => '03:30',
			),
			array()
		);

		foreach ( $plan['insert'] as $lesson ) {
			$this->assertSame( '02:30', $lesson['start_time'] );
			$this->assertSame( '03:30', $lesson['end_time'] );
		}
	}

	/**
	 * An invalid date range (`date_to` before `date_from`) yields an empty
	 * plan rather than an error or an infinite loop.
	 */
	public function test_date_to_before_date_from_yields_no_lessons(): void {
		$generator = new Lesson_Generator();

		$plan = $generator->plan(
			array(
				'type'       => Lesson_Generator::TYPE_COURSE,
				'weekday'    => 1,
				'date_from'  => '2025-09-15',
				'date_to'    => '2025-09-01',
				'start_time' => '18:00',
				'end_time'   => '19:00',
			),
			array()
		);

		$this->assertSame( array(), $plan['insert'] );
		$this->assertSame( array(), $plan['keep_ids'] );
		$this->assertSame( array(), $plan['delete_ids'] );
	}
}
