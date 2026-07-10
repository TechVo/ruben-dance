<?php
/**
 * Public catalog query orchestration: open terms grouped by course, filtered
 * by style/level/location/weekday (spec F1).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Lang;
use RubenDance\Post_Types;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Catalog_Service.
 *
 * Unlike `Pricing_Service`/`Term_Presenter`, this class does touch WordPress
 * (`get_posts()`, Polylang) — it is pure orchestration/glue, not
 * business-rule logic, so it is verified end-to-end against wp-env rather
 * than with PHPUnit fakes, the same reasoning `Catalog_Page`/`Course_Content`
 * (Front) use. `Course_Term_Repository::open_for_courses()` does the one
 * piece of real SQL.
 */
class Catalog_Service {

	/**
	 * Term repository.
	 *
	 * @var Course_Term_Repository
	 */
	private Course_Term_Repository $terms;

	/**
	 * Constructor.
	 *
	 * @param Course_Term_Repository $terms Term repository.
	 */
	public function __construct( Course_Term_Repository $terms ) {
		$this->terms = $terms;
	}

	/**
	 * Wire the service to the real repository.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		return new self( new Course_Term_Repository() );
	}

	/**
	 * Open terms grouped by course, for the `[rd_catalog]` shortcode.
	 *
	 * @param array{style?: int, level?: int, location_id?: int, weekday?: int} $filters Optional filter values; a term/taxonomy ID of 0 (or absent) means "no filter" on that facet. `style`/`level` are taxonomy term IDs in the *current* display language.
	 * @return array<int, array{course_id: int, post: \WP_Post, terms: array<int, array<string, mixed>>}> Ordered by course title.
	 */
	public function courses_with_open_terms( array $filters = array() ): array {
		$course_ids = $this->matching_course_ids( $filters );

		if ( array() === $course_ids ) {
			return array();
		}

		$term_filters = array();

		if ( ! empty( $filters['location_id'] ) ) {
			$term_filters['location_id'] = (int) $filters['location_id'];
		}

		if ( ! empty( $filters['weekday'] ) ) {
			$term_filters['weekday'] = (int) $filters['weekday'];
		}

		$rows = $this->terms->open_for_courses( $course_ids, $term_filters );

		$grouped = array();

		foreach ( $rows as $row ) {
			$grouped[ (int) $row['course_id'] ][] = $row;
		}

		$result = array();

		foreach ( $grouped as $course_id => $course_terms ) {
			$post = get_post( $course_id );

			if ( null === $post ) {
				continue; // Defensive: a term whose course was since trashed/deleted.
			}

			$result[] = array(
				'course_id' => $course_id,
				'post'      => $post,
				'terms'     => $course_terms,
			);
		}

		usort(
			$result,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['post']->post_title, $b['post']->post_title );
			}
		);

		return $result;
	}

	/**
	 * Resolve the filter set down to the (Czech/canonical) course post IDs
	 * that match every style/level facet requested. `wp_rd_course_term.course_id`
	 * always references the Czech post (spec §5 Multilingual), so this
	 * always queries Czech posts and, when a taxonomy filter is a
	 * current-language term ID, first resolves it to its Czech counterpart.
	 *
	 * @param array{style?: int, level?: int} $filters Filter values.
	 * @return int[]
	 */
	private function matching_course_ids( array $filters ): array {
		$tax_query = array();

		if ( ! empty( $filters['style'] ) ) {
			$tax_query[] = array(
				'taxonomy' => Taxonomies::DANCE_STYLE,
				'field'    => 'term_id',
				'terms'    => $this->canonical_term_id( (int) $filters['style'] ),
			);
		}

		if ( ! empty( $filters['level'] ) ) {
			$tax_query[] = array(
				'taxonomy' => Taxonomies::LEVEL,
				'field'    => 'term_id',
				'terms'    => $this->canonical_term_id( (int) $filters['level'] ),
			);
		}

		$args = array(
			'post_type'      => Post_Types::COURSE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'lang'           => Lang::CS,
		);

		if ( array() !== $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded by post_type=rd_course, a small custom post type; no pagination-scale concern here.
		}

		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Resolve a taxonomy term ID (potentially in the current display
	 * language) to its Czech-language counterpart, so it lines up with the
	 * Czech course posts `wp_rd_course_term.course_id` always points to.
	 * Falls back to the given ID unchanged when Polylang is absent or the
	 * term has no distinct Czech translation (i.e. it already is one) — the
	 * same defensive `function_exists()` pattern `Lang`/`Seed_Command` use.
	 *
	 * @param int $term_id Taxonomy term ID.
	 * @return int
	 */
	private function canonical_term_id( int $term_id ): int {
		if ( ! function_exists( 'pll_get_term' ) ) {
			return $term_id;
		}

		$cs_id = (int) pll_get_term( $term_id, Lang::CS );

		return $cs_id > 0 ? $cs_id : $term_id;
	}
}
