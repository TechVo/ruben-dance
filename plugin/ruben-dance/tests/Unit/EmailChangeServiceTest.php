<?php
/**
 * Tests for the email-change validation and request/confirm token lifecycle
 * (spec F7 acceptance criterion: "Email change requires re-verification
 * before taking effect").
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use RubenDance\Services\Email_Change_Service;
use RubenDance\Services\Mailer;

/**
 * Class EmailChangeServiceTest.
 *
 * `Email_Change_Service` is deliberately WordPress-agnostic (mirroring
 * `Registration_Service`'s verification-token lifecycle), so the
 * request/confirm flow — single-use, expiring, and re-checked against a
 * race-condition duplicate at confirm time — is exercised here with plain
 * PHPUnit and in-memory fakes, no WordPress bootstrap needed.
 */
class EmailChangeServiceTest extends TestCase {

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
			 * @param string               $to            Recipient.
			 * @param string               $subject       Subject.
			 * @param string               $body          Body.
			 * @param array<int, mixed>    $inline_images Unused by this fake.
			 * @return bool
			 */
			public function send( string $to, string $subject, string $body, array $inline_images = array() ): bool {
				unset( $inline_images );

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
	 * Build a service wired to simple in-memory fakes. `$existing` (mutable)
	 * models `email_exists()`, so a test can simulate a race — someone else
	 * claiming the pending address between request and confirm — by
	 * appending to it mid-test.
	 *
	 * @param array<string, mixed> $options {
	 *     @type string[] $existing_emails Emails `email_exists()` should report as taken.
	 *     @type int      $now             Initial "current" Unix timestamp.
	 *     @type Mailer   $mailer          Mailer fake (defaults to `fake_mailer()`).
	 * }
	 * @return array{0: Email_Change_Service, 1: ArrayObject, 2: ArrayObject, 3: Mailer, 4: ArrayObject, 5: ArrayObject} [service, meta store, applied-changes store, mailer, clock, existing emails].
	 */
	private function make_service( array $options = array() ): array {
		$existing = new ArrayObject( $options['existing_emails'] ?? array() );
		$mailer   = $options['mailer'] ?? $this->fake_mailer();

		$meta    = new ArrayObject( array() );
		$applied = new ArrayObject( array() );
		$clock   = new ArrayObject( array( 'now' => $options['now'] ?? 1700000000 ) );

		$service = new Email_Change_Service(
			static function ( string $email ) use ( $existing ): bool {
				return in_array( $email, (array) $existing, true );
			},
			static function ( int $user_id, string $key ) use ( $meta ): string {
				return (string) ( $meta[ $user_id ][ $key ] ?? '' );
			},
			static function ( int $user_id, string $key, string $value ) use ( $meta ): void {
				$row              = $meta[ $user_id ] ?? array();
				$row[ $key ]      = $value;
				$meta[ $user_id ] = $row;
			},
			static function ( int $user_id, string $key ) use ( $meta ): void {
				$row = $meta[ $user_id ] ?? array();
				unset( $row[ $key ] );
				$meta[ $user_id ] = $row;
			},
			static function ( int $user_id, string $new_email ) use ( $applied ): void {
				$applied[ $user_id ] = $new_email;
			},
			static function (): string {
				return 'fixed-token';
			},
			static function () use ( $clock ): int {
				return $clock['now'];
			},
			static function ( int $user_id, string $token, string $locale ): string {
				return "https://example.test/account?uid={$user_id}&token={$token}&locale={$locale}";
			},
			static function ( string $locale, string $new_email, string $link ): array {
				return array(
					'subject' => "confirm ({$locale}): {$new_email}",
					'body'    => "Click: {$link}",
				);
			},
			$mailer
		);

		return array( $service, $meta, $applied, $mailer, $clock, $existing );
	}

	/**
	 * A new address identical to the current one is rejected.
	 */
	public function test_validate_new_email_rejects_same_as_current(): void {
		list( $service ) = $this->make_service();

		$errors = $service->validate_new_email( 'jana@example.com', 'jana@example.com' );

		$this->assertSame( Email_Change_Service::ERROR_EMAIL_SAME, $errors['new_email'] );
	}

	/**
	 * An address already in use by another account is rejected.
	 */
	public function test_validate_new_email_rejects_taken_address(): void {
		list( $service ) = $this->make_service( array( 'existing_emails' => array( 'taken@example.com' ) ) );

		$errors = $service->validate_new_email( 'taken@example.com', 'jana@example.com' );

		$this->assertSame( Email_Change_Service::ERROR_EMAIL_TAKEN, $errors['new_email'] );
	}

	/**
	 * A fresh, valid address produces no errors.
	 */
	public function test_validate_new_email_accepts_a_fresh_address(): void {
		list( $service ) = $this->make_service();

		$this->assertSame( array(), $service->validate_new_email( 'new@example.com', 'jana@example.com' ) );
	}

	/**
	 * `request_change()` never applies the address — this is the acceptance
	 * criterion itself: the change is pending, not yet live. It also emails
	 * the confirmation link to the NEW address, not the old one — receiving
	 * and clicking it is what proves ownership.
	 */
	public function test_request_change_does_not_apply_the_email_yet(): void {
		list( $service, , $applied, $mailer ) = $this->make_service();

		$service->request_change( 5, 'new@example.com', 'cs' );

		$this->assertSame( array(), (array) $applied );
		$this->assertCount( 1, $mailer->sent );
		$this->assertSame( 'new@example.com', $mailer->sent[0]['to'] );
	}

	/**
	 * A fresh, valid token confirms and applies the change.
	 */
	public function test_confirm_applies_the_change_on_a_valid_token(): void {
		list( $service, , $applied ) = $this->make_service();

		$service->request_change( 5, 'new@example.com', 'cs' );

		$this->assertSame( Email_Change_Service::CONFIRM_OK, $service->confirm( 5, 'fixed-token' ) );
		$this->assertSame( 'new@example.com', $applied[5] );
	}

	/**
	 * A wrong token is rejected and nothing is applied.
	 */
	public function test_confirm_rejects_a_wrong_token(): void {
		list( $service, , $applied ) = $this->make_service();

		$service->request_change( 5, 'new@example.com', 'cs' );

		$this->assertSame( Email_Change_Service::CONFIRM_INVALID, $service->confirm( 5, 'wrong-token' ) );
		$this->assertArrayNotHasKey( 5, (array) $applied );
	}

	/**
	 * A token past its 48h expiry is rejected as expired, not merely invalid.
	 */
	public function test_confirm_rejects_an_expired_token(): void {
		list( $service, , , , $clock ) = $this->make_service( array( 'now' => 1700000000 ) );

		$service->request_change( 5, 'new@example.com', 'cs' );

		$clock['now'] = 1700000000 + Email_Change_Service::TOKEN_TTL_SECONDS + 1;

		$this->assertSame( Email_Change_Service::CONFIRM_EXPIRED, $service->confirm( 5, 'fixed-token' ) );
	}

	/**
	 * The token is single-use.
	 */
	public function test_confirm_is_single_use(): void {
		list( $service ) = $this->make_service();

		$service->request_change( 5, 'new@example.com', 'cs' );

		$this->assertSame( Email_Change_Service::CONFIRM_OK, $service->confirm( 5, 'fixed-token' ) );
		$this->assertSame( Email_Change_Service::CONFIRM_INVALID, $service->confirm( 5, 'fixed-token' ) );
	}

	/**
	 * If someone else claims the pending address while the link is
	 * outstanding, confirming reports CONFIRM_TAKEN and never applies it.
	 */
	public function test_confirm_rejects_when_address_was_taken_meanwhile(): void {
		list( $service, , $applied, , , $existing ) = $this->make_service();

		$service->request_change( 5, 'new@example.com', 'cs' );

		$existing[] = 'new@example.com';

		$this->assertSame( Email_Change_Service::CONFIRM_TAKEN, $service->confirm( 5, 'fixed-token' ) );
		$this->assertArrayNotHasKey( 5, (array) $applied );
	}

	/**
	 * An unknown user ID never confirms (defensive: malformed/forged link).
	 */
	public function test_confirm_rejects_unknown_user(): void {
		list( $service ) = $this->make_service();

		$this->assertSame( Email_Change_Service::CONFIRM_INVALID, $service->confirm( 999999, 'fixed-token' ) );
	}

	/**
	 * `pending_email()` reflects an in-flight request and clears once
	 * confirmed.
	 */
	public function test_pending_email_reflects_request_state(): void {
		list( $service ) = $this->make_service();

		$this->assertSame( '', $service->pending_email( 5 ) );

		$service->request_change( 5, 'new@example.com', 'cs' );
		$this->assertSame( 'new@example.com', $service->pending_email( 5 ) );

		$service->confirm( 5, 'fixed-token' );
		$this->assertSame( '', $service->pending_email( 5 ) );
	}
}
