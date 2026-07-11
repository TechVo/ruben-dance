<?php
/**
 * "Settings" admin screen: due-date days, admin notification email.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Roles;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Settings_Page.
 *
 * Deliberately minimal (M06 is pure-logic-first): a single form, no list
 * table, storing the two options `Settings` and the enrollment services
 * depend on. Follows the same nonce + `rd_manage` capability + `load-{hook}`
 * processing pattern as `Locations_Page`, scaled down to what one form needs.
 */
class Settings_Page {

	/**
	 * Submenu page slug.
	 *
	 * @var string
	 */
	const SLUG = 'ruben-dance-settings';

	/**
	 * Nonce action for the settings form submission.
	 *
	 * @var string
	 */
	const SAVE_NONCE_ACTION = 'rd_settings_save';

	/**
	 * Validation errors from a failed save, stashed by `handle_request()` for
	 * `render()` to display (see `Locations_Page` for why this two-phase
	 * dance is needed: `load-{hook}` is the only point a redirect can still
	 * happen).
	 *
	 * @var array{0: array<string, string>, 1: array<string, string>}|null
	 */
	private static ?array $form_errors = null;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the "Settings" submenu page under the Ruben Dance top-level menu.
	 */
	public static function add_menu(): void {
		$hook_suffix = add_submenu_page(
			Menu::SLUG,
			__( 'Settings', 'ruben-dance' ),
			__( 'Settings', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( false !== $hook_suffix ) {
			add_action( "load-{$hook_suffix}", array( self::class, 'handle_request' ) );
		}
	}

	/**
	 * Process a save for this screen, before any output is sent. Hooked to
	 * `load-{$hook_suffix}` (see `add_menu()`).
	 */
	public static function handle_request(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage settings.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only routes to the save branch; check_admin_referer() immediately below performs the real verification before any field is read or written.
		if ( ! isset( $_POST['rd_settings_action'] ) || 'save' !== $_POST['rd_settings_action'] ) {
			return;
		}

		check_admin_referer( self::SAVE_NONCE_ACTION );

		$submitted = array(
			'due_date_days'             => isset( $_POST['due_date_days'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date_days'] ) ) : '',
			'admin_notification_email'  => isset( $_POST['admin_notification_email'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_notification_email'] ) ) : '',
			'bank_account'              => isset( $_POST['bank_account'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_account'] ) ) : '',
			'iban'                      => isset( $_POST['iban'] ) ? sanitize_text_field( wp_unslash( $_POST['iban'] ) ) : '',
			'cancelled_lessons_display' => isset( $_POST['cancelled_lessons_display'] ) ? sanitize_text_field( wp_unslash( $_POST['cancelled_lessons_display'] ) ) : '',
			'retention_years'           => isset( $_POST['retention_years'] ) ? sanitize_text_field( wp_unslash( $_POST['retention_years'] ) ) : '',
		);

		$errors = Settings::validate( $submitted );

		if ( array() !== $errors ) {
			self::$form_errors = array( $errors, $submitted );
			return;
		}

		Settings::save( $submitted );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::SLUG,
					'rd_notice' => 'updated',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Main entry point, wired as the submenu page callback. Runs after
	 * `handle_request()` (see `add_menu()`); output only, no state changes.
	 */
	public static function render(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage settings.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( null !== self::$form_errors ) {
			list( $errors, $submitted ) = self::$form_errors;
			self::render_form( $submitted, $errors );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: purely cosmetic (which notice text to show after a redirect), no state change.
		if ( isset( $_GET['rd_notice'] ) && 'updated' === $_GET['rd_notice'] ) {
			self::render_notice( 'success', __( 'Settings saved.', 'ruben-dance' ) );
		}

		self::render_form(
			array(
				'due_date_days'             => (string) Settings::due_date_days(),
				'admin_notification_email'  => Settings::admin_notification_email(),
				'bank_account'              => Settings::bank_account(),
				'iban'                      => Settings::iban(),
				'cancelled_lessons_display' => Settings::cancelled_lessons_display(),
				'retention_years'           => (string) Settings::retention_years(),
			),
			array()
		);
	}

	/**
	 * Render the settings form.
	 *
	 * @param array<string, string> $submitted Field values to prefill.
	 * @param array<string, string> $errors    Field name => error code.
	 */
	private static function render_form( array $submitted, array $errors ): void {
		foreach ( $errors as $code ) {
			self::render_notice( 'error', self::error_message( $code ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Ruben Dance Settings', 'ruben-dance' ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">';

		wp_nonce_field( self::SAVE_NONCE_ACTION );

		echo '<input type="hidden" name="rd_settings_action" value="save">';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="rd_due_date_days">' . esc_html__( 'Payment due-date window (days)', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="number" min="1" step="1" id="rd_due_date_days" name="due_date_days" class="small-text" required="required" value="' . esc_attr( $submitted['due_date_days'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="rd_admin_notification_email">' . esc_html__( 'Admin notification email', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="email" id="rd_admin_notification_email" name="admin_notification_email" class="regular-text" value="' . esc_attr( $submitted['admin_notification_email'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="rd_bank_account">' . esc_html__( 'Bank account number', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="text" id="rd_bank_account" name="bank_account" class="regular-text" value="' . esc_attr( $submitted['bank_account'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Shown in payment instructions on the enrollment confirmation page and email.', 'ruben-dance' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rd_iban">' . esc_html__( 'IBAN (for QR payment codes)', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="text" id="rd_iban" name="iban" class="regular-text" value="' . esc_attr( $submitted['iban'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Enables the scannable QR platba code on payment instructions (emails and My account). Leave blank to keep payment instructions text-only.', 'ruben-dance' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rd_cancelled_lessons_display">' . esc_html__( 'Cancelled lessons on the public calendar', 'ruben-dance' ) . '</label></th><td>';
		echo '<select id="rd_cancelled_lessons_display" name="cancelled_lessons_display">';
		$cancelled_display_labels = array(
			Settings::CANCELLED_LESSONS_STRIKETHROUGH => __( 'Show, struck through', 'ruben-dance' ),
			Settings::CANCELLED_LESSONS_HIDDEN        => __( 'Hide entirely', 'ruben-dance' ),
		);
		foreach ( $cancelled_display_labels as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $submitted['cancelled_lessons_display'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'How [rd_calendar] shows a cancelled lesson (spec F2).', 'ruben-dance' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="rd_retention_years">' . esc_html__( 'Inactive customer retention (years)', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="number" min="1" step="1" id="rd_retention_years" name="retention_years" class="small-text" value="' . esc_attr( $submitted['retention_years'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Customer accounts with no non-cancelled enrollment in this many years are anonymized by the monthly retention cron (spec §6.1).', 'ruben-dance' ) . '</p></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save Settings', 'ruben-dance' ) );

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Echo a dismissible admin notice.
	 *
	 * @param string $type    'success'|'error'.
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
	 * Translate a `Settings::ERROR_*` code into a message.
	 *
	 * @param string $code One of the `Settings::ERROR_*` constants.
	 * @return string
	 */
	private static function error_message( string $code ): string {
		switch ( $code ) {
			case Settings::ERROR_DUE_DATE_DAYS_INVALID:
				return __( 'Payment due-date window must be a positive whole number of days.', 'ruben-dance' );

			case Settings::ERROR_ADMIN_EMAIL_INVALID:
				return __( 'Admin notification email must be a valid email address.', 'ruben-dance' );

			case Settings::ERROR_BANK_ACCOUNT_TOO_LONG:
				return sprintf(
					/* translators: %d: maximum character length. */
					__( 'Bank account number must be %d characters or fewer.', 'ruben-dance' ),
					Settings::BANK_ACCOUNT_MAX_LENGTH
				);

			case Settings::ERROR_CANCELLED_LESSONS_DISPLAY_INVALID:
				return __( 'Invalid cancelled-lessons display option.', 'ruben-dance' );

			case Settings::ERROR_IBAN_INVALID:
				return __( 'IBAN is not valid — please check the account number and country code.', 'ruben-dance' );

			case Settings::ERROR_RETENTION_YEARS_INVALID:
				return __( 'Retention window must be a positive whole number of years.', 'ruben-dance' );

			default:
				return __( 'Invalid input.', 'ruben-dance' );
		}
	}
}
