<?php
/**
 * Plugin settings stored as `wp_options`: due-date days, admin notification email.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance;

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
	 * Default due-date window (spec §3.2: "default 7").
	 *
	 * @var int
	 */
	const DEFAULT_DUE_DATE_DAYS = 7;

	const ERROR_DUE_DATE_DAYS_INVALID = 'due_date_days_invalid';
	const ERROR_ADMIN_EMAIL_INVALID   = 'admin_email_invalid';

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
	 * Validate submitted field values.
	 *
	 * @param array<string, mixed> $data Raw (unslashed) field values: due_date_days, admin_notification_email.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public static function validate( array $data ): array {
		$errors = array();

		$due_date_days = trim( (string) ( $data['due_date_days'] ?? '' ) );
		$admin_email   = trim( (string) ( $data['admin_notification_email'] ?? '' ) );

		if ( '' === $due_date_days || ! ctype_digit( $due_date_days ) || (int) $due_date_days <= 0 ) {
			$errors['due_date_days'] = self::ERROR_DUE_DATE_DAYS_INVALID;
		}

		if ( '' !== $admin_email && false === filter_var( $admin_email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['admin_notification_email'] = self::ERROR_ADMIN_EMAIL_INVALID;
		}

		return $errors;
	}

	/**
	 * Save submitted field values. Caller must call `validate()` first and
	 * only proceed when it returns an empty array.
	 *
	 * @param array<string, mixed> $data Field values: due_date_days, admin_notification_email.
	 */
	public static function save( array $data ): void {
		update_option( self::OPTION_DUE_DATE_DAYS, (int) ( $data['due_date_days'] ?? self::DEFAULT_DUE_DATE_DAYS ) );
		update_option( self::OPTION_ADMIN_NOTIFICATION_EMAIL, trim( (string) ( $data['admin_notification_email'] ?? '' ) ) );
	}
}
