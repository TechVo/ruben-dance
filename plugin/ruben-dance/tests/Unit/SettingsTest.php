<?php
/**
 * Tests for settings field validation.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Settings;

/**
 * Class SettingsTest.
 *
 * `Settings::validate()` is deliberately kept free of `get_option()`/
 * `update_option()` calls (unlike `due_date_days()`/`admin_notification_email()`,
 * which need WordPress), so it is exercised here with plain PHPUnit, no
 * WordPress bootstrap needed.
 */
class SettingsTest extends TestCase {

	/**
	 * A fully valid submission produces no errors.
	 */
	public function test_validate_accepts_valid_data(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'            => '7',
				'admin_notification_email' => 'owner@ruben-dance.cz',
			)
		);

		$this->assertSame( array(), $errors );
	}

	/**
	 * A blank admin email is allowed (optional field).
	 */
	public function test_validate_allows_blank_admin_email(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'            => '7',
				'admin_notification_email' => '',
			)
		);

		$this->assertArrayNotHasKey( 'admin_notification_email', $errors );
	}

	/**
	 * A blank due_date_days is rejected — unlike the email, it is required.
	 */
	public function test_validate_rejects_blank_due_date_days(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'            => '',
				'admin_notification_email' => '',
			)
		);

		$this->assertSame( Settings::ERROR_DUE_DATE_DAYS_INVALID, $errors['due_date_days'] );
	}

	/**
	 * Zero and negative values are rejected.
	 */
	public function test_validate_rejects_non_positive_due_date_days(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'            => '0',
				'admin_notification_email' => '',
			)
		);

		$this->assertSame( Settings::ERROR_DUE_DATE_DAYS_INVALID, $errors['due_date_days'] );
	}

	/**
	 * A non-numeric value is rejected.
	 */
	public function test_validate_rejects_non_numeric_due_date_days(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'            => 'abc',
				'admin_notification_email' => '',
			)
		);

		$this->assertSame( Settings::ERROR_DUE_DATE_DAYS_INVALID, $errors['due_date_days'] );
	}

	/**
	 * A malformed email is rejected.
	 */
	public function test_validate_rejects_malformed_admin_email(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'            => '7',
				'admin_notification_email' => 'not-an-email',
			)
		);

		$this->assertSame( Settings::ERROR_ADMIN_EMAIL_INVALID, $errors['admin_notification_email'] );
	}

	/**
	 * A blank bank account is allowed (optional field).
	 */
	public function test_validate_allows_blank_bank_account(): void {
		$errors = Settings::validate(
			array(
				'due_date_days' => '7',
				'bank_account'  => '',
			)
		);

		$this->assertArrayNotHasKey( 'bank_account', $errors );
	}

	/**
	 * A bank account within the length limit is accepted.
	 */
	public function test_validate_accepts_bank_account_within_limit(): void {
		$errors = Settings::validate(
			array(
				'due_date_days' => '7',
				'bank_account'  => '123456789/0800',
			)
		);

		$this->assertArrayNotHasKey( 'bank_account', $errors );
	}

	/**
	 * A bank account longer than the limit is rejected.
	 */
	public function test_validate_rejects_overlong_bank_account(): void {
		$errors = Settings::validate(
			array(
				'due_date_days' => '7',
				'bank_account'  => str_repeat( '1', Settings::BANK_ACCOUNT_MAX_LENGTH + 1 ),
			)
		);

		$this->assertSame( Settings::ERROR_BANK_ACCOUNT_TOO_LONG, $errors['bank_account'] );
	}

	/**
	 * A blank cancelled-lessons display is allowed (optional field; `save()`
	 * falls back to the strikethrough default).
	 */
	public function test_validate_allows_blank_cancelled_lessons_display(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'             => '7',
				'cancelled_lessons_display' => '',
			)
		);

		$this->assertArrayNotHasKey( 'cancelled_lessons_display', $errors );
	}

	/**
	 * Both spec-mandated display modes are accepted.
	 */
	public function test_validate_accepts_known_cancelled_lessons_display_values(): void {
		foreach ( Settings::CANCELLED_LESSONS_DISPLAY_OPTIONS as $value ) {
			$errors = Settings::validate(
				array(
					'due_date_days'             => '7',
					'cancelled_lessons_display' => $value,
				)
			);

			$this->assertArrayNotHasKey( 'cancelled_lessons_display', $errors );
		}
	}

	/**
	 * An unknown cancelled-lessons display value is rejected.
	 */
	public function test_validate_rejects_unknown_cancelled_lessons_display(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'             => '7',
				'cancelled_lessons_display' => 'delete-the-database',
			)
		);

		$this->assertSame( Settings::ERROR_CANCELLED_LESSONS_DISPLAY_INVALID, $errors['cancelled_lessons_display'] );
	}

	/**
	 * A blank retention window is allowed (optional field; `save()` falls
	 * back to `DEFAULT_RETENTION_YEARS`).
	 */
	public function test_validate_allows_blank_retention_years(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'   => '7',
				'retention_years' => '',
			)
		);

		$this->assertArrayNotHasKey( 'retention_years', $errors );
	}

	/**
	 * A positive whole number of years is accepted.
	 */
	public function test_validate_accepts_positive_retention_years(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'   => '7',
				'retention_years' => '3',
			)
		);

		$this->assertArrayNotHasKey( 'retention_years', $errors );
	}

	/**
	 * Zero and non-numeric retention windows are rejected.
	 */
	public function test_validate_rejects_non_positive_retention_years(): void {
		$errors = Settings::validate(
			array(
				'due_date_days'   => '7',
				'retention_years' => '0',
			)
		);

		$this->assertSame( Settings::ERROR_RETENTION_YEARS_INVALID, $errors['retention_years'] );
	}
}
