<?php
/**
 * `[rd_enroll]` shortcode: the public enrollment form (spec F3 steps 1, 3, 4).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Course_Fields;
use RubenDance\Lang;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Registration_Service;
use RubenDance\Services\Term_Presenter;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Enroll_Page.
 *
 * Output only — state changes already happened in `Enrollment_Form_Handler`
 * on `template_redirect` (mirrors M07's `Form_Handler`/`Shortcodes` split).
 * Every branch here re-derives the term's *current* status/capacity/course
 * from the database rather than trusting anything client-supplied — the same
 * "never trust the form" rule the handler follows for price — so a stale
 * bookmark or a crafted `term_id` never shows an enroll form for a
 * closed/draft/cancelled term (spec acceptance criterion: "direct URL access
 * refused").
 */
class Enroll_Page {

	/**
	 * `Front\Pages` "which" key this shortcode's page is registered under.
	 * See `Catalog_Page::PAGE_KEY` for why this is a plain string rather than
	 * a change to M07's `Pages` class.
	 *
	 * @var string
	 */
	const PAGE_KEY = 'enroll';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_shortcode' ) );
	}

	/**
	 * Register the `[rd_enroll]` shortcode.
	 */
	public static function register_shortcode(): void {
		add_shortcode( 'rd_enroll', array( self::class, 'render' ) );
	}

	/**
	 * `[rd_enroll]`.
	 *
	 * @return string
	 */
	public static function render(): string {
		$lang_helper = Lang::create_default();
		$lang        = $lang_helper->current();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects which term the page is about, no state change; every write is gated by Enrollment_Form_Handler's own nonce check.
		$term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;

		$result = Enrollment_Form_Handler::$result;

		if ( null !== $result && 'success' === $result['state'] ) {
			return self::render_success( $result, $lang_helper, $lang );
		}

		if ( $term_id <= 0 ) {
			return self::render_template( 'enroll-not-found', array() );
		}

		$term = ( new Course_Term_Repository() )->find( $term_id );

		if ( null === $term ) {
			return self::render_template( 'enroll-not-found', array() );
		}

		if ( 'open' !== (string) $term['status'] ) {
			return self::render_template(
				'enroll-closed',
				array(
					'course_title' => get_the_title( $lang_helper->resolve_post( (int) $term['course_id'], $lang ) ),
				)
			);
		}

		$enroll_url = add_query_arg( 'term_id', $term_id, self::page_url( $lang ) );

		if ( ! is_user_logged_in() ) {
			return self::render_template(
				'enroll-login-required',
				array(
					'login_url'    => add_query_arg( 'redirect_to', rawurlencode( $enroll_url ), Pages::url( Pages::LOGIN, $lang ) ),
					'register_url' => Pages::url( Pages::REGISTER, $lang ),
				)
			);
		}

		return self::render_form( $term, $term_id, $lang_helper, $lang, $result );
	}

	/**
	 * Render the post-submit confirmation screen (spec F3 step 4).
	 *
	 * @param array{state: string, errors: array<string,string>, submitted: array<string,mixed>, enrollment?: array<string,mixed>} $result Handler result.
	 * @param Lang                                                                                                                 $lang_helper Language helper.
	 * @param string                                                                                                               $lang     Current language.
	 * @return string
	 */
	private static function render_success( array $result, Lang $lang_helper, string $lang ): string {
		$enrollment = $result['enrollment'] ?? null;

		// Bot-guard "fake success" (Enrollment_Form_Handler) carries no
		// enrollment row — show a generic thank-you, indistinguishable from
		// a real one to whatever submitted the form.
		if ( null === $enrollment ) {
			return self::render_template( 'enroll-confirmation', array( 'enrollment' => null ) );
		}

		$term         = ( new Course_Term_Repository() )->find( (int) $enrollment['term_id'] );
		$course_title = null === $term ? '' : get_the_title( $lang_helper->resolve_post( (int) $term['course_id'], $lang ) );
		$season       = null === $term ? '' : ( Lang::EN === $lang && '' !== trim( (string) $term['season_label_en'] ) ? (string) $term['season_label_en'] : (string) $term['season_label_cs'] );

		return self::render_template(
			'enroll-confirmation',
			array(
				'enrollment'   => $enrollment,
				'course_title' => $course_title,
				'season'       => $season,
				'bank_account' => Settings::bank_account(),
			)
		);
	}

	/**
	 * Render the enrollment form itself (spec F3 step 3).
	 *
	 * @param array<string, mixed>                                                                    $term        Term row.
	 * @param int                                                                                     $term_id     Term ID.
	 * @param Lang                                                                                    $lang_helper Language helper.
	 * @param string                                                                                  $lang        Current language.
	 * @param array{state: string, errors: array<string,string>, submitted: array<string,mixed>}|null $result Handler result, if this render follows a failed submission.
	 * @return string
	 */
	private static function render_form( array $term, int $term_id, Lang $lang_helper, string $lang, ?array $result ): string {
		$presenter    = new Term_Presenter();
		$active_count = ( new Enrollment_Repository() )->count_active_for_term( $term_id );
		$is_full      = $presenter->is_full( $term, $active_count );
		$today        = current_time( 'Y-m-d' );
		$early_bird   = $presenter->early_bird( $term, $today );

		$roles_relevant = Course_Fields::is_roles_relevant( (int) $term['course_id'] );

		$user                      = wp_get_current_user();
		$already_marketing_consent = '1' === get_user_meta( $user->ID, Registration_Service::META_MARKETING_CONSENT, true );

		$errors    = null !== $result && in_array( $result['state'], array( 'form', 'duplicate' ), true ) ? $result['errors'] : array();
		$submitted = null !== $result && 'form' === $result['state'] ? $result['submitted'] : array();

		$notice = null !== $result ? (string) $result['state'] : '';

		wp_enqueue_style(
			'rd-front-catalog',
			plugins_url( 'public/assets/front-catalog.css', RUBEN_DANCE_PLUGIN_FILE ),
			array(),
			RUBEN_DANCE_VERSION
		);

		wp_enqueue_script(
			'rd-enroll-price',
			plugins_url( 'public/assets/enroll-price.js', RUBEN_DANCE_PLUGIN_FILE ),
			array(),
			RUBEN_DANCE_VERSION,
			true
		);

		wp_localize_script(
			'rd-enroll-price',
			'rdEnrollPriceL10n',
			array(
				'price'          => (string) $term['price'],
				'earlyBirdPrice' => null === $early_bird ? '' : $early_bird['price'],
				'pairDiscount'   => null === $term['discount_pair'] ? '' : (string) $term['discount_pair'],
				'currency'       => Lang::EN === $lang ? 'CZK' : 'Kč',
			)
		);

		return self::render_template(
			'enroll-form',
			array(
				'term'                      => $term,
				'term_id'                   => $term_id,
				'lang'                      => $lang,
				'course_title'              => get_the_title( $lang_helper->resolve_post( (int) $term['course_id'], $lang ) ),
				'season'                    => Lang::EN === $lang && '' !== trim( (string) $term['season_label_en'] ) ? (string) $term['season_label_en'] : (string) $term['season_label_cs'],
				'is_full'                   => $is_full,
				'early_bird'                => $early_bird,
				'roles_relevant'            => $roles_relevant,
				'roles'                     => Enrollment_Service::ROLES,
				'already_marketing_consent' => $already_marketing_consent,
				'errors'                    => $errors,
				'submitted'                 => $submitted,
				'notice'                    => $notice,
				'privacy_policy_url'        => function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '',
				'currency'                  => Lang::EN === $lang ? 'CZK' : 'Kč',
			)
		);
	}

	/**
	 * Translate a validation error code (from `Enrollment_Service::validate()`
	 * or this class's own `Enrollment_Form_Handler::ERROR_*`) into a message.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		switch ( $code ) {
			case Enrollment_Form_Handler::ERROR_TC_REQUIRED:
				return __( 'You must agree to the Terms & Conditions to enroll.', 'ruben-dance' );

			case Enrollment_Form_Handler::ERROR_PARTICIPANT_NAME_REQUIRED:
				return __( 'Please enter the participant\'s name.', 'ruben-dance' );

			case Enrollment_Service::ERROR_TERM_NOT_OPEN:
				return __( 'This term is no longer open for enrollment.', 'ruben-dance' );

			case Enrollment_Service::ERROR_TERM_NOT_FOUND:
				return __( 'This term could not be found.', 'ruben-dance' );

			case Enrollment_Service::ERROR_PARTICIPANT_TOO_LONG:
				return __( 'Participant name is too long.', 'ruben-dance' );

			case Enrollment_Service::ERROR_ROLE_INVALID:
				return __( 'Please choose a valid role.', 'ruben-dance' );

			case Enrollment_Service::ERROR_PARTNER_NAME_TOO_LONG:
				return __( 'Partner name is too long.', 'ruben-dance' );

			default:
				return __( 'Please check the highlighted fields.', 'ruben-dance' );
		}
	}

	/**
	 * Permalink for the `[rd_enroll]` page itself, in the given language.
	 *
	 * @param string $lang Language slug.
	 * @return string
	 */
	public static function page_url( string $lang ): string {
		return Pages::url( self::PAGE_KEY, $lang );
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
