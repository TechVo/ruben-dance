<?php
/**
 * WP-CLI `wp rd seed` command.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Seed_Command.
 *
 * Skeleton for now: registers the `wp rd seed` command so later milestones
 * only need to extend invoke() with real fixture data (courses, terms,
 * lessons, enrollments, discounts, ...). It intentionally does nothing yet.
 */
class Seed_Command {

	/**
	 * Seed the database with development/test fixture data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rd seed
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args ); // Required by the WP-CLI callable signature; unused for now.

		// Intentionally empty: no plugin functionality/fixtures exist yet.
		// Later milestones (see docs/implementation/) add seed data here.
		\WP_CLI::success( 'ruben-dance: nothing to seed yet.' );
	}
}
