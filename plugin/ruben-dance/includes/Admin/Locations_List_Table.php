<?php
/**
 * `WP_List_Table` for the Locations admin screen.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Repositories\Location_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Locations_List_Table.
 *
 * Deliberately minimal: no bulk actions, sorting or pagination — the
 * location count never justifies it. Later admin screens that do need those
 * add them on top of the same `WP_List_Table` base.
 */
class Locations_List_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'location',
				'plural'   => 'locations',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return array(
			'name'      => __( 'Name', 'ruben-dance' ),
			'address'   => __( 'Address', 'ruben-dance' ),
			'is_active' => __( 'Status', 'ruben-dance' ),
		);
	}

	/**
	 * Load rows from the repository.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = ( new Location_Repository() )->all();
	}

	/**
	 * "Name" column: name + row actions (edit, (de)activate, delete).
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	protected function column_name( array $item ): string {
		$id     = (int) $item['id'];
		$active = 1 === (int) $item['is_active'];

		$actions = array(
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Locations_Page::edit_url( $id ) ),
				esc_html__( 'Edit', 'ruben-dance' )
			),
		);

		if ( $active ) {
			$actions['deactivate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( Locations_Page::row_action_url( 'deactivate', $id ) ),
				esc_html__( 'Deactivate', 'ruben-dance' )
			);
		} else {
			$actions['activate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( Locations_Page::row_action_url( 'activate', $id ) ),
				esc_html__( 'Activate', 'ruben-dance' )
			);
			$actions['delete']   = sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( Locations_Page::row_action_url( 'delete', $id ) ),
				esc_js( __( 'Delete this location? If it is still used by a course term it will be deactivated instead.', 'ruben-dance' ) ),
				esc_html__( 'Delete', 'ruben-dance' )
			);
		}

		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>%3$s',
			esc_url( Locations_Page::edit_url( $id ) ),
			esc_html( (string) $item['name'] ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Every other column.
	 *
	 * @param array<string, mixed> $item        Row data.
	 * @param string               $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'address':
				return esc_html( (string) $item['address'] );

			case 'is_active':
				return 1 === (int) $item['is_active']
					? esc_html__( 'Active', 'ruben-dance' )
					: '<em>' . esc_html__( 'Inactive', 'ruben-dance' ) . '</em>';

			default:
				return '';
		}
	}

	/**
	 * Message shown when there are no locations at all.
	 */
	public function no_items() {
		esc_html_e( 'No locations yet.', 'ruben-dance' );
	}
}
