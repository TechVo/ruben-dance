<?php
/**
 * `[rd_calendar]` shortcode: the public FullCalendar view of scheduled
 * lessons (spec F2).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Lang;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Rest\Lessons_Controller;
use RubenDance\Services\Calendar_Service;
use RubenDance\Settings;
use RubenDance\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Calendar_Page.
 *
 * Output only: the actual data comes from `Rest\Lessons_Controller`'s public
 * REST route, fetched client-side by `calendar.js` (a real FullCalendar
 * instance, not server-rendered — month/week switching and the style/
 * location filters all re-fetch the same endpoint rather than reloading the
 * page). FullCalendar itself ships bundled under `public/assets/vendor/
 * fullcalendar/` — downloaded once at build time, never loaded from a CDN
 * (spec §5: no external runtime hosts).
 */
class Calendar_Page {

	/**
	 * `Front\Pages` "which" key this shortcode's page is registered under.
	 * See `Catalog_Page::PAGE_KEY` for why this is a plain string rather than
	 * a change to M07's `Pages` class.
	 *
	 * @var string
	 */
	const PAGE_KEY = 'calendar';

	/**
	 * How many days ahead the list-view fallback covers (spec §6.4:
	 * "keyboard-navigable calendar with a list-view fallback" — a real,
	 * always-rendered `<table>` of upcoming lessons that needs no JavaScript
	 * and no pointer/drag interaction to browse, unlike the FullCalendar
	 * grid). Deliberately short: this is a fallback for finding the next few
	 * lessons, not a full schedule browser — the calendar widget itself
	 * still covers any date range.
	 *
	 * @var int
	 */
	const LIST_VIEW_WINDOW_DAYS = 60;

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_shortcode' ) );
	}

	/**
	 * Register the `[rd_calendar]` shortcode.
	 */
	public static function register_shortcode(): void {
		add_shortcode( 'rd_calendar', array( self::class, 'render' ) );
	}

	/**
	 * `[rd_calendar]`.
	 *
	 * @return string
	 */
	public static function render(): string {
		$lang_helper = Lang::create_default();
		$lang        = $lang_helper->current();

		self::enqueue_assets( $lang );

		return self::render_template(
			'calendar',
			array(
				'style_options'    => self::taxonomy_options( Taxonomies::DANCE_STYLE, $lang ),
				'location_options' => ( new Location_Repository() )->active(),
				'upcoming_lessons' => self::upcoming_lessons( $lang ),
			)
		);
	}

	/**
	 * Unfiltered upcoming lessons for the list-view fallback, reusing the
	 * same public-safe, already-filtered-for-display data
	 * `Rest\Lessons_Controller` serves to the JS calendar widget — this
	 * server-rendered list is never out of sync with what the widget itself
	 * would show for the same range.
	 *
	 * @param string $lang Current display language.
	 * @return array<int, array<string, mixed>>
	 */
	private static function upcoming_lessons( string $lang ): array {
		$today = current_time( 'Y-m-d' );

		return Calendar_Service::create_default()->lessons_for_feed(
			array(
				'from'     => $today,
				'to'       => gmdate( 'Y-m-d', strtotime( $today ) + self::LIST_VIEW_WINDOW_DAYS * DAY_IN_SECONDS ),
				'style'    => 0,
				'location' => 0,
				'lang'     => $lang,
			)
		);
	}

	/**
	 * Enqueue the bundled FullCalendar assets, its Czech locale (CS pages
	 * only — English is FullCalendar's built-in default), and the plugin's
	 * own init script + stylesheet, localized with the REST endpoint and
	 * display settings the front end needs.
	 *
	 * @param string $lang Current display language.
	 */
	private static function enqueue_assets( string $lang ): void {
		wp_enqueue_style(
			'rd-front-calendar',
			plugins_url( 'public/assets/front-calendar.css', RUBEN_DANCE_PLUGIN_FILE ),
			array( 'rd-design' ),
			RUBEN_DANCE_VERSION
		);

		wp_enqueue_script(
			'rd-fullcalendar',
			plugins_url( 'public/assets/vendor/fullcalendar/fullcalendar.min.js', RUBEN_DANCE_PLUGIN_FILE ),
			array(),
			RUBEN_DANCE_VERSION,
			true
		);

		$calendar_deps = array( 'rd-fullcalendar' );

		if ( Lang::CS === $lang ) {
			wp_enqueue_script(
				'rd-fullcalendar-locale-cs',
				plugins_url( 'public/assets/vendor/fullcalendar/fullcalendar-locale-cs.min.js', RUBEN_DANCE_PLUGIN_FILE ),
				array( 'rd-fullcalendar' ),
				RUBEN_DANCE_VERSION,
				true
			);

			$calendar_deps[] = 'rd-fullcalendar-locale-cs';
		}

		wp_enqueue_script(
			'rd-calendar',
			plugins_url( 'public/assets/calendar.js', RUBEN_DANCE_PLUGIN_FILE ),
			$calendar_deps,
			RUBEN_DANCE_VERSION,
			true
		);

		wp_localize_script(
			'rd-calendar',
			'rdCalendarL10n',
			array(
				'restUrl'          => esc_url_raw( rest_url( Lessons_Controller::REST_NAMESPACE . Lessons_Controller::ROUTE ) ),
				'lang'             => $lang,
				'locale'           => Lang::CS === $lang ? 'cs' : 'en',
				'cancelledDisplay' => Settings::cancelled_lessons_display(),
				'cancelledHidden'  => Settings::CANCELLED_LESSONS_HIDDEN,
				'mobileBreakpoint' => 768,
				'cancelledLabel'   => __( 'Cancelled', 'ruben-dance' ),
			)
		);
	}

	/**
	 * Taxonomy term options for the style filter, restricted to the current
	 * display language when Polylang is active. Mirrors
	 * `Catalog_Page::taxonomy_options()`.
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
