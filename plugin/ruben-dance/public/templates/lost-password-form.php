<?php
/**
 * `[rd_lost_password]` template partial — covers every state of the
 * request-a-link / set-a-new-password two-step flow.
 *
 * Variables available: string $state ('request'|'request_done'|'reset'|
 * 'reset_done'|'invalid_key'|'rate_limited'), array<string,string> $errors,
 * string $submitted_email, string $key, string $login.
 *
 * @package RubenDance
 */

use RubenDance\Services\Registration_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-auth rd-auth--lost-password">
	<?php if ( 'request_done' === $state ) : ?>
		<div class="rd-notice rd-notice--success">
			<p><?php esc_html_e( 'If an account exists for that email address, a password reset link has been sent.', 'ruben-dance' ); ?></p>
		</div>

	<?php elseif ( 'reset_done' === $state ) : ?>
		<div class="rd-notice rd-notice--success">
			<p><?php esc_html_e( 'Your password has been changed. You can now log in with your new password.', 'ruben-dance' ); ?></p>
		</div>

	<?php elseif ( 'invalid_key' === $state ) : ?>
		<div class="rd-notice rd-notice--error">
			<p><?php esc_html_e( 'This password reset link is invalid or has expired. Please request a new one.', 'ruben-dance' ); ?></p>
		</div>

	<?php elseif ( 'rate_limited' === $state ) : ?>
		<div class="rd-notice rd-notice--error">
			<p><?php esc_html_e( 'Too many attempts. Please try again later.', 'ruben-dance' ); ?></p>
		</div>

	<?php elseif ( 'reset' === $state ) : ?>
		<?php if ( array() !== $errors ) : ?>
			<div class="rd-notice rd-notice--error" role="alert" tabindex="-1" id="rd-reset-password-errors">
				<p>
					<?php
					echo esc_html(
						Registration_Service::ERROR_PASSWORD_TOO_SHORT === ( $errors['password'] ?? '' )
							? __( 'Password must be at least 8 characters long.', 'ruben-dance' )
							: __( 'Please check the highlighted fields.', 'ruben-dance' )
					);
					?>
				</p>
			</div>
			<script>document.getElementById( 'rd-reset-password-errors' ).focus();</script>
		<?php endif; ?>

		<form method="post" class="rd-auth-form">
			<?php wp_nonce_field( 'rd_reset_password' ); ?>
			<input type="hidden" name="rd_auth_action" value="reset_password">
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">

			<p>
				<label for="rd-reset-password"><?php esc_html_e( 'New password', 'ruben-dance' ); ?></label><br>
				<input type="password" id="rd-reset-password" name="password" required="required" minlength="8" autocomplete="new-password" <?php echo isset( $errors['password'] ) ? 'aria-invalid="true" aria-describedby="rd-reset-password-errors"' : ''; ?>>
			</p>

			<p><button type="submit"><?php esc_html_e( 'Set new password', 'ruben-dance' ); ?></button></p>
		</form>

	<?php else : /* 'request' */ ?>
		<form method="post" class="rd-auth-form">
			<?php wp_nonce_field( 'rd_lost_password_request' ); ?>
			<input type="hidden" name="rd_auth_action" value="lost_password_request">

			<p>
				<label for="rd-lost-password-email"><?php esc_html_e( 'Email', 'ruben-dance' ); ?></label><br>
				<input type="email" id="rd-lost-password-email" name="email" required="required" autocomplete="email" value="<?php echo esc_attr( $submitted_email ); ?>">
			</p>

			<p><button type="submit"><?php esc_html_e( 'Send reset link', 'ruben-dance' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
