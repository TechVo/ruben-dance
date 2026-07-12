<?php
/**
 * `[rd_voucher_inquiry]` template partial: shown after a successful
 * submission (real or bot-silently-dropped — the two are indistinguishable
 * on purpose, spec §5: honeypot/time-trap hits are "silently dropped").
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-voucher-inquiry rd-voucher-inquiry--success">
	<div class="rd-notice rd-notice--success">
		<p><?php esc_html_e( 'Thank you — we have received your message and will get back to you shortly.', 'ruben-dance' ); ?></p>
	</div>
</div>
