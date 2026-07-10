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
	 * @param string $to      Recipient email address.
	 * @param string $subject Already-localized subject line.
	 * @param string $body    Already-localized plain-text body.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $body ): bool {
		return wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}
}
