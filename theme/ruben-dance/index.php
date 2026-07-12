<?php
/**
 * Fallback template (blog index / search / any request nothing more
 * specific matches). The site has no blogging feature in scope — this
 * exists only so WordPress always has *something* to render instead of a
 * fatal "no index.php" error, per the template hierarchy's own requirement
 * that every classic theme ship one.
 *
 * @package RubenDanceTheme
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

get_header();
?>

<div class="rd-page">
	<?php if ( have_posts() ) : ?>
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'rd-page__content' ); ?>>
				<h2 class="rd-h2"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<div class="rd-text"><?php the_excerpt(); ?></div>
			</article>
		<?php endwhile; ?>
	<?php else : ?>
		<div class="rd-empty-state">
			<p class="rd-empty-state__text"><?php esc_html_e( 'Nic tu není.', 'ruben-dance-theme' ); ?></p>
			<a class="rd-empty-state__action" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Zpět na hlavní stránku', 'ruben-dance-theme' ); ?></a>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();
