<?php
/**
 * WP-CLI `wp rd seed` command.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Cli;

use RubenDance\Lang;
use RubenDance\Post_Types;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Services\Term_Service;
use RubenDance\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Seed_Command.
 *
 * Registers the `wp rd seed` command. Each milestone that adds an entity
 * extends `__invoke()` with that entity's fixture data; every seed method
 * must stay idempotent (re-running `wp rd seed` must never create
 * duplicates), matched by name against what's already in the database.
 */
class Seed_Command {

	/**
	 * Real venues from ruben-dance.cz's "Tančírny v Praze" page, used as
	 * fixture data so admin screens are always exercised against realistic
	 * content rather than "Location 1", "Location 2", ...
	 *
	 * @var array<int, array{name: string, address: string, map_url: string}>
	 */
	const LOCATIONS = array(
		array(
			'name'    => 'Terasa Smíchov',
			'address' => 'Plzeňská 8, 150 00 Praha 5 – Smíchov',
			'map_url' => 'https://maps.google.com/?q=Plze%C5%88sk%C3%A1+8%2C+150+00+Praha+5',
		),
		array(
			'name'    => 'NYX Hotel Prague',
			'address' => 'Panská 892/9, 110 00 Praha 1 – Nové Město',
			'map_url' => 'https://maps.google.com/?q=Pansk%C3%A1+892%2F9%2C+110+00+Praha+1',
		),
		array(
			'name'    => 'Terasa 67 (Křižíkův pavilon B)',
			'address' => 'Výstaviště 67, 170 00 Praha 7 – Holešovice',
			'map_url' => 'https://maps.google.com/?q=V%C3%BDstavi%C5%A1t%C4%9B+67%2C+170+00+Praha+7',
		),
	);

	/**
	 * Four courses covering the styles ruben-dance.cz actually teaches, used
	 * as fixture data (spec §3.1: course = "name, description, level, dance
	 * style"). Each becomes a CS post plus its EN translation, linked via
	 * Polylang when available (see `seed_courses()`).
	 *
	 * @var array<int, array{
	 *     title_cs: string,
	 *     title_en: string,
	 *     content_cs: string,
	 *     content_en: string,
	 *     style_cs: string,
	 *     style_en: string,
	 *     level_cs: string,
	 *     level_en: string,
	 * }>
	 */
	const COURSES = array(
		array(
			'title_cs'   => 'Salsa pro začátečníky',
			'title_en'   => 'Salsa for Beginners',
			'content_cs' => 'Základy kubánské salsy — držení, základní krok a první točení. Žádné předchozí zkušenosti nejsou potřeba.',
			'content_en' => 'The fundamentals of Cuban-style salsa — frame, basic step and first turns. No previous experience required.',
			'style_cs'   => 'Salsa',
			'style_en'   => 'Salsa',
			'level_cs'   => 'Začátečníci',
			'level_en'   => 'Beginners',
		),
		array(
			'title_cs'   => 'Bachata pro mírně pokročilé',
			'title_en'   => 'Bachata for Intermediate Dancers',
			'content_cs' => 'Navazuje na úplné základy bachaty: složitější figury, práce s partnerem a muzikalita.',
			'content_en' => 'Building on the absolute basics of bachata: more advanced figures, partner work and musicality.',
			'style_cs'   => 'Bachata',
			'style_en'   => 'Bachata',
			'level_cs'   => 'Mírně pokročilí',
			'level_en'   => 'Intermediate',
		),
		array(
			'title_cs'   => 'Dětský tanec',
			'title_en'   => 'Kids Dance',
			'content_cs' => 'Pohybová a taneční příprava pro děti — rytmus, koordinace a radost z tance formou hry.',
			'content_en' => 'Movement and dance preparation for children — rhythm, coordination and the joy of dance through play.',
			'style_cs'   => 'Dětský tanec',
			'style_en'   => 'Kids Dance',
			'level_cs'   => 'Pro všechny',
			'level_en'   => 'All levels',
		),
		array(
			'title_cs'   => 'Dámský styling',
			'title_en'   => 'Ladies Styling',
			'content_cs' => 'Ženský styl, práce s tělem a sólové prvky do latinskoamerických tanců — bez partnera.',
			'content_en' => 'Feminine styling, body movement and solo elements for Latin dances — no partner needed.',
			'style_cs'   => 'Dámský styling',
			'style_en'   => 'Ladies Styling',
			'level_cs'   => 'Pro všechny',
			'level_en'   => 'All levels',
		),
	);

	/**
	 * Six course terms spanning the four seeded courses (M05: "mix of
	 * statuses, one with early-bird, one with pair discount, one workshop,
	 * one at low capacity"). Field values are strings, the same shape
	 * `Term_Service::validate()`/`row()` expect from a real admin form
	 * submission, so this fixture data is validated exactly like a real save
	 * rather than bypassing that logic.
	 *
	 * @var array<int, array<string, string>>
	 */
	const TERMS = array(
		// Open, with an early-bird discount.
		array(
			'course_title_cs' => 'Salsa pro začátečníky',
			'location_name'   => 'Terasa Smíchov',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '1', // Monday.
			'start_time'      => '18:00',
			'end_time'        => '19:00',
			'date_from'       => '2025-09-01',
			'date_to'         => '2025-12-15',
			'instructor'      => 'Ruben García',
			'capacity'        => '20',
			'price'           => '2400',
			'discount_early'  => '300',
			'early_until'     => '2025-08-15',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_OPEN,
			'season_label_cs' => 'Podzim 2025',
			'season_label_en' => 'Autumn 2025',
			'note_public_cs'  => 'S sebou taneční obuv.',
			'note_public_en'  => 'Please bring dance shoes.',
		),
		// Draft: next season, not yet published.
		array(
			'course_title_cs' => 'Salsa pro začátečníky',
			'location_name'   => 'NYX Hotel Prague',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '3', // Wednesday.
			'start_time'      => '19:00',
			'end_time'        => '20:00',
			'date_from'       => '2026-02-02',
			'date_to'         => '2026-05-18',
			'instructor'      => 'Ruben García',
			'capacity'        => '16',
			'price'           => '2400',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_DRAFT,
			'season_label_cs' => 'Jaro 2026',
			'season_label_en' => 'Spring 2026',
			'note_public_cs'  => '',
			'note_public_en'  => '',
		),
		// Open, with a pair discount.
		array(
			'course_title_cs' => 'Bachata pro mírně pokročilé',
			'location_name'   => 'Terasa 67 (Křižíkův pavilon B)',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '2', // Tuesday.
			'start_time'      => '19:30',
			'end_time'        => '20:30',
			'date_from'       => '2025-09-02',
			'date_to'         => '2025-12-16',
			'instructor'      => 'Ana Kováčová',
			'capacity'        => '18',
			'price'           => '2600',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '200',
			'status'          => Term_Service::STATUS_OPEN,
			'season_label_cs' => 'Podzim 2025',
			'season_label_en' => 'Autumn 2025',
			'note_public_cs'  => '',
			'note_public_en'  => '',
		),
		// Closed and at low capacity.
		array(
			'course_title_cs' => 'Dětský tanec',
			'location_name'   => 'Terasa Smíchov',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '6', // Saturday.
			'start_time'      => '10:00',
			'end_time'        => '11:00',
			'date_from'       => '2025-09-06',
			'date_to'         => '2025-12-13',
			'instructor'      => 'Petra Nováková',
			'capacity'        => '6',
			'price'           => '1800',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_CLOSED,
			'season_label_cs' => 'Podzim 2025',
			'season_label_en' => 'Autumn 2025',
			'note_public_cs'  => 'Kapacita naplněna, čekejte na jarní běh.',
			'note_public_en'  => 'Full for this season — spring intake opens soon.',
		),
		// Workshop: a single lesson, date_from = date_to.
		array(
			'course_title_cs' => 'Dámský styling',
			'location_name'   => 'NYX Hotel Prague',
			'type'            => Term_Service::TYPE_WORKSHOP,
			'weekday'         => '6', // Ignored by the generator for a workshop, still stored for display.
			'start_time'      => '10:00',
			'end_time'        => '16:00',
			'date_from'       => '2025-11-15',
			'date_to'         => '2025-11-15',
			'instructor'      => 'Ruben García',
			'capacity'        => '',
			'price'           => '900',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_OPEN,
			'season_label_cs' => 'Zimní workshop 2025',
			'season_label_en' => 'Winter Workshop 2025',
			'note_public_cs'  => 'Jednorázová dílna, není potřeba partner.',
			'note_public_en'  => 'One-off workshop, no partner needed.',
		),
		// Cancelled.
		array(
			'course_title_cs' => 'Bachata pro mírně pokročilé',
			'location_name'   => 'Terasa 67 (Křižíkův pavilon B)',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '4', // Thursday.
			'start_time'      => '18:00',
			'end_time'        => '19:00',
			'date_from'       => '2025-06-05',
			'date_to'         => '2025-08-28',
			'instructor'      => 'Ana Kováčová',
			'capacity'        => '16',
			'price'           => '2600',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_CANCELLED,
			'season_label_cs' => 'Léto 2025',
			'season_label_en' => 'Summer 2025',
			'note_public_cs'  => 'Zrušeno pro nízký zájem.',
			'note_public_en'  => 'Cancelled due to low interest.',
		),
	);

	/**
	 * Seed the database with development/test fixture data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rd seed
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args ); // Required by the WP-CLI callable signature; unused for now.

		$locations_created = $this->seed_locations();
		$courses_created   = $this->seed_courses();
		$terms_created     = $this->seed_terms();

		\WP_CLI::success(
			sprintf(
				'ruben-dance: seeded (%d location(s), %d course(s), %d term(s) created).',
				$locations_created,
				$courses_created,
				$terms_created
			)
		);
	}

	/**
	 * Insert the fixture locations, skipping any that already exist (matched
	 * by exact name) so repeated runs never create duplicates.
	 *
	 * @return int Number of locations actually created.
	 */
	private function seed_locations(): int {
		$repository = new Location_Repository();
		$created    = 0;

		foreach ( self::LOCATIONS as $location ) {
			if ( null !== $repository->find_by_name( $location['name'] ) ) {
				continue;
			}

			$repository->insert(
				array(
					'name'      => $location['name'],
					'address'   => $location['address'],
					'map_url'   => $location['map_url'],
					'is_active' => 1,
				)
			);

			++$created;
		}

		return $created;
	}

	/**
	 * Insert the fixture courses, skipping any whose Czech title already
	 * exists (the canonical post, per spec §5 Multilingual: "the term's
	 * `course_id` always points to the Czech course post") so repeated runs
	 * never create duplicates. When Polylang is active, each course also
	 * gets its English translation, linked via `pll_save_post_translations()`;
	 * when Polylang is absent, only the Czech post is created — seeding must
	 * degrade the same way the rest of the plugin does (see `Lang`).
	 *
	 * @return int Number of Czech course posts actually created.
	 */
	private function seed_courses(): int {
		$created = 0;

		foreach ( self::COURSES as $course ) {
			if ( null !== $this->find_course_by_title( $course['title_cs'] ) ) {
				continue;
			}

			$cs_id = wp_insert_post(
				array(
					'post_type'    => Post_Types::COURSE,
					'post_title'   => $course['title_cs'],
					'post_content' => $course['content_cs'],
					'post_status'  => 'publish',
				),
				true
			);

			if ( is_wp_error( $cs_id ) || ! $cs_id ) {
				continue;
			}

			$en_id = wp_insert_post(
				array(
					'post_type'    => Post_Types::COURSE,
					'post_title'   => $course['title_en'],
					'post_content' => $course['content_en'],
					'post_status'  => 'publish',
				),
				true
			);
			$en_id = is_wp_error( $en_id ) ? 0 : (int) $en_id;

			$style = $this->paired_term( Taxonomies::DANCE_STYLE, $course['style_cs'], $course['style_en'] );
			$level = $this->paired_term( Taxonomies::LEVEL, $course['level_cs'], $course['level_en'] );

			wp_set_object_terms( $cs_id, array( $style['cs'] ), Taxonomies::DANCE_STYLE );
			wp_set_object_terms( $cs_id, array( $level['cs'] ), Taxonomies::LEVEL );

			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $cs_id, Lang::CS );

				if ( $en_id ) {
					pll_set_post_language( $en_id, Lang::EN );
				}
			}

			if ( $en_id ) {
				wp_set_object_terms( $en_id, array( $style['en'] ), Taxonomies::DANCE_STYLE );
				wp_set_object_terms( $en_id, array( $level['en'] ), Taxonomies::LEVEL );

				if ( function_exists( 'pll_save_post_translations' ) ) {
					pll_save_post_translations(
						array(
							Lang::CS => $cs_id,
							Lang::EN => $en_id,
						)
					);
				}
			}

			++$created;
		}

		return $created;
	}

	/**
	 * Insert the fixture terms, skipping any that already exist (matched by
	 * course + season label, the same natural-key idea as
	 * `Location_Repository::find_by_name()`), so repeated runs never create
	 * duplicates. Goes through `Term_Service::validate()`/`create()` — the
	 * same code path a real admin form submission uses — rather than
	 * inserting rows directly, so lessons are generated for every seeded
	 * term exactly as they would be for a real one.
	 *
	 * @return int Number of terms actually created.
	 */
	private function seed_terms(): int {
		$term_repository     = new Course_Term_Repository();
		$location_repository = new Location_Repository();
		$service             = Term_Service::create_default();
		$created             = 0;

		foreach ( self::TERMS as $term ) {
			$course_id = $this->find_course_by_title( $term['course_title_cs'] );

			if ( null === $course_id ) {
				continue; // Defensive: the matching course should always have been seeded already.
			}

			if ( null !== $term_repository->find_by_course_and_season( $course_id, $term['season_label_cs'] ) ) {
				continue;
			}

			$location = $location_repository->find_by_name( $term['location_name'] );

			if ( null === $location ) {
				continue; // Defensive: the matching location should always have been seeded already.
			}

			$data = array(
				'course_id'       => $course_id,
				'location_id'     => (int) $location['id'],
				'type'            => $term['type'],
				'weekday'         => $term['weekday'],
				'start_time'      => $term['start_time'],
				'end_time'        => $term['end_time'],
				'date_from'       => $term['date_from'],
				'date_to'         => $term['date_to'],
				'instructor'      => $term['instructor'],
				'capacity'        => $term['capacity'],
				'price'           => $term['price'],
				'discount_early'  => $term['discount_early'],
				'early_until'     => $term['early_until'],
				'discount_pair'   => $term['discount_pair'],
				'status'          => $term['status'],
				'season_label_cs' => $term['season_label_cs'],
				'season_label_en' => $term['season_label_en'],
				'note_public_cs'  => $term['note_public_cs'],
				'note_public_en'  => $term['note_public_en'],
			);

			if ( array() !== $service->validate( $data ) ) {
				continue; // Defensive: fixture data is expected to always validate.
			}

			$service->create( $data );

			++$created;
		}

		return $created;
	}

	/**
	 * Find a course by its exact (Czech) title. Used for idempotency, the
	 * same way `Location_Repository::find_by_name()` is: `rd_course` is a
	 * core post type, not a `wp_rd_*` table, so this queries `$wpdb->posts`
	 * directly rather than through a repository (repositories are only for
	 * the plugin's custom tables, see includes/Repositories/).
	 *
	 * @param string $title Exact post title to match.
	 * @return int|null Post ID, or null if not found.
	 */
	private function find_course_by_title( string $title ): ?int {
		global $wpdb;

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_status != 'trash' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Post_Types::COURSE,
				$title
			)
		);

		return null === $id ? null : (int) $id;
	}

	/**
	 * Find or create a CS/EN pair of terms in one taxonomy, linking the
	 * translation when Polylang is active. Idempotent: re-running `wp rd
	 * seed` reuses the same pair instead of creating duplicates, including
	 * when the Czech and English names are identical (e.g. "Salsa") — the
	 * Czech term is found by name *and* language (`find_term_in_language()`),
	 * and the English one is then found via its translation group
	 * (`pll_get_term()`), never by name alone.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $name_cs  Czech term name.
	 * @param string $name_en  English term name.
	 * @return array{cs: int, en: int} Term IDs (equal to each other when
	 *                                 Polylang is absent — a single canonical term).
	 */
	private function paired_term( string $taxonomy, string $name_cs, string $name_en ): array {
		$cs_id = $this->find_term_in_language( $taxonomy, $name_cs, Lang::CS );

		if ( ! $cs_id ) {
			$cs_id = $this->insert_or_get_term( $name_cs, $taxonomy );

			if ( $cs_id && function_exists( 'pll_set_term_language' ) ) {
				pll_set_term_language( $cs_id, Lang::CS );
			}
		}

		if ( ! function_exists( 'pll_get_term' ) ) {
			return array(
				'cs' => $cs_id,
				'en' => $cs_id,
			);
		}

		$en_id = (int) pll_get_term( $cs_id, Lang::EN );

		if ( ! $en_id ) {
			// WordPress core forbids two terms with the same name at the same
			// taxonomy level unless an explicit, distinct slug is passed (see
			// `wp_insert_term()`); some styles/levels are spelled identically
			// in both languages (e.g. "Salsa"), so force a distinct slug for
			// the English term whenever the names collide, otherwise
			// `wp_insert_term()` would return the *Czech* term's ID as
			// "already exists" and we'd wrongly relabel it as English.
			$en_slug = strtolower( $name_cs ) === strtolower( $name_en )
				? sanitize_title( $name_en ) . '-en'
				: '';

			$en_id = $this->insert_or_get_term( $name_en, $taxonomy, $en_slug );

			if ( $en_id ) {
				pll_set_term_language( $en_id, Lang::EN );
				pll_save_term_translations(
					array(
						Lang::CS => $cs_id,
						Lang::EN => $en_id,
					)
				);
			}
		}

		return array(
			'cs' => $cs_id,
			'en' => 0 !== $en_id ? $en_id : $cs_id,
		);
	}

	/**
	 * Find an existing term by name, restricted to the given language when
	 * Polylang is active. Needed instead of a plain `get_term_by( 'name',
	 * ... )` because two Polylang terms can legitimately share a name across
	 * languages (e.g. "Salsa" in both CS and EN) — without the language
	 * filter, a second `wp rd seed` run could pick either one at random.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $name     Term name to match.
	 * @param string $lang     Language slug required when Polylang is active.
	 * @return int Term ID, or 0 if not found.
	 */
	private function find_term_in_language( string $taxonomy, string $name, string $lang ): int {
		$candidates = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $name,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $candidates ) || array() === $candidates ) {
			return 0;
		}

		if ( ! function_exists( 'pll_get_term_language' ) ) {
			return (int) $candidates[0]->term_id;
		}

		foreach ( $candidates as $candidate ) {
			if ( pll_get_term_language( $candidate->term_id ) === $lang ) {
				return (int) $candidate->term_id;
			}
		}

		return 0;
	}

	/**
	 * Insert a term, tolerating the "term already exists" case (returns the
	 * existing term's ID instead of failing) — needed because
	 * `get_term_by( 'name', ... )` alone cannot distinguish two Polylang
	 * terms that share a name across languages (see `paired_term()`).
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $slug     Optional explicit slug (see `paired_term()`:
	 *                         needed to insert an English term whose name is
	 *                         spelled the same as its Czech counterpart).
	 * @return int Term ID (0 on unexpected failure).
	 */
	private function insert_or_get_term( string $name, string $taxonomy, string $slug = '' ): int {
		$args   = '' === $slug ? array() : array( 'slug' => $slug );
		$result = wp_insert_term( $name, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			$existing = $result->get_error_data( 'term_exists' );

			return $existing ? (int) $existing : 0;
		}

		return (int) $result['term_id'];
	}
}
