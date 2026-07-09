<?php
/**
 * Taxonomies `rd_dance_style` and `rd_level`, attached to `rd_course`.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Taxonomies.
 *
 * Both taxonomies exist so the F1 catalog filters (dance style, level) have
 * something to filter by; the catalog UI itself is out of this milestone's
 * scope (M08 territory). Marked translatable to Polylang the same way as
 * `Post_Types::COURSE` — see that class for why the filter is added eagerly
 * rather than on `init`, and why it is safe without Polylang installed.
 */
class Taxonomies {

	/**
	 * Dance style taxonomy slug (e.g. Salsa, Bachata).
	 *
	 * @var string
	 */
	const DANCE_STYLE = 'rd_dance_style';

	/**
	 * Level taxonomy slug (e.g. Beginner, Intermediate).
	 *
	 * @var string
	 */
	const LEVEL = 'rd_level';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_all' ) );
		add_filter( 'pll_get_taxonomies', array( self::class, 'add_translatable_taxonomies' ) );
	}

	/**
	 * Register both taxonomies against `rd_course`.
	 */
	public static function register_all(): void {
		register_taxonomy(
			self::DANCE_STYLE,
			array( Post_Types::COURSE ),
			array(
				'labels'       => array(
					'name'          => __( 'Dance Styles', 'ruben-dance' ),
					'singular_name' => __( 'Dance Style', 'ruben-dance' ),
					'search_items'  => __( 'Search Dance Styles', 'ruben-dance' ),
					'all_items'     => __( 'All Dance Styles', 'ruben-dance' ),
					'edit_item'     => __( 'Edit Dance Style', 'ruben-dance' ),
					'add_new_item'  => __( 'Add New Dance Style', 'ruben-dance' ),
					'new_item_name' => __( 'New Dance Style Name', 'ruben-dance' ),
					'menu_name'     => __( 'Dance Styles', 'ruben-dance' ),
				),
				'public'       => true,
				'hierarchical' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'dance-style' ),
			)
		);

		register_taxonomy(
			self::LEVEL,
			array( Post_Types::COURSE ),
			array(
				'labels'       => array(
					'name'          => __( 'Levels', 'ruben-dance' ),
					'singular_name' => __( 'Level', 'ruben-dance' ),
					'search_items'  => __( 'Search Levels', 'ruben-dance' ),
					'all_items'     => __( 'All Levels', 'ruben-dance' ),
					'edit_item'     => __( 'Edit Level', 'ruben-dance' ),
					'add_new_item'  => __( 'Add New Level', 'ruben-dance' ),
					'new_item_name' => __( 'New Level Name', 'ruben-dance' ),
					'menu_name'     => __( 'Levels', 'ruben-dance' ),
				),
				'public'       => true,
				'hierarchical' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'level' ),
			)
		);
	}

	/**
	 * Add both taxonomies to Polylang's list of translatable taxonomies.
	 *
	 * @param string[] $taxonomies Taxonomy names, as array keys and values.
	 * @return string[]
	 */
	public static function add_translatable_taxonomies( array $taxonomies ): array {
		$taxonomies[ self::DANCE_STYLE ] = self::DANCE_STYLE;
		$taxonomies[ self::LEVEL ]       = self::LEVEL;

		return $taxonomies;
	}
}
