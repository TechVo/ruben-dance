<?php
/**
 * Business rules for `wp_rd_enrollment`: create-enrollment orchestration and
 * the status-transition state machine.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Compliance\Legal;
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
	const ERROR_MANUAL_PRICE_INVALID  = 'manual_price_invalid';

	const ERROR_PRICE_INVALID        = 'price_invalid';
	const ERROR_REASON_REQUIRED      = 'reason_required';
	const ERROR_NOTE_REQUIRED        = 'note_required';
	const ERROR_TARGET_TERM_REQUIRED = 'target_term_required';

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

		$manual_price = trim( (string) ( $data['manual_price'] ?? '' ) );

		if ( '' !== $manual_price && ! $this->is_valid_amount( $manual_price ) ) {
			$errors['manual_price'] = self::ERROR_MANUAL_PRICE_INVALID;
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

		// Manual enrollment (phone orders, spec F11b: "manual price allowed")
		// overrides the server-computed price entirely rather than adjusting
		// it — the discount breakdown stays whatever Pricing_Service computed
		// (still an honest record of what the *normal* price would have
		// been), and the override itself is written to admin_note so it is
		// never silently indistinguishable from a regularly-priced row.
		$manual_price = trim( (string) ( $data['manual_price'] ?? '' ) );
		$price        = $pricing['price'];
		$admin_note   = null;

		if ( '' !== $manual_price ) {
			$price      = number_format( (float) $manual_price, 2, '.', '' );
			$admin_note = sprintf(
				'Manual price set at enrollment (phone order): %1$s Kč (computed price would have been %2$s Kč).',
				$price,
				$pricing['price']
			);
		}

		$extra_admin_note = trim( (string) ( $data['admin_note'] ?? '' ) );

		if ( '' !== $extra_admin_note ) {
			$admin_note = $this->append_note( $admin_note, $extra_admin_note );
		}

		$row = array(
			'term_id'          => $term_id,
			'user_id'          => (int) $data['user_id'],
			'participant_name' => trim( (string) ( $data['participant_name'] ?? '' ) ),
			'role'             => $role,
			'partner_name'     => $partner_name,
			'status'           => self::STATUS_CONFIRMED,
			'over_capacity'    => $over_capacity ? 1 : 0,
			'price'            => $price,
			'discount_note'    => $pricing['discount_note'],
			'due_date'         => $due_date,
			'variable_symbol'  => '', // Placeholder: the real symbol needs this row's own ID, filled in right after insert below.
			'payment_method'   => $payment_method,
			'paid_at'          => null,
			'paid_marked_by'   => null,
			'customer_note'    => '' === $customer_note ? null : $customer_note,
			'admin_note'       => $admin_note,
			// Per-enrollment consent audit (spec §6.1): every path that
			// reaches `create()` — the public `[rd_enroll]` form (which
			// requires the `tc_accepted` checkbox before calling this) and
			// the admin phone-order screen (whose own note documents that
			// the owner confirms the T&C with the customer verbally) —
			// already implies acceptance, so the version/timestamp are
			// stamped unconditionally rather than threaded through as an
			// extra `$data` flag this service would have to validate again.
			'tc_version'       => Legal::TC_VERSION,
			'tc_accepted_at'   => $now,
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
	 * Validate a role/partner-note edit submission (spec F11b roster action:
	 * "edit role/partner note"). Reuses `validate()`'s own role/partner error
	 * codes — this is the exact same pair of fields, just editable after the
	 * fact instead of at creation.
	 *
	 * @param array<string, mixed> $data Raw field values: role, partner_name.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public function validate_role_partner( array $data ): array {
		$errors = array();

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
	 * Edit an enrollment's role/partner name. Caller must call
	 * `validate_role_partner()` first and only proceed when it returns an
	 * empty array. Price/discount_note/status are untouched — this only ever
	 * touches the two fields the roster's "edit role/partner note" action
	 * covers.
	 *
	 * @param int    $id           Enrollment ID.
	 * @param string $role         One of self::ROLES (already validated).
	 * @param string $partner_name Partner name, already validated (may be blank).
	 */
	public function update_role_partner( int $id, string $role, string $partner_name ): void {
		$this->require_enrollment( $id );

		$role         = in_array( $role, self::ROLES, true ) ? $role : self::ROLE_SOLO;
		$partner_name = trim( $partner_name );

		( $this->update_enrollment )(
			$id,
			array(
				'role'         => $role,
				'partner_name' => '' === $partner_name ? null : $partner_name,
				'updated_at'   => ( $this->now )(),
			)
		);
	}

	/**
	 * Validate a price-edit submission (spec F11b roster action: "edit price
	 * (requires a reason string appended to `admin_note`; `discount_note`
	 * preserved)"). The reason is mandatory — without it `edit_price()` would
	 * silently make the price unauditable, defeating the whole point of
	 * `discount_note`/`admin_note` (spec §3.2: "keeps the price auditable").
	 *
	 * @param array<string, mixed> $data Raw field values: price, reason.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public function validate_price_edit( array $data ): array {
		$errors = array();

		$price = trim( (string) ( $data['price'] ?? '' ) );

		if ( ! $this->is_valid_amount( $price ) ) {
			$errors['price'] = self::ERROR_PRICE_INVALID;
		}

		$reason = trim( (string) ( $data['reason'] ?? '' ) );

		if ( '' === $reason ) {
			$errors['reason'] = self::ERROR_REASON_REQUIRED;
		}

		return $errors;
	}

	/**
	 * Edit an enrollment's price. Caller must call `validate_price_edit()`
	 * first and only proceed when it returns an empty array. `discount_note`
	 * is never touched — it stays the honest record of what the *original*
	 * computed discount was; the reason for this manual override lives in
	 * `admin_note` instead (spec F11b: "`discount_note` preserved").
	 *
	 * @param int    $id          Enrollment ID.
	 * @param string $price       New price, already validated as a non-negative amount.
	 * @param string $reason      Non-blank reason, already validated.
	 * @param string $actor_label Human-readable label (e.g. admin display name) for the audit trail.
	 * @throws \InvalidArgumentException When no enrollment exists with this ID.
	 */
	public function edit_price( int $id, string $price, string $reason, string $actor_label ): void {
		$enrollment = $this->require_enrollment( $id );

		$formatted_price = number_format( (float) $price, 2, '.', '' );
		$reason          = trim( $reason );

		$note = sprintf(
			'Price changed from %1$s Kč to %2$s Kč by %3$s: %4$s',
			(string) $enrollment['price'],
			$formatted_price,
			$actor_label,
			$reason
		);

		( $this->update_enrollment )(
			$id,
			array(
				'price'      => $formatted_price,
				'admin_note' => $this->append_note( $enrollment['admin_note'] ?? null, $note ),
				'updated_at' => ( $this->now )(),
			)
		);
	}

	/**
	 * Move an enrollment to a different term (spec F11b roster action: "move
	 * enrollment to a different term (partner balancing)"; F12 acceptance
	 * criteria: "recompute nothing — price travels unchanged, admin adjusts
	 * manually if needed; over-capacity flag re-evaluated on the target
	 * term"). `price`/`discount_note`/`due_date`/`variable_symbol`/`status`
	 * are all left exactly as they were; only `term_id`, `over_capacity` and
	 * `admin_note` (the move's audit trail) change.
	 *
	 * @param int    $id             Enrollment ID.
	 * @param int    $target_term_id Term ID to move into.
	 * @param string $actor_label    Human-readable label (e.g. admin display name) for the audit trail.
	 * @throws \InvalidArgumentException When no enrollment exists with this ID, the target term doesn't exist, or the enrollment is already in that term.
	 * @throws Illegal_Status_Transition_Exception When the enrollment is cancelled — nothing to move.
	 * @throws Duplicate_Enrollment_Exception When the account/participant already has an active enrollment in the target term.
	 */
	public function move_to_term( int $id, int $target_term_id, string $actor_label ): void {
		$enrollment = $this->require_enrollment( $id );

		if ( self::STATUS_CANCELLED === (string) $enrollment['status'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception message, never echoed to a page.
			throw new Illegal_Status_Transition_Exception( sprintf( 'Cannot move cancelled enrollment %d.', $id ) );
		}

		$source_term_id = (int) $enrollment['term_id'];

		if ( $source_term_id === $target_term_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception message, never echoed to a page.
			throw new \InvalidArgumentException( sprintf( 'Enrollment %d is already in term %d.', $id, $target_term_id ) );
		}

		$target = ( $this->find_term )( $target_term_id );

		if ( null === $target ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception message, never echoed to a page.
			throw new \InvalidArgumentException( sprintf( 'Target term %d not found.', $target_term_id ) );
		}

		$capacity      = $target['capacity'] ?? null;
		$over_capacity = false;

		if ( null !== $capacity && '' !== (string) $capacity ) {
			$active_count  = ( $this->count_active_enrollments )( $target_term_id );
			$over_capacity = $active_count >= (int) $capacity;
		}

		$note = sprintf( 'Moved from term #%1$d to term #%2$d by %3$s.', $source_term_id, $target_term_id, $actor_label );

		try {
			( $this->update_enrollment )(
				$id,
				array(
					'term_id'       => $target_term_id,
					'over_capacity' => $over_capacity ? 1 : 0,
					'admin_note'    => $this->append_note( $enrollment['admin_note'] ?? null, $note ),
					'updated_at'    => ( $this->now )(),
				)
			);
		} catch ( Duplicate_Key_Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception (fixed message + chained previous exception), never echoed to a page.
			throw new Duplicate_Enrollment_Exception( 'The account/participant already has an enrollment in the target term.', 0, $e );
		}
	}

	/**
	 * Validate an admin-note submission (spec F11b roster action: "add admin
	 * note").
	 *
	 * @param array<string, mixed> $data Raw field values: note.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public function validate_admin_note( array $data ): array {
		$errors = array();

		$note = trim( (string) ( $data['note'] ?? '' ) );

		if ( '' === $note ) {
			$errors['note'] = self::ERROR_NOTE_REQUIRED;
		}

		return $errors;
	}

	/**
	 * Append an admin note. Caller must call `validate_admin_note()` first
	 * and only proceed when it returns an empty array. Notes accumulate
	 * (never overwrite) — every previous note, including the automatic ones
	 * `edit_price()`/`move_to_term()` write, stays part of the permanent
	 * record.
	 *
	 * @param int    $id          Enrollment ID.
	 * @param string $note        Non-blank note text, already validated.
	 * @param string $actor_label Human-readable label (e.g. admin display name) for the audit trail.
	 * @throws \InvalidArgumentException When no enrollment exists with this ID.
	 */
	public function add_admin_note( int $id, string $note, string $actor_label ): void {
		$enrollment = $this->require_enrollment( $id );

		$note = trim( $note );

		( $this->update_enrollment )(
			$id,
			array(
				'admin_note' => $this->append_note( $enrollment['admin_note'] ?? null, sprintf( '%1$s: %2$s', $actor_label, $note ) ),
				'updated_at' => ( $this->now )(),
			)
		);
	}

	/**
	 * Append a line to an existing (possibly null/blank) admin note, one line
	 * per entry — shared by `create()` (manual price), `edit_price()`,
	 * `move_to_term()` and `add_admin_note()` so every audit-trail entry this
	 * class ever writes accumulates the same way instead of four subtly
	 * different concatenation rules.
	 *
	 * @param string|null $existing Current `admin_note` value, or null.
	 * @param string      $addition New line to append (already non-blank).
	 * @return string
	 */
	private function append_note( ?string $existing, string $addition ): string {
		$existing = trim( (string) $existing );

		return '' === $existing ? $addition : $existing . "\n" . $addition;
	}

	/**
	 * Whether a string is a non-negative decimal amount (mirrors
	 * `Term_Service::is_valid_amount()` — the same shape of validation for
	 * the same shape of field, kept as its own private copy here rather than
	 * a shared dependency between the two WordPress-agnostic services).
	 *
	 * @param string $amount Candidate amount string.
	 * @return bool
	 */
	private function is_valid_amount( string $amount ): bool {
		return '' !== $amount && 1 === preg_match( '/^\d+(\.\d{1,2})?$/', $amount );
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
