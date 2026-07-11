<?php
/**
 * Dev-only mail catcher for wp-env (M07 verification note, upgraded in M13):
 * wp-env ships no MailHog/Mailpit container and no SMTP, so a real send
 * would always fail inside PHPMailer. This hooks `pre_wp_mail` (WP 5.7+),
 * appends every outgoing email to `wp-content/rd-mail-log.txt`, and
 * **short-circuits `wp_mail()` with `true`** — so "delivered to the catcher"
 * counts as a successful send, exactly the way a working SMTP setup would
 * report, and the plugin's email-log `sent`/`failed` statuses keep their
 * production semantics locally. (The previous `wp_mail`-filter version
 * logged the mail but still let PHPMailer fail, which made every local send
 * register as `failed` despite being readable in the log.)
 *
 * To exercise the failure path locally, disable this file (rename/move it)
 * — `wp_mail()` then falls through to PHPMailer, which fails without SMTP.
 *
 * Never shipped to the plugin itself — this file lives outside
 * `plugin/ruben-dance/` and is mounted only via `.wp-env.json`'s
 * `mappings.wp-content/mu-plugins`, so production installs never load it.
 *
 * Read the log with:
 *   npx wp-env run cli tail -f wp-content/rd-mail-log.txt
 *
 * @package RubenDance
 */

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, array $atts ) {
		unset( $short_circuit );

		$to = $atts['to'] ?? '';

		$line = sprintf(
			"---\n[%s] To: %s\nSubject: %s\n\n%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			is_array( $to ) ? implode( ', ', $to ) : (string) $to,
			(string) ( $atts['subject'] ?? '' ),
			(string) ( $atts['message'] ?? '' )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- dev-only mu-plugin (see doc comment above), not part of the shipped plugin; WP_Filesystem is overkill for a local debug log.
		file_put_contents( WP_CONTENT_DIR . '/rd-mail-log.txt', $line, FILE_APPEND | LOCK_EX );

		return true; // Skip PHPMailer entirely; wp_mail() returns true.
	},
	10,
	2
);

/**
 * M14 (QR payment code) addition: `pre_wp_mail`'s short-circuit above means
 * `Services\Html_Mailer`'s own `phpmailer_init` attach hook never runs here
 * (see that class's doc comment) — there is no PHPMailer instance to inspect
 * an inline image on. `Html_Mailer` fires this separate, harness-agnostic
 * hook right before every send that carries one or more inline images, which
 * is the only thing that lets this local dev catcher confirm the QR code was
 * actually attached: it notes the attempt in the same log and saves each
 * image's raw bytes next to it so it can be opened and eyeballed (or
 * scanned) directly.
 */
add_action(
	'ruben_dance_email_inline_images',
	static function ( string $to, array $inline_images ): void {
		if ( array() === $inline_images ) {
			return;
		}

		$note = sprintf( "[%s] Inline images for %s:\n", gmdate( 'Y-m-d H:i:s' ), $to );

		foreach ( $inline_images as $image ) {
			$cid      = (string) ( $image['cid'] ?? 'unknown' );
			$data     = (string) ( $image['data'] ?? '' );
			$filename = WP_CONTENT_DIR . '/rd-mail-log-inline-' . sanitize_file_name( $cid ) . '.png';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- dev-only mu-plugin, see doc comment above.
			file_put_contents( $filename, $data );

			$note .= sprintf( "  - cid:%s (%d bytes) saved to %s\n", $cid, strlen( $data ), basename( $filename ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- dev-only mu-plugin, see doc comment above.
		file_put_contents( WP_CONTENT_DIR . '/rd-mail-log.txt', $note, FILE_APPEND | LOCK_EX );
	},
	10,
	2
);
