<?php
/**
 * `[rd_enroll]` template partial: the enrollment form itself (spec F3 step 3).
 * Design/screens.html #3e (mobile 390 — term summary, capacity/error alerts,
 * participant/role toggles, live price breakdown) / #4e (desktop 1280 —
 * 2-column form + sticky summary sidebar).
 *
 * Variables available: array $term, int $term_id, string $lang,
 * string $course_title, string $course_url, string $season, string $weekday,
 * string $time, string $location, string $formatted_price, bool $is_workshop,
 * bool $is_full, array{price:string,until:string}|null $early_bird,
 * string $discount_early_amount, string $discount_pair_amount,
 * bool $roles_relevant, string[] $roles, bool $already_marketing_consent,
 * array<string,string> $errors, array<string,mixed> $submitted, string $notice,
 * string $privacy_policy_url, string $terms_url, string $currency.
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
$ruben_dance_partner_name     = (string) ( $submitted['partner_name'] ?? '' );

$ruben_dance_role_labels = array(
	'solo'     => __( 'Solo', 'ruben-dance' ),
	'leader'   => __( 'Leader', 'ruben-dance' ),
	'follower' => __( 'Follower', 'ruben-dance' ),
);

$ruben_dance_submit_label = Lang::EN === $lang
	// Spec §6.3 (§ 1826a Civil Code): the submit button must explicitly
	// signal a payment obligation, in the visitor's own language — a bare
	// __() call would depend on a compiled .mo translation that doesn't
	// exist for every locale, so the exact wording is picked here from
	// $lang rather than left to gettext (same pattern
	// `Registration_Service`'s email composers use).
	? __( 'Enroll with obligation to pay', 'ruben-dance' )
	: __( 'Závazně přihlásit s povinností platby', 'ruben-dance' );
?>
<div class="rd-app rd-enroll">
	<?php if ( '' !== $course_url ) : ?>
		<a class="rd-enr-back" href="<?php echo esc_url( $course_url ); ?>">&larr; <?php esc_html_e( 'Back to course', 'ruben-dance' ); ?></a>
	<?php endif; ?>
	<h1 class="rd-h2 rd-enr-heading"><?php esc_html_e( 'Enrollment', 'ruben-dance' ); ?></h1>

	<form method="post" class="rd-enr-layout" id="rd-enroll-form">
		<?php wp_nonce_field( Enrollment_Form_Handler::NONCE_ACTION_PREFIX . $term_id ); ?>
		<input type="hidden" name="rd_enroll_action" value="submit">
		<input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>">
		<?php echo Bot_Guard::fields_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bot_Guard::fields_html() already escapes everything it outputs. ?>

		<div class="rd-enr-summary">
			<span class="rd-eyebrow"><?php esc_html_e( 'Selected term', 'ruben-dance' ); ?></span>
			<div class="rd-enr-summary__title"><?php echo esc_html( $course_title ); ?></div>
			<div class="rd-enr-summary__meta">
				<?php echo esc_html( trim( $weekday . ' ' . $time ) ); ?> · <?php echo esc_html( $season ); ?>
				<?php if ( '' !== $location ) : ?>
					<br><?php echo esc_html( $location ); ?>
				<?php endif; ?>
			</div>
			<div class="rd-enr-summary__price-row">
				<?php if ( null !== $early_bird ) : ?>
					<strong class="rd-enr-summary__price"><?php echo esc_html( $early_bird['price'] ); ?> Kč</strong>
					<span class="rd-enr-summary__price-strike"><?php echo esc_html( $formatted_price ); ?> Kč</span>
					<span class="rd-badge rd-badge--early"><?php esc_html_e( 'Early-bird', 'ruben-dance' ); ?></span>
				<?php else : ?>
					<strong class="rd-enr-summary__price"><?php echo esc_html( $formatted_price ); ?> Kč</strong>
				<?php endif; ?>
				<?php if ( $is_workshop ) : ?>
					<span class="rd-badge rd-badge--workshop"><?php esc_html_e( 'Workshop', 'ruben-dance' ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<div class="rd-enr-alerts">
			<?php if ( 'rate_limited' === $notice ) : ?>
				<div class="rd-alert rd-alert--error"><strong class="rd-alert__icon">✕</strong><span><?php esc_html_e( 'Too many attempts. Please try again later.', 'ruben-dance' ); ?></span></div>
			<?php elseif ( 'duplicate' === $notice ) : ?>
				<div class="rd-alert rd-alert--error"><strong class="rd-alert__icon">✕</strong><span><?php esc_html_e( 'You are already enrolled in this term with this participant.', 'ruben-dance' ); ?></span></div>
			<?php endif; ?>

			<?php if ( $is_full ) : ?>
				<div class="rd-alert rd-alert--warning"><strong class="rd-alert__icon">!</strong><span><?php esc_html_e( 'This term is currently at capacity. You can still sign up — the school will contact you to confirm your spot.', 'ruben-dance' ); ?></span></div>
			<?php endif; ?>

			<?php if ( array() !== $errors ) : ?>
				<div class="rd-alert rd-alert--error" role="alert" tabindex="-1" id="rd-enroll-errors">
					<strong class="rd-alert__icon">✕</strong>
					<span>
						<strong>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of form errors. */
									_n( 'The form has %d error:', 'The form has %d errors:', count( $errors ), 'ruben-dance' ),
									count( $errors )
								)
							);
							?>
						</strong>
						<br>
						<?php foreach ( $errors as $ruben_dance_error_field => $ruben_dance_error_code ) : ?>
							<a href="#<?php echo esc_attr( Enroll_Page::error_anchor( (string) $ruben_dance_error_field ) ); ?>"><?php echo esc_html( Enroll_Page::error_message( $ruben_dance_error_code ) ); ?></a><br>
						<?php endforeach; ?>
					</span>
				</div>
				<script>document.getElementById( 'rd-enroll-errors' ).focus();</script>
			<?php endif; ?>
		</div>

		<div class="rd-card rd-enr-fields">
			<fieldset class="rd-enr-field-group">
				<legend class="rd-enr-legend"><?php esc_html_e( 'Who is attending?', 'ruben-dance' ); ?></legend>
				<div class="rd-enr-toggle">
					<label class="rd-enr-toggle__option">
						<input class="rd-enr-toggle__input" type="radio" name="participant_type" value="me" <?php checked( 'other' !== $ruben_dance_participant_type ); ?>>
						<span class="rd-enr-toggle__label"><?php esc_html_e( 'Me', 'ruben-dance' ); ?></span>
					</label>
					<label class="rd-enr-toggle__option">
						<input class="rd-enr-toggle__input" type="radio" name="participant_type" value="other" <?php checked( 'other' === $ruben_dance_participant_type ); ?>>
						<span class="rd-enr-toggle__label"><?php esc_html_e( 'Someone else', 'ruben-dance' ); ?></span>
					</label>
				</div>
				<p class="rd-enr-hint"><?php esc_html_e( 'E.g. a parent enrolling their child — enter their name below.', 'ruben-dance' ); ?></p>
			</fieldset>

			<div class="rd-field<?php echo isset( $errors['participant_name'] ) ? ' rd-field--error' : ''; ?>" id="rd-enroll-participant-name-row">
				<label for="rd-enroll-participant-name"><?php esc_html_e( 'Participant name', 'ruben-dance' ); ?> <span class="rd-field__required">*</span></label>
				<input type="text" id="rd-enroll-participant-name" name="participant_name" value="<?php echo esc_attr( (string) ( $submitted['participant_name'] ?? '' ) ); ?>" <?php echo isset( $errors['participant_name'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-enroll-participant-name-error">
				<p id="rd-enroll-participant-name-error" class="rd-field__error"><?php echo isset( $errors['participant_name'] ) ? esc_html( Enroll_Page::error_message( $errors['participant_name'] ) ) : ''; ?></p>
			</div>

			<?php if ( $roles_relevant ) : ?>
				<div id="rd-enroll-role">
					<div class="rd-enr-legend"><?php esc_html_e( 'Dance role', 'ruben-dance' ); ?> <span class="rd-enr-legend__hint">(<?php esc_html_e( 'for partner courses', 'ruben-dance' ); ?>)</span></div>
					<div class="rd-enr-role">
						<?php foreach ( $roles as $ruben_dance_role ) : ?>
							<label class="rd-enr-role__option">
								<input class="rd-enr-role__input" type="radio" name="role" value="<?php echo esc_attr( $ruben_dance_role ); ?>" <?php checked( $ruben_dance_role_value, $ruben_dance_role ); ?>>
								<span class="rd-enr-role__label"><?php echo esc_html( $ruben_dance_role_labels[ $ruben_dance_role ] ?? $ruben_dance_role ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="rd-field__error"><?php echo isset( $errors['role'] ) ? esc_html( Enroll_Page::error_message( $errors['role'] ) ) : ''; ?></p>
				</div>
			<?php endif; ?>

			<div class="rd-field<?php echo isset( $errors['partner_name'] ) ? ' rd-field--error' : ''; ?>">
				<label for="rd-enroll-partner-name"><?php esc_html_e( 'Partner name', 'ruben-dance' ); ?> <span class="rd-enr-legend__hint">(<?php esc_html_e( 'optional — enrolling as a pair may unlock a discount', 'ruben-dance' ); ?>)</span></label>
				<input type="text" id="rd-enroll-partner-name" name="partner_name" value="<?php echo esc_attr( $ruben_dance_partner_name ); ?>" <?php echo isset( $errors['partner_name'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-enroll-partner-name-error">
				<p id="rd-enroll-partner-name-error" class="rd-field__error"><?php echo isset( $errors['partner_name'] ) ? esc_html( Enroll_Page::error_message( $errors['partner_name'] ) ) : ''; ?></p>
			</div>

			<div class="rd-field">
				<label for="rd-enroll-note"><?php esc_html_e( 'Note', 'ruben-dance' ); ?> <span class="rd-enr-legend__hint">(<?php esc_html_e( 'optional', 'ruben-dance' ); ?>)</span></label>
				<textarea id="rd-enroll-note" name="customer_note" rows="3"><?php echo esc_textarea( (string) ( $submitted['customer_note'] ?? '' ) ); ?></textarea>
			</div>

			<label class="rd-checkbox-row<?php echo isset( $errors['tc_accepted'] ) ? ' rd-enr-checkbox--error' : ''; ?>">
				<input class="rd-checkbox-row__input" type="checkbox" id="rd-enroll-tc" name="tc_accepted" value="1" required="required" <?php checked( ! empty( $submitted['tc_accepted'] ) ); ?> <?php echo isset( $errors['tc_accepted'] ) ? 'aria-invalid="true"' : ''; ?> aria-describedby="rd-enroll-tc-error">
				<span class="rd-checkbox-row__box" aria-hidden="true"></span>
				<span>
					<?php if ( '' !== $terms_url ) : ?>
						<?php
						printf(
							/* translators: 1: Terms & Conditions link, 2: privacy policy link. */
							esc_html__( 'I agree to the %1$s and acknowledge the %2$s.', 'ruben-dance' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is escaped; both %s arguments are themselves built from esc_url()/esc_html() pieces.
							'<a href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms & Conditions', 'ruben-dance' ) . '</a>',
							'<a href="' . esc_url( $privacy_policy_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'ruben-dance' ) . '</a>'
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'I agree to the Terms & Conditions and acknowledge the privacy policy.', 'ruben-dance' ); ?>
					<?php endif; ?>
					<span class="rd-field__required">*</span>
					<span id="rd-enroll-tc-error" class="rd-field__error"><?php echo isset( $errors['tc_accepted'] ) ? esc_html( Enroll_Page::error_message( $errors['tc_accepted'] ) ) : ''; ?></span>
				</span>
			</label>

			<?php if ( ! $already_marketing_consent ) : ?>
				<label class="rd-checkbox-row">
					<input class="rd-checkbox-row__input" type="checkbox" name="marketing_consent" value="1" <?php checked( ! empty( $submitted['marketing_consent'] ) ); ?>>
					<span class="rd-checkbox-row__box" aria-hidden="true"></span>
					<span><?php esc_html_e( 'I would also like to receive occasional news and offers by email', 'ruben-dance' ); ?> <span class="rd-enr-legend__hint">(<?php esc_html_e( 'optional', 'ruben-dance' ); ?>)</span></span>
				</label>
			<?php endif; ?>
		</div>

		<div class="rd-price rd-enr-price">
			<span class="rd-eyebrow"><?php esc_html_e( 'Price — calculated live', 'ruben-dance' ); ?></span>
			<div class="rd-price__rows">
				<div class="rd-price__row">
					<span><?php esc_html_e( 'Base price', 'ruben-dance' ); ?></span>
					<span id="rd-enroll-price-base" class="<?php echo null !== $early_bird ? 'rd-price__base-strike' : ''; ?>"><?php echo esc_html( $formatted_price ); ?> Kč</span>
				</div>
				<?php if ( null !== $early_bird ) : ?>
					<div class="rd-price__row rd-price__row--discount">
						<span>
							★
							<?php
							printf(
								/* translators: %s: early-bird deadline date. */
								esc_html__( 'Early-bird until %s', 'ruben-dance' ),
								esc_html( $early_bird['until'] )
							);
							?>
						</span>
						<strong>− <?php echo esc_html( $discount_early_amount ); ?> Kč</strong>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $discount_pair_amount ) : ?>
					<div class="rd-price__row rd-price__row--discount" id="rd-enroll-price-partner-row" style="<?php echo '' === trim( $ruben_dance_partner_name ) ? 'display:none' : ''; ?>">
						<span><?php esc_html_e( 'Partner discount', 'ruben-dance' ); ?></span>
						<strong>− <?php echo esc_html( $discount_pair_amount ); ?> Kč</strong>
					</div>
				<?php endif; ?>
				<div class="rd-price__total">
					<strong><?php esc_html_e( 'Total', 'ruben-dance' ); ?></strong>
					<strong class="rd-price__total-value"><span id="rd-enroll-price-display"><?php echo esc_html( null !== $early_bird ? $early_bird['price'] : $formatted_price ); ?></span> <span id="rd-enroll-price-currency"><?php echo esc_html( $currency ); ?></span></strong>
				</div>
			</div>

			<button type="submit" class="rd-btn rd-btn--primary rd-enr-submit"><?php echo esc_html( $ruben_dance_submit_label ); ?></button>
			<p class="rd-enr-advisory"><?php esc_html_e( '(advisory — the final price is always confirmed on the next screen)', 'ruben-dance' ); ?></p>
			<p class="rd-enr-advisory"><em><?php esc_html_e( 'By clicking this button you agree to a binding enrollment with an obligation to pay.', 'ruben-dance' ); ?></em></p>
		</div>
	</form>
</div>
