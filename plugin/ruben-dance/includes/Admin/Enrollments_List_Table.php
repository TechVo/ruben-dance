<?php
/**
 * `WP_List_Table` for the cross-term enrollments admin screen (F11b).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Roster_Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Enrollments_List_Table.
 *
 * One row per enrollment across *every* term (spec F11b: "all enrollments"),
 * as opposed to `Roster_List_Table`'s single-term scope — filtering/search/
 * pagination all happen in `Repositories\Enrollment_Repository::search()`/
 * `count_search()`, this class only renders whatever page of rows the caller
 * already fetched. No inline paid-toggle button here (that stays a
 * roster-only affordance, spec F11a); this screen is read-plus-navigate:
 * every row links to `Enrollment_Detail_Page` where the real actions live.
 */
class Enrollments_List_Table extends \WP_List_Table {

	/**
	 * Enrollment rows for the current page (already loaded by the caller).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $enrollments;

	/**
	 * Term rows keyed by ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $terms_by_id;

	/**
	 * WP_User objects keyed by user ID.
	 *
	 * @var array<int, \WP_User>
	 */
	private array $users_by_id;

	/**
	 * `rd_phone` user meta keyed by user ID.
	 *
	 * @var array<int, string>
	 */
	private array $phones_by_id;

	/**
	 * Today's date (`Y-m-d`), for the overdue decision/row highlight.
	 *
	 * @var string
	 */
	private string $today;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $enrollments  Enrollment rows for the current page.
	 * @param array<int, array<string, mixed>> $terms_by_id  Term rows, keyed by ID.
	 * @param array<int, \WP_User>             $users_by_id  Account-holder users, keyed by ID.
	 * @param array<int, string>               $phones_by_id `rd_phone` meta, keyed by user ID.
	 * @param string                           $today        Today's `Y-m-d` date.
	 */
	public function __construct( array $enrollments, array $terms_by_id, array $users_by_id, array $phones_by_id, string $today ) {
		parent::__construct(
			array(
				'singular' => 'enrollment',
				'plural'   => 'enrollments',
				'ajax'     => false,
			)
		);

		$this->enrollments  = $enrollments;
		$this->terms_by_id  = $terms_by_id;
		$this->users_by_id  = $users_by_id;
		$this->phones_by_id = $phones_by_id;
		$this->today        = $today;
	}

	/**
	 * Column definitions.
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return array(
			'term'        => __( 'Term', 'ruben-dance' ),
			'participant' => __( 'Participant', 'ruben-dance' ),
			'contact'     => __( 'Contact', 'ruben-dance' ),
			'role'        => __( 'Role', 'ruben-dance' ),
			'price'       => __( 'Price', 'ruben-dance' ),
			'status'      => __( 'Status', 'ruben-dance' ),
		);
	}

	/**
	 * Load rows (pagination/total already computed by the caller — see
	 * `Enrollments_Page::render_list()`).
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->enrollments;
	}

	/**
	 * Highlight overdue-unpaid rows (spec F11b: "overdue-unpaid rows
	 * highlighted"), on top of `WP_List_Table`'s normal row rendering.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function single_row( $item ) {
		$overdue = ( new Roster_Stats() )->is_overdue( $item, $this->today );

		echo '<tr' . ( $overdue ? ' style="background-color:#fbeaea;"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal, hardcoded style string; $overdue is a bool, nothing from input reaches this markup.
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * "Term" column: season label + course + a link to that term's roster.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	protected function column_term( array $item ): string {
		$term_id = (int) $item['term_id'];
		$term    = $this->terms_by_id[ $term_id ] ?? null;

		if ( null === $term ) {
			return '<em>' . esc_html__( '(term no longer exists)', 'ruben-dance' ) . '</em>';
		}

		$label = (string) $term['season_label_cs'] . ' — ' . get_the_title( (int) $term['course_id'] );

		return '<a href="' . esc_url( Roster_Page::url( $term_id ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * "Participant" column: name (account-holder fallback), badges, and the
	 * "View details" row action (same as `Roster_List_Table::column_participant()`).
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	protected function column_participant( array $item ): string {
		$id     = (int) $item['id'];
		$user   = $this->users_by_id[ (int) $item['user_id'] ] ?? null;
		$holder = null === $user ? '' : $user->display_name;
		$name   = '' !== trim( (string) $item['participant_name'] ) ? (string) $item['participant_name'] : $holder;

		$html = '<strong><a href="' . esc_url( Enrollment_Detail_Page::url( $id ) ) . '">' . esc_html( '' !== $name ? $name : __( '(unknown)', 'ruben-dance' ) ) . '</a></strong>';

		if ( '' !== trim( (string) $item['participant_name'] ) && '' !== $holder ) {
			$html .= '<br><span class="description">' . sprintf(
				/* translators: %s: account holder's name. */
				esc_html__( 'account: %s', 'ruben-dance' ),
				esc_html( $holder )
			) . '</span>';
		}

		$html .= '<br>' . $this->badges( $item );

		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Enrollment_Detail_Page::url( $id ) ),
				esc_html__( 'View details', 'ruben-dance' )
			),
		);

		return $html . $this->row_actions( $actions );
	}

	/**
	 * Over-capacity/note badges (overdue and cancelled are already carried
	 * by the "Status"/row-highlight, so they aren't repeated here).
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	private function badges( array $item ): string {
		$badges = array();

		if ( ! empty( $item['over_capacity'] ) ) {
			$badges[] = self::badge( __( 'Over capacity', 'ruben-dance' ), '#996800' );
		}

		if ( '' !== trim( (string) ( $item['customer_note'] ?? '' ) ) || '' !== trim( (string) ( $item['admin_note'] ?? '' ) ) ) {
			$badges[] = self::badge( __( 'Note', 'ruben-dance' ), '#2271b1' );
		}

		return implode( ' ', $badges );
	}

	/**
	 * One small colored badge `<span>` (mirrors `Roster_List_Table::badge()`).
	 *
	 * @param string $label Already-translated label.
	 * @param string $color CSS color (hex).
	 * @return string
	 */
	private static function badge( string $label, string $color ): string {
		return sprintf(
			'<span style="display:inline-block;padding:1px 6px;margin-right:3px;border-radius:3px;background:%1$s;color:#fff;font-size:11px;line-height:1.6;">%2$s</span>',
			esc_attr( $color ),
			esc_html( $label )
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
			case 'contact':
				return $this->format_contact( $item );

			case 'role':
				return $this->format_role( $item );

			case 'price':
				return esc_html( number_format( (float) $item['price'], 2 ) . ' Kč' );

			case 'status':
				return $this->format_status( $item );

			default:
				return '';
		}
	}

	/**
	 * "Contact" column value: account holder's email + phone.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	private function format_contact( array $item ): string {
		$user = $this->users_by_id[ (int) $item['user_id'] ] ?? null;

		if ( null === $user ) {
			return '';
		}

		$phone = $this->phones_by_id[ (int) $item['user_id'] ] ?? '';

		$html = esc_html( $user->user_email );

		if ( '' !== $phone ) {
			$html .= '<br>' . esc_html( $phone );
		}

		return $html;
	}

	/**
	 * "Role" column value: role label, plus the stated partner name when set.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	private function format_role( array $item ): string {
		$labels = array(
			'solo'     => __( 'Solo', 'ruben-dance' ),
			'leader'   => __( 'Leader', 'ruben-dance' ),
			'follower' => __( 'Follower', 'ruben-dance' ),
		);

		$html = esc_html( $labels[ (string) $item['role'] ] ?? (string) $item['role'] );

		$partner_name = trim( (string) ( $item['partner_name'] ?? '' ) );

		if ( '' !== $partner_name ) {
			$html .= '<br><span class="description">' . sprintf(
				/* translators: %s: partner's name. */
				esc_html__( 'with %s', 'ruben-dance' ),
				esc_html( $partner_name )
			) . '</span>';
		}

		return $html;
	}

	/**
	 * "Status" column value: Paid/Unpaid/Cancelled, plus "Overdue" when
	 * applicable (the row itself is already highlighted, see `single_row()`
	 * — this label is what makes that highlight legible to a screen reader
	 * or a printed/exported view).
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	private function format_status( array $item ): string {
		$status = (string) $item['status'];

		switch ( $status ) {
			case Enrollment_Service::STATUS_PAID:
				return esc_html__( 'Paid', 'ruben-dance' );

			case Enrollment_Service::STATUS_CANCELLED:
				return '<em>' . esc_html__( 'Cancelled', 'ruben-dance' ) . '</em>';

			default:
				return ( new Roster_Stats() )->is_overdue( $item, $this->today )
					? '<strong>' . esc_html__( 'Unpaid — overdue', 'ruben-dance' ) . '</strong>'
					: esc_html__( 'Unpaid', 'ruben-dance' );
		}
	}

	/**
	 * Message shown when no enrollment matches the active filters.
	 */
	public function no_items() {
		esc_html_e( 'No enrollments match these filters.', 'ruben-dance' );
	}
}
