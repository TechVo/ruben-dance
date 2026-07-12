<?php
/**
 * Self-hosted gradient placeholder photo, for `rd_course` posts that have no
 * featured image.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Placeholder_Photo.
 *
 * A two-tone SVG gradient encoded as a `data:` URI — no network request, no
 * hotlinking a third-party image host. This is the plugin's own copy of the
 * same technique `ruben-dance` theme's `rd_theme_placeholder_photo()` uses
 * (see that function's docblock for the full reasoning); duplicated rather
 * than shared because the plugin must keep rendering a usable catalog page
 * regardless of which theme is active (see `Catalog_Page`/`Course_Content`
 * class docblocks) — it cannot call into the current theme's functions.php.
 */
class Placeholder_Photo {

	/**
	 * Brand-color gradient pairs (design/README.md "Barvy"), cycled by index
	 * so a grid of course cards doesn't render every placeholder identically.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	const GRADIENTS = array(
		array( '#F08A24', '#E8604C' ),
		array( '#E8604C', '#F5B840' ),
		array( '#F5B840', '#F08A24' ),
	);

	/**
	 * A `data:` URI gradient, cycling through `self::GRADIENTS` by index.
	 *
	 * @param int $index Any integer (e.g. the course's position in a list); only used modulo the gradient count.
	 * @return string
	 */
	public static function for_index( int $index ): string {
		$colors = self::GRADIENTS[ $index % count( self::GRADIENTS ) ];

		return self::gradient( $colors[0], $colors[1] );
	}

	/**
	 * Build the `data:` URI for a given pair of colors.
	 *
	 * @param string $from Gradient start color (hex).
	 * @param string $to   Gradient end color (hex).
	 * @return string
	 */
	public static function gradient( string $from, string $to ): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300">'
			. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
			. '<stop offset="0%" stop-color="' . $from . '"/>'
			. '<stop offset="100%" stop-color="' . $to . '"/>'
			. '</linearGradient></defs>'
			. '<rect width="400" height="300" fill="url(#g)"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- benign: encodes our own inline SVG as a data: URI, the standard representation for embedded images.
	}

	/**
	 * The photo to show for a course card: its featured image when set, else
	 * a gradient placeholder (design's "reuse D2's approach" instruction).
	 *
	 * @param int $post_id Course post ID.
	 * @param int $index   Position in the list, for gradient cycling.
	 * @return string Image URL or `data:` URI.
	 */
	public static function for_post( int $post_id, int $index ): string {
		if ( has_post_thumbnail( $post_id ) ) {
			$url = get_the_post_thumbnail_url( $post_id, 'medium_large' );

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return self::for_index( $index );
	}
}
