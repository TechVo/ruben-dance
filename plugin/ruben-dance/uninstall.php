<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted from the Plugins screen (never on
 * deactivation). Removes the `rd_manager` role and the plugin's options, but
 * deliberately keeps every `wp_rd_*` table — deleting customer/enrollment
 * data must be a conscious, separate, manual act.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // Disallow direct access.
}

$ruben_dance_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $ruben_dance_autoload ) ) {
	require_once $ruben_dance_autoload;
}

if ( class_exists( '\RubenDance\Roles' ) ) {
	\RubenDance\Roles::uninstall();
}

if ( class_exists( '\RubenDance\Schema' ) ) {
	delete_option( \RubenDance\Schema::OPTION_NAME );
}

// The retention cron's scheduled event is process footprint, not customer
// data — clearing it on full removal is the same "clean up after ourselves"
// reasoning as the role/options above, and does not touch any `wp_rd_*` table.
if ( class_exists( '\RubenDance\Compliance\Retention_Cron' ) ) {
	wp_clear_scheduled_hook( \RubenDance\Compliance\Retention_Cron::HOOK );
}
