<?php
/**
 * `[rd_enroll]` template partial: the enrollment form itself (spec F3 step 3).
 *
 * Variables available: array $term, int $term_id, string $course_title,
 * string $season, bool $is_full, array{price:string,until:string}|null $early_bird,
 * bool $roles_relevant, string[] $roles, bool $already_marketing_consent,
 * array<string,string> $errors, array<string,mixed> $submitted, string $notice,
 * string $privacy_policy_url, string $terms_url, string $currency, string $lang.
 *
 * @package RubenDance
 */

use RubenDance\Front\Bot_Guard;
use RubenDance\Front\Enrollment_Form_Handler;
use RubenDance\Front\Enroll_Page;
use RubenDance\Lang;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

$ruben_dance_participant_type = isset( $submitted['participant_type'] ) ? (string) $submitted['participant_type'] : 'me';
$ruben_dance_role_value       = isset( $submitted['role'] ) ? (string) $submitted['role'] : 'solo';

$ruben_dance_role_labels = array(
	'solo'     => __( 'Solo', 'ruben-dance' ),
	'leader'   => __( 'Leader', 'ruben-dance' ),
	'follower' => __( 'Follower', 'ruben-dance' ),
);
?>
<div class="rd-enroll">
	<h2><?php echo esc_html( $course_title ); ?> — <?php echo esc_html( $season ); ?></h2>

	<?php if ( 'rate_limited' === $notice ) : ?>
		<div class="rd-notice rd-notice--error"><p><?php esc_html_e( 'Too many attempts. Please try again later.', 'ruben-dance' ); ?></p></div>
	<?php elseif ( 'duplicate' === $notice ) : ?>
		<div class="rd-notice rd-notice--error"><p><?php esc_html_e( 'You are already enrolled in this term with this participant.', 'ruben-dance' ); ?></p></div>
	<?php endif; ?>

	<?php if ( array() !== $errors ) : ?>
		<div class="rd-notice rd-notice--error">
			<ul>
				<?php foreach ( $errors as $ruben_dance_error_code ) : ?>
					<li><?php echo esc_html( Enroll_Page::error_message( $ruben_dance_error_code ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $is_full ) : ?>
		<div class="rd-notice rd-notice--warning">
			<p><?php esc_html_e( 'This term is currently at capacity. You can still sign up — the school will contact you to confirm your spot.', 'ruben-dance' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" class="rd-enroll-form" id="rd-enroll-form">
		<?php wp_nonce_field( Enrollment_Form_Handler::NONCE_ACTION_PREFIX . $term_id ); ?>
		<input type="hidden" name="rd_enroll_action" value="submit">
		<input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>">
		<?php echo Bot_Guard::fields_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bot_Guard::fields_html() already escapes everything it outputs. ?>

		<fieldset>
			<legend><?php esc_html_e( 'Who is attending?', 'ruben-dance' ); ?></legend>
			<p>
				<label><input type="radio" name="participant_type" value="me" <?php checked( 'other' !== $ruben_dance_participant_type ); ?>> <?php esc_html_e( 'Me', 'ruben-dance' ); ?></label>
				<label><input type="radio" name="participant_type" value="other" <?php checked( 'other' === $ruben_dance_participant_type ); ?>> <?php esc_html_e( 'Someone else (e.g. my child)', 'ruben-dance' ); ?></label>
			</p>
			<p id="rd-enroll-participant-name-row">
				<label for="rd-enroll-participant-name"><?php esc_html_e( 'Participant name', 'ruben-dance' ); ?></label><br>
				<input type="text" id="rd-enroll-participant-name" name="participant_name" value="<?php echo esc_attr( (string) ( $submitted['participant_name'] ?? '' ) ); ?>">
			</p>
		</fieldset>

		<?php if ( $roles_relevant ) : ?>
			<p>
				<label for="rd-enroll-role"><?php esc_html_e( 'Role', 'ruben-dance' ); ?></label><br>
				<select id="rd-enroll-role" name="role">
					<?php foreach ( $roles as $ruben_dance_role ) : ?>
						<option value="<?php echo esc_attr( $ruben_dance_role ); ?>" <?php selected( $ruben_dance_role_value, $ruben_dance_role ); ?>><?php echo esc_html( $ruben_dance_role_labels[ $ruben_dance_role ] ?? $ruben_dance_role ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>

		<p>
			<label for="rd-enroll-partner-name"><?php esc_html_e( 'Partner name (optional — enrolling as a pair may unlock a discount)', 'ruben-dance' ); ?></label><br>
			<input type="text" id="rd-enroll-partner-name" name="partner_name" value="<?php echo esc_attr( (string) ( $submitted['partner_name'] ?? '' ) ); ?>">
		</p>

		<p>
			<label for="rd-enroll-note"><?php esc_html_e( 'Note (optional)', 'ruben-dance' ); ?></label><br>
			<textarea id="rd-enroll-note" name="customer_note" rows="3"><?php echo esc_textarea( (string) ( $submitted['customer_note'] ?? '' ) ); ?></textarea>
		</p>

		<p class="rd-enroll-price">
			<?php esc_html_e( 'Estimated price:', 'ruben-dance' ); ?>
			<strong>
				<span id="rd-enroll-price-display"><?php echo esc_html( null !== $early_bird ? $early_bird['price'] : (string) $term['price'] ); ?></span>
				<span id="rd-enroll-price-currency"><?php echo esc_html( $currency ); ?></span>
			</strong>
			<span class="description"><?php esc_html_e( '(advisory — the final price is always confirmed on the next screen)', 'ruben-dance' ); ?></span>
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

		<?php if ( ! $already_marketing_consent ) : ?>
			<p>
				<label>
					<input type="checkbox" name="marketing_consent" value="1" <?php checked( ! empty( $submitted['marketing_consent'] ) ); ?>>
					<?php esc_html_e( 'I would also like to receive occasional news and offers by email (optional).', 'ruben-dance' ); ?>
				</label>
			</p>
		<?php endif; ?>

		<?php
		// Spec §6.3 (§ 1826a Civil Code): the submit button must explicitly
		// signal a payment obligation, in the visitor's own language — a
		// bare __() call would depend on a compiled .mo translation that
		// doesn't exist yet (see Registration_Service's email composers for
		// the same explicit-branch pattern), so the exact wording is picked
		// here from $lang rather than left to gettext.
		$ruben_dance_submit_label = Lang::EN === $lang
			? __( 'Enroll with obligation to pay', 'ruben-dance' )
			: __( 'Závazně přihlásit s povinností platby', 'ruben-dance' );
		?>
		<p><button type="submit"><?php echo esc_html( $ruben_dance_submit_label ); ?></button></p>
		<p class="description"><em><?php esc_html_e( 'By clicking this button you agree to a binding enrollment with an obligation to pay.', 'ruben-dance' ); ?></em></p>
	</form>
</div>
<script>
( function () {
	var typeRadios = document.querySelectorAll( 'input[name="participant_type"]' );
	var nameRow = document.getElementById( 'rd-enroll-participant-name-row' );

	function toggle() {
		var isOther = document.querySelector( 'input[name="participant_type"]:checked' ).value === 'other';
		nameRow.style.display = isOther ? '' : 'none';
	}

	if ( typeRadios.length && nameRow ) {
		typeRadios.forEach( function ( radio ) {
			radio.addEventListener( 'change', toggle );
		} );
		toggle();
	}
} )();
</script>
