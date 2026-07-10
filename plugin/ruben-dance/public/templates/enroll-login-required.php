<?php
/**
 * `[rd_enroll]` template partial: anonymous visitor (spec F3 step 2 —
 * register-or-login precedes the enrollment form itself).
 *
 * Variables available: string $login_url, string $register_url.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-enroll rd-enroll--login-required">
	<div class="rd-notice">
		<p><?php esc_html_e( 'Please log in or create an account to enroll. Once you\'re signed in, you\'ll come straight back here to finish your enrollment.', 'ruben-dance' ); ?></p>
	</div>

	<p>
		<a class="rd-button" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'ruben-dance' ); ?></a>
		<a class="rd-button rd-button--secondary" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Create an account', 'ruben-dance' ); ?></a>
	</p>
</div>
