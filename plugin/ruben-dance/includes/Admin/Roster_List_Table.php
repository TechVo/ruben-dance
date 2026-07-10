<?php
/**
 * `WP_List_Table` for the admin term roster (F11a).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Services\Roster_Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Roster_List_Table.
 *
 * One row per enrollment, any status (spec F11a) — the term-level filtering
 * lives in `Roster_Page`, not here. Contact info (email/phone) belongs to
 * the account holder (`wp_users`/`rd_phone` meta), not the enrollment row
 * itself, so `$users_by_id`/`$phones_by_id` are prefetched once by the
 * caller and passed in, the same batch-lookup tradeoff `Terms_List_Table`
 * makes for locations — this screen's row count (one term's enrollments)
 * never justifies a per-row query.
 */
class Roster_List_Table extends \WP_List_Table {

	/**
	 * Enrollment rows to display (already loaded by the caller).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $enrollments;

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
	 * Today's date (`Y-m-d`), for the overdue decision.
	 *
	 * @var string
	 */
	private string $today;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $enrollments  Enrollment rows for the term.
	 * @param array<int, \WP_User>             $users_by_id  Account-holder users, keyed by ID.
	 * @param array<int, string>               $phones_by_id `rd_phone` meta, keyed by user ID.
	 * @param string                           $today        Today's `Y-m-d` date.
	 */
	public function __construct( array $enrollments, array $users_by_id, array $phones_by_id, string $today ) {
		parent::__construct(
			array(
				'singular' => 'enrollment',
				'plural'   => 'enrollments',
				'ajax'     => false,
			)
		);

		$this->enrollments  = $enrollments;
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
			'participant' => __( 'Participant', 'ruben-dance' ),
			'contact'     => __( 'Contact', 'ruben-dance' ),
			'role'        => __( 'Role', 'ruben-dance' ),
			'price'       => __( 'Price', 'ruben-dance' ),
			'paid'        => __( 'Paid', 'ruben-dance' ),
		);
	}

	/**
	 * Load rows (already fetched by the caller, see constructor).
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->enrollments;
	}

	/**
	 * "Participant" column: name (account-holder fallback), badges, note
	 * indicator, and the "View details" row action (spec F11a: "row click →
	 * enrollment detail").
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	protected function column_participant( array $item ): string {
		$id      = (int) $item['id'];
		$user    = $this->users_by_id[ (int) $item['user_id'] ] ?? null;
		$holder  = null === $user ? '' : $user->display_name;
		$name    = '' !== trim( (string) $item['participant_name'] ) ? (string) $item['participant_name'] : $holder;
		$is_self = '' === trim( (string) $item['participant_name'] );

		$html = '<strong><a href="' . esc_url( Enrollment_Detail_Page::url( $id ) ) . '">' . esc_html( '' !== $name ? $name : __( '(unknown)', 'ruben-dance' ) ) . '</a></strong>';

		if ( ! $is_self && '' !== $holder ) {
			$html .= '<br><span class="description">' . sprintf(
				/* translators: %s: account holder's name. */
				esc_html__( 'account: %s', 'ruben-dance' ),
				esc_html( $holder )
			) . '</span>';
		}

		// The wrapping span gives Roster_Page's inline AJAX script a stable
		// target: after a mark/unmark it needs to add or remove the overdue
		// badge in place, without knowing anything else about this column's
		// markup (see Roster_Page::render_ajax_script()).
		$html .= '<br><span class="rd-roster-badges">' . $this->badges( $item ) . '</span>';

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
	 * Badges: over-capacity, overdue, cancelled, note indicator (spec F11a).
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	private function badges( array $item ): string {
		$badges = array();

		$status = (string) $item['status'];

		if ( 'cancelled' === $status ) {
			$badges[] = self::badge( __( 'Cancelled', 'ruben-dance' ), '#dc3232' );
		}

		if ( ! empty( $item['over_capacity'] ) ) {
			$badges[] = self::badge( __( 'Over capacity', 'ruben-dance' ), '#996800' );
		}

		if ( ( new Roster_Stats() )->is_overdue( $item, $this->today ) ) {
			// Tagged with a dedicated class: Roster_Page's inline AJAX script
			// removes this specific badge after a successful "mark paid"
			// (marking paid always makes the row no-longer-overdue), without
			// having to know about any of this table's other markup.
			$badges[] = self::badge( __( 'Overdue', 'ruben-dance' ), '#dc3232', 'rd-roster-badge-overdue' );
		}

		if ( '' !== trim( (string) ( $item['customer_note'] ?? '' ) ) || '' !== trim( (string) ( $item['admin_note'] ?? '' ) ) ) {
			$badges[] = self::badge( __( 'Note', 'ruben-dance' ), '#2271b1' );
		}

		return implode( ' ', $badges );
	}

	/**
	 * One small colored badge `<span>`.
	 *
	 * @param string $label      Already-translated label.
	 * @param string $color      CSS color (hex).
	 * @param string $extra_class Optional extra CSS class (see the overdue badge above).
	 * @return string
	 */
	private static function badge( string $label, string $color, string $extra_class = '' ): string {
		return sprintf(
			'<span class="%3$s" style="display:inline-block;padding:1px 6px;margin-right:3px;border-radius:3px;background:%1$s;color:#fff;font-size:11px;line-height:1.6;">%2$s</span>',
			esc_attr( $color ),
			esc_html( $label ),
			esc_attr( $extra_class )
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

			case 'paid':
				return $this->format_paid( $item );

			default:
				return '';
		}
	}

	/**
	 * "Contact" column value: account holder's email + phone (the
	 * participant, e.g. an enrolled child, has no account of its own — spec
	 * §3.2: "empty = the account holder").
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
	 * "Role" column value: role label, plus the stated partner name when set
	 * (spec §3.2: "coming with a partner ... free text").
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
	 * "Paid" column value: the AJAX toggle button (spec F11a: "single click,
	 * AJAX, no page reload"), disabled for a cancelled enrollment — there is
	 * no legal `cancelled → paid`/`cancelled → confirmed` transition (see
	 * `Services\Enrollment_Service::mark_paid()`/`unmark_paid()`).
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	private function format_paid( array $item ): string {
		$id     = (int) $item['id'];
		$status = (string) $item['status'];

		if ( 'cancelled' === $status ) {
			return '<span aria-hidden="true">—</span>';
		}

		$is_paid = 'paid' === $status;
		$action  = $is_paid ? 'unmark' : 'mark';
		$label   = $is_paid ? __( 'Unmark paid', 'ruben-dance' ) : __( 'Mark paid', 'ruben-dance' );

		$html = sprintf(
			'<button type="button" class="button%1$s rd-roster-toggle-paid" data-enrollment-id="%2$d" data-action="%3$s">%4$s</button>',
			$is_paid ? '' : ' button-primary',
			$id,
			esc_attr( $action ),
			esc_html( $label )
		);

		if ( $is_paid && '' !== (string) ( $item['paid_at'] ?? '' ) ) {
			$html .= '<br><span class="description rd-roster-paid-at">' . esc_html(
				sprintf(
					/* translators: %s: date/time the enrollment was marked paid. */
					__( 'on %s', 'ruben-dance' ),
					mysql2date( 'j M Y H:i', (string) $item['paid_at'] )
				)
			) . '</span>';
		}

		return $html;
	}

	/**
	 * Message shown when the term has no enrollments at all.
	 */
	public function no_items() {
		esc_html_e( 'No enrollments for this term yet.', 'ruben-dance' );
	}
}
