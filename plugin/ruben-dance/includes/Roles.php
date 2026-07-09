<?php
/**
 * Custom `rd_manager` role and plugin capability.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Roles.
 *
 * Owns the `rd_manager` role and the `rd_manage` capability that gates every
 * plugin admin screen and action. `administrator` also gets the capability
 * so the site admin never has to switch roles to manage the plugin.
 */
class Roles {

	/**
	 * Role slug for the plugin owner/manager.
	 *
	 * @var string
	 */
	const ROLE = 'rd_manager';

	/**
	 * Capability required to access any Ruben Dance admin screen or action.
	 *
	 * @var string
	 */
	const CAPABILITY = 'rd_manage';

	/**
	 * Create the `rd_manager` role and grant the capability to `administrator`.
	 *
	 * Safe to call repeatedly (activation, upgrade): `add_role()` is a no-op
	 * if the role already exists, and `add_cap()` is idempotent.
	 */
	public static function install(): void {
		add_role(
			self::ROLE,
			__( 'Ruben Dance Manager', 'ruben-dance' ),
			array(
				'read'           => true,
				self::CAPABILITY => true,
			)
		);

		$administrator = get_role( 'administrator' );

		if ( null !== $administrator && ! $administrator->has_cap( self::CAPABILITY ) ) {
			$administrator->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Remove the role and the capability from `administrator`.
	 *
	 * Called on uninstall only (never on deactivation) — a deactivated plugin
	 * must not strip capabilities out from under existing users.
	 */
	public static function uninstall(): void {
		remove_role( self::ROLE );

		$administrator = get_role( 'administrator' );

		if ( null !== $administrator ) {
			$administrator->remove_cap( self::CAPABILITY );
		}
	}
}
