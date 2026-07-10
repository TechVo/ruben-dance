<?php
/**
 * `Mailer` decorator that writes every send to `wp_rd_email_log`.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Repositories\Email_Log_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Logging_Mailer.
 *
 * Exists for the flows where the email is composed *inside* a
 * WordPress-agnostic service that only sees the `Mailer` interface —
 * `Registration_Service`'s E1 verification email is the one such case. The
 * enrollment-related types (E2–E7) instead go through
 * `Emails\Email_Sender`, which composes and logs in one place; wrapping the
 * mailer here keeps `Registration_Service`'s "the service never knows about
 * logging or templates" seam intact while still satisfying the spec's
 * "every send is written to `wp_rd_email_log`" (F14). Only the subject is
 * logged, never the body (spec §6.1).
 */
class Logging_Mailer implements Mailer {

	/**
	 * The mailer actually performing the send.
	 *
	 * @var Mailer
	 */
	private Mailer $inner;

	/**
	 * Email-log `type` value written for every send (e.g. `E1`).
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Resolves the recipient address to a WP user ID for the log row, or
	 * null: function( string $to ): ?int.
	 *
	 * @var callable
	 */
	private $resolve_user_id;

	/**
	 * Constructor.
	 *
	 * @param Mailer   $inner           The real transport.
	 * @param string   $type            Email-log `type` value.
	 * @param callable $resolve_user_id function( string $to ): ?int.
	 */
	public function __construct( Mailer $inner, string $type, callable $resolve_user_id ) {
		$this->inner           = $inner;
		$this->type            = $type;
		$this->resolve_user_id = $resolve_user_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $to      Recipient email address.
	 * @param string $subject Already-localized subject line.
	 * @param string $body    Already-localized body.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $body ): bool {
		$sent = $this->inner->send( $to, $subject, $body );

		( new Email_Log_Repository() )->insert(
			array(
				'enrollment_id' => null,
				'user_id'       => ( $this->resolve_user_id )( $to ),
				'type'          => $this->type,
				'recipient'     => $to,
				'subject'       => $subject,
				'sent_at'       => current_time( 'mysql' ),
				'status'        => $sent ? 'sent' : 'failed',
			)
		);

		return $sent;
	}
}
