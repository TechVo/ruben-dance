<?php
/**
 * Tests for registration validation, account-creation orchestration and the
 * verification-token lifecycle (single-use, expiring).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use RubenDance\Services\Mailer;
use RubenDance\Services\Registration_Service;

/**
 * Class RegistrationServiceTest.
 *
 * `Registration_Service` is deliberately WordPress-agnostic (every database/
 * clock/token touchpoint is an injected callable, mirroring
 * `Enrollment_Service`), so validation, account-creation orchestration and —
 * the highest-risk part — the single-use/expiring verification token are
 * exercised here with plain PHPUnit and in-memory fakes, no WordPress
 * bootstrap needed.
 */
class RegistrationServiceTest extends TestCase {

	/**
	 * A minimal in-memory `Mailer` fake that records every send.
	 *
	 * @return Mailer
	 */
	private function fake_mailer(): Mailer {
		return new class() implements Mailer {
			/**
			 * Recorded sends.
			 *
			 * @var array<int, array{to: string, subject: string, body: string}>
			 */
			public array $sent = array();

			/**
			 * {@inheritDoc}
			 *
			 * @param string $to      Recipient.
			 * @param string $subject Subject.
			 * @param string $body    Body.
			 * @return bool
			 */
			public function send( string $to, string $subject, string $body ): bool {
				$this->sent[] = array(
					'to'      => $to,
					'subject' => $subject,
					'body'    => $body,
				);

				return true;
			}
		};
	}

	/**
	 * Build a service wired to simple in-memory fakes. The returned "clock"
	 * `ArrayObject` (key `now`) can be mutated by the caller *between* calls
	 * to simulate time passing (see the token-expiry test), because the
	 * `now` callable reads it fresh every time rather than capturing a value.
	 *
	 * @param array<string, mixed> $options {
	 *     @type bool   $email_exists Value the fake `email_exists()` returns.
	 *     @type int    $now          Initial "current" Unix timestamp.
	 *     @type Mailer $mailer       Mailer fake (defaults to `fake_mailer()`).
	 * }
	 * @return array{0: Registration_Service, 1: ArrayObject, 2: Mailer, 3: ArrayObject} [service, user_meta store, mailer, clock].
	 */
	private function make_service( array $options = array() ): array {
		$email_exists = $options['email_exists'] ?? false;
		$mailer       = $options['mailer'] ?? $this->fake_mailer();

		$meta    = new ArrayObject( array() );
		$clock   = new ArrayObject( array( 'now' => $options['now'] ?? 1700000000 ) );
		$next_id = new ArrayObject( array( 'id' => 100 ) );

		$service = new Registration_Service(
			static function ( string $email ) use ( $email_exists ): bool {
				unset( $email );

				return $email_exists;
			},
			static function ( array $data ) use ( $meta, $next_id ): int {
				unset( $data );
				$id            = $next_id['id'];
				$next_id['id'] = $id + 1;
				$meta[ $id ]   = array();

				return $id;
			},
			static function ( int $user_id, string $key, string $value ) use ( $meta ): void {
				$row              = $meta[ $user_id ] ?? array();
				$row[ $key ]      = $value;
				$meta[ $user_id ] = $row;
			},
			static function ( int $user_id, string $key ) use ( $meta ): string {
				return (string) ( $meta[ $user_id ][ $key ] ?? '' );
			},
			static function ( int $user_id, string $key ) use ( $meta ): void {
				$row = $meta[ $user_id ] ?? array();
				unset( $row[ $key ] );
				$meta[ $user_id ] = $row;
			},
			static function (): string {
				return 'fixed-token';
			},
			static function () use ( $clock ): int {
				return $clock['now'];
			},
			static function ( int $user_id, string $token, string $locale ): string {
				return "https://example.test/verify?uid={$user_id}&token={$token}&locale={$locale}";
			},
			static function ( string $locale, string $link ): array {
				return array(
					'subject' => "verify ({$locale})",
					'body'    => "Click: {$link}",
				);
			},
			$mailer
		);

		return array( $service, $meta, $mailer, $clock );
	}

	/**
	 * A valid registration submission, as a baseline every test tweaks from.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_data(): array {
		return array(
			'first_name'        => 'Jana',
			'last_name'         => 'Nováková',
			'email'             => 'jana@example.com',
			'phone'             => '+420 601 111 222',
			'password'          => 'correct-horse',
			'tc_accepted'       => '1',
			'marketing_consent' => '',
			'locale'            => 'cs',
		);
	}

	/**
	 * A completely valid submission produces no errors.
	 */
	public function test_validate_accepts_valid_data(): void {
		list( $service ) = $this->make_service();

		$this->assertSame( array(), $service->validate( $this->valid_data() ) );
	}

	/**
	 * Every required field is checked; T&C is required, marketing consent is not.
	 */
	public function test_validate_rejects_missing_required_fields(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate(
			array(
				'first_name'  => '',
				'last_name'   => '',
				'email'       => '',
				'phone'       => '',
				'password'    => '',
				'tc_accepted' => '',
			)
		);

		$this->assertSame( Registration_Service::ERROR_FIRST_NAME_REQUIRED, $errors['first_name'] );
		$this->assertSame( Registration_Service::ERROR_LAST_NAME_REQUIRED, $errors['last_name'] );
		$this->assertSame( Registration_Service::ERROR_EMAIL_REQUIRED, $errors['email'] );
		$this->assertSame( Registration_Service::ERROR_PHONE_REQUIRED, $errors['phone'] );
		$this->assertSame( Registration_Service::ERROR_PASSWORD_TOO_SHORT, $errors['password'] );
		$this->assertSame( Registration_Service::ERROR_TC_REQUIRED, $errors['tc_accepted'] );
	}

	/**
	 * An already-registered email is rejected distinctly from a malformed one.
	 */
	public function test_validate_rejects_taken_email(): void {
		list( $service ) = $this->make_service( array( 'email_exists' => true ) );

		$errors = $service->validate( $this->valid_data() );

		$this->assertSame( Registration_Service::ERROR_EMAIL_TAKEN, $errors['email'] );
	}

	/**
	 * A malformed email is rejected before the (fake) "already exists" check
	 * even runs.
	 */
	public function test_validate_rejects_malformed_email(): void {
		list( $service ) = $this->make_service( array( 'email_exists' => true ) );

		$data          = $this->valid_data();
		$data['email'] = 'not-an-email';

		$errors = $service->validate( $data );

		$this->assertSame( Registration_Service::ERROR_EMAIL_INVALID, $errors['email'] );
	}

	/**
	 * `register()` stores phone, locale, T&C timestamp and marketing consent
	 * (unset, since the submission left it unchecked) and sends exactly one
	 * verification email.
	 */
	public function test_register_stores_meta_and_sends_verification_email(): void {
		list( $service, $meta, $mailer ) = $this->make_service();

		$user_id = $service->register( $this->valid_data() );

		$this->assertSame( '+420 601 111 222', $meta[ $user_id ][ Registration_Service::META_PHONE ] );
		$this->assertSame( 'cs', $meta[ $user_id ][ Registration_Service::META_LOCALE ] );
		$this->assertSame( '0', $meta[ $user_id ][ Registration_Service::META_EMAIL_VERIFIED ] );
		$this->assertSame( '0', $meta[ $user_id ][ Registration_Service::META_MARKETING_CONSENT ] );
		$this->assertArrayNotHasKey( Registration_Service::META_MARKETING_CONSENT_AT, $meta[ $user_id ] );
		$this->assertNotSame( '', $meta[ $user_id ][ Registration_Service::META_TC_ACCEPTED_AT ] );

		$this->assertCount( 1, $mailer->sent );
		$this->assertSame( 'jana@example.com', $mailer->sent[0]['to'] );
		$this->assertStringContainsString( "uid={$user_id}", $mailer->sent[0]['body'] );
	}

	/**
	 * Marketing consent, when checked, is stored together with a timestamp
	 * (spec §6.1: consent must be recorded with a timestamp).
	 */
	public function test_register_stores_marketing_consent_timestamp_when_given(): void {
		list( $service, $meta ) = $this->make_service();

		$data                      = $this->valid_data();
		$data['marketing_consent'] = '1';

		$user_id = $service->register( $data );

		$this->assertSame( '1', $meta[ $user_id ][ Registration_Service::META_MARKETING_CONSENT ] );
		$this->assertNotSame( '', $meta[ $user_id ][ Registration_Service::META_MARKETING_CONSENT_AT ] );
	}

	/**
	 * `register_pre_verified()` (used by `wp rd seed`) never sends an email
	 * and marks the account verified immediately.
	 */
	public function test_register_pre_verified_skips_email_and_marks_verified(): void {
		list( $service, $meta, $mailer ) = $this->make_service();

		$user_id = $service->register_pre_verified( $this->valid_data() );

		$this->assertSame( '1', $meta[ $user_id ][ Registration_Service::META_EMAIL_VERIFIED ] );
		$this->assertSame( array(), $mailer->sent );
	}

	/**
	 * The core happy path: a freshly issued token verifies successfully and
	 * flips the account to verified.
	 */
	public function test_verify_accepts_a_fresh_valid_token(): void {
		list( $service, $meta ) = $this->make_service();

		$user_id = $service->register( $this->valid_data() );

		$this->assertSame( Registration_Service::VERIFY_OK, $service->verify( $user_id, 'fixed-token' ) );
		$this->assertSame( '1', $meta[ $user_id ][ Registration_Service::META_EMAIL_VERIFIED ] );
	}

	/**
	 * The token is single-use: verifying twice with the same token fails the
	 * second time, because the token meta was deleted on first success.
	 */
	public function test_verify_rejects_a_reused_token(): void {
		list( $service ) = $this->make_service();

		$user_id = $service->register( $this->valid_data() );

		$this->assertSame( Registration_Service::VERIFY_OK, $service->verify( $user_id, 'fixed-token' ) );
		$this->assertSame( Registration_Service::VERIFY_INVALID, $service->verify( $user_id, 'fixed-token' ) );
	}

	/**
	 * A wrong token is rejected without revealing anything about the real one.
	 */
	public function test_verify_rejects_a_wrong_token(): void {
		list( $service ) = $this->make_service();

		$user_id = $service->register( $this->valid_data() );

		$this->assertSame( Registration_Service::VERIFY_INVALID, $service->verify( $user_id, 'not-the-token' ) );
	}

	/**
	 * A token past its 48h expiry is rejected as expired, not merely invalid
	 * — the mutable `$clock` fake lets the test advance time between
	 * `register()` and `verify()` without needing two service instances.
	 */
	public function test_verify_rejects_an_expired_token(): void {
		list( $service, , , $clock ) = $this->make_service( array( 'now' => 1700000000 ) );

		$user_id = $service->register( $this->valid_data() );

		$clock['now'] = 1700000000 + Registration_Service::TOKEN_TTL_SECONDS + 1;

		$this->assertSame( Registration_Service::VERIFY_EXPIRED, $service->verify( $user_id, 'fixed-token' ) );
	}

	/**
	 * An unknown user ID never verifies (defensive: malformed/forged link).
	 */
	public function test_verify_rejects_unknown_user(): void {
		list( $service ) = $this->make_service();

		$this->assertSame( Registration_Service::VERIFY_INVALID, $service->verify( 999999, 'fixed-token' ) );
	}
}
