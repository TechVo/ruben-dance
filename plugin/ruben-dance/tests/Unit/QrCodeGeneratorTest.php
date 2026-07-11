<?php
/**
 * Tests for the QR image renderer.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use chillerlan\QRCode\QRCode;
use PHPUnit\Framework\TestCase;
use RubenDance\Services\Qr_Code_Generator;

/**
 * Class QrCodeGeneratorTest.
 *
 * `chillerlan/php-qrcode` needs `ext-gd` or `ext-imagick` to actually render
 * (and to decode, for the round-trip check below) — neither is guaranteed to
 * be present in every environment this suite runs in, so every test here
 * skips rather than fails when both are missing, the standard PHPUnit
 * pattern for an optional-extension dependency. Where the extension *is*
 * present (every real WordPress hosting environment, and `wp-env`'s
 * container — see the M14 milestone's manual verification), this is the
 * "decode the generated QR programmatically" check the milestone calls for:
 * round-tripping the exact same SPAYD string this plugin's
 * `Emails\Payment_Qr_Email`/`Front\Qr_Code_Ajax` feed into it.
 */
class QrCodeGeneratorTest extends TestCase {

	/**
	 * Skip every test in this class when neither backend the library needs
	 * is loaded.
	 */
	protected function setUp(): void {
		if ( ! extension_loaded( 'gd' ) && ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Requires ext-gd or ext-imagick, neither of which is loaded.' );
		}
	}

	/**
	 * The renderer produces a non-empty, well-formed PNG.
	 */
	public function test_renders_a_png_image(): void {
		$png = ( new Qr_Code_Generator() )->png( 'SPD*1.0*ACC:CZ6508000000192000145399*AM:1500.00*CC:CZK*X-VS:2025000042*MSG:Kurz' );

		$this->assertNotSame( '', $png );
		// The 8-byte PNG file signature (spec: RFC 2083 / the PNG standard).
		$this->assertSame( "\x89PNG\x0D\x0A\x1A\x0A", substr( $png, 0, 8 ) );
	}

	/**
	 * Round-trip: decoding the rendered image reproduces the exact SPAYD
	 * string that was encoded, character-for-character — the strongest
	 * automatable substitute for "scan it with a real banking app".
	 */
	public function test_decodes_back_to_the_exact_spayd_string(): void {
		$spayd = 'SPD*1.0*ACC:CZ6508000000192000145399*AM:1349.50*CC:CZK*X-VS:2025000042*MSG:Tancovani pro pokrocile';

		$png = ( new Qr_Code_Generator() )->png( $spayd );

		$decoded = ( new QRCode() )->readFromBlob( $png );

		$this->assertSame( $spayd, $decoded->data );
	}
}
