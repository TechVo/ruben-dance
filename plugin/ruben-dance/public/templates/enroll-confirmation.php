<?php
/**
 * `[rd_enroll]` template partial: post-submit confirmation (spec F3 step 4).
 * Design/screens.html #3f (mobile 390) / #4f (desktop 1280 — 2 columns).
 *
 * Variables available: array<string,mixed>|null $enrollment, string $email,
 * string $course_title, string $season, string $weekday, string $time,
 * string $location, string $participant_name, string $base_price,
 * array<int,array{label:string,amount:string}> $discount_rows,
 * string $total_price, string $bank_account, string $due_date,
 * string $qr_url, string $account_url, string $catalog_url.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-enroll rd-enroll-confirmation">
	<div class="rd-enr-success">
		<div class="rd-enr-success__icon" aria-hidden="true">✓</div>
		<h1 class="rd-h2"><?php esc_html_e( 'Enrollment received!', 'ruben-dance' ); ?></h1>
		<p class="rd-enr-success__text">
			<?php
			printf(
				/* translators: %s: customer's email address. */
				esc_html__( "We've sent a confirmation to %s.", 'ruben-dance' ),
				'<strong>' . esc_html( $email ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is escaped; the %s argument is itself esc_html()'d above.
			);
			?>
		</p>
	</div>

	<?php if ( null !== $enrollment ) : ?>
		<div class="rd-enr-confirm-grid">
			<div class="rd-price rd-enr-order-summary">
				<span class="rd-eyebrow"><?php esc_html_e( 'Order summary', 'ruben-dance' ); ?></span>
				<div class="rd-price__rows">
					<div class="rd-price__row"><span><?php esc_html_e( 'Course', 'ruben-dance' ); ?></span><strong><?php echo esc_html( $course_title ); ?></strong></div>
					<div class="rd-price__row"><span><?php esc_html_e( 'Term', 'ruben-dance' ); ?></span><strong><?php echo esc_html( trim( $weekday . ' ' . $time ) ); ?> · <?php echo esc_html( $season ); ?></strong></div>
					<div class="rd-price__row"><span><?php esc_html_e( 'Participant', 'ruben-dance' ); ?></span><strong><?php echo esc_html( $participant_name ); ?></strong></div>
					<div class="rd-price__row"><span><?php esc_html_e( 'Base price', 'ruben-dance' ); ?></span><span class="<?php echo array() !== $discount_rows ? 'rd-price__base-strike' : ''; ?>"><?php echo esc_html( $base_price ); ?> Kč</span></div>
					<?php foreach ( $discount_rows as $ruben_dance_discount_row ) : ?>
						<div class="rd-price__row rd-price__row--discount">
							<span><?php echo esc_html( $ruben_dance_discount_row['label'] ); ?></span>
							<?php /* $ruben_dance_discount_row['amount'] already carries Pricing_Service's own minus sign (e.g. "−200") — no extra "−" prefix here. */ ?>
							<strong><?php echo esc_html( $ruben_dance_discount_row['amount'] ); ?> Kč</strong>
						</div>
					<?php endforeach; ?>
					<div class="rd-price__total">
						<strong><?php esc_html_e( 'Total', 'ruben-dance' ); ?></strong>
						<strong class="rd-price__total-value"><?php echo esc_html( $total_price ); ?> Kč</strong>
					</div>
				</div>

				<?php if ( ! empty( $enrollment['over_capacity'] ) ) : ?>
					<div class="rd-alert rd-alert--warning rd-enr-capacity-note">
						<strong class="rd-alert__icon">!</strong>
						<span><?php esc_html_e( 'This term is currently at capacity — we will contact you to confirm your spot.', 'ruben-dance' ); ?></span>
					</div>
				<?php endif; ?>
			</div>

			<div class="rd-payment">
				<div class="rd-payment__status">⏱ <?php esc_html_e( 'Awaiting payment', 'ruben-dance' ); ?></div>
				<div class="rd-payment__amount"><?php echo esc_html( $total_price ); ?> Kč</div>
				<div class="rd-payment__rows">
					<div class="rd-payment__row"><span><?php esc_html_e( 'Bank account', 'ruben-dance' ); ?></span><strong><?php echo esc_html( '' === $bank_account ? __( '(to be confirmed)', 'ruben-dance' ) : $bank_account ); ?></strong></div>
					<div class="rd-payment__row"><span><?php esc_html_e( 'Variable symbol', 'ruben-dance' ); ?></span><strong><?php echo esc_html( (string) $enrollment['variable_symbol'] ); ?></strong></div>
					<div class="rd-payment__row"><span><?php esc_html_e( 'Due date', 'ruben-dance' ); ?></span><strong><?php echo esc_html( $due_date ); ?></strong></div>
				</div>

				<?php if ( '' !== $qr_url ) : ?>
					<div class="rd-payment__qr">
						<div class="rd-payment__qr-box">
							<img src="<?php echo esc_url( $qr_url ); ?>" alt="<?php esc_attr_e( 'QR payment code', 'ruben-dance' ); ?>" width="104" height="104" loading="lazy">
						</div>
						<div class="rd-payment__qr-text">
							<strong><?php esc_html_e( 'QR payment', 'ruben-dance' ); ?></strong><br>
							<?php esc_html_e( 'Scan with your banking app — the amount, account and variable symbol fill in automatically.', 'ruben-dance' ); ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="rd-enr-actions">
		<a class="rd-btn rd-btn--small rd-enr-actions__primary" href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Go to My account', 'ruben-dance' ); ?></a>
		<a class="rd-btn rd-btn--secondary rd-enr-actions__secondary" href="<?php echo esc_url( $catalog_url ); ?>"><?php esc_html_e( 'Back to courses', 'ruben-dance' ); ?></a>
	</div>
</div>
