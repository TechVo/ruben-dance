<?php
/**
 * Plugin settings stored as `wp_options`: due-date days, admin notification email.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance;

use RubenDance\Services\Iban_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Settings.
 *
 * Thin wrapper around `get_option()`/`update_option()`, the same shape as
 * `Roles`: two plugin-wide settings the services need (spec §3.2:
 * `due_date` = `created_at` + N days, N "configurable, default 7"; the admin
 * notification email address, used by later email milestones). `validate()`
 * is kept pure (no `get_option()`/`update_option()` call inside it) so it is
 * unit-testable with plain PHPUnit, the same way `Location_Service::validate()`
 * is.
 */
class Settings {

	/**
	 * Option name for the payment due-date window, in days.
	 *
	 * @var string
	 */
	const OPTION_DUE_DATE_DAYS = 'rd_due_date_days';

	/**
	 * Option name for the admin notification email address.
	 *
	 * @var string
	 */
	const OPTION_ADMIN_NOTIFICATION_EMAIL = 'rd_admin_notification_email';

	/**
	 * Option name for the bank account number quoted in payment instructions
	 * (spec F3 step 4/§4.5: "account number" alongside amount, variable
	 * symbol, due date). Added by M08, which is the first milestone that
	 * actually needs it (the enrollment confirmation page/email).
	 *
	 * @var string
	 */
	const OPTION_BANK_ACCOUNT = 'rd_bank_account';

	/**
	 * Option name for the school's IBAN, used only to build the QR-platba
	 * (SPAYD) code (spec F16/§4.5): the "Bank account number" field above is
	 * the human-readable text customers read; this is the machine-readable
	 * form the QR code itself encodes. Deliberately a separate option — a
	 * site can quote a non-IBAN Czech account number for humans while the QR
	 * feature stays off until this is filled in (see `iban()`'s "unset ⇒ no
	 * QR" contract, checked by every QR call site).
	 *
	 * @var string
	 */
	const OPTION_IBAN = 'rd_iban';

	/**
	 * Option name for how the `[rd_calendar]` shortcode (M10) displays a
	 * cancelled lesson: `self::CANCELLED_LESSONS_STRIKETHROUGH` (shown, struck
	 * through) or `self::CANCELLED_LESSONS_HIDDEN` (omitted entirely). Spec
	 * F2: "Cancelled lessons shown struck-through or hidden (admin choice)."
	 *
	 * @var string
	 */
	const OPTION_CANCELLED_LESSONS_DISPLAY = 'rd_cancelled_lessons_display';

	/**
	 * Default due-date window (spec §3.2: "default 7").
	 *
	 * @var int
	 */
	const DEFAULT_DUE_DATE_DAYS = 7;

	/**
	 * `OPTION_CANCELLED_LESSONS_DISPLAY` value: cancelled lessons stay on the
	 * calendar, struck through. The spec-mandated default.
	 *
	 * @var string
	 */
	const CANCELLED_LESSONS_STRIKETHROUGH = 'strikethrough';

	/**
	 * `OPTION_CANCELLED_LESSONS_DISPLAY` value: cancelled lessons are omitted
	 * from the calendar entirely.
	 *
	 * @var string
	 */
	const CANCELLED_LESSONS_HIDDEN = 'hidden';

	/**
	 * Every valid `OPTION_CANCELLED_LESSONS_DISPLAY` value.
	 *
	 * @var string[]
	 */
	const CANCELLED_LESSONS_DISPLAY_OPTIONS = array( self::CANCELLED_LESSONS_STRIKETHROUGH, self::CANCELLED_LESSONS_HIDDEN );

	/**
	 * Max length for the bank account field. Generous enough for a Czech
	 * account number with bank code (`######-##########/####`) or a full
	 * IBAN (34 characters max per ISO 13616), without pinning the format —
	 * the owners may quote either, and validating the format itself is a
	 * bank-integration concern out of this milestone's scope.
	 *
	 * @var int
	 */
	const BANK_ACCOUNT_MAX_LENGTH = 50;

	const ERROR_DUE_DATE_DAYS_INVALID             = 'due_date_days_invalid';
	const ERROR_ADMIN_EMAIL_INVALID               = 'admin_email_invalid';
	const ERROR_BANK_ACCOUNT_TOO_LONG             = 'bank_account_too_long';
	const ERROR_CANCELLED_LESSONS_DISPLAY_INVALID = 'cancelled_lessons_display_invalid';
	const ERROR_IBAN_INVALID                      = 'iban_invalid';

	/**
	 * The configured due-date window in days, falling back to
	 * `DEFAULT_DUE_DATE_DAYS` when unset or invalid.
	 *
	 * @return int
	 */
	public static function due_date_days(): int {
		$days = (int) get_option( self::OPTION_DUE_DATE_DAYS, self::DEFAULT_DUE_DATE_DAYS );

		return $days > 0 ? $days : self::DEFAULT_DUE_DATE_DAYS;
	}

	/**
	 * The configured admin notification email address, or `''` when unset.
	 *
	 * @return string
	 */
	public static function admin_notification_email(): string {
		return (string) get_option( self::OPTION_ADMIN_NOTIFICATION_EMAIL, '' );
	}

	/**
	 * The configured bank account number for payment instructions, or `''`
	 * when unset.
	 *
	 * @return string
	 */
	public static function bank_account(): string {
		return (string) get_option( self::OPTION_BANK_ACCOUNT, '' );
	}

	/**
	 * The configured IBAN for the QR-platba code, already normalized (no
	 * spaces, upper-case — see `save()`), or `''` when unset. Every QR call
	 * site (`Emails\Payment_Qr_Email`, `Front\Qr_Code_Ajax`) treats `''` as
	 * "feature disabled" (spec F16 acceptance criterion: "No IBAN configured
	 * → no QR anywhere, no errors, text instructions intact").
	 *
	 * @return string
	 */
	public static function iban(): string {
		return (string) get_option( self::OPTION_IBAN, '' );
	}

	/**
	 * The configured cancelled-lessons display mode for `[rd_calendar]`,
	 * falling back to `self::CANCELLED_LESSONS_STRIKETHROUGH` when unset or
	 * invalid (spec F2: "struck-through or hidden (admin choice)", default
	 * struck-through).
	 *
	 * @return string One of `self::CANCELLED_LESSONS_DISPLAY_OPTIONS`.
	 */
	public static function cancelled_lessons_display(): string {
		$value = (string) get_option( self::OPTION_CANCELLED_LESSONS_DISPLAY, self::CANCELLED_LESSONS_STRIKETHROUGH );

		return in_array( $value, self::CANCELLED_LESSONS_DISPLAY_OPTIONS, true ) ? $value : self::CANCELLED_LESSONS_STRIKETHROUGH;
	}

	/**
	 * Validate submitted field values.
	 *
	 * @param array<string, mixed> $data Raw (unslashed) field values: due_date_days, admin_notification_email, bank_account, iban, cancelled_lessons_display.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public static function validate( array $data ): array {
		$errors = array();

		$due_date_days             = trim( (string) ( $data['due_date_days'] ?? '' ) );
		$admin_email               = trim( (string) ( $data['admin_notification_email'] ?? '' ) );
		$bank_account              = trim( (string) ( $data['bank_account'] ?? '' ) );
		$iban                      = trim( (string) ( $data['iban'] ?? '' ) );
		$cancelled_lessons_display = trim( (string) ( $data['cancelled_lessons_display'] ?? '' ) );

		if ( '' === $due_date_days || ! ctype_digit( $due_date_days ) || (int) $due_date_days <= 0 ) {
			$errors['due_date_days'] = self::ERROR_DUE_DATE_DAYS_INVALID;
		}

		if ( '' !== $admin_email && false === filter_var( $admin_email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['admin_notification_email'] = self::ERROR_ADMIN_EMAIL_INVALID;
		}

		if ( strlen( $bank_account ) > self::BANK_ACCOUNT_MAX_LENGTH ) {
			$errors['bank_account'] = self::ERROR_BANK_ACCOUNT_TOO_LONG;
		}

		if ( '' !== $iban && ! Iban_Validator::is_valid( $iban ) ) {
			$errors['iban'] = self::ERROR_IBAN_INVALID;
		}

		if ( '' !== $cancelled_lessons_display && ! in_array( $cancelled_lessons_display, self::CANCELLED_LESSONS_DISPLAY_OPTIONS, true ) ) {
			$errors['cancelled_lessons_display'] = self::ERROR_CANCELLED_LESSONS_DISPLAY_INVALID;
		}

		return $errors;
	}

	/**
	 * Save submitted field values. Caller must call `validate()` first and
	 * only proceed when it returns an empty array.
	 *
	 * @param array<string, mixed> $data Field values: due_date_days, admin_notification_email, bank_account, iban, cancelled_lessons_display.
	 */
	public static function save( array $data ): void {
		update_option( self::OPTION_DUE_DATE_DAYS, (int) ( $data['due_date_days'] ?? self::DEFAULT_DUE_DATE_DAYS ) );
		update_option( self::OPTION_ADMIN_NOTIFICATION_EMAIL, trim( (string) ( $data['admin_notification_email'] ?? '' ) ) );
		update_option( self::OPTION_BANK_ACCOUNT, trim( (string) ( $data['bank_account'] ?? '' ) ) );
		// Stored already normalized (no spaces, upper-case) — every reader
		// (`iban()`, `Spayd_Builder::normalize_iban()` again defensively)
		// then never has to reformat a human-pasted "CZ65 0800 ..." value.
		update_option( self::OPTION_IBAN, Iban_Validator::normalize( trim( (string) ( $data['iban'] ?? '' ) ) ) );
		update_option( self::OPTION_CANCELLED_LESSONS_DISPLAY, trim( (string) ( $data['cancelled_lessons_display'] ?? self::CANCELLED_LESSONS_STRIKETHROUGH ) ) );
	}
}
