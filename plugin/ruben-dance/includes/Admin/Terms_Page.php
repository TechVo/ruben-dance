<?php
/**
 * "Terms" admin screen: list (filter by season/status/location), add/edit,
 * duplicate.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Lang;
use RubenDance\Post_Types;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Roles;
use RubenDance\Services\Term_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Terms_Page.
 *
 * Follows the `Locations_Page` reference pattern: `WP_List_Table` + add/edit
 * form, nonce + `rd_manage` capability on every action, POST-redirect-GET on
 * success, re-render with inline errors on validation failure, processing
 * wired to `load-{hook}` (never inside `render()`, see `Locations_Page` for
 * why). After a successful create/update this screen redirects straight to
 * the term's lessons sub-screen (`Term_Lessons_Page`) rather than back to
 * this list — that is how "on save, show the generated dates on the term
 * screen" (F9) is satisfied, without duplicating a lesson preview here too.
 */
class Terms_Page {

	const SLUG = 'ruben-dance-terms';

	const SAVE_NONCE_ACTION = 'rd_term_save';

	/**
	 * Row actions that change state.
	 *
	 * @var string[]
	 */
	const ROW_ACTIONS = array( 'duplicate' );

	/**
	 * Validation errors from a failed save, stashed by `handle_request()` for
	 * `render()` to display (see `Locations_Page::$form_errors`).
	 *
	 * @var array{0: array<string, string>, 1: array<string, mixed>, 2: int}|null
	 */
	private static ?array $form_errors = null;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the "Terms" submenu page under the Ruben Dance top-level menu.
	 */
	public static function add_menu(): void {
		$hook_suffix = add_submenu_page(
			Menu::SLUG,
			__( 'Terms', 'ruben-dance' ),
			__( 'Terms', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( false !== $hook_suffix ) {
			add_action( "load-{$hook_suffix}", array( self::class, 'handle_request' ) );
		}
	}

	/**
	 * Process a save or row action for this screen, before any output is sent.
	 */
	public static function handle_request(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage terms.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		$form_errors = self::maybe_handle_save();

		if ( null !== $form_errors ) {
			self::$form_errors = $form_errors;
			return;
		}

		self::maybe_handle_row_action();
	}

	/**
	 * URL to the edit form for a given term.
	 *
	 * @param int $id Term ID.
	 * @return string
	 */
	public static function edit_url( int $id ): string {
		return add_query_arg(
			array(
				'page'   => self::SLUG,
				'action' => 'edit',
				'id'     => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL to the add-new form.
	 *
	 * @return string
	 */
	public static function add_url(): string {
		return add_query_arg(
			array(
				'page'   => self::SLUG,
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Nonce-protected URL for a state-changing row action.
	 *
	 * @param string $row_action One of self::ROW_ACTIONS.
	 * @param int    $id         Term ID.
	 * @return string
	 */
	public static function row_action_url( string $row_action, int $id ): string {
		$url = add_query_arg(
			array(
				'page'       => self::SLUG,
				'row_action' => $row_action,
				'id'         => $id,
			),
			admin_url( 'admin.php' )
		);

		return wp_nonce_url( $url, self::row_action_nonce_action( $row_action, $id ) );
	}

	/**
	 * Nonce action string for a given row action + term.
	 *
	 * @param string $row_action Row action key.
	 * @param int    $id         Term ID.
	 * @return string
	 */
	private static function row_action_nonce_action( string $row_action, int $id ): string {
		return 'rd_term_' . $row_action . '_' . $id;
	}

	/**
	 * Main entry point, wired as the submenu page callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage terms.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( null !== self::$form_errors ) {
			list( $errors, $submitted, $id ) = self::$form_errors;
			self::render_form( $id, $submitted, $errors );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only selects which view renders below, no state change.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'add' === $action ) {
			self::render_form( 0, self::blank_submission(), array() );
			return;
		}

		if ( 'edit' === $action ) {
			self::render_edit_form();
			return;
		}

		self::render_list();
	}

	/**
	 * Default field values for the "Add" form.
	 *
	 * @return array<string, mixed>
	 */
	private static function blank_submission(): array {
		return array(
			'course_id'       => 0,
			'location_id'     => 0,
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => 1,
			'start_time'      => '',
			'end_time'        => '',
			'date_from'       => '',
			'date_to'         => '',
			'instructor'      => '',
			'capacity'        => '',
			'price'           => '',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_DRAFT,
			'season_label_cs' => '',
			'season_label_en' => '',
			'note_public_cs'  => '',
			'note_public_en'  => '',
		);
	}

	/**
	 * Load the requested term and render its edit form, or bounce back to
	 * the list with a "not found" notice.
	 */
	private static function render_edit_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which record to load, no state change.
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$term = ( new Course_Term_Repository() )->find( $id );

		if ( null === $term ) {
			self::render_notice( 'error', __( 'Term not found.', 'ruben-dance' ) );
			self::render_list();
			return;
		}

		self::render_form(
			$id,
			array(
				'course_id'       => (int) $term['course_id'],
				'location_id'     => (int) $term['location_id'],
				'type'            => (string) $term['type'],
				'weekday'         => (int) $term['weekday'],
				'start_time'      => Terms_List_Table::format_time( (string) $term['start_time'] ),
				'end_time'        => Terms_List_Table::format_time( (string) $term['end_time'] ),
				'date_from'       => (string) $term['date_from'],
				'date_to'         => (string) $term['date_to'],
				'instructor'      => (string) $term['instructor'],
				'capacity'        => null === $term['capacity'] ? '' : (string) $term['capacity'],
				'price'           => (string) $term['price'],
				'discount_early'  => null === $term['discount_early'] ? '' : (string) $term['discount_early'],
				'early_until'     => (string) ( $term['early_until'] ?? '' ),
				'discount_pair'   => null === $term['discount_pair'] ? '' : (string) $term['discount_pair'],
				'status'          => (string) $term['status'],
				'season_label_cs' => (string) $term['season_label_cs'],
				'season_label_en' => (string) $term['season_label_en'],
				'note_public_cs'  => (string) ( $term['note_public_cs'] ?? '' ),
				'note_public_en'  => (string) ( $term['note_public_en'] ?? '' ),
			),
			array()
		);
	}

	/**
	 * Handle the add/edit form submission, if this request is one.
	 *
	 * @return array{0: array<string, string>, 1: array<string, mixed>, 2: int}|null
	 *         [errors, submitted values, id] to re-render the form on
	 *         validation failure, or null when there was nothing to handle
	 *         (or the save succeeded and the request already redirected).
	 */
	private static function maybe_handle_save(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only routes to the save branch; check_admin_referer() immediately below performs the real verification before any field is read or written.
		if ( ! isset( $_POST['rd_term_action'] ) || 'save' !== $_POST['rd_term_action'] ) {
			return null;
		}

		check_admin_referer( self::SAVE_NONCE_ACTION );

		$id        = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$submitted = self::read_submission();

		$service = Term_Service::create_default();
		$errors  = $service->validate( $submitted );

		if ( array() !== $errors ) {
			return array( $errors, $submitted, $id );
		}

		if ( $id > 0 ) {
			$service->update_details( $id, $submitted );
			$term_id = $id;
		} else {
			$term_id = $service->create( $submitted );
		}

		self::redirect_to_lessons( $term_id, 'term_saved' );

		return null;
	}

	/**
	 * Read and unslash the raw submission from `$_POST`. Sanitization beyond
	 * this (numeric parsing, trimming, invalid-value fallbacks) happens in
	 * `Term_Service::validate()`/`row()`, the same split `Location_Page`
	 * uses.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_submission(): array {
		$fields = array(
			'course_id',
			'location_id',
			'type',
			'weekday',
			'start_time',
			'end_time',
			'date_from',
			'date_to',
			'instructor',
			'capacity',
			'price',
			'discount_early',
			'early_until',
			'discount_pair',
			'status',
			'season_label_cs',
			'season_label_en',
			'note_public_cs',
			'note_public_en',
		);

		$submitted = array();

		foreach ( $fields as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			$submitted[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}

		return $submitted;
	}

	/**
	 * Handle a state-changing row action (duplicate), if this request is one.
	 */
	private static function maybe_handle_row_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only used to pick the nonce action string; check_admin_referer() below performs the real verification before any write happens.
		$row_action = isset( $_GET['row_action'] ) ? sanitize_key( wp_unslash( $_GET['row_action'] ) ) : '';

		if ( ! in_array( $row_action, self::ROW_ACTIONS, true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only used to build the nonce action string; check_admin_referer() below performs the real verification before any write happens.
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		check_admin_referer( self::row_action_nonce_action( $row_action, $id ) );

		if ( 0 === $id ) {
			self::redirect( array( 'rd_notice' => 'not_found' ) );
			return;
		}

		if ( 'duplicate' === $row_action ) {
			$new_id = Term_Service::create_default()->duplicate( $id );

			if ( null === $new_id ) {
				self::redirect( array( 'rd_notice' => 'not_found' ) );
				return;
			}

			// Land straight on the new draft's edit form (F9: "admin edits
			// dates" is the very next thing they need to do).
			wp_safe_redirect(
				add_query_arg(
					array(
						'rd_notice' => 'duplicated',
					),
					self::edit_url( $new_id )
				)
			);
			exit;
		}
	}

	/**
	 * Redirect to a term's lessons sub-screen (see class docblock: this is
	 * how a save shows the generated dates).
	 *
	 * @param int    $term_id Term ID.
	 * @param string $notice  Notice code.
	 */
	private static function redirect_to_lessons( int $term_id, string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'rd_notice' => $notice ), Term_Lessons_Page::url( $term_id ) ) );
		exit;
	}

	/**
	 * Redirect back to the list screen with a notice code.
	 *
	 * @param array<string, string> $args Extra query args (e.g. `rd_notice`).
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'page' => self::SLUG ), $args ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the list screen (filters + table + notice banner).
	 */
	private static function render_list(): void {
		self::render_notice_from_query();

		$filters = self::read_filters();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Terms', 'ruben-dance' ) . '</h1> ';
		echo '<a href="' . esc_url( self::add_url() ) . '" class="page-title-action">' . esc_html__( 'Add New', 'ruben-dance' ) . '</a>';
		echo '<hr class="wp-header-end">';

		self::render_filters( $filters );

		$table = new Terms_List_Table( $filters );
		$table->prepare_items();
		$table->display();

		echo '</div>';
	}

	/**
	 * Read the active list filters from `$_GET`.
	 *
	 * @return array{status: string, location_id: int, season_label_cs: string}
	 */
	private static function read_filters(): array {
		return array(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the list shows, no state change.
			'status'          => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the list shows, no state change.
			'location_id'     => isset( $_GET['location_id'] ) ? absint( $_GET['location_id'] ) : 0,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the list shows, no state change.
			'season_label_cs' => isset( $_GET['season'] ) ? sanitize_text_field( wp_unslash( $_GET['season'] ) ) : '',
		);
	}

	/**
	 * Render the status/location/season filter form (F9: "list (filter by
	 * season/status/location)").
	 *
	 * @param array{status: string, location_id: int, season_label_cs: string} $filters Active filter values.
	 */
	private static function render_filters( array $filters ): void {
		echo '<form method="get" style="margin:1em 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';

		echo '<select name="status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'ruben-dance' ) . '</option>';
		foreach ( Term_Service::STATUSES as $status ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $status ),
				selected( $filters['status'], $status, false )
			);
		}
		echo '</select> ';

		echo '<select name="location_id">';
		echo '<option value="">' . esc_html__( 'All locations', 'ruben-dance' ) . '</option>';
		foreach ( ( new Location_Repository() )->all() as $location ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $location['id'],
				selected( $filters['location_id'], (int) $location['id'], false ),
				esc_html( (string) $location['name'] )
			);
		}
		echo '</select> ';

		echo '<select name="season">';
		echo '<option value="">' . esc_html__( 'All seasons', 'ruben-dance' ) . '</option>';
		foreach ( ( new Course_Term_Repository() )->distinct_seasons() as $season ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $season ),
				selected( $filters['season_label_cs'], $season, false )
			);
		}
		echo '</select> ';

		submit_button( __( 'Filter', 'ruben-dance' ), '', '', false );
		echo '</form>';
	}

	/**
	 * Render the add/edit form.
	 *
	 * @param int                   $id        Term ID (0 = new).
	 * @param array<string, mixed>  $submitted Field values to prefill.
	 * @param array<string, string> $errors    Field name => error code.
	 */
	private static function render_form( int $id, array $submitted, array $errors ): void {
		foreach ( $errors as $code ) {
			self::render_notice( 'error', self::error_message( $code ) );
		}

		$title = $id > 0 ? __( 'Edit Term', 'ruben-dance' ) : __( 'Add Term', 'ruben-dance' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">';

		wp_nonce_field( self::SAVE_NONCE_ACTION );

		echo '<input type="hidden" name="rd_term_action" value="save">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';

		echo '<table class="form-table"><tbody>';

		self::render_select_field(
			'course_id',
			__( 'Course', 'ruben-dance' ),
			self::course_options(),
			(string) $submitted['course_id'],
			true
		);

		self::render_select_field(
			'location_id',
			__( 'Location', 'ruben-dance' ),
			self::location_options( (int) $submitted['location_id'] ),
			(string) $submitted['location_id'],
			true
		);

		self::render_select_field(
			'type',
			__( 'Type', 'ruben-dance' ),
			array(
				Term_Service::TYPE_COURSE   => __( 'Course (multi-week)', 'ruben-dance' ),
				Term_Service::TYPE_WORKSHOP => __( 'Workshop (single lesson)', 'ruben-dance' ),
			),
			(string) $submitted['type'],
			true,
			'rd_term_type'
		);

		self::render_select_field(
			'weekday',
			__( 'Weekday', 'ruben-dance' ),
			Terms_List_Table::weekday_labels(),
			(string) $submitted['weekday'],
			true,
			'rd_term_weekday_row'
		);

		self::render_text_field( 'start_time', __( 'Start time', 'ruben-dance' ), (string) $submitted['start_time'], 'time', true );
		self::render_text_field( 'end_time', __( 'End time', 'ruben-dance' ), (string) $submitted['end_time'], 'time', true );
		self::render_text_field( 'date_from', __( 'First date', 'ruben-dance' ), (string) $submitted['date_from'], 'date', true );
		self::render_text_field( 'date_to', __( 'Last date', 'ruben-dance' ), (string) $submitted['date_to'], 'date', true, 'rd_term_date_to_row' );
		self::render_text_field( 'instructor', __( 'Instructor', 'ruben-dance' ), (string) $submitted['instructor'], 'text', true );
		self::render_text_field( 'capacity', __( 'Capacity', 'ruben-dance' ), (string) $submitted['capacity'], 'number', false, '', __( 'Leave blank for unlimited.', 'ruben-dance' ) );
		self::render_text_field( 'price', __( 'Price (CZK)', 'ruben-dance' ), (string) $submitted['price'], 'number', true );
		self::render_text_field( 'discount_early', __( 'Early-bird discount (CZK)', 'ruben-dance' ), (string) $submitted['discount_early'], 'number' );
		self::render_text_field( 'early_until', __( 'Early-bird deadline', 'ruben-dance' ), (string) $submitted['early_until'], 'date', false, '', __( 'Required together with the early-bird discount.', 'ruben-dance' ) );
		self::render_text_field( 'discount_pair', __( 'Pair discount (CZK)', 'ruben-dance' ), (string) $submitted['discount_pair'], 'number' );

		self::render_select_field(
			'status',
			__( 'Status', 'ruben-dance' ),
			array(
				Term_Service::STATUS_DRAFT     => __( 'Draft', 'ruben-dance' ),
				Term_Service::STATUS_OPEN      => __( 'Open (enrollable)', 'ruben-dance' ),
				Term_Service::STATUS_CLOSED    => __( 'Closed (visible, not enrollable)', 'ruben-dance' ),
				Term_Service::STATUS_CANCELLED => __( 'Cancelled', 'ruben-dance' ),
			),
			(string) $submitted['status'],
			true
		);

		self::render_text_field( 'season_label_cs', __( 'Season label (Czech)', 'ruben-dance' ), (string) $submitted['season_label_cs'], 'text', true );
		self::render_text_field( 'season_label_en', __( 'Season label (English)', 'ruben-dance' ), (string) $submitted['season_label_en'], 'text' );
		self::render_textarea_field( 'note_public_cs', __( 'Public note (Czech)', 'ruben-dance' ), (string) $submitted['note_public_cs'] );
		self::render_textarea_field( 'note_public_en', __( 'Public note (English)', 'ruben-dance' ), (string) $submitted['note_public_en'] );

		echo '</tbody></table>';

		submit_button( $id > 0 ? __( 'Update Term', 'ruben-dance' ) : __( 'Add Term', 'ruben-dance' ) );

		echo '</form>';
		echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Back to list', 'ruben-dance' ) . '</a></p>';

		self::render_type_toggle_script();

		echo '</div>';
	}

	/**
	 * A `<select>` field row.
	 *
	 * @param string                    $name    Field name.
	 * @param string                    $label   Field label.
	 * @param array<int|string, string> $options Value => label.
	 * @param string                    $value   Currently selected value.
	 * @param bool                      $required Whether the field is required.
	 * @param string                    $row_id  Optional `id` on the `<tr>`, for JS toggling.
	 */
	private static function render_select_field( string $name, string $label, array $options, string $value, bool $required = false, string $row_id = '' ): void {
		$row_attr = '' === $row_id ? '' : ' id="' . esc_attr( $row_id ) . '"';

		echo '<tr' . $row_attr . '><th scope="row"><label for="rd_term_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $row_attr is built above from esc_attr( $row_id ) or a literal empty string, never raw input.
		echo '<select id="rd_term_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' required="required"' : '' ) . '>';

		foreach ( $options as $option_value => $option_label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $option_value ),
				selected( $value, (string) $option_value, false ),
				esc_html( $option_label )
			);
		}

		echo '</select></td></tr>';
	}

	/**
	 * A plain `<input>` field row.
	 *
	 * @param string $name     Field name.
	 * @param string $label    Field label.
	 * @param string $value    Current value.
	 * @param string $type     HTML input type.
	 * @param bool   $required Whether the field is required.
	 * @param string $row_id   Optional `id` on the `<tr>`, for JS toggling.
	 * @param string $help     Optional help text below the field.
	 */
	private static function render_text_field( string $name, string $label, string $value, string $type = 'text', bool $required = false, string $row_id = '', string $help = '' ): void {
		$row_attr = '' === $row_id ? '' : ' id="' . esc_attr( $row_id ) . '"';
		$step     = 'number' === $type ? ' step="0.01" min="0"' : '';

		echo '<tr' . $row_attr . '><th scope="row"><label for="rd_term_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $row_attr is built above from esc_attr( $row_id ) or a literal empty string, never raw input.
		echo '<input type="' . esc_attr( $type ) . '" id="rd_term_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="regular-text"' . $step . ( $required ? ' required="required"' : '' ) . ' value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $step is one of two hardcoded literal strings assigned above, never derived from input.

		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * A `<textarea>` field row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Field label.
	 * @param string $value Current value.
	 */
	private static function render_textarea_field( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="rd_term_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="rd_term_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="large-text" rows="3">' . esc_textarea( $value ) . '</textarea>';
		echo '</td></tr>';
	}

	/**
	 * Tiny inline script hiding the weekday/last-date rows while "Workshop"
	 * is selected — purely a UX nicety, the server always derives/overrides
	 * both for a workshop regardless of what the form actually submits (see
	 * `Term_Service::row()`), so this cannot become a validation bypass.
	 */
	private static function render_type_toggle_script(): void {
		?>
		<script>
		( function () {
			var typeField = document.getElementById( 'rd_term_type' );
			var weekdayRow = document.getElementById( 'rd_term_weekday_row' );
			var dateToRow = document.getElementById( 'rd_term_date_to_row' );

			if ( ! typeField || ! weekdayRow || ! dateToRow ) {
				return;
			}

			function toggle() {
				var isWorkshop = 'workshop' === typeField.value;
				weekdayRow.style.display = isWorkshop ? 'none' : '';
				dateToRow.style.display = isWorkshop ? 'none' : '';
			}

			typeField.addEventListener( 'change', toggle );
			toggle();
		} )();
		</script>
		<?php
	}

	/**
	 * Course dropdown options: published `rd_course` posts, restricted to
	 * the Czech original when Polylang is active (spec §5: `course_id`
	 * always references the Czech post).
	 *
	 * @return array<int, string>
	 */
	private static function course_options(): array {
		$posts = get_posts(
			array(
				'post_type'      => Post_Types::COURSE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'lang'           => Lang::CS,
			)
		);

		$options = array();
		foreach ( $posts as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}

		return $options;
	}

	/**
	 * Location dropdown options: active locations, plus the given ID's
	 * location even if it has since been deactivated (an edit form must
	 * still accept the term's already-selected location).
	 *
	 * @param int $current_location_id Currently selected location ID (0 = none/new).
	 * @return array<int, string>
	 */
	private static function location_options( int $current_location_id ): array {
		$repository = new Location_Repository();
		$locations  = $repository->active();

		$has_current = false;
		foreach ( $locations as $location ) {
			if ( (int) $location['id'] === $current_location_id ) {
				$has_current = true;
				break;
			}
		}

		if ( $current_location_id > 0 && ! $has_current ) {
			$current = $repository->find( $current_location_id );

			if ( null !== $current ) {
				$locations[] = $current;
			}
		}

		$options = array();
		foreach ( $locations as $location ) {
			$options[ $location['id'] ] = $location['name'];
		}

		return $options;
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
			'duplicated' => array( 'success', __( 'Term duplicated as a new draft — update the dates below.', 'ruben-dance' ) ),
			'not_found'  => array( 'error', __( 'Term not found.', 'ruben-dance' ) ),
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
	 * Translate a `Term_Service::ERROR_*` code into a message.
	 *
	 * @param string $code One of the `Term_Service::ERROR_*` constants.
	 * @return string
	 */
	private static function error_message( string $code ): string {
		switch ( $code ) {
			case Term_Service::ERROR_COURSE_REQUIRED:
				return __( 'Course is required.', 'ruben-dance' );

			case Term_Service::ERROR_COURSE_INVALID:
				return __( 'Selected course does not exist.', 'ruben-dance' );

			case Term_Service::ERROR_LOCATION_REQUIRED:
				return __( 'Location is required.', 'ruben-dance' );

			case Term_Service::ERROR_LOCATION_INVALID:
				return __( 'Selected location does not exist.', 'ruben-dance' );

			case Term_Service::ERROR_TYPE_INVALID:
				return __( 'Invalid term type.', 'ruben-dance' );

			case Term_Service::ERROR_WEEKDAY_INVALID:
				return __( 'Weekday is required for a course term.', 'ruben-dance' );

			case Term_Service::ERROR_START_TIME_INVALID:
				return __( 'Start time is invalid.', 'ruben-dance' );

			case Term_Service::ERROR_END_TIME_INVALID:
				return __( 'End time is invalid.', 'ruben-dance' );

			case Term_Service::ERROR_END_TIME_BEFORE_START:
				return __( 'End time must be after start time.', 'ruben-dance' );

			case Term_Service::ERROR_DATE_FROM_INVALID:
				return __( 'First date is invalid.', 'ruben-dance' );

			case Term_Service::ERROR_DATE_TO_INVALID:
				return __( 'Last date is invalid.', 'ruben-dance' );

			case Term_Service::ERROR_DATE_TO_BEFORE_FROM:
				return __( 'Last date must not be before the first date.', 'ruben-dance' );

			case Term_Service::ERROR_INSTRUCTOR_REQUIRED:
				return __( 'Instructor is required.', 'ruben-dance' );

			case Term_Service::ERROR_INSTRUCTOR_TOO_LONG:
				return __( 'Instructor must be 190 characters or fewer.', 'ruben-dance' );

			case Term_Service::ERROR_CAPACITY_INVALID:
				return __( 'Capacity must be a positive whole number, or left blank for unlimited.', 'ruben-dance' );

			case Term_Service::ERROR_PRICE_INVALID:
				return __( 'Price must be a non-negative amount.', 'ruben-dance' );

			case Term_Service::ERROR_DISCOUNT_EARLY_INVALID:
				return __( 'Early-bird discount must be a non-negative amount.', 'ruben-dance' );

			case Term_Service::ERROR_EARLY_UNTIL_INVALID:
				return __( 'Early-bird deadline is invalid.', 'ruben-dance' );

			case Term_Service::ERROR_EARLY_UNTIL_REQUIRED:
				return __( 'The early-bird discount and its deadline must be set together.', 'ruben-dance' );

			case Term_Service::ERROR_DISCOUNT_PAIR_INVALID:
				return __( 'Pair discount must be a non-negative amount.', 'ruben-dance' );

			case Term_Service::ERROR_STATUS_INVALID:
				return __( 'Invalid status.', 'ruben-dance' );

			case Term_Service::ERROR_SEASON_LABEL_CS_REQUIRED:
				return __( 'Season label (Czech) is required.', 'ruben-dance' );

			case Term_Service::ERROR_SEASON_LABEL_CS_TOO_LONG:
				return __( 'Season label (Czech) must be 100 characters or fewer.', 'ruben-dance' );

			case Term_Service::ERROR_SEASON_LABEL_EN_TOO_LONG:
				return __( 'Season label (English) must be 100 characters or fewer.', 'ruben-dance' );

			default:
				return __( 'Invalid input.', 'ruben-dance' );
		}
	}
}
