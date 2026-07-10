<?php
/**
 * `[rd_account]` "My schedule" tab (spec F6).
 *
 * Variables available (inherited from account.php's shared scope):
 * array<int,array> $schedule.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-account-schedule">
	<?php if ( array() === $schedule ) : ?>
		<p><?php esc_html_e( 'No upcoming lessons.', 'ruben-dance' ); ?></p>
	<?php else : ?>
		<table class="rd-account-schedule-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'ruben-dance' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Time', 'ruben-dance' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Course', 'ruben-dance' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Location', 'ruben-dance' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'ruben-dance' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $schedule as $ruben_dance_lesson ) : ?>
					<tr class="rd-account-schedule-row rd-account-schedule-row--<?php echo esc_attr( $ruben_dance_lesson['status'] ); ?>">
						<td><?php echo esc_html( $ruben_dance_lesson['date'] ); ?></td>
						<td><?php echo esc_html( $ruben_dance_lesson['time'] ); ?></td>
						<td><?php echo esc_html( $ruben_dance_lesson['course_title'] ); ?></td>
						<td><?php echo esc_html( $ruben_dance_lesson['location'] ); ?></td>
						<td>
							<?php if ( 'cancelled' === $ruben_dance_lesson['status'] ) : ?>
								<span class="rd-badge rd-badge--cancelled"><?php esc_html_e( 'Cancelled', 'ruben-dance' ); ?></span>
							<?php elseif ( 'moved' === $ruben_dance_lesson['status'] ) : ?>
								<span class="rd-badge rd-badge--moved"><?php esc_html_e( 'Moved', 'ruben-dance' ); ?></span>
							<?php else : ?>
								<?php esc_html_e( 'Scheduled', 'ruben-dance' ); ?>
							<?php endif; ?>
							<?php if ( '' !== $ruben_dance_lesson['note'] ) : ?>
								<br><small><?php echo esc_html( $ruben_dance_lesson['note'] ); ?></small>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
