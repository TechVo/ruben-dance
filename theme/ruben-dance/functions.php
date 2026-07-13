<?php
/**
 * Ruben Dance theme bootstrap.
 *
 * This is a thin presentation shell around the `ruben-dance` plugin, which
 * owns all business logic (courses, terms, enrollments, accounts) and
 * renders its own screens via shortcodes on plugin-created pages (spec D2:
 * this milestone is header/footer/homepage only). Every plugin touchpoint
 * below is guarded with class_exists()/function_exists() so the theme still
 * renders a usable page — header, footer, homepage with no fatal error —
 * if the plugin is inactive or Polylang isn't installed; see
 * rd_theme_current_lang(), rd_theme_nav_links(), rd_theme_auth_cta() and
 * rd_theme_footer_legal_links() for the fallback in each case.
 *
 * @package RubenDanceTheme
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

define( 'RD_THEME_VERSION', '0.1.0' );
define( 'RD_THEME_DIR', get_template_directory() );
define( 'RD_THEME_URI', get_template_directory_uri() );

/**
 * Theme setup: title tag, featured images (used for course cards on the
 * homepage), HTML5 markup for the pieces core still renders (search form,
 * comment form — unused here, but harmless to declare), and the
 * `rd-footer-links` support flag.
 *
 * The flag is what lets `RubenDance\Front\Footer_Links` (plugin, M15) know
 * this theme already renders the required legal links in its own
 * `footer.php` (see footer.php + design #3a's dark cocoa footer pattern) so
 * that class's `wp_footer` fallback — built for themes that show neither
 * link on their own — steps aside instead of printing a second, unstyled
 * copy of the same two links under our footer.
 */
function rd_theme_setup(): void {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'script', 'style' )
	);
	add_theme_support( 'rd-footer-links' );
}
add_action( 'after_setup_theme', 'rd_theme_setup' );

/**
 * Enqueue design tokens + theme chrome/homepage CSS, and the tiny mobile
 * menu enhancement script.
 *
 * `rd-design`/`rd-fonts` are registered unconditionally by the plugin's
 * `Design_Assets` class on every front-end request (see
 * plugin/ruben-dance/includes/Front/Design_Assets.php) — when that class
 * exists we simply depend on the handles it already registered, so
 * WordPress prints one copy, in the right order, no matter which of the two
 * (plugin, theme) actually enqueues it. When the plugin is inactive those
 * handles don't exist, so the theme falls back to its own vendored copies
 * (assets/vendor/) — same bytes, kept in sync manually — so the site never
 * white-screens or renders unstyled just because the plugin got deactivated.
 */
function rd_theme_enqueue_assets(): void {
	$design_assets_active = class_exists( '\RubenDance\Front\Design_Assets' );

	if ( ! $design_assets_active ) {
		wp_enqueue_style(
			'rd-fonts',
			RD_THEME_URI . '/assets/vendor/rd-fonts.css',
			array(),
			RD_THEME_VERSION
		);

		wp_enqueue_style(
			'rd-design',
			RD_THEME_URI . '/assets/vendor/rd-design.css',
			array( 'rd-fonts' ),
			RD_THEME_VERSION
		);
	}

	wp_enqueue_style(
		'rd-theme',
		RD_THEME_URI . '/assets/theme.css',
		array( 'rd-design' ),
		RD_THEME_VERSION
	);

	wp_enqueue_script(
		'rd-theme-menu',
		RD_THEME_URI . '/assets/theme.js',
		array(),
		RD_THEME_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'rd_theme_enqueue_assets' );

/**
 * Current front-end language slug ('cs'/'en'), degrading to Czech (the
 * site's default) exactly like `RubenDance\Lang::current()` does — see that
 * class's docblock for why Czech is the fallback. Prefers the plugin's own
 * `Lang` helper when available so both stay identical; falls back to
 * calling Polylang directly, then to a bare 'cs' when neither is present.
 *
 * @return string
 */
function rd_theme_current_lang(): string {
	if ( class_exists( '\RubenDance\Lang' ) ) {
		return \RubenDance\Lang::create_default()->current();
	}

	if ( function_exists( 'pll_current_language' ) ) {
		$lang = pll_current_language();

		if ( is_string( $lang ) && '' !== $lang ) {
			return $lang;
		}
	}

	return 'cs';
}

/**
 * The catalog ("Kurzy") page URL, via `Front\Pages` when the plugin is
 * active. Shared by the header nav, the homepage hero's "Vybrat kurz" CTA,
 * and the "Vyberte si kurz" section's "Všechny kurzy →" link, so all three
 * always agree on where "the courses" live.
 *
 * @return string
 */
function rd_theme_catalog_url(): string {
	if ( class_exists( '\RubenDance\Front\Pages' ) && class_exists( '\RubenDance\Front\Catalog_Page' ) ) {
		return \RubenDance\Front\Pages::url( \RubenDance\Front\Catalog_Page::PAGE_KEY, rd_theme_current_lang() );
	}

	return home_url( '/' );
}

/**
 * The calendar ("Kalendář") page URL, via `Front\Pages` when active.
 *
 * @return string
 */
function rd_theme_calendar_url(): string {
	if ( class_exists( '\RubenDance\Front\Pages' ) && class_exists( '\RubenDance\Front\Calendar_Page' ) ) {
		return \RubenDance\Front\Pages::url( \RubenDance\Front\Calendar_Page::PAGE_KEY, rd_theme_current_lang() );
	}

	return home_url( '/' );
}

/**
 * Primary nav links (design #2a/#2b/#2c: "Kurzy · Kalendář · Kontakt").
 * Resolved via the plugin's `Front\Pages` registry when it's active, so the
 * links always point at the real seeded catalog/calendar pages in the
 * current language; falls back to the site's front page otherwise (the same
 * "never a dead link" contract `Pages::url()` itself documents).
 *
 * "Kontakt" has no dedicated plugin page (spec's `Pages` registry only
 * covers auth/catalog/enroll/account/calendar/legal — see
 * plugin/ruben-dance/includes/Front/Pages.php), so it points at the
 * `#kontakt` anchor inside this theme's own footer (present on every page,
 * design #3a) rather than a page that doesn't exist.
 *
 * @return array<int, array{label: string, url: string}>
 */
function rd_theme_nav_links(): array {
	return array(
		array(
			'label' => __( 'Kurzy', 'ruben-dance-theme' ),
			'url'   => rd_theme_catalog_url(),
		),
		array(
			'label' => __( 'Kalendář', 'ruben-dance-theme' ),
			'url'   => rd_theme_calendar_url(),
		),
		array(
			'label' => __( 'Kontakt', 'ruben-dance-theme' ),
			'url'   => home_url( '/' ) . '#kontakt',
		),
	);
}

/**
 * The coral "Přihlásit se" / "Můj účet" pill (design #2a/#2b: login-state
 * aware). Logged-in visitors get the account page; anonymous visitors get
 * the login page — both via `Front\Pages` when the plugin is active,
 * falling back to WordPress's own `wp_login_url()`/home so the button is
 * always a real, working link.
 *
 * @return array{label: string, url: string}
 */
function rd_theme_auth_cta(): array {
	$lang = rd_theme_current_lang();

	if ( is_user_logged_in() ) {
		$url = home_url( '/' );
		if ( class_exists( '\RubenDance\Front\Pages' ) && class_exists( '\RubenDance\Front\Account_Page' ) ) {
			$url = \RubenDance\Front\Pages::url( \RubenDance\Front\Account_Page::PAGE_KEY, $lang );
		}

		return array(
			'label' => __( 'Můj účet', 'ruben-dance-theme' ),
			'url'   => $url,
		);
	}

	$url = wp_login_url();
	if ( class_exists( '\RubenDance\Front\Pages' ) ) {
		$url = \RubenDance\Front\Pages::url( \RubenDance\Front\Pages::LOGIN, $lang );
	}

	return array(
		'label' => __( 'Přihlásit se', 'ruben-dance-theme' ),
		'url'   => $url,
	);
}

/**
 * CZ/EN language switcher pill (design #2a nav example; markup contract
 * documented in rd-design.css above `.rd-lang-switch`). Built from
 * `pll_the_languages()` (Polylang's own front-end language-links API) so
 * this always reflects however many languages the site actually has
 * configured, on whatever page is currently showing. Returns '' when
 * Polylang is inactive — a single-language site has nothing to switch
 * between, so the whole pill is omitted rather than shown empty/broken.
 *
 * @return string HTML, empty string when there is nothing to render.
 */
function rd_theme_lang_switch_html(): string {
	if ( ! function_exists( 'pll_the_languages' ) ) {
		return '';
	}

	$languages = pll_the_languages( array( 'raw' => 1 ) );

	if ( empty( $languages ) || ! is_array( $languages ) ) {
		return '';
	}

	// Design #2a's switcher shows the country-style "CZ", not Polylang's
	// ISO 639-1 language slug "cs" — every other slug (en, …) already
	// matches its design label when uppercased, so only Czech needs the
	// override.
	$labels = array( 'cs' => 'CZ' );

	$items = '';

	foreach ( $languages as $language ) {
		$slug    = isset( $language['slug'] ) ? (string) $language['slug'] : '';
		$url     = isset( $language['url'] ) ? (string) $language['url'] : '#';
		$current = ! empty( $language['current_lang'] );

		if ( '' === $slug ) {
			continue;
		}

		$label = $labels[ $slug ] ?? strtoupper( $slug );

		$items .= sprintf(
			'<a href="%1$s"%2$s>%3$s</a>',
			esc_url( $url ),
			$current ? ' class="is-active" aria-current="true"' : '',
			esc_html( $label )
		);
	}

	if ( '' === $items ) {
		return '';
	}

	return '<div class="rd-lang-switch">' . $items . '</div>';
}

/**
 * Footer legal links (design #3a: "Obchodní podmínky" / "Zásady ochrany
 * osobních údajů"), resolved via `Front\Pages` when the plugin is active.
 * Falls back to the site's front page — never a bare '#' — matching
 * `Pages::url()`'s own "always a real link" contract, so the footer never
 * ships a dead link just because the plugin is inactive.
 *
 * @return array<int, array{label: string, url: string}>
 */
function rd_theme_footer_legal_links(): array {
	$lang        = rd_theme_current_lang();
	$terms_url   = home_url( '/' );
	$privacy_url = home_url( '/' );

	if ( class_exists( '\RubenDance\Front\Pages' ) ) {
		$terms_url   = \RubenDance\Front\Pages::url( \RubenDance\Front\Pages::TERMS, $lang );
		$privacy_url = \RubenDance\Front\Pages::url( \RubenDance\Front\Pages::PRIVACY_POLICY, $lang );
	}

	return array(
		array(
			'label' => __( 'Obchodní podmínky', 'ruben-dance-theme' ),
			'url'   => $terms_url,
		),
		array(
			'label' => __( 'Zásady ochrany osobních údajů', 'ruben-dance-theme' ),
			'url'   => $privacy_url,
		),
	);
}

/**
 * The "RUBEN·DANCE" logotype (design #2a/#3a: coral middle dot), shared by
 * header.php and footer.php so the two never drift apart.
 *
 * @param string $extra_class Additional class(es) for the wrapping element.
 * @return string HTML.
 */
function rd_theme_logo_html( string $extra_class = '' ): string {
	$class = trim( 'rd-logo ' . $extra_class );

	return sprintf(
		'<a class="%1$s" href="%2$s"><span aria-hidden="true">RUBEN</span><span class="rd-logo__dot" aria-hidden="true">&middot;</span><span aria-hidden="true">DANCE</span><span class="screen-reader-text">Ruben Dance</span></a>',
		esc_attr( $class ),
		esc_url( home_url( '/' ) )
	);
}

/**
 * A self-contained (no network request) placeholder photo: a two-tone SVG
 * gradient encoded as a data: URI. Stands in for the real ruben-dance.cz
 * photography (hero, course cards, Ruben's profile) without hotlinking the
 * live site's images — spec's GDPR "no external runtime hosts" rule extends
 * naturally to "no unrelated third-party image host" too, and hotlinking
 * ruben-dance.cz specifically would also load a photo nobody signed off on
 * for this new theme.
 *
 * @param string $from Gradient start color (hex).
 * @param string $to   Gradient end color (hex).
 * @return string data: URI.
 */
function rd_theme_placeholder_photo( string $from, string $to ): string {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300">'
		. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
		. '<stop offset="0%" stop-color="' . $from . '"/>'
		. '<stop offset="100%" stop-color="' . $to . '"/>'
		. '</linearGradient></defs>'
		. '<rect width="400" height="300" fill="url(#g)"/>'
		. '</svg>';

	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

/**
 * Real courses for the homepage "Vyberte si kurz" section (design
 * #1a/#2c/#2b), pulled from the plugin's `rd_course` CPT. Returns an empty
 * array when the plugin/post type is absent so `front-page.php` can fall
 * back to nothing rather than fatal.
 *
 * Deliberately a plain `WP_Query` against the CPT (title, permalink,
 * featured image, `rd_level` terms for the subtitle) rather than
 * `Services\Catalog_Service` — that service returns *open enrollment terms*
 * grouped by course (catalog-page business logic, M08 territory this theme
 * doesn't own); the homepage only ever needs "which courses exist and what
 * do they look like", which the CPT alone already answers, and Polylang
 * (when active) filters `WP_Query` to the current language automatically.
 *
 * @param int $limit Max number of courses to return.
 * @return array<int, array{title: string, url: string, subtitle: string, thumbnail_html: string}>
 */
function rd_theme_homepage_courses( int $limit ): array {
	if ( ! post_type_exists( 'rd_course' ) ) {
		return array();
	}

	$posts = get_posts(
		array(
			'post_type'      => 'rd_course',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'date'       => 'ASC',
			),
		)
	);

	$gradients = array(
		array( '#F08A24', '#E8604C' ),
		array( '#E8604C', '#F5B840' ),
		array( '#F5B840', '#F08A24' ),
	);

	$courses = array();

	foreach ( $posts as $index => $post ) {
		$subtitle = '';

		$levels = get_the_terms( $post->ID, 'rd_level' );
		if ( is_array( $levels ) && array() !== $levels ) {
			$subtitle = implode( ' · ', wp_list_pluck( $levels, 'name' ) );
		}

		if ( '' === $subtitle ) {
			$excerpt  = get_the_excerpt( $post );
			$subtitle = wp_trim_words( '' !== $excerpt ? $excerpt : $post->post_content, 8, '…' );
		}

		if ( has_post_thumbnail( $post ) ) {
			$thumbnail_html = get_the_post_thumbnail(
				$post,
				'medium_large',
				array(
					'class' => 'rd-course-card__photo',
					'alt'   => get_the_title( $post ),
				)
			);
		} else {
			$colors = $gradients[ $index % count( $gradients ) ];
			$thumbnail_html = sprintf(
				'<img class="rd-course-card__photo" src="%1$s" alt="%2$s" loading="lazy">',
				esc_attr( rd_theme_placeholder_photo( $colors[0], $colors[1] ) ),
				/* translators: %s: course title. */
				esc_attr( sprintf( __( 'Fotografie kurzu %s', 'ruben-dance-theme' ), get_the_title( $post ) ) )
			);
		}

		$courses[] = array(
			'title'          => get_the_title( $post ),
			'url'            => get_permalink( $post ),
			'subtitle'       => $subtitle,
			'thumbnail_html' => $thumbnail_html,
		);
	}

	return $courses;
}

/**
 * Table of contents for `template-legal.php` (design #3i/#4j: "Obsah" card),
 * built automatically from the page's own `<h2>` headings rather than a
 * second, hand-maintained copy of the section list — the legal pages' body
 * text is lawyer content the theme must never hardcode (see
 * `Cli\Seed_Command::LEGAL_PAGES`'s placeholder copy), so the only heading
 * list this can ever safely reflect is whatever headings that content
 * actually contains right now.
 *
 * Also injects an `id` attribute into each `<h2>` (needed for the TOC's own
 * `#anchor` links) via the same pass, so the returned HTML and the returned
 * TOC entries can never disagree about the anchor slugs.
 *
 * @param string $html Already-`the_content`-filtered page HTML.
 * @return array{html: string, items: array<int, array{id: string, text: string}>}
 */
function rd_theme_legal_toc( string $html ): array {
	$items   = array();
	$counter = 0;

	$with_ids = preg_replace_callback(
		'/<h2([^>]*)>(.*?)<\/h2>/is',
		static function ( array $matches ) use ( &$items, &$counter ): string {
			++$counter;

			$text = wp_strip_all_tags( $matches[2] );
			$id   = sanitize_title( $text );

			if ( '' === $id ) {
				$id = 'section-' . $counter;
			}

			$items[] = array(
				'id'   => $id,
				'text' => $text,
			);

			return '<h2' . $matches[1] . ' id="' . esc_attr( $id ) . '">' . $matches[2] . '</h2>';
		},
		$html
	);

	return array(
		'html'  => is_string( $with_ids ) ? $with_ids : $html,
		'items' => $items,
	);
}
