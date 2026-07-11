<?php
/**
 * The only `Mailer` implementation for now: a plain-text `wp_mail()` wrapper.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Plain_Mailer.
 *
 * Deliberately minimal (spec M07 "Out of scope": real email templates are
 * M13). Sends `text/plain` via `wp_mail()`, the standard WordPress transport
 * (whatever SMTP plugin/host config is wired into it is out of this class's
 * concern, per spec §5: "API keys (SMTP etc.) in `wp-config.php` constants").
 */
class Plain_Mailer implements Mailer {

	/**
	 * {@inheritDoc}
	 *
	 * `$inline_images` is accepted (to satisfy the `Mailer` interface) but
	 * always ignored — a plain-text body has no `cid:` reference to attach
	 * them for, and none of this class's callers (E1 verification, the
	 * email-change confirmation) are one of the payment-instruction types
	 * that ever carries a QR code.
	 *
	 * @param string                                                     $to            Recipient email address.
	 * @param string                                                     $subject       Already-localized subject line.
	 * @param string                                                     $body          Already-localized plain-text body.
	 * @param array<int, array{cid: string, data: string, mime: string}> $inline_images Unused, see above.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $body, array $inline_images = array() ): bool {
		unset( $inline_images );

		return wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}
}
