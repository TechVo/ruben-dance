<?php
/**
 * Business rules for editing a single `wp_rd_lesson` row: cancel, change
 * time, add a note (F10).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Repositories\Lesson_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Lesson_Service.
 *
 * Kept WordPress-agnostic the same way `Location_Service` and `Term_Service`
 * are: the row update and the "notify enrollees" side effect are both
 * injected callables (see `create_default()`), so `validate()` and the
 * notify-trigger decision in `save()` are unit-testable with plain PHPUnit.
 *
 * The notify side of this is deliberately a stub for this milestone (spec
 * F10: "cancelling a lesson can trigger an email to enrolled customers,
 * admin-confirmed send"; the implementation plan explicitly defers the real
 * mailer to M13). `create_default()` wires the notify callable to a plain
 * `do_action()` on `self::NOTIFY_HOOK` with no listener attached yet, so the
 * admin-facing "notify enrollees" checkbox already works end-to-end (the
 * hook fires with the right arguments) — M13 only has to add a handler.
 */
class Lesson_Service {

	const STATUS_SCHEDULED = 'scheduled';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_MOVED     = 'moved';

	const STATUSES = array( self::STATUS_SCHEDULED, self::STATUS_CANCELLED, self::STATUS_MOVED );

	/**
	 * Statuses for which "notify enrollees" is meaningful — a plain
	 * re-schedule back to `scheduled` (undoing a mistake) has nothing to
	 * notify anyone about.
	 *
	 * @var string[]
	 */
	const NOTIFIABLE_STATUSES = array( self::STATUS_CANCELLED, self::STATUS_MOVED );

	const ERROR_START_TIME_INVALID    = 'start_time_invalid';
	const ERROR_END_TIME_INVALID      = 'end_time_invalid';
	const ERROR_END_TIME_BEFORE_START = 'end_time_before_start';
	const ERROR_STATUS_INVALID        = 'status_invalid';
	const ERROR_NOTE_TOO_LONG         = 'note_too_long';

	/**
	 * Action hook fired when a lesson's status/time/note is saved with the
	 * "notify enrollees" checkbox ticked and the new status warrants it. No
	 * listener is attached in this milestone — M13 adds the real mailer.
	 *
	 * @var string
	 */
	const NOTIFY_HOOK = 'rd_lesson_changed_notify';

	/**
	 * Updates a lesson row by ID: function( int $id, array $data ): bool.
	 *
	 * @var callable
	 */
	private $update_row;

	/**
	 * Fires the (stubbed) enrollee notification: function( int $lesson_id, string $status ): void.
	 *
	 * @var callable
	 */
	private $notify;

	/**
	 * Constructor.
	 *
	 * @param callable $update_row function( int $id, array $data ): bool.
	 * @param callable $notify     function( int $lesson_id, string $status ): void.
	 */
	public function __construct( callable $update_row, callable $notify ) {
		$this->update_row = $update_row;
		$this->notify     = $notify;
	}

	/**
	 * Wire the service to the real repository and the stubbed notify hook.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		$lessons = new Lesson_Repository();

		return new self(
			static function ( int $id, array $data ) use ( $lessons ): bool {
				return $lessons->update( $id, $data );
			},
			static function ( int $lesson_id, string $status ): void {
				/**
				 * Fires when a lesson is cancelled/moved and the admin asked
				 * to notify enrollees. Stubbed in M05 — no listener yet; M13
				 * attaches the real email send (spec E5).
				 *
				 * @param int    $lesson_id Lesson ID.
				 * @param string $status    New lesson status ('cancelled'|'moved').
				 */
				do_action( self::NOTIFY_HOOK, $lesson_id, $status ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::NOTIFY_HOOK = 'rd_lesson_changed_notify', already correctly prefixed; the sniff can't resolve a class constant statically.
			}
		);
	}

	/**
	 * Validate submitted field values.
	 *
	 * @param array<string, mixed> $data Raw (unslashed) field values: start_time, end_time, status, note.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public function validate( array $data ): array {
		$errors = array();

		$start_time = trim( (string) ( $data['start_time'] ?? '' ) );
		$end_time   = trim( (string) ( $data['end_time'] ?? '' ) );
		$status     = isset( $data['status'] ) ? (string) $data['status'] : '';
		$note       = trim( (string) ( $data['note'] ?? '' ) );

		$start_time_valid = $this->is_valid_time( $start_time );
		if ( ! $start_time_valid ) {
			$errors['start_time'] = self::ERROR_START_TIME_INVALID;
		}

		if ( ! $this->is_valid_time( $end_time ) ) {
			$errors['end_time'] = self::ERROR_END_TIME_INVALID;
		} elseif ( $start_time_valid && $end_time <= $start_time ) {
			$errors['end_time'] = self::ERROR_END_TIME_BEFORE_START;
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$errors['status'] = self::ERROR_STATUS_INVALID;
		}

		if ( strlen( $note ) > 255 ) {
			$errors['note'] = self::ERROR_NOTE_TOO_LONG;
		}

		return $errors;
	}

	/**
	 * Save a lesson's time/status/note, optionally firing the (stubbed)
	 * enrollee notification. Caller must call `validate()` first and only
	 * proceed when it returns an empty array.
	 *
	 * @param int                  $lesson_id        Lesson ID.
	 * @param array<string, mixed> $data             Field values, same shape as `validate()`.
	 * @param bool                 $notify_enrollees Whether the admin asked to notify enrollees.
	 * @return bool
	 */
	public function save( int $lesson_id, array $data, bool $notify_enrollees ): bool {
		$row = $this->row( $data );

		$saved = ( $this->update_row )( $lesson_id, $row );

		if ( $notify_enrollees && in_array( $row['status'], self::NOTIFIABLE_STATUSES, true ) ) {
			( $this->notify )( $lesson_id, $row['status'] );
		}

		return $saved;
	}

	/**
	 * Map validated field values to storage-ready column values.
	 *
	 * @param array<string, mixed> $data Field values, same shape as `validate()`.
	 * @return array<string, mixed>
	 */
	private function row( array $data ): array {
		$note = trim( (string) ( $data['note'] ?? '' ) );

		return array(
			'start_time' => trim( (string) ( $data['start_time'] ?? '' ) ),
			'end_time'   => trim( (string) ( $data['end_time'] ?? '' ) ),
			'status'     => in_array( $data['status'] ?? '', self::STATUSES, true ) ? (string) $data['status'] : self::STATUS_SCHEDULED,
			'note'       => '' === $note ? null : $note,
		);
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
}
