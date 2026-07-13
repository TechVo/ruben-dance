<?php
/**
 * `[rd_voucher_inquiry]` shortcode: the short inquiry form on the voucher
 * info page (spec F17/§4.6).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Services\Voucher_Inquiry_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Voucher_Page.
 *
 * Output only — state changes already happened in `Voucher_Form_Handler` on
 * `template_redirect` (mirrors `Enroll_Page`/`Enrollment_Form_Handler`'s
 * split). The voucher *information* itself is normal WP page content, edited
 * like any other page (spec F17: "a voucher info page (normal WP content,
 * CS + EN)") — this shortcode only renders the inquiry form beneath it, so
 * the same page works whether the shortcode sits inside the seeded content
 * or an admin later rearranges the page in the block editor.
 */
class Voucher_Page {

	/**
	 * `Front\Pages` "which" key this shortcode's page is registered under.
	 * See `Catalog_Page::PAGE_KEY` for why this is a plain string rather than
	 * a change to M07's `Pages` class.
	 *
	 * @var string
	 */
	const PAGE_KEY = 'voucher';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_styles' ) );
	}

	/**
	 * Register the `[rd_voucher_inquiry]` shortcode.
	 */
	public static function register_shortcode(): void {
		add_shortcode( 'rd_voucher_inquiry', array( self::class, 'render' ) );
	}

	/**
	 * Minimal shortcode stylesheet. Small enough to load unconditionally
	 * rather than detecting which page has the shortcode — same reasoning
	 * `Shortcodes::enqueue_styles()` uses for `front-auth.css`.
	 */
	public static function enqueue_styles(): void {
		wp_enqueue_style(
			'rd-front-voucher',
			plugins_url( 'public/assets/front-voucher.css', RUBEN_DANCE_PLUGIN_FILE ),
			array( 'rd-design' ),
			RUBEN_DANCE_VERSION
		);
	}

	/**
	 * `[rd_voucher_inquiry]`.
	 *
	 * Design/screens.html #3i (mobile) / #4j (desktop): the decorative
	 * voucher-preview card (`voucher-card.php`) always renders first, ahead
	 * of whichever state the form itself is in — it is pure decoration, not
	 * tied to the submission result, so it stays on screen through a
	 * validation error, a rate limit, and the post-submit success state
	 * alike. `front-voucher.css` floats it beside the form at desktop widths
	 * and stacks it above the form on mobile.
	 *
	 * @return string
	 */
	public static function render(): string {
		$result = Voucher_Form_Handler::$result ?? array(
			'state'     => 'form',
			'errors'    => array(),
			'submitted' => array(),
		);

		$card = self::render_template( 'voucher-card', array() );

		if ( 'success' === $result['state'] ) {
			return '<div class="rd-app rd-vou-inquiry">' . $card . self::render_template( 'voucher-success', array() ) . '</div>';
		}

		$form = self::render_template(
			'voucher-form',
			array(
				'errors'       => $result['errors'],
				'submitted'    => $result['submitted'],
				'rate_limited' => 'rate_limited' === $result['state'],
			)
		);

		return '<div class="rd-app rd-vou-inquiry">' . $card . $form . '</div>';
	}

	/**
	 * Translate a `Voucher_Inquiry_Service::ERROR_*` code into a message.
	 *
	 * @param string $code One of the `Voucher_Inquiry_Service::ERROR_*` constants.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		switch ( $code ) {
			case Voucher_Inquiry_Service::ERROR_NAME_REQUIRED:
				return __( 'Please enter your name.', 'ruben-dance' );

			case Voucher_Inquiry_Service::ERROR_NAME_TOO_LONG:
				return __( 'Name is too long.', 'ruben-dance' );

			case Voucher_Inquiry_Service::ERROR_EMAIL_REQUIRED:
				return __( 'Please enter your email address.', 'ruben-dance' );

			case Voucher_Inquiry_Service::ERROR_EMAIL_INVALID:
				return __( 'Please enter a valid email address.', 'ruben-dance' );

			case Voucher_Inquiry_Service::ERROR_MESSAGE_REQUIRED:
				return __( 'Please enter your message.', 'ruben-dance' );

			case Voucher_Inquiry_Service::ERROR_MESSAGE_TOO_LONG:
				return __( 'Message is too long.', 'ruben-dance' );

			default:
				return __( 'Please check the highlighted fields.', 'ruben-dance' );
		}
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
