<?php
/**
 * WP-CLI `wp rd seed` command.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Cli;

use RubenDance\Repositories\Location_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Seed_Command.
 *
 * Registers the `wp rd seed` command. Each milestone that adds an entity
 * extends `__invoke()` with that entity's fixture data; every seed method
 * must stay idempotent (re-running `wp rd seed` must never create
 * duplicates), matched by name against what's already in the database.
 */
class Seed_Command {

	/**
	 * Real venues from ruben-dance.cz's "Tančírny v Praze" page, used as
	 * fixture data so admin screens are always exercised against realistic
	 * content rather than "Location 1", "Location 2", ...
	 *
	 * @var array<int, array{name: string, address: string, map_url: string}>
	 */
	const LOCATIONS = array(
		array(
			'name'    => 'Terasa Smíchov',
			'address' => 'Plzeňská 8, 150 00 Praha 5 – Smíchov',
			'map_url' => 'https://maps.google.com/?q=Plze%C5%88sk%C3%A1+8%2C+150+00+Praha+5',
		),
		array(
			'name'    => 'NYX Hotel Prague',
			'address' => 'Panská 892/9, 110 00 Praha 1 – Nové Město',
			'map_url' => 'https://maps.google.com/?q=Pansk%C3%A1+892%2F9%2C+110+00+Praha+1',
		),
		array(
			'name'    => 'Terasa 67 (Křižíkův pavilon B)',
			'address' => 'Výstaviště 67, 170 00 Praha 7 – Holešovice',
			'map_url' => 'https://maps.google.com/?q=V%C3%BDstavi%C5%A1t%C4%9B+67%2C+170+00+Praha+7',
		),
	);

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

		$created = $this->seed_locations();

		\WP_CLI::success( sprintf( 'ruben-dance: seeded (%d location(s) created).', $created ) );
	}

	/**
	 * Insert the fixture locations, skipping any that already exist (matched
	 * by exact name) so repeated runs never create duplicates.
	 *
	 * @return int Number of locations actually created.
	 */
	private function seed_locations(): int {
		$repository = new Location_Repository();
		$created    = 0;

		foreach ( self::LOCATIONS as $location ) {
			if ( null !== $repository->find_by_name( $location['name'] ) ) {
				continue;
			}

			$repository->insert(
				array(
					'name'      => $location['name'],
					'address'   => $location['address'],
					'map_url'   => $location['map_url'],
					'is_active' => 1,
				)
			);

			++$created;
		}

		return $created;
	}
}
