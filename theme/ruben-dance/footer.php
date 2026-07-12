<?php
/**
 * Site footer: dark cocoa panel with the logotype, required legal links,
 * contact details and a copyright/IČO line (design #3a "Patička s
 * povinnými odkazy").
 *
 * @package RubenDanceTheme
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

$rd_legal_links = rd_theme_footer_legal_links();
?>
</main><!-- #main -->

<footer class="rd-site-footer" id="kontakt">
	<div class="rd-site-footer__inner">
		<?php echo rd_theme_logo_html( 'rd-site-footer__logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rd_theme_logo_html() escapes internally. ?>

		<nav class="rd-site-footer__legal" aria-label="<?php esc_attr_e( 'Právní informace', 'ruben-dance-theme' ); ?>">
			<?php foreach ( $rd_legal_links as $rd_link ) : ?>
				<a href="<?php echo esc_url( $rd_link['url'] ); ?>"><?php echo esc_html( $rd_link['label'] ); ?></a>
			<?php endforeach; ?>
			<a href="#kontakt"><?php esc_html_e( 'Kontakt', 'ruben-dance-theme' ); ?></a>
		</nav>

		<div class="rd-site-footer__contact">
			<a href="tel:+420776337877">776 337 877</a>
			<a href="mailto:info@ruben-dance.cz">info@ruben-dance.cz</a>
		</div>
	</div>

	<div class="rd-site-footer__bottom">
		<?php
		printf(
			/* translators: 1: current year, 2: IČO placeholder — replace with the real company ID before launch. */
			esc_html__( '© %1$s Ruben-Dance · IČO %2$s', 'ruben-dance-theme' ),
			esc_html( gmdate( 'Y' ) ),
			'12345678'
		);
		?>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
