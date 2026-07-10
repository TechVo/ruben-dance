<?php
/**
 * Appends the course's open terms + Enroll buttons to the `rd_course` single
 * view (spec F1: "Each course has a detail page ... listing its open terms
 * with day/time/place/price ... and an Enroll button").
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Admin\Terms_List_Table;
use RubenDance\Lang;
use RubenDance\Post_Types;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Services\Term_Presenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Course_Content.
 *
 * A `the_content` filter rather than a full single-{post-type} template
 * override (spec M08 task list: "Course detail (CPT template/filter)") —
 * keeps this milestone independent of whatever theme the final site uses,
 * the same "shortcodes on normal pages, theme-independent" reasoning spec §5
 * gives for `[rd_catalog]`/`[rd_enroll]`. Only ever appends past the theme's
 * own rendering of the course's title/editor content, and only on the
 * canonical single view (never excerpts/archives/REST).
 */
class Course_Content {

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_filter( 'the_content', array( self::class, 'append_terms' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_styles' ) );
	}

	/**
	 * Enqueue the shared catalog/enroll stylesheet when viewing a single
	 * course (badges, term cards reused here).
	 */
	public static function maybe_enqueue_styles(): void {
		if ( ! is_singular( Post_Types::COURSE ) ) {
			return;
		}

		wp_enqueue_style(
			'rd-front-catalog',
			plugins_url( 'public/assets/front-catalog.css', RUBEN_DANCE_PLUGIN_FILE ),
			array(),
			RUBEN_DANCE_VERSION
		);
	}

	/**
	 * Append the term list to the single course's content.
	 *
	 * @param string $content Original post content.
	 * @return string
	 */
	public static function append_terms( string $content ): string {
		if ( ! is_singular( Post_Types::COURSE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		global $post;

		$lang_helper = Lang::create_default();
		$lang        = $lang_helper->current();
		$course_id   = $lang_helper->resolve_post( (int) $post->ID, Lang::CS );

		$rows = ( new Course_Term_Repository() )->open_for_courses( array( $course_id ) );

		if ( array() === $rows ) {
			return $content . self::render_template( 'course-terms', array( 'terms' => array() ) );
		}

		$presenter     = new Term_Presenter();
		$enrollments   = new Enrollment_Repository();
		$location_repo = new Location_Repository();
		$today         = current_time( 'Y-m-d' );
		$locations     = array();

		$display_terms = array();

		foreach ( $rows as $term ) {
			$location_id = (int) $term['location_id'];

			if ( ! isset( $locations[ $location_id ] ) ) {
				$locations[ $location_id ] = $location_repo->find( $location_id );
			}

			$location     = $locations[ $location_id ];
			$active_count = $enrollments->count_active_for_term( (int) $term['id'] );

			$display_terms[] = array(
				'id'         => (int) $term['id'],
				'type'       => (string) $term['type'],
				'season'     => Lang::EN === $lang && '' !== trim( (string) $term['season_label_en'] ) ? (string) $term['season_label_en'] : (string) $term['season_label_cs'],
				'weekday'    => Terms_List_Table::weekday_labels()[ (int) $term['weekday'] ] ?? '',
				'time'       => Terms_List_Table::format_time( (string) $term['start_time'] ) . '–' . Terms_List_Table::format_time( (string) $term['end_time'] ),
				'location'   => null === $location ? '' : (string) $location['name'],
				'price'      => (string) $term['price'],
				'early_bird' => $presenter->early_bird( $term, $today ),
				'is_full'    => $presenter->is_full( $term, $active_count ),
				'enroll_url' => add_query_arg( 'term_id', (int) $term['id'], Enroll_Page::page_url( $lang ) ),
			);
		}

		return $content . self::render_template( 'course-terms', array( 'terms' => $display_terms ) );
	}

	/**
	 * Include a template partial with `$vars` extracted as local variables.
	 * See `Catalog_Page::render_template()` for why this is duplicated
	 * rather than shared with M07's `Shortcodes` class.
	 *
	 * @param string               $name Template file name, without `.php`, in `public/templates/`.
	 * @param array<string, mixed> $vars Variables made available to the template.
	 * @return string
	 */
	private static function render_template( string $name, array $vars ): string {
		$path = RUBEN_DANCE_PLUGIN_DIR . 'public/templates/' . $name . '.php';

		if ( ! file_exists( $path ) ) {
			return '';
		}

		extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template partials are the standard WP pattern for this (get_template_part()-style); $vars is entirely our own array, never user input.

		ob_start();
		include $path;

		return (string) ob_get_clean();
	}
}
