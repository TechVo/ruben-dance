<?php
/**
 * `WP_List_Table` for the per-term lessons admin screen (F10).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Repositories\Lesson_Repository;
use RubenDance\Services\Lesson_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Term_Lessons_List_Table.
 */
class Term_Lessons_List_Table extends \WP_List_Table {

	/**
	 * Term ID whose lessons this table lists.
	 *
	 * @var int
	 */
	private int $term_id;

	/**
	 * Constructor.
	 *
	 * @param int $term_id Term ID.
	 */
	public function __construct( int $term_id ) {
		parent::__construct(
			array(
				'singular' => 'lesson',
				'plural'   => 'lessons',
				'ajax'     => false,
			)
		);

		$this->term_id = $term_id;
	}

	/**
	 * Column definitions.
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return array(
			'lesson_date' => __( 'Date', 'ruben-dance' ),
			'time'        => __( 'Time', 'ruben-dance' ),
			'status'      => __( 'Status', 'ruben-dance' ),
			'note'        => __( 'Note', 'ruben-dance' ),
		);
	}

	/**
	 * Load rows from the repository.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = ( new Lesson_Repository() )->all_for_term( $this->term_id );
	}

	/**
	 * "Date" column: date + weekday name + row action (edit).
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	protected function column_lesson_date( array $item ): string {
		$id   = (int) $item['id'];
		$date = (string) $item['lesson_date'];

		$actions = array(
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Term_Lessons_Page::edit_url( $this->term_id, $id ) ),
				esc_html__( 'Edit', 'ruben-dance' )
			),
		);

		$label = date_i18n( 'D, j M Y', strtotime( $date ) );

		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>%3$s',
			esc_url( Term_Lessons_Page::edit_url( $this->term_id, $id ) ),
			esc_html( $label ),
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
			case 'time':
				return esc_html( Terms_List_Table::format_time( (string) $item['start_time'] ) . '–' . Terms_List_Table::format_time( (string) $item['end_time'] ) );

			case 'status':
				return esc_html( $this->status_label( (string) $item['status'] ) );

			case 'note':
				return '' === (string) ( $item['note'] ?? '' ) ? '' : esc_html( (string) $item['note'] );

			default:
				return '';
		}
	}

	/**
	 * A lesson status to its translated label.
	 *
	 * @param string $status One of `Lesson_Service::STATUSES`.
	 * @return string
	 */
	private function status_label( string $status ): string {
		switch ( $status ) {
			case Lesson_Service::STATUS_SCHEDULED:
				return __( 'Scheduled', 'ruben-dance' );

			case Lesson_Service::STATUS_CANCELLED:
				return __( 'Cancelled', 'ruben-dance' );

			case Lesson_Service::STATUS_MOVED:
				return __( 'Moved', 'ruben-dance' );

			default:
				return $status;
		}
	}

	/**
	 * Message shown when a term has no generated lessons at all.
	 */
	public function no_items() {
		esc_html_e( 'No lessons generated yet — save the term with a valid date range.', 'ruben-dance' );
	}
}
