<?php
/**
 * Term roster admin screen (F11a): the owners' main daily-use screen.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Roles;
use RubenDance\Services\Registration_Service;
use RubenDance\Services\Roster_Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Roster_Page.
 *
 * Two views behind one menu entry, switched on the `term_id` query arg (no
 * `term_id` = term picker, same "pick a term first" shape as
 * `Term_Lessons_Page`): a plain term picker, then the roster itself — header
 * stats + `Roster_List_Table` + an inline AJAX script for the paid toggle
 * (spec F11a: "single click, AJAX, no page reload"). `stat_fragments()` is
 * `public static` and reused verbatim by `Roster_Ajax` so the header stats
 * shown on first load and after a toggle are computed and formatted by
 * exactly one method, never two copies drifting apart.
 */
class Roster_Page {

	const SLUG = 'ruben-dance-roster';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_rd_roster_export_csv', array( self::class, 'handle_export' ) );
	}

	/**
	 * Add the "Roster" submenu page under the Ruben Dance top-level menu.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			Menu::SLUG,
			__( 'Roster', 'ruben-dance' ),
			__( 'Roster', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * URL to a term's roster, or to the term picker when `$term_id` is 0.
	 *
	 * @param int $term_id Term ID (0 = term picker).
	 * @return string
	 */
	public static function url( int $term_id ): string {
		$args = array( 'page' => self::SLUG );

		if ( $term_id > 0 ) {
			$args['term_id'] = $term_id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Nonce-protected URL for the CSV export.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	public static function export_url( int $term_id ): string {
		$url = add_query_arg(
			array(
				'action'  => 'rd_roster_export_csv',
				'term_id' => $term_id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::export_nonce_action( $term_id ) );
	}

	/**
	 * Nonce action string for a term's CSV export.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private static function export_nonce_action( int $term_id ): string {
		return 'rd_roster_export_' . $term_id;
	}

	/**
	 * Main entry point, wired as the submenu page callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view the roster.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which term's roster to show, no state change.
		$term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;

		if ( $term_id > 0 ) {
			self::render_roster( $term_id );
			return;
		}

		self::render_term_picker();
	}

	/**
	 * Render the "pick a term" landing view.
	 */
	private static function render_term_picker(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Term roster', 'ruben-dance' ) . '</h1>';
		echo '<p>' . esc_html__( 'Pick a term to see who is enrolled and manage payments.', 'ruben-dance' ) . '</p>';

		$terms = ( new Course_Term_Repository() )->all_with_filters();

		if ( array() === $terms ) {
			echo '<p>' . esc_html__( 'No terms yet — create one first.', 'ruben-dance' ) . '</p>';
			echo '</div>';
			return;
		}

		$locations_by_id = array();
		foreach ( ( new Location_Repository() )->all() as $location ) {
			$locations_by_id[ (int) $location['id'] ] = $location;
		}

		echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Season / Term', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Course', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Location', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Dates', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ruben-dance' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $terms as $term ) {
			$location = $locations_by_id[ (int) $term['location_id'] ] ?? null;

			echo '<tr>';
			echo '<td><a href="' . esc_url( self::url( (int) $term['id'] ) ) . '"><strong>' . esc_html( (string) $term['season_label_cs'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( get_the_title( (int) $term['course_id'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $location ? '' : (string) $location['name'] ) . '</td>';
			echo '<td>' . esc_html( $term['date_from'] . ' → ' . $term['date_to'] ) . '</td>';
			echo '<td>' . esc_html( (string) $term['status'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Render one term's roster: header stats + list table + AJAX script.
	 *
	 * @param int $term_id Term ID.
	 */
	private static function render_roster( int $term_id ): void {
		$term = ( new Course_Term_Repository() )->find( $term_id );

		echo '<div class="wrap">';

		if ( null === $term ) {
			echo '<h1>' . esc_html__( 'Term roster', 'ruben-dance' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Term not found.', 'ruben-dance' ) . '</p></div>';
			echo '<p><a href="' . esc_url( self::url( 0 ) ) . '">' . esc_html__( 'Back to term picker', 'ruben-dance' ) . '</a></p>';
			echo '</div>';
			return;
		}

		$location = ( new Location_Repository() )->find( (int) $term['location_id'] );

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Term roster', 'ruben-dance' ) . '</h1> ';
		echo '<a href="' . esc_url( self::export_url( $term_id ) ) . '" class="page-title-action">' . esc_html__( 'Export CSV', 'ruben-dance' ) . '</a>';
		echo '<hr class="wp-header-end">';

		echo '<p>';
		printf(
			/* translators: 1: season label, 2: course title, 3: location name. */
			esc_html__( '%1$s — %2$s at %3$s', 'ruben-dance' ),
			esc_html( (string) $term['season_label_cs'] ),
			esc_html( get_the_title( (int) $term['course_id'] ) ),
			esc_html( null === $location ? '' : (string) $location['name'] )
		);
		echo ' — <a href="' . esc_url( self::url( 0 ) ) . '">' . esc_html__( 'change term', 'ruben-dance' ) . '</a></p>';

		self::render_notice_from_query();

		$enrollments = ( new Enrollment_Repository() )->for_term( $term_id );
		$capacity    = self::capacity_of( $term );
		$stats       = ( new Roster_Stats() )->compute( $enrollments, $capacity );

		self::render_stats( $stats );

		list( $users_by_id, $phones_by_id ) = self::load_users( $enrollments );

		$table = new Roster_List_Table( $enrollments, $users_by_id, $phones_by_id, current_time( 'Y-m-d' ) );
		$table->prepare_items();
		$table->display();

		self::render_ajax_script( $term_id );

		echo '</div>';
	}

	/**
	 * A term's capacity as `int|null` (NULL = unlimited, spec §3.2), from the
	 * raw repository row's mixed/blank-string shape.
	 *
	 * @param array<string, mixed> $term Term row.
	 * @return int|null
	 */
	private static function capacity_of( array $term ): ?int {
		$capacity = $term['capacity'] ?? null;

		return ( null === $capacity || '' === (string) $capacity ) ? null : (int) $capacity;
	}

	/**
	 * Render the header stats block (spec F11a: "paid/total count vs.
	 * capacity, leader/follower/solo breakdown ..., sum collected vs.
	 * expected"). Each figure sits in its own `id`-tagged element so
	 * `Roster_Ajax`'s response can update just that element after a
	 * mark/unmark, without a page reload (see `render_ajax_script()`).
	 *
	 * @param array<string, mixed> $stats `Roster_Stats::compute()`'s return shape.
	 */
	private static function render_stats( array $stats ): void {
		$fragments = self::stat_fragments( $stats );

		echo '<p style="font-size:14px;">';
		echo '<strong id="rd-roster-stat-paid">' . esc_html( $fragments['paid'] ) . '</strong>';
		echo ' &nbsp;·&nbsp; <span id="rd-roster-stat-capacity">' . esc_html( $fragments['capacity'] ) . '</span>';
		echo ' &nbsp;·&nbsp; <span id="rd-roster-stat-roles">' . esc_html( $fragments['roles'] ) . '</span>';
		echo ' &nbsp;·&nbsp; <span id="rd-roster-stat-money">' . esc_html( $fragments['money'] ) . '</span>';
		echo '</p>';
	}

	/**
	 * Format `Roster_Stats::compute()`'s numbers into the plain-text strings
	 * the header shows — the single formatting authority both the initial
	 * page render (`render_stats()`) and `Roster_Ajax`'s JSON response
	 * (consumed by the inline script in `render_ajax_script()`) call, so the
	 * two can never drift apart.
	 *
	 * @param array{paid: int, total: int, capacity: int|null, solo: int, leader: int, follower: int, collected: float, expected: float} $stats `Roster_Stats::compute()`'s return shape.
	 * @return array{paid: string, capacity: string, roles: string, money: string}
	 */
	public static function stat_fragments( array $stats ): array {
		return array(
			'paid'     => sprintf(
				/* translators: 1: number of enrollments marked paid, 2: total active (non-cancelled) enrollments. */
				__( '%1$d / %2$d paid', 'ruben-dance' ),
				$stats['paid'],
				$stats['total']
			),
			'capacity' => null === $stats['capacity']
				? __( 'capacity: unlimited', 'ruben-dance' )
				: sprintf(
					/* translators: 1: active enrollment count, 2: term capacity. */
					__( 'capacity: %1$d / %2$d', 'ruben-dance' ),
					$stats['total'],
					$stats['capacity']
				),
			'roles'    => sprintf(
				/* translators: 1: solo count, 2: leader count, 3: follower count. */
				__( 'Solo: %1$d · Leader: %2$d · Follower: %3$d', 'ruben-dance' ),
				$stats['solo'],
				$stats['leader'],
				$stats['follower']
			),
			'money'    => sprintf(
				/* translators: 1: amount collected so far, 2: total amount expected. */
				__( '%1$s Kč collected of %2$s Kč expected', 'ruben-dance' ),
				number_format( $stats['collected'], 2 ),
				number_format( $stats['expected'], 2 )
			),
		);
	}

	/**
	 * Batch-load the account-holder users + `rd_phone` meta for a set of
	 * enrollments, so `Roster_List_Table` never issues one query per row
	 * (same tradeoff `Terms_List_Table` makes for locations).
	 *
	 * @param array<int, array<string, mixed>> $enrollments Enrollment rows.
	 * @return array{0: array<int, \WP_User>, 1: array<int, string>}
	 */
	private static function load_users( array $enrollments ): array {
		$ids = array();

		foreach ( $enrollments as $enrollment ) {
			$ids[] = (int) $enrollment['user_id'];
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( array() === $ids ) {
			return array( array(), array() );
		}

		$users = get_users(
			array(
				'include' => $ids,
				'fields'  => 'all',
			)
		);

		$users_by_id = array();
		foreach ( $users as $user ) {
			$users_by_id[ $user->ID ] = $user;
		}

		// Primes the user-meta cache for every ID in one query, so the
		// per-user get_user_meta() calls below hit the cache instead of the
		// database (the standard WordPress batch-meta-load idiom).
		update_meta_cache( 'user', $ids );

		$phones_by_id = array();
		foreach ( $ids as $id ) {
			$phone               = get_user_meta( $id, Registration_Service::META_PHONE, true );
			$phones_by_id[ $id ] = is_string( $phone ) ? $phone : '';
		}

		return array( $users_by_id, $phones_by_id );
	}

	/**
	 * Inline AJAX script for the paid toggle (spec F11a: "single click,
	 * AJAX, no page reload"). Follows the same "inline `<script>`, no
	 * enqueued asset file" pattern `Terms_Page::render_type_toggle_script()`
	 * uses — this is the first admin screen with real client/server
	 * round-trips, but still small enough not to justify a build step.
	 *
	 * @param int $term_id Term ID (unused by the script itself, kept for a
	 *                      future "refresh whole table" affordance without
	 *                      changing the call site).
	 */
	private static function render_ajax_script( int $term_id ): void {
		unset( $term_id );

		$config = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( Roster_Ajax::NONCE_ACTION ),
			'markAction'   => Roster_Ajax::ACTION_MARK,
			'unmarkAction' => Roster_Ajax::ACTION_UNMARK,
			'emailAction'  => Roster_Ajax::ACTION_SEND_EMAIL,
			'i18n'         => array(
				'confirmSendEmail' => __( 'Send the payment confirmation email now?', 'ruben-dance' ),
				'emailSent'        => __( 'Confirmation email sent.', 'ruben-dance' ),
				'emailFailed'      => __( 'Could not send the confirmation email.', 'ruben-dance' ),
				'requestFailed'    => __( 'Something went wrong — please reload and try again.', 'ruben-dance' ),
				'markLabel'        => __( 'Mark paid', 'ruben-dance' ),
				'unmarkLabel'      => __( 'Unmark paid', 'ruben-dance' ),
				'overdueLabel'     => __( 'Overdue', 'ruben-dance' ),
			),
		);
		?>
		<script>
		( function () {
			var rdRoster = <?php echo wp_json_encode( $config ); ?>;

			function updateStats( stats ) {
				var map = {
					paid: 'rd-roster-stat-paid',
					capacity: 'rd-roster-stat-capacity',
					roles: 'rd-roster-stat-roles',
					money: 'rd-roster-stat-money'
				};

				Object.keys( map ).forEach( function ( key ) {
					var el = document.getElementById( map[ key ] );
					if ( el && undefined !== stats[ key ] ) {
						el.textContent = stats[ key ];
					}
				} );
			}

			function updateRow( button, enrollment ) {
				var cell = button.closest( 'td' );
				var isPaid = !! enrollment.paid;

				button.dataset.action = isPaid ? 'unmark' : 'mark';
				button.textContent = isPaid ? rdRoster.i18n.unmarkLabel : rdRoster.i18n.markLabel;
				button.classList.toggle( 'button-primary', ! isPaid );

				if ( cell ) {
					var note = cell.querySelector( '.rd-roster-paid-at' );
					if ( note ) {
						var prev = note.previousElementSibling;
						if ( prev && 'BR' === prev.tagName ) {
							prev.remove();
						}
						note.remove();
					}

					if ( isPaid && enrollment.paidAtLabel ) {
						cell.appendChild( document.createElement( 'br' ) );
						var span = document.createElement( 'span' );
						span.className = 'description rd-roster-paid-at';
						span.textContent = enrollment.paidAtLabel;
						cell.appendChild( span );
					}
				}

				var row = button.closest( 'tr' );
				var badgesContainer = row ? row.querySelector( '.rd-roster-badges' ) : null;
				var overdueBadge = badgesContainer ? badgesContainer.querySelector( '.rd-roster-badge-overdue' ) : null;

				if ( overdueBadge ) {
					overdueBadge.remove();
				}

				// A fresh mark always clears overdue (nothing left to chase);
				// an unmark can leave a due_date that is already in the past,
				// in which case the badge must reappear immediately rather
				// than waiting for a page reload — `enrollment.overdue` is
				// server-computed per-request, never inferred client-side.
				if ( badgesContainer && enrollment.overdue ) {
					var badge = document.createElement( 'span' );
					badge.className = 'rd-roster-badge-overdue';
					badge.setAttribute( 'style', 'display:inline-block;padding:1px 6px;margin-right:3px;border-radius:3px;background:#dc3232;color:#fff;font-size:11px;line-height:1.6;' );
					badge.textContent = rdRoster.i18n.overdueLabel;
					badgesContainer.appendChild( badge );
				}
			}

			function post( action, enrollmentId ) {
				var body = new URLSearchParams();
				body.set( 'action', action );
				body.set( 'enrollment_id', enrollmentId );
				body.set( 'nonce', rdRoster.nonce );

				return fetch( rdRoster.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} ).then( function ( response ) {
					return response.json();
				} );
			}

			document.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '.rd-roster-toggle-paid' );

				if ( ! button || button.disabled ) {
					return;
				}

				event.preventDefault();

				var enrollmentId = button.dataset.enrollmentId;
				var isMark = 'mark' === button.dataset.action;
				var action = isMark ? rdRoster.markAction : rdRoster.unmarkAction;

				button.disabled = true;

				post( action, enrollmentId ).then( function ( response ) {
					button.disabled = false;

					if ( ! response || ! response.success ) {
						window.alert( ( response && response.data && response.data.message ) ? response.data.message : rdRoster.i18n.requestFailed );
						return;
					}

					updateRow( button, response.data.enrollment );
					updateStats( response.data.stats );

					// Unmark never asks about email (spec F11a: "never sends
					// an email"); only a fresh mark offers the E4 prompt,
					// defaulting to "send" via the confirm() dialog's OK button.
					if ( isMark && window.confirm( rdRoster.i18n.confirmSendEmail ) ) {
						post( rdRoster.emailAction, enrollmentId ).then( function ( emailResponse ) {
							window.alert( ( emailResponse && emailResponse.success ) ? rdRoster.i18n.emailSent : rdRoster.i18n.emailFailed );
						} );
					}
				} ).catch( function () {
					button.disabled = false;
					window.alert( rdRoster.i18n.requestFailed );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Handle the CSV export (spec F11a: "CSV export of the roster
	 * (attendance sheet)"). Registered on `admin_post_rd_roster_export_csv`
	 * (see `register()`) rather than folded into `render()`, the standard
	 * WordPress way to stream a file download instead of an admin page.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to export the roster.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only builds the nonce action string to verify; check_admin_referer() immediately below performs the real verification before any output is sent.
		$term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;

		check_admin_referer( self::export_nonce_action( $term_id ) );

		$term = ( new Course_Term_Repository() )->find( $term_id );

		if ( null === $term ) {
			wp_die( esc_html__( 'Term not found.', 'ruben-dance' ), '', array( 'response' => 404 ) );
		}

		$enrollments = ( new Enrollment_Repository() )->for_term( $term_id );

		list( $users_by_id, $phones_by_id ) = self::load_users( $enrollments );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'roster-' . $term['season_label_cs'] . '-' . $term_id . '.csv' ) . '"' );

		// php://output is a write-only response stream, not a real file —
		// WP_Filesystem has no concept of it, so the direct fopen()/fwrite()/
		// fclose() calls in this method are the only way to stream a CSV
		// download; see e.g. WooCommerce's own CSV exporters for the same
		// exception.
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		// UTF-8 byte-order mark: without it Excel guesses a Windows codepage
		// and mangles Czech diacritics (spec M11 acceptance criteria: "CSV
		// opens in Excel/LibreOffice with correct diacritics").
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		fputcsv(
			$out,
			array(
				__( 'Participant', 'ruben-dance' ),
				__( 'Contact', 'ruben-dance' ),
				__( 'Role', 'ruben-dance' ),
				__( 'Paid', 'ruben-dance' ),
			)
		);

		foreach ( $enrollments as $enrollment ) {
			$user   = $users_by_id[ (int) $enrollment['user_id'] ] ?? null;
			$holder = null === $user ? '' : $user->display_name;
			$name   = '' !== trim( (string) $enrollment['participant_name'] ) ? (string) $enrollment['participant_name'] : $holder;

			$contact = null === $user ? '' : $user->user_email;
			$phone   = $phones_by_id[ (int) $enrollment['user_id'] ] ?? '';

			if ( '' !== $phone ) {
				$contact .= ' / ' . $phone;
			}

			fputcsv(
				$out,
				array(
					$name,
					$contact,
					self::csv_role_label( (string) $enrollment['role'] ),
					self::csv_paid_label( (string) $enrollment['status'] ),
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * A role value to its CSV label (kept separate from
	 * `Roster_List_Table::format_role()` — the CSV column intentionally
	 * omits the partner name, which belongs on-screen only).
	 *
	 * @param string $role One of `Services\Enrollment_Service::ROLES`.
	 * @return string
	 */
	private static function csv_role_label( string $role ): string {
		switch ( $role ) {
			case 'leader':
				return __( 'Leader', 'ruben-dance' );

			case 'follower':
				return __( 'Follower', 'ruben-dance' );

			default:
				return __( 'Solo', 'ruben-dance' );
		}
	}

	/**
	 * A status value to its CSV "Paid" column label.
	 *
	 * @param string $status One of `Services\Enrollment_Service::STATUSES`.
	 * @return string
	 */
	private static function csv_paid_label( string $status ): string {
		switch ( $status ) {
			case 'paid':
				return __( 'Yes', 'ruben-dance' );

			case 'cancelled':
				return __( 'Cancelled', 'ruben-dance' );

			default:
				return __( 'No', 'ruben-dance' );
		}
	}

	/**
	 * Read the `rd_notice` query arg left by a redirect and render it.
	 * Currently unused by any redirect in this milestone (no roster action
	 * redirects — the paid toggle is AJAX-only) but kept for the same
	 * "notice-from-query" convention every other admin screen follows, ready
	 * for M12's roster actions (cancel, move, etc.) to reuse.
	 */
	private static function render_notice_from_query(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: purely cosmetic (which notice text to show after a redirect), no state change.
		$notice = isset( $_GET['rd_notice'] ) ? sanitize_key( wp_unslash( $_GET['rd_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sanitize_text_field( $notice ) )
		);
	}
}
