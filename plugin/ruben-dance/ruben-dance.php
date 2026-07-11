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
 * Register the `wp rd retention` WP-CLI command (spec §6.1 acceptance
 * criterion: "dry-run WP-CLI command `wp rd retention --dry-run`").
 */
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\RubenDance\Cli\Retention_Command' ) ) {
	WP_CLI::add_command( 'rd retention', '\RubenDance\Cli\Retention_Command' );
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

/**
 * Load the compiled `cs_CZ` translation (M16/§5 Multilingual: "WordPress
 * i18n (`__()` with text domain `ruben-dance`), CS + EN `.po` files" — the
 * source strings themselves are the English default, so only a non-English
 * locale ever needs a `.mo` loaded at all). Not hooked to `plugins_loaded`/
 * `init`: this file already runs during `plugins_loaded` (WordPress executes
 * every active plugin's main file then), and every string this plugin
 * translates is only ever read from inside a *later* hook callback (a menu
 * page render, a shortcode, a REST callback, ...), never at top-level file
 * scope — so loading synchronously here, before any of those callbacks are
 * registered, is early enough for every one of them.
 */
load_plugin_textdomain( 'ruben-dance', false, dirname( plugin_basename( RUBEN_DANCE_PLUGIN_FILE ) ) . '/languages' );

\RubenDance\Admin\Menu::register();
\RubenDance\Admin\Locations_Page::register();
\RubenDance\Admin\Terms_Page::register();
\RubenDance\Admin\Term_Lessons_Page::register();
\RubenDance\Admin\Roster_Page::register();
\RubenDance\Admin\Enrollment_Detail_Page::register();
\RubenDance\Admin\Roster_Ajax::register();
\RubenDance\Admin\Enrollments_Page::register();
\RubenDance\Admin\Manual_Enrollment_Page::register();
\RubenDance\Admin\Customers_Page::register();
\RubenDance\Admin\Customer_Detail_Page::register();
\RubenDance\Admin\Settings_Page::register();
\RubenDance\Admin\Email_Templates_Page::register();
\RubenDance\Admin\Email_Log_Page::register();
\RubenDance\Admin\Lesson_Notify_Page::register();
\RubenDance\Post_Types::register();
\RubenDance\Taxonomies::register();
\RubenDance\Polylang_Setup::register();
\RubenDance\Course_Fields::register();
\RubenDance\Front\Shortcodes::register();
\RubenDance\Front\Form_Handler::register();
\RubenDance\Front\Access_Restrictions::register();
\RubenDance\Front\Catalog_Page::register();
\RubenDance\Front\Course_Content::register();
\RubenDance\Front\Enroll_Page::register();
\RubenDance\Front\Enrollment_Form_Handler::register();
\RubenDance\Front\Account_Page::register();
\RubenDance\Front\Account_Form_Handler::register();
\RubenDance\Front\Qr_Code_Ajax::register();
\RubenDance\Front\Calendar_Page::register();
\RubenDance\Front\Voucher_Page::register();
\RubenDance\Front\Voucher_Form_Handler::register();
\RubenDance\Rest\Lessons_Controller::register();
\RubenDance\Services\Calendar_Cache::register();
\RubenDance\Front\Footer_Links::register();
\RubenDance\Compliance\Personal_Data::register();
\RubenDance\Compliance\Retention_Cron::register();
