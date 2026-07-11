<?php
/**
 * `[rd_account]` shortcode: "My enrollments" / "My schedule" / "Profile"
 * (spec F5, F6, F7).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Admin\Terms_List_Table;
use RubenDance\Lang;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Services\Account_Service;
use RubenDance\Services\Email_Change_Service;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Profile_Service;
use RubenDance\Services\Registration_Service;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Account_Page.
 *
 * Output only — every state change (profile form submission, email
 * re-verification link) already happened in `Account_Form_Handler` on
 * `template_redirect`, the same split M07/M08 use. Every row shown here
 * comes from `Account_Service`, which is only ever called with
 * `get_current_user_id()` — never an ID read from the request — so
 * tampering with any query string or hidden form field cannot surface
 * another customer's data (spec §5, ownership enforced in the repository
 * layer, not just here).
 */
class Account_Page {

	/**
	 * `Front\Pages` "which" key this shortcode's page is registered under. A
	 * plain string (not a `Pages::` constant), matching `Catalog_Page::PAGE_KEY`/
	 * `Enroll_Page::PAGE_KEY`'s documented reasoning.
	 *
	 * @var string
	 */
	const PAGE_KEY = 'account';

	const TAB_ENROLLMENTS = 'enrollments';
	const TAB_SCHEDULE    = 'schedule';
	const TAB_PROFILE     = 'profile';

	/**
	 * Every valid `?rd_tab=` value, in display order.
	 *
	 * @var string[]
	 */
	const TABS = array( self::TAB_ENROLLMENTS, self::TAB_SCHEDULE, self::TAB_PROFILE );

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_styles' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_redirect_anonymous' ) );
		add_filter( 'ruben_dance_subscriber_redirect_url', array( self::class, 'redirect_url' ) );
	}

	/**
	 * Register the `[rd_account]` shortcode.
	 */
	public static function register_shortcode(): void {
		add_shortcode( 'rd_account', array( self::class, 'render' ) );
	}

	/**
	 * Front-end stylesheet for the account tabs/tables/forms. Small enough
	 * to load unconditionally, the same reasoning `Catalog_Page::enqueue_styles()`
	 * uses.
	 */
	public static function enqueue_styles(): void {
		wp_enqueue_style(
			'rd-front-account',
			plugins_url( 'public/assets/front-account.css', RUBEN_DANCE_PLUGIN_FILE ),
			array(),
			RUBEN_DANCE_VERSION
		);
	}

	/**
	 * Where `Access_Restrictions::redirect_customers_from_wp_admin()` sends a
	 * customer instead of `wp-admin`, now that `[rd_account]` exists (spec
	 * M09 task: "Hook the `ruben_dance_subscriber_redirect_url` filter ...
	 * to point at the account page").
	 *
	 * @param string $default_url Unused fallback from `Access_Restrictions`.
	 * @return string
	 */
	public static function redirect_url( string $default_url ): string {
		unset( $default_url );

		return Pages::url( self::PAGE_KEY, Lang::create_default()->current() );
	}

	/**
	 * Redirect an anonymous visitor straight to the login page (spec:
	 * "Anonymous access to the page → login form"), preserving the account
	 * page itself as `redirect_to` so a successful login lands them right
	 * back here. Unlike `[rd_catalog]`/`[rd_enroll]`, there is no public
	 * content to show on this page at all, so this runs up front on
	 * `template_redirect` rather than as inline markup in `render()`.
	 *
	 * Skips an email-change confirmation link (`?rd_account_email_verify=1`)
	 * outright: clicking it from a different browser/device than the one
	 * currently logged in is the expected case (mirrors
	 * `Registration_Service::verify()`'s "the token itself is the
	 * authorization" reasoning), and `Account_Form_Handler::handle_request()`
	 * — hooked on this same `template_redirect` action — already redirects
	 * this request onward itself once the token is processed; without this
	 * guard that second redirect would never run.
	 */
	public static function maybe_redirect_anonymous(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, no state change; Account_Form_Handler::handle_email_verify() re-validates the token itself before acting.
		if ( isset( $_GET['rd_account_email_verify'] ) && '1' === $_GET['rd_account_email_verify'] ) {
			return;
		}

		if ( is_user_logged_in() || is_admin() ) {
			return;
		}

		$post = get_post();

		if ( null === $post || ! is_singular( 'page' ) || ! has_shortcode( (string) $post->post_content, 'rd_account' ) ) {
			return;
		}

		$lang = Lang::create_default()->current();

		wp_safe_redirect(
			add_query_arg( 'redirect_to', rawurlencode( (string) get_permalink( $post ) ), Pages::url( Pages::LOGIN, $lang ) )
		);
		exit;
	}

	/**
	 * `[rd_account]`.
	 *
	 * @return string
	 */
	public static function render(): string {
		if ( ! is_user_logged_in() ) {
			// Defensive fallback: maybe_redirect_anonymous() already handles
			// the normal case (the shortcode embedded on its own seeded
			// page); this only guards an unusual embed (e.g. the shortcode
			// pasted into a different page) from ever leaking account markup.
			return '<p>' . esc_html__( 'Please log in to view your account.', 'ruben-dance' ) . '</p>';
		}

		$lang_helper = Lang::create_default();
		$lang        = $lang_helper->current();
		$user        = wp_get_current_user();
		$user_id     = $user->ID;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which tab to show, no state change; every write is gated by Account_Form_Handler's own nonce checks.
		$tab = isset( $_GET['rd_tab'] ) ? sanitize_key( wp_unslash( $_GET['rd_tab'] ) ) : self::TAB_ENROLLMENTS;
		$tab = in_array( $tab, self::TABS, true ) ? $tab : self::TAB_ENROLLMENTS;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which notice to show after the email-confirmation-link redirect, no state change (Account_Form_Handler::handle_email_verify() already verified the token before redirecting here).
		$email_notice = isset( $_GET['rd_email_notice'] ) ? sanitize_key( wp_unslash( $_GET['rd_email_notice'] ) ) : '';

		$tab_urls = array();

		foreach ( self::TABS as $t ) {
			$tab_urls[ $t ] = add_query_arg( 'rd_tab', $t, Pages::url( self::PAGE_KEY, $lang ) );
		}

		$service = Account_Service::create_default();

		return self::render_template(
			'account',
			array(
				'tab'          => $tab,
				'tab_urls'     => $tab_urls,
				'lang'         => $lang,
				'email_notice' => $email_notice,
				'enrollments'  => self::TAB_ENROLLMENTS === $tab ? self::display_enrollments( $service->enrollments_for( $user_id ), $lang_helper, $lang ) : array(),
				'schedule'     => self::TAB_SCHEDULE === $tab ? self::display_schedule( $service->schedule_for( $user_id, current_time( 'Y-m-d' ) ), $lang_helper, $lang ) : array(),
				'profile'      => self::profile_data( $user ),
				'bank_account' => Settings::bank_account(),
			)
		);
	}

	/**
	 * Build the "My enrollments" tab's display rows (spec F5) from raw
	 * `wp_rd_enrollment` rows, resolving each one's term/course/location.
	 *
	 * @param array<int, array<string, mixed>> $rows        Enrollment rows, already ownership-filtered by `Account_Service`.
	 * @param Lang                             $lang_helper Language helper.
	 * @param string                           $lang        Current language.
	 * @return array<int, array<string, mixed>>
	 */
	private static function display_enrollments( array $rows, Lang $lang_helper, string $lang ): array {
		$term_repository     = new Course_Term_Repository();
		$location_repository = new Location_Repository();
		$terms_cache         = array();
		$locations_cache     = array();
		$display             = array();

		foreach ( $rows as $row ) {
			$term_id = (int) $row['term_id'];

			if ( ! array_key_exists( $term_id, $terms_cache ) ) {
				$terms_cache[ $term_id ] = $term_repository->find( $term_id );
			}

			$term = $terms_cache[ $term_id ];

			if ( null === $term ) {
				continue; // Defensive: no hard FK from enrollment to term (see Schema).
			}

			$location_id = (int) $term['location_id'];

			if ( ! array_key_exists( $location_id, $locations_cache ) ) {
				$locations_cache[ $location_id ] = $location_repository->find( $location_id );
			}

			$location = $locations_cache[ $location_id ];

			$display[] = array(
				'id'               => (int) $row['id'],
				'course_title'     => get_the_title( $lang_helper->resolve_post( (int) $term['course_id'], $lang ) ),
				'season'           => Lang::EN === $lang && '' !== trim( (string) $term['season_label_en'] ) ? (string) $term['season_label_en'] : (string) $term['season_label_cs'],
				'weekday'          => Terms_List_Table::weekday_labels()[ (int) $term['weekday'] ] ?? '',
				'time'             => Terms_List_Table::format_time( (string) $term['start_time'] ) . '–' . Terms_List_Table::format_time( (string) $term['end_time'] ),
				'location'         => null === $location ? '' : (string) $location['name'],
				'participant_name' => trim( (string) $row['participant_name'] ),
				'price'            => (string) $row['price'],
				'discount_note'    => (string) ( $row['discount_note'] ?? '' ),
				'status'           => (string) $row['status'],
				'over_capacity'    => ! empty( $row['over_capacity'] ),
				'due_date'         => (string) $row['due_date'],
				'variable_symbol'  => (string) $row['variable_symbol'],
				'qr_url'           => self::qr_url( $row ),
			);
		}

		return $display;
	}

	/**
	 * The QR-payment-code `<img>` URL for one "My enrollments" row (spec
	 * F16), or `''` when the feature doesn't apply — either the enrollment
	 * isn't currently unpaid (spec acceptance criterion: "hides it for
	 * paid") or no IBAN is configured (spec acceptance criterion: "No IBAN
	 * configured → no QR anywhere"). Computed here, once, rather than in the
	 * template, so `account-enrollments.php` only ever has to check "is this
	 * blank" — it never re-derives the same two conditions
	 * `Front\Qr_Code_Ajax::handle()` independently re-checks server-side
	 * before actually rendering the image.
	 *
	 * @param array<string, mixed> $row Raw `wp_rd_enrollment` row.
	 * @return string
	 */
	private static function qr_url( array $row ): string {
		if ( Enrollment_Service::STATUS_CONFIRMED !== (string) $row['status'] || '' === Settings::iban() ) {
			return '';
		}

		return Qr_Code_Ajax::url( (int) $row['id'] );
	}

	/**
	 * Build the "My schedule" tab's display rows (spec F6) from raw
	 * `wp_rd_lesson` rows, resolving each one's term/course/location.
	 *
	 * @param array<int, array<string, mixed>> $lessons     Lesson rows, already ownership-filtered by `Account_Service`.
	 * @param Lang                             $lang_helper Language helper.
	 * @param string                           $lang        Current language.
	 * @return array<int, array<string, mixed>>
	 */
	private static function display_schedule( array $lessons, Lang $lang_helper, string $lang ): array {
		if ( array() === $lessons ) {
			return array();
		}

		$term_ids = array_values( array_unique( array_map( static fn( array $l ): int => (int) $l['term_id'], $lessons ) ) );

		$term_repository     = ( new Course_Term_Repository() )->find_many( $term_ids );
		$location_repository = new Location_Repository();
		$locations_cache     = array();
		$display             = array();

		foreach ( $lessons as $lesson ) {
			$term_id = (int) $lesson['term_id'];
			$term    = $term_repository[ $term_id ] ?? null;

			if ( null === $term ) {
				continue; // Defensive: no hard FK from lesson to term (see Schema).
			}

			$location_id = (int) $term['location_id'];

			if ( ! array_key_exists( $location_id, $locations_cache ) ) {
				$locations_cache[ $location_id ] = $location_repository->find( $location_id );
			}

			$location = $locations_cache[ $location_id ];

			$display[] = array(
				'date'         => (string) $lesson['lesson_date'],
				'time'         => Terms_List_Table::format_time( (string) $lesson['start_time'] ) . '–' . Terms_List_Table::format_time( (string) $lesson['end_time'] ),
				'course_title' => get_the_title( $lang_helper->resolve_post( (int) $term['course_id'], $lang ) ),
				'location'     => null === $location ? '' : (string) $location['name'],
				'status'       => (string) $lesson['status'],
				'note'         => (string) ( $lesson['note'] ?? '' ),
			);
		}

		return $display;
	}

	/**
	 * Current profile field values (spec F7), for pre-filling the form and
	 * showing the "pending email change" notice.
	 *
	 * @param \WP_User $user Current user.
	 * @return array<string, mixed>
	 */
	private static function profile_data( \WP_User $user ): array {
		$locale = (string) get_user_meta( $user->ID, Registration_Service::META_LOCALE, true );

		return array(
			'first_name'        => (string) $user->first_name,
			'last_name'         => (string) $user->last_name,
			'email'             => (string) $user->user_email,
			'phone'             => (string) get_user_meta( $user->ID, Registration_Service::META_PHONE, true ),
			'locale'            => '' === $locale ? Lang::DEFAULT_LANGUAGE : $locale,
			'marketing_consent' => '1' === get_user_meta( $user->ID, Registration_Service::META_MARKETING_CONSENT, true ),
			'pending_email'     => Email_Change_Service::create_default()->pending_email( $user->ID ),
		);
	}

	/**
	 * A `wp_rd_enrollment.status` value to its translated badge label (spec
	 * F5: "awaiting payment / paid / cancelled").
	 *
	 * @param string $status One of `Enrollment_Service::STATUSES`.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case Enrollment_Service::STATUS_PAID:
				return __( 'Paid', 'ruben-dance' );

			case Enrollment_Service::STATUS_CANCELLED:
				return __( 'Cancelled', 'ruben-dance' );

			case Enrollment_Service::STATUS_CONFIRMED:
			default:
				return __( 'Awaiting payment', 'ruben-dance' );
		}
	}

	/**
	 * Translate a `Profile_Service::ERROR_*`/`Email_Change_Service::ERROR_*`
	 * code into a message, for the profile-tab templates. Mirrors
	 * `Enroll_Page::error_message()`.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		switch ( $code ) {
			case Profile_Service::ERROR_FIRST_NAME_REQUIRED:
				return __( 'Please enter your first name.', 'ruben-dance' );

			case Profile_Service::ERROR_FIRST_NAME_TOO_LONG:
				return __( 'First name is too long.', 'ruben-dance' );

			case Profile_Service::ERROR_LAST_NAME_REQUIRED:
				return __( 'Please enter your last name.', 'ruben-dance' );

			case Profile_Service::ERROR_LAST_NAME_TOO_LONG:
				return __( 'Last name is too long.', 'ruben-dance' );

			case Profile_Service::ERROR_PHONE_REQUIRED:
				return __( 'Please enter your phone number.', 'ruben-dance' );

			case Profile_Service::ERROR_PHONE_INVALID:
				return __( 'Please enter a valid phone number.', 'ruben-dance' );

			case Profile_Service::ERROR_LOCALE_INVALID:
				return __( 'Please choose a valid language.', 'ruben-dance' );

			case Profile_Service::ERROR_PASSWORD_TOO_SHORT:
				return __( 'Password must be at least 8 characters long.', 'ruben-dance' );

			case Profile_Service::ERROR_PASSWORD_MISMATCH:
				return __( 'The passwords do not match.', 'ruben-dance' );

			case Email_Change_Service::ERROR_EMAIL_REQUIRED:
				return __( 'Please enter an email address.', 'ruben-dance' );

			case Email_Change_Service::ERROR_EMAIL_INVALID:
				return __( 'Please enter a valid email address.', 'ruben-dance' );

			case Email_Change_Service::ERROR_EMAIL_SAME:
				return __( 'This is already your current email address.', 'ruben-dance' );

			case Email_Change_Service::ERROR_EMAIL_TAKEN:
				return __( 'An account with this email already exists.', 'ruben-dance' );

			default:
				return __( 'Please check the highlighted fields.', 'ruben-dance' );
		}
	}

	/**
	 * Include a template partial with `$vars` extracted as local variables.
	 * Mirrors `Catalog_Page::render_template()` — duplicated rather than
	 * shared to keep this class independent of other milestones' files.
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
