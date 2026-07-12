<?php
/**
 * `[rd_login]` template partial.
 *
 * Design/screens.html #3g (mobile 390 — white rd-card, email/heslo, error
 * alert, unverified-account warning alert) / #4g (desktop 1280 — same card
 * centered in a narrower column).
 *
 * Variables available: string $error, string $error_code, string
 * $submitted_email, string $notice, string $redirect_to, string $register_url,
 * string $lost_password_url.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

// `rd_account_unverified` (see Access_Restrictions::block_unverified_login())
// is styled as a warning rather than an error — it only ever fires once the
// password already matched, so unlike a generic "wrong credentials" it isn't
// hiding an account-enumeration risk, just prompting the customer to finish
// verifying (design/screens.html #3g's separate orange "Účet ještě není
// ověřený" alert vs. the red "Nesprávný e-mail nebo heslo" one).
$ruben_dance_unverified = 'rd_account_unverified' === $error_code;
?>
<div class="rd-app rd-auth rd-auth--login">
	<h1 class="rd-h2 rd-auth-heading"><?php esc_html_e( 'Log in', 'ruben-dance' ); ?></h1>

	<?php if ( '' !== $notice || '' !== $error ) : ?>
		<div class="rd-auth-alerts">
			<?php if ( 'ok' === $notice ) : ?>
				<div class="rd-alert rd-alert--success">
					<strong class="rd-alert__icon">✓</strong>
					<span><?php esc_html_e( 'Your email address has been verified. You can now log in.', 'ruben-dance' ); ?></span>
				</div>
			<?php elseif ( 'expired' === $notice ) : ?>
				<div class="rd-alert rd-alert--error">
					<strong class="rd-alert__icon">✕</strong>
					<span><?php esc_html_e( 'This verification link has expired. Please register again or contact us.', 'ruben-dance' ); ?></span>
				</div>
			<?php elseif ( 'invalid' === $notice ) : ?>
				<div class="rd-alert rd-alert--error">
					<strong class="rd-alert__icon">✕</strong>
					<span><?php esc_html_e( 'This verification link is invalid or has already been used.', 'ruben-dance' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<div class="rd-alert <?php echo $ruben_dance_unverified ? 'rd-alert--warning' : 'rd-alert--error'; ?>" role="alert" tabindex="-1" id="rd-login-error">
					<strong class="rd-alert__icon"><?php echo $ruben_dance_unverified ? '!' : '✕'; ?></strong>
					<span><?php echo esc_html( $error ); ?></span>
				</div>
				<script>document.getElementById( 'rd-login-error' ).focus();</script>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post" class="rd-card rd-auth-card">
		<?php wp_nonce_field( 'rd_login' ); ?>
		<input type="hidden" name="rd_auth_action" value="login">
		<?php if ( '' !== $redirect_to ) : ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
		<?php endif; ?>

		<div class="rd-field">
			<label for="rd-login-email"><?php esc_html_e( 'Email', 'ruben-dance' ); ?></label>
			<input type="email" id="rd-login-email" name="email" required="required" autocomplete="username" value="<?php echo esc_attr( $submitted_email ); ?>" <?php echo '' !== $error ? 'aria-invalid="true" aria-describedby="rd-login-error"' : ''; ?>>
		</div>

		<div class="rd-field">
			<label for="rd-login-password"><?php esc_html_e( 'Password', 'ruben-dance' ); ?></label>
			<input type="password" id="rd-login-password" name="password" required="required" autocomplete="current-password" <?php echo '' !== $error ? 'aria-invalid="true" aria-describedby="rd-login-error"' : ''; ?>>
		</div>

		<label class="rd-checkbox-row rd-auth-remember">
			<input class="rd-checkbox-row__input" type="checkbox" name="remember" value="1">
			<span class="rd-checkbox-row__box" aria-hidden="true"></span>
			<span><?php esc_html_e( 'Remember me', 'ruben-dance' ); ?></span>
		</label>

		<button type="submit" class="rd-btn rd-btn--primary rd-auth-submit"><?php esc_html_e( 'Log in', 'ruben-dance' ); ?></button>

		<div class="rd-auth-links">
			<a href="<?php echo esc_url( $lost_password_url ); ?>"><?php esc_html_e( 'Lost your password?', 'ruben-dance' ); ?></a>
			<a href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Create an account', 'ruben-dance' ); ?></a>
		</div>
	</form>
</div>
