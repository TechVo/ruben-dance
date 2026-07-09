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
}
