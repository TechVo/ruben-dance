<?php
/**
 * `[rd_enroll]` template partial: anonymous visitor (spec F3 step 2 —
 * register-or-login precedes the enrollment form itself). Design/screens.html
 * #3e's yellow-bordered "Nejdřív se přihlaste" login block.
 *
 * Variables available: string $login_url, string $register_url.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-enroll rd-enroll--login-required">
	<div class="rd-payment rd-enr-login-card">
		<h2 class="rd-h3"><?php esc_html_e( 'Please log in first', 'ruben-dance' ); ?></h2>
		<p class="rd-text"><?php esc_html_e( "You'll finish your enrollment right back here — nothing will be lost.", 'ruben-dance' ); ?></p>
		<div class="rd-enr-login-actions">
			<a class="rd-btn rd-btn--small" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'ruben-dance' ); ?></a>
			<a class="rd-btn rd-btn--secondary" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Create an account', 'ruben-dance' ); ?></a>
		</div>
	</div>
</div>
