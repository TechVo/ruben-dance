<?php
/**
 * `Mailer` implementation sending the real HTML templates (M13).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Html_Mailer.
 *
 * The `Mailer` interface itself never changed (per `Mailer`'s own doc
 * comment: "M13 replaces/wraps it with real CS/EN HTML templates without any
 * caller needing to change") — this is simply a second implementation,
 * alongside `Plain_Mailer`, sending `text/html` instead of `text/plain`
 * because `Emails\Email_Templates`' default bodies (and anything an admin
 * edits them into) are HTML (`<p>`, `<strong>`, links). `Plain_Mailer` stays
 * in place for `Email_Change_Service`, which isn't one of the F14 E1–E7
 * templates this milestone covers.
 */
class Html_Mailer implements Mailer {

	/**
	 * {@inheritDoc}
	 *
	 * Inline images (spec F16/M14: the QR-platba code) are attached via
	 * `PHPMailer::addStringEmbeddedImage()` from a one-shot `phpmailer_init`
	 * callback, rather than through `wp_mail()`'s own `$attachments` param —
	 * that parameter only accepts file paths and always attaches as a
	 * regular (non-inline) attachment, neither of which is what a `cid:`
	 * reference in the HTML body needs. The hook is added and removed around
	 * this single `wp_mail()` call only, so it can never leak into an
	 * unrelated send elsewhere in the same request.
	 *
	 * @param string                                                     $to            Recipient email address.
	 * @param string                                                     $subject       Already-localized subject line.
	 * @param string                                                     $body          Already-localized HTML body.
	 * @param array<int, array{cid: string, data: string, mime: string}> $inline_images Images to embed, referenced from `$body` via `cid:<cid>`.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $body, array $inline_images = array() ): bool {
		$attach = static function ( \PHPMailer\PHPMailer\PHPMailer $phpmailer ) use ( $inline_images ): void {
			foreach ( $inline_images as $image ) {
				$phpmailer->addStringEmbeddedImage(
					$image['data'],
					$image['cid'],
					'qr.png',
					'base64',
					$image['mime']
				);
			}
		};

		if ( array() !== $inline_images ) {
			add_action( 'phpmailer_init', $attach );

			/**
			 * Fires right before an HTML email carrying inline images is
			 * sent. `phpmailer_init` itself (used above to actually attach
			 * them) never fires when something short-circuits `wp_mail()`
			 * via the `pre_wp_mail` filter — e.g. `wp-env`'s dev-only mail
			 * catcher, which has no SMTP to deliver through — so this is the
			 * one observable hook a local harness can use to confirm the QR
			 * code was attached (spec F16 acceptance criterion: "E2 email
			 * ... displays the QR"), without this class needing to know
			 * anything about how the harness observes it.
			 *
			 * @param string                                                     $to            Recipient email address.
			 * @param array<int, array{cid: string, data: string, mime: string}> $inline_images Images that will be embedded.
			 */
			do_action( 'ruben_dance_email_inline_images', $to, $inline_images );
		}

		$sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		if ( array() !== $inline_images ) {
			remove_action( 'phpmailer_init', $attach );
		}

		return $sent;
	}
}
