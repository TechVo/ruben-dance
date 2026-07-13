<?php
/**
 * `[rd_account]` template partial: tab navigation + the active tab's content.
 * Design/screens.html #3h (mobile 390 — segmented pill tabs, top) / #4i
 * (tablet 834 — same pill tabs, just wider) / #4h (desktop 1280 — left
 * sidebar nav card with a "Log out" link, per-tab heading to the right).
 *
 * One nav is rendered; front-account.css reflows it from a horizontal pill
 * bar into a vertical sidebar list at 1024px — the same "one markup, CSS
 * reflows it" approach `catalog.php`'s `<details>` filter panel already uses
 * for its own mobile-chip/desktop-sidebar switch.
 *
 * Variables available: string $tab, array<string,string> $tab_urls, string
 * $lang, string $email_notice, array<int,array> $enrollments, bool
 * $has_enrollments, array<int,array> $schedule, array<string,mixed> $profile,
 * string $bank_account, string $catalog_url, string $logout_url.
 *
 * @package RubenDance
 */

use RubenDance\Front\Account_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-app rd-account">
	<div class="rd-acc-shell">
		<nav class="rd-acc-nav" aria-label="<?php esc_attr_e( 'Account navigation', 'ruben-dance' ); ?>">
			<span class="rd-acc-nav__heading"><?php esc_html_e( 'My account', 'ruben-dance' ); ?></span>
			<?php foreach ( Account_Page::TABS as $ruben_dance_tab_key ) : ?>
				<a
					href="<?php echo esc_url( $tab_urls[ $ruben_dance_tab_key ] ); ?>"
					class="rd-acc-nav__tab<?php echo $ruben_dance_tab_key === $tab ? ' is-active' : ''; ?>"
					<?php echo $ruben_dance_tab_key === $tab ? 'aria-current="page"' : ''; ?>
				><?php echo esc_html( Account_Page::tab_label( $ruben_dance_tab_key ) ); ?></a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $logout_url ); ?>" class="rd-acc-nav__logout"><?php esc_html_e( 'Log out', 'ruben-dance' ); ?></a>
		</nav>

		<div class="rd-acc-content">
			<h2 class="rd-h2 rd-acc-content__title"><?php echo esc_html( Account_Page::tab_label( $tab ) ); ?></h2>

			<?php
			// Template partials share this scope (same reasoning `Shortcodes::render_template()`
			// documents for extract() — get_template_part()-style), so $enrollments/
			// $schedule/$profile/etc. are all already visible to whichever of these
			// is included below.
			if ( Account_Page::TAB_ENROLLMENTS === $tab ) {
				include __DIR__ . '/account-enrollments.php';
			} elseif ( Account_Page::TAB_SCHEDULE === $tab ) {
				include __DIR__ . '/account-schedule.php';
			} else {
				include __DIR__ . '/account-profile.php';
			}
			?>
		</div>
	</div>
</div>
