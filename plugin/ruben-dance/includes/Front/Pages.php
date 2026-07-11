<?php
/**
 * Lookup for the WP pages that host the auth shortcodes, per language.
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
 * Class Pages.
 *
 * `wp rd seed` creates the `[rd_login]`/`[rd_register]`/`[rd_lost_password]`
 * pages (CS + EN) and registers their IDs here via `set()`, the same way
 * `Settings` stores plugin-wide options — this is the single place the rest
 * of the plugin asks "where is the login page for this language?" instead of
 * searching post content ad hoc. Falls back to the site's front page when a
 * page hasn't been registered (e.g. Polylang inactive, or the operator built
 * the pages by hand without re-running the seed), so a link is always
 * produced, never a fatal error.
 */
class Pages {

	const LOGIN         = 'login';
	const REGISTER      = 'register';
	const LOST_PASSWORD = 'lost_password';

	/**
	 * The privacy policy and Terms & Conditions placeholder pages (M15/§6.1,
	 * §6.3), seeded CS + EN the same way the auth pages are. Registered here
	 * (rather than as a `PAGE_KEY` constant on some owning shortcode class,
	 * the pattern `catalog`/`enroll`/`account`/`calendar` follow) because
	 * neither page renders a plugin shortcode — they are plain page content —
	 * so there is no natural "owning" front-end class for them.
	 *
	 * @var string
	 */
	const PRIVACY_POLICY = 'privacy_policy';

	/**
	 * See `self::PRIVACY_POLICY`.
	 *
	 * @var string
	 */
	const TERMS = 'terms';

	/**
	 * Option name storing the `[which][lang] => page_id` map.
	 *
	 * @var string
	 */
	const OPTION = 'rd_auth_page_ids';

	/**
	 * Register a page ID for a shortcode/language pair.
	 *
	 * @param string $which   One of self::LOGIN, self::REGISTER, self::LOST_PASSWORD.
	 * @param string $lang    Language slug (Lang::CS or Lang::EN).
	 * @param int    $page_id WP page ID.
	 */
	public static function set( string $which, string $lang, int $page_id ): void {
		$map                    = get_option( self::OPTION, array() );
		$map[ $which ][ $lang ] = $page_id;
		update_option( self::OPTION, $map );
	}

	/**
	 * Permalink for the given shortcode page in the given language, falling
	 * back to the language's Czech counterpart, then the site's front page.
	 *
	 * @param string $which One of self::LOGIN, self::REGISTER, self::LOST_PASSWORD.
	 * @param string $lang  Language slug.
	 * @return string
	 */
	public static function url( string $which, string $lang ): string {
		$map = get_option( self::OPTION, array() );

		$page_id = (int) ( $map[ $which ][ $lang ] ?? $map[ $which ][ Lang::CS ] ?? 0 );

		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}

		return home_url( '/' );
	}
}
