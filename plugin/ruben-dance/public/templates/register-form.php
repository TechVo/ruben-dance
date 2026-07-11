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
<div class="rd-auth rd-auth--register">
	<?php if ( $rate_limited ) : ?>
		<div class="rd-notice rd-notice--error"><p><?php esc_html_e( 'Too many registration attempts. Please try again later.', 'ruben-dance' ); ?></p></div>
	<?php endif; ?>

	<?php if ( array() !== $errors ) : ?>
		<div class="rd-notice rd-notice--error">
			<ul>
				<?php foreach ( $errors as $ruben_dance_error_code ) : ?>
					<li><?php echo esc_html( Shortcodes::register_error_message( $ruben_dance_error_code ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="post" class="rd-auth-form" id="rd-register-form">
		<?php wp_nonce_field( 'rd_register' ); ?>
		<input type="hidden" name="rd_auth_action" value="register">
		<?php echo Bot_Guard::fields_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bot_Guard::fields_html() already escapes everything it outputs. ?>

		<p>
			<label for="rd-register-first-name"><?php esc_html_e( 'First name', 'ruben-dance' ); ?></label><br>
			<input type="text" id="rd-register-first-name" name="first_name" required="required" autocomplete="given-name" value="<?php echo esc_attr( $submitted['first_name'] ?? '' ); ?>">
		</p>

		<p>
			<label for="rd-register-last-name"><?php esc_html_e( 'Last name', 'ruben-dance' ); ?></label><br>
			<input type="text" id="rd-register-last-name" name="last_name" required="required" autocomplete="family-name" value="<?php echo esc_attr( $submitted['last_name'] ?? '' ); ?>">
		</p>

		<p>
			<label for="rd-register-email"><?php esc_html_e( 'Email', 'ruben-dance' ); ?></label><br>
			<input type="email" id="rd-register-email" name="email" required="required" autocomplete="email" value="<?php echo esc_attr( $submitted['email'] ?? '' ); ?>">
		</p>

		<p>
			<label for="rd-register-phone"><?php esc_html_e( 'Phone', 'ruben-dance' ); ?></label><br>
			<input type="tel" id="rd-register-phone" name="phone" required="required" autocomplete="tel" value="<?php echo esc_attr( $submitted['phone'] ?? '' ); ?>">
		</p>

		<p>
			<label for="rd-register-password"><?php esc_html_e( 'Password', 'ruben-dance' ); ?></label><br>
			<input type="password" id="rd-register-password" name="password" required="required" minlength="8" autocomplete="new-password">
			<div id="rd-register-password-strength" class="rd-password-strength"></div>
		</p>

		<p>
			<label>
				<input type="checkbox" name="tc_accepted" value="1" required="required" <?php checked( ! empty( $submitted['tc_accepted'] ) ); ?>>
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
