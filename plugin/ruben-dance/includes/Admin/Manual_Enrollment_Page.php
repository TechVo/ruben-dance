<?php
/**
 * Manual enrollment admin screen (F11b): phone orders — pick or create a
 * customer, then enroll them through the same `Enrollment_Service` the
 * public form uses.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Lang;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Roles;
use RubenDance\Services\Duplicate_Enrollment_Exception;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Registration_Failed_Exception;
use RubenDance\Services\Registration_Service;
use RubenDance\Services\Term_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Manual_Enrollment_Page.
 *
 * Two steps on one screen: pick an existing customer (search) or start a new
 * one, then fill in the enrollment itself. Goes through
 * `Services\Registration_Service::register_pre_verified()` for a new
 * customer — the same "skip the token/email step entirely" method `wp rd
 * seed` uses — so a phone-order account never gets a verification email and
 * is usable immediately (spec F11b: "create minimal account without
 * verification email"), then `Services\Enrollment_Service::create()` for the
 * enrollment itself, so price/variable-symbol/due-date generation and the
 * duplicate-enrollment guard are exactly the same code path the public form
 * uses (spec F11b: "goes through EnrollmentService").
 */
class Manual_Enrollment_Page {

	const SLUG = 'ruben-dance-manual-enrollment';

	const SAVE_NONCE_ACTION = 'rd_manual_enrollment_save';

	/**
	 * A positive placeholder used only to exercise
	 * `Enrollment_Service::validate()`'s "user_id required" rule before a
	 * brand-new customer's real account (and therefore real ID) exists yet.
	 * `validate()` never checks that an ID actually exists, only that it is
	 * positive, so any fixed positive integer works identically here.
	 *
	 * @var int
	 */
	const VALIDATION_PLACEHOLDER_USER_ID = 999999999;

	/**
	 * State stashed by `maybe_handle_save()` on a validation failure, for
	 * `render()` to redisplay the same step with errors + prefilled values
	 * (see `Locations_Page::$form_errors` for the same pattern).
	 *
	 * @var array{mode: string, errors: array<string, string>, customer_submitted: array<string, string>, enrollment_submitted: array<string, mixed>, user_id: int}|null
	 */
	private static ?array $form_state = null;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the "New Enrollment" submenu page under the Ruben Dance top-level menu.
	 */
	public static function add_menu(): void {
		$hook_suffix = add_submenu_page(
			Menu::SLUG,
			__( 'New Manual Enrollment', 'ruben-dance' ),
			__( 'New Enrollment', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( false !== $hook_suffix ) {
			add_action( "load-{$hook_suffix}", array( self::class, 'handle_request' ) );
		}
	}

	/**
	 * URL to this screen (the customer-picker landing view).
	 *
	 * @return string
	 */
	public static function url(): string {
		return add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * Process a save for this screen, before any output is sent.
	 */
	public static function handle_request(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to create enrollments.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		self::maybe_handle_save();
	}

	/**
	 * Handle the create-enrollment form submission, if this request is one.
	 */
	private static function maybe_handle_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only routes to the save branch; check_admin_referer() immediately below performs the real verification before any field is read or written.
		if ( ! isset( $_POST['rd_manual_action'] ) || 'create' !== $_POST['rd_manual_action'] ) {
			return;
		}

		check_admin_referer( self::SAVE_NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() above.
		$mode = isset( $_POST['customer_mode'] ) ? sanitize_key( wp_unslash( $_POST['customer_mode'] ) ) : 'existing';
		$mode = in_array( $mode, array( 'existing', 'new' ), true ) ? $mode : 'existing';

		$enrollment_submitted = self::read_enrollment_fields();

		$user_id            = 0;
		$customer_submitted = array();
		$registration_data  = array();

		if ( 'existing' === $mode ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() above.
			$user_id = isset( $_POST['existing_user_id'] ) ? absint( $_POST['existing_user_id'] ) : 0;

			if ( $user_id <= 0 || false === get_userdata( $user_id ) ) {
				// Only reachable via a forged/tampered request — the UI never
				// shows the enrollment fields without a valid selected customer.
				self::redirect( array( 'rd_notice' => 'customer_invalid' ) );
				return;
			}
		} else {
			$customer_submitted = self::read_customer_fields();
			$registration_data  = array_merge(
				$customer_submitted,
				array(
					// Generated server-side, never taken from the phone-order
					// admin (spec F11b: "create minimal account without
					// verification email"); the customer never sees or needs
					// this password — they log in via "lost password" if they
					// ever want web access.
					'password'          => wp_generate_password( 24, true, true ),
					'locale'            => Lang::DEFAULT_LANGUAGE,
					// The owner confirms the T&C with the customer over the
					// phone; there is no on-screen checkbox to tick on the
					// customer's behalf.
					'tc_accepted'       => true,
					'marketing_consent' => false,
				)
			);
		}

		$errors = array();

		if ( 'new' === $mode ) {
			$errors = Registration_Service::create_default()->validate( $registration_data );
		}

		$service               = Enrollment_Service::create_default();
		$enrollment_check_data = array_merge(
			$enrollment_submitted,
			array( 'user_id' => $user_id > 0 ? $user_id : self::VALIDATION_PLACEHOLDER_USER_ID )
		);
		$errors                = array_merge( $errors, $service->validate( $enrollment_check_data ) );

		if ( array() !== $errors ) {
			self::$form_state = array(
				'mode'                 => $mode,
				'errors'               => $errors,
				'customer_submitted'   => $customer_submitted,
				'enrollment_submitted' => $enrollment_submitted,
				'user_id'              => $user_id,
			);
			return;
		}

		if ( 'new' === $mode ) {
			try {
				$user_id = Registration_Service::create_default()->register_pre_verified( $registration_data );
			} catch ( Registration_Failed_Exception $e ) {
				self::$form_state = array(
					'mode'                 => $mode,
					'errors'               => array( '_form' => 'account_creation_failed' ),
					'customer_submitted'   => $customer_submitted,
					'enrollment_submitted' => $enrollment_submitted,
					'user_id'              => 0,
				);
				return;
			}
		}

		$actor      = wp_get_current_user();
		$final_data = array_merge(
			$enrollment_submitted,
			array(
				'user_id'    => $user_id,
				'admin_note' => sprintf( 'Manual phone-order enrollment created by %s.', $actor->display_name ),
			)
		);

		try {
			$id = $service->create( $final_data );
		} catch ( Duplicate_Enrollment_Exception $e ) {
			self::$form_state = array(
				'mode'                 => $mode,
				'errors'               => array( '_form' => 'duplicate' ),
				'customer_submitted'   => $customer_submitted,
				'enrollment_submitted' => $enrollment_submitted,
				'user_id'              => $user_id,
			);
			return;
		}

		wp_safe_redirect( add_query_arg( array( 'rd_notice' => 'manual_enrollment_created' ), Enrollment_Detail_Page::url( $id ) ) );
		exit;
	}

	/**
	 * Read and sanitize the enrollment-side fields from `$_POST`.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_enrollment_fields(): array {
		return array(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			'term_id'          => isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			'participant_name' => isset( $_POST['participant_name'] ) ? sanitize_text_field( wp_unslash( $_POST['participant_name'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			'role'             => isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : Enrollment_Service::ROLE_SOLO,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			'partner_name'     => isset( $_POST['partner_name'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_name'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			'manual_price'     => isset( $_POST['manual_price'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_price'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			'payment_method'   => isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : Enrollment_Service::PAYMENT_BANK_TRANSFER,
		);
	}

	/**
	 * Read and sanitize the new-customer fields from `$_POST`.
	 *
	 * @return array<string, string>
	 */
	private static function read_customer_fields(): array {
		return array(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			'last_name'  => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			'email'      => isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() in the caller before this method runs.
			'phone'      => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
		);
	}

	/**
	 * Main entry point, wired as the submenu page callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to create enrollments.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( null !== self::$form_state ) {
			$state    = self::$form_state;
			$customer = $state['user_id'] > 0 ? get_userdata( $state['user_id'] ) : false;

			self::render_page( $state['mode'], $customer, $state['customer_submitted'], $state['enrollment_submitted'], $state['errors'] );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which customer to preselect, no state change.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which step of the form shows, no state change.
		$new_customer = isset( $_GET['new_customer'] ) && '1' === $_GET['new_customer'];

		if ( $user_id > 0 ) {
			$customer = get_userdata( $user_id );

			if ( false === $customer ) {
				echo '<div class="wrap">';
				self::render_notice( 'error', __( 'Customer not found.', 'ruben-dance' ) );
				echo '</div>';
				self::render_customer_picker();
				return;
			}

			self::render_page( 'existing', $customer, array(), self::blank_enrollment_fields(), array() );
			return;
		}

		if ( $new_customer ) {
			self::render_page( 'new', false, self::blank_customer_fields(), self::blank_enrollment_fields(), array() );
			return;
		}

		self::render_customer_picker();
	}

	/**
	 * Step 1: find an existing customer, or start a new one.
	 */
	private static function render_customer_picker(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'New Manual Enrollment', 'ruben-dance' ) . '</h1>';
		echo '<p>' . esc_html__( 'Phone orders: find the customer, or create a new account for them.', 'ruben-dance' ) . '</p>';

		self::render_notice_from_query();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the search results show, no state change.
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		echo '<h2>' . esc_html__( 'Find an existing customer', 'ruben-dance' ) . '</h2>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search name or email…', 'ruben-dance' ) . '"> ';
		submit_button( __( 'Search', 'ruben-dance' ), '', '', false );
		echo '</form>';

		if ( '' !== $q ) {
			$results = get_users(
				array(
					'search'         => '*' . $q . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
					'role__not_in'   => array( 'administrator', Roles::ROLE ),
					'number'         => 20,
					'orderby'        => 'display_name',
					'order'          => 'ASC',
				)
			);

			if ( array() === $results ) {
				echo '<p>' . esc_html__( 'No matching customers.', 'ruben-dance' ) . '</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:700px;"><thead><tr>';
				echo '<th>' . esc_html__( 'Name', 'ruben-dance' ) . '</th><th>' . esc_html__( 'Email', 'ruben-dance' ) . '</th><th></th>';
				echo '</tr></thead><tbody>';

				foreach ( $results as $user ) {
					echo '<tr><td>' . esc_html( $user->display_name ) . '</td><td>' . esc_html( $user->user_email ) . '</td><td>';
					echo '<a href="' . esc_url( add_query_arg( array( 'user_id' => $user->ID ), self::url() ) ) . '" class="button">' . esc_html__( 'Select', 'ruben-dance' ) . '</a>';
					echo '</td></tr>';
				}

				echo '</tbody></table>';
			}
		}

		echo '<h2>' . esc_html__( 'Or create a new customer', 'ruben-dance' ) . '</h2>';
		echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'new_customer' => 1 ), self::url() ) ) . '">' . esc_html__( 'New customer', 'ruben-dance' ) . '</a></p>';

		echo '</div>';
	}

	/**
	 * Step 2: the enrollment form itself, with the customer already
	 * decided (existing, selected above, or a new one about to be created).
	 *
	 * @param string                $mode                 'existing'|'new'.
	 * @param \WP_User|false        $customer             The selected existing customer, or false in 'new' mode (or on a failed lookup).
	 * @param array<string, string> $customer_submitted   New-customer field values to prefill (mode 'new' only).
	 * @param array<string, mixed>  $enrollment_submitted Enrollment field values to prefill.
	 * @param array<string, string> $errors                Field name (or `_form`) => error code.
	 */
	private static function render_page( string $mode, $customer, array $customer_submitted, array $enrollment_submitted, array $errors ): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'New Manual Enrollment', 'ruben-dance' ) . '</h1>';

		if ( isset( $errors['_form'] ) ) {
			self::render_notice( 'error', self::error_message( $errors['_form'] ) );
		}

		foreach ( $errors as $field => $code ) {
			if ( '_form' === $field ) {
				continue;
			}

			self::render_notice( 'error', self::error_message( $code ) );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">';
		wp_nonce_field( self::SAVE_NONCE_ACTION );
		echo '<input type="hidden" name="rd_manual_action" value="create">';
		echo '<input type="hidden" name="customer_mode" value="' . esc_attr( $mode ) . '">';

		echo '<h2>' . esc_html__( 'Customer', 'ruben-dance' ) . '</h2>';

		if ( 'existing' === $mode && false !== $customer ) {
			echo '<input type="hidden" name="existing_user_id" value="' . esc_attr( (string) $customer->ID ) . '">';
			echo '<p><strong>' . esc_html( $customer->display_name ) . '</strong> — ' . esc_html( $customer->user_email ) . '</p>';
			echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Change customer', 'ruben-dance' ) . '</a></p>';
		} else {
			echo '<table class="form-table"><tbody>';
			self::text_row( 'first_name', __( 'First name', 'ruben-dance' ), $customer_submitted['first_name'] ?? '', true );
			self::text_row( 'last_name', __( 'Last name', 'ruben-dance' ), $customer_submitted['last_name'] ?? '', true );
			self::text_row( 'email', __( 'Email', 'ruben-dance' ), $customer_submitted['email'] ?? '', true, 'email' );
			self::text_row( 'phone', __( 'Phone', 'ruben-dance' ), $customer_submitted['phone'] ?? '', true );
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'A minimal account is created for this customer without sending a verification email; it is marked verified immediately.', 'ruben-dance' ) . '</p>';
			echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Search for an existing customer instead', 'ruben-dance' ) . '</a></p>';
		}

		echo '<h2>' . esc_html__( 'Enrollment', 'ruben-dance' ) . '</h2>';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="rd_manual_term_id">' . esc_html__( 'Term', 'ruben-dance' ) . '</label></th><td>';
		echo '<select id="rd_manual_term_id" name="term_id" required="required">';
		echo '<option value="">' . esc_html__( '— select an open term —', 'ruben-dance' ) . '</option>';

		foreach ( ( new Course_Term_Repository() )->all_with_filters( array( 'status' => Term_Service::STATUS_OPEN ) ) as $term ) {
			printf(
				'<option value="%1$d"%2$s>%3$s — %4$s</option>',
				(int) $term['id'],
				selected( (int) $enrollment_submitted['term_id'], (int) $term['id'], false ),
				esc_html( (string) $term['season_label_cs'] ),
				esc_html( get_the_title( (int) $term['course_id'] ) )
			);
		}

		echo '</select></td></tr>';

		self::text_row( 'participant_name', __( 'Participant name', 'ruben-dance' ), (string) $enrollment_submitted['participant_name'], false, 'text', __( 'Leave blank if the customer is enrolling themselves.', 'ruben-dance' ) );

		echo '<tr><th scope="row"><label for="rd_manual_role">' . esc_html__( 'Role', 'ruben-dance' ) . '</label></th><td>';
		echo '<select id="rd_manual_role" name="role">';
		foreach (
			array(
				Enrollment_Service::ROLE_SOLO     => __( 'Solo', 'ruben-dance' ),
				Enrollment_Service::ROLE_LEADER   => __( 'Leader', 'ruben-dance' ),
				Enrollment_Service::ROLE_FOLLOWER => __( 'Follower', 'ruben-dance' ),
			) as $value => $label
		) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $enrollment_submitted['role'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		self::text_row( 'partner_name', __( 'Partner name', 'ruben-dance' ), (string) $enrollment_submitted['partner_name'], false );
		self::text_row( 'manual_price', __( 'Manual price (CZK)', 'ruben-dance' ), (string) $enrollment_submitted['manual_price'], false, 'number', __( 'Leave blank to use the term’s normal computed price.', 'ruben-dance' ) );

		echo '<tr><th scope="row"><label for="rd_manual_payment_method">' . esc_html__( 'Payment method', 'ruben-dance' ) . '</label></th><td>';
		echo '<select id="rd_manual_payment_method" name="payment_method">';
		foreach (
			array(
				Enrollment_Service::PAYMENT_BANK_TRANSFER => __( 'Bank transfer', 'ruben-dance' ),
				Enrollment_Service::PAYMENT_CASH          => __( 'Cash', 'ruben-dance' ),
			) as $value => $label
		) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $enrollment_submitted['payment_method'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Create Enrollment', 'ruben-dance' ) );

		echo '</form>';
		echo '<p><a href="' . esc_url( Enrollments_Page::url() ) . '">' . esc_html__( 'Back to enrollments', 'ruben-dance' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * A plain `<input>` field row (mirrors `Terms_Page::render_text_field()`).
	 *
	 * @param string $name     Field name.
	 * @param string $label    Field label.
	 * @param string $value    Current value.
	 * @param bool   $required Whether the field is required.
	 * @param string $type     HTML input type.
	 * @param string $help     Optional help text below the field.
	 */
	private static function text_row( string $name, string $label, string $value, bool $required = false, string $type = 'text', string $help = '' ): void {
		$step = 'number' === $type ? ' step="0.01" min="0"' : '';

		echo '<tr><th scope="row"><label for="rd_manual_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" id="rd_manual_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="regular-text"' . $step . ( $required ? ' required="required"' : '' ) . ' value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $step is one of two hardcoded literal strings assigned above, never derived from input.

		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Default (blank) enrollment field values.
	 *
	 * @return array<string, mixed>
	 */
	private static function blank_enrollment_fields(): array {
		return array(
			'term_id'          => 0,
			'participant_name' => '',
			'role'             => Enrollment_Service::ROLE_SOLO,
			'partner_name'     => '',
			'manual_price'     => '',
			'payment_method'   => Enrollment_Service::PAYMENT_BANK_TRANSFER,
		);
	}

	/**
	 * Default (blank) new-customer field values.
	 *
	 * @return array<string, string>
	 */
	private static function blank_customer_fields(): array {
		return array(
			'first_name' => '',
			'last_name'  => '',
			'email'      => '',
			'phone'      => '',
		);
	}

	/**
	 * Redirect back to this screen with a notice code (POST-redirect-GET).
	 *
	 * @param array<string, string> $args Extra query args (e.g. `rd_notice`).
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
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
			'customer_invalid' => array( 'error', __( 'Please select or create a customer first.', 'ruben-dance' ) ),
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
	 * Translate an error code — from either `Registration_Service::validate()`
	 * or `Enrollment_Service::validate()`, plus this page's own `_form`-level
	 * codes — into a message. The two services' error codes never collide
	 * (`first_name`/`last_name`/`email`/`phone`/`password`/`tc_accepted` vs.
	 * `term_id`/`user_id`/`participant_name`/`role`/`partner_name`/`manual_price`),
	 * so one switch can safely cover both.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	private static function error_message( string $code ): string {
		switch ( $code ) {
			case Registration_Service::ERROR_FIRST_NAME_REQUIRED:
				return __( 'First name is required.', 'ruben-dance' );

			case Registration_Service::ERROR_FIRST_NAME_TOO_LONG:
				return __( 'First name must be 190 characters or fewer.', 'ruben-dance' );

			case Registration_Service::ERROR_LAST_NAME_REQUIRED:
				return __( 'Last name is required.', 'ruben-dance' );

			case Registration_Service::ERROR_LAST_NAME_TOO_LONG:
				return __( 'Last name must be 190 characters or fewer.', 'ruben-dance' );

			case Registration_Service::ERROR_EMAIL_REQUIRED:
				return __( 'Email is required.', 'ruben-dance' );

			case Registration_Service::ERROR_EMAIL_INVALID:
				return __( 'Email is invalid.', 'ruben-dance' );

			case Registration_Service::ERROR_EMAIL_TAKEN:
				return __( 'An account with this email already exists — search for the existing customer instead.', 'ruben-dance' );

			case Registration_Service::ERROR_PHONE_REQUIRED:
				return __( 'Phone is required.', 'ruben-dance' );

			case Registration_Service::ERROR_PHONE_INVALID:
				return __( 'Phone is invalid.', 'ruben-dance' );

			case Enrollment_Service::ERROR_TERM_REQUIRED:
				return __( 'Select a term.', 'ruben-dance' );

			case Enrollment_Service::ERROR_TERM_NOT_FOUND:
				return __( 'Selected term not found.', 'ruben-dance' );

			case Enrollment_Service::ERROR_TERM_NOT_OPEN:
				return __( 'Selected term is not open for enrollment.', 'ruben-dance' );

			case Enrollment_Service::ERROR_USER_REQUIRED:
				return __( 'Select or create a customer.', 'ruben-dance' );

			case Enrollment_Service::ERROR_PARTICIPANT_TOO_LONG:
				return __( 'Participant name must be 190 characters or fewer.', 'ruben-dance' );

			case Enrollment_Service::ERROR_ROLE_INVALID:
				return __( 'Invalid role.', 'ruben-dance' );

			case Enrollment_Service::ERROR_PARTNER_NAME_TOO_LONG:
				return __( 'Partner name must be 190 characters or fewer.', 'ruben-dance' );

			case Enrollment_Service::ERROR_MANUAL_PRICE_INVALID:
				return __( 'Manual price must be a non-negative amount.', 'ruben-dance' );

			case 'account_creation_failed':
				return __( 'Could not create the customer account — please try again.', 'ruben-dance' );

			case 'duplicate':
				return __( 'This customer/participant is already enrolled in this term.', 'ruben-dance' );

			default:
				return __( 'Invalid input.', 'ruben-dance' );
		}
	}
}
