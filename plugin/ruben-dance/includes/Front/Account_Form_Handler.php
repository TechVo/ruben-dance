<?php
/**
 * Processes every `[rd_account]` form submission (profile, password, email
 * change, marketing consent) and the email-change confirmation link, before
 * any page output is sent.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Lang;
use RubenDance\Services\Email_Change_Service;
use RubenDance\Services\Profile_Service;
use RubenDance\Services\Registration_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Account_Form_Handler.
 *
 * Hooked to `template_redirect`, the same as M07's `Form_Handler` and M08's
 * `Enrollment_Form_Handler`: the only point a redirect can still happen,
 * well before `Account_Page::render()` echoes any HTML. Every write here
 * derives the account it acts on exclusively from `get_current_user_id()` —
 * never from a posted/queried ID — which is the front-end half of spec §5's
 * ownership rule ("Never trust a user ID from the request"); the repository
 * layer (`Enrollment_Repository`/`Account_Service`) is the other half, and a
 * customer's own account settings have nothing to look up by ID in the
 * first place.
 */
class Account_Form_Handler {

	const ACTION_FIELD = 'rd_account_action';

	/**
	 * "Personal details" form result: null (untouched), or
	 * array{ state: 'form'|'success', errors: array<string,string>, submitted: array<string,string> }.
	 *
	 * @var array{state: string, errors: array<string,string>, submitted: array<string,string>}|null
	 */
	public static ?array $profile_result = null;

	/**
	 * "Password" form result: null (untouched), or
	 * array{ state: 'form'|'success', errors: array<string,string> }.
	 *
	 * @var array{state: string, errors: array<string,string>}|null
	 */
	public static ?array $password_result = null;

	/**
	 * "Email change" form result: null (untouched), or
	 * array{ state: 'form'|'requested', errors: array<string,string>, submitted: array<string,string> }.
	 *
	 * @var array{state: string, errors: array<string,string>, submitted: array<string,string>}|null
	 */
	public static ?array $email_result = null;

	/**
	 * "Marketing consent" form result: null (untouched), or array{ state: 'form'|'success' }.
	 *
	 * @var array{state: string}|null
	 */
	public static ?array $consent_result = null;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'handle_request' ) );
	}

	/**
	 * Single entry point dispatching to whichever action (if any) the
	 * current request carries.
	 */
	public static function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only, no state change; handle_email_verify() re-validates the token itself before acting, the same reasoning as Form_Handler::handle_verify().
		if ( isset( $_GET['rd_account_email_verify'] ) && '1' === $_GET['rd_account_email_verify'] ) {
			self::handle_email_verify();
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only; each handler below calls wp_verify_nonce() before reading/writing anything.
		$action = isset( $_POST[ self::ACTION_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) ) : '';

		switch ( $action ) {
			case 'update_profile':
				self::handle_update_profile();
				break;

			case 'update_password':
				self::handle_update_password();
				break;

			case 'request_email_change':
				self::handle_request_email_change();
				break;

			case 'toggle_marketing_consent':
				self::handle_toggle_marketing_consent();
				break;
		}
	}

	/**
	 * Handle the "personal details" (name/phone/locale) form.
	 */
	private static function handle_update_profile(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'rd_account_profile' ) ) {
			self::$profile_result = array(
				'state'     => 'form',
				'errors'    => array( '_form' => __( 'Your session expired, please try again.', 'ruben-dance' ) ),
				'submitted' => array(),
			);
			return;
		}

		$submitted = array(
			'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
			'last_name'  => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
			'phone'      => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'locale'     => isset( $_POST['locale'] ) ? sanitize_key( wp_unslash( $_POST['locale'] ) ) : Lang::DEFAULT_LANGUAGE,
		);

		$service = Profile_Service::create_default();
		$errors  = $service->validate_profile( $submitted );

		if ( array() !== $errors ) {
			self::$profile_result = array(
				'state'     => 'form',
				'errors'    => $errors,
				'submitted' => $submitted,
			);
			return;
		}

		// Always the account of whoever is currently logged in — the form
		// carries no user ID field at all (spec §5: never trust one from
		// the request).
		$service->update_profile( get_current_user_id(), $submitted );

		self::$profile_result = array(
			'state'     => 'success',
			'errors'    => array(),
			'submitted' => array(),
		);
	}

	/**
	 * Handle the "change password" form.
	 */
	private static function handle_update_password(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'rd_account_password' ) ) {
			self::$password_result = array(
				'state'  => 'form',
				'errors' => array( '_form' => __( 'Your session expired, please try again.', 'ruben-dance' ) ),
			);
			return;
		}

		// Not run through sanitize_text_field(): a password is opaque data,
		// must reach wp_set_password() byte-for-byte (same reasoning as
		// Form_Handler::handle_login()).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce already verified above.
		$new_password = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce already verified above.
		$confirm = isset( $_POST['new_password_confirm'] ) ? (string) wp_unslash( $_POST['new_password_confirm'] ) : '';

		$service = Profile_Service::create_default();
		$errors  = $service->validate_password(
			array(
				'new_password'         => $new_password,
				'new_password_confirm' => $confirm,
			)
		);

		if ( array() !== $errors ) {
			self::$password_result = array(
				'state'  => 'form',
				'errors' => $errors,
			);
			return;
		}

		$service->update_password( get_current_user_id(), $new_password );

		self::$password_result = array(
			'state'  => 'success',
			'errors' => array(),
		);
	}

	/**
	 * Handle the "request email change" form (spec F7 acceptance criterion:
	 * "requires re-verification before taking effect" — this only *requests*
	 * the change; `handle_email_verify()` is what actually applies it).
	 */
	private static function handle_request_email_change(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'rd_account_email_change' ) ) {
			self::$email_result = array(
				'state'     => 'form',
				'errors'    => array( '_form' => __( 'Your session expired, please try again.', 'ruben-dance' ) ),
				'submitted' => array(),
			);
			return;
		}

		$new_email = isset( $_POST['new_email'] ) ? sanitize_text_field( wp_unslash( $_POST['new_email'] ) ) : '';
		$user_id   = get_current_user_id();
		$user      = wp_get_current_user();

		$service = Email_Change_Service::create_default();
		$errors  = $service->validate_new_email( $new_email, $user->user_email );

		if ( array() !== $errors ) {
			self::$email_result = array(
				'state'     => 'form',
				'errors'    => $errors,
				'submitted' => array( 'new_email' => $new_email ),
			);
			return;
		}

		$locale = (string) get_user_meta( $user_id, Registration_Service::META_LOCALE, true );
		$locale = '' === $locale ? Lang::DEFAULT_LANGUAGE : $locale;

		$service->request_change( $user_id, trim( $new_email ), $locale );

		self::$email_result = array(
			'state'     => 'requested',
			'errors'    => array(),
			'submitted' => array(),
		);
	}

	/**
	 * Handle the marketing-consent toggle form.
	 */
	private static function handle_toggle_marketing_consent(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'rd_account_marketing_consent' ) ) {
			self::$consent_result = array( 'state' => 'form' );
			return;
		}

		$consent = ! empty( $_POST['marketing_consent'] );

		Profile_Service::create_default()->toggle_marketing_consent( get_current_user_id(), $consent );

		self::$consent_result = array( 'state' => 'success' );
	}

	/**
	 * Handle a confirmation-link click: `?rd_account_email_verify=1&uid=&token=`.
	 * Always redirects back to the account page's profile tab with a notice
	 * code, the same pattern `Form_Handler::handle_verify()` uses — a fresh
	 * GET request can't keep anything in a static property from this one.
	 */
	private static function handle_email_verify(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token itself (single-use, hashed, expiring; see Email_Change_Service::confirm()) *is* the authorization, the same reasoning Form_Handler::handle_verify() documents.
		$user_id = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$result = Email_Change_Service::create_default()->confirm( $user_id, $token );

		$locale = $user_id > 0 ? (string) get_user_meta( $user_id, Registration_Service::META_LOCALE, true ) : Lang::DEFAULT_LANGUAGE;
		$locale = '' === $locale ? Lang::DEFAULT_LANGUAGE : $locale;

		wp_safe_redirect(
			add_query_arg(
				array(
					'rd_tab'          => Account_Page::TAB_PROFILE,
					'rd_email_notice' => $result,
				),
				Pages::url( Account_Page::PAGE_KEY, $locale )
			)
		);
		exit;
	}
}
