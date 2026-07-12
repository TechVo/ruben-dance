<?php
/**
 * Appended to a single `rd_course` post's content (see `Front\Course_Content`).
 * Design/screens.html #3c (mobile "Vypsané termíny" list + no-terms empty
 * state) and #4c (desktop — same list, in the main column next to the
 * theme's sidebar cards).
 *
 * Variables available: array<int,array> $terms (see
 * `Course_Content::terms_for_display()` for the exact shape).
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-catalog rd-course-terms">
	<h2 class="rd-h2 rd-course-terms__heading"><?php esc_html_e( 'Open terms', 'ruben-dance' ); ?></h2>

	<?php if ( array() === $terms ) : ?>
		<div class="rd-empty-state">
			<p class="rd-empty-state__text"><?php esc_html_e( 'No open terms for this course right now.', 'ruben-dance' ); ?></p>
			<p class="rd-text"><?php esc_html_e( "Write to us and we'll let you know as soon as a term opens.", 'ruben-dance' ); ?></p>
			<a class="rd-btn rd-btn--secondary" href="<?php echo esc_url( home_url( '/' ) . '#kontakt' ); ?>"><?php esc_html_e( 'Contact us', 'ruben-dance' ); ?></a>
		</div>
	<?php else : ?>
		<div class="rd-term-list">
			<?php foreach ( $terms as $ruben_dance_term ) : ?>
				<div class="rd-term-card<?php echo 'workshop' === $ruben_dance_term['type'] ? ' rd-term-card--workshop' : ''; ?>">
					<div class="rd-term-card__info">
						<p class="rd-term-card__schedule"><strong><?php echo esc_html( trim( $ruben_dance_term['weekday'] . ' ' . $ruben_dance_term['time'] ) ); ?></strong></p>
						<p class="rd-term-card__schedule">
							<?php echo esc_html( $ruben_dance_term['season'] ); ?>
							<?php if ( '' !== $ruben_dance_term['location'] ) : ?>
								· <?php echo esc_html( $ruben_dance_term['location'] ); ?>
							<?php endif; ?>
						</p>
						<p class="rd-term-card__price">
							<?php if ( null !== $ruben_dance_term['early_bird'] ) : ?>
								<span class="rd-term-card__price-now rd-term-card__price-now--early"><?php echo esc_html( $ruben_dance_term['early_bird']['price'] ); ?> Kč</span>
								<span class="rd-price__base-strike"><?php echo esc_html( $ruben_dance_term['price'] ); ?> Kč</span>
								<span class="rd-badge rd-badge--early">
									<?php
									printf(
										/* translators: %s: early-bird deadline date. */
										esc_html__( 'Early-bird until %s', 'ruben-dance' ),
										esc_html( $ruben_dance_term['early_bird']['until'] )
									);
									?>
								</span>
							<?php else : ?>
								<span class="rd-term-card__price-now"><?php echo esc_html( $ruben_dance_term['price'] ); ?> Kč</span>
							<?php endif; ?>
							<?php if ( 'workshop' === $ruben_dance_term['type'] ) : ?>
								<span class="rd-badge rd-badge--workshop"><?php esc_html_e( 'Workshop', 'ruben-dance' ); ?></span>
							<?php endif; ?>
							<?php if ( $ruben_dance_term['is_full'] ) : ?>
								<span class="rd-badge rd-badge--full"><?php esc_html_e( "Full — we'll contact you", 'ruben-dance' ); ?></span>
							<?php endif; ?>
						</p>
					</div>
					<a class="rd-btn <?php echo $ruben_dance_term['is_full'] ? 'rd-btn--secondary' : 'rd-btn--primary'; ?> rd-term-card__cta" href="<?php echo esc_url( $ruben_dance_term['enroll_url'] ); ?>"><?php esc_html_e( 'Enroll', 'ruben-dance' ); ?></a>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
