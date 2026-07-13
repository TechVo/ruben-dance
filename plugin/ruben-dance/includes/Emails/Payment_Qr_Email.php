<?php
/**
 * Appends the QR-platba code to an already-composed E2/E7 email body.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Emails;

use RubenDance\Lang;
use RubenDance\Services\Qr_Code_Generator;
use RubenDance\Services\Spayd_Builder;
use RubenDance\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Payment_Qr_Email.
 *
 * Wherever payment instructions appear in an email (spec F16/§4.5: E2 + E7),
 * this builds the `Emails\Email_Sender::send()` `$augment_body` callback that
 * appends an inline QR-platba image after the (admin-editable) template body
 * — the template text itself never needs to know the QR feature exists, so
 * an admin who rewrites the E2/E7 body can never accidentally delete it.
 * `augmenter()` returns `null` when no IBAN is configured, so `Email_Sender`
 * simply skips augmentation and the email goes out exactly as it did before
 * this milestone (spec acceptance criterion: "No IBAN configured → no QR
 * anywhere, no errors, text instructions intact").
 */
class Payment_Qr_Email {

	/**
	 * Build the augmenter for one enrollment's E2/E7 email, or `null` when
	 * the QR feature is currently off (no IBAN configured).
	 *
	 * @param array<string, mixed>      $enrollment Enrollment row (needs `id`, `price`, `variable_symbol`).
	 * @param array<string, mixed>|null $term       Term row, for the course title used as the SPAYD `MSG`.
	 * @param string                    $lang       Recipient language, `Lang::CS`/`Lang::EN`.
	 * @return callable|null function( string $html_body ): array{body: string, inline_images: array}.
	 */
	public static function augmenter( array $enrollment, ?array $term, string $lang ): ?callable {
		if ( '' === Settings::iban() ) {
			return null;
		}

		return static function ( string $body ) use ( $enrollment, $term, $lang ): array {
			return self::augment( $body, $enrollment, $term, $lang );
		};
	}

	/**
	 * Insert the QR-platba image markup into `$body` and build the matching
	 * inline-image attachment `Services\Mailer::send()` embeds it from.
	 *
	 * @param string                    $body       Already-composed HTML email body.
	 * @param array<string, mixed>      $enrollment Enrollment row.
	 * @param array<string, mixed>|null $term       Term row, or null when it no longer exists.
	 * @param string                    $lang       Recipient language.
	 * @return array{body: string, inline_images: array<int, array{cid: string, data: string, mime: string}>}
	 */
	private static function augment( string $body, array $enrollment, ?array $term, string $lang ): array {
		$iban = Settings::iban();

		if ( '' === $iban ) {
			// Defensive: the setting could theoretically change between
			// `augmenter()` building this closure and `Email_Sender::send()`
			// invoking it (e.g. an admin clears the field mid-request in a
			// multi-process setup) — fail closed to "no QR", never to a
			// broken SPAYD string.
			return array(
				'body'          => $body,
				'inline_images' => array(),
			);
		}

		$spayd = Spayd_Builder::build(
			$iban,
			(string) ( $enrollment['price'] ?? '0' ),
			(string) ( $enrollment['variable_symbol'] ?? '' ),
			Enrollment_Email_Data::course_title( $term, $lang )
		);

		$cid = 'rd-qr-' . (int) ( $enrollment['id'] ?? 0 );

		$png = ( new Qr_Code_Generator() )->png( $spayd );

		$caption = Lang::EN === $lang
			? 'Scan with your banking app to pay'
			: 'Naskenujte QR kód bankovní aplikací a zaplaťte';

		// Design/screens.html #3a/#3j's payment card puts the QR code
		// *inside* the card, below a dashed divider — matched here by
		// replacing the `<!--RD-QR-->` marker `Email_Templates::payment_block_html()`
		// leaves for exactly this, rather than appending after the whole
		// body as pre-D8 did. Falls back to appending at the end (the old
		// behavior) when the marker isn't found — e.g. an admin has since
		// rewritten the E2/E7 template text in `Admin\Email_Templates_Page`
		// and removed it — so a customized template still gets its QR code
		// somewhere rather than silently losing it.
		$qr_html = '<div style="display:flex;gap:12px;align-items:center;margin-top:12px;padding-top:10px;border-top:1px dashed rgba(43,23,16,.25)">'
			. '<img src="cid:' . $cid . '" alt="QR platba" width="80" height="80" style="display:block;border-radius:8px;flex:none">'
			. '<div style="font-size:12px;line-height:1.45;color:rgba(43,23,16,.7)"><strong>QR platba</strong> &mdash; ' . $caption . '.</div>'
			. '</div>';

		if ( false !== strpos( $body, '<!--RD-QR-->' ) ) {
			$body = str_replace( '<!--RD-QR-->', $qr_html, $body );
		} else {
			$body .= '<p style="text-align:center;margin-top:1.5em">' . $qr_html . '</p>';
		}

		return array(
			'body'          => $body,
			'inline_images' => array(
				array(
					'cid'  => $cid,
					'data' => $png,
					'mime' => 'image/png',
				),
			),
		);
	}
}
