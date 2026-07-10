<?php
/**
 * `[rd_calendar]` template partial.
 *
 * Variables available: array<int,string> $style_options (term ID => name),
 * array<int,array> $location_options (active locations).
 *
 * The calendar grid itself is rendered client-side by `calendar.js` into
 * `#rd-calendar`; this template only provides the filter controls and the
 * mount point (plus a no-JS fallback notice).
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

	<div id="rd-calendar" class="rd-calendar">
		<noscript>
			<p class="rd-notice rd-notice--warning"><?php esc_html_e( 'Enable JavaScript to see the calendar.', 'ruben-dance' ); ?></p>
		</noscript>
	</div>
</div>
