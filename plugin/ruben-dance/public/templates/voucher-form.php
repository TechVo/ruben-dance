<?php
/**
 * `[rd_voucher_inquiry]` template partial: the inquiry form itself (spec
 * F17/§4.6). Design/screens.html #3i (mobile 390 — white rd-card, "Nezávazná
 * poptávka" heading, jméno/e-mail/zpráva fields, coral "Odeslat poptávku"
 * pill) / #4j (desktop 1280 — same card, floated left of the decorative
 * voucher-card.php preview via front-voucher.css).
 *
 * Root element is `.rd-vou-form-wrap` only (no `.rd-app` here) — the shared
 * `.rd-app` wrapper is applied once, around this partial and
 * `voucher-card.php` together, by `Front\Voucher_Page::render()`.
 *
 * Variables available: array<string,string> $errors, array<string,string>
 * $submitted, bool $rate_limited.
 *
 * @package RubenDance
 */

use RubenDance\Front\Bot_Guard;
use RubenDance\Front\Voucher_Form_Handler;
use RubenDance\Front\Voucher_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-vou-form-wrap">
	<?php if ( $rate_limited ) : ?>
		<div class="rd-alert rd-alert--error" role="alert" tabindex="-1" id="rd-voucher-rate-limited">
			<strong class="rd-alert__icon">✕</strong>
			<span><?php esc_html_e( 'Too many attempts. Please try again later.', 'ruben-dance' ); ?></span>
		</div>
		<script>document.getElementById( 'rd-voucher-rate-limited' ).focus();</script>
	<?php elseif ( array() !== $errors ) : ?>
		<div class="rd-alert rd-alert--error" role="alert" tabindex="-1" id="rd-voucher-errors">
			<strong class="rd-alert__icon">✕</strong>
			<span>
				<ul class="rd-vou-error-list">
					<?php foreach ( $errors as $ruben_dance_error_code ) : ?>
						<li><?php echo esc_html( Voucher_Page::error_message( $ruben_dance_error_code ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</span>
		</div>
		<script>document.getElementById( 'rd-voucher-errors' ).focus();</script>
	<?php endif; ?>

	<form method="post" class="rd-card rd-vou-form" id="rd-voucher-form">
		<div class="rd-h3 rd-vou-form__heading"><?php esc_html_e( 'No-obligation inquiry', 'ruben-dance' ); ?></div>

		<?php wp_nonce_field( Voucher_Form_Handler::NONCE_ACTION ); ?>
		<input type="hidden" name="rd_voucher_action" value="submit">
		<?php echo Bot_Guard::fields_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bot_Guard::fields_html() already escapes everything it outputs. ?>

		<?php // POST field names are rd_voucher_*-prefixed: a bare `name` is a WP public query var and 404s the whole POST — see Voucher_Form_Handler::sanitized_submission(). ?>
		<div class="rd-field<?php echo isset( $errors['name'] ) ? ' rd-field--error' : ''; ?>">
			<label for="rd-voucher-name"><?php esc_html_e( 'Name', 'ruben-dance' ); ?> <span class="rd-field__required">*</span></label>
			<input type="text" id="rd-voucher-name" name="rd_voucher_name" required="required" autocomplete="name" value="<?php echo esc_attr( $submitted['name'] ?? '' ); ?>" <?php echo isset( $errors['name'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-voucher-name-error">
			<p class="rd-field__error" id="rd-voucher-name-error"><?php echo isset( $errors['name'] ) ? esc_html( Voucher_Page::error_message( $errors['name'] ) ) : ''; ?></p>
		</div>

		<div class="rd-field<?php echo isset( $errors['email'] ) ? ' rd-field--error' : ''; ?>">
			<label for="rd-voucher-email"><?php esc_html_e( 'Email', 'ruben-dance' ); ?> <span class="rd-field__required">*</span></label>
			<input type="email" id="rd-voucher-email" name="rd_voucher_email" required="required" autocomplete="email" value="<?php echo esc_attr( $submitted['email'] ?? '' ); ?>" <?php echo isset( $errors['email'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-voucher-email-error">
			<p class="rd-field__error" id="rd-voucher-email-error"><?php echo isset( $errors['email'] ) ? esc_html( Voucher_Page::error_message( $errors['email'] ) ) : ''; ?></p>
		</div>

		<div class="rd-field<?php echo isset( $errors['message'] ) ? ' rd-field--error' : ''; ?>">
			<label for="rd-voucher-message"><?php esc_html_e( 'Message', 'ruben-dance' ); ?></label>
			<textarea id="rd-voucher-message" name="rd_voucher_message" rows="4" required="required" <?php echo isset( $errors['message'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-voucher-message-error" placeholder="<?php esc_attr_e( 'Who is the voucher for, and for which course…', 'ruben-dance' ); ?>"><?php echo esc_textarea( $submitted['message'] ?? '' ); ?></textarea>
			<p class="rd-field__error" id="rd-voucher-message-error"><?php echo isset( $errors['message'] ) ? esc_html( Voucher_Page::error_message( $errors['message'] ) ) : ''; ?></p>
		</div>

		<button type="submit" class="rd-btn rd-btn--primary rd-vou-submit"><?php esc_html_e( 'Send inquiry', 'ruben-dance' ); ?></button>
	</form>
</div>
