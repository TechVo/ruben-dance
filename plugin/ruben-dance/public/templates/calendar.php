<?php
/**
 * `[rd_calendar]` template partial — design/screens.html #3d (mobile 390:
 * a day-list week view + list-view fallback) / #4d (desktop 1280: a
 * 7-column week grid). The H1 itself ("Kalendář lekcí") is not part of
 * this partial — it comes from the WP page title via the theme's
 * `page.php` (`<h1 class="rd-h1 rd-page__title">`), the same as every
 * other `[rd_*]` shortcode page.
 *
 * Variables available: array<int,string> $style_options (term ID => name),
 * array<int,array> $location_options (active locations), array<int,array>
 * $upcoming_lessons (see `Services\Calendar_Service::lessons_for_feed()` for
 * the row shape, plus `weekday_short`/`date_short` added by
 * `Calendar_Page::upcoming_lessons()`), int $list_view_days.
 *
 * The calendar grid itself is rendered client-side by `calendar.js` into
 * `#rd-calendar` (the toolbar above it — view toggle, style/location
 * filters, prev/next, range label — is JS-driven too, see calendar.js);
 * this template also provides an always-rendered, server-side `<table>` of
 * the same upcoming lessons that needs no JavaScript and no drag/click grid
 * interaction to use (spec §6.4: "keyboard-navigable calendar with a
 * list-view fallback").
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-calendar-wrap rd-cal">
	<div class="rd-cal__toolbar">
		<div class="rd-cal__toolbar-row">
			<div class="rd-cal__view-toggle" role="group" aria-label="<?php esc_attr_e( 'Calendar view', 'ruben-dance' ); ?>">
				<button type="button" class="rd-cal__view-btn is-active" data-rd-cal-view="week" aria-pressed="true"><?php esc_html_e( 'Week', 'ruben-dance' ); ?></button>
				<button type="button" class="rd-cal__view-btn" data-rd-cal-view="month" aria-pressed="false"><?php esc_html_e( 'Month', 'ruben-dance' ); ?></button>
			</div>

			<form class="rd-cal__filters" onsubmit="return false;">
				<label class="screen-reader-text" for="rd-calendar-style"><?php esc_html_e( 'Style', 'ruben-dance' ); ?></label>
				<select id="rd-calendar-style" class="rd-filter-chip rd-cal__filter-select">
					<option value="0"><?php esc_html_e( 'All styles', 'ruben-dance' ); ?></option>
					<?php foreach ( $style_options as $ruben_dance_id => $ruben_dance_name ) : ?>
						<option value="<?php echo esc_attr( (string) $ruben_dance_id ); ?>"><?php echo esc_html( $ruben_dance_name ); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="screen-reader-text" for="rd-calendar-location"><?php esc_html_e( 'Location', 'ruben-dance' ); ?></label>
				<select id="rd-calendar-location" class="rd-filter-chip rd-cal__filter-select">
					<option value="0"><?php esc_html_e( 'All locations', 'ruben-dance' ); ?></option>
					<?php foreach ( $location_options as $ruben_dance_location ) : ?>
						<option value="<?php echo esc_attr( (string) $ruben_dance_location['id'] ); ?>"><?php echo esc_html( (string) $ruben_dance_location['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
		</div>

		<div class="rd-cal__toolbar-row rd-cal__nav-row">
			<span class="rd-cal__nav-buttons">
				<button type="button" class="rd-cal__nav-btn" data-rd-cal-nav="prev" aria-label="<?php esc_attr_e( 'Previous period', 'ruben-dance' ); ?>">‹</button>
				<button type="button" class="rd-cal__nav-btn" data-rd-cal-nav="next" aria-label="<?php esc_attr_e( 'Next period', 'ruben-dance' ); ?>">›</button>
			</span>
			<span class="rd-cal__range-label" id="rd-cal-range-label" aria-live="polite"></span>
			<a class="rd-cal__skip-link" href="#rd-calendar-list-view"><?php esc_html_e( 'Skip to list view', 'ruben-dance' ); ?></a>
		</div>
	</div>

	<div id="rd-calendar" class="rd-calendar rd-card rd-cal__grid">
		<noscript>
			<p class="rd-notice rd-notice--warning"><?php esc_html_e( 'Enable JavaScript to see the calendar.', 'ruben-dance' ); ?></p>
		</noscript>
	</div>

	<section id="rd-calendar-list-view" class="rd-cal__list" tabindex="-1">
		<h2 class="rd-h2 rd-cal__list-heading"><?php esc_html_e( 'Upcoming lessons (list view)', 'ruben-dance' ); ?></h2>
		<p class="rd-text rd-cal__list-sub">
			<?php
			printf(
				/* translators: %d: number of days the list view covers. */
				esc_html__( 'Next %d days · works without JavaScript', 'ruben-dance' ),
				(int) $list_view_days
			);
			?>
		</p>
		<?php if ( array() === $upcoming_lessons ) : ?>
			<p class="rd-text"><?php esc_html_e( 'No upcoming lessons found.', 'ruben-dance' ); ?></p>
		<?php else : ?>
			<table class="rd-card rd-cal__list-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'ruben-dance' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Course', 'ruben-dance' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Location', 'ruben-dance' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $upcoming_lessons as $ruben_dance_lesson ) : ?>
						<?php
						$ruben_dance_is_cancelled = 'cancelled' === $ruben_dance_lesson['status'];
						$ruben_dance_is_workshop  = 'workshop' === $ruben_dance_lesson['type'];
						$ruben_dance_row_class    = 'rd-cal__list-row' . ( $ruben_dance_is_cancelled ? ' rd-cal__list-row--cancelled' : '' );
						?>
						<tr class="<?php echo esc_attr( $ruben_dance_row_class ); ?>">
							<td class="rd-cal__list-row__date">
								<strong><?php echo esc_html( trim( $ruben_dance_lesson['weekday_short'] . ' ' . $ruben_dance_lesson['date_short'] ) ); ?></strong>
								<span class="rd-cal__list-row__time"><?php echo esc_html( $ruben_dance_lesson['start'] ); ?></span>
							</td>
							<td class="rd-cal__list-row__course">
								<?php if ( $ruben_dance_is_workshop ) : ?>
									<span aria-hidden="true">◆ </span><span class="screen-reader-text"><?php esc_html_e( 'Workshop:', 'ruben-dance' ); ?> </span>
								<?php endif; ?>
								<?php if ( '' !== $ruben_dance_lesson['url'] ) : ?>
									<a href="<?php echo esc_url( $ruben_dance_lesson['url'] ); ?>"><?php echo esc_html( $ruben_dance_lesson['title'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $ruben_dance_lesson['title'] ); ?>
								<?php endif; ?>
								<?php if ( $ruben_dance_is_cancelled ) : ?>
									· <strong><?php esc_html_e( 'Cancelled', 'ruben-dance' ); ?></strong>
								<?php elseif ( 'moved' === $ruben_dance_lesson['status'] ) : ?>
									· <?php esc_html_e( 'Moved', 'ruben-dance' ); ?>
								<?php endif; ?>
							</td>
							<td class="rd-cal__list-row__location"><?php echo esc_html( $ruben_dance_lesson['location'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
</div>
