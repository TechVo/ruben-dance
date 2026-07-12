<?php
/**
 * Default page template: a clean, centered container on the cream
 * background so plugin shortcode pages (/kurzy/, /muj-ucet/, /prihlaseni/,
 * …) sit correctly framed by the theme's header and footer. The shortcode
 * output itself (each wrapped in `.rd-app` by the plugin, see
 * plugin/ruben-dance/public/assets/rd-design.css's file docblock) supplies
 * all of its own screen-specific layout — this template only provides the
 * page-level container width/padding, nothing else.
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
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<h1 class="rd-h1 rd-page__title"><?php the_title(); ?></h1>

		<div class="rd-page__content">
			<?php the_content(); ?>
		</div>
	<?php endwhile; ?>
</div>

<?php
get_footer();
