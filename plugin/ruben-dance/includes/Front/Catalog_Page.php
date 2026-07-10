<?php
/**
 * `[rd_catalog]` shortcode: open terms grouped by course, filterable by
 * style/level/location/weekday (spec F1).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Admin\Terms_List_Table;
use RubenDance\Lang;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Services\Catalog_Service;
use RubenDance\Services\Term_Presenter;
use RubenDance\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Catalog_Page.
 *
 * Output only, read-only filters (GET, no state change — the same reasoning
 * `Terms_Page::render_filters()` uses for its admin equivalent): everything
 * this class does is assemble already-formatted display data and hand it to
 * `public/templates/catalog.php`, which contains no business logic of its
 * own.
 */
class Catalog_Page {

	/**
	 * `Front\Pages` "which" key this shortcode's page is registered under.
	 * A plain string (not a `Pages::` constant) so this stays a same-file
	 * addition rather than a change to M07's `Pages` class — see
	 * `Pages::set()`/`url()`, which already accept an arbitrary key.
	 *
	 * @var string
	 */
	const PAGE_KEY = 'catalog';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_styles' ) );
	}

	/**
	 * Register the `[rd_catalog]` shortcode.
	 */
	public static function register_shortcode(): void {
		add_shortcode( 'rd_catalog', array( self::class, 'render' ) );
	}

	/**
	 * Shared front-end stylesheet for the catalog/course-detail/enroll
	 * templates. Small enough to load unconditionally, the same reasoning
	 * `Shortcodes::enqueue_styles()` (M07) uses for `front-auth.css`.
	 */
	public static function enqueue_styles(): void {
		wp_enqueue_style(
			'rd-front-catalog',
			plugins_url( 'public/assets/front-catalog.css', RUBEN_DANCE_PLUGIN_FILE ),
			array(),
			RUBEN_DANCE_VERSION
		);
	}

	/**
	 * `[rd_catalog]`.
	 *
	 * @return string
	 */
	public static function render(): string {
		$lang_helper = Lang::create_default();
		$lang        = $lang_helper->current();
		$filters     = self::read_filters();

		$groups = Catalog_Service::create_default()->courses_with_open_terms( $filters );

		$presenter           = new Term_Presenter();
		$enrollments         = new Enrollment_Repository();
		$locations           = array();
		$location_repository = new Location_Repository();
		$today               = current_time( 'Y-m-d' );

		$display_groups = array();

		foreach ( $groups as $group ) {
			$course_post_id = $lang_helper->resolve_post( $group['course_id'], $lang );

			$display_terms = array();

			foreach ( $group['terms'] as $term ) {
				$location_id = (int) $term['location_id'];

				if ( ! isset( $locations[ $location_id ] ) ) {
					$locations[ $location_id ] = $location_repository->find( $location_id );
				}

				$location     = $locations[ $location_id ];
				$active_count = $enrollments->count_active_for_term( (int) $term['id'] );

				$display_terms[] = array(
					'id'         => (int) $term['id'],
					'type'       => (string) $term['type'],
					'season'     => Lang::EN === $lang && '' !== trim( (string) $term['season_label_en'] ) ? (string) $term['season_label_en'] : (string) $term['season_label_cs'],
					'weekday'    => Terms_List_Table::weekday_labels()[ (int) $term['weekday'] ] ?? '',
					'time'       => Terms_List_Table::format_time( (string) $term['start_time'] ) . '–' . Terms_List_Table::format_time( (string) $term['end_time'] ),
					'date_from'  => (string) $term['date_from'],
					'date_to'    => (string) $term['date_to'],
					'location'   => null === $location ? '' : (string) $location['name'],
					'price'      => (string) $term['price'],
					'early_bird' => $presenter->early_bird( $term, $today ),
					'is_full'    => $presenter->is_full( $term, $active_count ),
					'note'       => Lang::EN === $lang && '' !== trim( (string) ( $term['note_public_en'] ?? '' ) ) ? (string) $term['note_public_en'] : (string) ( $term['note_public_cs'] ?? '' ),
					'enroll_url' => add_query_arg( 'term_id', (int) $term['id'], Pages::url( Enroll_Page::PAGE_KEY, $lang ) ),
				);
			}

			$display_groups[] = array(
				'title' => get_the_title( $course_post_id ),
				'url'   => get_permalink( $course_post_id ),
				'terms' => $display_terms,
			);
		}

		return self::render_template(
			'catalog',
			array(
				'groups'           => $display_groups,
				'filters'          => $filters,
				'style_options'    => self::taxonomy_options( Taxonomies::DANCE_STYLE, $lang ),
				'level_options'    => self::taxonomy_options( Taxonomies::LEVEL, $lang ),
				'location_options' => $location_repository->active(),
				'weekday_options'  => Terms_List_Table::weekday_labels(),
				'page_url'         => Pages::url( self::PAGE_KEY, $lang ),
			)
		);
	}

	/**
	 * Read the active catalog filters from `$_GET`.
	 *
	 * @return array{style: int, level: int, location_id: int, weekday: int}
	 */
	private static function read_filters(): array {
		return array(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the catalog shows, no state change.
			'style'       => isset( $_GET['style'] ) ? absint( $_GET['style'] ) : 0,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the catalog shows, no state change.
			'level'       => isset( $_GET['level'] ) ? absint( $_GET['level'] ) : 0,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the catalog shows, no state change.
			'location_id' => isset( $_GET['location_id'] ) ? absint( $_GET['location_id'] ) : 0,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which rows the catalog shows, no state change.
			'weekday'     => isset( $_GET['weekday'] ) ? absint( $_GET['weekday'] ) : 0,
		);
	}

	/**
	 * Taxonomy term options for a filter dropdown, restricted to the current
	 * display language when Polylang is active (so submitted IDs line up
	 * with what `Catalog_Service::matching_course_ids()` expects to receive).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $lang     Current display language.
	 * @return array<int, string> Term ID => name.
	 */
	private static function taxonomy_options( string $taxonomy, string $lang ): array {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		);

		if ( function_exists( 'pll_get_term' ) ) {
			$args['lang'] = $lang;
		}

		$terms = get_terms( $args );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$options = array();

		foreach ( $terms as $term ) {
			$options[ $term->term_id ] = $term->name;
		}

		return $options;
	}

	/**
	 * Include a template partial with `$vars` extracted as local variables.
	 * Mirrors `Shortcodes::render_template()` (M07) — duplicated rather than
	 * shared to keep this class independent of M07's file.
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
