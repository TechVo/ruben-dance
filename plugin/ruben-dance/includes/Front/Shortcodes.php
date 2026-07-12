<?php
/**
 * `[rd_login]`, `[rd_register]`, `[rd_lost_password]` shortcodes.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Lang;
use RubenDance\Services\Registration_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Shortcodes.
 *
 * Output only — every state change (form submission, verification-link
 * click) already happened in `Form_Handler` on `template_redirect`, well
 * before shortcode rendering runs during `the_content`. Each `render_*()`
 * method just reads `Form_Handler`'s result for the current request (or a
 * fresh/default state) and includes the matching template partial from
 * `public/templates/`.
 */
class Shortcodes {

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_styles' ) );
	}

	/**
	 * Register the three shortcodes.
	 */
	public static function register_shortcodes(): void {
		add_shortcode( 'rd_login', array( self::class, 'render_login' ) );
		add_shortcode( 'rd_register', array( self::class, 'render_register' ) );
		add_shortcode( 'rd_lost_password', array( self::class, 'render_lost_password' ) );
	}

	/**
	 * Minimal shared stylesheet for the three auth forms. Small enough to
	 * load unconditionally rather than detecting which page has which
	 * shortcode.
	 */
	public static function enqueue_styles(): void {
		wp_enqueue_style(
			'rd-front-auth',
			plugins_url( 'public/assets/front-auth.css', RUBEN_DANCE_PLUGIN_FILE ),
			array( 'rd-design' ),
			RUBEN_DANCE_VERSION
		);
	}

	/**
	 * `[rd_login]`.
	 *
	 * @return string
	 */
	public static function render_login(): string {
		if ( is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You are already logged in.', 'ruben-dance' ) . '</p>';
		}

		$result = Form_Handler::$login_result ?? array(
			'error'           => '',
			'submitted_email' => '',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: purely cosmetic (which notice to show after the verification-link redirect), no state change.
		$notice = isset( $_GET['rd_notice'] ) ? sanitize_key( wp_unslash( $_GET['rd_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_to = isset( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) : '';

		return self::render_template(
			'login-form',
			array(
				'error'             => $result['error'],
				'submitted_email'   => $result['submitted_email'],
				'notice'            => $notice,
				'redirect_to'       => $redirect_to,
				'register_url'      => Pages::url( Pages::REGISTER, Lang::create_default()->current() ),
				'lost_password_url' => Pages::url( Pages::LOST_PASSWORD, Lang::create_default()->current() ),
			)
		);
	}

	/**
	 * `[rd_register]`.
	 *
	 * @return string
	 */
	public static function render_register(): string {
		if ( is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You are already logged in.', 'ruben-dance' ) . '</p>';
		}

		$result = Form_Handler::$register_result ?? array(
			'state'     => 'form',
			'errors'    => array(),
			'submitted' => array(),
		);

		if ( 'success' === $result['state'] ) {
			return self::render_template( 'register-success', array() );
		}

		wp_enqueue_script( 'password-strength-meter' );
		wp_enqueue_script(
			'rd-password-strength',
			plugins_url( 'public/assets/password-strength.js', RUBEN_DANCE_PLUGIN_FILE ),
			array( 'jquery', 'password-strength-meter' ),
			RUBEN_DANCE_VERSION,
			true
		);
		wp_localize_script(
			'rd-password-strength',
			'rdPasswordStrengthL10n',
			array(
				'short'    => __( 'Too short', 'ruben-dance' ),
				'bad'      => __( 'Very weak', 'ruben-dance' ),
				'good'     => __( 'Medium', 'ruben-dance' ),
				'strong'   => __( 'Strong', 'ruben-dance' ),
				'mismatch' => __( 'Passwords do not match', 'ruben-dance' ),
			)
		);

		$lang = Lang::create_default()->current();

		return self::render_template(
			'register-form',
			array(
				'errors'             => $result['errors'],
				'submitted'          => $result['submitted'],
				'rate_limited'       => 'rate_limited' === $result['state'],
				// M15/§6.1+§6.3: both link to the plugin's own bilingual
				// placeholder pages (`Cli\Seed_Command::LEGAL_PAGES`) rather
				// than `get_privacy_policy_url()` (WP core's single-page,
				// single-language option) — the visitor's own locale decides
				// which language they land on, with `Pages::url()`'s built-in
				// fallback to Czech, then the home page, if a page is missing.
				'privacy_policy_url' => Pages::url( Pages::PRIVACY_POLICY, $lang ),
				'terms_url'          => Pages::url( Pages::TERMS, $lang ),
				'login_url'          => Pages::url( Pages::LOGIN, $lang ),
			)
		);
	}

	/**
	 * `[rd_lost_password]`.
	 *
	 * @return string
	 */
	public static function render_lost_password(): string {
		if ( is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You are already logged in.', 'ruben-dance' ) . '</p>';
		}

		$result = Form_Handler::$lost_password_result;

		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only decides which of two forms to show, no state change; the actual reset is verified server-side again on submit via check_password_reset_key().
			$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';

			if ( '' !== $key && '' !== $login ) {
				$user = check_password_reset_key( $key, $login );

				$result = is_wp_error( $user )
					? array(
						'state'           => 'invalid_key',
						'errors'          => array(),
						'submitted_email' => '',
					)
					: array(
						'state'           => 'reset',
						'errors'          => array(),
						'submitted_email' => '',
						'key'             => $key,
						'login'           => $login,
					);
			} else {
				$result = array(
					'state'           => 'request',
					'errors'          => array(),
					'submitted_email' => '',
				);
			}
		}

		return self::render_template(
			'lost-password-form',
			array_merge(
				array(
					'key'   => '',
					'login' => '',
				),
				$result
			)
		);
	}

	/**
	 * Translate a `Registration_Service::ERROR_*` code into a message, for
	 * the register-form template. Mirrors `Settings_Page::error_message()`.
	 *
	 * @param string $code One of the `Registration_Service::ERROR_*` constants.
	 * @return string
	 */
	public static function register_error_message( string $code ): string {
		switch ( $code ) {
			case Registration_Service::ERROR_FIRST_NAME_REQUIRED:
				return __( 'Please enter your first name.', 'ruben-dance' );

			case Registration_Service::ERROR_FIRST_NAME_TOO_LONG:
				return __( 'First name is too long.', 'ruben-dance' );

			case Registration_Service::ERROR_LAST_NAME_REQUIRED:
				return __( 'Please enter your last name.', 'ruben-dance' );

			case Registration_Service::ERROR_LAST_NAME_TOO_LONG:
				return __( 'Last name is too long.', 'ruben-dance' );

			case Registration_Service::ERROR_EMAIL_REQUIRED:
				return __( 'Please enter your email address.', 'ruben-dance' );

			case Registration_Service::ERROR_EMAIL_INVALID:
				return __( 'Please enter a valid email address.', 'ruben-dance' );

			case Registration_Service::ERROR_EMAIL_TAKEN:
				return __( 'An account with this email already exists. Try logging in instead.', 'ruben-dance' );

			case Registration_Service::ERROR_PHONE_REQUIRED:
				return __( 'Please enter your phone number.', 'ruben-dance' );

			case Registration_Service::ERROR_PHONE_INVALID:
				return __( 'Please enter a valid phone number.', 'ruben-dance' );

			case Registration_Service::ERROR_PASSWORD_TOO_SHORT:
				return __( 'Password must be at least 8 characters long.', 'ruben-dance' );

			case Registration_Service::ERROR_TC_REQUIRED:
				return __( 'You must agree to the Terms & Conditions to register.', 'ruben-dance' );

			default:
				return __( 'Please check the highlighted fields.', 'ruben-dance' );
		}
	}

	/**
	 * Include a template partial with `$vars` extracted as local variables.
	 *
	 * @param string               $name Template file name, without `.php`, in `public/templates/`.
	 * @param array<string, mixed> $vars Variables made available to the template.
	 * @return string
	 */
	private static function render_template( string $name, array $vars ): string {
		$path = RUBEN_DANCE_PLUGIN_DIR . 'public/templates/' . $name . '.php';

		if ( ! file_exists( $path ) ) {
			return '';
		}

		extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template partials are the standard WP pattern for this (get_template_part()-style); $vars is entirely our own array, never user input.

		ob_start();
		include $path;

		return (string) ob_get_clean();
	}
}
