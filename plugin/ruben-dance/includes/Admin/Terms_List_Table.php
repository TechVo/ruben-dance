<?php
/**
 * `WP_List_Table` for the Terms admin screen.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Services\Term_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Terms_List_Table.
 *
 * Columns resolve `course_id` (a post) and `location_id` (a
 * `wp_rd_location` row) to their display names per row; the term count this
 * screen is built for (a handful of seasons per year) never justifies a JOIN
 * or a batched lookup, see `Locations_List_Table` for the same tradeoff.
 */
class Terms_List_Table extends \WP_List_Table {

	/**
	 * Active filter values from the list screen's filter form.
	 *
	 * @var array{status: string, location_id: int, season_label_cs: string}
	 */
	private array $filters;

	/**
	 * Locations keyed by ID, for the "Location" column.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $locations_by_id = array();

	/**
	 * Constructor.
	 *
	 * @param array{status: string, location_id: int, season_label_cs: string} $filters Active filter values.
	 */
	public function __construct( array $filters ) {
		parent::__construct(
			array(
				'singular' => 'term',
				'plural'   => 'terms',
				'ajax'     => false,
			)
		);

		$this->filters = $filters;
	}

	/**
	 * Column definitions.
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return array(
			'season_label_cs' => __( 'Season / Term', 'ruben-dance' ),
			'course'          => __( 'Course', 'ruben-dance' ),
			'location'        => __( 'Location', 'ruben-dance' ),
			'schedule'        => __( 'Schedule', 'ruben-dance' ),
			'dates'           => __( 'Dates', 'ruben-dance' ),
			'capacity'        => __( 'Capacity', 'ruben-dance' ),
			'price'           => __( 'Price', 'ruben-dance' ),
			'status'          => __( 'Status', 'ruben-dance' ),
		);
	}

	/**
	 * Load rows from the repository, applying the active filters.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->items = ( new Course_Term_Repository() )->all_with_filters(
			array_filter(
				array(
					'status'          => $this->filters['status'],
					'location_id'     => $this->filters['location_id'],
					'season_label_cs' => $this->filters['season_label_cs'],
				)
			)
		);

		foreach ( ( new Location_Repository() )->all() as $location ) {
			$this->locations_by_id[ (int) $location['id'] ] = $location;
		}
	}

	/**
	 * "Season / Term" column: label + row actions (edit, duplicate, lessons).
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	protected function column_season_label_cs( array $item ): string {
		$id = (int) $item['id'];

		$actions = array(
			'edit'      => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Terms_Page::edit_url( $id ) ),
				esc_html__( 'Edit', 'ruben-dance' )
			),
			'lessons'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Term_Lessons_Page::url( $id ) ),
				esc_html__( 'Lessons', 'ruben-dance' )
			),
			'duplicate' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Terms_Page::row_action_url( 'duplicate', $id ) ),
				esc_html__( 'Duplicate', 'ruben-dance' )
			),
		);

		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>%3$s',
			esc_url( Terms_Page::edit_url( $id ) ),
			esc_html( (string) $item['season_label_cs'] ),
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
			case 'course':
				return esc_html( get_the_title( (int) $item['course_id'] ) );

			case 'location':
				$location = $this->locations_by_id[ (int) $item['location_id'] ] ?? null;

				return null === $location ? '' : esc_html( (string) $location['name'] );

			case 'schedule':
				return $this->format_schedule( $item );

			case 'dates':
				return esc_html( $item['date_from'] . ' → ' . $item['date_to'] );

			case 'capacity':
				return null === $item['capacity'] ? esc_html__( 'Unlimited', 'ruben-dance' ) : esc_html( (string) $item['capacity'] );

			case 'price':
				return esc_html( number_format( (float) $item['price'], 2 ) . ' Kč' );

			case 'status':
				return esc_html( $this->status_label( (string) $item['status'] ) );

			default:
				return '';
		}
	}

	/**
	 * "Schedule" column value: type + weekday + time, or "Workshop" for a
	 * single-lesson term.
	 *
	 * Deliberately not named `column_schedule()`: `WP_List_Table::single_row_
	 * columns()` dispatches to any method literally named `column_{$key}` for
	 * every column key returned by `get_columns()`, before ever consulting
	 * `column_default()` — a method named `column_schedule()` here would
	 * collide with that convention and get called directly (and, being
	 * private, fail silently via a `call_user_func()` visibility warning
	 * instead of rendering anything).
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	private function format_schedule( array $item ): string {
		$time_range = self::format_time( (string) $item['start_time'] ) . '–' . self::format_time( (string) $item['end_time'] );

		if ( Term_Service::TYPE_WORKSHOP === $item['type'] ) {
			return esc_html(
				sprintf(
					/* translators: %s: start–end time, e.g. "10:00–16:00". */
					__( 'Workshop, %s', 'ruben-dance' ),
					$time_range
				)
			);
		}

		$weekdays = $this->weekday_labels();
		$weekday  = $weekdays[ (int) $item['weekday'] ] ?? '';

		return esc_html( trim( $weekday . ' ' . $time_range ) );
	}

	/**
	 * Trim a `TIME` column's `HH:MM:SS` (as `$wpdb` returns it) down to
	 * `HH:MM` for display — the seconds are never meaningful for a class
	 * schedule.
	 *
	 * @param string $time Raw `HH:MM:SS` (or already-short `HH:MM`) value.
	 * @return string
	 */
	public static function format_time( string $time ): string {
		return 8 === strlen( $time ) ? substr( $time, 0, 5 ) : $time;
	}

	/**
	 * ISO weekday number (1=Mon...7=Sun) to a translated label.
	 *
	 * @return array<int, string>
	 */
	public static function weekday_labels(): array {
		return array(
			1 => __( 'Monday', 'ruben-dance' ),
			2 => __( 'Tuesday', 'ruben-dance' ),
			3 => __( 'Wednesday', 'ruben-dance' ),
			4 => __( 'Thursday', 'ruben-dance' ),
			5 => __( 'Friday', 'ruben-dance' ),
			6 => __( 'Saturday', 'ruben-dance' ),
			7 => __( 'Sunday', 'ruben-dance' ),
		);
	}

	/**
	 * A term status to its translated label.
	 *
	 * @param string $status One of `Term_Service::STATUSES`.
	 * @return string
	 */
	private function status_label( string $status ): string {
		switch ( $status ) {
			case Term_Service::STATUS_DRAFT:
				return __( 'Draft', 'ruben-dance' );

			case Term_Service::STATUS_OPEN:
				return __( 'Open', 'ruben-dance' );

			case Term_Service::STATUS_CLOSED:
				return __( 'Closed', 'ruben-dance' );

			case Term_Service::STATUS_CANCELLED:
				return __( 'Cancelled', 'ruben-dance' );

			default:
				return $status;
		}
	}

	/**
	 * Message shown when there are no terms matching the active filters.
	 */
	public function no_items() {
		esc_html_e( 'No terms found.', 'ruben-dance' );
	}
}
