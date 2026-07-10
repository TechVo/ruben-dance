<?php
/**
 * Dev-only mail catcher for wp-env (M07 verification note): wp-env ships no
 * MailHog/Mailpit container, so this hooks `wp_mail` and appends every
 * outgoing email to `wp-content/rd-mail-log.txt` instead. Never shipped to
 * the plugin itself — this file lives outside `plugin/ruben-dance/` and is
 * mounted only via `.wp-env.json`'s `mappings.wp-content/mu-plugins`, so
 * production installs never load it.
 *
 * Read the log with:
 *   npx wp-env run cli tail -f wp-content/rd-mail-log.txt
 *
 * @package RubenDance
 */

add_filter(
	'wp_mail',
	static function ( array $args ): array {
		$line = sprintf(
			"---\n[%s] To: %s\nSubject: %s\n\n%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : (string) $args['to'],
			(string) $args['subject'],
			(string) $args['message']
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- dev-only mu-plugin (see doc comment above), not part of the shipped plugin; WP_Filesystem is overkill for a local debug log.
		file_put_contents( WP_CONTENT_DIR . '/rd-mail-log.txt', $line, FILE_APPEND | LOCK_EX );

		return $args;
	}
);
