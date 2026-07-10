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
	 * @param string $to      Recipient email address.
	 * @param string $subject Already-localized subject line.
	 * @param string $body    Already-localized HTML body.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $body ): bool {
		return wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
