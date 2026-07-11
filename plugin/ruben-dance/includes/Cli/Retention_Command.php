<?php
/**
 * WP-CLI `wp rd retention` command.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Cli;

use RubenDance\Services\Retention_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Retention_Command.
 *
 * Registers `wp rd retention [--dry-run]` (spec §6.1 acceptance criterion:
 * "dry-run WP-CLI command `wp rd retention --dry-run`"). Without the flag it
 * runs the exact same job the monthly cron does
 * (`Compliance\Retention_Cron::run()`) — useful for running it on demand
 * rather than waiting for the schedule.
 */
class Retention_Command {

	/**
	 * Run the retention job.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only report what the job would do — anonymize/purge nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rd retention --dry-run
	 *     wp rd retention
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: dry-run.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$summary = Retention_Service::create_default()->run( $dry_run );

		\WP_CLI::log( sprintf( '%s — run at %s', $dry_run ? 'Retention dry-run' : 'Retention run', $summary['ran_at'] ) );

		\WP_CLI::log(
			sprintf(
				'Customers %s: %d',
				$dry_run ? 'that would be anonymized' : 'anonymized',
				$summary['customers_anonymized']
			)
		);

		if ( array() !== $summary['customer_ids'] ) {
			\WP_CLI::log( '  user IDs: ' . implode( ', ', $summary['customer_ids'] ) );
		}

		\WP_CLI::log(
			sprintf(
				'Email log rows %s: %d',
				$dry_run ? 'that would be purged' : 'purged',
				$summary['email_log_purged']
			)
		);

		\WP_CLI::log(
			sprintf(
				'Cancelled/unpaid enrollments %s: %d',
				$dry_run ? 'that would be purged' : 'purged',
				$summary['enrollments_purged']
			)
		);

		\WP_CLI::success( $dry_run ? 'Retention dry-run complete.' : 'Retention run complete.' );
	}
}
