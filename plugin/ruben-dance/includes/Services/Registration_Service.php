<?php
/**
 * Business rules for customer registration: field validation, account
 * creation, locale/consent capture, and the email-verification token
 * lifecycle (spec F4, F3 step 2, §6.1).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Compliance\Legal;
use RubenDance\Lang;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Registration_Service.
 *
 * Kept WordPress-agnostic the same way `Enrollment_Service` is: every touch
 * of `wp_insert_user()`/user meta/the clock/token generation is an injected
 * callable (see `create_default()` for the real wiring), so validation, the
 * account-creation orchestration and — the highest-risk part — the
 * single-use/expiring verification token are unit-testable with plain
 * PHPUnit and fakes, no WordPress bootstrap needed. Sending the actual email
 * is delegated to a `Mailer` (an interface; `Plain_Mailer` for now, per spec
 * M07 "Out of scope": real templates land in M13).
 */
class Registration_Service {

	const ERROR_FIRST_NAME_REQUIRED = 'first_name_required';
	const ERROR_FIRST_NAME_TOO_LONG = 'first_name_too_long';
	const ERROR_LAST_NAME_REQUIRED  = 'last_name_required';
	const ERROR_LAST_NAME_TOO_LONG  = 'last_name_too_long';
	const ERROR_EMAIL_REQUIRED      = 'email_required';
	const ERROR_EMAIL_INVALID       = 'email_invalid';
	const ERROR_EMAIL_TAKEN         = 'email_taken';
	const ERROR_PHONE_REQUIRED      = 'phone_required';
	const ERROR_PHONE_INVALID       = 'phone_invalid';
	const ERROR_PASSWORD_TOO_SHORT  = 'password_too_short';
	const ERROR_TC_REQUIRED         = 'tc_required';

	/**
	 * Minimum password length. WordPress core enforces no minimum of its own
	 * (the strength meter is advisory only) so the plugin picks one, per
	 * spec §5 "Authentication & accounts".
	 *
	 * @var int
	 */
	const MIN_PASSWORD_LENGTH = 8;

	/**
	 * Verification token lifetime: 48 hours.
	 *
	 * @var int
	 */
	const TOKEN_TTL_SECONDS = 172800;

	const META_PHONE                     = 'rd_phone';
	const META_LOCALE                    = 'rd_locale';
	const META_TC_ACCEPTED_AT            = 'rd_tc_accepted_at';
	const META_TC_VERSION                = 'rd_tc_version';
	const META_MARKETING_CONSENT         = 'rd_marketing_consent';
	const META_MARKETING_CONSENT_AT      = 'rd_marketing_consent_at';
	const META_EMAIL_VERIFIED            = 'rd_email_verified';
	const META_VERIFICATION_TOKEN_HASH   = 'rd_verification_token_hash';
	const META_VERIFICATION_TOKEN_EXPIRE = 'rd_verification_token_expires';

	/**
	 * User meta key marking an account anonymized by a GDPR erasure request
	 * or the retention cron (spec §6.1): its value is the `Y-m-d H:i:s`
	 * moment anonymization happened. Presence of this key is the guard both
	 * `Compliance\Personal_Data::anonymize_user()` (idempotency: never
	 * re-anonymize the same account) and `Services\Retention_Service` (never
	 * re-select an already-anonymized account as an "inactive customer"
	 * candidate) rely on.
	 *
	 * @var string
	 */
	const META_ANONYMIZED_AT = 'rd_anonymized_at';

	const VERIFY_OK      = 'ok';
	const VERIFY_INVALID = 'invalid';
	const VERIFY_EXPIRED = 'expired';

	/**
	 * True if an account already exists for the email: function( string $email ): bool.
	 *
	 * @var callable
	 */
	private $email_exists;

	/**
	 * Creates the WP user, returns its ID: function( array $data ): int.
	 * `$data` has keys email, password, first_name, last_name. Must throw
	 * `Registration_Failed_Exception` on failure.
	 *
	 * @var callable
	 */
	private $insert_user;

	/**
	 * Sets a single user meta value: function( int $user_id, string $key, string $value ): void.
	 *
	 * @var callable
	 */
	private $update_user_meta;

	/**
	 * Reads a single user meta value, `''` if unset: function( int $user_id, string $key ): string.
	 *
	 * @var callable
	 */
	private $get_user_meta;

	/**
	 * Deletes a single user meta value: function( int $user_id, string $key ): void.
	 *
	 * @var callable
	 */
	private $delete_user_meta;

	/**
	 * Generates a random, URL-safe token: function(): string.
	 *
	 * @var callable
	 */
	private $generate_token;

	/**
	 * Current Unix timestamp: function(): int.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Builds the verification link for a user/token pair:
	 * function( int $user_id, string $token, string $locale ): string.
	 *
	 * @var callable
	 */
	private $verification_link;

	/**
	 * Composes the localized verification email:
	 * function( string $locale, string $link, string $first_name ): array{subject: string, body: string}.
	 * Injected (rather than rendering templates directly in this class) so
	 * this WordPress-agnostic service never needs the template system (or
	 * `__()`) to exist — the same reasoning as every other WordPress
	 * touchpoint here.
	 *
	 * @var callable
	 */
	private $compose_verification_email;

	/**
	 * Mailer the verification email is sent through.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param callable $email_exists                function( string $email ): bool.
	 * @param callable $insert_user                  function( array $data ): int.
	 * @param callable $update_user_meta             function( int $user_id, string $key, string $value ): void.
	 * @param callable $get_user_meta                function( int $user_id, string $key ): string.
	 * @param callable $delete_user_meta              function( int $user_id, string $key ): void.
	 * @param callable $generate_token                function(): string.
	 * @param callable $now                           function(): int.
	 * @param callable $verification_link             function( int $user_id, string $token, string $locale ): string.
	 * @param callable $compose_verification_email    function( string $locale, string $link, string $first_name ): array{subject: string, body: string}.
	 * @param Mailer   $mailer                        Mailer implementation.
	 */
	public function __construct(
		callable $email_exists,
		callable $insert_user,
		callable $update_user_meta,
		callable $get_user_meta,
		callable $delete_user_meta,
		callable $generate_token,
		callable $now,
		callable $verification_link,
		callable $compose_verification_email,
		Mailer $mailer
	) {
		$this->email_exists               = $email_exists;
		$this->insert_user                = $insert_user;
		$this->update_user_meta           = $update_user_meta;
		$this->get_user_meta              = $get_user_meta;
		$this->delete_user_meta           = $delete_user_meta;
		$this->generate_token             = $generate_token;
		$this->now                        = $now;
		$this->verification_link          = $verification_link;
		$this->compose_verification_email = $compose_verification_email;
		$this->mailer                     = $mailer;
	}

	/**
	 * Wire the service to real WordPress users/user meta and the clock.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		return new self(
			static function ( string $email ): bool {
				return false !== email_exists( $email );
			},
			static function ( array $data ): int {
				$user_id = wp_insert_user(
					array(
						'user_login'   => $data['email'],
						'user_email'   => $data['email'],
						'user_pass'    => $data['password'],
						'first_name'   => $data['first_name'],
						'last_name'    => $data['last_name'],
						'display_name' => trim( $data['first_name'] . ' ' . $data['last_name'] ),
						'role'         => 'subscriber',
					)
				);

				if ( is_wp_error( $user_id ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing exception message, never echoed to a page.
					throw new Registration_Failed_Exception( $user_id->get_error_message() );
				}

				return (int) $user_id;
			},
			static function ( int $user_id, string $key, string $value ): void {
				update_user_meta( $user_id, $key, $value );
			},
			static function ( int $user_id, string $key ): string {
				$value = get_user_meta( $user_id, $key, true );

				return is_string( $value ) ? $value : '';
			},
			static function ( int $user_id, string $key ): void {
				delete_user_meta( $user_id, $key );
			},
			static function (): string {
				return bin2hex( random_bytes( 32 ) );
			},
			static function (): int {
				return time();
			},
			static function ( int $user_id, string $token, string $locale ): string {
				$login_url = \RubenDance\Front\Pages::url( \RubenDance\Front\Pages::LOGIN, $locale );

				return add_query_arg(
					array(
						'rd_verify' => '1',
						'uid'       => $user_id,
						'token'     => $token,
					),
					$login_url
				);
			},
			static function ( string $locale, string $link, string $first_name ): array {
				// The real, editable CS/EN E1 template (M13) — replaces the
				// hardcoded plain-text body M07 shipped with.
				return \RubenDance\Emails\Email_Templates::compose(
					\RubenDance\Emails\Email_Templates::TYPE_E1,
					$locale,
					array(
						'first_name' => $first_name,
						'link'       => $link,
					)
				);
			},
			// E1 must appear in wp_rd_email_log like every other type (spec
			// F14), but this service deliberately knows nothing about logging
			// — the decorator adds it at the transport seam. The user ID is
			// resolved from the recipient address (the account was created
			// moments before the send, so the lookup always succeeds).
			new Logging_Mailer(
				new Html_Mailer(),
				\RubenDance\Emails\Email_Templates::TYPE_E1,
				static function ( string $to ): ?int {
					$user_id = email_exists( $to );

					return false === $user_id ? null : (int) $user_id;
				}
			)
		);
	}

	/**
	 * Validate submitted registration field values.
	 *
	 * @param array<string, mixed> $data Raw (unslashed) field values: first_name, last_name, email, phone, password, tc_accepted.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public function validate( array $data ): array {
		$errors = array();

		$first_name = trim( (string) ( $data['first_name'] ?? '' ) );
		$last_name  = trim( (string) ( $data['last_name'] ?? '' ) );
		$email      = trim( (string) ( $data['email'] ?? '' ) );
		$phone      = trim( (string) ( $data['phone'] ?? '' ) );
		$password   = (string) ( $data['password'] ?? '' );

		if ( '' === $first_name ) {
			$errors['first_name'] = self::ERROR_FIRST_NAME_REQUIRED;
		} elseif ( strlen( $first_name ) > 190 ) {
			$errors['first_name'] = self::ERROR_FIRST_NAME_TOO_LONG;
		}

		if ( '' === $last_name ) {
			$errors['last_name'] = self::ERROR_LAST_NAME_REQUIRED;
		} elseif ( strlen( $last_name ) > 190 ) {
			$errors['last_name'] = self::ERROR_LAST_NAME_TOO_LONG;
		}

		if ( '' === $email ) {
			$errors['email'] = self::ERROR_EMAIL_REQUIRED;
		} elseif ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['email'] = self::ERROR_EMAIL_INVALID;
		} elseif ( ( $this->email_exists )( $email ) ) {
			$errors['email'] = self::ERROR_EMAIL_TAKEN;
		}

		if ( '' === $phone ) {
			$errors['phone'] = self::ERROR_PHONE_REQUIRED;
		} elseif ( strlen( $phone ) > 30 || 1 !== preg_match( '/^[0-9+()\s.-]{6,30}$/', $phone ) ) {
			$errors['phone'] = self::ERROR_PHONE_INVALID;
		}

		if ( strlen( $password ) < self::MIN_PASSWORD_LENGTH ) {
			$errors['password'] = self::ERROR_PASSWORD_TOO_SHORT;
		}

		if ( empty( $data['tc_accepted'] ) ) {
			$errors['tc_accepted'] = self::ERROR_TC_REQUIRED;
		}

		return $errors;
	}

	/**
	 * Register a new (inactive) account and email the verification link.
	 * Caller must call `validate()` first and only proceed when it returns
	 * an empty array.
	 *
	 * @param array<string, mixed> $data Field values, same shape as `validate()`, plus locale, marketing_consent.
	 * @return int New user ID.
	 * @throws Registration_Failed_Exception When account creation fails (e.g. a race-condition duplicate email).
	 */
	public function register( array $data ): int {
		$user_id = $this->create_account( $data, false );

		$this->issue_verification_token(
			$user_id,
			(string) $data['email'],
			(string) ( $data['locale'] ?? Lang::DEFAULT_LANGUAGE ),
			trim( (string) ( $data['first_name'] ?? '' ) )
		);

		return $user_id;
	}

	/**
	 * Register an already-verified account, skipping the token/email step
	 * entirely. Used by `wp rd seed` to create ready-to-use fixture
	 * customers without generating verification emails on every seed run.
	 *
	 * @param array<string, mixed> $data Field values, same shape as `validate()`, plus locale, marketing_consent.
	 * @return int New user ID.
	 * @throws Registration_Failed_Exception When account creation fails.
	 */
	public function register_pre_verified( array $data ): int {
		return $this->create_account( $data, true );
	}

	/**
	 * Verify a token: single-use (the token meta is deleted on success, so a
	 * repeat click of the same link fails) and time-limited (rejected once
	 * `now() > expires`, spec M07 acceptance criteria).
	 *
	 * @param int    $user_id User ID from the verification link.
	 * @param string $token   Raw token from the verification link.
	 * @return string One of self::VERIFY_OK, self::VERIFY_INVALID, self::VERIFY_EXPIRED.
	 */
	public function verify( int $user_id, string $token ): string {
		if ( '' === $token || $user_id <= 0 ) {
			return self::VERIFY_INVALID;
		}

		$stored_hash = ( $this->get_user_meta )( $user_id, self::META_VERIFICATION_TOKEN_HASH );
		$expires     = ( $this->get_user_meta )( $user_id, self::META_VERIFICATION_TOKEN_EXPIRE );

		if ( '' === $stored_hash || '' === $expires ) {
			return self::VERIFY_INVALID;
		}

		if ( ! hash_equals( $stored_hash, hash( 'sha256', $token ) ) ) {
			return self::VERIFY_INVALID;
		}

		if ( ( $this->now )() > (int) $expires ) {
			return self::VERIFY_EXPIRED;
		}

		( $this->update_user_meta )( $user_id, self::META_EMAIL_VERIFIED, '1' );
		( $this->delete_user_meta )( $user_id, self::META_VERIFICATION_TOKEN_HASH );
		( $this->delete_user_meta )( $user_id, self::META_VERIFICATION_TOKEN_EXPIRE );

		return self::VERIFY_OK;
	}

	/**
	 * Shared account-creation + meta-capture orchestration for `register()`
	 * and `register_pre_verified()`. Always writes `META_EMAIL_VERIFIED`
	 * (to `'0'` or `'1'`), never leaves it unset — that is what lets
	 * `Front\Access_Restrictions::block_unverified_login()` distinguish "a
	 * customer account still awaiting verification" from "an account that
	 * never went through this flow at all" (e.g. an admin-created one),
	 * which must never be blocked.
	 *
	 * @param array<string, mixed> $data         Field values: first_name, last_name, email, phone, password, locale, marketing_consent.
	 * @param bool                 $pre_verified Whether to mark the account verified immediately.
	 * @return int New user ID.
	 */
	private function create_account( array $data, bool $pre_verified ): int {
		$user_id = ( $this->insert_user )(
			array(
				'email'      => trim( (string) $data['email'] ),
				'password'   => (string) $data['password'],
				'first_name' => trim( (string) $data['first_name'] ),
				'last_name'  => trim( (string) $data['last_name'] ),
			)
		);

		$now    = ( $this->now )();
		$locale = (string) ( $data['locale'] ?? Lang::DEFAULT_LANGUAGE );

		( $this->update_user_meta )( $user_id, self::META_PHONE, trim( (string) $data['phone'] ) );
		( $this->update_user_meta )( $user_id, self::META_LOCALE, $locale );
		( $this->update_user_meta )( $user_id, self::META_TC_ACCEPTED_AT, gmdate( 'Y-m-d H:i:s', $now ) );
		( $this->update_user_meta )( $user_id, self::META_TC_VERSION, Legal::TC_VERSION );
		( $this->update_user_meta )( $user_id, self::META_EMAIL_VERIFIED, $pre_verified ? '1' : '0' );

		$marketing_consent = ! empty( $data['marketing_consent'] );

		( $this->update_user_meta )( $user_id, self::META_MARKETING_CONSENT, $marketing_consent ? '1' : '0' );

		if ( $marketing_consent ) {
			( $this->update_user_meta )( $user_id, self::META_MARKETING_CONSENT_AT, gmdate( 'Y-m-d H:i:s', $now ) );
		}

		return $user_id;
	}

	/**
	 * Generate, store (hashed) and email a fresh verification token.
	 *
	 * @param int    $user_id    New user ID.
	 * @param string $email      Address to send the verification link to.
	 * @param string $locale     Locale the email is written in (spec F3 step 2:
	 *                           "the site language at registration ... drives
	 *                           the language of all future emails").
	 * @param string $first_name Recipient's first name, for the template's
	 *                           `{first_name}` placeholder.
	 */
	private function issue_verification_token( int $user_id, string $email, string $locale, string $first_name ): void {
		$token   = ( $this->generate_token )();
		$expires = ( $this->now )() + self::TOKEN_TTL_SECONDS;

		( $this->update_user_meta )( $user_id, self::META_VERIFICATION_TOKEN_HASH, hash( 'sha256', $token ) );
		( $this->update_user_meta )( $user_id, self::META_VERIFICATION_TOKEN_EXPIRE, (string) $expires );

		$link    = ( $this->verification_link )( $user_id, $token, $locale );
		$content = ( $this->compose_verification_email )( $locale, $link, $first_name );

		$this->mailer->send( $email, $content['subject'], $content['body'] );
	}
}
