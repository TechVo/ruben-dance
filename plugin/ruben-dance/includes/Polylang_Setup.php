<?php
/**
 * First-run Polylang language configuration: Czech default, English second.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Polylang_Setup.
 *
 * The milestone requires Polylang configured (CS default, EN second) to be
 * reproducible from `wp-env start` alone, with no manual admin-wizard step.
 * Polylang has no WP-CLI commands of its own, so this hooks the one-time
 * setup into the plugin itself: on every load, if Polylang is active and no
 * language has been configured yet, create Czech (made default automatically
 * by being added first) and English.
 *
 * Entirely inert when Polylang is not installed/active: `pll_init` only ever
 * fires when Polylang has finished bootstrapping (its own doc comment: "Fires
 * after the $polylang object and the API is loaded"), so `register()` never
 * runs `maybe_configure_languages()` at all otherwise. Hooking here — rather
 * than the more obvious `plugins_loaded` — matters: Polylang logs a
 * "must not be called before 'pll_pre_init'" deprecation notice (and may
 * return incomplete data) if its language list is read any earlier.
 */
class Polylang_Setup {

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'pll_init', array( self::class, 'maybe_configure_languages' ) );
	}

	/**
	 * Create the CS/EN languages if none exist yet.
	 */
	public static function maybe_configure_languages(): void {
		if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'PLL' ) ) {
			return; // Defensive: should always be true by `pll_init`.
		}

		if ( array() !== pll_languages_list() ) {
			return; // Already configured (by us, or manually) — never touch it again.
		}

		$pll = PLL();

		if ( ! is_object( $pll ) || ! isset( $pll->model ) ) {
			return; // Defensive: unexpected Polylang internals.
		}

		// Czech first: with no default language set yet, Polylang makes the
		// first added language the default one (spec §5: "CS default").
		$pll->model->add_language(
			array(
				'locale'     => 'cs_CZ',
				'slug'       => Lang::CS,
				'name'       => 'Čeština',
				'rtl'        => 0,
				'term_group' => 0,
			)
		);

		$pll->model->add_language(
			array(
				'locale'     => 'en_US',
				'slug'       => Lang::EN,
				'name'       => 'English',
				'rtl'        => 0,
				'term_group' => 1,
			)
		);
	}
}
