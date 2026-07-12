<?php
/**
 * Homepage — design/screens.html #1a (mobile 390), #2c (tablet 834), #2b
 * (desktop 1280). Layout is mobile-first (base rules = #1a), with the #2c/
 * #2b changes layered on top at the ~768px/~1024px breakpoints in
 * assets/theme.css.
 *
 * Course cards in "Vyberte si kurz" pull real `rd_course` posts via
 * rd_theme_homepage_courses() (functions.php) when the plugin is active;
 * see that function's docblock for why a plain post query is enough here.
 * All photography is a self-hosted gradient placeholder
 * (rd_theme_placeholder_photo()) — never a hotlink to ruben-dance.cz.
 *
 * @package RubenDanceTheme
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

get_header();

$rd_courses = rd_theme_homepage_courses( 4 );
?>

<article class="rd-home">

	<!-- Hero (#1a/#2c/#2b) -->
	<section class="rd-hero">
		<div class="rd-hero__circle" aria-hidden="true"></div>

		<div class="rd-hero__content">
			<h1 class="rd-h1 rd-hero__heading">
				<?php esc_html_e( 'Tančete tak,', 'ruben-dance-theme' ); ?><br class="rd-hero__break">
				<?php esc_html_e( 'jak se tančí', 'ruben-dance-theme' ); ?><br class="rd-hero__break">
				<span class="rd-hero__accent"><?php esc_html_e( 'v Karibiku.', 'ruben-dance-theme' ); ?></span>
			</h1>
			<p class="rd-text rd-hero__lead"><?php esc_html_e( 'Kurzy salsy a bachaty v Praze pod vedením rodilých tanečníků. Učíme od roku 1994.', 'ruben-dance-theme' ); ?></p>
			<div class="rd-hero__ctas">
				<a class="rd-btn rd-btn--primary rd-hero__cta" href="<?php echo esc_url( rd_theme_catalog_url() ); ?>"><?php esc_html_e( 'Vybrat kurz', 'ruben-dance-theme' ); ?></a>
				<a class="rd-btn rd-btn--secondary rd-hero__cta" href="<?php echo esc_url( home_url( '/' ) . '#kontakt' ); ?>"><?php esc_html_e( 'Zkušební lekce', 'ruben-dance-theme' ); ?></a>
			</div>
		</div>

		<div class="rd-hero__photo">
			<img
				src="<?php echo esc_attr( rd_theme_placeholder_photo( '#F08A24', '#E8604C' ) ); ?>"
				alt="<?php esc_attr_e( 'Tanečníci salsy a bachaty na lekci Ruben Dance', 'ruben-dance-theme' ); ?>"
			>
			<div class="rd-hero__rating"><?php esc_html_e( '★ 4,9 · hodnocení absolventů', 'ruben-dance-theme' ); ?></div>
		</div>
	</section>

	<!-- Trust strip -->
	<section class="rd-trust-strip">
		<div class="rd-trust-strip__item">
			<div class="rd-trust-strip__value">32 <?php esc_html_e( 'let', 'ruben-dance-theme' ); ?></div>
			<div class="rd-trust-strip__label"><?php esc_html_e( 'zkušeností s výukou', 'ruben-dance-theme' ); ?></div>
		</div>
		<div class="rd-trust-strip__item">
			<div class="rd-trust-strip__value">7 <?php esc_html_e( 'míst', 'ruben-dance-theme' ); ?></div>
			<div class="rd-trust-strip__label"><?php esc_html_e( 'po celé Praze', 'ruben-dance-theme' ); ?></div>
		</div>
		<div class="rd-trust-strip__item">
			<div class="rd-trust-strip__value"><?php esc_html_e( 'Rodilí lektoři', 'ruben-dance-theme' ); ?></div>
			<div class="rd-trust-strip__label"><?php esc_html_e( 'přímo z Karibiku', 'ruben-dance-theme' ); ?></div>
		</div>
	</section>

	<!-- Co se u nás naučíte -->
	<section class="rd-section">
		<h2 class="rd-h2"><?php esc_html_e( 'Co se u nás naučíte', 'ruben-dance-theme' ); ?></h2>
		<div class="rd-benefits">
			<div class="rd-card rd-benefit">
				<div class="rd-benefit__number rd-benefit__number--yellow">1</div>
				<div class="rd-benefit__title"><?php esc_html_e( 'Tančíte už po první lekci', 'ruben-dance-theme' ); ?></div>
				<div class="rd-text rd-benefit__text"><?php esc_html_e( 'Základní kroky salsy nebo bachaty zvládnete hned — bez partnera i s ním.', 'ruben-dance-theme' ); ?></div>
			</div>
			<div class="rd-card rd-benefit">
				<div class="rd-benefit__number rd-benefit__number--orange">2</div>
				<div class="rd-benefit__title"><?php esc_html_e( 'Porozumíte hudbě', 'ruben-dance-theme' ); ?></div>
				<div class="rd-text rd-benefit__text"><?php esc_html_e( 'Netančíte naučené otočky — rozumíte tomu, co a proč tančíte.', 'ruben-dance-theme' ); ?></div>
			</div>
			<div class="rd-card rd-benefit">
				<div class="rd-benefit__number rd-benefit__number--coral">3</div>
				<div class="rd-benefit__title"><?php esc_html_e( 'Zatančíte si kdekoliv na světě', 'ruben-dance-theme' ); ?></div>
				<div class="rd-text rd-benefit__text"><?php esc_html_e( 'Autentický styl, který poznají v Havaně i v Praze.', 'ruben-dance-theme' ); ?></div>
			</div>
		</div>
	</section>

	<!-- Vyberte si kurz -->
	<section class="rd-section">
		<div class="rd-section__heading-row">
			<h2 class="rd-h2"><?php esc_html_e( 'Vyberte si kurz', 'ruben-dance-theme' ); ?></h2>
			<a class="rd-btn rd-btn--text rd-section__all-link" href="<?php echo esc_url( rd_theme_catalog_url() ); ?>"><?php esc_html_e( 'Všechny kurzy →', 'ruben-dance-theme' ); ?></a>
		</div>

		<?php if ( array() !== $rd_courses ) : ?>
			<div class="rd-courses">
				<?php foreach ( $rd_courses as $rd_course ) : ?>
					<div class="rd-course-card">
						<?php echo $rd_course['thumbnail_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built in rd_theme_homepage_courses(), already escaped. ?>
						<div class="rd-course-card__body">
							<div>
								<div class="rd-course-card__title"><?php echo esc_html( $rd_course['title'] ); ?></div>
								<div class="rd-course-card__subtitle"><?php echo esc_html( $rd_course['subtitle'] ); ?></div>
							</div>
							<a class="rd-btn rd-btn--small" href="<?php echo esc_url( $rd_course['url'] ); ?>"><?php esc_html_e( 'Přihlásit', 'ruben-dance-theme' ); ?></a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<div class="rd-empty-state">
				<div class="rd-empty-state__icon" aria-hidden="true">🕺</div>
				<p class="rd-empty-state__text"><?php esc_html_e( 'Kurzy se právě připravují.', 'ruben-dance-theme' ); ?></p>
				<a class="rd-empty-state__action" href="<?php echo esc_url( home_url( '/' ) . '#kontakt' ); ?>"><?php esc_html_e( 'Ozvěte se nám', 'ruben-dance-theme' ); ?></a>
			</div>
		<?php endif; ?>

		<div class="rd-chips-row">
			<span class="rd-filter-chip"><?php esc_html_e( 'pro děti', 'ruben-dance-theme' ); ?></span>
			<span class="rd-filter-chip"><?php esc_html_e( 'pro ženy', 'ruben-dance-theme' ); ?></span>
			<span class="rd-filter-chip"><?php esc_html_e( 'pro seniory', 'ruben-dance-theme' ); ?></span>
			<span class="rd-filter-chip"><?php esc_html_e( 'letní intenzivní', 'ruben-dance-theme' ); ?></span>
		</div>
	</section>

	<!-- Ruben profile + testimonial -->
	<section class="rd-section rd-profile-row">
		<div class="rd-profile-card">
			<img
				class="rd-profile-card__photo"
				src="<?php echo esc_attr( rd_theme_placeholder_photo( '#F5B840', '#F08A24' ) ); ?>"
				alt="<?php esc_attr_e( 'Ruben Peguero, lektor tance', 'ruben-dance-theme' ); ?>"
			>
			<div class="rd-profile-card__body">
				<div class="rd-eyebrow"><?php esc_html_e( 'Váš lektor', 'ruben-dance-theme' ); ?></div>
				<div class="rd-profile-card__name"><?php esc_html_e( 'Ruben Peguero', 'ruben-dance-theme' ); ?></div>
				<p class="rd-text"><?php esc_html_e( 'Tanečník a choreograf z Dominikánské republiky. Salsu a bachatu učí v Česku od roku 1994 — s trpělivostí, humorem a láskou ke karibské kultuře.', 'ruben-dance-theme' ); ?></p>
			</div>
		</div>

		<blockquote class="rd-testimonial">
			<p class="rd-testimonial__quote">&#8222;<?php esc_html_e( 'Není nikdo, koho by Ruben nenaučil tančit.', 'ruben-dance-theme' ); ?><span class="rd-testimonial__quote-mark">&#8220;</span></p>
			<cite class="rd-testimonial__author">— <?php esc_html_e( 'absolventka kurzu salsy', 'ruben-dance-theme' ); ?></cite>
		</blockquote>
	</section>

	<!-- CTA band -->
	<section class="rd-cta-band">
		<div class="rd-cta-band__title"><?php esc_html_e( 'První lekce nezávazně.', 'ruben-dance-theme' ); ?></div>
		<a class="rd-cta-band__button" href="<?php echo esc_url( home_url( '/' ) . '#kontakt' ); ?>"><?php esc_html_e( 'Chci tančit', 'ruben-dance-theme' ); ?></a>
		<div class="rd-cta-band__contact">
			<a href="tel:+420776337877">776 337 877</a>
			<a href="mailto:info@ruben-dance.cz">info@ruben-dance.cz</a>
			<a href="https://www.instagram.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Instagram', 'ruben-dance-theme' ); ?></a>
		</div>
	</section>

</article>

<?php
get_footer();
