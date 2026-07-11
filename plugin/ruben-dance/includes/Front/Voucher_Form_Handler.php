<?php
/**
 * Processes `[rd_voucher_inquiry]` form submissions, before any page output
 * is sent (spec F17/§4.6).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Emails\Email_Sender;
use RubenDance\Emails\Email_Templates;
use RubenDance\Lang;
use RubenDance\Services\Rate_Limiter;
use RubenDance\Services\Voucher_Inquiry_Service;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Voucher_Form_Handler.
 *
 * Hooked to `template_redirect`, the same as `Enrollment_Form_Handler`/M07's
 * `Form_Handler`: the only point a redirect can still happen, well before
 * `Voucher_Page::render()` echoes any HTML. Public, anonymous-allowed (no
 * `is_user_logged_in()` gate — a voucher inquiry is a pre-sale question, not
 * an account action), so it leans on the same three layers every other
 * public write endpoint in this plugin does (spec §5): nonce, honeypot +
 * time-trap, per-IP rate limit.
 */
class Voucher_Form_Handler {

	const NONCE_ACTION = 'rd_voucher_inquiry';

	const MAX_ATTEMPTS   = 5;
	const WINDOW_SECONDS = 900; // 15 minutes.

	/**
	 * Submission result for `Voucher_Page::render()`: null (untouched, GET
	 * request), or array{ state: 'success'|'form'|'rate_limited', errors: array<string,string>, submitted: array<string,string> }.
	 *
	 * @var array{state: string, errors: array<string,string>, submitted: array<string,string>}|null
	 */
	public static ?array $result = null;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'handle_request' ) );
	}

	/**
	 * Single entry point.
	 */
	public static function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only; handle_submit() calls wp_verify_nonce() before reading/writing anything.
		if ( ! isset( $_POST['rd_voucher_action'] ) || 'submit' !== $_POST['rd_voucher_action'] ) {
			return;
		}

		self::handle_submit();
	}

	/**
	 * Handle `[rd_voucher_inquiry]` form submission.
	 */
	private static function handle_submit(): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			self::$result = array(
				'state'     => 'form',
				'errors'    => array( '_form' => __( 'Your session expired, please try again.', 'ruben-dance' ) ),
				'submitted' => array(),
			);
			return;
		}

		$submitted = self::sanitized_submission();

		// Bot baseline (spec §5, same as every other public form in this
		// plugin): a filled honeypot or an instantly-submitted form is
		// silently dropped — the visitor sees the same thank-you screen a
		// real inquiry would, but nothing is sent and nothing logged.
		if ( Bot_Guard::is_bot( wp_unslash( $_POST ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- Bot_Guard reads/validates its own signed fields; nonce already checked above.
			self::$result = array(
				'state'     => 'success',
				'errors'    => array(),
				'submitted' => array(),
			);
			return;
		}

		if ( Rate_Limiter::create_default()->too_many_attempts( 'voucher_inquiry', self::client_ip(), self::MAX_ATTEMPTS, self::WINDOW_SECONDS ) ) {
			self::$result = array(
				'state'     => 'rate_limited',
				'errors'    => array(),
				'submitted' => $submitted,
			);
			return;
		}

		$errors = Voucher_Inquiry_Service::validate( $submitted );

		if ( array() !== $errors ) {
			self::$result = array(
				'state'     => 'form',
				'errors'    => $errors,
				'submitted' => $submitted,
			);
			return;
		}

		self::send_admin_notification( $submitted );

		self::$result = array(
			'state'     => 'success',
			'errors'    => array(),
			'submitted' => array(),
		);
	}

	/**
	 * Send E8 (admin notification, always CS — mirrors E3) when an admin
	 * notification address is configured. Silently a no-op otherwise: there
	 * is nobody to notify, and the visitor already gets the same thank-you
	 * screen either way (nothing they did was wrong).
	 *
	 * @param array<string, string> $submitted Validated name/email/message.
	 */
	private static function send_admin_notification( array $submitted ): void {
		$admin_email = Settings::admin_notification_email();

		if ( '' === $admin_email ) {
			return;
		}

		Email_Sender::create_default()->send(
			Email_Templates::TYPE_E8,
			Lang::CS,
			$admin_email,
			array(
				'name'    => $submitted['name'],
				'email'   => $submitted['email'],
				'message' => $submitted['message'],
			),
			null,
			null
		);
	}

	/**
	 * Read and sanitize the fixed set of submitted fields.
	 *
	 * The POST field names are `rd_voucher_*`-prefixed (unlike the enroll/
	 * register forms' bare names, which post to shortcode pages the same
	 * way but happen not to collide) because a bare `name` field is a
	 * WordPress public query variable (the post-slug query var):
	 * `WP::parse_request()` reads public query vars from `$_REQUEST`, so a
	 * POSTed `name=Jana Nováková` would make the main query look for a post
	 * with that slug and turn the whole response into a 404 before this
	 * handler's shortcode ever rendered.
	 *
	 * @return array<string, string>
	 */
	private static function sanitized_submission(): array {
		return array(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
			'name'    => isset( $_POST['rd_voucher_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rd_voucher_name'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
			'email'   => isset( $_POST['rd_voucher_email'] ) ? sanitize_text_field( wp_unslash( $_POST['rd_voucher_email'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this runs.
			'message' => isset( $_POST['rd_voucher_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rd_voucher_message'] ) ) : '',
		);
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
