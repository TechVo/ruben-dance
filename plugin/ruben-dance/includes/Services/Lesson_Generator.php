<?php
/**
 * Pure lesson-date generation logic for a course term.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Lesson_Generator.
 *
 * Deliberately pure PHP with zero WordPress touchpoints (no `$wpdb`, no
 * `current_time()`, nothing) so the date-generation rules — the trickiest,
 * most bug-prone part of this milestone — are unit-testable directly, the
 * same way `Schema_Upgrader` and `Location_Service` keep their decision logic
 * WordPress-agnostic. `Term_Service` is the only caller and owns turning the
 * plan this class returns into actual database writes.
 *
 * Date arithmetic is done entirely in UTC (see `expected_dates()`), even
 * though the studio itself is in the `Europe/Prague` timezone: the lessons
 * table stores `lesson_date` and `start_time`/`end_time` as separate columns,
 * and the spec is explicit that lesson times are wall-clock (a Monday 18:00
 * class is at 18:00 local time every week, DST notwithstanding). Iterating
 * calendar dates in a fixed, DST-free timezone is what makes that guarantee
 * hold: a `+7 days` step in a DST-observing timezone can drift by an hour
 * exactly on the transition week, which is harmless for a bare date but is
 * the kind of thing that quietly corrupts date math if a time ever gets
 * attached to the same `DateTime` instance later. Keeping this class
 * date-only and UTC-only sidesteps the question entirely.
 */
class Lesson_Generator {

	const TYPE_COURSE   = 'course';
	const TYPE_WORKSHOP = 'workshop';

	const STATUS_SCHEDULED = 'scheduled';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_MOVED     = 'moved';

	/**
	 * Lesson statuses that represent a deliberate admin decision (as opposed
	 * to the generator's own default), and are therefore preserved even when
	 * a term edit (e.g. shrinking `date_to`) removes their date from the
	 * newly expected set. Losing a cancellation because someone shortened the
	 * term afterwards would silently un-cancel a class no one told the
	 * customers wasn't happening.
	 *
	 * @var string[]
	 */
	const PRESERVED_STATUSES = array( self::STATUS_CANCELLED, self::STATUS_MOVED );

	/**
	 * Compute the insert/keep/delete plan to bring `wp_rd_lesson` rows for a
	 * term in line with its current `weekday`/`date_from`/`date_to` (or, for
	 * a workshop, its single date).
	 *
	 * Matching between the newly expected dates and already-existing lesson
	 * rows is done purely by `lesson_date`: an existing row on an expected
	 * date is left untouched (whatever time/status/note it already carries
	 * survives, satisfying "regeneration preserves manually edited/cancelled
	 * lessons"); an expected date with no existing row gets a new row using
	 * the term's current start/end time; an existing row whose date is no
	 * longer expected is deleted, unless its status is one of
	 * `self::PRESERVED_STATUSES`, in which case it is kept as a historical
	 * record instead of being silently destroyed.
	 *
	 * @param array<string, mixed>             $term             Term fields relevant to date generation:
	 *                                                            type, weekday, date_from, date_to, start_time, end_time.
	 * @param array<int, array<string, mixed>> $existing_lessons Current `wp_rd_lesson` rows for this term,
	 *                                                each with id, lesson_date, start_time, end_time, status, note.
	 * @return array<string, mixed> insert (rows to create), keep_ids (untouched lesson IDs),
	 *                              delete_ids (lesson IDs to remove).
	 */
	public function plan( array $term, array $existing_lessons ): array {
		$expected_dates = $this->expected_dates( $term );

		$existing_by_date = array();
		foreach ( $existing_lessons as $lesson ) {
			$existing_by_date[ (string) $lesson['lesson_date'] ] = $lesson;
		}

		$insert          = array();
		$keep_ids        = array();
		$expected_lookup = array_fill_keys( $expected_dates, true );

		foreach ( $expected_dates as $date ) {
			if ( isset( $existing_by_date[ $date ] ) ) {
				$keep_ids[] = (int) $existing_by_date[ $date ]['id'];
				continue;
			}

			$insert[] = array(
				'lesson_date' => $date,
				'start_time'  => (string) $term['start_time'],
				'end_time'    => (string) $term['end_time'],
				'status'      => self::STATUS_SCHEDULED,
				'note'        => null,
			);
		}

		$delete_ids = array();
		foreach ( $existing_lessons as $lesson ) {
			$date = (string) $lesson['lesson_date'];

			if ( isset( $expected_lookup[ $date ] ) ) {
				continue; // Already accounted for as a kept row above.
			}

			if ( in_array( (string) $lesson['status'], self::PRESERVED_STATUSES, true ) ) {
				$keep_ids[] = (int) $lesson['id'];
				continue;
			}

			$delete_ids[] = (int) $lesson['id'];
		}

		return array(
			'insert'     => $insert,
			'keep_ids'   => $keep_ids,
			'delete_ids' => $delete_ids,
		);
	}

	/**
	 * Every date a term's lessons should exist on, in ascending order.
	 *
	 * @param array{type: string, weekday: int, date_from: string, date_to: string} $term Term fields.
	 * @return string[] `Y-m-d` dates.
	 */
	private function expected_dates( array $term ): array {
		$date_from = (string) $term['date_from'];

		if ( self::TYPE_WORKSHOP === (string) $term['type'] ) {
			// Spec §3.2: a workshop always has date_from = date_to and
			// produces exactly one lesson, regardless of `weekday`.
			return $this->is_valid_date( $date_from ) ? array( $date_from ) : array();
		}

		$date_to = (string) $term['date_to'];
		$weekday = (int) $term['weekday'];

		if ( ! $this->is_valid_date( $date_from ) || ! $this->is_valid_date( $date_to ) || $weekday < 1 || $weekday > 7 ) {
			return array();
		}

		$utc    = new \DateTimeZone( 'UTC' );
		$cursor = new \DateTimeImmutable( $date_from . ' 00:00:00', $utc );
		$end    = new \DateTimeImmutable( $date_to . ' 00:00:00', $utc );

		if ( $cursor > $end ) {
			return array();
		}

		// Advance to the first occurrence of the target weekday on or after date_from.
		$offset = $weekday - (int) $cursor->format( 'N' );
		if ( $offset < 0 ) {
			$offset += 7;
		}
		$cursor = $cursor->modify( "+{$offset} days" );

		$dates = array();
		while ( $cursor <= $end ) {
			$dates[] = $cursor->format( 'Y-m-d' );
			$cursor  = $cursor->modify( '+7 days' );
		}

		return $dates;
	}

	/**
	 * Whether a string is a real calendar date in `Y-m-d` form (rejects
	 * malformed input and impossible dates like `2025-02-30`).
	 *
	 * @param string $date Candidate date string.
	 * @return bool
	 */
	private function is_valid_date( string $date ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );

		return checkdate( $month, $day, $year );
	}
}
