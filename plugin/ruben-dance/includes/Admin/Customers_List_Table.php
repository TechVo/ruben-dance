<?php
/**
 * `WP_List_Table` for the searchable customer list (F12).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Lang;
use RubenDance\Services\Registration_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Customers_List_Table.
 *
 * Rows are `WP_User` objects (as returned by `WP_User_Query`), not enrollment
 * array rows — the "who is this person calling me" list is a list of
 * accounts, spec F12. Pagination/search happen in `Customers_Page` via
 * `WP_User_Query`; this class only renders whatever page of users it's given.
 */
class Customers_List_Table extends \WP_List_Table {

	/**
	 * Customers for the current page (already loaded by the caller).
	 *
	 * @var \WP_User[]
	 */
	private array $users;

	/**
	 * Constructor.
	 *
	 * @param \WP_User[] $users Customers for the current page.
	 */
	public function __construct( array $users ) {
		parent::__construct(
			array(
				'singular' => 'customer',
				'plural'   => 'customers',
				'ajax'     => false,
			)
		);

		$this->users = $users;
	}

	/**
	 * Column definitions.
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return array(
			'name'              => __( 'Name', 'ruben-dance' ),
			'email'             => __( 'Email', 'ruben-dance' ),
			'phone'             => __( 'Phone', 'ruben-dance' ),
			'locale'            => __( 'Locale', 'ruben-dance' ),
			'marketing_consent' => __( 'Marketing consent', 'ruben-dance' ),
		);
	}

	/**
	 * Load rows (already fetched by the caller, see constructor).
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->users;
	}

	/**
	 * "Name" column: display name + "View" row action.
	 *
	 * @param \WP_User $item Row data.
	 * @return string
	 */
	protected function column_name( \WP_User $item ): string {
		$html = '<strong><a href="' . esc_url( Customer_Detail_Page::url( $item->ID ) ) . '">' . esc_html( $item->display_name ) . '</a></strong>';

		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Customer_Detail_Page::url( $item->ID ) ),
				esc_html__( 'View details', 'ruben-dance' )
			),
		);

		return $html . $this->row_actions( $actions );
	}

	/**
	 * Every other column.
	 *
	 * @param \WP_User $item        Row data.
	 * @param string   $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'email':
				return esc_html( $item->user_email );

			case 'phone':
				$phone = get_user_meta( $item->ID, Registration_Service::META_PHONE, true );

				return esc_html( is_string( $phone ) ? $phone : '' );

			case 'locale':
				$locale = get_user_meta( $item->ID, Registration_Service::META_LOCALE, true );

				return esc_html( Lang::EN === $locale ? __( 'English', 'ruben-dance' ) : __( 'Czech', 'ruben-dance' ) );

			case 'marketing_consent':
				return '1' === get_user_meta( $item->ID, Registration_Service::META_MARKETING_CONSENT, true )
					? esc_html__( 'Yes', 'ruben-dance' )
					: esc_html__( 'No', 'ruben-dance' );

			default:
				return '';
		}
	}

	/**
	 * Message shown when no customer matches the search.
	 */
	public function no_items() {
		esc_html_e( 'No customers found.', 'ruben-dance' );
	}
}
