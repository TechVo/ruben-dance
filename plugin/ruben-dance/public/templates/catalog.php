<?php
/**
 * `[rd_catalog]` template partial.
 *
 * Variables available: array<int,array{title:string,url:string,terms:array}>
 * $groups, array{style:int,level:int,location_id:int,weekday:int} $filters,
 * array<int,string> $style_options, $level_options, array<int,array> $location_options,
 * array<int,string> $weekday_options, string $page_url.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-catalog">
	<form method="get" action="<?php echo esc_url( $page_url ); ?>" class="rd-catalog-filters">
		<select name="style">
			<option value="0"><?php esc_html_e( 'All styles', 'ruben-dance' ); ?></option>
			<?php foreach ( $style_options as $ruben_dance_id => $ruben_dance_name ) : ?>
				<option value="<?php echo esc_attr( (string) $ruben_dance_id ); ?>" <?php selected( $filters['style'], $ruben_dance_id ); ?>><?php echo esc_html( $ruben_dance_name ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="level">
			<option value="0"><?php esc_html_e( 'All levels', 'ruben-dance' ); ?></option>
			<?php foreach ( $level_options as $ruben_dance_id => $ruben_dance_name ) : ?>
				<option value="<?php echo esc_attr( (string) $ruben_dance_id ); ?>" <?php selected( $filters['level'], $ruben_dance_id ); ?>><?php echo esc_html( $ruben_dance_name ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="location_id">
			<option value="0"><?php esc_html_e( 'All locations', 'ruben-dance' ); ?></option>
			<?php foreach ( $location_options as $ruben_dance_location ) : ?>
				<option value="<?php echo esc_attr( (string) $ruben_dance_location['id'] ); ?>" <?php selected( $filters['location_id'], (int) $ruben_dance_location['id'] ); ?>><?php echo esc_html( (string) $ruben_dance_location['name'] ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="weekday">
			<option value="0"><?php esc_html_e( 'Any weekday', 'ruben-dance' ); ?></option>
			<?php foreach ( $weekday_options as $ruben_dance_number => $ruben_dance_label ) : ?>
				<option value="<?php echo esc_attr( (string) $ruben_dance_number ); ?>" <?php selected( $filters['weekday'], $ruben_dance_number ); ?>><?php echo esc_html( $ruben_dance_label ); ?></option>
			<?php endforeach; ?>
		</select>

		<button type="submit"><?php esc_html_e( 'Filter', 'ruben-dance' ); ?></button>
	</form>

	<?php if ( array() === $groups ) : ?>
		<p class="rd-notice"><?php esc_html_e( 'No open terms match your filters right now.', 'ruben-dance' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $groups as $ruben_dance_group ) : ?>
		<section class="rd-catalog-course">
			<h2><a href="<?php echo esc_url( $ruben_dance_group['url'] ); ?>"><?php echo esc_html( $ruben_dance_group['title'] ); ?></a></h2>

			<div class="rd-term-list">
				<?php foreach ( $ruben_dance_group['terms'] as $ruben_dance_term ) : ?>
					<div class="rd-term-card<?php echo 'workshop' === $ruben_dance_term['type'] ? ' rd-term-card--workshop' : ''; ?>">
						<?php if ( 'workshop' === $ruben_dance_term['type'] ) : ?>
							<span class="rd-badge rd-badge--workshop"><?php esc_html_e( 'Workshop', 'ruben-dance' ); ?></span>
						<?php endif; ?>
						<?php if ( $ruben_dance_term['is_full'] ) : ?>
							<span class="rd-badge rd-badge--full"><?php esc_html_e( 'Full', 'ruben-dance' ); ?></span>
						<?php endif; ?>

						<p class="rd-term-schedule"><?php echo esc_html( trim( $ruben_dance_term['weekday'] . ' ' . $ruben_dance_term['time'] ) ); ?></p>
						<p class="rd-term-season"><?php echo esc_html( $ruben_dance_term['season'] ); ?></p>
						<p class="rd-term-location"><?php echo esc_html( $ruben_dance_term['location'] ); ?></p>

						<p class="rd-term-price">
							<?php if ( null !== $ruben_dance_term['early_bird'] ) : ?>
								<span class="rd-price-early">
									<?php
									printf(
										/* translators: 1: early-bird price, 2: deadline date. */
										esc_html__( 'Early bird: %1$s Kč until %2$s', 'ruben-dance' ),
										esc_html( $ruben_dance_term['early_bird']['price'] ),
										esc_html( $ruben_dance_term['early_bird']['until'] )
									);
									?>
								</span>
								<span class="rd-price-strike">
									<?php
									/* translators: %s: regular price. */
									printf( esc_html__( '(regular %s Kč)', 'ruben-dance' ), esc_html( $ruben_dance_term['price'] ) );
									?>
								</span>
							<?php else : ?>
								<?php
								/* translators: %s: price. */
								printf( esc_html__( '%s Kč', 'ruben-dance' ), esc_html( $ruben_dance_term['price'] ) );
								?>
							<?php endif; ?>
						</p>

						<?php if ( '' !== $ruben_dance_term['note'] ) : ?>
							<p class="rd-term-note"><?php echo esc_html( $ruben_dance_term['note'] ); ?></p>
						<?php endif; ?>

						<a class="rd-button" href="<?php echo esc_url( $ruben_dance_term['enroll_url'] ); ?>"><?php esc_html_e( 'Enroll', 'ruben-dance' ); ?></a>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endforeach; ?>
</div>
