<?php
/**
 * `[rd_voucher_inquiry]` template partial: shown after a successful
 * submission (real or bot-silently-dropped — the two are indistinguishable
 * on purpose, spec §5: honeypot/time-trap hits are "silently dropped").
 * Design/screens.html #3i/#4j: green `rd-alert--success` bar, "Ozveme se do
 * 2 pracovních dnů" (within 2 business days).
 *
 * Root element is `.rd-vou-form-wrap` only — see voucher-form.php's doc
 * comment for why the shared `.rd-app` wrapper lives in
 * `Front\Voucher_Page::render()` instead.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-vou-form-wrap rd-vou-form-wrap--success">
	<div class="rd-alert rd-alert--success">
		<strong class="rd-alert__icon">✓</strong>
		<span><strong><?php esc_html_e( 'Sent.', 'ruben-dance' ); ?></strong> <?php esc_html_e( 'We will get back to you within 2 business days.', 'ruben-dance' ); ?></span>
	</div>
</div>
