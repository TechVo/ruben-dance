<?php
/**
 * `[rd_voucher_inquiry]` template partial: the inquiry form itself (spec
 * F17/§4.6).
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
<div class="rd-app rd-voucher-inquiry">
	<?php if ( $rate_limited ) : ?>
		<div class="rd-notice rd-notice--error" role="alert" tabindex="-1" id="rd-voucher-rate-limited">
			<p><?php esc_html_e( 'Too many attempts. Please try again later.', 'ruben-dance' ); ?></p>
		</div>
		<script>document.getElementById( 'rd-voucher-rate-limited' ).focus();</script>
	<?php elseif ( array() !== $errors ) : ?>
		<div class="rd-notice rd-notice--error" role="alert" tabindex="-1" id="rd-voucher-errors">
			<ul>
				<?php foreach ( $errors as $ruben_dance_error_code ) : ?>
					<li><?php echo esc_html( Voucher_Page::error_message( $ruben_dance_error_code ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<script>document.getElementById( 'rd-voucher-errors' ).focus();</script>
	<?php endif; ?>

	<form method="post" class="rd-auth-form" id="rd-voucher-form">
		<?php wp_nonce_field( Voucher_Form_Handler::NONCE_ACTION ); ?>
		<input type="hidden" name="rd_voucher_action" value="submit">
		<?php echo Bot_Guard::fields_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bot_Guard::fields_html() already escapes everything it outputs. ?>

		<?php // POST field names are rd_voucher_*-prefixed: a bare `name` is a WP public query var and 404s the whole POST — see Voucher_Form_Handler::sanitized_submission(). ?>
		<p>
			<label for="rd-voucher-name"><?php esc_html_e( 'Name', 'ruben-dance' ); ?></label><br>
			<input type="text" id="rd-voucher-name" name="rd_voucher_name" required="required" autocomplete="name" value="<?php echo esc_attr( $submitted['name'] ?? '' ); ?>" <?php echo isset( $errors['name'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-voucher-name-error">
			<span id="rd-voucher-name-error" class="rd-field-error">
				<?php echo isset( $errors['name'] ) ? esc_html( Voucher_Page::error_message( $errors['name'] ) ) : ''; ?>
			</span>
		</p>

		<p>
			<label for="rd-voucher-email"><?php esc_html_e( 'Email', 'ruben-dance' ); ?></label><br>
			<input type="email" id="rd-voucher-email" name="rd_voucher_email" required="required" autocomplete="email" value="<?php echo esc_attr( $submitted['email'] ?? '' ); ?>" <?php echo isset( $errors['email'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-voucher-email-error">
			<span id="rd-voucher-email-error" class="rd-field-error">
				<?php echo isset( $errors['email'] ) ? esc_html( Voucher_Page::error_message( $errors['email'] ) ) : ''; ?>
			</span>
		</p>

		<p>
			<label for="rd-voucher-message"><?php esc_html_e( 'Message', 'ruben-dance' ); ?></label><br>
			<textarea id="rd-voucher-message" name="rd_voucher_message" rows="4" required="required" <?php echo isset( $errors['message'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-voucher-message-error"><?php echo esc_textarea( $submitted['message'] ?? '' ); ?></textarea>
			<span id="rd-voucher-message-error" class="rd-field-error">
				<?php echo isset( $errors['message'] ) ? esc_html( Voucher_Page::error_message( $errors['message'] ) ) : ''; ?>
			</span>
		</p>

		<p><button type="submit"><?php esc_html_e( 'Send inquiry', 'ruben-dance' ); ?></button></p>
	</form>
</div>
