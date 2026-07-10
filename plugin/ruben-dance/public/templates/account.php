<?php
/**
 * `[rd_account]` template partial: tab navigation + the active tab's content.
 *
 * Variables available: string $tab, array<string,string> $tab_urls, string
 * $lang, string $email_notice, array<int,array> $enrollments,
 * array<int,array> $schedule, array<string,mixed> $profile, string $bank_account.
 *
 * @package RubenDance
 */

use RubenDance\Front\Account_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}
?>
<div class="rd-account">
	<nav class="rd-account-tabs">
		<a href="<?php echo esc_url( $tab_urls[ Account_Page::TAB_ENROLLMENTS ] ); ?>" class="rd-account-tab<?php echo Account_Page::TAB_ENROLLMENTS === $tab ? ' is-active' : ''; ?>"><?php esc_html_e( 'My enrollments', 'ruben-dance' ); ?></a>
		<a href="<?php echo esc_url( $tab_urls[ Account_Page::TAB_SCHEDULE ] ); ?>" class="rd-account-tab<?php echo Account_Page::TAB_SCHEDULE === $tab ? ' is-active' : ''; ?>"><?php esc_html_e( 'My schedule', 'ruben-dance' ); ?></a>
		<a href="<?php echo esc_url( $tab_urls[ Account_Page::TAB_PROFILE ] ); ?>" class="rd-account-tab<?php echo Account_Page::TAB_PROFILE === $tab ? ' is-active' : ''; ?>"><?php esc_html_e( 'Profile', 'ruben-dance' ); ?></a>
	</nav>

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
