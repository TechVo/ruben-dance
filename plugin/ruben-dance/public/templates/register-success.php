<?php
/**
 * `[rd_register]` template partial: shown after a successful submission
 * (real or bot-silently-dropped — the two are indistinguishable on purpose,
 * spec §5: honeypot/time-trap hits are "silently dropped").
 *
 * Design/screens.html #3g's "Zkontrolujte e-mail" info alert (white card, 1.5px
 * cocoa-25 border, orange ✉ icon — `.rd-alert` with no color modifier).
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-auth rd-auth--register-success">
	<h1 class="rd-h2 rd-auth-heading"><?php esc_html_e( 'Register', 'ruben-dance' ); ?></h1>

	<div class="rd-alert">
		<strong class="rd-alert__icon">✉</strong>
		<span><?php esc_html_e( 'Almost done! Please check your inbox and click the verification link to activate your account.', 'ruben-dance' ); ?></span>
	</div>
</div>
