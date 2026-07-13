<?php
/**
 * The universal HTML email chrome wrapped around every composed email body
 * (design/screens.html #3j).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Emails;

use RubenDance\Front\Pages;
use RubenDance\Lang;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Email_Layout.
 *
 * Design #3j's note says it all: "the same template serves verification,
 * payment confirmation, lesson cancellation and reminder — only the body
 * block changes". `Email_Sender::send()` is the one call site every E1-E8
 * trigger already funnels through (see that class's own doc comment), so
 * this is applied there, once, around whatever `Email_Templates::compose()`
 * (plus `Payment_Qr_Email::augmenter()`, when applicable) already produced —
 * no per-trigger class needs to know this wrapper exists.
 *
 * Every style below is a `style="…"` attribute, not a class name: this is
 * the one screen in the whole plugin that cannot depend on `rd-design.css`
 * (email clients don't fetch external stylesheets, and several strip
 * `<style>` blocks too) and cannot use the self-hosted Bricolage Grotesque
 * font either (no `@font-face`/web-font loading in an email) — hence the
 * plain system-font stack fallback that's already `rd-design.css`'s own
 * belt-and-braces `--rd-font` fallback chain (see that file's docblock).
 * Colors/radii are still the same design tokens (design/README.md), just
 * copied as literal hex values instead of `var(--rd-*)` custom properties,
 * which most email clients strip.
 */
class Email_Layout {

	/**
	 * Wrap a composed email body in the shared chrome: centered card, coral
	 * top bar, RUBEN·DANCE logo, a heading, the body itself, and a footer
	 * with the legal links + an email-preferences ("Odhlásit odběr") link.
	 *
	 * @param string $subject Already-placeholder-rendered subject line, reused as the
	 *                        visible in-body heading (see this method's own reasoning
	 *                        below) — HTML-escaped here, never passed in pre-escaped.
	 * @param string $body    Already-composed (and, for E2/E7, QR-augmented) HTML body.
	 * @param string $lang    Recipient language, `Lang::CS`/`Lang::EN`.
	 * @return string Full HTML document.
	 */
	public static function wrap( string $subject, string $body, string $lang ): string {
		$terms_url   = esc_url( Pages::url( Pages::TERMS, $lang ) );
		$privacy_url = esc_url( Pages::url( Pages::PRIVACY_POLICY, $lang ) );
		// 'account' matches `Front\Account_Page::PAGE_KEY` — a plain string
		// rather than a `Pages::` constant, the same reasoning that class's
		// own doc comment gives (this namespace doesn't otherwise depend on
		// `Front\Account_Page`, so it isn't `use`-imported just for one
		// constant); the account page's "Profile" tab is where a customer
		// manages their marketing-email consent (`Voucher_Form_Handler`'s
		// admin notifications and other CS-only sends land here too, for
		// whom the link is simply inert — harmless, not incorrect).
		$account_url = esc_url( Pages::url( 'account', $lang ) );

		$is_en = Lang::EN === $lang;

		// Reusing the (already localized, already placeholder-rendered)
		// subject line as the in-body heading means every E1-E8 template can
		// gain a heading without `Email_Templates::default_template()`'s 16
		// body strings each needing their own near-duplicate heading text —
		// the subject already says "what this email is about" in the
		// recipient's language, which is exactly what the heading needs to
		// say too.
		$heading = esc_html( $subject );

		$terms_label   = $is_en ? 'Terms & Conditions' : 'Obchodní podmínky';
		$privacy_label = $is_en ? 'Privacy' : 'Ochrana údajů';
		$unsub_label   = $is_en ? 'Manage email preferences' : 'Odhlásit odběr';
		$footer_note   = $is_en ? 'Ruben-Dance &middot; Prague &middot; 776 337 877' : 'Ruben-Dance &middot; Praha &middot; 776 337 877';

		$link_style = 'color:rgba(43,23,16,.55);text-decoration:underline';

		return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$heading}</title>
</head>
<body style="margin:0;padding:0;background:#EFE7D8">
<div style="background:#EFE7D8;padding:24px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#2B1710">
	<div style="max-width:432px;margin:0 auto">
		<div style="text-align:center;font-weight:800;font-size:16px;letter-spacing:-0.02em;padding-bottom:14px">RUBEN<span style="color:#E8604C">&middot;</span>DANCE</div>
		<div style="background:#ffffff;border-radius:16px;overflow:hidden">
			<div style="height:6px;line-height:6px;font-size:0;background-color:#E8604C">&nbsp;</div>
			<div style="padding:24px">
				<div style="font-weight:800;font-size:20px;letter-spacing:-0.01em;margin:0 0 10px;color:#2B1710">{$heading}</div>
				<div style="font-size:14px;line-height:1.55;color:rgba(43,23,16,.85)">
{$body}
				</div>
			</div>
		</div>
		<div style="text-align:center;font-size:11.5px;color:rgba(43,23,16,.55);padding-top:14px;line-height:1.6">
			{$footer_note}<br>
			<a href="{$terms_url}" style="{$link_style}">{$terms_label}</a> &middot; <a href="{$privacy_url}" style="{$link_style}">{$privacy_label}</a> &middot; <a href="{$account_url}" style="{$link_style}">{$unsub_label}</a>
		</div>
	</div>
</div>
</body>
</html>
HTML;
	}
}
