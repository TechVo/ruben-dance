<?php
/**
 * Outbound-email seam: an interface only, so M07 can send the verification
 * email without committing to real templates yet (those land in M13).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Interface Mailer.
 *
 * Every place the plugin needs to send an email depends on this interface,
 * never on `wp_mail()` directly — the same seam-first approach as `Lang`
 * wrapping Polylang. `Plain_Mailer` is the only implementation for now (a
 * thin `wp_mail()` wrapper, plain text); M13 replaces/wraps it with real
 * CS/EN HTML templates without any caller needing to change.
 */
interface Mailer {

	/**
	 * Send a single email.
	 *
	 * @param string                                                     $to            Recipient email address.
	 * @param string                                                     $subject       Already-localized subject line.
	 * @param string                                                     $body          Already-localized plain-text body.
	 * @param array<int, array{cid: string, data: string, mime: string}> $inline_images Images to embed for reference from the body via `cid:<cid>`
	 *                                                                              (spec F16/M14: the QR-platba code embedded in E2/E7). Empty by
	 *                                                                              default; `Plain_Mailer` ignores it (a plain-text body has nothing
	 *                                                                              to reference a `cid:` from), only `Html_Mailer` attaches them.
	 * @return bool True on (apparent) success.
	 */
	public function send( string $to, string $subject, string $body, array $inline_images = array() ): bool;
}
