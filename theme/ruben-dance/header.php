<?php
/**
 * Site header: logotype, nav (Kurzy/Kalendář/Kontakt), CZ/EN switcher,
 * login-state-aware coral pill, and the mobile hamburger menu.
 *
 * Design source: design/screens.html #2a (nav pattern + mobile menu panel),
 * #2b (desktop header), #2c (tablet header).
 *
 * The mobile menu is a native <details>/<summary> disclosure rather than a
 * JS-driven show/hide: opening and closing already works with zero
 * JavaScript (a browser built-in, keyboard operable via Enter/Space on the
 * <summary>), which is what "must degrade to visible links without JS"
 * requires. assets/theme.js only *enhances* it afterwards (closes it on an
 * outside click / Escape, keeps aria-expanded in sync) — see that file.
 *
 * @package RubenDanceTheme
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

$rd_nav_links     = rd_theme_nav_links();
$rd_auth_cta      = rd_theme_auth_cta();
$rd_lang_switch   = rd_theme_lang_switch_html();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="rd-skip-link" href="#main"><?php esc_html_e( 'Přeskočit na obsah', 'ruben-dance-theme' ); ?></a>

<header class="rd-site-header">
	<div class="rd-site-header__inner">
		<?php echo rd_theme_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rd_theme_logo_html() escapes internally. ?>

		<nav class="rd-nav-desktop" aria-label="<?php esc_attr_e( 'Hlavní menu', 'ruben-dance-theme' ); ?>">
			<?php foreach ( $rd_nav_links as $rd_link ) : ?>
				<a href="<?php echo esc_url( $rd_link['url'] ); ?>"><?php echo esc_html( $rd_link['label'] ); ?></a>
			<?php endforeach; ?>

			<?php if ( '' !== $rd_lang_switch ) : ?>
				<?php echo $rd_lang_switch; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rd_theme_lang_switch_html() escapes internally. ?>
			<?php endif; ?>

			<a class="rd-btn rd-btn--primary rd-nav-desktop__cta" href="<?php echo esc_url( $rd_auth_cta['url'] ); ?>"><?php echo esc_html( $rd_auth_cta['label'] ); ?></a>
		</nav>

		<details class="rd-mobile-menu">
			<summary class="rd-mobile-menu__trigger" aria-label="<?php esc_attr_e( 'Menu', 'ruben-dance-theme' ); ?>">
				<span class="rd-mobile-menu__bars" aria-hidden="true"></span>
			</summary>
			<nav class="rd-mobile-menu__panel" aria-label="<?php esc_attr_e( 'Mobilní menu', 'ruben-dance-theme' ); ?>">
				<?php foreach ( $rd_nav_links as $rd_link ) : ?>
					<a href="<?php echo esc_url( $rd_link['url'] ); ?>"><?php echo esc_html( $rd_link['label'] ); ?></a>
				<?php endforeach; ?>

				<?php if ( '' !== $rd_lang_switch ) : ?>
					<?php echo $rd_lang_switch; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rd_theme_lang_switch_html() escapes internally. ?>
				<?php endif; ?>

				<a class="rd-mobile-menu__cta" href="<?php echo esc_url( $rd_auth_cta['url'] ); ?>"><?php echo esc_html( $rd_auth_cta['label'] ); ?></a>
			</nav>
		</details>
	</div>
</header>

<main id="main" class="rd-site-main">
