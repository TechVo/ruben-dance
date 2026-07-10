<?php
/**
 * Processes every auth form submission (register/login/lost-password) and
 * the verification-link click, before any page output is sent.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Lang;
use RubenDance\Services\Rate_Limiter;
use RubenDance\Services\Registration_Failed_Exception;
use RubenDance\Services\Registration_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Form_Handler.
 *
 * All processing is hooked to `template_redirect` — the front-end
 * equivalent of the admin screens' `load-{$hook_suffix}` pattern
 * (`Locations_Page`): the only point a redirect can still happen, well
 * before `Shortcodes::render_*()` echoes any HTML. Results are stashed on
 * public static properties for `Shortcodes` to read back within the same
 * request (never persisted, never read across requests).
 */
class Form_Handler {

	const ACTION_FIELD = 'rd_auth_action';

	const MAX_ATTEMPTS_REGISTER      = 5;
	const MAX_ATTEMPTS_LOGIN         = 10;
	const MAX_ATTEMPTS_LOST_PASSWORD = 5;
	const RATE_LIMIT_WINDOW_SECONDS  = 900; // 15 minutes.

	/**
	 * Registration form result for `Shortcodes::render_register()`:
	 * null (untouched), or array{ state: 'success'|'form'|'rate_limited', errors: array<string,string>, submitted: array<string,string> }.
	 *
	 * @var array{state: string, errors: array<string,string>, submitted: array<string,string>}|null
	 */
	public static ?array $register_result = null;

	/**
	 * Login form result: null (untouched), or array{ error: string, submitted_email: string }.
	 *
	 * @var array{error: string, submitted_email: string}|null
	 */
	public static ?array $login_result = null;

	/**
	 * Lost-password form result: null (untouched), or
	 * array{ state: 'request'|'request_done'|'reset'|'reset_done'|'invalid_key'|'rate_limited', errors: array<string,string>, submitted_email: string }.
	 *
	 * @var array{state: string, errors: array<string,string>, submitted_email: string}|null
	 */
	public static ?array $lost_password_result = null;

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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only, no state read/changed; every branch below verifies its own nonce before acting.
		if ( isset( $_GET['rd_verify'] ) && '1' === $_GET['rd_verify'] ) {
			self::handle_verify();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only; each handler below calls wp_verify_nonce()/check_admin_referer() before reading/writing anything.
		$action = isset( $_POST[ self::ACTION_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) ) : '';

		switch ( $action ) {
			case 'register':
				self::handle_register();
				break;

			case 'login':
				self::handle_login();
				break;

			case 'lost_password_request':
				self::handle_lost_password_request();
				break;

			case 'reset_password':
				self::handle_reset_password();
				break;
		}
	}

	/**
	 * Handle a verification-link click: `?rd_verify=1&uid=&token=`.
	 */
	private static function handle_verify(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token itself (single-use, hashed, expiring; see Registration_Service::verify()) *is* the authorization; a nonce would need to be embedded in an email link days in advance, which WordPress nonces do not support well past 24-48h anyway.
		$user_id = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$result = Registration_Service::create_default()->verify( $user_id, $token );

		$locale = $user_id > 0 ? (string) get_user_meta( $user_id, Registration_Service::META_LOCALE, true ) : Lang::DEFAULT_LANGUAGE;
		$locale = '' === $locale ? Lang::DEFAULT_LANGUAGE : $locale;

		wp_safe_redirect(
			add_query_arg( 'rd_notice', $result, Pages::url( Pages::LOGIN, $locale ) )
		);
		exit;
	}

	/**
	 * Handle `[rd_register]` form submission.
	 */
	private static function handle_register(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'rd_register' ) ) {
			self::$register_result = array(
				'state'     => 'form',
				'errors'    => array( '_form' => __( 'Your session expired, please try again.', 'ruben-dance' ) ),
				'submitted' => array(),
			);
			return;
		}

		$submitted = self::sanitized_post(
			array( 'first_name', 'last_name', 'email', 'phone', 'tc_accepted', 'marketing_consent' )
		);

		// Not run through sanitize_text_field(): that would mangle otherwise
		// legal password characters (tag-like sequences, collapsed
		// whitespace, ...) — a password is opaque data, not display text.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified above; a password is opaque data and must reach wp_insert_user() byte-for-byte.
		$submitted['password'] = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		// Bot baseline (spec §5): a filled honeypot or an instantly-submitted
		// form is silently dropped — the visitor sees the same success screen
		// a real registrant would, but no account is created and no email sent.
		if ( Bot_Guard::is_bot( wp_unslash( $_POST ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- Bot_Guard reads/validates its own signed fields; nonce already checked above.
			self::$register_result = array(
				'state'     => 'success',
				'errors'    => array(),
				'submitted' => array(),
			);
			return;
		}

		if ( Rate_Limiter::create_default()->too_many_attempts( 'register', self::client_ip(), self::MAX_ATTEMPTS_REGISTER, self::RATE_LIMIT_WINDOW_SECONDS ) ) {
			self::$register_result = array(
				'state'     => 'rate_limited',
				'errors'    => array(),
				'submitted' => $submitted,
			);
			return;
		}

		$service = Registration_Service::create_default();
		$errors  = $service->validate( $submitted );

		if ( array() !== $errors ) {
			self::$register_result = array(
				'state'     => 'form',
				'errors'    => $errors,
				'submitted' => $submitted,
			);
			return;
		}

		try {
			$service->register(
				array_merge(
					$submitted,
					array( 'locale' => \RubenDance\Lang::create_default()->current() )
				)
			);
		} catch ( Registration_Failed_Exception $e ) {
			self::$register_result = array(
				'state'     => 'form',
				'errors'    => array( '_form' => __( 'Registration failed, please try again.', 'ruben-dance' ) ),
				'submitted' => $submitted,
			);
			return;
		}

		self::$register_result = array(
			'state'     => 'success',
			'errors'    => array(),
			'submitted' => array(),
		);
	}

	/**
	 * Handle `[rd_login]` form submission.
	 */
	private static function handle_login(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'rd_login' ) ) {
			self::$login_result = array(
				'error'           => __( 'Your session expired, please try again.', 'ruben-dance' ),
				'submitted_email' => '',
			);
			return;
		}

		$email = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is opaque data (must reach wp_signon() byte-for-byte), not display text; sanitize_text_field() would mangle otherwise-legal characters.
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$remember = ! empty( $_POST['remember'] );

		if ( Rate_Limiter::create_default()->too_many_attempts( 'login', self::client_ip(), self::MAX_ATTEMPTS_LOGIN, self::RATE_LIMIT_WINDOW_SECONDS ) ) {
			self::$login_result = array(
				'error'           => __( 'Too many login attempts. Please try again later.', 'ruben-dance' ),
				'submitted_email' => $email,
			);
			return;
		}

		$user = wp_signon(
			array(
				'user_login'    => $email,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			// Same message for "no such account" and "wrong password" (spec
			// §5: "generic error messages on login/reset, don't reveal
			// whether an email exists"); `rd_account_unverified` is distinct
			// on purpose — it only ever fires after the password already
			// matched, so it leaks nothing an attacker didn't already know.
			$message = 'rd_account_unverified' === $user->get_error_code()
				? $user->get_error_message()
				: __( 'Incorrect email or password.', 'ruben-dance' );

			self::$login_result = array(
				'error'           => $message,
				'submitted_email' => $email,
			);
			return;
		}

		$redirect_to = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';

		wp_safe_redirect( wp_validate_redirect( $redirect_to, home_url( '/' ) ) );
		exit;
	}

	/**
	 * Handle the "request a reset link" half of `[rd_lost_password]`.
	 */
	private static function handle_lost_password_request(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'rd_lost_password_request' ) ) {
			self::$lost_password_result = array(
				'state'           => 'request',
				'errors'          => array( '_form' => __( 'Your session expired, please try again.', 'ruben-dance' ) ),
				'submitted_email' => '',
			);
			return;
		}

		$email = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';

		if ( Rate_Limiter::create_default()->too_many_attempts( 'lost_password', self::client_ip(), self::MAX_ATTEMPTS_LOST_PASSWORD, self::RATE_LIMIT_WINDOW_SECONDS ) ) {
			self::$lost_password_result = array(
				'state'           => 'rate_limited',
				'errors'          => array(),
				'submitted_email' => $email,
			);
			return;
		}

		add_filter( 'retrieve_password_message', array( self::class, 'filter_retrieve_password_message' ), 10, 4 );
		retrieve_password( $email ); // Return value intentionally ignored: same message either way, see below.
		remove_filter( 'retrieve_password_message', array( self::class, 'filter_retrieve_password_message' ), 10 );

		// Always the same outcome regardless of whether the email exists
		// (spec §5: no user enumeration on login/reset).
		self::$lost_password_result = array(
			'state'           => 'request_done',
			'errors'          => array(),
			'submitted_email' => '',
		);
	}

	/**
	 * Replace WP core's `wp-login.php?action=rp` reset link with one
	 * pointing at our own `[rd_lost_password]` page (spec F4: customers are
	 * never sent to `wp-login.php` styling) — the key/login validation
	 * itself is still 100% WP core (`check_password_reset_key()`).
	 *
	 * @param string   $message    Default core message (discarded).
	 * @param string   $key        Password reset key.
	 * @param string   $user_login User login.
	 * @param \WP_User $user_data  The user.
	 * @return string
	 */
	public static function filter_retrieve_password_message( string $message, string $key, string $user_login, \WP_User $user_data ): string {
		unset( $message );

		$locale = (string) get_user_meta( $user_data->ID, Registration_Service::META_LOCALE, true );
		$locale = '' === $locale ? Lang::DEFAULT_LANGUAGE : $locale;

		$link = add_query_arg(
			array(
				'key'   => $key,
				'login' => rawurlencode( $user_login ),
			),
			Pages::url( Pages::LOST_PASSWORD, $locale )
		);

		if ( Lang::EN === $locale ) {
			return sprintf(
				/* translators: %s: password reset link. */
				__( "Someone requested a password reset for your Ruben Dance account.\n\nIf this was you, click the link below to choose a new password:\n%s\n\nIf you did not request this, you can safely ignore this email.", 'ruben-dance' ),
				$link
			);
		}

		return sprintf(
			/* translators: %s: password reset link. */
			__( "Někdo požádal o obnovení hesla k vašemu účtu Ruben Dance.\n\nPokud jste to byli vy, klikněte na odkaz níže a zvolte si nové heslo:\n%s\n\nPokud jste o obnovení hesla nežádali, tento email prosím ignorujte.", 'ruben-dance' ),
			$link
		);
	}

	/**
	 * Handle the "set a new password" half of `[rd_lost_password]`.
	 */
	private static function handle_reset_password(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'rd_reset_password' ) ) {
			self::$lost_password_result = array(
				'state'           => 'request',
				'errors'          => array( '_form' => __( 'Your session expired, please try again.', 'ruben-dance' ) ),
				'submitted_email' => '',
			);
			return;
		}

		$login = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';
		$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is opaque data (must reach reset_password() byte-for-byte), not display text; sanitize_text_field() would mangle otherwise-legal characters.
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			self::$lost_password_result = array(
				'state'           => 'invalid_key',
				'errors'          => array(),
				'submitted_email' => '',
			);
			return;
		}

		if ( strlen( $password ) < Registration_Service::MIN_PASSWORD_LENGTH ) {
			self::$lost_password_result = array(
				'state'           => 'reset',
				'errors'          => array( 'password' => Registration_Service::ERROR_PASSWORD_TOO_SHORT ),
				'submitted_email' => '',
			);
			// Re-expose the key/login so the form can be resubmitted.
			self::$lost_password_result['key']   = $key;
			self::$lost_password_result['login'] = $login;
			return;
		}

		reset_password( $user, $password );

		self::$lost_password_result = array(
			'state'           => 'reset_done',
			'errors'          => array(),
			'submitted_email' => '',
		);
	}

	/**
	 * Sanitize a fixed set of `$_POST` fields as plain text, defaulting
	 * missing ones to `''`.
	 *
	 * @param string[] $fields Field names to read.
	 * @return array<string, string>
	 */
	private static function sanitized_post( array $fields ): array {
		$out = array();

		foreach ( $fields as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
			$out[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}

		return $out;
	}

	/**
	 * Request IP address, used only as a rate-limit bucket key (never stored).
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only as an opaque rate-limit bucket key (hashed by Rate_Limiter), never echoed or stored verbatim.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
	}
}
