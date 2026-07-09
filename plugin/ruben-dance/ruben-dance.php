<?php
/**
 * Plugin Name:       Ruben Dance
 * Plugin URI:         https://ruben-dance.cz/
 * Description:        Custom reservation system for Ruben Dance courses, terms, lessons and enrollments.
 * Version:            0.1.0
 * Requires at least:  6.4
 * Requires PHP:       8.1
 * Author:             Ruben Dance
 * License:            GPL-2.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        ruben-dance
 * Domain Path:        /languages
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

define( 'RUBEN_DANCE_VERSION', '0.1.0' );
define( 'RUBEN_DANCE_PLUGIN_FILE', __FILE__ );
define( 'RUBEN_DANCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Composer autoloader (dev dependencies such as phpcs/phpunit are not
// shipped; only the PSR-4 class map is required at runtime).
if ( file_exists( RUBEN_DANCE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once RUBEN_DANCE_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Register the skeleton `wp rd seed` WP-CLI command.
 *
 * Later milestones extend RubenDance\Cli\Seed_Command::__invoke() with real
 * fixture data (courses, terms, lessons, enrollments). It intentionally does
 * nothing yet.
 */
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\RubenDance\Cli\Seed_Command' ) ) {
	WP_CLI::add_command( 'rd seed', '\RubenDance\Cli\Seed_Command' );
}

/**
 * Activation: create/upgrade the schema and the `rd_manager` role.
 *
 * Always (re)applies, so reactivating after a manual table drop or a fresh
 * `wp-env start` recreates everything from scratch.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		\RubenDance\Schema::install();
		\RubenDance\Roles::install();
	}
);

/*
 * Deactivation intentionally has no hook: nothing here is destructive.
 * Tables, options and the rd_manager role all survive a deactivate/reactivate
 * cycle; only uninstall.php (an explicit "delete" from the plugins screen)
 * removes the role and options, and even it keeps the tables.
 */

/**
 * Catch schema drift on every load (e.g. after a plugin update that bumps
 * `Schema::SCHEMA_VERSION` without a fresh activation, or multisite network
 * activation): re-run `dbDelta()` only when the stored version differs.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		\RubenDance\Schema::maybe_upgrade();
	}
);

\RubenDance\Admin\Menu::register();
\RubenDance\Admin\Locations_Page::register();
\RubenDance\Admin\Terms_Page::register();
\RubenDance\Admin\Term_Lessons_Page::register();
\RubenDance\Admin\Settings_Page::register();
\RubenDance\Post_Types::register();
\RubenDance\Taxonomies::register();
\RubenDance\Polylang_Setup::register();
