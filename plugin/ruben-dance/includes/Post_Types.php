<?php
/**
 * Custom post type `rd_course`: the abstract, translatable course content.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Post_Types.
 *
 * Registers `rd_course` (spec §3.1: "the abstract course as public content:
 * name, description, level, dance style, photos" — a CPT so it gets the
 * editor, permalinks, SEO and media handling for free; the structured,
 * per-term data lives in `wp_rd_course_term`, not here).
 *
 * Also declares the post type translatable to Polylang via the
 * `pll_get_post_types` filter — the documented way to make a custom post
 * type translatable without relying on a checkbox in Polylang's settings
 * screen, so a fresh `wp-env start` needs no manual admin step. The filter
 * registration itself is harmless when Polylang is absent (the hook simply
 * never fires), which keeps this class safe to load either way.
 */
class Post_Types {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const COURSE = 'rd_course';

	/**
	 * Hook registration. Per Polylang's own documentation the
	 * `pll_get_post_types` filter "must be added soon in the WordPress
	 * loading process", so it is added immediately here rather than
	 * deferred to `init`.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_course' ) );
		add_filter( 'pll_get_post_types', array( self::class, 'add_translatable_post_type' ) );
	}

	/**
	 * Register the `rd_course` post type.
	 */
	public static function register_course(): void {
		register_post_type(
			self::COURSE,
			array(
				'labels'       => array(
					'name'               => __( 'Courses', 'ruben-dance' ),
					'singular_name'      => __( 'Course', 'ruben-dance' ),
					'add_new'            => __( 'Add New', 'ruben-dance' ),
					'add_new_item'       => __( 'Add New Course', 'ruben-dance' ),
					'edit_item'          => __( 'Edit Course', 'ruben-dance' ),
					'new_item'           => __( 'New Course', 'ruben-dance' ),
					'view_item'          => __( 'View Course', 'ruben-dance' ),
					'view_items'         => __( 'View Courses', 'ruben-dance' ),
					'search_items'       => __( 'Search Courses', 'ruben-dance' ),
					'not_found'          => __( 'No courses found', 'ruben-dance' ),
					'not_found_in_trash' => __( 'No courses found in Trash', 'ruben-dance' ),
					'all_items'          => __( 'All Courses', 'ruben-dance' ),
					'archives'           => __( 'Course Archives', 'ruben-dance' ),
					'menu_name'          => __( 'Courses', 'ruben-dance' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-universal-access-alt',
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'rewrite'      => array( 'slug' => 'course' ),
			)
		);
	}

	/**
	 * Add `rd_course` to Polylang's list of translatable post types.
	 *
	 * @param string[] $post_types Post type names, as array keys and values.
	 * @return string[]
	 */
	public static function add_translatable_post_type( array $post_types ): array {
		$post_types[ self::COURSE ] = self::COURSE;

		return $post_types;
	}
}
