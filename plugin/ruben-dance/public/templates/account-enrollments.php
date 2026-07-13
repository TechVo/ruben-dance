<?php
/**
 * `[rd_account]` "My enrollments" tab (spec F5). Design/screens.html
 * #3h/#4i/#4h: an enrollment awaiting payment expands into the full
 * `.rd-payment` block (amount, bank account, variable symbol, due date, QR —
 * same component `enroll-confirmation.php` already uses); paid/cancelled
 * enrollments are compact cards, grouped together in `.rd-acc-enr-compact-group`
 * so front-account.css can pack them into a 2-column group beside (tablet) or
 * below (desktop) the full-width unpaid card(s) — see that file's
 * `.rd-acc-enr-grid` rules.
 *
 * Variables available (inherited from account.php's shared scope):
 * array<int,array> $enrollments, string $bank_account, string $catalog_url.
 *
 * @package RubenDance
 */

use RubenDance\Front\Account_Page;
use RubenDance\Services\Enrollment_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

// Split once here (not re-checked per row in the template below) so the
// layout grouping is a single, obvious decision: everything still awaiting
// payment gets the full expanded treatment; paid/cancelled rows are
// summarized compactly. Order within each group is left exactly as
// `Account_Service` returned it — this only groups by status, it never
// re-sorts.
$ruben_dance_unpaid_rows  = array();
$ruben_dance_settled_rows = array();

foreach ( $enrollments as $ruben_dance_row ) {
	if ( Enrollment_Service::STATUS_CONFIRMED === $ruben_dance_row['status'] ) {
		$ruben_dance_unpaid_rows[] = $ruben_dance_row;
	} else {
		$ruben_dance_settled_rows[] = $ruben_dance_row;
	}
}
?>
<div class="rd-acc-enrollments">
	<?php if ( array() === $enrollments ) : ?>
		<div class="rd-empty-state">
			<div class="rd-empty-state__icon" aria-hidden="true">🕺</div>
			<p class="rd-empty-state__text"><?php esc_html_e( 'You have no enrollments yet.', 'ruben-dance' ); ?></p>
			<a class="rd-empty-state__action" href="<?php echo esc_url( $catalog_url ); ?>"><?php esc_html_e( 'Browse courses', 'ruben-dance' ); ?> →</a>
		</div>
	<?php else : ?>
		<div class="rd-acc-enr-grid">
			<?php foreach ( $ruben_dance_unpaid_rows as $ruben_dance_enrollment ) : ?>
				<article class="rd-card rd-acc-enr rd-acc-enr--unpaid">
					<div class="rd-acc-enr__head">
						<div>
							<h3 class="rd-acc-enr__title"><?php echo esc_html( $ruben_dance_enrollment['course_title'] ); ?> — <?php echo esc_html( $ruben_dance_enrollment['season'] ); ?></h3>
							<p class="rd-acc-enr__meta">
								<?php echo esc_html( trim( $ruben_dance_enrollment['weekday'] . ' ' . $ruben_dance_enrollment['time'] ) ); ?>
								<?php if ( '' !== $ruben_dance_enrollment['location'] ) : ?>
									· <?php echo esc_html( $ruben_dance_enrollment['location'] ); ?>
								<?php endif; ?>
								<?php if ( '' !== $ruben_dance_enrollment['participant_name'] ) : ?>
									· <?php echo esc_html( $ruben_dance_enrollment['participant_name'] ); ?>
								<?php endif; ?>
							</p>
						</div>
						<span class="rd-badge rd-badge--<?php echo esc_attr( Account_Page::badge_class( $ruben_dance_enrollment['status'] ) ); ?>"><?php echo esc_html( Account_Page::status_label( $ruben_dance_enrollment['status'] ) ); ?></span>
					</div>

					<?php if ( $ruben_dance_enrollment['over_capacity'] ) : ?>
						<div class="rd-alert rd-alert--warning rd-acc-enr__capacity-note">
							<strong class="rd-alert__icon">!</strong>
							<span><?php esc_html_e( 'This enrollment was over capacity when made — we will contact you to confirm your spot.', 'ruben-dance' ); ?></span>
						</div>
					<?php endif; ?>

					<div class="rd-payment rd-acc-enr__payment">
						<div class="rd-payment__status">⏱ <?php esc_html_e( 'Awaiting payment', 'ruben-dance' ); ?></div>
						<div class="rd-payment__amount"><?php echo esc_html( $ruben_dance_enrollment['price'] ); ?> Kč</div>
						<div class="rd-payment__rows">
							<div class="rd-payment__row"><span><?php esc_html_e( 'Bank account', 'ruben-dance' ); ?></span><strong><?php echo esc_html( '' === $bank_account ? __( '(to be confirmed)', 'ruben-dance' ) : $bank_account ); ?></strong></div>
							<div class="rd-payment__row"><span><?php esc_html_e( 'Variable symbol', 'ruben-dance' ); ?></span><strong><?php echo esc_html( $ruben_dance_enrollment['variable_symbol'] ); ?></strong></div>
							<div class="rd-payment__row"><span><?php esc_html_e( 'Due date', 'ruben-dance' ); ?></span><strong><?php echo esc_html( $ruben_dance_enrollment['due_date'] ); ?></strong></div>
						</div>

						<?php if ( '' !== $ruben_dance_enrollment['qr_url'] ) : ?>
							<div class="rd-payment__qr">
								<div class="rd-payment__qr-box">
									<img src="<?php echo esc_url( $ruben_dance_enrollment['qr_url'] ); ?>" alt="<?php esc_attr_e( 'QR payment code', 'ruben-dance' ); ?>" width="92" height="92" loading="lazy">
								</div>
								<div class="rd-payment__qr-text">
									<strong><?php esc_html_e( 'QR payment', 'ruben-dance' ); ?></strong><br>
									<?php esc_html_e( 'Scan with your banking app — the amount, account and variable symbol fill in automatically.', 'ruben-dance' ); ?>
								</div>
							</div>
						<?php endif; ?>
					</div>

					<p class="rd-acc-enr__note"><?php esc_html_e( "Need to cancel? Please contact us — self-service cancellation isn't available.", 'ruben-dance' ); ?></p>
				</article>
			<?php endforeach; ?>

			<?php if ( array() !== $ruben_dance_settled_rows ) : ?>
				<div class="rd-acc-enr-compact-group">
					<?php foreach ( $ruben_dance_settled_rows as $ruben_dance_enrollment ) : ?>
						<?php $ruben_dance_is_cancelled = Enrollment_Service::STATUS_CANCELLED === $ruben_dance_enrollment['status']; ?>
						<article class="rd-card rd-acc-enr rd-acc-enr--compact<?php echo $ruben_dance_is_cancelled ? ' rd-acc-enr--cancelled' : ''; ?>">
							<div class="rd-acc-enr__head">
								<div>
									<h3 class="rd-acc-enr__title"><?php echo esc_html( $ruben_dance_enrollment['course_title'] ); ?> — <?php echo esc_html( $ruben_dance_enrollment['season'] ); ?></h3>
									<p class="rd-acc-enr__meta">
										<?php echo esc_html( trim( $ruben_dance_enrollment['weekday'] . ' ' . $ruben_dance_enrollment['time'] ) ); ?>
										<?php if ( '' !== $ruben_dance_enrollment['location'] ) : ?>
											· <?php echo esc_html( $ruben_dance_enrollment['location'] ); ?>
										<?php endif; ?>
										<?php if ( '' !== $ruben_dance_enrollment['participant_name'] ) : ?>
											· <?php echo esc_html( $ruben_dance_enrollment['participant_name'] ); ?>
										<?php endif; ?>
									</p>
								</div>
								<span class="rd-badge rd-badge--<?php echo esc_attr( Account_Page::badge_class( $ruben_dance_enrollment['status'] ) ); ?>"><?php echo esc_html( Account_Page::status_label( $ruben_dance_enrollment['status'] ) ); ?></span>
							</div>

							<?php if ( $ruben_dance_enrollment['over_capacity'] ) : ?>
								<div class="rd-alert rd-alert--warning rd-acc-enr__capacity-note">
									<strong class="rd-alert__icon">!</strong>
									<span><?php esc_html_e( 'This enrollment was over capacity when made — we will contact you to confirm your spot.', 'ruben-dance' ); ?></span>
								</div>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
