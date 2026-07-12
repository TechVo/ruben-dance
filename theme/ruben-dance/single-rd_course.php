<?php
/**
 * Course detail — design/screens.html #3c (mobile 390, incl. no-terms
 * state) and #4c (desktop 1280; tablet 834 shares the same 2-column grid,
 * just narrower margins, per that anchor's own annotation).
 *
 * Without this template WordPress falls back to index.php's archive-style
 * loop (title + excerpt only, the_content() never called) — which meant
 * `RubenDance\Front\Course_Content`'s `the_content` filter (open terms +
 * Enroll buttons) never actually ran on a real course page. This template
 * fixes that simply by calling the_content() like a normal singular
 * template: the plugin's own term list still renders itself (course-terms.php),
 * this file only supplies the surrounding page chrome the plugin
 * deliberately doesn't own (breadcrumb, badges, gallery, the 2-column
 * layout, and the right-column "nearest open term"/location/instructor
 * cards, all outside the the_content() blob).
 *
 * The right-column cards reuse `RubenDance\Front\Course_Content::terms_for_display()`
 * (the exact same query + business rules the plugin's own term list uses)
 * rather than duplicating that logic — guarded by class_exists() the same
 * way every other plugin touchpoint in this theme is, so the page still
 * renders (minus those cards) if the plugin is inactive.
 *
 * @package RubenDanceTheme
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

get_header();

while ( have_posts() ) :
	the_post();

	$rd_course_terms  = array();
	$rd_first_term    = null;
	$rd_nearest_term  = null;

	if ( class_exists( '\RubenDance\Front\Course_Content' ) && class_exists( '\RubenDance\Lang' ) ) {
		$rd_lang_helper  = \RubenDance\Lang::create_default();
		$rd_lang         = $rd_lang_helper->current();
		$rd_course_id_cs = $rd_lang_helper->resolve_post( get_the_ID(), \RubenDance\Lang::CS );
		$rd_course_terms = \RubenDance\Front\Course_Content::terms_for_display( $rd_course_id_cs, $rd_lang );

		if ( array() !== $rd_course_terms ) {
			$rd_first_term = $rd_course_terms[0];

			foreach ( $rd_course_terms as $rd_term ) {
				if ( ! $rd_term['is_full'] ) {
					$rd_nearest_term = $rd_term;
					break;
				}
			}
		}
	}

	$rd_style = '';
	$rd_level = '';

	$rd_style_terms = get_the_terms( get_the_ID(), 'rd_dance_style' );
	if ( is_array( $rd_style_terms ) && array() !== $rd_style_terms ) {
		$rd_style = $rd_style_terms[0]->name;
	}

	$rd_level_terms = get_the_terms( get_the_ID(), 'rd_level' );
	if ( is_array( $rd_level_terms ) && array() !== $rd_level_terms ) {
		$rd_level = $rd_level_terms[0]->name;
	}

	if ( has_post_thumbnail() ) {
		$rd_photo_1 = get_the_post_thumbnail_url( get_the_ID(), 'large' );
	} else {
		$rd_photo_1 = rd_theme_placeholder_photo( '#F08A24', '#E8604C' );
	}
	$rd_photo_2 = rd_theme_placeholder_photo( '#E8604C', '#F5B840' );
	?>

	<article class="rd-course-detail">
		<div class="rd-course-detail__layout">
			<div class="rd-course-detail__main">
				<a class="rd-course-detail__breadcrumb" href="<?php echo esc_url( rd_theme_catalog_url() ); ?>">
					<?php esc_html_e( 'Kurzy', 'ruben-dance-theme' ); ?><?php if ( '' !== $rd_style ) : ?><span class="rd-course-detail__breadcrumb-style"> / <?php echo esc_html( $rd_style ); ?></span><?php endif; ?>
				</a>

				<?php if ( '' !== $rd_style || '' !== $rd_level ) : ?>
					<div class="rd-course-detail__badges">
						<?php if ( '' !== $rd_style ) : ?>
							<span class="rd-course-detail__badge"><?php echo esc_html( $rd_style ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $rd_level ) : ?>
							<span class="rd-course-detail__badge rd-course-detail__badge--level"><?php echo esc_html( $rd_level ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<h1 class="rd-course-detail__title"><?php the_title(); ?></h1>

				<div class="rd-course-detail__gallery">
					<img src="<?php echo esc_attr( $rd_photo_1 ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy">
					<img src="<?php echo esc_attr( $rd_photo_2 ); ?>" alt="" aria-hidden="true" loading="lazy">
				</div>

				<?php
				$rd_meta_bits = array();

				if ( null !== $rd_first_term ) {
					$rd_times = explode( '–', (string) $rd_first_term['time'] );

					if ( 2 === count( $rd_times ) ) {
						$rd_minutes = ( (int) strtotime( '1970-01-01 ' . $rd_times[1] ) - (int) strtotime( '1970-01-01 ' . $rd_times[0] ) ) / 60;

						if ( $rd_minutes > 0 ) {
							/* translators: %d: lesson length in minutes. */
							$rd_meta_bits[] = '⏱ ' . sprintf( __( 'lekce %d min', 'ruben-dance-theme' ), (int) $rd_minutes );
						}
					}

					if ( 0 < (int) $rd_first_term['capacity'] ) {
						/* translators: %d: maximum number of participants. */
						$rd_meta_bits[] = '👥 ' . sprintf( __( 'max %d osob', 'ruben-dance-theme' ), (int) $rd_first_term['capacity'] );
					}
				}
				?>

				<?php if ( array() !== $rd_meta_bits ) : ?>
					<div class="rd-course-detail__meta">
						<?php foreach ( $rd_meta_bits as $rd_meta_bit ) : ?>
							<span><?php echo esc_html( $rd_meta_bit ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="rd-course-detail__content">
					<?php the_content(); ?>
				</div>
			</div>

			<aside class="rd-course-aside">
				<?php if ( null !== $rd_nearest_term ) : ?>
					<div class="rd-course-highlight">
						<div class="rd-course-highlight__eyebrow"><?php esc_html_e( 'Nejbližší volný termín', 'ruben-dance-theme' ); ?></div>
						<div class="rd-course-highlight__title">
							<?php
							echo esc_html(
								trim( $rd_nearest_term['weekday'] . ' ' . explode( '–', $rd_nearest_term['time'] )[0] )
								. ( '' !== $rd_nearest_term['location'] ? ' · ' . $rd_nearest_term['location'] : '' )
							);
							?>
						</div>
						<div class="rd-course-highlight__meta">
							<?php
							printf(
								/* translators: %s: term start date, e.g. "14. September". */
								esc_html__( 'začínáme %s', 'ruben-dance-theme' ),
								esc_html( date_i18n( 'j. F', strtotime( $rd_nearest_term['date_from'] ) ) )
							);
							?>
							·
							<?php if ( null !== $rd_nearest_term['early_bird'] ) : ?>
								<strong><?php echo esc_html( $rd_nearest_term['early_bird']['price'] ); ?> Kč</strong> <s><?php echo esc_html( $rd_nearest_term['price'] ); ?> Kč</s>
							<?php else : ?>
								<strong><?php echo esc_html( $rd_nearest_term['price'] ); ?> Kč</strong>
							<?php endif; ?>
						</div>
						<a class="rd-btn rd-btn--primary rd-course-highlight__cta" href="<?php echo esc_url( $rd_nearest_term['enroll_url'] ); ?>"><?php esc_html_e( 'Přihlásit se', 'ruben-dance-theme' ); ?></a>
					</div>
				<?php endif; ?>

				<?php if ( null !== $rd_first_term && '' !== $rd_first_term['location'] ) : ?>
					<div class="rd-card rd-course-location">
						<div class="rd-course-location__icon" aria-hidden="true">📍</div>
						<div class="rd-course-location__text">
							<strong><?php echo esc_html( $rd_first_term['location'] ); ?></strong><br>
							<?php if ( '' !== $rd_first_term['location_address'] ) : ?>
								<span class="rd-course-location__address"><?php echo esc_html( $rd_first_term['location_address'] ); ?></span><br>
							<?php endif; ?>
							<?php if ( '' !== $rd_first_term['location_map_url'] ) : ?>
								<a class="rd-course-location__link" href="<?php echo esc_url( $rd_first_term['location_map_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Otevřít mapu', 'ruben-dance-theme' ); ?> →</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( null !== $rd_first_term && '' !== $rd_first_term['instructor'] ) : ?>
					<div class="rd-card rd-course-instructor">
						<div class="rd-course-instructor__photo" aria-hidden="true"></div>
						<div class="rd-course-instructor__text">
							<?php esc_html_e( 'Učí', 'ruben-dance-theme' ); ?> <strong><?php echo esc_html( $rd_first_term['instructor'] ); ?></strong>
							<br><span class="rd-course-instructor__role"><?php esc_html_e( 'lektor kurzu', 'ruben-dance-theme' ); ?></span>
						</div>
					</div>
				<?php endif; ?>
			</aside>
		</div>
	</article>

	<?php
endwhile;

get_footer();
