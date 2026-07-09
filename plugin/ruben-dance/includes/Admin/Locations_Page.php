<?php
/**
 * "Locations" admin screen: list, add, edit, deactivate/activate, delete.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Repositories\Location_Repository;
use RubenDance\Roles;
use RubenDance\Services\Location_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Locations_Page.
 *
 * The reference implementation every later admin screen (terms, roster,
 * customers, ...) is meant to copy: `WP_List_Table` + add/edit form, nonce +
 * `rd_manage` capability on every action, POST-redirect-GET on success, and
 * re-render with inline errors on validation failure.
 */
class Locations_Page {

	/**
	 * Submenu page slug.
	 *
	 * @var string
	 */
	const SLUG = 'ruben-dance-locations';

	/**
	 * Nonce action for the add/edit form submission.
	 *
	 * @var string
	 */
	const SAVE_NONCE_ACTION = 'rd_location_save';

	/**
	 * Row actions that change state (as opposed to the `add`/`edit` query
	 * arg, which only selects which view renders).
	 *
	 * @var string[]
	 */
	const ROW_ACTIONS = array( 'deactivate', 'activate', 'delete' );

	/**
	 * Validation errors from a failed save, stashed by `handle_request()` for
	 * `render()` to display. Both run within the same request — WordPress
	 * calls `load-{page_hook}` (where saves/redirects must happen, before any
	 * HTML is sent) and only then the page callback (where output happens).
	 *
	 * @var array{0: array<string, string>, 1: array<string, string>, 2: int}|null
	 */
	private static ?array $form_errors = null;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the "Locations" submenu page under the Ruben Dance top-level menu.
	 *
	 * Processing is wired to `load-{$hook_suffix}`, not the page callback:
	 * WordPress' `admin.php` fires that hook *before* it requires
	 * `admin-header.php`, which is the only point at which a save/row-action
	 * handler can still call `wp_safe_redirect()` — by the time the page
	 * callback (`render()`) runs, admin-header.php has already sent output.
	 */
	public static function add_menu(): void {
		$hook_suffix = add_submenu_page(
			Menu::SLUG,
			__( 'Locations', 'ruben-dance' ),
			__( 'Locations', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( false !== $hook_suffix ) {
			add_action( "load-{$hook_suffix}", array( self::class, 'handle_request' ) );
		}
	}

	/**
	 * Process a save or row action for this screen, before any output is
	 * sent. Hooked to `load-{$hook_suffix}` (see `add_menu()`).
	 */
	public static function handle_request(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage locations.', 'ruben-dance' ),
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
	 * URL to the edit form for a given location.
	 *
	 * @param int $id Location ID.
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
	 * @param int    $id         Location ID.
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
	 * Nonce action string for a given row action + location, so a nonce
	 * minted for one location/action can't be replayed against another.
	 *
	 * @param string $row_action Row action key.
	 * @param int    $id         Location ID.
	 * @return string
	 */
	private static function row_action_nonce_action( string $row_action, int $id ): string {
		return 'rd_location_' . $row_action . '_' . $id;
	}

	/**
	 * Main entry point, wired as the submenu page callback. Runs after
	 * `handle_request()` (see `add_menu()`); output only, no state changes.
	 */
	public static function render(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage locations.', 'ruben-dance' ),
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
			self::render_form(
				0,
				array(
					'name'    => '',
					'address' => '',
					'map_url' => '',
				),
				array()
			);
			return;
		}

		if ( 'edit' === $action ) {
			self::render_edit_form();
			return;
		}

		self::render_list();
	}

	/**
	 * Load the requested location and render its edit form, or bounce back
	 * to the list with a "not found" notice.
	 */
	private static function render_edit_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which record to load, no state change.
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$location = ( new Location_Repository() )->find( $id );

		if ( null === $location ) {
			self::render_notice( 'error', __( 'Location not found.', 'ruben-dance' ) );
			self::render_list();
			return;
		}

		self::render_form(
			$id,
			array(
				'name'    => (string) $location['name'],
				'address' => (string) $location['address'],
				'map_url' => (string) ( $location['map_url'] ?? '' ),
			),
			array()
		);
	}

	/**
	 * Handle the add/edit form submission, if this request is one.
	 *
	 * @return array{0: array<string, string>, 1: array<string, string>, 2: int}|null
	 *         [errors, submitted values, id] to re-render the form on
	 *         validation failure, or null when there was nothing to handle
	 *         (or the save succeeded and the request already redirected).
	 */
	private static function maybe_handle_save(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only routes to the save branch; check_admin_referer() immediately below performs the real verification before any field is read or written.
		if ( ! isset( $_POST['rd_location_action'] ) || 'save' !== $_POST['rd_location_action'] ) {
			return null;
		}

		check_admin_referer( self::SAVE_NONCE_ACTION );

		$id        = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$submitted = array(
			'name'    => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'address' => isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '',
			'map_url' => isset( $_POST['map_url'] ) ? esc_url_raw( wp_unslash( $_POST['map_url'] ) ) : '',
		);

		$service = Location_Service::create_default();
		$errors  = $service->validate( $submitted );

		if ( array() !== $errors ) {
			return array( $errors, $submitted, $id );
		}

		if ( $id > 0 ) {
			$service->update_details( $id, $submitted );
			$notice = 'updated';
		} else {
			$id     = $service->create( $submitted );
			$notice = 'created';
		}

		self::redirect( array( 'rd_notice' => $notice ) );

		return null;
	}

	/**
	 * Handle a state-changing row action (deactivate/activate/delete), if
	 * this request is one.
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

		$service = Location_Service::create_default();

		switch ( $row_action ) {
			case 'deactivate':
				$service->deactivate( $id );
				self::redirect( array( 'rd_notice' => 'deactivated' ) );
				break;

			case 'activate':
				$service->activate( $id );
				self::redirect( array( 'rd_notice' => 'activated' ) );
				break;

			case 'delete':
				$result = $service->delete_or_deactivate( $id );
				self::redirect(
					array(
						'rd_notice' => Location_Service::ACTION_DEACTIVATED === $result ? 'delete_blocked' : 'deleted',
					)
				);
				break;
		}
	}

	/**
	 * Redirect back to the list screen with a notice code, and stop
	 * execution (POST/GET-redirect-GET so a page reload never resubmits).
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
	 * Render the list screen (table + notice banner).
	 */
	private static function render_list(): void {
		self::render_notice_from_query();

		$table = new Locations_List_Table();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Locations', 'ruben-dance' ) . '</h1> ';
		echo '<a href="' . esc_url( self::add_url() ) . '" class="page-title-action">' . esc_html__( 'Add New', 'ruben-dance' ) . '</a>';
		echo '<hr class="wp-header-end">';
		$table->display();
		echo '</div>';
	}

	/**
	 * Render the add/edit form.
	 *
	 * @param int                   $id        Location ID (0 = new).
	 * @param array<string, string> $submitted Field values to prefill.
	 * @param array<string, string> $errors    Field name => error code.
	 */
	private static function render_form( int $id, array $submitted, array $errors ): void {
		foreach ( $errors as $code ) {
			self::render_notice( 'error', self::error_message( $code ) );
		}

		$title = $id > 0 ? __( 'Edit Location', 'ruben-dance' ) : __( 'Add Location', 'ruben-dance' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">';

		wp_nonce_field( self::SAVE_NONCE_ACTION );

		echo '<input type="hidden" name="rd_location_action" value="save">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="rd_location_name">' . esc_html__( 'Name', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="text" id="rd_location_name" name="name" class="regular-text" required="required" value="' . esc_attr( $submitted['name'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="rd_location_address">' . esc_html__( 'Address', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="text" id="rd_location_address" name="address" class="regular-text" required="required" value="' . esc_attr( $submitted['address'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="rd_location_map_url">' . esc_html__( 'Map URL', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="url" id="rd_location_map_url" name="map_url" class="regular-text" placeholder="https://maps.google.com/..." value="' . esc_attr( $submitted['map_url'] ) . '"></td></tr>';

		echo '</tbody></table>';

		submit_button( $id > 0 ? __( 'Update Location', 'ruben-dance' ) : __( 'Add Location', 'ruben-dance' ) );

		echo '</form>';
		echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Back to list', 'ruben-dance' ) . '</a></p>';
		echo '</div>';
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
			'created'        => array( 'success', __( 'Location created.', 'ruben-dance' ) ),
			'updated'        => array( 'success', __( 'Location updated.', 'ruben-dance' ) ),
			'deactivated'    => array( 'success', __( 'Location deactivated.', 'ruben-dance' ) ),
			'activated'      => array( 'success', __( 'Location activated.', 'ruben-dance' ) ),
			'deleted'        => array( 'success', __( 'Location deleted.', 'ruben-dance' ) ),
			'delete_blocked' => array( 'warning', __( 'This location is still used by a course term, so it was deactivated instead of deleted.', 'ruben-dance' ) ),
			'not_found'      => array( 'error', __( 'Location not found.', 'ruben-dance' ) ),
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
	 * Translate a `Location_Service::ERROR_*` code into a message.
	 *
	 * @param string $code One of the `Location_Service::ERROR_*` constants.
	 * @return string
	 */
	private static function error_message( string $code ): string {
		switch ( $code ) {
			case Location_Service::ERROR_NAME_REQUIRED:
				return __( 'Name is required.', 'ruben-dance' );

			case Location_Service::ERROR_NAME_TOO_LONG:
				return __( 'Name must be 190 characters or fewer.', 'ruben-dance' );

			case Location_Service::ERROR_ADDRESS_REQUIRED:
				return __( 'Address is required.', 'ruben-dance' );

			case Location_Service::ERROR_ADDRESS_TOO_LONG:
				return __( 'Address must be 255 characters or fewer.', 'ruben-dance' );

			case Location_Service::ERROR_MAP_URL_INVALID:
				return __( 'Map URL must be a valid URL of 255 characters or fewer.', 'ruben-dance' );

			default:
				return __( 'Invalid input.', 'ruben-dance' );
		}
	}
}
