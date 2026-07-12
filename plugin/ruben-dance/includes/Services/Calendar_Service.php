<?php
/**
 * Public calendar query orchestration: lessons in a date range, filtered by
 * style/location, localized for display (spec F2).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use RubenDance\Lang;
use RubenDance\Post_Types;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Lesson_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Calendar_Service.
 *
 * Touches WordPress directly (`get_posts()`, `wp_get_post_terms()`,
 * `get_permalink()`, Polylang) the same way `Catalog_Service` does — pure
 * orchestration/glue, not business-rule logic, so it is verified end-to-end
 * against wp-env rather than with PHPUnit fakes (see that class's docblock
 * for the same reasoning). `Rest\Lessons_Controller` is the only caller; it
 * owns request validation (`Rest\Lessons_Query`) and caching
 * (`Calendar_Cache`) — this class only ever receives already-validated
 * values and never reads `wp_rd_enrollment` or any user table, so its return
 * value is always safe to expose on a public, unauthenticated route.
 */
class Calendar_Service {

	/**
	 * Term repository.
	 *
	 * @var Course_Term_Repository
	 */
	private Course_Term_Repository $terms;

	/**
	 * Lesson repository.
	 *
	 * @var Lesson_Repository
	 */
	private Lesson_Repository $lessons;

	/**
	 * Location repository.
	 *
	 * @var Location_Repository
	 */
	private Location_Repository $locations;

	/**
	 * Constructor.
	 *
	 * @param Course_Term_Repository $terms     Term repository.
	 * @param Lesson_Repository      $lessons   Lesson repository.
	 * @param Location_Repository    $locations Location repository.
	 */
	public function __construct( Course_Term_Repository $terms, Lesson_Repository $lessons, Location_Repository $locations ) {
		$this->terms     = $terms;
		$this->lessons   = $lessons;
		$this->locations = $locations;
	}

	/**
	 * Wire the service to the real repositories.
	 *
	 * @return self
	 */
	public static function create_default(): self {
		return new self( new Course_Term_Repository(), new Lesson_Repository(), new Location_Repository() );
	}

	/**
	 * Lessons for the public calendar feed. Every value in `$filters` is
	 * assumed already validated by `Rest\Lessons_Query`/`Rest\Lessons_Controller`
	 * — this method does not re-validate.
	 *
	 * @param array{from: string, to: string, style: int, location: int, lang: string} $filters Validated filter values (`style`/`location` = 0 means "no filter").
	 * @return array<int, array<string, mixed>> Public-safe lesson rows
	 *                                          (id, date, start, end, title, url, style, location, status, type),
	 *                                          ordered by date/time ascending. No user/enrollment data, ever.
	 */
	public function lessons_for_feed( array $filters ): array {
		$course_ids = null;

		if ( $filters['style'] > 0 ) {
			$course_ids = $this->course_ids_for_style( $filters['style'] );

			if ( array() === $course_ids ) {
				return array();
			}
		}

		$term_filters = array();

		if ( $filters['location'] > 0 ) {
			$term_filters['location_id'] = $filters['location'];
		}

		if ( null !== $course_ids ) {
			$term_filters['course_ids'] = $course_ids;
		}

		$terms = $this->terms->visible_for_calendar( $term_filters );

		if ( array() === $terms ) {
			return array();
		}

		$terms_by_id = array();
		foreach ( $terms as $term ) {
			$terms_by_id[ (int) $term['id'] ] = $term;
		}

		$lessons = $this->lessons->for_terms_between( array_keys( $terms_by_id ), $filters['from'], $filters['to'] );

		if ( array() === $lessons ) {
			return array();
		}

		$lang_helper = Lang::create_default();
		$lang        = $filters['lang'];

		$locations_by_id = array();
		$courses_by_id   = array(); // (Czech/canonical) course_id => display data, resolved at most once per course.

		$result = array();

		foreach ( $lessons as $lesson ) {
			$term = $terms_by_id[ (int) $lesson['term_id'] ] ?? null;

			if ( null === $term ) {
				continue; // Defensive: every lesson here was fetched by a term_id from $terms_by_id above.
			}

			$course_id_cs = (int) $term['course_id'];

			if ( ! array_key_exists( $course_id_cs, $courses_by_id ) ) {
				$courses_by_id[ $course_id_cs ] = $this->course_display_data( $course_id_cs, $lang, $lang_helper );
			}

			$course = $courses_by_id[ $course_id_cs ];

			if ( null === $course ) {
				continue; // Defensive: the course post was since trashed/deleted.
			}

			$location_id = (int) $term['location_id'];

			if ( ! array_key_exists( $location_id, $locations_by_id ) ) {
				$locations_by_id[ $location_id ] = $this->locations->find( $location_id );
			}

			$location = $locations_by_id[ $location_id ];

			$result[] = array(
				'id'       => (int) $lesson['id'],
				'date'     => (string) $lesson['lesson_date'],
				'start'    => self::short_time( (string) $lesson['start_time'] ),
				'end'      => self::short_time( (string) $lesson['end_time'] ),
				'title'    => $course['title'],
				'url'      => $course['url'],
				'style'    => $course['style'],
				'location' => null === $location ? '' : (string) $location['name'],
				'status'   => self::effective_status( $lesson, $term ),
				// D4: the term's `type` column ('course'|Term_Service::TYPE_WORKSHOP),
				// carried through so the front-end calendar/list view can give a
				// workshop lesson its dashed-border chip treatment (design
				// screens.html #3d/#4d) the same way Catalog_Page already flags a
				// workshop-only course. Public-safe: just a term column, no
				// enrollment/user data.
				'type'     => (string) $term['type'],
			);
		}

		return $result;
	}

	/**
	 * (Czech/canonical) course IDs whose `rd_dance_style` includes the given
	 * taxonomy term (potentially in the current display language).
	 *
	 * @param int $style_term_id Dance-style taxonomy term ID.
	 * @return int[]
	 */
	private function course_ids_for_style( int $style_term_id ): array {
		$canonical_id = $this->canonical_term_id( $style_term_id );

		$args = array(
			'post_type'      => Post_Types::COURSE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'lang'           => Lang::CS,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded by post_type=rd_course, a small custom post type; no pagination-scale concern here (same reasoning as Catalog_Service::matching_course_ids()).
				array(
					'taxonomy' => Taxonomies::DANCE_STYLE,
					'field'    => 'term_id',
					'terms'    => $canonical_id,
				),
			),
		);

		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Resolve a taxonomy term ID (potentially in the current display
	 * language) to its Czech-language counterpart, so it lines up with the
	 * Czech course posts `wp_rd_course_term.course_id` always points to.
	 * Duplicated from `Catalog_Service::canonical_term_id()` rather than
	 * shared — see `Front\Catalog_Page::render_template()` for the same
	 * "small private helpers are duplicated, not shared across unrelated
	 * classes" convention this codebase already follows.
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

	/**
	 * Localized title/permalink/style-slug for a (Czech/canonical) course
	 * post, or null if the post no longer exists.
	 *
	 * @param int    $course_id_cs (Czech/canonical) course post ID.
	 * @param string $lang         Display language.
	 * @param Lang   $lang_helper  Language helper.
	 * @return array{title: string, url: string, style: string}|null
	 */
	private function course_display_data( int $course_id_cs, string $lang, Lang $lang_helper ): ?array {
		$display_id = $lang_helper->resolve_post( $course_id_cs, $lang );
		$post       = get_post( $display_id );

		if ( null === $post ) {
			return null;
		}

		$style_terms = wp_get_post_terms( $display_id, Taxonomies::DANCE_STYLE );
		$style_slug  = ( is_array( $style_terms ) && array() !== $style_terms ) ? (string) $style_terms[0]->slug : '';

		return array(
			'title' => get_the_title( $post ),
			'url'   => (string) get_permalink( $post ),
			'style' => $style_slug,
		);
	}

	/**
	 * A lesson's effective public status: a whole cancelled term (spec §3.2:
	 * `wp_rd_course_term.status = 'cancelled'`) cancels every one of its
	 * lessons for display purposes even though the individual `wp_rd_lesson`
	 * rows keep whatever status they already had — cancelling a term does
	 * not cascade into its lesson rows (`Lesson_Generator` only ever inserts
	 * new rows as `scheduled`; nothing rewrites existing ones on a term-level
	 * status change).
	 *
	 * @param array<string, mixed> $lesson Lesson row.
	 * @param array<string, mixed> $term   Its term row.
	 * @return string One of `Lesson_Service::STATUSES`.
	 */
	private static function effective_status( array $lesson, array $term ): string {
		if ( Lesson_Service::STATUS_CANCELLED === $lesson['status'] || Term_Service::STATUS_CANCELLED === $term['status'] ) {
			return Lesson_Service::STATUS_CANCELLED;
		}

		return Lesson_Service::STATUS_MOVED === $lesson['status'] ? Lesson_Service::STATUS_MOVED : Lesson_Service::STATUS_SCHEDULED;
	}

	/**
	 * Trim a `TIME` column's `HH:MM:SS` (as `$wpdb` returns it) down to
	 * `HH:MM`. Mirrors `Admin\Terms_List_Table::format_time()`.
	 *
	 * @param string $time Raw `HH:MM:SS` (or already-short `HH:MM`) value.
	 * @return string
	 */
	private static function short_time( string $time ): string {
		return 8 === strlen( $time ) ? substr( $time, 0, 5 ) : $time;
	}
}
