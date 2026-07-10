<?php
/**
 * "Email Templates" admin screen: edit the per-type, per-language subject/
 * body templates, with a placeholder legend and a "send test to me" button
 * (spec F14/M13).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Admin;

use RubenDance\Emails\Email_Sender;
use RubenDance\Emails\Email_Templates;
use RubenDance\Lang;
use RubenDance\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Email_Templates_Page.
 *
 * One template (type + language pair, picked via GET) is edited at a time —
 * fourteen subject/body pairs on one screen would be unusable. Follows the
 * same nonce + `rd_manage` + `load-{hook}` processing pattern as
 * `Settings_Page`. "Send test to me" saves the submitted template first and
 * then sends it (through the same `Emails\Email_Sender` path as a real
 * send, so it exercises composition, transport and the email log alike) to
 * the logged-in admin's own address, with sample placeholder values.
 */
class Email_Templates_Page {

	const SLUG = 'ruben-dance-email-templates';

	const SAVE_NONCE_ACTION = 'rd_email_template_save';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Add the "Email Templates" submenu page under the Ruben Dance top-level
	 * menu.
	 */
	public static function add_menu(): void {
		$hook_suffix = add_submenu_page(
			Menu::SLUG,
			__( 'Email Templates', 'ruben-dance' ),
			__( 'Email Templates', 'ruben-dance' ),
			Roles::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);

		if ( false !== $hook_suffix ) {
			add_action( "load-{$hook_suffix}", array( self::class, 'handle_request' ) );
		}
	}

	/**
	 * URL to this screen for one type/language pair.
	 *
	 * @param string $type One of `Email_Templates::TYPES`.
	 * @param string $lang One of `Email_Templates::LANGUAGES`.
	 * @return string
	 */
	public static function url( string $type, string $lang ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'type' => $type,
				'lang' => $lang,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Process a save/test-send for this screen, before any output is sent.
	 * Hooked to `load-{$hook_suffix}` (see `add_menu()`).
	 */
	public static function handle_request(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage email templates.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this only routes to the save branch; check_admin_referer() immediately below performs the real verification before any field is read or written.
		if ( ! isset( $_POST['rd_template_action'] ) ) {
			return;
		}

		check_admin_referer( self::SAVE_NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() above.
		$action = sanitize_key( wp_unslash( $_POST['rd_template_action'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() above.
		$type = isset( $_POST['template_type'] ) ? sanitize_text_field( wp_unslash( $_POST['template_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() above.
		$lang = isset( $_POST['template_lang'] ) ? sanitize_key( wp_unslash( $_POST['template_lang'] ) ) : '';

		if ( ! in_array( $type, Email_Templates::TYPES, true ) || ! in_array( $lang, Email_Templates::LANGUAGES, true ) ) {
			self::redirect( Email_Templates::TYPE_E1, Lang::CS, 'invalid' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() above.
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() above. wp_kses_post() (not a plain sanitizer) because the body legitimately contains the same HTML tags a post body may.
		$body = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';

		Email_Templates::save( $type, $lang, $subject, $body );

		if ( 'test' === $action ) {
			$sent = Email_Sender::create_default()->send(
				$type,
				$lang,
				wp_get_current_user()->user_email,
				self::sample_values( $lang ),
				null,
				get_current_user_id()
			);

			self::redirect( $type, $lang, $sent ? 'test_sent' : 'test_failed' );
		}

		self::redirect( $type, $lang, 'saved' );
	}

	/**
	 * Main entry point, wired as the submenu page callback. Runs after
	 * `handle_request()`; output only, no state changes.
	 */
	public static function render(): void {
		if ( ! current_user_can( Roles::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage email templates.', 'ruben-dance' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which template to show, no state change.
		$type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : Email_Templates::TYPE_E1;
		$type = in_array( $type, Email_Templates::TYPES, true ) ? $type : Email_Templates::TYPE_E1;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which template to show, no state change.
		$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : Lang::CS;
		$lang = in_array( $lang, Email_Templates::LANGUAGES, true ) ? $lang : Lang::CS;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Email Templates', 'ruben-dance' ) . '</h1>';

		self::render_notice_from_query();
		self::render_picker( $type, $lang );
		self::render_form( $type, $lang );

		echo '</div>';
	}

	/**
	 * The type/language navigation above the edit form.
	 *
	 * @param string $current_type Currently edited type.
	 * @param string $current_lang Currently edited language.
	 */
	private static function render_picker( string $current_type, string $current_lang ): void {
		echo '<ul class="subsubsub" style="margin-bottom:1em;">';

		$labels = Email_Templates::type_labels();
		$items  = array();

		foreach ( Email_Templates::TYPES as $type ) {
			$items[] = sprintf(
				'<li><a href="%1$s"%2$s>%3$s</a></li>',
				esc_url( self::url( $type, $current_lang ) ),
				$type === $current_type ? ' class="current"' : '',
				esc_html( $labels[ $type ] )
			);
		}

		echo implode( ' | ', $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item is individually escaped as it is built above.
		echo '</ul><br class="clear">';

		echo '<p>';

		foreach ( Email_Templates::LANGUAGES as $lang ) {
			$label = Lang::EN === $lang ? __( 'English', 'ruben-dance' ) : __( 'Czech', 'ruben-dance' );

			if ( $lang === $current_lang ) {
				echo '<strong>' . esc_html( $label ) . '</strong> ';
			} else {
				echo '<a href="' . esc_url( self::url( $current_type, $lang ) ) . '">' . esc_html( $label ) . '</a> ';
			}
		}

		echo '</p>';

		if ( Email_Templates::TYPE_E3 === $current_type && Lang::EN === $current_lang ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Note: the E3 admin notification is always sent in Czech (spec F14); the English variant here is never used.', 'ruben-dance' ) . '</p></div>';
		}
	}

	/**
	 * The subject/body edit form with placeholder legend and both buttons.
	 *
	 * @param string $type Template type.
	 * @param string $lang Template language.
	 */
	private static function render_form( string $type, string $lang ): void {
		$template = Email_Templates::get( $type, $lang );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">';
		wp_nonce_field( self::SAVE_NONCE_ACTION );
		echo '<input type="hidden" name="template_type" value="' . esc_attr( $type ) . '">';
		echo '<input type="hidden" name="template_lang" value="' . esc_attr( $lang ) . '">';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="rd_template_subject">' . esc_html__( 'Subject', 'ruben-dance' ) . '</label></th><td>';
		echo '<input type="text" id="rd_template_subject" name="subject" class="large-text" required="required" value="' . esc_attr( $template['subject'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="rd_template_body">' . esc_html__( 'Body (HTML)', 'ruben-dance' ) . '</label></th><td>';
		echo '<textarea id="rd_template_body" name="body" class="large-text code" rows="12" required="required">' . esc_textarea( $template['body'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Sent as HTML. Placeholder values are HTML-escaped automatically; unknown or empty placeholders are removed.', 'ruben-dance' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Available placeholders', 'ruben-dance' ) . '</th><td>';
		echo '<table class="widefat striped" style="max-width:520px;"><tbody>';

		foreach ( Email_Templates::placeholders_for( $type ) as $token => $description ) {
			echo '<tr><td style="width:180px;"><code>{' . esc_html( $token ) . '}</code></td><td>' . esc_html( $description ) . '</td></tr>';
		}

		echo '</tbody></table></td></tr>';

		echo '</tbody></table>';

		echo '<p class="submit">';
		echo '<button type="submit" name="rd_template_action" value="save" class="button button-primary">' . esc_html__( 'Save Template', 'ruben-dance' ) . '</button> ';
		echo '<button type="submit" name="rd_template_action" value="test" class="button">' . esc_html(
			sprintf(
			/* translators: %s: current admin's email address. */
				__( 'Save & send test to me (%s)', 'ruben-dance' ),
				wp_get_current_user()->user_email
			)
		) . '</button>';
		echo '</p>';

		echo '</form>';
	}

	/**
	 * Sample placeholder values for the test send — one superset covering
	 * every type's tokens, so any template renders fully.
	 *
	 * @param string $lang Template language (drives the sample formatting).
	 * @return array<string, string>
	 */
	private static function sample_values( string $lang ): array {
		$is_en = Lang::EN === $lang;

		return array(
			'first_name'      => 'Jana',
			'course'          => $is_en ? 'Salsa for beginners (sample)' : 'Salsa pro začátečníky (ukázka)',
			'participant'     => 'Jana Nováková',
			'term_schedule'   => $is_en ? 'Autumn 2026, Monday 18:00–19:00' : 'Podzim 2026, pondělí 18:00–19:00',
			'price'           => $is_en ? '1,500.00 CZK' : '1 500,00 Kč',
			'account_number'  => '123456789/0100',
			'variable_symbol' => '20260042',
			'due_date'        => $is_en ? '15 Sep 2026' : '15. 9. 2026',
			'link'            => home_url( '/?rd_sample_link=1' ),
			'lesson_date'     => $is_en ? '6 Oct 2026' : '6. 10. 2026',
			'status'          => $is_en ? 'cancelled' : 'zrušena',
			'note'            => $is_en ? 'Sample note about the change.' : 'Ukázková poznámka ke změně.',
		);
	}

	/**
	 * Redirect back to this screen with a notice code (POST-redirect-GET).
	 *
	 * @param string $type   Template type.
	 * @param string $lang   Template language.
	 * @param string $notice Notice code.
	 */
	private static function redirect( string $type, string $lang, string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'rd_notice' => $notice ), self::url( $type, $lang ) ) );
		exit;
	}

	/**
	 * Read the `rd_notice` query arg left by a redirect and render it.
	 */
	private static function render_notice_from_query(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: purely cosmetic (which notice text to show after a redirect), no state change.
		$notice = isset( $_GET['rd_notice'] ) ? sanitize_key( wp_unslash( $_GET['rd_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'saved'       => array( 'success', __( 'Template saved. The next send of this type uses it.', 'ruben-dance' ) ),
			'test_sent'   => array( 'success', __( 'Template saved and test email sent to your address (also recorded in the email log).', 'ruben-dance' ) ),
			'test_failed' => array( 'error', __( 'Template saved, but the test email could not be sent (logged with status "failed") — check the site’s mail configuration.', 'ruben-dance' ) ),
			'invalid'     => array( 'error', __( 'Unknown template type or language.', 'ruben-dance' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $messages[ $notice ];

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
