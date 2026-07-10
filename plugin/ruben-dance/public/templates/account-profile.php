<?php
/**
 * `[rd_account]` "Profile" tab (spec F7): name/phone/preferred language,
 * password, email change (re-verified before it takes effect), marketing
 * consent toggle.
 *
 * Variables available (inherited from account.php's shared scope):
 * array<string,mixed> $profile, string $email_notice.
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
?>
<div class="rd-account-profile">

	<?php if ( '' !== $email_notice ) : ?>
		<?php if ( 'ok' === $email_notice ) : ?>
			<div class="rd-notice rd-notice--success"><p><?php esc_html_e( 'Your new email address has been confirmed.', 'ruben-dance' ); ?></p></div>
		<?php elseif ( 'expired' === $email_notice ) : ?>
			<div class="rd-notice rd-notice--error"><p><?php esc_html_e( 'That confirmation link has expired. Please request the email change again.', 'ruben-dance' ); ?></p></div>
		<?php elseif ( 'taken' === $email_notice ) : ?>
			<div class="rd-notice rd-notice--error"><p><?php esc_html_e( 'That email address was taken by another account in the meantime. Please request the change again with a different address.', 'ruben-dance' ); ?></p></div>
		<?php elseif ( 'invalid' === $email_notice ) : ?>
			<div class="rd-notice rd-notice--error"><p><?php esc_html_e( 'That confirmation link is invalid or has already been used.', 'ruben-dance' ); ?></p></div>
		<?php endif; ?>
	<?php endif; ?>

	<section class="rd-account-profile-section">
		<h3><?php esc_html_e( 'Personal details', 'ruben-dance' ); ?></h3>

		<?php if ( 'success' === $ruben_dance_profile_result['state'] ) : ?>
			<div class="rd-notice rd-notice--success"><p><?php esc_html_e( 'Your details have been updated.', 'ruben-dance' ); ?></p></div>
		<?php elseif ( array() !== $ruben_dance_profile_result['errors'] ) : ?>
			<div class="rd-notice rd-notice--error">
				<ul>
					<?php foreach ( $ruben_dance_profile_result['errors'] as $ruben_dance_code ) : ?>
						<li><?php echo esc_html( Account_Page::error_message( $ruben_dance_code ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" class="rd-account-form">
			<?php wp_nonce_field( 'rd_account_profile' ); ?>
			<input type="hidden" name="rd_account_action" value="update_profile">

			<p>
				<label for="rd-account-first-name"><?php esc_html_e( 'First name', 'ruben-dance' ); ?></label><br>
				<input type="text" id="rd-account-first-name" name="first_name" required="required" autocomplete="given-name" value="<?php echo esc_attr( $ruben_dance_first_name ); ?>">
			</p>
			<p>
				<label for="rd-account-last-name"><?php esc_html_e( 'Last name', 'ruben-dance' ); ?></label><br>
				<input type="text" id="rd-account-last-name" name="last_name" required="required" autocomplete="family-name" value="<?php echo esc_attr( $ruben_dance_last_name ); ?>">
			</p>
			<p>
				<label for="rd-account-phone"><?php esc_html_e( 'Phone', 'ruben-dance' ); ?></label><br>
				<input type="tel" id="rd-account-phone" name="phone" required="required" autocomplete="tel" value="<?php echo esc_attr( $ruben_dance_phone ); ?>">
			</p>
			<p>
				<label for="rd-account-locale"><?php esc_html_e( 'Preferred language', 'ruben-dance' ); ?></label><br>
				<select id="rd-account-locale" name="locale">
					<option value="<?php echo esc_attr( Lang::CS ); ?>" <?php selected( $ruben_dance_locale, Lang::CS ); ?>><?php esc_html_e( 'Czech', 'ruben-dance' ); ?></option>
					<option value="<?php echo esc_attr( Lang::EN ); ?>" <?php selected( $ruben_dance_locale, Lang::EN ); ?>><?php esc_html_e( 'English', 'ruben-dance' ); ?></option>
				</select>
			</p>

			<p><button type="submit"><?php esc_html_e( 'Save changes', 'ruben-dance' ); ?></button></p>
		</form>
	</section>

	<section class="rd-account-profile-section">
		<h3><?php esc_html_e( 'Email address', 'ruben-dance' ); ?></h3>
		<p><?php esc_html_e( 'Current email:', 'ruben-dance' ); ?> <strong><?php echo esc_html( $profile['email'] ); ?></strong></p>

		<?php if ( '' !== $profile['pending_email'] ) : ?>
			<div class="rd-notice">
				<p>
					<?php
					printf(
						/* translators: %s: pending new email address. */
						esc_html__( 'A change to %s is pending — check that inbox for a confirmation link.', 'ruben-dance' ),
						esc_html( $profile['pending_email'] )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( 'requested' === $ruben_dance_email_result['state'] ) : ?>
			<div class="rd-notice rd-notice--success"><p><?php esc_html_e( 'Please check the new address\'s inbox and click the confirmation link — the change only takes effect once confirmed.', 'ruben-dance' ); ?></p></div>
		<?php elseif ( array() !== $ruben_dance_email_result['errors'] ) : ?>
			<div class="rd-notice rd-notice--error">
				<ul>
					<?php foreach ( $ruben_dance_email_result['errors'] as $ruben_dance_code ) : ?>
						<li><?php echo esc_html( Account_Page::error_message( $ruben_dance_code ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" class="rd-account-form">
			<?php wp_nonce_field( 'rd_account_email_change' ); ?>
			<input type="hidden" name="rd_account_action" value="request_email_change">
			<p>
				<label for="rd-account-new-email"><?php esc_html_e( 'New email address', 'ruben-dance' ); ?></label><br>
				<input type="email" id="rd-account-new-email" name="new_email" required="required" value="<?php echo esc_attr( (string) ( $ruben_dance_email_result['submitted']['new_email'] ?? '' ) ); ?>">
			</p>
			<p><button type="submit"><?php esc_html_e( 'Request email change', 'ruben-dance' ); ?></button></p>
		</form>
	</section>

	<section class="rd-account-profile-section">
		<h3><?php esc_html_e( 'Password', 'ruben-dance' ); ?></h3>

		<?php if ( 'success' === $ruben_dance_password_result['state'] ) : ?>
			<div class="rd-notice rd-notice--success"><p><?php esc_html_e( 'Your password has been changed.', 'ruben-dance' ); ?></p></div>
		<?php elseif ( array() !== $ruben_dance_password_result['errors'] ) : ?>
			<div class="rd-notice rd-notice--error">
				<ul>
					<?php foreach ( $ruben_dance_password_result['errors'] as $ruben_dance_code ) : ?>
						<li><?php echo esc_html( Account_Page::error_message( $ruben_dance_code ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" class="rd-account-form">
			<?php wp_nonce_field( 'rd_account_password' ); ?>
			<input type="hidden" name="rd_account_action" value="update_password">
			<p>
				<label for="rd-account-new-password"><?php esc_html_e( 'New password', 'ruben-dance' ); ?></label><br>
				<input type="password" id="rd-account-new-password" name="new_password" required="required" minlength="8" autocomplete="new-password">
			</p>
			<p>
				<label for="rd-account-new-password-confirm"><?php esc_html_e( 'Confirm new password', 'ruben-dance' ); ?></label><br>
				<input type="password" id="rd-account-new-password-confirm" name="new_password_confirm" required="required" minlength="8" autocomplete="new-password">
			</p>
			<p><button type="submit"><?php esc_html_e( 'Change password', 'ruben-dance' ); ?></button></p>
		</form>
	</section>

	<section class="rd-account-profile-section">
		<h3><?php esc_html_e( 'Marketing preferences', 'ruben-dance' ); ?></h3>

		<?php if ( null !== $ruben_dance_consent_result && 'success' === $ruben_dance_consent_result['state'] ) : ?>
			<div class="rd-notice rd-notice--success"><p><?php esc_html_e( 'Your preference has been saved.', 'ruben-dance' ); ?></p></div>
		<?php endif; ?>

		<form method="post" class="rd-account-form">
			<?php wp_nonce_field( 'rd_account_marketing_consent' ); ?>
			<input type="hidden" name="rd_account_action" value="toggle_marketing_consent">
			<p>
				<label>
					<input type="checkbox" name="marketing_consent" value="1" <?php checked( $profile['marketing_consent'] ); ?>>
					<?php esc_html_e( 'I would like to receive occasional news and offers by email.', 'ruben-dance' ); ?>
				</label>
			</p>
			<p><button type="submit"><?php esc_html_e( 'Save preference', 'ruben-dance' ); ?></button></p>
		</form>
	</section>
</div>
