<?php
/**
 * Authenticated `admin-ajax.php` endpoint rendering one enrollment's QR
 * payment code (spec F16/§4.5), for the `[rd_account]` "My enrollments" tab.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Emails\Enrollment_Email_Data;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Qr_Code_Generator;
use RubenDance\Services\Spayd_Builder;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Qr_Code_Ajax.
 *
 * Registered on `wp_ajax_` only, never `wp_ajax_nopriv_` — the same reasoning
 * `Admin\Roster_Ajax` documents: a logged-out request has no action to hook
 * into at all, so WordPress core itself answers it before this class ever
 * runs (spec acceptance criterion: "refuses anonymous ... requests"). An
 * `<img src>` tag cannot send a custom header, so the nonce travels as a
 * query parameter (`check_ajax_referer()` reads `$_REQUEST` either way) —
 * the same reason a plain GET, not a state-changing POST, is appropriate
 * here regardless. Ownership is enforced by
 * `Enrollment_Repository::find_for_user()` at the SQL layer, not merely by a
 * check in this class (spec §5: "checked in the repository layer"), so a
 * request for another customer's `enrollment_id` finds no row at all rather
 * than being merely "denied" — the same shape as `Account_Service`'s
 * guarantee for the rest of the account page.
 */
class Qr_Code_Ajax {

	/**
	 * `admin-ajax.php` action name, also used as the nonce action.
	 *
	 * @var string
	 */
	const ACTION = 'rd_account_qr_code';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The URL `[rd_account]`'s enrollments tab embeds as the QR `<img>`'s
	 * `src`, already carrying a fresh nonce for `$enrollment_id`.
	 *
	 * @param int $enrollment_id Enrollment ID.
	 * @return string
	 */
	public static function url( int $enrollment_id ): string {
		return add_query_arg(
			array(
				'action'        => self::ACTION,
				'enrollment_id' => $enrollment_id,
				'nonce'         => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Render the PNG, or fail with a bare HTTP status and no body — there is
	 * no sensible "error image" to fall back to, and the `<img>` tag simply
	 * shows a broken-image icon, which is the correct behavior for a request
	 * that should never legitimately happen (spec: "no errors" only promises
	 * the *normal* no-IBAN case degrades quietly; this endpoint is never
	 * requested at all in that case — see `Front\Account_Page::display_enrollments()`).
	 */
	public static function handle(): void {
		if ( ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			self::fail( 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified explicitly above via check_ajax_referer(); this only reads the target ID.
		$enrollment_id = isset( $_GET['enrollment_id'] ) ? absint( $_GET['enrollment_id'] ) : 0;

		if ( 0 === $enrollment_id ) {
			self::fail( 400 );
		}

		$iban = Settings::iban();

		if ( '' === $iban ) {
			self::fail( 404 );
		}

		$enrollment = ( new Enrollment_Repository() )->find_for_user( $enrollment_id, get_current_user_id() );

		if ( null === $enrollment || Enrollment_Service::STATUS_CONFIRMED !== (string) $enrollment['status'] ) {
			// Not this customer's enrollment, doesn't exist, or already
			// paid/cancelled — the QR feature is confirmed-only either way
			// (spec acceptance criterion: "hides it for paid").
			self::fail( 404 );
		}

		$term = ( new Course_Term_Repository() )->find( (int) $enrollment['term_id'] );
		$lang = Enrollment_Email_Data::user_lang( get_current_user_id() );

		$spayd = Spayd_Builder::build(
			$iban,
			(string) $enrollment['price'],
			(string) $enrollment['variable_symbol'],
			Enrollment_Email_Data::course_title( $term, $lang )
		);

		$png = ( new Qr_Code_Generator() )->png( $spayd );

		nocache_headers();
		header( 'Content-Type: image/png' );
		header( 'Content-Length: ' . strlen( $png ) );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary PNG image data, not HTML.
		exit;
	}

	/**
	 * Send a bare HTTP status with no body, then stop.
	 *
	 * @param int $status HTTP status code.
	 */
	private static function fail( int $status ): void {
		status_header( $status );
		exit;
	}
}
