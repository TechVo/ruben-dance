<?php
/**
 * `[rd_register]` template partial: shown after a successful submission
 * (real or bot-silently-dropped — the two are indistinguishable on purpose,
 * spec §5: honeypot/time-trap hits are "silently dropped").
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-auth rd-auth--register-success">
	<div class="rd-notice rd-notice--success">
		<p><?php esc_html_e( 'Almost done! Please check your inbox and click the verification link to activate your account.', 'ruben-dance' ); ?></p>
	</div>
</div>
