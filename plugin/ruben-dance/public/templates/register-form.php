<?php
/**
 * `[rd_register]` template partial (form state; see register-success.php for
 * the post-submit state).
 *
 * Design/screens.html #3g (mobile 390 — white rd-card, jméno/příjmení
 * paired, email/telefon, heslo + password-strength meter, required T&C
 * checkbox + optional marketing checkbox, primary submit) / #4g (desktop
 * 1280 — same card, email/telefon also paired, centered in a wider column).
 *
 * Variables available: array<string,string> $errors, array<string,string>
 * $submitted, bool $rate_limited, string $privacy_policy_url, string $terms_url,
 * string $login_url.
 *
 * @package RubenDance
 */

use RubenDance\Front\Bot_Guard;
use RubenDance\Front\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-auth rd-auth--register">
	<h1 class="rd-h2 rd-auth-heading"><?php esc_html_e( 'Register', 'ruben-dance' ); ?></h1>

	<?php if ( $rate_limited || array() !== $errors ) : ?>
		<div class="rd-auth-alerts">
			<?php if ( $rate_limited ) : ?>
				<div class="rd-alert rd-alert--error">
					<strong class="rd-alert__icon">✕</strong>
					<span><?php esc_html_e( 'Too many registration attempts. Please try again later.', 'ruben-dance' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( array() !== $errors ) : ?>
				<div class="rd-alert rd-alert--error" role="alert" tabindex="-1" id="rd-register-errors">
					<strong class="rd-alert__icon">✕</strong>
					<span>
						<ul class="rd-auth-error-list">
							<?php foreach ( $errors as $ruben_dance_error_code ) : ?>
								<li><?php echo esc_html( Shortcodes::register_error_message( $ruben_dance_error_code ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					</span>
				</div>
				<script>document.getElementById( 'rd-register-errors' ).focus();</script>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post" class="rd-card rd-auth-card" id="rd-register-form">
		<?php wp_nonce_field( 'rd_register' ); ?>
		<input type="hidden" name="rd_auth_action" value="register">
		<?php echo Bot_Guard::fields_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bot_Guard::fields_html() already escapes everything it outputs. ?>

		<div class="rd-auth-row">
			<div class="rd-field<?php echo isset( $errors['first_name'] ) ? ' rd-field--error' : ''; ?>">
				<label for="rd-register-first-name"><?php esc_html_e( 'First name', 'ruben-dance' ); ?> <span class="rd-field__required">*</span></label>
				<input type="text" id="rd-register-first-name" name="first_name" required="required" autocomplete="given-name" value="<?php echo esc_attr( $submitted['first_name'] ?? '' ); ?>" <?php echo isset( $errors['first_name'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-first-name-error">
				<p id="rd-register-first-name-error" class="rd-field__error"><?php echo isset( $errors['first_name'] ) ? esc_html( Shortcodes::register_error_message( $errors['first_name'] ) ) : ''; ?></p>
			</div>

			<div class="rd-field<?php echo isset( $errors['last_name'] ) ? ' rd-field--error' : ''; ?>">
				<label for="rd-register-last-name"><?php esc_html_e( 'Last name', 'ruben-dance' ); ?> <span class="rd-field__required">*</span></label>
				<input type="text" id="rd-register-last-name" name="last_name" required="required" autocomplete="family-name" value="<?php echo esc_attr( $submitted['last_name'] ?? '' ); ?>" <?php echo isset( $errors['last_name'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-last-name-error">
				<p id="rd-register-last-name-error" class="rd-field__error"><?php echo isset( $errors['last_name'] ) ? esc_html( Shortcodes::register_error_message( $errors['last_name'] ) ) : ''; ?></p>
			</div>
		</div>

		<div class="rd-auth-row rd-auth-row--split">
			<div class="rd-field<?php echo isset( $errors['email'] ) ? ' rd-field--error' : ''; ?>">
				<label for="rd-register-email"><?php esc_html_e( 'Email', 'ruben-dance' ); ?> <span class="rd-field__required">*</span></label>
				<input type="email" id="rd-register-email" name="email" required="required" autocomplete="email" value="<?php echo esc_attr( $submitted['email'] ?? '' ); ?>" <?php echo isset( $errors['email'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-email-error">
				<p id="rd-register-email-error" class="rd-field__error"><?php echo isset( $errors['email'] ) ? esc_html( Shortcodes::register_error_message( $errors['email'] ) ) : ''; ?></p>
			</div>

			<div class="rd-field<?php echo isset( $errors['phone'] ) ? ' rd-field--error' : ''; ?>">
				<label for="rd-register-phone"><?php esc_html_e( 'Phone', 'ruben-dance' ); ?> <span class="rd-field__required">*</span></label>
				<input type="tel" id="rd-register-phone" name="phone" required="required" autocomplete="tel" value="<?php echo esc_attr( $submitted['phone'] ?? '' ); ?>" <?php echo isset( $errors['phone'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-phone-error">
				<p id="rd-register-phone-error" class="rd-field__error"><?php echo isset( $errors['phone'] ) ? esc_html( Shortcodes::register_error_message( $errors['phone'] ) ) : ''; ?></p>
			</div>
		</div>

		<div class="rd-field<?php echo isset( $errors['password'] ) ? ' rd-field--error' : ''; ?>">
			<label for="rd-register-password"><?php esc_html_e( 'Password', 'ruben-dance' ); ?> <span class="rd-field__required">*</span></label>
			<input type="password" id="rd-register-password" name="password" required="required" minlength="8" autocomplete="new-password" <?php echo isset( $errors['password'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-password-error">
			<div id="rd-register-password-strength" class="rd-password-strength">
				<span class="rd-password-strength__bars" aria-hidden="true">
					<span class="rd-password-strength__bar"></span>
					<span class="rd-password-strength__bar"></span>
					<span class="rd-password-strength__bar"></span>
					<span class="rd-password-strength__bar"></span>
				</span>
				<span class="rd-password-strength__label"></span>
			</div>
			<p id="rd-register-password-error" class="rd-field__error"><?php echo isset( $errors['password'] ) ? esc_html( Shortcodes::register_error_message( $errors['password'] ) ) : ''; ?></p>
		</div>

		<label class="rd-checkbox-row<?php echo isset( $errors['tc_accepted'] ) ? ' rd-auth-checkbox--error' : ''; ?>">
			<input class="rd-checkbox-row__input" type="checkbox" id="rd-register-tc" name="tc_accepted" value="1" required="required" <?php checked( ! empty( $submitted['tc_accepted'] ) ); ?> <?php echo isset( $errors['tc_accepted'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-tc-error">
			<span class="rd-checkbox-row__box" aria-hidden="true"></span>
			<span>
				<?php if ( '' !== $terms_url ) : ?>
					<?php
					printf(
						/* translators: %s: Terms & Conditions link. */
						esc_html__( 'I agree to the %s.', 'ruben-dance' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is escaped; the %s argument below is itself built from esc_url()/esc_html() pieces.
						'<a href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms & Conditions', 'ruben-dance' ) . '</a>'
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'I agree to the Terms & Conditions.', 'ruben-dance' ); ?>
				<?php endif; ?>
				<span class="rd-field__required">*</span>
				<span id="rd-register-tc-error" class="rd-field__error"><?php echo isset( $errors['tc_accepted'] ) ? esc_html( Shortcodes::register_error_message( $errors['tc_accepted'] ) ) : ''; ?></span>
				<br>
				<span class="rd-auth-hint">
					<?php if ( '' !== $privacy_policy_url ) : ?>
						<?php
						printf(
							/* translators: %s: privacy policy link. */
							esc_html__( 'Your personal data will be processed according to our %s.', 'ruben-dance' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is escaped; the %s argument below is itself built from esc_url()/esc_html() pieces.
							'<a href="' . esc_url( $privacy_policy_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'ruben-dance' ) . '</a>'
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Your personal data will be processed according to our privacy policy.', 'ruben-dance' ); ?>
					<?php endif; ?>
				</span>
			</span>
		</label>

		<label class="rd-checkbox-row">
			<input class="rd-checkbox-row__input" type="checkbox" name="marketing_consent" value="1" <?php checked( ! empty( $submitted['marketing_consent'] ) ); ?>>
			<span class="rd-checkbox-row__box" aria-hidden="true"></span>
			<span><?php esc_html_e( 'I would also like to receive occasional news and offers by email (optional).', 'ruben-dance' ); ?></span>
		</label>

		<button type="submit" class="rd-btn rd-btn--primary rd-auth-submit"><?php esc_html_e( 'Create account', 'ruben-dance' ); ?></button>
	</form>

	<p class="rd-auth-switch">
		<?php
		printf(
			/* translators: %s: login link. */
			esc_html__( 'Already have an account? %s', 'ruben-dance' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is escaped; the %s argument below is itself built from esc_url()/esc_html() pieces.
			'<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Log in', 'ruben-dance' ) . '</a>'
		);
		?>
	</p>
</div>
