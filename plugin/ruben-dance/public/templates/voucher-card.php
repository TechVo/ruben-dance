<?php
/**
 * `[rd_voucher_inquiry]` template partial: the decorative "sample voucher"
 * preview card (design/screens.html #3i mobile / #4j desktop — dark cocoa
 * card, yellow decorative circle, "Dárkový poukaz" eyebrow, sample title,
 * validity line, RUBEN·DANCE footer line).
 *
 * Purely illustrative — always the same static sample copy in every
 * language, the same way the design mock shows one example voucher rather
 * than pulling from real data (there is no "voucher" entity in this plugin
 * to render real values from). Rendered by `Front\Voucher_Page::render()`
 * ahead of both the form and success partials, so it stays visible
 * (unchanged) across both states.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-vou-card">
	<div class="rd-vou-card__circle" aria-hidden="true"></div>
	<div class="rd-vou-card__eyebrow"><?php esc_html_e( 'Gift voucher', 'ruben-dance' ); ?></div>
	<div class="rd-vou-card__title"><?php esc_html_e( 'Salsa course for two', 'ruben-dance' ); ?></div>
	<div class="rd-vou-card__meta"><?php esc_html_e( 'for: Petra & Jana · valid until 9/2027', 'ruben-dance' ); ?></div>
	<div class="rd-vou-card__footer">RUBEN<span aria-hidden="true">&middot;</span>DANCE · ruben-dance.cz</div>
</div>
