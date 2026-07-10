<?php
/**
 * Email log admin screen (spec F14/§6.1): every send the plugin ever made,
 * searchable — metadata + subject only, never bodies.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Emails\Email_Templates;
use RubenDance\Repositories\Email_Log_Repository;
use RubenDance\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Email_Log_Page.
 *
 * Read-only, the same "every request is a GET, no `load-{hook}` handler
 * needed" reasoning as `Enrollments_Page`: search + pagination happen in
 * `Repositories\Email_Log_Repository::search()`, and every row with an
 * enrollment reference links into `Enrollment_Detail_Page` (whose own email
 * history section shows the same rows scoped to that enrollment). Kept as a
 * plain table rather than a `WP_List_Table` subclass — a five-column,
 * filter-less log ("a simple log screen with search", M13) doesn't need
 * column callbacks or bulk actions.
 */
class Email_Log_Page {

	const SLUG = 'ruben-dance-email-log';

	const PER_PAGE = 25;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the "Email Log" submenu page under the Ruben Dance top-level menu.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			Menu::SLUG,
			__( 'Email Log', 'ruben-dance' ),
			__( 'Email Log', 'ruben-dance' ),
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
				esc_html__( 'You do not have permission to view the email log.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the list shows, no state change.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which page of rows the list shows, no state change.
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$paged = $paged > 0 ? $paged : 1;

		$repository = new Email_Log_Repository();
		$total      = $repository->count_search( $search );
		$rows       = $repository->search( $search, self::PER_PAGE, $paged );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Email Log', 'ruben-dance' ) . '</h1>';
		echo '<p>' . esc_html__( 'Every email the plugin sent (or failed to send). Only metadata and the subject are stored — never full bodies (GDPR §6.1).', 'ruben-dance' ) . '</p>';

		echo '<form method="get" style="margin:1em 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search recipient, subject or type…', 'ruben-dance' ) . '"> ';
		submit_button( __( 'Search', 'ruben-dance' ), '', '', false );
		echo '</form>';

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No log entries match.', 'ruben-dance' ) . '</p>';
			echo '</div>';
			return;
		}

		self::render_table( $rows );
		self::render_pagination( $total, $paged, $search );

		echo '</div>';
	}

	/**
	 * The log table itself.
	 *
	 * @param array<int, array<string, mixed>> $rows Log rows for this page.
	 */
	private static function render_table( array $rows ): void {
		$type_labels = Email_Templates::type_labels();

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Sent', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Recipient', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Subject', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ruben-dance' ) . '</th>';
		echo '<th>' . esc_html__( 'Enrollment', 'ruben-dance' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$type = (string) $row['type'];

			echo '<tr>';
			echo '<td>' . esc_html( mysql2date( 'j M Y H:i', (string) $row['sent_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $type_labels[ $type ] ?? $type ) . '</td>';
			echo '<td>' . esc_html( (string) $row['recipient'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['subject'] ) . '</td>';

			if ( 'failed' === (string) $row['status'] ) {
				echo '<td><strong style="color:#b32d2e;">' . esc_html__( 'Failed', 'ruben-dance' ) . '</strong></td>';
			} else {
				echo '<td>' . esc_html__( 'Sent', 'ruben-dance' ) . '</td>';
			}

			$enrollment_id = null === $row['enrollment_id'] ? 0 : (int) $row['enrollment_id'];

			if ( $enrollment_id > 0 ) {
				echo '<td><a href="' . esc_url( Enrollment_Detail_Page::url( $enrollment_id ) ) . '">#' . esc_html( (string) $enrollment_id ) . '</a></td>';
			} else {
				echo '<td>—</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Simple previous/next pagination links.
	 *
	 * @param int    $total  Total matching rows.
	 * @param int    $paged  Current 1-based page.
	 * @param string $search Active search text (carried through the links).
	 */
	private static function render_pagination( int $total, int $paged, string $search ): void {
		$total_pages = (int) ceil( $total / self::PER_PAGE );

		if ( $total_pages <= 1 ) {
			return;
		}

		$base_args = array( 'page' => self::SLUG );

		if ( '' !== $search ) {
			$base_args['s'] = $search;
		}

		echo '<p style="margin-top:1em;">';

		if ( $paged > 1 ) {
			$prev_url = add_query_arg( array_merge( $base_args, array( 'paged' => $paged - 1 ) ), admin_url( 'admin.php' ) );
			echo '<a class="button" href="' . esc_url( $prev_url ) . '">&laquo; ' . esc_html__( 'Previous', 'ruben-dance' ) . '</a> ';
		}

		printf(
			'<span style="margin:0 .5em;">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: current page, 2: total pages, 3: total rows. */
					__( 'Page %1$d of %2$d (%3$d entries)', 'ruben-dance' ),
					$paged,
					$total_pages,
					$total
				)
			)
		);

		if ( $paged < $total_pages ) {
			$next_url = add_query_arg( array_merge( $base_args, array( 'paged' => $paged + 1 ) ), admin_url( 'admin.php' ) );
			echo ' <a class="button" href="' . esc_url( $next_url ) . '">' . esc_html__( 'Next', 'ruben-dance' ) . ' &raquo;</a>';
		}

		echo '</p>';
	}
}
