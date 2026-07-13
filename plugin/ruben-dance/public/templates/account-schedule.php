<?php
/**
 * `[rd_account]` "My schedule" tab (spec F6). Design/screens.html #3h: a
 * card-row list (date/time + course/location); a cancelled or moved lesson
 * gets the warning treatment (background/border) with its note (e.g. a
 * replacement lesson's date) shown inline underneath.
 *
 * Variables available (inherited from account.php's shared scope):
 * array<int,array> $schedule, string $catalog_url.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-acc-schedule">
	<?php if ( array() === $schedule ) : ?>
		<div class="rd-empty-state">
			<div class="rd-empty-state__icon" aria-hidden="true">🕺</div>
			<p class="rd-empty-state__text"><?php esc_html_e( 'No upcoming lessons.', 'ruben-dance' ); ?></p>
			<a class="rd-empty-state__action" href="<?php echo esc_url( $catalog_url ); ?>"><?php esc_html_e( 'Browse courses', 'ruben-dance' ); ?> →</a>
		</div>
	<?php else : ?>
		<h3 class="rd-h3 rd-acc-schedule__heading"><?php esc_html_e( 'Upcoming lessons', 'ruben-dance' ); ?></h3>
		<ul class="rd-acc-sched-list">
			<?php foreach ( $schedule as $ruben_dance_lesson ) : ?>
				<?php
				$ruben_dance_is_cancelled = 'cancelled' === $ruben_dance_lesson['status'];
				$ruben_dance_is_moved     = 'moved' === $ruben_dance_lesson['status'];
				?>
				<li class="rd-acc-sched-row<?php echo ( $ruben_dance_is_cancelled || $ruben_dance_is_moved ) ? ' rd-acc-sched-row--warning' : ''; ?>">
					<div class="rd-acc-sched-row__when">
						<strong><?php echo esc_html( trim( $ruben_dance_lesson['weekday_short'] . ' ' . $ruben_dance_lesson['date_short'] ) ); ?> · <?php echo esc_html( $ruben_dance_lesson['time'] ); ?></strong>
						<span<?php echo $ruben_dance_is_cancelled ? ' class="rd-acc-sched-row__title--strike"' : ''; ?>><?php echo esc_html( $ruben_dance_lesson['course_title'] ); ?></span>
						<?php if ( $ruben_dance_is_cancelled ) : ?>
							— <strong class="rd-acc-sched-row__status"><?php esc_html_e( 'Cancelled', 'ruben-dance' ); ?></strong>
						<?php elseif ( $ruben_dance_is_moved ) : ?>
							— <strong class="rd-acc-sched-row__status"><?php esc_html_e( 'Moved', 'ruben-dance' ); ?></strong>
						<?php endif; ?>
					</div>
					<?php if ( '' !== $ruben_dance_lesson['location'] ) : ?>
						<div class="rd-acc-sched-row__meta"><?php echo esc_html( $ruben_dance_lesson['location'] ); ?></div>
					<?php endif; ?>
					<?php if ( '' !== $ruben_dance_lesson['note'] ) : ?>
						<div class="rd-acc-sched-row__note"><?php echo esc_html( $ruben_dance_lesson['note'] ); ?></div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
