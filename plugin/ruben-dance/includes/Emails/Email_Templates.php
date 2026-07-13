<?php
/**
 * Per-type, per-language (CS/EN) email subject/body templates (spec F14),
 * stored as a single `wp_options` row with built-in defaults.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Emails;

use RubenDance\Lang;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Email_Templates.
 *
 * One option (`self::OPTION`) holds every type/language pair as a nested
 * array, the same "single option, nested shape" choice `Settings` makes for
 * its own handful of fields — seven types times two languages is small
 * enough that a dedicated row per template would be overkill. `get()` falls
 * back to `default_template()` whenever the stored subject/body is blank, so
 * a site that never visits the settings screen still sends fully-formed
 * emails from day one (spec M13 task: "stored as options with defaults").
 *
 * Every default body is semantic HTML (`<p>` paragraphs, `<a>` links) with
 * the minimum inline `style="…"` needed for the E2/E7 payment block and E1's
 * button to render correctly in an email client (design/screens.html #3j —
 * see `Emails\Email_Layout`, which wraps the surrounding chrome, for why
 * inline styles rather than a stylesheet/CSS classes). `Services\Html_Mailer`
 * sends `text/html`, and `Placeholder_Renderer::render()` HTML-escapes every
 * substituted value, so the templates themselves only need to supply the
 * surrounding markup.
 */
class Email_Templates {

	const OPTION = 'rd_email_templates';

	const TYPE_E1 = 'E1'; // Account registration verification (customer).
	const TYPE_E2 = 'E2'; // Enrollment created: summary + payment instructions (customer).
	const TYPE_E3 = 'E3'; // Enrollment created: admin notification (always CS).
	const TYPE_E4 = 'E4'; // Marked as paid: payment confirmation (customer).
	const TYPE_E5 = 'E5'; // Lesson cancelled/moved (customer, admin-confirmed send).
	const TYPE_E6 = 'E6'; // Enrollment cancelled (customer).
	const TYPE_E7 = 'E7'; // Payment reminder (customer).

	/**
	 * Voucher inquiry: admin notification (always CS, mirrors E3). Not part
	 * of the spec's F14/E1-E7 table — added by M16/F17 for the new
	 * `[rd_voucher_inquiry]` form, the same "goes through Email_Sender with a
	 * new type code" extension the milestone calls for, so this send is
	 * logged and admin-editable exactly like every other outgoing email.
	 *
	 * @var string
	 */
	const TYPE_E8 = 'E8'; // Voucher inquiry: admin notification.

	/**
	 * Every template type, in spec table order.
	 *
	 * @var string[]
	 */
	const TYPES = array( self::TYPE_E1, self::TYPE_E2, self::TYPE_E3, self::TYPE_E4, self::TYPE_E5, self::TYPE_E6, self::TYPE_E7, self::TYPE_E8 );

	/**
	 * Every language a template set exists for (spec §5 Multilingual: "one
	 * template set per language (CS + EN)").
	 *
	 * @var string[]
	 */
	const LANGUAGES = array( Lang::CS, Lang::EN );

	/**
	 * The subject/body pair for one type/language, falling back to the
	 * built-in default when unset or blank.
	 *
	 * @param string $type One of `self::TYPES`.
	 * @param string $lang One of `self::LANGUAGES`.
	 * @return array{subject: string, body: string}
	 */
	public static function get( string $type, string $lang ): array {
		$type = in_array( $type, self::TYPES, true ) ? $type : self::TYPE_E1;
		$lang = in_array( $lang, self::LANGUAGES, true ) ? $lang : Lang::DEFAULT_LANGUAGE;

		$stored  = get_option( self::OPTION, array() );
		$subject = $stored[ $type ][ $lang ]['subject'] ?? '';
		$body    = $stored[ $type ][ $lang ]['body'] ?? '';

		$default = self::default_template( $type, $lang );

		return array(
			'subject' => '' !== trim( (string) $subject ) ? (string) $subject : $default['subject'],
			'body'    => '' !== trim( (string) $body ) ? (string) $body : $default['body'],
		);
	}

	/**
	 * Render one type/language template with a set of placeholder values in
	 * one step — `Emails\Email_Sender` and every WordPress-agnostic service
	 * that composes its own email (`Services\Registration_Service`) share
	 * this rather than each calling `get()` + `Placeholder_Renderer::render()`
	 * separately.
	 *
	 * @param string                     $type   One of `self::TYPES`.
	 * @param string                     $lang   One of `self::LANGUAGES`.
	 * @param array<string, string|null> $values Placeholder token => value.
	 * @return array{subject: string, body: string}
	 */
	public static function compose( string $type, string $lang, array $values ): array {
		$template = self::get( $type, $lang );

		return array(
			'subject' => Placeholder_Renderer::render( $template['subject'], $values, false ),
			'body'    => Placeholder_Renderer::render( $template['body'], $values, true ),
		);
	}

	/**
	 * Save one type/language template. No validation beyond "non-blank" is
	 * performed here — an admin who saves a broken/empty template only ever
	 * hurts themselves, and `get()` already guards the read side by falling
	 * back to the default when blank.
	 *
	 * @param string $type    One of `self::TYPES`.
	 * @param string $lang    One of `self::LANGUAGES`.
	 * @param string $subject New subject line (may contain placeholders).
	 * @param string $body    New body (may contain placeholders).
	 */
	public static function save( string $type, string $lang, string $subject, string $body ): void {
		if ( ! in_array( $type, self::TYPES, true ) || ! in_array( $lang, self::LANGUAGES, true ) ) {
			return;
		}

		$stored                              = get_option( self::OPTION, array() );
		$stored[ $type ][ $lang ]['subject'] = trim( $subject );
		$stored[ $type ][ $lang ]['body']    = trim( $body );

		update_option( self::OPTION, $stored );
	}

	/**
	 * Human labels for every type, for the settings screen's type picker and
	 * the email-log screen's type column.
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return array(
			self::TYPE_E1 => __( 'E1 — Account verification', 'ruben-dance' ),
			self::TYPE_E2 => __( 'E2 — Enrollment confirmation', 'ruben-dance' ),
			self::TYPE_E3 => __( 'E3 — Admin notification', 'ruben-dance' ),
			self::TYPE_E4 => __( 'E4 — Payment confirmation', 'ruben-dance' ),
			self::TYPE_E5 => __( 'E5 — Lesson cancelled/moved', 'ruben-dance' ),
			self::TYPE_E6 => __( 'E6 — Enrollment cancelled', 'ruben-dance' ),
			self::TYPE_E7 => __( 'E7 — Payment reminder', 'ruben-dance' ),
			self::TYPE_E8 => __( 'E8 — Voucher inquiry (admin notification)', 'ruben-dance' ),
		);
	}

	/**
	 * The placeholder tokens relevant to one template type, for the settings
	 * screen's legend. Rendering itself (`Placeholder_Renderer::render()`)
	 * doesn't enforce this list — it just replaces whatever `{token}`s
	 * actually appear in the template text — this is documentation only, so
	 * an admin editing a template knows what's available.
	 *
	 * @param string $type One of `self::TYPES`.
	 * @return array<string, string> Token (without braces) => description.
	 */
	public static function placeholders_for( string $type ): array {
		$common = array(
			'first_name' => __( "Recipient's first name", 'ruben-dance' ),
			'course'     => __( 'Course name', 'ruben-dance' ),
		);

		switch ( $type ) {
			case self::TYPE_E1:
				return array(
					'first_name' => $common['first_name'],
					'link'       => __( 'Verification link', 'ruben-dance' ),
				);

			case self::TYPE_E2:
				return $common + array(
					'participant'     => __( 'Participant name', 'ruben-dance' ),
					'term_schedule'   => __( 'Term season/weekday/time', 'ruben-dance' ),
					'price'           => __( 'Price (incl. currency)', 'ruben-dance' ),
					'account_number'  => __( 'Bank account number', 'ruben-dance' ),
					'variable_symbol' => __( 'Variable symbol', 'ruben-dance' ),
					'due_date'        => __( 'Payment due date', 'ruben-dance' ),
					'terms_url'       => __( 'Terms & Conditions link (spec §6.3)', 'ruben-dance' ),
				);

			case self::TYPE_E3:
				return $common + array(
					'participant'     => __( 'Participant name', 'ruben-dance' ),
					'term_schedule'   => __( 'Term season/weekday/time', 'ruben-dance' ),
					'price'           => __( 'Price (incl. currency)', 'ruben-dance' ),
					'account_number'  => __( 'Bank account number', 'ruben-dance' ),
					'variable_symbol' => __( 'Variable symbol', 'ruben-dance' ),
					'due_date'        => __( 'Payment due date', 'ruben-dance' ),
				);

			case self::TYPE_E4:
				return $common + array(
					'price'           => __( 'Price (incl. currency)', 'ruben-dance' ),
					'variable_symbol' => __( 'Variable symbol', 'ruben-dance' ),
				);

			case self::TYPE_E5:
				return array(
					'first_name'  => $common['first_name'],
					'course'      => $common['course'],
					'lesson_date' => __( 'Affected lesson date', 'ruben-dance' ),
					'status'      => __( 'Cancelled/moved', 'ruben-dance' ),
					'note'        => __( "Admin's note about the change", 'ruben-dance' ),
				);

			case self::TYPE_E6:
				return $common + array(
					'term_schedule' => __( 'Term season/weekday/time', 'ruben-dance' ),
				);

			case self::TYPE_E7:
				return $common + array(
					'price'           => __( 'Price (incl. currency)', 'ruben-dance' ),
					'account_number'  => __( 'Bank account number', 'ruben-dance' ),
					'variable_symbol' => __( 'Variable symbol', 'ruben-dance' ),
					'due_date'        => __( 'Payment due date', 'ruben-dance' ),
				);

			case self::TYPE_E8:
				return array(
					'name'    => __( "Inquirer's name", 'ruben-dance' ),
					'email'   => __( "Inquirer's email address", 'ruben-dance' ),
					'message' => __( 'Inquiry message', 'ruben-dance' ),
				);

			default:
				return $common;
		}
	}

	/**
	 * Built-in CS/EN default subject/body for one type. These are plain
	 * string literals per language (not routed through WordPress i18n's
	 * `__()`), the same reasoning `Services\Registration_Service`'s original
	 * M07 templates already followed: real `.po` translation lands in M16,
	 * so a customer-facing string still needs its Czech text to actually be
	 * Czech today, regardless of which locale is currently loaded.
	 *
	 * @param string $type One of `self::TYPES`.
	 * @param string $lang One of `self::LANGUAGES`.
	 * @return array{subject: string, body: string}
	 */
	public static function default_template( string $type, string $lang ): array {
		$is_en = Lang::EN === $lang;

		switch ( $type ) {
			case self::TYPE_E1:
				return $is_en
					? array(
						'subject' => 'Please verify your Ruben Dance account',
						'body'    => '<p style="margin:0 0 14px">Hi {first_name},</p><p style="margin:0 0 16px">Thanks for registering with Ruben Dance. Please confirm your email address:</p><a href="{link}" style="display:block;background:#E8604C;color:#ffffff;font-weight:700;font-size:14px;padding:14px 0;border-radius:99px;text-align:center;text-decoration:none">Verify account</a><p style="margin:16px 0 0">The link is valid for 48 hours and can only be used once. If you did not create this account, you can safely ignore this email.</p>',
					)
					: array(
						'subject' => 'Potvrďte prosím svůj účet Ruben Dance',
						'body'    => '<p style="margin:0 0 14px">Ahoj {first_name},</p><p style="margin:0 0 16px">Děkujeme za registraci na Ruben Dance. Potvrďte prosím svou emailovou adresu:</p><a href="{link}" style="display:block;background:#E8604C;color:#ffffff;font-weight:700;font-size:14px;padding:14px 0;border-radius:99px;text-align:center;text-decoration:none">Potvrdit účet</a><p style="margin:16px 0 0">Odkaz je platný 48 hodin a lze jej použít pouze jednou. Pokud jste si tento účet nevytvořili vy, tento email prosím ignorujte.</p>',
					);

			case self::TYPE_E2:
				return $is_en
					? array(
						'subject' => 'Your enrollment: {course}',
						'body'    => '<p style="margin:0 0 14px">Hi {first_name},</p><p style="margin:0 0 14px">Thanks for enrolling {participant} in "{course}" ({term_schedule}).</p>' . self::payment_block_html( false ) . '<p style="margin:16px 0 0">Please use the variable symbol so we can match your payment. This email confirms your enrollment; our <a href="{terms_url}" style="color:#2B1710">Terms &amp; Conditions</a> apply.</p>',
					)
					: array(
						'subject' => 'Vaše přihláška: {course}',
						'body'    => '<p style="margin:0 0 14px">Ahoj {first_name},</p><p style="margin:0 0 14px">Děkujeme za přihlášení ({participant}) na kurz "{course}" ({term_schedule}).</p>' . self::payment_block_html( true ) . '<p style="margin:16px 0 0">Uveďte prosím variabilní symbol, ať platbu správně spárujeme. Tento email potvrzuje vaši přihlášku; platí naše <a href="{terms_url}" style="color:#2B1710">obchodní podmínky</a>.</p>',
					);

			case self::TYPE_E3:
				// Always sent in Czech (spec F14: "admin notifications (E3)
				// always CS") — the EN variant still exists in storage for
				// consistency but is never rendered by Email_Sender for this type.
				return array(
					'subject' => 'Nová přihláška: {course}',
					'body'    => '<p>Nová přihláška.</p><p>Zákazník: {first_name}<br>Účastník: {participant}<br>Kurz: {course} ({term_schedule})<br>Částka: {price}<br>Variabilní symbol: {variable_symbol}</p>',
				);

			case self::TYPE_E4:
				return $is_en
					? array(
						'subject' => 'Payment received — Ruben Dance',
						'body'    => '<p>Hi {first_name},</p><p>We have received your payment.</p><p>Course: {course}<br>Amount: {price}<br>Variable symbol: {variable_symbol}</p><p>See you in class!</p>',
					)
					: array(
						'subject' => 'Platba přijata — Ruben Dance',
						'body'    => '<p>Ahoj {first_name},</p><p>Přijali jsme vaši platbu.</p><p>Kurz: {course}<br>Částka: {price}<br>Variabilní symbol: {variable_symbol}</p><p>Těšíme se na viděnou!</p>',
					);

			case self::TYPE_E5:
				return $is_en
					? array(
						'subject' => 'Class change: {course}',
						'body'    => '<p>Hi {first_name},</p><p>The lesson on {lesson_date} for "{course}" has been <strong>{status}</strong>.</p><p>{note}</p><p>Sorry for the inconvenience.</p>',
					)
					: array(
						'subject' => 'Změna lekce: {course}',
						'body'    => '<p>Ahoj {first_name},</p><p>Lekce dne {lesson_date} v kurzu "{course}" byla <strong>{status}</strong>.</p><p>{note}</p><p>Omlouváme se za komplikace.</p>',
					);

			case self::TYPE_E6:
				return $is_en
					? array(
						'subject' => 'Your enrollment was cancelled: {course}',
						'body'    => '<p>Hi {first_name},</p><p>Your enrollment in "{course}" ({term_schedule}) has been cancelled.</p><p>If this is unexpected, please get in touch.</p>',
					)
					: array(
						'subject' => 'Vaše přihláška byla zrušena: {course}',
						'body'    => '<p>Ahoj {first_name},</p><p>Vaše přihláška na kurz "{course}" ({term_schedule}) byla zrušena.</p><p>Pokud jste to neočekávali, ozvěte se nám prosím.</p>',
					);

			case self::TYPE_E7:
				return $is_en
					? array(
						'subject' => 'Payment reminder — Ruben Dance',
						'body'    => '<p style="margin:0 0 14px">Hi {first_name},</p><p style="margin:0 0 14px">This is a reminder that we haven\'t yet received your payment for "{course}".</p>' . self::payment_block_html( false ) . '<p style="margin:16px 0 0">Please send your payment as soon as possible.</p>',
					)
					: array(
						'subject' => 'Připomínka platby — Ruben Dance',
						'body'    => '<p style="margin:0 0 14px">Ahoj {first_name},</p><p style="margin:0 0 14px">Připomínáme, že jsme od vás ještě neobdrželi platbu za kurz "{course}".</p>' . self::payment_block_html( true ) . '<p style="margin:16px 0 0">Platbu prosím odešlete co nejdříve.</p>',
					);

			case self::TYPE_E8:
				// Always sent in Czech (mirrors E3: "admin notifications
				// always CS" — see the TYPE_E8 constant docblock).
				return array(
					'subject' => 'Dotaz na voucher',
					'body'    => '<p>Nový dotaz na dárkový voucher.</p><p>Jméno: {name}<br>Email: {email}<br>Zpráva: {message}</p>',
				);

			default:
				return array(
					'subject' => '',
					'body'    => '',
				);
		}
	}

	/**
	 * The E2/E7 payment-instructions block, inline-styled to match
	 * design/screens.html #3a/#3j's `.rd-payment` card (cream background,
	 * 2px yellow border, bold amount, dashed-divider QR row) — but as a
	 * literal `style="…"` HTML fragment rather than that CSS class, since
	 * `Email_Layout` (the only place in this plugin that renders HTML for an
	 * email client rather than a browser) can't load an external stylesheet.
	 *
	 * Leaves an `<!--RD-QR-->` HTML-comment marker where the QR code image
	 * belongs: `Payment_Qr_Email::augment()` replaces it with the actual
	 * `<img>` when IBAN/QR is configured, and simply leaves the (invisible)
	 * comment in place otherwise — see that class's doc comment for why a
	 * marker-replace, rather than "always append after the whole body", is
	 * what lets the QR image land *inside* this card instead of trailing
	 * behind it.
	 *
	 * The `{price}`/`{account_number}`/`{variable_symbol}`/`{due_date}`
	 * placeholder tokens are unchanged from the pre-D8 plain-paragraph
	 * version — only the surrounding HTML/CSS changed, not what an admin
	 * editing this template in `Admin\Email_Templates_Page` can reference.
	 *
	 * @param bool $is_cs Czech labels when true, English otherwise.
	 * @return string
	 */
	private static function payment_block_html( bool $is_cs ): string {
		$status_label  = $is_cs ? 'Čeká na platbu' : 'Awaiting payment';
		$account_label = $is_cs ? 'Účet' : 'Account';
		$vs_label      = $is_cs ? 'Variabilní symbol' : 'Variable symbol';
		$due_label     = $is_cs ? 'Splatnost' : 'Due date';

		return '<div style="margin:16px 0 0;background:#FDF6EA;border:2px solid #F5B840;border-radius:12px;padding:16px">'
			. '<div style="font-size:10.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#8A5500">&#9201; ' . $status_label . '</div>'
			. '<div style="font-weight:800;font-size:22px;margin:4px 0 10px;color:#2B1710">{price}</div>'
			. '<div style="font-size:13px;line-height:1.7;color:#2B1710">' . $account_label . ': <strong>{account_number}</strong><br>' . $vs_label . ': <strong>{variable_symbol}</strong><br>' . $due_label . ': <strong>{due_date}</strong></div>'
			. '<!--RD-QR-->'
			. '</div>';
	}
}
