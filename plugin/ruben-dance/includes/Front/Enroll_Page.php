<?php
/**
 * `[rd_enroll]` shortcode: the public enrollment form (spec F3 steps 1, 3, 4).
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Front;

use RubenDance\Admin\Terms_List_Table;
use RubenDance\Course_Fields;
use RubenDance\Lang;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Enrollment_Repository;
use RubenDance\Repositories\Location_Repository;
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
			wp_enqueue_style(
				'rd-front-enroll',
				plugins_url( 'public/assets/front-enroll.css', RUBEN_DANCE_PLUGIN_FILE ),
				array( 'rd-design' ),
				RUBEN_DANCE_VERSION
			);

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

		wp_enqueue_style(
			'rd-front-enroll',
			plugins_url( 'public/assets/front-enroll.css', RUBEN_DANCE_PLUGIN_FILE ),
			array( 'rd-design' ),
			RUBEN_DANCE_VERSION
		);

		// Both the real-enrollment and bot-guard "fake success" paths reach
		// here only after Enrollment_Form_Handler::handle_submit() already
		// required is_user_logged_in(), so a current user always exists —
		// the confirmation's "we emailed you at ..." line can therefore
		// always show an address, even for the fake-success case that
		// deliberately carries no enrollment row (spec: indistinguishable
		// from a real one to whatever submitted the form).
		$email = wp_get_current_user()->user_email;

		// Bot-guard "fake success" (Enrollment_Form_Handler) carries no
		// enrollment row — show a generic thank-you, indistinguishable from
		// a real one to whatever submitted the form.
		if ( null === $enrollment ) {
			return self::render_template(
				'enroll-confirmation',
				array(
					'enrollment'  => null,
					'email'       => $email,
					'account_url' => Pages::url( Account_Page::PAGE_KEY, $lang ),
					'catalog_url' => Pages::url( Catalog_Page::PAGE_KEY, $lang ),
				)
			);
		}

		$term         = ( new Course_Term_Repository() )->find( (int) $enrollment['term_id'] );
		$course_title = null === $term ? '' : get_the_title( $lang_helper->resolve_post( (int) $term['course_id'], $lang ) );
		$season       = null === $term ? '' : ( Lang::EN === $lang && '' !== trim( (string) $term['season_label_en'] ) ? (string) $term['season_label_en'] : (string) $term['season_label_cs'] );

		$location = null === $term ? null : ( new Location_Repository() )->find( (int) $term['location_id'] );

		$participant_name = trim( (string) $enrollment['participant_name'] );

		if ( '' === $participant_name ) {
			$participant_name = wp_get_current_user()->display_name;
		}

		$presenter = new Term_Presenter();

		return self::render_template(
			'enroll-confirmation',
			array(
				'enrollment'       => $enrollment,
				'email'            => $email,
				'course_title'     => $course_title,
				'season'           => $season,
				'weekday'          => null === $term ? '' : ( Terms_List_Table::weekday_labels()[ (int) $term['weekday'] ] ?? '' ),
				'time'             => null === $term ? '' : Terms_List_Table::format_time( (string) $term['start_time'] ),
				'location'         => null === $location ? '' : (string) $location['name'],
				'participant_name' => $participant_name,
				'base_price'       => null === $term ? '' : $presenter->format_price( (string) $term['price'] ),
				'discount_rows'    => self::discount_rows( $enrollment['discount_note'] ?? null ),
				'total_price'      => $presenter->format_price( (string) $enrollment['price'] ),
				'bank_account'     => Settings::bank_account(),
				'due_date'         => date_i18n( 'j. n. Y', (int) strtotime( (string) $enrollment['due_date'] ) ),
				'qr_url'           => self::qr_url( $enrollment ),
				'account_url'      => Pages::url( Account_Page::PAGE_KEY, $lang ),
				'catalog_url'      => Pages::url( Catalog_Page::PAGE_KEY, $lang ),
			)
		);
	}

	/**
	 * Split a persisted `discount_note` audit string (e.g. "early-bird
	 * −400, partner −240" — see `Services\Pricing_Service::compute()`) into
	 * rows for the confirmation screen's price breakdown. Parses the note
	 * itself rather than re-deriving discounts from the term's *current*
	 * configuration, since the note is the honest historical record of what
	 * applied at enrollment time (spec §3.2) — the term's early-bird
	 * deadline or discount amounts may have changed since.
	 *
	 * @param string|null $discount_note Raw `discount_note` column value.
	 * @return array<int, array{label: string, amount: string}>
	 */
	private static function discount_rows( ?string $discount_note ): array {
		$discount_note = trim( (string) $discount_note );

		if ( '' === $discount_note ) {
			return array();
		}

		$rows = array();

		foreach ( explode( ',', $discount_note ) as $ruben_dance_part ) {
			$ruben_dance_part = trim( $ruben_dance_part );

			if ( 1 === preg_match( '/^early-bird\s+(.+)$/u', $ruben_dance_part, $ruben_dance_matches ) ) {
				$rows[] = array(
					'label'  => __( '★ Early-bird', 'ruben-dance' ),
					'amount' => $ruben_dance_matches[1],
				);
			} elseif ( 1 === preg_match( '/^partner\s+(.+)$/u', $ruben_dance_part, $ruben_dance_matches ) ) {
				$rows[] = array(
					'label'  => __( 'Partner discount', 'ruben-dance' ),
					'amount' => $ruben_dance_matches[1],
				);
			}
		}

		return $rows;
	}

	/**
	 * The QR-payment-code `<img>` URL for the just-created enrollment (spec
	 * F16), or `''` when the feature doesn't apply — mirrors
	 * `Account_Page`'s own `qr_url()` (kept as a separate copy rather than a
	 * shared dependency between the two front-end classes, the same way
	 * `render_template()` below is deliberately duplicated). A freshly
	 * created enrollment is always `STATUS_CONFIRMED`
	 * (`Services\Enrollment_Service::create()`), so the status check here is
	 * belt-and-braces rather than expected to ever fail in practice.
	 *
	 * @param array<string, mixed> $enrollment Enrollment row.
	 * @return string
	 */
	private static function qr_url( array $enrollment ): string {
		if ( Enrollment_Service::STATUS_CONFIRMED !== (string) $enrollment['status'] || '' === Settings::iban() ) {
			return '';
		}

		return Qr_Code_Ajax::url( (int) $enrollment['id'] );
	}

	/**
	 * The form-field element ID a validation error's anchor link (error
	 * summary, spec F3) should point at, or the form's own ID as a fallback
	 * for account-level errors (`_form`, `term_id`, `user_id`) that have no
	 * single field to jump to.
	 *
	 * @param string $field Error array key, as produced by `Enrollment_Form_Handler`/`Services\Enrollment_Service::validate()`.
	 * @return string
	 */
	public static function error_anchor( string $field ): string {
		$map = array(
			'participant_name' => 'rd-enroll-participant-name',
			'role'             => 'rd-enroll-role',
			'partner_name'     => 'rd-enroll-partner-name',
			'tc_accepted'      => 'rd-enroll-tc',
		);

		return $map[ $field ] ?? 'rd-enroll-form';
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

		// Captured before display formatting below turns `$early_bird['price']`
		// into a thousands-separated string ("2 400") that `parseFloat()`
		// (enroll-price.js) can no longer read correctly.
		$early_bird_raw_price = null !== $early_bird ? $early_bird['price'] : '';

		if ( null !== $early_bird ) {
			// Display-only formatting (design #3e/#4e: "2 400 Kč" + strike +
			// deadline date) — the raw values `Term_Presenter::early_bird()`
			// returns are what `Pricing_Service::compute()` still recomputes
			// from at submit time, untouched by this. Mirrors
			// `Front\Catalog_Page`/`Front\Course_Content`'s identical
			// formatting of the same array shape.
			$early_bird['price'] = $presenter->format_price( $early_bird['price'] );
			$early_bird['until'] = date_i18n( 'j. n. Y', (int) strtotime( $early_bird['until'] ) );
		}

		$roles_relevant = Course_Fields::is_roles_relevant( (int) $term['course_id'] );

		$user                      = wp_get_current_user();
		$already_marketing_consent = '1' === get_user_meta( $user->ID, Registration_Service::META_MARKETING_CONSENT, true );

		$errors    = null !== $result && in_array( $result['state'], array( 'form', 'duplicate' ), true ) ? $result['errors'] : array();
		$submitted = null !== $result && 'form' === $result['state'] ? $result['submitted'] : array();

		$notice = null !== $result ? (string) $result['state'] : '';

		$location = ( new Location_Repository() )->find( (int) $term['location_id'] );

		$course_id = (int) $term['course_id'];

		$discount_early_amount = null !== $early_bird ? $presenter->format_price( (string) $term['discount_early'] ) : '';
		$discount_pair_amount  = null !== $term['discount_pair'] && '' !== (string) $term['discount_pair'] ? $presenter->format_price( (string) $term['discount_pair'] ) : '';

		wp_enqueue_style(
			'rd-front-enroll',
			plugins_url( 'public/assets/front-enroll.css', RUBEN_DANCE_PLUGIN_FILE ),
			array( 'rd-design' ),
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
				'earlyBirdPrice' => $early_bird_raw_price,
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
				'course_title'              => get_the_title( $lang_helper->resolve_post( $course_id, $lang ) ),
				'course_url'                => (string) get_permalink( $lang_helper->resolve_post( $course_id, $lang ) ),
				'season'                    => Lang::EN === $lang && '' !== trim( (string) $term['season_label_en'] ) ? (string) $term['season_label_en'] : (string) $term['season_label_cs'],
				'weekday'                   => Terms_List_Table::weekday_labels()[ (int) $term['weekday'] ] ?? '',
				'time'                      => Terms_List_Table::format_time( (string) $term['start_time'] ) . '–' . Terms_List_Table::format_time( (string) $term['end_time'] ),
				'location'                  => null === $location ? '' : (string) $location['name'],
				'formatted_price'           => $presenter->format_price( (string) $term['price'] ),
				'is_workshop'               => 'workshop' === (string) $term['type'],
				'is_full'                   => $is_full,
				'early_bird'                => $early_bird,
				'discount_early_amount'     => $discount_early_amount,
				'discount_pair_amount'      => $discount_pair_amount,
				'roles_relevant'            => $roles_relevant,
				'roles'                     => Enrollment_Service::ROLES,
				'already_marketing_consent' => $already_marketing_consent,
				'errors'                    => $errors,
				'submitted'                 => $submitted,
				'notice'                    => $notice,
				// M15/§6.1+§6.3: see `Front\Shortcodes::render_register()` for
				// why these come from `Pages::url()` rather than
				// `get_privacy_policy_url()`.
				'privacy_policy_url'        => Pages::url( Pages::PRIVACY_POLICY, $lang ),
				'terms_url'                 => Pages::url( Pages::TERMS, $lang ),
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
