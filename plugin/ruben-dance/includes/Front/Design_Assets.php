<?php
/**
 * D1 design foundation: self-hosted fonts + shared design-token/component
 * stylesheet, enqueued on every front-end request ahead of the per-screen
 * stylesheets (front-catalog.css, front-account.css, front-auth.css,
 * front-calendar.css).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Design_Assets.
 *
 * Registered (not merely enqueued) unconditionally on every front-end
 * request — the same "small enough to load unconditionally" reasoning
 * `Catalog_Page::enqueue_styles()`/`Shortcodes::enqueue_styles()` already
 * use for their own stylesheets — so every per-screen `wp_enqueue_style()`
 * call site can simply list `rd-design` as a dependency and be guaranteed
 * correct load order without needing to know *when* this class's own hook
 * ran (WordPress resolves style dependency order at print time, not at
 * registration time, so the two hooks' relative priority doesn't matter).
 *
 * `rd-fonts` (the self-hosted `@font-face` rules, spec §5's GDPR "no
 * external runtime hosts" — no Google Fonts request in production output)
 * is a dependency of `rd-design` rather than a second, separately-required
 * handle: every per-screen page that needs the design tokens also needs the
 * font they're set in, and there's no scenario where one loads without the
 * other.
 */
class Design_Assets {

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueue the fonts stylesheet and the design-token/shared-component
	 * stylesheet, in that order.
	 */
	public static function enqueue(): void {
		wp_enqueue_style(
			'rd-fonts',
			plugins_url( 'public/assets/rd-fonts.css', RUBEN_DANCE_PLUGIN_FILE ),
			array(),
			RUBEN_DANCE_VERSION
		);

		wp_enqueue_style(
			'rd-design',
			plugins_url( 'public/assets/rd-design.css', RUBEN_DANCE_PLUGIN_FILE ),
			array( 'rd-fonts' ),
			RUBEN_DANCE_VERSION
		);
	}
}
