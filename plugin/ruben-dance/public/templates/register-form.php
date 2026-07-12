<?php
/**
 * `[rd_register]` template partial (form state; see register-success.php for
 * the post-submit state).
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
	<?php if ( $rate_limited ) : ?>
		<div class="rd-notice rd-notice--error"><p><?php esc_html_e( 'Too many registration attempts. Please try again later.', 'ruben-dance' ); ?></p></div>
	<?php endif; ?>

	<?php if ( array() !== $errors ) : ?>
		<div class="rd-notice rd-notice--error" role="alert" tabindex="-1" id="rd-register-errors">
			<ul>
				<?php foreach ( $errors as $ruben_dance_error_code ) : ?>
					<li><?php echo esc_html( Shortcodes::register_error_message( $ruben_dance_error_code ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<script>document.getElementById( 'rd-register-errors' ).focus();</script>
	<?php endif; ?>

	<form method="post" class="rd-auth-form" id="rd-register-form">
		<?php wp_nonce_field( 'rd_register' ); ?>
		<input type="hidden" name="rd_auth_action" value="register">
		<?php echo Bot_Guard::fields_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bot_Guard::fields_html() already escapes everything it outputs. ?>

		<p>
			<label for="rd-register-first-name"><?php esc_html_e( 'First name', 'ruben-dance' ); ?></label><br>
			<input type="text" id="rd-register-first-name" name="first_name" required="required" autocomplete="given-name" value="<?php echo esc_attr( $submitted['first_name'] ?? '' ); ?>" <?php echo isset( $errors['first_name'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-first-name-error">
			<span id="rd-register-first-name-error" class="rd-field-error">
				<?php echo isset( $errors['first_name'] ) ? esc_html( Shortcodes::register_error_message( $errors['first_name'] ) ) : ''; ?>
			</span>
		</p>

		<p>
			<label for="rd-register-last-name"><?php esc_html_e( 'Last name', 'ruben-dance' ); ?></label><br>
			<input type="text" id="rd-register-last-name" name="last_name" required="required" autocomplete="family-name" value="<?php echo esc_attr( $submitted['last_name'] ?? '' ); ?>" <?php echo isset( $errors['last_name'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-last-name-error">
			<span id="rd-register-last-name-error" class="rd-field-error">
				<?php echo isset( $errors['last_name'] ) ? esc_html( Shortcodes::register_error_message( $errors['last_name'] ) ) : ''; ?>
			</span>
		</p>

		<p>
			<label for="rd-register-email"><?php esc_html_e( 'Email', 'ruben-dance' ); ?></label><br>
			<input type="email" id="rd-register-email" name="email" required="required" autocomplete="email" value="<?php echo esc_attr( $submitted['email'] ?? '' ); ?>" <?php echo isset( $errors['email'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-email-error">
			<span id="rd-register-email-error" class="rd-field-error">
				<?php echo isset( $errors['email'] ) ? esc_html( Shortcodes::register_error_message( $errors['email'] ) ) : ''; ?>
			</span>
		</p>

		<p>
			<label for="rd-register-phone"><?php esc_html_e( 'Phone', 'ruben-dance' ); ?></label><br>
			<input type="tel" id="rd-register-phone" name="phone" required="required" autocomplete="tel" value="<?php echo esc_attr( $submitted['phone'] ?? '' ); ?>" <?php echo isset( $errors['phone'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-phone-error">
			<span id="rd-register-phone-error" class="rd-field-error">
				<?php echo isset( $errors['phone'] ) ? esc_html( Shortcodes::register_error_message( $errors['phone'] ) ) : ''; ?>
			</span>
		</p>

		<p>
			<label for="rd-register-password"><?php esc_html_e( 'Password', 'ruben-dance' ); ?></label><br>
			<input type="password" id="rd-register-password" name="password" required="required" minlength="8" autocomplete="new-password" <?php echo isset( $errors['password'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-password-error">
			<div id="rd-register-password-strength" class="rd-password-strength"></div>
			<span id="rd-register-password-error" class="rd-field-error">
				<?php echo isset( $errors['password'] ) ? esc_html( Shortcodes::register_error_message( $errors['password'] ) ) : ''; ?>
			</span>
		</p>

		<p>
			<label for="rd-register-tc">
				<input type="checkbox" id="rd-register-tc" name="tc_accepted" value="1" required="required" <?php checked( ! empty( $submitted['tc_accepted'] ) ); ?> <?php echo isset( $errors['tc_accepted'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-register-tc-error">
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
				<span class="required">*</span>
			</label>
			<span id="rd-register-tc-error" class="rd-field-error">
				<?php echo isset( $errors['tc_accepted'] ) ? esc_html( Shortcodes::register_error_message( $errors['tc_accepted'] ) ) : ''; ?>
			</span>
			<br>
			<small>
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
			</small>
		</p>

		<p>
			<label>
				<input type="checkbox" name="marketing_consent" value="1" <?php checked( ! empty( $submitted['marketing_consent'] ) ); ?>>
				<?php esc_html_e( 'I would also like to receive occasional news and offers by email (optional).', 'ruben-dance' ); ?>
			</label>
		</p>

		<p><button type="submit"><?php esc_html_e( 'Create account', 'ruben-dance' ); ?></button></p>
	</form>

	<p>
		<?php
		printf(
			/* translators: %s: login link. */
			esc_html__( 'Already have an account? %s', 'ruben-dance' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is escaped; the %s argument below is itself built from esc_url()/esc_html() pieces.
			'<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Log in', 'ruben-dance' ) . '</a>'
		);
		?>
	</p>
</div>
