<?php
/**
 * Searchable customer list admin screen (F12).
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
 * Class Customers_Page.
 *
 * Read-only: `WP_User_Query` does the search/pagination, this class only
 * wires the request into it and hands the result to `Customers_List_Table`.
 * Staff accounts (administrator/`rd_manager`) are excluded — this is "who is
 * this customer calling me" (spec F12), not a general user list.
 */
class Customers_Page {

	const SLUG = 'ruben-dance-customers';

	const PER_PAGE = 20;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the "Customers" submenu page under the Ruben Dance top-level menu.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			Menu::SLUG,
			__( 'Customers', 'ruben-dance' ),
			__( 'Customers', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * URL to this screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * Main entry point, wired as the submenu page callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view customers.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the list shows, no state change.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which page of rows the list shows, no state change.
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$paged = $paged > 0 ? $paged : 1;

		$args = array(
			'role__not_in' => array( 'administrator', Roles::ROLE ),
			'number'       => self::PER_PAGE,
			'paged'        => $paged,
			'orderby'      => 'display_name',
			'order'        => 'ASC',
			'count_total'  => true,
		);

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$query = new \WP_User_Query( $args );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Customers', 'ruben-dance' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		echo '<form method="get" style="margin:1em 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name or email…', 'ruben-dance' ) . '"> ';
		submit_button( __( 'Search', 'ruben-dance' ), '', '', false );
		echo '</form>';

		$total = (int) $query->get_total();

		$table = new Customers_List_Table( $query->get_results() );
		$table->prepare_items();
		$table->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
		$table->display();

		echo '</div>';
	}
}
