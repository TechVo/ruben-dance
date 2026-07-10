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
	 * @param string $to      Recipient email address.
	 * @param string $subject Already-localized subject line.
	 * @param string $body    Already-localized plain-text body.
	 * @return bool True on (apparent) success.
	 */
	public function send( string $to, string $subject, string $body ): bool;
}
