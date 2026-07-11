<?php
/**
 * Renders a QR code image from a string, server-side only.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Qr_Code_Generator.
 *
 * Thin wrapper around `chillerlan/php-qrcode` (MIT/Apache-2.0, a small local
 * PHP library, no external service call — spec §4.5: "Generated locally ...
 * no external service, nothing personal leaves the server"). Kept as its own
 * class (rather than calling the library directly from every call site) so
 * the rendering options — error-correction level, scale, output format — are
 * decided in exactly one place, and so a future output-format change or
 * library swap never has to touch `Front\Qr_Code_Ajax`/`Emails\Payment_Qr_Email`.
 */
class Qr_Code_Generator {

	/**
	 * Render `$data` as a PNG image.
	 *
	 * @param string $data Payload to encode (the SPAYD string, in this plugin's only use).
	 * @return string Raw PNG binary data.
	 */
	public function png( string $data ): string {
		$options = new QROptions(
			array(
				'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
				// Error-correction M (~15% damage-tolerant): a step up from
				// the library's default L, cheap for a short SPAYD payload,
				// and the level most real-world QR payment generators use so
				// a slightly scuffed print/screen still scans.
				'eccLevel'     => QRCode::ECC_M,
				// Raw binary, not a `data:image/png;base64,...` URI — this
				// class's only callers (an authenticated image endpoint and
				// an email inline-image attachment) both want the bytes
				// themselves.
				'imageBase64'  => false,
				'scale'        => 6,
				'addQuietzone' => true,
			)
		);

		return (string) ( new QRCode( $options ) )->render( $data );
	}
}
