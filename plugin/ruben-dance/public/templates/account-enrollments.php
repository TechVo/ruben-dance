<?php
/**
 * `[rd_account]` "My enrollments" tab (spec F5).
 *
 * Variables available (inherited from account.php's shared scope):
 * array<int,array> $enrollments, string $bank_account.
 *
 * @package RubenDance
 */

use RubenDance\Front\Account_Page;
use RubenDance\Services\Enrollment_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-account-enrollments">
	<?php if ( array() === $enrollments ) : ?>
		<p><?php esc_html_e( 'You have no enrollments yet.', 'ruben-dance' ); ?></p>
	<?php else : ?>
		<?php foreach ( $enrollments as $ruben_dance_enrollment ) : ?>
			<div class="rd-account-enrollment">
				<h3>
					<?php echo esc_html( $ruben_dance_enrollment['course_title'] ); ?> — <?php echo esc_html( $ruben_dance_enrollment['season'] ); ?>
					<span class="rd-badge rd-badge--status-<?php echo esc_attr( $ruben_dance_enrollment['status'] ); ?>"><?php echo esc_html( Account_Page::status_label( $ruben_dance_enrollment['status'] ) ); ?></span>
				</h3>
				<ul class="rd-account-enrollment-meta">
					<?php if ( '' !== $ruben_dance_enrollment['participant_name'] ) : ?>
						<li><?php esc_html_e( 'Participant:', 'ruben-dance' ); ?> <?php echo esc_html( $ruben_dance_enrollment['participant_name'] ); ?></li>
					<?php endif; ?>
					<li><?php echo esc_html( trim( $ruben_dance_enrollment['weekday'] . ' ' . $ruben_dance_enrollment['time'] ) ); ?></li>
					<?php if ( '' !== $ruben_dance_enrollment['location'] ) : ?>
						<li><?php esc_html_e( 'Location:', 'ruben-dance' ); ?> <?php echo esc_html( $ruben_dance_enrollment['location'] ); ?></li>
					<?php endif; ?>
					<li>
						<?php esc_html_e( 'Price:', 'ruben-dance' ); ?>
						<?php echo esc_html( $ruben_dance_enrollment['price'] ); ?> Kč
						<?php if ( '' !== $ruben_dance_enrollment['discount_note'] ) : ?>
							(<?php echo esc_html( $ruben_dance_enrollment['discount_note'] ); ?>)
						<?php endif; ?>
					</li>
				</ul>

				<?php if ( $ruben_dance_enrollment['over_capacity'] ) : ?>
					<div class="rd-notice rd-notice--warning">
						<p><?php esc_html_e( 'This enrollment was over capacity when made — we will contact you to confirm your spot.', 'ruben-dance' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( Enrollment_Service::STATUS_CONFIRMED === $ruben_dance_enrollment['status'] ) : ?>
					<div class="rd-account-payment-instructions">
						<h4><?php esc_html_e( 'Payment instructions', 'ruben-dance' ); ?></h4>
						<table class="rd-enroll-summary">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( 'Amount', 'ruben-dance' ); ?></th>
									<td><?php echo esc_html( $ruben_dance_enrollment['price'] ); ?> Kč</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Bank account', 'ruben-dance' ); ?></th>
									<td><?php echo esc_html( '' === $bank_account ? __( '(to be confirmed)', 'ruben-dance' ) : $bank_account ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Variable symbol', 'ruben-dance' ); ?></th>
									<td><?php echo esc_html( $ruben_dance_enrollment['variable_symbol'] ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Due date', 'ruben-dance' ); ?></th>
									<td><?php echo esc_html( $ruben_dance_enrollment['due_date'] ); ?></td>
								</tr>
							</tbody>
						</table>
						<?php if ( '' !== $ruben_dance_enrollment['qr_url'] ) : ?>
							<div class="rd-account-qr-payment">
								<img src="<?php echo esc_url( $ruben_dance_enrollment['qr_url'] ); ?>" alt="<?php esc_attr_e( 'QR payment code', 'ruben-dance' ); ?>" width="200" height="200" loading="lazy">
								<p class="description"><?php esc_html_e( 'Scan with your banking app to pre-fill the amount and variable symbol.', 'ruben-dance' ); ?></p>
							</div>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( "Need to cancel? Please contact us — self-service cancellation isn't available.", 'ruben-dance' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
