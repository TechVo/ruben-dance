<?php
/**
 * Privacy policy + Terms & Conditions links in the site footer (spec §6.1,
 * §6.3: "linked from registration, enrollment, and footer").
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Lang;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Footer_Links.
 *
 * The spec requires these two links to appear in the footer on every public
 * page, but nothing in this plugin controls the active theme's footer
 * template — a WordPress.com/self-hosted site can run any theme, and several
 * (e.g. classic themes without `the_privacy_policy_link()` in their
 * `footer.php`, or a block theme whose footer template part was never
 * customized) show neither link on their own. Rather than depend on that,
 * this hooks `wp_footer` — which every theme calls, block or classic, by
 * WordPress's own template-compatibility contract — so the requirement is
 * met regardless of theme choice. Front-end only (`is_admin()` excluded);
 * `wp-login.php` also fires `wp_footer`, which is a reasonable place for
 * these links too (registration lives right there).
 */
class Footer_Links {

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'wp_footer', array( self::class, 'render' ) );
		add_action( 'login_footer', array( self::class, 'render' ) );
	}

	/**
	 * Echo the links bar. `Pages::url()` always returns *some* URL (falling
	 * back to the site's front page before `wp rd seed` has run) so this
	 * always renders both links — the same "never a fatal error, worst case
	 * a link to the home page" contract every other `Pages::url()` call site
	 * in the plugin relies on.
	 *
	 * Skipped when the active theme declares `add_theme_support(
	 * 'rd-footer-links' )` (the `ruben-dance` theme, D2, does this in its
	 * `functions.php`) — that theme already renders these same two links,
	 * properly styled, inside its own `footer.php` (design #3a's dark cocoa
	 * footer), so this class's plain, inline-styled fallback would otherwise
	 * print a second, visually broken copy directly beneath it. Themes that
	 * don't declare the flag (the scenario this class exists for in the
	 * first place — see class docblock) still get this fallback exactly as
	 * before.
	 */
	public static function render(): void {
		if ( is_admin() || current_theme_supports( 'rd-footer-links' ) ) {
			return;
		}

		$lang = Lang::create_default()->current();

		$terms_url   = Pages::url( Pages::TERMS, $lang );
		$privacy_url = Pages::url( Pages::PRIVACY_POLICY, $lang );

		echo '<nav class="rd-footer-links" aria-label="' . esc_attr__( 'Legal', 'ruben-dance' ) . '" style="text-align:center;padding:1em 0;font-size:0.85em;">';
		echo '<a href="' . esc_url( $terms_url ) . '">' . esc_html__( 'Terms & Conditions', 'ruben-dance' ) . '</a>';
		echo ' &middot; ';
		echo '<a href="' . esc_url( $privacy_url ) . '">' . esc_html__( 'Privacy Policy', 'ruben-dance' ) . '</a>';
		echo '</nav>';
	}
}
