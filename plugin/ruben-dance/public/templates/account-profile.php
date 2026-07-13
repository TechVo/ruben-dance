<?php
/**
 * `[rd_account]` "Profile" tab (spec F7): name/phone/preferred language,
 * password, email change (re-verified before it takes effect), marketing
 * consent toggle. Design/screens.html #3h: one white settings card holding
 * every field; the 4 underlying `<form>`s (personal details, email change,
 * password, marketing consent) are unchanged from pre-D7 — same field names,
 * nonces, actions — this only nests them inside a shared card and restyles
 * each control. The password form is tucked behind a "Change password →"
 * `<details>` disclosure (the same no-JS pattern `catalog.php`'s filter panel
 * uses) to match the mock's single link where pre-D7 showed the fields
 * inline; nothing about the form itself changes, only its default
 * visibility.
 *
 * Variables available (inherited from account.php's shared scope):
 * array<string,mixed> $profile, string $email_notice, bool $has_enrollments,
 * string $catalog_url.
 *
 * @package RubenDance
 */

use RubenDance\Front\Account_Form_Handler;
use RubenDance\Front\Account_Page;
use RubenDance\Lang;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

$ruben_dance_profile_result = Account_Form_Handler::$profile_result ?? array(
	'state'     => 'form',
	'errors'    => array(),
	'submitted' => array(),
);

$ruben_dance_password_result = Account_Form_Handler::$password_result ?? array(
	'state'  => 'form',
	'errors' => array(),
);

$ruben_dance_email_result = Account_Form_Handler::$email_result ?? array(
	'state'     => 'form',
	'errors'    => array(),
	'submitted' => array(),
);

$ruben_dance_consent_result = Account_Form_Handler::$consent_result;

$ruben_dance_first_name = (string) ( $ruben_dance_profile_result['submitted']['first_name'] ?? $profile['first_name'] );
$ruben_dance_last_name  = (string) ( $ruben_dance_profile_result['submitted']['last_name'] ?? $profile['last_name'] );
$ruben_dance_phone      = (string) ( $ruben_dance_profile_result['submitted']['phone'] ?? $profile['phone'] );
$ruben_dance_locale     = (string) ( $ruben_dance_profile_result['submitted']['locale'] ?? $profile['locale'] );

// The password disclosure opens by itself when its own submission had
// errors (or, moot but harmless, succeeded) — otherwise a validation error
// would render inside a `<details>` the customer never opened.
$ruben_dance_password_open = 'form' !== $ruben_dance_password_result['state'] || array() !== $ruben_dance_password_result['errors'];
?>
<div class="rd-acc-profile">

	<?php if ( '' !== $email_notice ) : ?>
		<?php if ( 'ok' === $email_notice ) : ?>
			<div class="rd-alert rd-alert--success"><strong class="rd-alert__icon">✓</strong><span><?php esc_html_e( 'Your new email address has been confirmed.', 'ruben-dance' ); ?></span></div>
		<?php elseif ( 'expired' === $email_notice ) : ?>
			<div class="rd-alert rd-alert--error"><strong class="rd-alert__icon">✕</strong><span><?php esc_html_e( 'That confirmation link has expired. Please request the email change again.', 'ruben-dance' ); ?></span></div>
		<?php elseif ( 'taken' === $email_notice ) : ?>
			<div class="rd-alert rd-alert--error"><strong class="rd-alert__icon">✕</strong><span><?php esc_html_e( 'That email address was taken by another account in the meantime. Please request the change again with a different address.', 'ruben-dance' ); ?></span></div>
		<?php elseif ( 'invalid' === $email_notice ) : ?>
			<div class="rd-alert rd-alert--error"><strong class="rd-alert__icon">✕</strong><span><?php esc_html_e( 'That confirmation link is invalid or has already been used.', 'ruben-dance' ); ?></span></div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="rd-card rd-acc-profile-card">

		<section class="rd-acc-profile-section">
			<span class="rd-eyebrow rd-acc-profile-section__label"><?php esc_html_e( 'Personal details', 'ruben-dance' ); ?></span>

			<?php if ( 'success' === $ruben_dance_profile_result['state'] ) : ?>
				<div class="rd-alert rd-alert--success"><strong class="rd-alert__icon">✓</strong><span><?php esc_html_e( 'Your details have been updated.', 'ruben-dance' ); ?></span></div>
			<?php elseif ( array() !== $ruben_dance_profile_result['errors'] ) : ?>
				<div class="rd-alert rd-alert--error" role="alert" tabindex="-1" id="rd-account-profile-errors">
					<strong class="rd-alert__icon">✕</strong>
					<span>
						<?php foreach ( $ruben_dance_profile_result['errors'] as $ruben_dance_code ) : ?>
							<?php echo esc_html( Account_Page::error_message( $ruben_dance_code ) ); ?><br>
						<?php endforeach; ?>
					</span>
				</div>
				<script>document.getElementById( 'rd-account-profile-errors' ).focus();</script>
			<?php endif; ?>

			<form method="post" class="rd-acc-form">
				<?php wp_nonce_field( 'rd_account_profile' ); ?>
				<input type="hidden" name="rd_account_action" value="update_profile">

				<div class="rd-acc-form__row">
					<div class="rd-field<?php echo isset( $ruben_dance_profile_result['errors']['first_name'] ) ? ' rd-field--error' : ''; ?>">
						<label for="rd-account-first-name"><?php esc_html_e( 'First name', 'ruben-dance' ); ?></label>
						<input type="text" id="rd-account-first-name" name="first_name" required="required" autocomplete="given-name" value="<?php echo esc_attr( $ruben_dance_first_name ); ?>" <?php echo isset( $ruben_dance_profile_result['errors']['first_name'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-account-first-name-error">
						<p id="rd-account-first-name-error" class="rd-field__error"><?php echo isset( $ruben_dance_profile_result['errors']['first_name'] ) ? esc_html( Account_Page::error_message( $ruben_dance_profile_result['errors']['first_name'] ) ) : ''; ?></p>
					</div>
					<div class="rd-field<?php echo isset( $ruben_dance_profile_result['errors']['last_name'] ) ? ' rd-field--error' : ''; ?>">
						<label for="rd-account-last-name"><?php esc_html_e( 'Last name', 'ruben-dance' ); ?></label>
						<input type="text" id="rd-account-last-name" name="last_name" required="required" autocomplete="family-name" value="<?php echo esc_attr( $ruben_dance_last_name ); ?>" <?php echo isset( $ruben_dance_profile_result['errors']['last_name'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-account-last-name-error">
						<p id="rd-account-last-name-error" class="rd-field__error"><?php echo isset( $ruben_dance_profile_result['errors']['last_name'] ) ? esc_html( Account_Page::error_message( $ruben_dance_profile_result['errors']['last_name'] ) ) : ''; ?></p>
					</div>
				</div>

				<div class="rd-field<?php echo isset( $ruben_dance_profile_result['errors']['phone'] ) ? ' rd-field--error' : ''; ?>">
					<label for="rd-account-phone"><?php esc_html_e( 'Phone', 'ruben-dance' ); ?></label>
					<input type="tel" id="rd-account-phone" name="phone" required="required" autocomplete="tel" value="<?php echo esc_attr( $ruben_dance_phone ); ?>" <?php echo isset( $ruben_dance_profile_result['errors']['phone'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-account-phone-error">
					<p id="rd-account-phone-error" class="rd-field__error"><?php echo isset( $ruben_dance_profile_result['errors']['phone'] ) ? esc_html( Account_Page::error_message( $ruben_dance_profile_result['errors']['phone'] ) ) : ''; ?></p>
				</div>

				<div class="rd-field">
					<label id="rd-account-locale-label"><?php esc_html_e( 'Preferred language', 'ruben-dance' ); ?></label>
					<div class="rd-acc-lang-toggle" role="radiogroup" aria-labelledby="rd-account-locale-label">
						<label class="rd-acc-lang-toggle__option">
							<input type="radio" class="rd-acc-lang-toggle__input" name="locale" value="<?php echo esc_attr( Lang::CS ); ?>" <?php checked( $ruben_dance_locale, Lang::CS ); ?>>
							<span class="rd-acc-lang-toggle__pill"><?php esc_html_e( 'Czech', 'ruben-dance' ); ?></span>
						</label>
						<label class="rd-acc-lang-toggle__option">
							<input type="radio" class="rd-acc-lang-toggle__input" name="locale" value="<?php echo esc_attr( Lang::EN ); ?>" <?php checked( $ruben_dance_locale, Lang::EN ); ?>>
							<span class="rd-acc-lang-toggle__pill"><?php esc_html_e( 'English', 'ruben-dance' ); ?></span>
						</label>
					</div>
					<?php if ( isset( $ruben_dance_profile_result['errors']['locale'] ) ) : ?>
						<p class="rd-field__error"><?php echo esc_html( Account_Page::error_message( $ruben_dance_profile_result['errors']['locale'] ) ); ?></p>
					<?php endif; ?>
				</div>

				<button type="submit" class="rd-btn rd-acc-save-btn"><?php esc_html_e( 'Save changes', 'ruben-dance' ); ?></button>
			</form>
		</section>

		<section class="rd-acc-profile-section">
			<span class="rd-eyebrow rd-acc-profile-section__label"><?php esc_html_e( 'Email address', 'ruben-dance' ); ?></span>

			<div class="rd-field">
				<label for="rd-account-current-email"><?php esc_html_e( 'Email', 'ruben-dance' ); ?></label>
				<div id="rd-account-current-email" class="rd-acc-profile-static"><?php echo esc_html( $profile['email'] ); ?></div>
			</div>

			<?php if ( '' !== $profile['pending_email'] ) : ?>
				<div class="rd-alert rd-alert--warning rd-acc-email-pending">
					<strong class="rd-alert__icon">!</strong>
					<span>
						<?php
						printf(
							/* translators: %s: pending new email address. */
							esc_html__( 'A change to %s is pending — check that inbox for a confirmation link.', 'ruben-dance' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is escaped; the %s argument is itself esc_html()'d below.
							'<strong>' . esc_html( $profile['pending_email'] ) . '</strong>'
						);
						?>
						<a href="#rd-account-new-email" class="rd-acc-email-pending__resend"><?php esc_html_e( 'Resend', 'ruben-dance' ); ?></a>
					</span>
				</div>
			<?php endif; ?>

			<?php if ( 'requested' === $ruben_dance_email_result['state'] ) : ?>
				<div class="rd-alert rd-alert--success"><strong class="rd-alert__icon">✓</strong><span><?php esc_html_e( 'Please check the new address\'s inbox and click the confirmation link — the change only takes effect once confirmed.', 'ruben-dance' ); ?></span></div>
			<?php elseif ( array() !== $ruben_dance_email_result['errors'] ) : ?>
				<div class="rd-alert rd-alert--error" role="alert" tabindex="-1" id="rd-account-email-errors">
					<strong class="rd-alert__icon">✕</strong>
					<span>
						<?php foreach ( $ruben_dance_email_result['errors'] as $ruben_dance_code ) : ?>
							<?php echo esc_html( Account_Page::error_message( $ruben_dance_code ) ); ?><br>
						<?php endforeach; ?>
					</span>
				</div>
				<script>document.getElementById( 'rd-account-email-errors' ).focus();</script>
			<?php endif; ?>

			<form method="post" class="rd-acc-form">
				<?php wp_nonce_field( 'rd_account_email_change' ); ?>
				<input type="hidden" name="rd_account_action" value="request_email_change">
				<div class="rd-field<?php echo isset( $ruben_dance_email_result['errors']['new_email'] ) ? ' rd-field--error' : ''; ?>">
					<label for="rd-account-new-email"><?php esc_html_e( 'New email address', 'ruben-dance' ); ?></label>
					<input type="email" id="rd-account-new-email" name="new_email" required="required" value="<?php echo esc_attr( (string) ( $ruben_dance_email_result['submitted']['new_email'] ?? '' ) ); ?>" <?php echo isset( $ruben_dance_email_result['errors']['new_email'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-account-new-email-error">
					<p id="rd-account-new-email-error" class="rd-field__error"><?php echo isset( $ruben_dance_email_result['errors']['new_email'] ) ? esc_html( Account_Page::error_message( $ruben_dance_email_result['errors']['new_email'] ) ) : ''; ?></p>
				</div>
				<button type="submit" class="rd-btn rd-btn--secondary"><?php esc_html_e( 'Request email change', 'ruben-dance' ); ?></button>
			</form>
		</section>

		<details class="rd-acc-password"<?php echo $ruben_dance_password_open ? ' open' : ''; ?>>
			<summary class="rd-acc-password__toggle"><?php esc_html_e( 'Change password', 'ruben-dance' ); ?> →</summary>

			<?php if ( 'success' === $ruben_dance_password_result['state'] ) : ?>
				<div class="rd-alert rd-alert--success"><strong class="rd-alert__icon">✓</strong><span><?php esc_html_e( 'Your password has been changed.', 'ruben-dance' ); ?></span></div>
			<?php elseif ( array() !== $ruben_dance_password_result['errors'] ) : ?>
				<div class="rd-alert rd-alert--error" role="alert" tabindex="-1" id="rd-account-password-errors">
					<strong class="rd-alert__icon">✕</strong>
					<span>
						<?php foreach ( $ruben_dance_password_result['errors'] as $ruben_dance_code ) : ?>
							<?php echo esc_html( Account_Page::error_message( $ruben_dance_code ) ); ?><br>
						<?php endforeach; ?>
					</span>
				</div>
				<script>document.getElementById( 'rd-account-password-errors' ).focus();</script>
			<?php endif; ?>

			<form method="post" class="rd-acc-form">
				<?php wp_nonce_field( 'rd_account_password' ); ?>
				<input type="hidden" name="rd_account_action" value="update_password">
				<div class="rd-field<?php echo isset( $ruben_dance_password_result['errors']['new_password'] ) ? ' rd-field--error' : ''; ?>">
					<label for="rd-account-new-password"><?php esc_html_e( 'New password', 'ruben-dance' ); ?></label>
					<input type="password" id="rd-account-new-password" name="new_password" required="required" minlength="8" autocomplete="new-password" <?php echo isset( $ruben_dance_password_result['errors']['new_password'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-account-new-password-error">
					<p id="rd-account-new-password-error" class="rd-field__error"><?php echo isset( $ruben_dance_password_result['errors']['new_password'] ) ? esc_html( Account_Page::error_message( $ruben_dance_password_result['errors']['new_password'] ) ) : ''; ?></p>
				</div>
				<div class="rd-field<?php echo isset( $ruben_dance_password_result['errors']['new_password_confirm'] ) ? ' rd-field--error' : ''; ?>">
					<label for="rd-account-new-password-confirm"><?php esc_html_e( 'Confirm new password', 'ruben-dance' ); ?></label>
					<input type="password" id="rd-account-new-password-confirm" name="new_password_confirm" required="required" minlength="8" autocomplete="new-password" <?php echo isset( $ruben_dance_password_result['errors']['new_password_confirm'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-account-new-password-confirm-error">
					<p id="rd-account-new-password-confirm-error" class="rd-field__error"><?php echo isset( $ruben_dance_password_result['errors']['new_password_confirm'] ) ? esc_html( Account_Page::error_message( $ruben_dance_password_result['errors']['new_password_confirm'] ) ) : ''; ?></p>
				</div>
				<button type="submit" class="rd-btn rd-btn--secondary"><?php esc_html_e( 'Change password', 'ruben-dance' ); ?></button>
			</form>
		</details>

		<section class="rd-acc-profile-section rd-acc-profile-section--marketing">
			<?php if ( null !== $ruben_dance_consent_result && 'success' === $ruben_dance_consent_result['state'] ) : ?>
				<div class="rd-alert rd-alert--success"><strong class="rd-alert__icon">✓</strong><span><?php esc_html_e( 'Your preference has been saved.', 'ruben-dance' ); ?></span></div>
			<?php endif; ?>

			<form method="post" class="rd-acc-form rd-acc-marketing-form">
				<?php wp_nonce_field( 'rd_account_marketing_consent' ); ?>
				<input type="hidden" name="rd_account_action" value="toggle_marketing_consent">
				<label class="rd-acc-switch">
					<input type="checkbox" class="rd-acc-switch__input" name="marketing_consent" value="1" <?php checked( $profile['marketing_consent'] ); ?>>
					<span class="rd-acc-switch__track" aria-hidden="true"><span class="rd-acc-switch__thumb"></span></span>
					<span class="rd-acc-switch__label"><?php esc_html_e( 'I would like to receive occasional news and offers by email.', 'ruben-dance' ); ?></span>
				</label>
				<button type="submit" class="rd-btn rd-btn--small"><?php esc_html_e( 'Save preference', 'ruben-dance' ); ?></button>
			</form>
		</section>
	</div>

	<?php if ( ! $has_enrollments ) : ?>
		<div class="rd-empty-state rd-acc-profile-empty">
			<p class="rd-empty-state__text"><?php esc_html_e( 'You have no enrollments yet.', 'ruben-dance' ); ?></p>
			<a class="rd-empty-state__action" href="<?php echo esc_url( $catalog_url ); ?>"><?php esc_html_e( 'Browse courses', 'ruben-dance' ); ?> →</a>
		</div>
	<?php endif; ?>
</div>
