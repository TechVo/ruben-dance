<?php
/**
 * Business rules for `wp_rd_enrollment`: create-enrollment orchestration and
 * the status-transition state machine.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Duplicate_Key_Exception;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Enrollment_Service.
 *
 * Kept WordPress-agnostic the same way `Term_Service` is: every database and
 * clock touchpoint is an injected callable (see `create_default()` for the
 * real wiring), so the create-enrollment orchestration and the status state
 * machine — the highest-risk logic in the project (spec §3.2, F3, "computed
 * price ... never trust the price shown in the form") — are unit-testable
 * with plain PHPUnit and fakes. The actual money math and formatting are
 * delegated entirely to `Pricing_Service`, `Due_Date_Calculator` and
 * `Variable_Symbol_Generator`, which this class never second-guesses.
 */
class Enrollment_Service {

	const ROLE_SOLO     = 'solo';
	const ROLE_LEADER   = 'leader';
	const ROLE_FOLLOWER = 'follower';

	const ROLES = array( self::ROLE_SOLO, self::ROLE_LEADER, self::ROLE_FOLLOWER );

	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_PAID      = 'paid';
	const STATUS_CANCELLED = 'cancelled';

	const STATUSES = array( self::STATUS_CONFIRMED, self::STATUS_PAID, self::STATUS_CANCELLED );

	const PAYMENT_BANK_TRANSFER = 'bank_transfer';
	const PAYMENT_CASH          = 'cash';

	const PAYMENT_METHODS = array( self::PAYMENT_BANK_TRANSFER, self::PAYMENT_CASH );

	const ERROR_TERM_REQUIRED         = 'term_required';
	const ERROR_TERM_NOT_FOUND        = 'term_not_found';
	const ERROR_TERM_NOT_OPEN         = 'term_not_open';
	const ERROR_USER_REQUIRED         = 'user_required';
	const ERROR_PARTICIPANT_TOO_LONG  = 'participant_name_too_long';
	const ERROR_ROLE_INVALID          = 'role_invalid';
	const ERROR_PARTNER_NAME_TOO_LONG = 'partner_name_too_long';

	/**
	 * Finds a term row by ID, or null: function( int $term_id ): ?array.
	 *
	 * @var callable
	 */
	private $find_term;

	/**
	 * Finds an enrollment row by ID, or null: function( int $id ): ?array.
	 *
	 * @var callable
	 */
	private $find_enrollment;

	/**
	 * Count of active (non-cancelled) enrollments for a term:
	 * function( int $term_id ): int.
	 *
	 * @var callable
	 */
	private $count_active_enrollments;

	/**
	 * Inserts a new enrollment row, returns its ID: function( array $data ):
	 * int. Must throw `Repositories\Duplicate_Key_Exception` when the
	 * `(term_id, user_id, participant_name)` unique key rejects the row.
	 *
	 * @var callable
	 */
	private $insert_enrollment;

	/**
	 * Updates an enrollment row by ID: function( int $id, array $data ): bool.
	 *
	 * @var callable
	 */
	private $update_enrollment;

	/**
	 * The configured due-date window in days: function(): int.
	 *
	 * @var callable
	 */
	private $due_date_days;

	/**
	 * Current datetime in `Y-m-d H:i:s` form: function(): string.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Discount computation this service delegates to.
	 *
	 * @var Pricing_Service
	 */
	private Pricing_Service $pricing;

	/**
	 * Variable-symbol formatting this service delegates to.
	 *
	 * @var Variable_Symbol_Generator
	 */
	private Variable_Symbol_Generator $variable_symbols;

	/**
	 * Due-date arithmetic this service delegates to.
	 *
	 * @var Due_Date_Calculator
	 */
	private Due_Date_Calculator $due_dates;

	/**
	 * Constructor.
	 *
	 * @param callable                  $find_term                 function( int $term_id ): ?array.
	 * @param callable                  $find_enrollment           function( int $id ): ?array.
	 * @param callable                  $count_active_enrollments  function( int $term_id ): int.
	 * @param callable                  $insert_enrollment         function( array $data ): int.
	 * @param callable                  $update_enrollment         function( int $id, array $data ): bool.
	 * @param callable                  $due_date_days             function(): int.
	 * @param callable                  $now                       function(): string.
	 * @param Pricing_Service           $pricing                   Discount computation.
	 * @param Variable_Symbol_Generator $variable_symbols          Variable-symbol formatting.
	 * @param Due_Date_Calculator       $due_dates                 Due-date arithmetic.
	 */
	public function __construct(
		callable $find_term,
		callable $find_enrollment,
		callable $count_active_enrollments,
		callable $insert_enrollment,
		callable $update_enrollment,
		callable $due_date_days,
		callable $now,
		Pricing_Service $pricing,
		Variable_Symbol_Generator $variable_symbols,
		Due_Date_Calculator $due_dates
	) {
		$this->find_term                = $find_term;
		$this->find_enrollment          = $find_enrollment;
		$this->count_active_enrollments = $count_active_enrollments;
		$this->insert_enrollment        = $insert_enrollment;
		$this->update_enrollment        = $update_enrollment;
		$this->due_date_days            = $due_date_days;
		$this->now                      = $now;
		$this->pricing                  = $pricing;
		$this->variable_symbols         = $variable_symbols;
		$this->due_dates                = $due_dates;
	}

	/**
	 * Wire the service to the real repositories, settings and WordPress clock.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		$terms       = new Course_Term_Repository();
		$enrollments = new Enrollment_Repository();

		return new self(
			static function ( int $term_id ) use ( $terms ): ?array {
				return $terms->find( $term_id );
			},
			static function ( int $id ) use ( $enrollments ): ?array {
				return $enrollments->find( $id );
			},
			static function ( int $term_id ) use ( $enrollments ): int {
				return $enrollments->count_active_for_term( $term_id );
			},
			static function ( array $data ) use ( $enrollments ): int {
				return $enrollments->insert_unique( $data );
			},
			static function ( int $id, array $data ) use ( $enrollments ): bool {
				return $enrollments->update( $id, $data );
			},
			static function (): int {
				return Settings::due_date_days();
			},
			static function (): string {
				return current_time( 'mysql' );
			},
			new Pricing_Service(),
			new Variable_Symbol_Generator(),
			new Due_Date_Calculator()
		);
	}

	/**
	 * Validate submitted field values, including the term's existence and
	 * `open` status (spec F3: "Visitor clicks 'Enroll' on an open term").
	 *
	 * @param array<string, mixed> $data Raw field values: term_id, user_id, participant_name, role, partner_name, payment_method.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public function validate( array $data ): array {
		$errors = array();

		$term_id = isset( $data['term_id'] ) ? (int) $data['term_id'] : 0;

		if ( $term_id <= 0 ) {
			$errors['term_id'] = self::ERROR_TERM_REQUIRED;
		} else {
			$term = ( $this->find_term )( $term_id );

			if ( null === $term ) {
				$errors['term_id'] = self::ERROR_TERM_NOT_FOUND;
			} elseif ( 'open' !== (string) $term['status'] ) {
				$errors['term_id'] = self::ERROR_TERM_NOT_OPEN;
			}
		}

		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;

		if ( $user_id <= 0 ) {
			$errors['user_id'] = self::ERROR_USER_REQUIRED;
		}

		$participant_name = trim( (string) ( $data['participant_name'] ?? '' ) );

		if ( strlen( $participant_name ) > 190 ) {
			$errors['participant_name'] = self::ERROR_PARTICIPANT_TOO_LONG;
		}

		$role = (string) ( $data['role'] ?? self::ROLE_SOLO );

		if ( ! in_array( $role, self::ROLES, true ) ) {
			$errors['role'] = self::ERROR_ROLE_INVALID;
		}

		$partner_name = trim( (string) ( $data['partner_name'] ?? '' ) );

		if ( strlen( $partner_name ) > 190 ) {
			$errors['partner_name'] = self::ERROR_PARTNER_NAME_TOO_LONG;
		}

		return $errors;
	}

	/**
	 * Create a new enrollment. Caller must call `validate()` first and only
	 * proceed when it returns an empty array.
	 *
	 * Orchestration, in order: price via `Pricing_Service` (server-side only
	 * — spec §3.2/§8: "prices recomputed server-side, never trust the price
	 * shown in the form"), the `over_capacity` flag from the term's current
	 * active-enrollment count, the due date via `Due_Date_Calculator`, then
	 * the insert itself — which is the only point the DB's unique key can
	 * still reject a race-condition duplicate that slipped past `validate()`
	 * (spec §3.3). The `variable_symbol` needs the row's own auto-increment
	 * ID (spec §3.2: `{year}{enrollment_id}`), so it is written in a second
	 * update immediately after the insert.
	 *
	 * @param array<string, mixed> $data Field values, same shape as `validate()`, plus optional customer_note/payment_method.
	 * @return int New enrollment ID.
	 * @throws Duplicate_Enrollment_Exception When the `(term_id, user_id, participant_name)` unique key rejects the row.
	 */
	public function create( array $data ): int {
		$term_id = (int) $data['term_id'];
		$term    = ( $this->find_term )( $term_id );

		$now             = ( $this->now )();
		$enrollment_date = substr( $now, 0, 10 );

		$partner_name = trim( (string) ( $data['partner_name'] ?? '' ) );
		$partner_name = '' === $partner_name ? null : $partner_name;

		$pricing = $this->pricing->compute( $term, $enrollment_date, $partner_name );

		$capacity      = $term['capacity'] ?? null;
		$over_capacity = false;

		if ( null !== $capacity && '' !== (string) $capacity ) {
			$active_count  = ( $this->count_active_enrollments )( $term_id );
			$over_capacity = $active_count >= (int) $capacity;
		}

		$due_date = $this->due_dates->calculate( $now, ( $this->due_date_days )() );

		$role = (string) ( $data['role'] ?? self::ROLE_SOLO );
		$role = in_array( $role, self::ROLES, true ) ? $role : self::ROLE_SOLO;

		$payment_method = (string) ( $data['payment_method'] ?? self::PAYMENT_BANK_TRANSFER );
		$payment_method = in_array( $payment_method, self::PAYMENT_METHODS, true ) ? $payment_method : self::PAYMENT_BANK_TRANSFER;

		$customer_note = trim( (string) ( $data['customer_note'] ?? '' ) );

		$row = array(
			'term_id'          => $term_id,
			'user_id'          => (int) $data['user_id'],
			'participant_name' => trim( (string) ( $data['participant_name'] ?? '' ) ),
			'role'             => $role,
			'partner_name'     => $partner_name,
			'status'           => self::STATUS_CONFIRMED,
			'over_capacity'    => $over_capacity ? 1 : 0,
			'price'            => $pricing['price'],
			'discount_note'    => $pricing['discount_note'],
			'due_date'         => $due_date,
			'variable_symbol'  => '', // Placeholder: the real symbol needs this row's own ID, filled in right after insert below.
			'payment_method'   => $payment_method,
			'paid_at'          => null,
			'paid_marked_by'   => null,
			'customer_note'    => '' === $customer_note ? null : $customer_note,
			'admin_note'       => null,
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		try {
			$id = ( $this->insert_enrollment )( $row );
		} catch ( Duplicate_Key_Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception (fixed message + chained previous exception), never echoed to a page.
			throw new Duplicate_Enrollment_Exception( 'You are already enrolled in this term with this participant.', 0, $e );
		}

		$year            = (int) substr( $now, 0, 4 );
		$variable_symbol = $this->variable_symbols->generate( $year, $id );

		( $this->update_enrollment )( $id, array( 'variable_symbol' => $variable_symbol ) );

		return $id;
	}

	/**
	 * Mark an enrollment paid: `confirmed → paid` (spec §3.2 state diagram).
	 *
	 * @param int $id             Enrollment ID.
	 * @param int $admin_user_id  WP user ID of the admin performing the action, stored as `paid_marked_by`.
	 * @throws Illegal_Status_Transition_Exception When the enrollment isn't `confirmed` (already `paid`, or `cancelled`).
	 */
	public function mark_paid( int $id, int $admin_user_id ): void {
		$enrollment = $this->require_enrollment( $id );

		if ( self::STATUS_CONFIRMED !== (string) $enrollment['status'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception message, never echoed to a page.
			throw new Illegal_Status_Transition_Exception( sprintf( 'Cannot mark enrollment %d paid from status "%s".', $id, (string) $enrollment['status'] ) );
		}

		( $this->update_enrollment )(
			$id,
			array(
				'status'         => self::STATUS_PAID,
				'paid_at'        => ( $this->now )(),
				'paid_marked_by' => $admin_user_id,
				'updated_at'     => ( $this->now )(),
			)
		);
	}

	/**
	 * Unmark an enrollment as paid: `paid → confirmed` (spec §3.2: "wrong row
	 * clicked, payment bounced/refunded ... never sends an email" — the "no
	 * email" part is the caller's concern, not this method's).
	 *
	 * @param int $id Enrollment ID.
	 * @throws Illegal_Status_Transition_Exception When the enrollment isn't `paid` (still `confirmed`, or `cancelled`).
	 */
	public function unmark_paid( int $id ): void {
		$enrollment = $this->require_enrollment( $id );

		if ( self::STATUS_PAID !== (string) $enrollment['status'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception message, never echoed to a page.
			throw new Illegal_Status_Transition_Exception( sprintf( 'Cannot unmark enrollment %d as paid from status "%s".', $id, (string) $enrollment['status'] ) );
		}

		( $this->update_enrollment )(
			$id,
			array(
				'status'         => self::STATUS_CONFIRMED,
				'paid_at'        => null,
				'paid_marked_by' => null,
				'updated_at'     => ( $this->now )(),
			)
		);
	}

	/**
	 * Cancel an enrollment: `confirmed|paid → cancelled` (spec §3.2 state
	 * diagram; `cancelled` is terminal, so cancelling twice is illegal).
	 *
	 * @param int $id Enrollment ID.
	 * @throws Illegal_Status_Transition_Exception When the enrollment is already `cancelled`.
	 */
	public function cancel( int $id ): void {
		$enrollment = $this->require_enrollment( $id );

		if ( self::STATUS_CANCELLED === (string) $enrollment['status'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception message, never echoed to a page.
			throw new Illegal_Status_Transition_Exception( sprintf( 'Enrollment %d is already cancelled.', $id ) );
		}

		( $this->update_enrollment )(
			$id,
			array(
				'status'     => self::STATUS_CANCELLED,
				'updated_at' => ( $this->now )(),
			)
		);
	}

	/**
	 * Load an enrollment or fail loudly — every status-transition method
	 * needs the current row to decide whether the move is legal, and a
	 * missing ID is a programmer error (the caller looked it up from a list
	 * screen a moment ago), not a user-facing validation case.
	 *
	 * @param int $id Enrollment ID.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When no enrollment exists with this ID.
	 */
	private function require_enrollment( int $id ): array {
		$enrollment = ( $this->find_enrollment )( $id );

		if ( null === $enrollment ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception message, never echoed to a page.
			throw new \InvalidArgumentException( sprintf( 'Enrollment %d not found.', $id ) );
		}

		return $enrollment;
	}
}
