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
