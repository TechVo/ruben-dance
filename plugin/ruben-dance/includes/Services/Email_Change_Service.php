<?php
/**
 * Business rules for changing a customer's account email address: the change
 * only takes effect after the customer proves ownership of the *new*
 * address (spec F7 acceptance criterion: "Email change requires
 * re-verification before taking effect").
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Email_Change_Service.
 *
 * Mirrors `Registration_Service`'s verification-token lifecycle (single-use,
 * hashed, expiring) but scoped to "confirm the new address" rather than
 * "confirm the address you registered with": the confirmation link is
 * emailed to the *new* address, never the old one, and `user_email` is only
 * ever written by `confirm()`, never by `request_change()`. Kept
 * WordPress-agnostic the same way `Registration_Service` is — every touch of
 * user meta/the clock/token generation/`wp_update_user()` is an injected
 * callable (see `create_default()`), so the request/confirm lifecycle is
 * unit-testable with plain PHPUnit and fakes.
 */
class Email_Change_Service {

	const ERROR_EMAIL_REQUIRED = 'email_required';
	const ERROR_EMAIL_INVALID  = 'email_invalid';
	const ERROR_EMAIL_SAME     = 'email_same';
	const ERROR_EMAIL_TAKEN    = 'email_taken';

	const CONFIRM_OK      = 'ok';
	const CONFIRM_INVALID = 'invalid';
	const CONFIRM_EXPIRED = 'expired';

	/**
	 * Someone else registered/claimed the pending address while the
	 * confirmation link was outstanding — distinct from CONFIRM_INVALID so
	 * the profile screen can say something more useful than "bad link".
	 *
	 * @var string
	 */
	const CONFIRM_TAKEN = 'taken';

	/**
	 * Confirmation token lifetime: 48 hours, matching `Registration_Service`.
	 *
	 * @var int
	 */
	const TOKEN_TTL_SECONDS = 172800;

	const META_PENDING_EMAIL = 'rd_email_change_pending';
	const META_TOKEN_HASH    = 'rd_email_change_token_hash';
	const META_TOKEN_EXPIRE  = 'rd_email_change_token_expires';

	/**
	 * True if an account already exists for the email: function( string $email ): bool.
	 *
	 * @var callable
	 */
	private $email_exists;

	/**
	 * Reads a single user meta value, `''` if unset: function( int $user_id, string $key ): string.
	 *
	 * @var callable
	 */
	private $get_user_meta;

	/**
	 * Sets a single user meta value: function( int $user_id, string $key, string $value ): void.
	 *
	 * @var callable
	 */
	private $update_user_meta;

	/**
	 * Deletes a single user meta value: function( int $user_id, string $key ): void.
	 *
	 * @var callable
	 */
	private $delete_user_meta;

	/**
	 * Actually applies the change to `user_email`: function( int $user_id, string $new_email ): void.
	 *
	 * @var callable
	 */
	private $apply_email_change;

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
	 * Builds the confirmation link for a user/token pair:
	 * function( int $user_id, string $token, string $locale ): string.
	 *
	 * @var callable
	 */
	private $confirmation_link;

	/**
	 * Composes the localized confirmation email:
	 * function( string $locale, string $new_email, string $link ): array{subject: string, body: string}.
	 *
	 * @var callable
	 */
	private $compose_email;

	/**
	 * Mailer the confirmation email is sent through.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param callable $email_exists        function( string $email ): bool.
	 * @param callable $get_user_meta       function( int $user_id, string $key ): string.
	 * @param callable $update_user_meta    function( int $user_id, string $key, string $value ): void.
	 * @param callable $delete_user_meta    function( int $user_id, string $key ): void.
	 * @param callable $apply_email_change  function( int $user_id, string $new_email ): void.
	 * @param callable $generate_token      function(): string.
	 * @param callable $now                 function(): int.
	 * @param callable $confirmation_link   function( int $user_id, string $token, string $locale ): string.
	 * @param callable $compose_email       function( string $locale, string $new_email, string $link ): array{subject: string, body: string}.
	 * @param Mailer   $mailer              Mailer implementation.
	 */
	public function __construct(
		callable $email_exists,
		callable $get_user_meta,
		callable $update_user_meta,
		callable $delete_user_meta,
		callable $apply_email_change,
		callable $generate_token,
		callable $now,
		callable $confirmation_link,
		callable $compose_email,
		Mailer $mailer
	) {
		$this->email_exists       = $email_exists;
		$this->get_user_meta      = $get_user_meta;
		$this->update_user_meta   = $update_user_meta;
		$this->delete_user_meta   = $delete_user_meta;
		$this->apply_email_change = $apply_email_change;
		$this->generate_token     = $generate_token;
		$this->now                = $now;
		$this->confirmation_link  = $confirmation_link;
		$this->compose_email      = $compose_email;
		$this->mailer             = $mailer;
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
			static function ( int $user_id, string $key ): string {
				$value = get_user_meta( $user_id, $key, true );

				return is_string( $value ) ? $value : '';
			},
			static function ( int $user_id, string $key, string $value ): void {
				update_user_meta( $user_id, $key, $value );
			},
			static function ( int $user_id, string $key ): void {
				delete_user_meta( $user_id, $key );
			},
			static function ( int $user_id, string $new_email ): void {
				wp_update_user(
					array(
						'ID'         => $user_id,
						'user_email' => $new_email,
					)
				);
			},
			static function (): string {
				return bin2hex( random_bytes( 32 ) );
			},
			static function (): int {
				return time();
			},
			static function ( int $user_id, string $token, string $locale ): string {
				// A plain string, matching Catalog_Page::PAGE_KEY/Enroll_Page::PAGE_KEY's
				// documented reasoning: `Front\Pages::url()` already accepts an
				// arbitrary key, so this stays independent of Front\Account_Page's file.
				$account_url = \RubenDance\Front\Pages::url( 'account', $locale );

				return add_query_arg(
					array(
						'rd_account_email_verify' => '1',
						'uid'                     => $user_id,
						'token'                   => $token,
					),
					$account_url
				);
			},
			static function ( string $locale, string $new_email, string $link ): array {
				$is_en = \RubenDance\Lang::EN === $locale;

				$subject = $is_en
					? __( 'Confirm your new Ruben Dance email address', 'ruben-dance' )
					: __( 'Potvrďte novou emailovou adresu Ruben Dance', 'ruben-dance' );

				$body = $is_en
					? sprintf(
						/* translators: 1: new email address, 2: confirmation link. */
						__( "A change of your Ruben Dance account email to %1\$s was requested.\n\nPlease confirm by clicking the link below:\n%2\$s\n\nThe link is valid for 48 hours and can only be used once. Your current email stays active until you confirm. If you did not request this, you can safely ignore this email.", 'ruben-dance' ),
						$new_email,
						$link
					)
					: sprintf(
						/* translators: 1: new email address, 2: confirmation link. */
						__( "Byla vyžádána změna emailové adresy vašeho účtu Ruben Dance na %1\$s.\n\nPotvrďte prosím kliknutím na následující odkaz:\n%2\$s\n\nOdkaz je platný 48 hodin a lze jej použít pouze jednou. Vaše současná emailová adresa zůstává aktivní až do potvrzení. Pokud jste o tuto změnu nežádali, tento email prosím ignorujte.", 'ruben-dance' ),
						$new_email,
						$link
					);

				return array(
					'subject' => $subject,
					'body'    => $body,
				);
			},
			new Plain_Mailer()
		);
	}

	/**
	 * Validate a submitted new email address.
	 *
	 * @param string $new_email     Raw (unslashed) submitted value.
	 * @param string $current_email The account's current email address.
	 * @return array<string, string> Field name => error code, only when invalid.
	 */
	public function validate_new_email( string $new_email, string $current_email ): array {
		$errors    = array();
		$new_email = trim( $new_email );

		if ( '' === $new_email ) {
			$errors['new_email'] = self::ERROR_EMAIL_REQUIRED;
		} elseif ( false === filter_var( $new_email, FILTER_VALIDATE_EMAIL ) ) {
			$errors['new_email'] = self::ERROR_EMAIL_INVALID;
		} elseif ( strtolower( $new_email ) === strtolower( $current_email ) ) {
			$errors['new_email'] = self::ERROR_EMAIL_SAME;
		} elseif ( ( $this->email_exists )( $new_email ) ) {
			$errors['new_email'] = self::ERROR_EMAIL_TAKEN;
		}

		return $errors;
	}

	/**
	 * Request an email change: issues a token and emails the confirmation
	 * link to the *new* address (never the old one — receiving and clicking
	 * it is the proof of ownership). `user_email` is left untouched; the
	 * account keeps logging in with its current address until `confirm()`
	 * succeeds. Caller must call `validate_new_email()` first and only
	 * proceed when it returns an empty array.
	 *
	 * @param int    $user_id  Account requesting the change.
	 * @param string $new_email Validated new email address.
	 * @param string $locale    Locale the confirmation email is written in.
	 */
	public function request_change( int $user_id, string $new_email, string $locale ): void {
		$token   = ( $this->generate_token )();
		$expires = ( $this->now )() + self::TOKEN_TTL_SECONDS;

		( $this->update_user_meta )( $user_id, self::META_PENDING_EMAIL, $new_email );
		( $this->update_user_meta )( $user_id, self::META_TOKEN_HASH, hash( 'sha256', $token ) );
		( $this->update_user_meta )( $user_id, self::META_TOKEN_EXPIRE, (string) $expires );

		$link    = ( $this->confirmation_link )( $user_id, $token, $locale );
		$content = ( $this->compose_email )( $locale, $new_email, $link );

		$this->mailer->send( $new_email, $content['subject'], $content['body'] );
	}

	/**
	 * Confirm a pending email change: single-use (the token meta is deleted
	 * either way once resolved) and time-limited, mirroring
	 * `Registration_Service::verify()`. Also re-checks that the pending
	 * address hasn't been claimed by a different account in the meantime
	 * (spec §5: never trust state that could have gone stale) — the only
	 * point this class actually writes `user_email`.
	 *
	 * @param int    $user_id User ID from the confirmation link.
	 * @param string $token   Raw token from the confirmation link.
	 * @return string One of self::CONFIRM_OK, self::CONFIRM_INVALID, self::CONFIRM_EXPIRED, self::CONFIRM_TAKEN.
	 */
	public function confirm( int $user_id, string $token ): string {
		if ( '' === $token || $user_id <= 0 ) {
			return self::CONFIRM_INVALID;
		}

		$stored_hash   = ( $this->get_user_meta )( $user_id, self::META_TOKEN_HASH );
		$expires       = ( $this->get_user_meta )( $user_id, self::META_TOKEN_EXPIRE );
		$pending_email = ( $this->get_user_meta )( $user_id, self::META_PENDING_EMAIL );

		if ( '' === $stored_hash || '' === $expires || '' === $pending_email ) {
			return self::CONFIRM_INVALID;
		}

		if ( ! hash_equals( $stored_hash, hash( 'sha256', $token ) ) ) {
			return self::CONFIRM_INVALID;
		}

		if ( ( $this->now )() > (int) $expires ) {
			return self::CONFIRM_EXPIRED;
		}

		if ( ( $this->email_exists )( $pending_email ) ) {
			$this->clear_pending( $user_id );

			return self::CONFIRM_TAKEN;
		}

		( $this->apply_email_change )( $user_id, $pending_email );
		$this->clear_pending( $user_id );

		return self::CONFIRM_OK;
	}

	/**
	 * The email address currently awaiting confirmation, or `''` if none is
	 * pending — the profile screen's "check your inbox for X" notice.
	 *
	 * @param int $user_id Account.
	 * @return string
	 */
	public function pending_email( int $user_id ): string {
		return ( $this->get_user_meta )( $user_id, self::META_PENDING_EMAIL );
	}

	/**
	 * Clear the pending-change meta, on both success and a stale/taken token.
	 *
	 * @param int $user_id Account.
	 */
	private function clear_pending( int $user_id ): void {
		( $this->delete_user_meta )( $user_id, self::META_PENDING_EMAIL );
		( $this->delete_user_meta )( $user_id, self::META_TOKEN_HASH );
		( $this->delete_user_meta )( $user_id, self::META_TOKEN_EXPIRE );
	}
}
