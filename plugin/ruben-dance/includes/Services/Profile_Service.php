<?php
/**
 * Business rules for the `[rd_account]` "Profile" tab: name/phone/locale
 * edits, password change, marketing-consent toggle (spec F7, §6.1). Email
 * change lives in the dedicated `Email_Change_Service` — its
 * re-verification token lifecycle is the highest-risk part of this
 * milestone and deserves the same isolation `Registration_Service` gives
 * account verification.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Lang;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Profile_Service.
 *
 * Kept WordPress-agnostic the same way `Registration_Service` is: every
 * touch of `wp_update_user()`/user meta/the clock is an injected callable
 * (see `create_default()` for the real wiring), so validation and the
 * update orchestration are unit-testable with plain PHPUnit and fakes.
 * Every method takes the account to act on as an explicit `$user_id`
 * parameter — the front-end handler (`Front\Account_Form_Handler`) is the
 * only caller and always passes `get_current_user_id()`, never a value read
 * from the request (spec §5: "Never trust a user ID from the request").
 */
class Profile_Service {

	const ERROR_FIRST_NAME_REQUIRED = 'first_name_required';
	const ERROR_FIRST_NAME_TOO_LONG = 'first_name_too_long';
	const ERROR_LAST_NAME_REQUIRED  = 'last_name_required';
	const ERROR_LAST_NAME_TOO_LONG  = 'last_name_too_long';
	const ERROR_PHONE_REQUIRED      = 'phone_required';
	const ERROR_PHONE_INVALID       = 'phone_invalid';
	const ERROR_LOCALE_INVALID      = 'locale_invalid';
	const ERROR_PASSWORD_TOO_SHORT  = 'password_too_short';
	const ERROR_PASSWORD_MISMATCH   = 'password_mismatch';

	/**
	 * Minimum password length, matching `Registration_Service` (spec §5:
	 * "Authentication & accounts" applies equally to a changed password).
	 *
	 * @var int
	 */
	const MIN_PASSWORD_LENGTH = Registration_Service::MIN_PASSWORD_LENGTH;

	/**
	 * Updates the WP user's own first/last/display name:
	 * function( int $user_id, array{first_name: string, last_name: string} $data ): void.
	 *
	 * @var callable
	 */
	private $update_user;

	/**
	 * Sets a single user meta value: function( int $user_id, string $key, string $value ): void.
	 *
	 * @var callable
	 */
	private $update_user_meta;

	/**
	 * Sets a new password for the account: function( int $user_id, string $password ): void.
	 *
	 * @var callable
	 */
	private $set_password;

	/**
	 * Current datetime in `Y-m-d H:i:s` form: function(): string.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param callable $update_user      function( int $user_id, array $data ): void.
	 * @param callable $update_user_meta function( int $user_id, string $key, string $value ): void.
	 * @param callable $set_password     function( int $user_id, string $password ): void.
	 * @param callable $now              function(): string.
	 */
	public function __construct(
		callable $update_user,
		callable $update_user_meta,
		callable $set_password,
		callable $now
	) {
		$this->update_user      = $update_user;
		$this->update_user_meta = $update_user_meta;
		$this->set_password     = $set_password;
		$this->now              = $now;
	}

	/**
	 * Wire the service to real WordPress users/user meta and the clock.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		return new self(
			static function ( int $user_id, array $data ): void {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'first_name'   => $data['first_name'],
						'last_name'    => $data['last_name'],
						'display_name' => trim( $data['first_name'] . ' ' . $data['last_name'] ),
					)
				);
			},
			static function ( int $user_id, string $key, string $value ): void {
				update_user_meta( $user_id, $key, $value );
			},
			static function ( int $user_id, string $password ): void {
				// wp_set_password() re-authenticates the current session
				// automatically when the user changing the password is the
				// one currently logged in (WP core, since 5.7) — no manual
				// wp_set_auth_cookie() needed here.
				wp_set_password( $password, $user_id );
			},
			static function (): string {
				return current_time( 'mysql' );
			}
		);
	}

	/**
	 * Validate submitted profile field values.
	 *
	 * @param array<string, mixed> $data Raw (unslashed) field values: first_name, last_name, phone, locale.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public function validate_profile( array $data ): array {
		$errors = array();

		$first_name = trim( (string) ( $data['first_name'] ?? '' ) );
		$last_name  = trim( (string) ( $data['last_name'] ?? '' ) );
		$phone      = trim( (string) ( $data['phone'] ?? '' ) );
		$locale     = (string) ( $data['locale'] ?? '' );

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

		if ( '' === $phone ) {
			$errors['phone'] = self::ERROR_PHONE_REQUIRED;
		} elseif ( strlen( $phone ) > 30 || 1 !== preg_match( '/^[0-9+()\s.-]{6,30}$/', $phone ) ) {
			$errors['phone'] = self::ERROR_PHONE_INVALID;
		}

		if ( ! in_array( $locale, array( Lang::CS, Lang::EN ), true ) ) {
			$errors['locale'] = self::ERROR_LOCALE_INVALID;
		}

		return $errors;
	}

	/**
	 * Save submitted profile field values. Caller must call
	 * `validate_profile()` first and only proceed when it returns an empty
	 * array.
	 *
	 * @param int                  $user_id Account to update.
	 * @param array<string, mixed> $data    Field values, same shape as `validate_profile()`.
	 */
	public function update_profile( int $user_id, array $data ): void {
		( $this->update_user )(
			$user_id,
			array(
				'first_name' => trim( (string) $data['first_name'] ),
				'last_name'  => trim( (string) $data['last_name'] ),
			)
		);

		( $this->update_user_meta )( $user_id, Registration_Service::META_PHONE, trim( (string) $data['phone'] ) );
		( $this->update_user_meta )( $user_id, Registration_Service::META_LOCALE, (string) $data['locale'] );
	}

	/**
	 * Validate a password-change submission.
	 *
	 * @param array<string, mixed> $data Raw field values: new_password, new_password_confirm.
	 * @return array<string, string> Field name => error code, only for invalid fields.
	 */
	public function validate_password( array $data ): array {
		$errors = array();

		$password = (string) ( $data['new_password'] ?? '' );
		$confirm  = (string) ( $data['new_password_confirm'] ?? '' );

		if ( strlen( $password ) < self::MIN_PASSWORD_LENGTH ) {
			$errors['new_password'] = self::ERROR_PASSWORD_TOO_SHORT;
		} elseif ( ! hash_equals( $password, $confirm ) ) {
			$errors['new_password_confirm'] = self::ERROR_PASSWORD_MISMATCH;
		}

		return $errors;
	}

	/**
	 * Set a new password. Caller must call `validate_password()` first and
	 * only proceed when it returns an empty array.
	 *
	 * @param int    $user_id      Account to update.
	 * @param string $new_password New password, already validated.
	 */
	public function update_password( int $user_id, string $new_password ): void {
		( $this->set_password )( $user_id, $new_password );
	}

	/**
	 * Toggle marketing-consent and record when it last changed (spec
	 * acceptance criterion: "Marketing consent toggle updates the stored
	 * consent + timestamp"). The timestamp is refreshed on *every* toggle,
	 * on or off — it records "when did this consent status last change",
	 * not merely "when was it last given", so an opt-out is just as
	 * auditable as an opt-in (spec §6.1: consent changes must be traceable).
	 *
	 * @param int  $user_id Account to update.
	 * @param bool $consent New consent state.
	 */
	public function toggle_marketing_consent( int $user_id, bool $consent ): void {
		( $this->update_user_meta )( $user_id, Registration_Service::META_MARKETING_CONSENT, $consent ? '1' : '0' );
		( $this->update_user_meta )( $user_id, Registration_Service::META_MARKETING_CONSENT_AT, ( $this->now )() );
	}
}
