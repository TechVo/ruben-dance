<?php
/**
 * Top-level "Ruben Dance" admin menu.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Menu.
 *
 * Registers the empty "Ruben Dance" landing page. Later milestones add
 * submenu pages (locations, terms, enrollments, ...) under the same slug.
 */
class Menu {

	/**
	 * Top-level menu/page slug.
	 *
	 * @var string
	 */
	const SLUG = 'ruben-dance';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the top-level menu, gated by the `rd_manage` capability.
	 */
	public static function add_menu(): void {
		add_menu_page(
			__( 'Ruben Dance', 'ruben-dance' ),
			__( 'Ruben Dance', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render_landing_page' ),
			'dashicons-groups'
		);
	}

	/**
	 * Render the (intentionally empty) landing page.
	 */
	public static function render_landing_page(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Ruben Dance', 'ruben-dance' ) . '</h1></div>';
	}
}
