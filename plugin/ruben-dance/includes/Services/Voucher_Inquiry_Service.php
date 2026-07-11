<?php
/**
 * Validation for the voucher inquiry form (spec F17/§4.6).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Voucher_Inquiry_Service.
 *
 * Kept WordPress-agnostic, mirroring `Settings::validate()`: no `get_option()`/
 * `wp_mail()` call inside `validate()` itself, so the field rules are
 * unit-testable with plain PHPUnit. `Front\Voucher_Form_Handler` is the only
 * caller; it owns the bot/rate-limit checks and the actual send via
 * `Emails\Email_Sender`.
 */
class Voucher_Inquiry_Service {

	const NAME_MAX_LENGTH    = 190;
	const MESSAGE_MAX_LENGTH = 2000;

	const ERROR_NAME_REQUIRED    = 'name_required';
	const ERROR_NAME_TOO_LONG    = 'name_too_long';
	const ERROR_EMAIL_REQUIRED   = 'email_required';
	const ERROR_EMAIL_INVALID    = 'email_invalid';
	const ERROR_MESSAGE_REQUIRED = 'message_required';
	const ERROR_MESSAGE_TOO_LONG = 'message_too_long';

	/**
	 * Validate submitted field values.
	 *
	 * @param array<string, mixed> $data Raw (already `sanitize_text_field()`/
	 *                                    `sanitize_textarea_field()`'d) values: name, email, message.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public static function validate( array $data ): array {
		$errors = array();

		$name    = trim( (string) ( $data['name'] ?? '' ) );
		$email   = trim( (string) ( $data['email'] ?? '' ) );
		$message = trim( (string) ( $data['message'] ?? '' ) );

		if ( '' === $name ) {
			$errors['name'] = self::ERROR_NAME_REQUIRED;
		} elseif ( strlen( $name ) > self::NAME_MAX_LENGTH ) {
			$errors['name'] = self::ERROR_NAME_TOO_LONG;
		}

		if ( '' === $email ) {
			$errors['email'] = self::ERROR_EMAIL_REQUIRED;
		} elseif ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['email'] = self::ERROR_EMAIL_INVALID;
		}

		if ( '' === $message ) {
			$errors['message'] = self::ERROR_MESSAGE_REQUIRED;
		} elseif ( strlen( $message ) > self::MESSAGE_MAX_LENGTH ) {
			$errors['message'] = self::ERROR_MESSAGE_TOO_LONG;
		}

		return $errors;
	}
}
