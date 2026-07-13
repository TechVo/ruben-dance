<?php
/**
 * Template Name: Právní stránka
 *
 * Reusable page template for the legal pages (obchodní podmínky, zásady
 * ochrany osobních údajů — currently lawyer-placeholder content, see
 * `Cli\Seed_Command::LEGAL_PAGES`) and any future one built the same way: an
 * "eyebrow + H1 + effective-date line + table of contents + prose" layout
 * (design/screens.html #3i mobile / #4j desktop), assignable from the block
 * editor's Page Attributes panel like any other page template — this file
 * never hardcodes which page uses it.
 *
 * The table of contents is generated from the page's own `<h2>` headings
 * (`rd_theme_legal_toc()`) rather than duplicated by hand, so it can never
 * drift out of sync with whatever section headings the (lawyer-supplied)
 * body content actually contains.
 *
 * "Účinné od" (effective date) has no dedicated admin field — it falls back
 * to the page's last-modified date, which already updates itself every time
 * an owner edits and republishes the legal text, the same "no new UI needed"
 * reasoning `rd_theme_legal_toc()`'s doc comment uses.
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

	$rd_legal_content = apply_filters( 'the_content', get_the_content() );
	$rd_legal_toc     = rd_theme_legal_toc( is_string( $rd_legal_content ) ? $rd_legal_content : '' );
	?>

	<div class="rd-page rd-legal">
		<div class="rd-eyebrow rd-legal__eyebrow"><?php esc_html_e( 'Právní dokument', 'ruben-dance-theme' ); ?></div>
		<h1 class="rd-h1 rd-legal__title"><?php the_title(); ?></h1>
		<div class="rd-legal__date">
			<?php
			printf(
				/* translators: %s: formatted date. */
				esc_html__( 'Účinné od %s', 'ruben-dance-theme' ),
				esc_html( get_the_modified_date() )
			);
			?>
		</div>

		<div class="rd-legal__layout">
			<?php if ( array() !== $rd_legal_toc['items'] ) : ?>
				<nav class="rd-card rd-legal__toc" aria-label="<?php esc_attr_e( 'Obsah', 'ruben-dance-theme' ); ?>">
					<div class="rd-legal__toc-title"><?php esc_html_e( 'Obsah', 'ruben-dance-theme' ); ?></div>
					<ol class="rd-legal__toc-list">
						<?php foreach ( $rd_legal_toc['items'] as $rd_index => $rd_item ) : ?>
							<li><a href="#<?php echo esc_attr( $rd_item['id'] ); ?>"><?php echo esc_html( ( $rd_index + 1 ) . '. ' . $rd_item['text'] ); ?></a></li>
						<?php endforeach; ?>
					</ol>
				</nav>
			<?php endif; ?>

			<div class="rd-legal__content">
				<?php echo $rd_legal_toc['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already passed through the `the_content` filter chain (core's own sanitization for post content); rd_theme_legal_toc() only adds an `id="…"` attribute built from sanitize_title(). ?>
			</div>
		</div>
	</div>

	<?php
endwhile;

get_footer();
