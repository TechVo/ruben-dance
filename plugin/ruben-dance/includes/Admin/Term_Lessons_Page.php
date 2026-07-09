<?php
/**
 * Per-term "Lessons" admin screen (F10): list of dates, cancel / change
 * time / add a note.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Lesson_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Roles;
use RubenDance\Services\Lesson_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Term_Lessons_Page.
 *
 * Reached from `Terms_List_Table`'s "Lessons" row action and from
 * `Terms_Page`'s post-save redirect; not itself a top-level or visible
 * submenu entry (registered with a null parent, the standard WordPress way
 * to add an admin-page hook without a sidebar entry — see `add_menu()`), since
 * a lessons screen only ever makes sense in the context of one specific term.
 *
 * One edit form covers cancel / change time / add a note at once (F10 lists
 * these as three separate abilities, but they are all just fields on the
 * same `wp_rd_lesson` row) plus a "notify enrollees" checkbox that feeds
 * `Lesson_Service::NOTIFY_HOOK` — stubbed in this milestone, see that class.
 */
class Term_Lessons_Page {

	const SLUG = 'ruben-dance-term-lessons';

	const SAVE_NONCE_ACTION = 'rd_lesson_save';

	/**
	 * Validation errors from a failed save, stashed by `handle_request()` for
	 * `render()` to display (see `Locations_Page::$form_errors`).
	 *
	 * @var array{0: array<string, string>, 1: array<string, mixed>, 2: int, 3: int}|null
	 */
	private static ?array $form_errors = null;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the hidden (no-sidebar-entry) lessons page.
	 */
	public static function add_menu(): void {
		$hook_suffix = add_submenu_page(
			null, // A null parent is the documented way to register an admin page hook without adding a sidebar menu entry.
			__( 'Lessons', 'ruben-dance' ),
			__( 'Lessons', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( false !== $hook_suffix ) {
			add_action( "load-{$hook_suffix}", array( self::class, 'handle_request' ) );
		}
	}

	/**
	 * URL to a term's lessons list.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	public static function url( int $term_id ): string {
		return add_query_arg(
			array(
				'page'    => self::SLUG,
				'term_id' => $term_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL to a single lesson's edit form.
	 *
	 * @param int $term_id   Term ID.
	 * @param int $lesson_id Lesson ID.
	 * @return string
	 */
	public static function edit_url( int $term_id, int $lesson_id ): string {
		return add_query_arg( array( 'lesson_id' => $lesson_id ), self::url( $term_id ) );
	}

	/**
	 * Process a save for this screen, before any output is sent.
	 */
	public static function handle_request(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage lessons.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		$form_errors = self::maybe_handle_save();

		if ( null !== $form_errors ) {
			self::$form_errors = $form_errors;
		}
	}

	/**
	 * Handle the lesson edit form submission, if this request is one.
	 *
	 * @return array{0: array<string, string>, 1: array<string, mixed>, 2: int, 3: int}|null
	 *         [errors, submitted values, term_id, lesson_id] to re-render the
	 *         form on validation failure, or null otherwise.
	 */
	private static function maybe_handle_save(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only routes to the save branch; check_admin_referer() immediately below performs the real verification before any field is read or written.
		if ( ! isset( $_POST['rd_lesson_action'] ) || 'save' !== $_POST['rd_lesson_action'] ) {
			return null;
		}

		check_admin_referer( self::SAVE_NONCE_ACTION );

		$term_id   = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( $_POST['lesson_id'] ) : 0;

		$submitted = array(
			'start_time' => isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '',
			'end_time'   => isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '',
			'status'     => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
			'note'       => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
		);

		$notify = isset( $_POST['notify_enrollees'] );

		$service = Lesson_Service::create_default();
		$errors  = $service->validate( $submitted );

		if ( array() !== $errors ) {
			return array( $errors, $submitted, $term_id, $lesson_id );
		}

		$service->save( $lesson_id, $submitted, $notify );

		wp_safe_redirect( add_query_arg( array( 'rd_notice' => 'lesson_saved' ), self::url( $term_id ) ) );
		exit;
	}

	/**
	 * Main entry point, wired as the submenu page callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage lessons.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( null !== self::$form_errors ) {
			list( $errors, $submitted, $term_id, $lesson_id ) = self::$form_errors;
			self::render_header( $term_id );
			self::render_form( $term_id, $lesson_id, $submitted, $errors );
			echo '</div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which term/lesson to load, no state change.
		$term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;

		if ( null === ( new Course_Term_Repository() )->find( $term_id ) ) {
			echo '<div class="wrap">';
			self::render_notice( 'error', __( 'Term not found.', 'ruben-dance' ) );
			echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => Terms_Page::SLUG ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Back to terms', 'ruben-dance' ) . '</a></p>';
			echo '</div>';
			return;
		}

		self::render_header( $term_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which lesson to edit, no state change.
		$lesson_id = isset( $_GET['lesson_id'] ) ? absint( $_GET['lesson_id'] ) : 0;

		if ( $lesson_id > 0 ) {
			self::render_edit_form( $term_id, $lesson_id );
		} else {
			self::render_list( $term_id );
		}

		echo '</div>';
	}

	/**
	 * Render the `<div class="wrap">` opening tag, page title, term summary
	 * and any redirect-carried notice. Left open — callers close it.
	 *
	 * @param int $term_id Term ID.
	 */
	private static function render_header( int $term_id ): void {
		$term = ( new Course_Term_Repository() )->find( $term_id );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Lessons', 'ruben-dance' ) . '</h1>';

		if ( null !== $term ) {
			$location = ( new Location_Repository() )->find( (int) $term['location_id'] );

			echo '<p>';
			printf(
				/* translators: 1: season label, 2: course title, 3: location name. */
				esc_html__( '%1$s — %2$s at %3$s', 'ruben-dance' ),
				esc_html( (string) $term['season_label_cs'] ),
				esc_html( get_the_title( (int) $term['course_id'] ) ),
				esc_html( null === $location ? '' : (string) $location['name'] )
			);
			echo '</p>';
			echo '<p><a href="' . esc_url( Terms_Page::edit_url( $term_id ) ) . '">' . esc_html__( 'Edit term', 'ruben-dance' ) . '</a> | ';
			echo '<a href="' . esc_url( add_query_arg( array( 'page' => Terms_Page::SLUG ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Back to terms list', 'ruben-dance' ) . '</a></p>';
		}

		self::render_notice_from_query();
	}

	/**
	 * Render the lessons list for a term.
	 *
	 * @param int $term_id Term ID.
	 */
	private static function render_list( int $term_id ): void {
		$table = new Term_Lessons_List_Table( $term_id );
		$table->prepare_items();
		$table->display();
	}

	/**
	 * Load the requested lesson and render its edit form, or show a
	 * "not found" notice.
	 *
	 * @param int $term_id   Term ID (for the back link and hidden field).
	 * @param int $lesson_id Lesson ID.
	 */
	private static function render_edit_form( int $term_id, int $lesson_id ): void {
		$lesson = ( new Lesson_Repository() )->find( $lesson_id );

		if ( null === $lesson || (int) $lesson['term_id'] !== $term_id ) {
			self::render_notice( 'error', __( 'Lesson not found.', 'ruben-dance' ) );
			self::render_list( $term_id );
			return;
		}

		self::render_form(
			$term_id,
			$lesson_id,
			array(
				'start_time' => Terms_List_Table::format_time( (string) $lesson['start_time'] ),
				'end_time'   => Terms_List_Table::format_time( (string) $lesson['end_time'] ),
				'status'     => (string) $lesson['status'],
				'note'       => (string) ( $lesson['note'] ?? '' ),
			),
			array()
		);
	}

	/**
	 * Render the lesson edit form: time, status, note, notify checkbox.
	 *
	 * @param int                   $term_id   Term ID.
	 * @param int                   $lesson_id Lesson ID.
	 * @param array<string, string> $submitted Field values to prefill.
	 * @param array<string, string> $errors    Field name => error code.
	 */
	private static function render_form( int $term_id, int $lesson_id, array $submitted, array $errors ): void {
		foreach ( $errors as $code ) {
			self::render_notice( 'error', self::error_message( $code ) );
		}

		echo '<form method="post" action="' . esc_url( add_query_arg( array(), admin_url( 'admin.php?page=' . self::SLUG ) ) ) . '">';

		wp_nonce_field( self::SAVE_NONCE_ACTION );

		echo '<input type="hidden" name="rd_lesson_action" value="save">';
		echo '<input type="hidden" name="term_id" value="' . esc_attr( (string) $term_id ) . '">';
		echo '<input type="hidden" name="lesson_id" value="' . esc_attr( (string) $lesson_id ) . '">';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="rd_lesson_start_time">' . esc_html__( 'Start time', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="time" id="rd_lesson_start_time" name="start_time" required="required" value="' . esc_attr( $submitted['start_time'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="rd_lesson_end_time">' . esc_html__( 'End time', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="time" id="rd_lesson_end_time" name="end_time" required="required" value="' . esc_attr( $submitted['end_time'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="rd_lesson_status">' . esc_html__( 'Status', 'ruben-dance' ) . '</label></th><td>';
		echo '<select id="rd_lesson_status" name="status">';
		$statuses = array(
			Lesson_Service::STATUS_SCHEDULED => __( 'Scheduled', 'ruben-dance' ),
			Lesson_Service::STATUS_CANCELLED => __( 'Cancelled', 'ruben-dance' ),
			Lesson_Service::STATUS_MOVED     => __( 'Moved', 'ruben-dance' ),
		);
		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $submitted['status'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="rd_lesson_note">' . esc_html__( 'Note', 'ruben-dance' ) . '</label></th><td>';
		echo '<textarea id="rd_lesson_note" name="note" class="large-text" rows="2" placeholder="' . esc_attr__( 'e.g. State holiday — no class', 'ruben-dance' ) . '">' . esc_textarea( $submitted['note'] ) . '</textarea>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Notify enrollees', 'ruben-dance' ) . '</th><td>';
		echo '<label><input type="checkbox" name="notify_enrollees" value="1" checked="checked"> ' . esc_html__( 'Email enrolled customers about this change', 'ruben-dance' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Sending is not implemented yet; this only records the request for now.', 'ruben-dance' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save Lesson', 'ruben-dance' ) );

		echo '</form>';
		echo '<p><a href="' . esc_url( self::url( $term_id ) ) . '">' . esc_html__( 'Back to lessons', 'ruben-dance' ) . '</a></p>';
	}

	/**
	 * Read the `rd_notice` query arg left by a redirect and render it.
	 */
	private static function render_notice_from_query(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: purely cosmetic (which notice text to show after a redirect), no state change.
		$notice = isset( $_GET['rd_notice'] ) ? sanitize_key( wp_unslash( $_GET['rd_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'term_saved'   => array( 'success', __( 'Term saved. Lessons regenerated below.', 'ruben-dance' ) ),
			'lesson_saved' => array( 'success', __( 'Lesson updated.', 'ruben-dance' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $messages[ $notice ];

		self::render_notice( $type, $message );
	}

	/**
	 * Echo a dismissible admin notice.
	 *
	 * @param string $type    'success'|'error'|'warning'.
	 * @param string $message Already-translated message text.
	 */
	private static function render_notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Translate a `Lesson_Service::ERROR_*` code into a message.
	 *
	 * @param string $code One of the `Lesson_Service::ERROR_*` constants.
	 * @return string
	 */
	private static function error_message( string $code ): string {
		switch ( $code ) {
			case Lesson_Service::ERROR_START_TIME_INVALID:
				return __( 'Start time is invalid.', 'ruben-dance' );

			case Lesson_Service::ERROR_END_TIME_INVALID:
				return __( 'End time is invalid.', 'ruben-dance' );

			case Lesson_Service::ERROR_END_TIME_BEFORE_START:
				return __( 'End time must be after start time.', 'ruben-dance' );

			case Lesson_Service::ERROR_STATUS_INVALID:
				return __( 'Invalid status.', 'ruben-dance' );

			case Lesson_Service::ERROR_NOTE_TOO_LONG:
				return __( 'Note must be 255 characters or fewer.', 'ruben-dance' );

			default:
				return __( 'Invalid input.', 'ruben-dance' );
		}
	}
}
