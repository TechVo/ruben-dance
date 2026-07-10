<?php
/**
 * Tests for profile validation and the update/password/consent orchestration.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use RubenDance\Services\Profile_Service;
use RubenDance\Services\Registration_Service;

/**
 * Class ProfileServiceTest.
 *
 * `Profile_Service` is deliberately WordPress-agnostic (every user/meta/
 * clock touchpoint is an injected callable, mirroring `Registration_Service`),
 * so validation and the update orchestration are exercised here with plain
 * PHPUnit and in-memory fakes, no WordPress bootstrap needed.
 */
class ProfileServiceTest extends TestCase {

	/**
	 * Build a service wired to simple in-memory fakes.
	 *
	 * @return array{0: Profile_Service, 1: ArrayObject, 2: ArrayObject} [service, users store, user_meta store].
	 */
	private function make_service(): array {
		$users = new ArrayObject( array() );
		$meta  = new ArrayObject( array() );

		$service = new Profile_Service(
			static function ( int $user_id, array $data ) use ( $users ): void {
				$row                = $users[ $user_id ] ?? array();
				$users[ $user_id ]  = array_merge( $row, $data );
			},
			static function ( int $user_id, string $key, string $value ) use ( $meta ): void {
				$row              = $meta[ $user_id ] ?? array();
				$row[ $key ]      = $value;
				$meta[ $user_id ] = $row;
			},
			static function ( int $user_id, string $password ) use ( $users ): void {
				$row               = $users[ $user_id ] ?? array();
				$row['password']   = $password;
				$users[ $user_id ] = $row;
			},
			static function (): string {
				return '2025-01-01 10:00:00';
			}
		);

		return array( $service, $users, $meta );
	}

	/**
	 * A completely valid submission produces no errors.
	 */
	public function test_validate_profile_accepts_valid_data(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_profile(
			array(
				'first_name' => 'Jana',
				'last_name'  => 'Nováková',
				'phone'      => '+420 601 111 222',
				'locale'     => 'cs',
			)
		);

		$this->assertSame( array(), $errors );
	}

	/**
	 * Every required field is checked, including the locale whitelist.
	 */
	public function test_validate_profile_rejects_missing_or_invalid_fields(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_profile(
			array(
				'first_name' => '',
				'last_name'  => '',
				'phone'      => '',
				'locale'     => 'xx',
			)
		);

		$this->assertSame( Profile_Service::ERROR_FIRST_NAME_REQUIRED, $errors['first_name'] );
		$this->assertSame( Profile_Service::ERROR_LAST_NAME_REQUIRED, $errors['last_name'] );
		$this->assertSame( Profile_Service::ERROR_PHONE_REQUIRED, $errors['phone'] );
		$this->assertSame( Profile_Service::ERROR_LOCALE_INVALID, $errors['locale'] );
	}

	/**
	 * A malformed phone number is rejected distinctly from a missing one.
	 */
	public function test_validate_profile_rejects_malformed_phone(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_profile(
			array(
				'first_name' => 'Jana',
				'last_name'  => 'Nováková',
				'phone'      => 'not a phone number!!',
				'locale'     => 'cs',
			)
		);

		$this->assertSame( Profile_Service::ERROR_PHONE_INVALID, $errors['phone'] );
	}

	/**
	 * `update_profile()` writes both the WP user fields and the phone/locale
	 * meta.
	 */
	public function test_update_profile_writes_user_and_meta_fields(): void {
		list( $service, $users, $meta ) = $this->make_service();

		$service->update_profile(
			5,
			array(
				'first_name' => 'Jana',
				'last_name'  => 'Nováková',
				'phone'      => '+420 601 111 222',
				'locale'     => 'en',
			)
		);

		$this->assertSame( 'Jana', $users[5]['first_name'] );
		$this->assertSame( 'Nováková', $users[5]['last_name'] );
		$this->assertSame( '+420 601 111 222', $meta[5][ Registration_Service::META_PHONE ] );
		$this->assertSame( 'en', $meta[5][ Registration_Service::META_LOCALE ] );
	}

	/**
	 * A short password is rejected.
	 */
	public function test_validate_password_requires_minimum_length(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_password(
			array(
				'new_password'         => 'short',
				'new_password_confirm' => 'short',
			)
		);

		$this->assertSame( Profile_Service::ERROR_PASSWORD_TOO_SHORT, $errors['new_password'] );
	}

	/**
	 * A mismatched confirmation is rejected distinctly from "too short".
	 */
	public function test_validate_password_requires_matching_confirmation(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_password(
			array(
				'new_password'         => 'correct-horse',
				'new_password_confirm' => 'different-horse',
			)
		);

		$this->assertSame( Profile_Service::ERROR_PASSWORD_MISMATCH, $errors['new_password_confirm'] );
	}

	/**
	 * A valid, matching password produces no errors.
	 */
	public function test_validate_password_accepts_a_valid_matching_pair(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_password(
			array(
				'new_password'         => 'correct-horse-battery',
				'new_password_confirm' => 'correct-horse-battery',
			)
		);

		$this->assertSame( array(), $errors );
	}

	/**
	 * `update_password()` delegates to the injected `set_password` callable.
	 */
	public function test_update_password_delegates_to_set_password(): void {
		list( $service, $users ) = $this->make_service();

		$service->update_password( 5, 'correct-horse-battery' );

		$this->assertSame( 'correct-horse-battery', $users[5]['password'] );
	}

	/**
	 * Toggling consent on writes both the flag and a timestamp.
	 */
	public function test_toggle_marketing_consent_on_writes_flag_and_timestamp(): void {
		list( $service, , $meta ) = $this->make_service();

		$service->toggle_marketing_consent( 5, true );

		$this->assertSame( '1', $meta[5][ Registration_Service::META_MARKETING_CONSENT ] );
		$this->assertSame( '2025-01-01 10:00:00', $meta[5][ Registration_Service::META_MARKETING_CONSENT_AT ] );
	}

	/**
	 * Toggling consent off *also* updates the timestamp — the acceptance
	 * criterion is "updates the stored consent + timestamp", on either
	 * direction, per spec §6.1 (consent changes must stay traceable).
	 */
	public function test_toggle_marketing_consent_off_also_writes_timestamp(): void {
		list( $service, , $meta ) = $this->make_service();

		$service->toggle_marketing_consent( 5, false );

		$this->assertSame( '0', $meta[5][ Registration_Service::META_MARKETING_CONSENT ] );
		$this->assertSame( '2025-01-01 10:00:00', $meta[5][ Registration_Service::META_MARKETING_CONSENT_AT ] );
	}
}
