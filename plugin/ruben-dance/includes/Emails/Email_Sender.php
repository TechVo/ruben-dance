<?php
/**
 * Compose-send-log orchestrator shared by every E1–E7 trigger (F14).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Emails;

use RubenDance\Repositories\Email_Log_Repository;
use RubenDance\Services\Html_Mailer;
use RubenDance\Services\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Email_Sender.
 *
 * Every admin/front controller that used to hand-roll "build a subject/body,
 * call `Mailer::send()`, insert an `Email_Log_Repository` row" (the M07–M12
 * stubs in `Roster_Ajax`, `Enrollment_Detail_Page`, `Enrollment_Form_Handler`)
 * now goes through this one method instead, so the three steps can never
 * drift apart — in particular, "no full bodies in the log" (spec §6.1) only
 * has to be true in one place. `Mailer`/the clock/the log write are injected
 * callables/interfaces (mirroring every other service in this plugin), so
 * this class stays unit-testable without a WordPress bootstrap.
 */
class Email_Sender {

	/**
	 * Mailer the composed email is sent through.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Writes one `wp_rd_email_log` row: function( array $data ): void.
	 *
	 * @var callable
	 */
	private $log_email;

	/**
	 * Current datetime in `Y-m-d H:i:s` form: function(): string.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param Mailer   $mailer    Mailer implementation.
	 * @param callable $log_email function( array $data ): void.
	 * @param callable $now       function(): string.
	 */
	public function __construct( Mailer $mailer, callable $log_email, callable $now ) {
		$this->mailer    = $mailer;
		$this->log_email = $log_email;
		$this->now       = $now;
	}

	/**
	 * Wire the sender to the real HTML mailer, the real email log table and
	 * the WordPress clock.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		return new self(
			new Html_Mailer(),
			static function ( array $data ): void {
				( new Email_Log_Repository() )->insert( $data );
			},
			static function (): string {
				return current_time( 'mysql' );
			}
		);
	}

	/**
	 * Compose one type/language template with `$placeholders`, send it, and
	 * log the attempt — success or failure, `wp_mail` failures are logged
	 * too (spec M13 task: "failures ... logged with status `failed` ...
	 * never silently lost"), never skipped.
	 *
	 * @param string                     $type          One of `Email_Templates::TYPES`.
	 * @param string                     $lang          One of `Email_Templates::LANGUAGES`.
	 * @param string                     $to            Recipient email address.
	 * @param array<string, string|null> $placeholders  Placeholder token => value.
	 * @param int|null                   $enrollment_id Related enrollment ID, or null (e.g. E1 has none yet).
	 * @param int|null                   $user_id       Related WP user ID, or null.
	 * @param callable|null              $augment_body  Optional `function( string $html_body ): array{body: string, inline_images: array}`,
	 *                                                  run on the composed body before sending (spec F16/M14: appending the
	 *                                                  QR-platba image to E2/E7 without `Email_Templates`' admin-editable
	 *                                                  template text ever needing to know QR/SPAYD exists — see
	 *                                                  `Emails\Payment_Qr_Email::augmenter()`). Null (the default) for every
	 *                                                  other email type.
	 * @return bool True if `wp_mail()` reported success.
	 */
	public function send( string $type, string $lang, string $to, array $placeholders, ?int $enrollment_id, ?int $user_id, ?callable $augment_body = null ): bool {
		$content = Email_Templates::compose( $type, $lang, $placeholders );

		$inline_images = array();

		if ( null !== $augment_body ) {
			$augmented = $augment_body( $content['body'] );

			$content['body'] = $augmented['body'];
			$inline_images   = $augmented['inline_images'];
		}

		// D8: the universal HTML chrome (design/screens.html #3j) — applied
		// last, around the fully-composed (and, for E2/E7, QR-augmented)
		// body, so every E1-E8 send gets it without any individual trigger
		// needing to know it exists. `Email_Log_Repository::insert()` below
		// still logs `$content['subject']` only, per spec §6.1's "no full
		// bodies in the log" — the wrapped HTML is only ever what's mailed,
		// never what's stored.
		$content['body'] = Email_Layout::wrap( $content['subject'], $content['body'], $lang );

		$sent = $this->mailer->send( $to, $content['subject'], $content['body'], $inline_images );

		( $this->log_email )(
			array(
				'enrollment_id' => $enrollment_id,
				'user_id'       => $user_id,
				'type'          => $type,
				'recipient'     => $to,
				'subject'       => $content['subject'],
				'sent_at'       => ( $this->now )(),
				'status'        => $sent ? 'sent' : 'failed',
			)
		);

		return $sent;
	}
}
