<?php
/**
 * `[rd_calendar]` template partial.
 *
 * Variables available: array<int,string> $style_options (term ID => name),
 * array<int,array> $location_options (active locations), array<int,array>
 * $upcoming_lessons (see `Services\Calendar_Service::lessons_for_feed()` for
 * the row shape).
 *
 * The calendar grid itself is rendered client-side by `calendar.js` into
 * `#rd-calendar`; this template also provides the filter controls, the mount
 * point, and — spec §6.4: "keyboard-navigable calendar with a list-view
 * fallback" — an always-rendered, server-side list of the same upcoming
 * lessons that needs no JavaScript and no drag/click grid interaction to use.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-calendar-wrap">
	<form class="rd-calendar-filters" onsubmit="return false;">
		<label for="rd-calendar-style"><?php esc_html_e( 'Style', 'ruben-dance' ); ?></label>
		<select id="rd-calendar-style">
			<option value="0"><?php esc_html_e( 'All styles', 'ruben-dance' ); ?></option>
			<?php foreach ( $style_options as $ruben_dance_id => $ruben_dance_name ) : ?>
				<option value="<?php echo esc_attr( (string) $ruben_dance_id ); ?>"><?php echo esc_html( $ruben_dance_name ); ?></option>
			<?php endforeach; ?>
		</select>

		<label for="rd-calendar-location"><?php esc_html_e( 'Location', 'ruben-dance' ); ?></label>
		<select id="rd-calendar-location">
			<option value="0"><?php esc_html_e( 'All locations', 'ruben-dance' ); ?></option>
			<?php foreach ( $location_options as $ruben_dance_location ) : ?>
				<option value="<?php echo esc_attr( (string) $ruben_dance_location['id'] ); ?>"><?php echo esc_html( (string) $ruben_dance_location['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
	</form>

	<p class="rd-calendar-list-link">
		<a href="#rd-calendar-list-view"><?php esc_html_e( 'Skip to list view', 'ruben-dance' ); ?></a>
	</p>

	<div id="rd-calendar" class="rd-calendar">
		<noscript>
			<p class="rd-notice rd-notice--warning"><?php esc_html_e( 'Enable JavaScript to see the calendar.', 'ruben-dance' ); ?></p>
		</noscript>
	</div>

	<section id="rd-calendar-list-view" class="rd-calendar-list-view" tabindex="-1">
		<h2><?php esc_html_e( 'Upcoming lessons (list view)', 'ruben-dance' ); ?></h2>
		<?php if ( array() === $upcoming_lessons ) : ?>
			<p><?php esc_html_e( 'No upcoming lessons found.', 'ruben-dance' ); ?></p>
		<?php else : ?>
			<table class="rd-calendar-list-table">
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
					<?php foreach ( $upcoming_lessons as $ruben_dance_lesson ) : ?>
						<tr>
							<td><?php echo esc_html( $ruben_dance_lesson['date'] ); ?></td>
							<td><?php echo esc_html( $ruben_dance_lesson['start'] . '–' . $ruben_dance_lesson['end'] ); ?></td>
							<td>
								<?php if ( '' !== $ruben_dance_lesson['url'] ) : ?>
									<a href="<?php echo esc_url( $ruben_dance_lesson['url'] ); ?>"><?php echo esc_html( $ruben_dance_lesson['title'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $ruben_dance_lesson['title'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $ruben_dance_lesson['location'] ); ?></td>
							<td>
								<?php if ( 'cancelled' === $ruben_dance_lesson['status'] ) : ?>
									<?php esc_html_e( 'Cancelled', 'ruben-dance' ); ?>
								<?php elseif ( 'moved' === $ruben_dance_lesson['status'] ) : ?>
									<?php esc_html_e( 'Moved', 'ruben-dance' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Scheduled', 'ruben-dance' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
</div>
