<?php
/**
 * Appended to a single `rd_course` post's content (see `Front\Course_Content`).
 *
 * Variables available: array<int,array> $terms.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-catalog rd-course-terms">
	<h2><?php esc_html_e( 'Open terms', 'ruben-dance' ); ?></h2>

	<?php if ( array() === $terms ) : ?>
		<p class="rd-notice"><?php esc_html_e( 'No open terms for this course right now — check back soon.', 'ruben-dance' ); ?></p>
	<?php else : ?>
		<div class="rd-term-list">
			<?php foreach ( $terms as $ruben_dance_term ) : ?>
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
						<?php else : ?>
							<?php
							/* translators: %s: price. */
							printf( esc_html__( '%s Kč', 'ruben-dance' ), esc_html( $ruben_dance_term['price'] ) );
							?>
						<?php endif; ?>
					</p>

					<a class="rd-button" href="<?php echo esc_url( $ruben_dance_term['enroll_url'] ); ?>"><?php esc_html_e( 'Enroll', 'ruben-dance' ); ?></a>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
