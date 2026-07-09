<?php
/**
 * Business rules for `wp_rd_course_term`: field validation, save, duplicate,
 * and keeping `wp_rd_lesson` in sync via `Lesson_Generator`.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Post_Types;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Lesson_Repository;
use RubenDance\Repositories\Location_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Term_Service.
 *
 * Kept WordPress-agnostic the same way `Location_Service` is: every database
 * and clock touchpoint is an injected callable (see `create_default()` for
 * the real wiring), so `validate()`, `row()` and the save/duplicate
 * orchestration are unit-testable with plain PHPUnit and fakes. The one piece
 * of real domain logic beyond plain CRUD — computing which lesson rows to
 * insert/keep/delete on every save — is delegated entirely to
 * `Lesson_Generator`, which this class never second-guesses.
 */
class Term_Service {

	const TYPE_COURSE   = 'course';
	const TYPE_WORKSHOP = 'workshop';

	const STATUS_DRAFT     = 'draft';
	const STATUS_OPEN      = 'open';
	const STATUS_CLOSED    = 'closed';
	const STATUS_CANCELLED = 'cancelled';

	const STATUSES = array( self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_CANCELLED );

	const ERROR_COURSE_REQUIRED          = 'course_required';
	const ERROR_COURSE_INVALID           = 'course_invalid';
	const ERROR_LOCATION_REQUIRED        = 'location_required';
	const ERROR_LOCATION_INVALID         = 'location_invalid';
	const ERROR_TYPE_INVALID             = 'type_invalid';
	const ERROR_WEEKDAY_INVALID          = 'weekday_invalid';
	const ERROR_START_TIME_INVALID       = 'start_time_invalid';
	const ERROR_END_TIME_INVALID         = 'end_time_invalid';
	const ERROR_END_TIME_BEFORE_START    = 'end_time_before_start';
	const ERROR_DATE_FROM_INVALID        = 'date_from_invalid';
	const ERROR_DATE_TO_INVALID          = 'date_to_invalid';
	const ERROR_DATE_TO_BEFORE_FROM      = 'date_to_before_from';
	const ERROR_INSTRUCTOR_REQUIRED      = 'instructor_required';
	const ERROR_INSTRUCTOR_TOO_LONG      = 'instructor_too_long';
	const ERROR_CAPACITY_INVALID         = 'capacity_invalid';
	const ERROR_PRICE_INVALID            = 'price_invalid';
	const ERROR_DISCOUNT_EARLY_INVALID   = 'discount_early_invalid';
	const ERROR_EARLY_UNTIL_INVALID      = 'early_until_invalid';
	const ERROR_EARLY_UNTIL_REQUIRED     = 'early_until_required';
	const ERROR_DISCOUNT_PAIR_INVALID    = 'discount_pair_invalid';
	const ERROR_STATUS_INVALID           = 'status_invalid';
	const ERROR_SEASON_LABEL_CS_REQUIRED = 'season_label_cs_required';
	const ERROR_SEASON_LABEL_CS_TOO_LONG = 'season_label_cs_too_long';
	const ERROR_SEASON_LABEL_EN_TOO_LONG = 'season_label_en_too_long';

	/**
	 * Whether a course post ID is a real, existing `rd_course` post:
	 * function( int $course_id ): bool.
	 *
	 * @var callable
	 */
	private $course_exists;

	/**
	 * Whether a location ID is a real, existing location row (active or not
	 * — an edit form must still accept a term's already-selected, since-
	 * deactivated location): function( int $location_id ): bool.
	 *
	 * @var callable
	 */
	private $location_exists;

	/**
	 * Finds a term row by ID, or null: function( int $id ): ?array.
	 *
	 * @var callable
	 */
	private $find_term;

	/**
	 * Inserts a new term row, returns its ID: function( array $data ): int.
	 *
	 * @var callable
	 */
	private $insert_term;

	/**
	 * Updates a term row by ID: function( int $id, array $data ): bool.
	 *
	 * @var callable
	 */
	private $update_term;

	/**
	 * All existing lessons for a term: function( int $term_id ): array.
	 *
	 * @var callable
	 */
	private $existing_lessons;

	/**
	 * Bulk-inserts lesson rows for a term: function( int $term_id, array $rows ): void.
	 *
	 * @var callable
	 */
	private $insert_lessons;

	/**
	 * Bulk-deletes lesson rows by ID: function( int[] $ids ): void.
	 *
	 * @var callable
	 */
	private $delete_lessons;

	/**
	 * Current datetime in `Y-m-d H:i:s` form, for created_at/updated_at:
	 * function(): string.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * The date-generation logic this service delegates to on every save.
	 *
	 * @var Lesson_Generator
	 */
	private Lesson_Generator $generator;

	/**
	 * Constructor.
	 *
	 * @param callable         $course_exists    function( int $course_id ): bool.
	 * @param callable         $location_exists  function( int $location_id ): bool.
	 * @param callable         $find_term        function( int $id ): ?array.
	 * @param callable         $insert_term      function( array $data ): int.
	 * @param callable         $update_term      function( int $id, array $data ): bool.
	 * @param callable         $existing_lessons function( int $term_id ): array.
	 * @param callable         $insert_lessons   function( int $term_id, array $rows ): void.
	 * @param callable         $delete_lessons   function( int[] $ids ): void.
	 * @param callable         $now              function(): string.
	 * @param Lesson_Generator $generator        Date-generation logic.
	 */
	public function __construct(
		callable $course_exists,
		callable $location_exists,
		callable $find_term,
		callable $insert_term,
		callable $update_term,
		callable $existing_lessons,
		callable $insert_lessons,
		callable $delete_lessons,
		callable $now,
		Lesson_Generator $generator
	) {
		$this->course_exists    = $course_exists;
		$this->location_exists  = $location_exists;
		$this->find_term        = $find_term;
		$this->insert_term      = $insert_term;
		$this->update_term      = $update_term;
		$this->existing_lessons = $existing_lessons;
		$this->insert_lessons   = $insert_lessons;
		$this->delete_lessons   = $delete_lessons;
		$this->now              = $now;
		$this->generator        = $generator;
	}

	/**
	 * Wire the service to the real repositories and WordPress clock.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		$terms     = new Course_Term_Repository();
		$lessons   = new Lesson_Repository();
		$locations = new Location_Repository();

		return new self(
			static function ( int $course_id ): bool {
				return $course_id > 0 && Post_Types::COURSE === get_post_type( $course_id );
			},
			static function ( int $location_id ) use ( $locations ): bool {
				return null !== $locations->find( $location_id );
			},
			static function ( int $id ) use ( $terms ): ?array {
				return $terms->find( $id );
			},
			static function ( array $data ) use ( $terms ): int {
				return $terms->insert( $data );
			},
			static function ( int $id, array $data ) use ( $terms ): bool {
				return $terms->update( $id, $data );
			},
			static function ( int $term_id ) use ( $lessons ): array {
				return $lessons->all_for_term( $term_id );
			},
			static function ( int $term_id, array $rows ) use ( $lessons ): void {
				$lessons->insert_many( $term_id, $rows );
			},
			static function ( array $ids ) use ( $lessons ): void {
				$lessons->delete_many( $ids );
			},
			static function (): string {
				return current_time( 'mysql' );
			},
			new Lesson_Generator()
		);
	}

	/**
	 * Validate submitted field values.
	 *
	 * @param array<string, mixed> $data Raw (unslashed) field values.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public function validate( array $data ): array {
		$errors = array();

		$course_id       = isset( $data['course_id'] ) ? (int) $data['course_id'] : 0;
		$location_id     = isset( $data['location_id'] ) ? (int) $data['location_id'] : 0;
		$type            = isset( $data['type'] ) ? (string) $data['type'] : '';
		$weekday         = isset( $data['weekday'] ) ? (int) $data['weekday'] : 0;
		$start_time      = trim( (string) ( $data['start_time'] ?? '' ) );
		$end_time        = trim( (string) ( $data['end_time'] ?? '' ) );
		$date_from       = trim( (string) ( $data['date_from'] ?? '' ) );
		$date_to         = trim( (string) ( $data['date_to'] ?? '' ) );
		$instructor      = trim( (string) ( $data['instructor'] ?? '' ) );
		$capacity        = trim( (string) ( $data['capacity'] ?? '' ) );
		$price           = trim( (string) ( $data['price'] ?? '' ) );
		$discount_early  = trim( (string) ( $data['discount_early'] ?? '' ) );
		$early_until     = trim( (string) ( $data['early_until'] ?? '' ) );
		$discount_pair   = trim( (string) ( $data['discount_pair'] ?? '' ) );
		$status          = isset( $data['status'] ) ? (string) $data['status'] : '';
		$season_label_cs = trim( (string) ( $data['season_label_cs'] ?? '' ) );
		$season_label_en = trim( (string) ( $data['season_label_en'] ?? '' ) );

		if ( $course_id <= 0 ) {
			$errors['course_id'] = self::ERROR_COURSE_REQUIRED;
		} elseif ( ! ( $this->course_exists )( $course_id ) ) {
			$errors['course_id'] = self::ERROR_COURSE_INVALID;
		}

		if ( $location_id <= 0 ) {
			$errors['location_id'] = self::ERROR_LOCATION_REQUIRED;
		} elseif ( ! ( $this->location_exists )( $location_id ) ) {
			$errors['location_id'] = self::ERROR_LOCATION_INVALID;
		}

		$valid_type = in_array( $type, array( self::TYPE_COURSE, self::TYPE_WORKSHOP ), true );
		if ( ! $valid_type ) {
			$errors['type'] = self::ERROR_TYPE_INVALID;
			$type           = self::TYPE_COURSE; // Fall through with a safe default so later checks stay meaningful.
		}

		// A workshop's weekday is derived from date_from (see row()), not
		// submitted, so only a course term needs an explicit valid weekday.
		if ( self::TYPE_COURSE === $type && ( $weekday < 1 || $weekday > 7 ) ) {
			$errors['weekday'] = self::ERROR_WEEKDAY_INVALID;
		}

		$start_time_valid = $this->is_valid_time( $start_time );
		if ( ! $start_time_valid ) {
			$errors['start_time'] = self::ERROR_START_TIME_INVALID;
		}

		if ( ! $this->is_valid_time( $end_time ) ) {
			$errors['end_time'] = self::ERROR_END_TIME_INVALID;
		} elseif ( $start_time_valid && $end_time <= $start_time ) {
			$errors['end_time'] = self::ERROR_END_TIME_BEFORE_START;
		}

		$date_from_valid = $this->is_valid_date( $date_from );
		if ( ! $date_from_valid ) {
			$errors['date_from'] = self::ERROR_DATE_FROM_INVALID;
		}

		// A workshop's date_to is forced to date_from server-side (see
		// row()), so it needs no independent validation here.
		if ( self::TYPE_WORKSHOP !== $type ) {
			if ( ! $this->is_valid_date( $date_to ) ) {
				$errors['date_to'] = self::ERROR_DATE_TO_INVALID;
			} elseif ( $date_from_valid && $date_to < $date_from ) {
				$errors['date_to'] = self::ERROR_DATE_TO_BEFORE_FROM;
			}
		}

		if ( '' === $instructor ) {
			$errors['instructor'] = self::ERROR_INSTRUCTOR_REQUIRED;
		} elseif ( strlen( $instructor ) > 190 ) {
			$errors['instructor'] = self::ERROR_INSTRUCTOR_TOO_LONG;
		}

		if ( '' !== $capacity && ( ! ctype_digit( $capacity ) || (int) $capacity <= 0 ) ) {
			$errors['capacity'] = self::ERROR_CAPACITY_INVALID;
		}

		if ( ! $this->is_valid_amount( $price ) ) {
			$errors['price'] = self::ERROR_PRICE_INVALID;
		}

		$has_discount_early = '' !== $discount_early;
		$has_early_until    = '' !== $early_until;

		if ( $has_discount_early && ! $this->is_valid_amount( $discount_early ) ) {
			$errors['discount_early'] = self::ERROR_DISCOUNT_EARLY_INVALID;
		}

		if ( $has_early_until && ! $this->is_valid_date( $early_until ) ) {
			$errors['early_until'] = self::ERROR_EARLY_UNTIL_INVALID;
		} elseif ( $has_discount_early !== $has_early_until ) {
			// The discount amount and its deadline only mean something
			// together (spec §3.2: "discount_early ... when enrolling on/
			// before early_until"); accept both present or both absent, and
			// reject exactly one of them being set.
			$errors['early_until'] = self::ERROR_EARLY_UNTIL_REQUIRED;
		}

		if ( '' !== $discount_pair && ! $this->is_valid_amount( $discount_pair ) ) {
			$errors['discount_pair'] = self::ERROR_DISCOUNT_PAIR_INVALID;
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$errors['status'] = self::ERROR_STATUS_INVALID;
		}

		if ( '' === $season_label_cs ) {
			$errors['season_label_cs'] = self::ERROR_SEASON_LABEL_CS_REQUIRED;
		} elseif ( strlen( $season_label_cs ) > 100 ) {
			$errors['season_label_cs'] = self::ERROR_SEASON_LABEL_CS_TOO_LONG;
		}

		if ( strlen( $season_label_en ) > 100 ) {
			$errors['season_label_en'] = self::ERROR_SEASON_LABEL_EN_TOO_LONG;
		}

		return $errors;
	}

	/**
	 * Create a new term and generate its lessons. Caller must call
	 * `validate()` first and only proceed when it returns an empty array.
	 *
	 * @param array<string, mixed> $data Field values, same shape as `validate()`.
	 * @return int New term ID.
	 */
	public function create( array $data ): int {
		$now               = ( $this->now )();
		$row               = $this->row( $data );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$term_id = ( $this->insert_term )( $row );

		$this->regenerate_lessons( $term_id, $row );

		return $term_id;
	}

	/**
	 * Update an existing term's editable fields and regenerate its lessons.
	 * Caller must call `validate()` first and only proceed when it returns an
	 * empty array.
	 *
	 * @param int                  $id   Term ID.
	 * @param array<string, mixed> $data Field values, same shape as `validate()`.
	 * @return bool
	 */
	public function update_details( int $id, array $data ): bool {
		$row               = $this->row( $data );
		$row['updated_at'] = ( $this->now )();

		$updated = ( $this->update_term )( $id, $row );

		$this->regenerate_lessons( $id, $row );

		return $updated;
	}

	/**
	 * Duplicate a term verbatim (F9: "copy Autumn 2026 → Spring 2027") as an
	 * editable draft, and generate lessons for the copy using the same dates
	 * — the admin edits `date_from`/`date_to` afterwards, and saving again
	 * regenerates lessons for whatever range they land on.
	 *
	 * @param int $id Term ID to duplicate.
	 * @return int|null New term ID, or null if the source term doesn't exist.
	 */
	public function duplicate( int $id ): ?int {
		$source = ( $this->find_term )( $id );

		if ( null === $source ) {
			return null;
		}

		$now = ( $this->now )();

		$row = $source;
		unset( $row['id'] );
		$row['status']     = self::STATUS_DRAFT;
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$new_id = ( $this->insert_term )( $row );

		$this->regenerate_lessons( $new_id, $row );

		return $new_id;
	}

	/**
	 * Map validated field values to storage-ready column values.
	 *
	 * A workshop's `date_to` and `weekday` are always derived here rather
	 * than trusted from the submission (spec §3.2: "workshop ... date_from =
	 * date_to"), so that invariant holds even if the form's client-side
	 * disabling of those fields is bypassed.
	 *
	 * @param array<string, mixed> $data Field values, same shape as `validate()`.
	 * @return array<string, mixed>
	 */
	public function row( array $data ): array {
		$type      = in_array( $data['type'] ?? '', array( self::TYPE_COURSE, self::TYPE_WORKSHOP ), true )
			? (string) $data['type']
			: self::TYPE_COURSE;
		$date_from = trim( (string) ( $data['date_from'] ?? '' ) );

		if ( self::TYPE_WORKSHOP === $type ) {
			$date_to = $date_from;
			$weekday = $this->is_valid_date( $date_from )
				? (int) ( new \DateTimeImmutable( $date_from . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->format( 'N' )
				: 1;
		} else {
			$date_to = trim( (string) ( $data['date_to'] ?? '' ) );
			$weekday = (int) ( $data['weekday'] ?? 0 );
		}

		$capacity       = trim( (string) ( $data['capacity'] ?? '' ) );
		$discount_early = trim( (string) ( $data['discount_early'] ?? '' ) );
		$early_until    = trim( (string) ( $data['early_until'] ?? '' ) );
		$discount_pair  = trim( (string) ( $data['discount_pair'] ?? '' ) );
		$note_public_cs = trim( (string) ( $data['note_public_cs'] ?? '' ) );
		$note_public_en = trim( (string) ( $data['note_public_en'] ?? '' ) );
		$status         = in_array( $data['status'] ?? '', self::STATUSES, true ) ? (string) $data['status'] : self::STATUS_DRAFT;

		return array(
			'course_id'       => (int) ( $data['course_id'] ?? 0 ),
			'location_id'     => (int) ( $data['location_id'] ?? 0 ),
			'type'            => $type,
			'season_label_cs' => trim( (string) ( $data['season_label_cs'] ?? '' ) ),
			'season_label_en' => trim( (string) ( $data['season_label_en'] ?? '' ) ),
			'weekday'         => $weekday,
			'start_time'      => trim( (string) ( $data['start_time'] ?? '' ) ),
			'end_time'        => trim( (string) ( $data['end_time'] ?? '' ) ),
			'date_from'       => $date_from,
			'date_to'         => $date_to,
			'instructor'      => trim( (string) ( $data['instructor'] ?? '' ) ),
			'capacity'        => '' === $capacity ? null : (int) $capacity,
			'price'           => $this->format_amount( (string) ( $data['price'] ?? '0' ) ),
			'discount_early'  => '' === $discount_early ? null : $this->format_amount( $discount_early ),
			'early_until'     => '' === $early_until ? null : $early_until,
			'discount_pair'   => '' === $discount_pair ? null : $this->format_amount( $discount_pair ),
			'status'          => $status,
			'note_public_cs'  => '' === $note_public_cs ? null : $note_public_cs,
			'note_public_en'  => '' === $note_public_en ? null : $note_public_en,
		);
	}

	/**
	 * Recompute the insert/keep/delete lesson plan for a term and apply it.
	 *
	 * @param int                  $term_id Term ID.
	 * @param array<string, mixed> $row     Storage-ready term row (from `row()` or a duplicated source row).
	 */
	private function regenerate_lessons( int $term_id, array $row ): void {
		$existing = ( $this->existing_lessons )( $term_id );
		$plan     = $this->generator->plan( $row, $existing );

		if ( array() !== $plan['insert'] ) {
			( $this->insert_lessons )( $term_id, $plan['insert'] );
		}

		if ( array() !== $plan['delete_ids'] ) {
			( $this->delete_lessons )( $plan['delete_ids'] );
		}
	}

	/**
	 * Whether a string is `HH:MM` or `HH:MM:SS`, 24-hour.
	 *
	 * @param string $time Candidate time string.
	 * @return bool
	 */
	private function is_valid_time( string $time ): bool {
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time );
	}

	/**
	 * Whether a string is a real calendar date in `Y-m-d` form.
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

	/**
	 * Whether a string is a non-negative decimal amount.
	 *
	 * @param string $amount Candidate amount string.
	 * @return bool
	 */
	private function is_valid_amount( string $amount ): bool {
		return '' !== $amount && 1 === preg_match( '/^\d+(\.\d{1,2})?$/', $amount );
	}

	/**
	 * Normalize a validated amount string to a fixed two-decimal string
	 * suitable for a DECIMAL(10,2) column.
	 *
	 * @param string $amount Validated amount string.
	 * @return string
	 */
	private function format_amount( string $amount ): string {
		return '' === $amount ? '0.00' : number_format( (float) $amount, 2, '.', '' );
	}
}
