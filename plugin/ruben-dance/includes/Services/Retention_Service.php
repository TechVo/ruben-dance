<?php
/**
 * Retention cron decision logic + orchestration (spec §6.1).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Compliance\Personal_Data;
use RubenDance\Repositories\Email_Log_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Repositories\Retention_Log_Repository;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Retention_Service.
 *
 * Kept WordPress-agnostic the same way `Enrollment_Service`/
 * `Registration_Service` are: every database/clock/settings touchpoint is an
 * injected callable (see `create_default()` for the real wiring), so the
 * "who counts as an inactive candidate, what gets purged" decision logic
 * (spec §6.1: three separate rules — inactive-customer anonymization,
 * email-log purge, cancelled-unpaid-enrollment purge) is unit-testable with
 * plain PHPUnit and fakes, no WordPress/database needed. `run( true )` (dry
 * run) computes exactly the same candidates a real run would act on, but
 * never calls the mutating callables — the WP-CLI `--dry-run` flag and the
 * scheduled monthly cron both call `run()`, just with a different flag.
 */
class Retention_Service {

	/**
	 * Every non-anonymized customer account ID: function(): int[].
	 *
	 * @var callable
	 */
	private $find_customer_ids;

	/**
	 * Distinct user IDs with a non-cancelled enrollment on/after a cutoff:
	 * function( string $cutoff ): int[].
	 *
	 * @var callable
	 */
	private $active_user_ids_since;

	/**
	 * Anonymizes one customer account: function( int $user_id ): void.
	 *
	 * @var callable
	 */
	private $anonymize_customer;

	/**
	 * Counts email log rows older than a cutoff (dry run):
	 * function( string $cutoff ): int.
	 *
	 * @var callable
	 */
	private $count_old_email_log;

	/**
	 * Deletes email log rows older than a cutoff, returns the count deleted
	 * (real run): function( string $cutoff ): int.
	 *
	 * @var callable
	 */
	private $delete_old_email_log;

	/**
	 * Counts cancelled/unpaid enrollments older than a cutoff (dry run):
	 * function( string $cutoff ): int.
	 *
	 * @var callable
	 */
	private $count_old_cancelled_unpaid;

	/**
	 * Deletes cancelled/unpaid enrollments older than a cutoff, returns the
	 * count deleted (real run): function( string $cutoff ): int.
	 *
	 * @var callable
	 */
	private $delete_old_cancelled_unpaid;

	/**
	 * The configured "inactive customer" retention window, in years:
	 * function(): int.
	 *
	 * @var callable
	 */
	private $retention_years;

	/**
	 * Current datetime, `Y-m-d H:i:s`: function(): string.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Persists one run's summary: function( array $summary ): void.
	 *
	 * @var callable
	 */
	private $log_run;

	/**
	 * Constructor.
	 *
	 * @param callable $find_customer_ids           function(): int[].
	 * @param callable $active_user_ids_since       function( string $cutoff ): int[].
	 * @param callable $anonymize_customer          function( int $user_id ): void.
	 * @param callable $count_old_email_log         function( string $cutoff ): int.
	 * @param callable $delete_old_email_log        function( string $cutoff ): int.
	 * @param callable $count_old_cancelled_unpaid   function( string $cutoff ): int.
	 * @param callable $delete_old_cancelled_unpaid  function( string $cutoff ): int.
	 * @param callable $retention_years              function(): int.
	 * @param callable $now                          function(): string.
	 * @param callable $log_run                       function( array $summary ): void.
	 */
	public function __construct(
		callable $find_customer_ids,
		callable $active_user_ids_since,
		callable $anonymize_customer,
		callable $count_old_email_log,
		callable $delete_old_email_log,
		callable $count_old_cancelled_unpaid,
		callable $delete_old_cancelled_unpaid,
		callable $retention_years,
		callable $now,
		callable $log_run
	) {
		$this->find_customer_ids           = $find_customer_ids;
		$this->active_user_ids_since       = $active_user_ids_since;
		$this->anonymize_customer          = $anonymize_customer;
		$this->count_old_email_log         = $count_old_email_log;
		$this->delete_old_email_log        = $delete_old_email_log;
		$this->count_old_cancelled_unpaid  = $count_old_cancelled_unpaid;
		$this->delete_old_cancelled_unpaid = $delete_old_cancelled_unpaid;
		$this->retention_years             = $retention_years;
		$this->now                         = $now;
		$this->log_run                     = $log_run;
	}

	/**
	 * Wire the service to the real repositories, `Settings` and the
	 * WordPress clock/user query.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		$enrollments   = new Enrollment_Repository();
		$email_log     = new Email_Log_Repository();
		$retention_log = new Retention_Log_Repository();

		return new self(
			static function (): array {
				$ids = get_users(
					array(
						'role'       => 'subscriber',
						'fields'     => 'ID',
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-time-a-month cron over the customer table, not a per-request query; no realistic customer count makes this slow.
							array(
								'key'     => Registration_Service::META_ANONYMIZED_AT,
								'compare' => 'NOT EXISTS',
							),
						),
					)
				);

				return array_map( 'intval', $ids );
			},
			static function ( string $cutoff ) use ( $enrollments ): array {
				return $enrollments->distinct_active_user_ids_since( $cutoff );
			},
			static function ( int $user_id ): void {
				Personal_Data::anonymize_user( $user_id );
			},
			static function ( string $cutoff ) use ( $email_log ): int {
				return $email_log->count_older_than( $cutoff );
			},
			static function ( string $cutoff ) use ( $email_log ): int {
				return $email_log->delete_older_than( $cutoff );
			},
			static function ( string $cutoff ) use ( $enrollments ): int {
				return $enrollments->count_cancelled_unpaid_older_than( $cutoff );
			},
			static function ( string $cutoff ) use ( $enrollments ): int {
				return $enrollments->delete_cancelled_unpaid_older_than( $cutoff );
			},
			static function (): int {
				return Settings::retention_years();
			},
			static function (): string {
				return current_time( 'mysql' );
			},
			static function ( array $summary ) use ( $retention_log ): void {
				$retention_log->insert(
					array(
						'run_at'               => $summary['ran_at'],
						'dry_run'              => $summary['dry_run'] ? 1 : 0,
						'customers_anonymized' => $summary['customers_anonymized'],
						'email_log_purged'     => $summary['email_log_purged'],
						'enrollments_purged'   => $summary['enrollments_purged'],
					)
				);
			}
		);
	}

	/**
	 * Run the retention job.
	 *
	 * Order: compute the inactive-customer candidate set first (subscribers
	 * minus everyone with a non-cancelled enrollment inside the retention
	 * window); when `$dry_run` is false, anonymize each candidate. Then the
	 * two independent 1-year purge rules (spec §6.1: "email log purged after
	 * 1 year", "unpaid cancelled enrollments after 1 year") — dry run counts,
	 * real run deletes. Every call, dry run or real, is logged (spec: "every
	 * run logged").
	 *
	 * @param bool $dry_run When true, only computes/reports candidates —
	 *                      nothing is anonymized, purged or deleted.
	 * @return array{dry_run: bool, ran_at: string, customer_ids: int[], customers_anonymized: int, email_log_purged: int, enrollments_purged: int}
	 */
	public function run( bool $dry_run ): array {
		$now   = ( $this->now )();
		$years = ( $this->retention_years )();

		$inactive_cutoff = self::cutoff( $now, "-{$years} years" );
		$one_year_cutoff = self::cutoff( $now, '-1 year' );

		$customer_ids  = ( $this->find_customer_ids )();
		$active_ids    = ( $this->active_user_ids_since )( $inactive_cutoff );
		$candidate_ids = array_values( array_diff( $customer_ids, $active_ids ) );

		if ( ! $dry_run ) {
			foreach ( $candidate_ids as $user_id ) {
				( $this->anonymize_customer )( $user_id );
			}
		}

		$email_log_count = $dry_run
			? ( $this->count_old_email_log )( $one_year_cutoff )
			: ( $this->delete_old_email_log )( $one_year_cutoff );

		$enrollments_count = $dry_run
			? ( $this->count_old_cancelled_unpaid )( $one_year_cutoff )
			: ( $this->delete_old_cancelled_unpaid )( $one_year_cutoff );

		$summary = array(
			'dry_run'              => $dry_run,
			'ran_at'               => $now,
			'customer_ids'         => $candidate_ids,
			'customers_anonymized' => count( $candidate_ids ),
			'email_log_purged'     => $email_log_count,
			'enrollments_purged'   => $enrollments_count,
		);

		( $this->log_run )( $summary );

		return $summary;
	}

	/**
	 * A `$now` minus/plus some `DateTimeImmutable::modify()`-compatible
	 * offset, e.g. `"-3 years"` — the same date-arithmetic approach
	 * `Due_Date_Calculator` uses, kept UTC throughout so it never drifts with
	 * the site's configured timezone.
	 *
	 * @param string $now      `Y-m-d H:i:s` (or any `strtotime()`-parseable) current time.
	 * @param string $modifier `DateTimeImmutable::modify()` argument.
	 * @return string `Y-m-d H:i:s`.
	 */
	private static function cutoff( string $now, string $modifier ): string {
		return ( new \DateTimeImmutable( $now, new \DateTimeZone( 'UTC' ) ) )->modify( $modifier )->format( 'Y-m-d H:i:s' );
	}
}
