<?php
/**
 * Monthly WP-Cron scheduling for the retention job (spec §6.1: "Implement as
 * a monthly WP-Cron job").
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Compliance;

use RubenDance\Services\Retention_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Retention_Cron.
 *
 * WordPress core ships no "monthly" schedule (only hourly/twicedaily/daily),
 * so this registers one via `cron_schedules`, a fixed 30-day interval — a
 * calendar month varies in length, but the retention windows this job
 * enforces are measured in years/a year, where a few days of slack across
 * runs is immaterial. `maybe_schedule()` runs on every `init` (cheap:
 * `wp_next_scheduled()` is a single `wp_options`/cron-array read) so the
 * event self-heals if ever cleared, without needing an activation hook.
 */
class Retention_Cron {

	/**
	 * The cron action hook this job runs on.
	 *
	 * @var string
	 */
	const HOOK = 'rd_run_retention';

	/**
	 * The custom recurrence slug registered with `cron_schedules`.
	 *
	 * @var string
	 */
	const SCHEDULE = 'rd_monthly';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- registering a new named schedule, not changing an existing core one.
		add_action( 'init', array( self::class, 'maybe_schedule' ) );
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * `cron_schedules` filter callback: adds the `rd_monthly` recurrence.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Registered schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once a month (Ruben Dance retention)', 'ruben-dance' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the recurring event if it isn't already.
	 */
	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * The cron callback itself: a real (non-dry-run) retention pass.
	 */
	public static function run(): void {
		Retention_Service::create_default()->run( false );
	}
}
