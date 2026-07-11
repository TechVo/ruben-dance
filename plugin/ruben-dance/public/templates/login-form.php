<?php
/**
 * `[rd_login]` template partial.
 *
 * Variables available: string $error, string $submitted_email, string
 * $notice, string $redirect_to, string $register_url, string $lost_password_url.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-auth rd-auth--login">
	<?php if ( '' !== $notice ) : ?>
		<?php if ( 'ok' === $notice ) : ?>
			<div class="rd-notice rd-notice--success"><p><?php esc_html_e( 'Your email address has been verified. You can now log in.', 'ruben-dance' ); ?></p></div>
		<?php elseif ( 'expired' === $notice ) : ?>
			<div class="rd-notice rd-notice--error"><p><?php esc_html_e( 'This verification link has expired. Please register again or contact us.', 'ruben-dance' ); ?></p></div>
		<?php elseif ( 'invalid' === $notice ) : ?>
			<div class="rd-notice rd-notice--error"><p><?php esc_html_e( 'This verification link is invalid or has already been used.', 'ruben-dance' ); ?></p></div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( '' !== $error ) : ?>
		<div class="rd-notice rd-notice--error" role="alert" tabindex="-1" id="rd-login-error"><p><?php echo esc_html( $error ); ?></p></div>
		<script>document.getElementById( 'rd-login-error' ).focus();</script>
	<?php endif; ?>

	<form method="post" class="rd-auth-form">
		<?php wp_nonce_field( 'rd_login' ); ?>
		<input type="hidden" name="rd_auth_action" value="login">
		<?php if ( '' !== $redirect_to ) : ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
		<?php endif; ?>

		<p>
			<label for="rd-login-email"><?php esc_html_e( 'Email', 'ruben-dance' ); ?></label><br>
			<input type="email" id="rd-login-email" name="email" required="required" autocomplete="username" value="<?php echo esc_attr( $submitted_email ); ?>" <?php echo '' !== $error ? 'aria-invalid="true" aria-describedby="rd-login-error"' : ''; ?>>
		</p>

		<p>
			<label for="rd-login-password"><?php esc_html_e( 'Password', 'ruben-dance' ); ?></label><br>
			<input type="password" id="rd-login-password" name="password" required="required" autocomplete="current-password" <?php echo '' !== $error ? 'aria-invalid="true" aria-describedby="rd-login-error"' : ''; ?>>
		</p>

		<p>
			<label><input type="checkbox" name="remember" value="1"> <?php esc_html_e( 'Remember me', 'ruben-dance' ); ?></label>
		</p>

		<p><button type="submit"><?php esc_html_e( 'Log in', 'ruben-dance' ); ?></button></p>
	</form>

	<p>
		<a href="<?php echo esc_url( $lost_password_url ); ?>"><?php esc_html_e( 'Lost your password?', 'ruben-dance' ); ?></a>
		&middot;
		<a href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Create an account', 'ruben-dance' ); ?></a>
	</p>
</div>
