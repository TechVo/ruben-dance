<?php
/**
 * Tests for the voucher inquiry form field validation.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Services\Voucher_Inquiry_Service;

/**
 * Class VoucherInquiryServiceTest.
 *
 * `Voucher_Inquiry_Service::validate()` is deliberately kept free of any
 * WordPress touchpoint (mirrors `Settings::validate()`), so it is exercised
 * here with plain PHPUnit, no WordPress bootstrap needed.
 */
class VoucherInquiryServiceTest extends TestCase {

	/**
	 * A fully valid submission produces no errors.
	 */
	public function test_validate_accepts_valid_data(): void {
		$errors = Voucher_Inquiry_Service::validate(
			array(
				'name'    => 'Jana Nováková',
				'email'   => 'jana.novakova@example.com',
				'message' => 'Chtěla bych poukaz na kurz salsy pro dvě osoby.',
			)
		);

		$this->assertSame( array(), $errors );
	}

	/**
	 * A blank name is rejected.
	 */
	public function test_validate_rejects_blank_name(): void {
		$errors = Voucher_Inquiry_Service::validate(
			array(
				'name'    => '',
				'email'   => 'jana.novakova@example.com',
				'message' => 'Hello',
			)
		);

		$this->assertSame( Voucher_Inquiry_Service::ERROR_NAME_REQUIRED, $errors['name'] );
	}

	/**
	 * A name longer than the limit is rejected.
	 */
	public function test_validate_rejects_overlong_name(): void {
		$errors = Voucher_Inquiry_Service::validate(
			array(
				'name'    => str_repeat( 'a', Voucher_Inquiry_Service::NAME_MAX_LENGTH + 1 ),
				'email'   => 'jana.novakova@example.com',
				'message' => 'Hello',
			)
		);

		$this->assertSame( Voucher_Inquiry_Service::ERROR_NAME_TOO_LONG, $errors['name'] );
	}

	/**
	 * A blank email is rejected.
	 */
	public function test_validate_rejects_blank_email(): void {
		$errors = Voucher_Inquiry_Service::validate(
			array(
				'name'    => 'Jana Nováková',
				'email'   => '',
				'message' => 'Hello',
			)
		);

		$this->assertSame( Voucher_Inquiry_Service::ERROR_EMAIL_REQUIRED, $errors['email'] );
	}

	/**
	 * A malformed email is rejected.
	 */
	public function test_validate_rejects_malformed_email(): void {
		$errors = Voucher_Inquiry_Service::validate(
			array(
				'name'    => 'Jana Nováková',
				'email'   => 'not-an-email',
				'message' => 'Hello',
			)
		);

		$this->assertSame( Voucher_Inquiry_Service::ERROR_EMAIL_INVALID, $errors['email'] );
	}

	/**
	 * A blank message is rejected.
	 */
	public function test_validate_rejects_blank_message(): void {
		$errors = Voucher_Inquiry_Service::validate(
			array(
				'name'    => 'Jana Nováková',
				'email'   => 'jana.novakova@example.com',
				'message' => '   ',
			)
		);

		$this->assertSame( Voucher_Inquiry_Service::ERROR_MESSAGE_REQUIRED, $errors['message'] );
	}

	/**
	 * A message longer than the limit is rejected.
	 */
	public function test_validate_rejects_overlong_message(): void {
		$errors = Voucher_Inquiry_Service::validate(
			array(
				'name'    => 'Jana Nováková',
				'email'   => 'jana.novakova@example.com',
				'message' => str_repeat( 'a', Voucher_Inquiry_Service::MESSAGE_MAX_LENGTH + 1 ),
			)
		);

		$this->assertSame( Voucher_Inquiry_Service::ERROR_MESSAGE_TOO_LONG, $errors['message'] );
	}
}
