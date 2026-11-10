<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode as Chillerlan;
use chillerlan\QRCode\QROptions;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The QR code that turns a printed certificate back into a verifiable one.
 *
 * WHY A DATA URI RATHER THAN A FILE. The code is embedded in the frozen snapshot HTML, which
 * has to render identically in a browser today and in a PDF generated from the database in
 * five years. A file on disk is one `wp-content` restore away from being gone, and the PDF
 * renderer is deliberately configured with remote fetching OFF — so a URL would not load into
 * the PDF at all. Inline bytes have neither problem.
 *
 * PNG RATHER THAN SVG, for the same reason the media rules exclude SVG: it is a document
 * format that can carry script, and this one ends up inside HTML that is stored and re-served.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ THIS CLASS FAILS SILENTLY ON PURPOSE, SO IT IS THE ONE PLACE A LIBRARY UPGRADE CAN
 * BREAK WITH NOTHING TO SHOW FOR IT.
 *
 * `data_uri()` returns an empty string rather than throwing — see the method for why that
 * is right. The cost is that anything wrong in HERE looks, from outside, like a certificate
 * that happens to have no QR code. That is exactly what happened the moment php-qrcode went
 * from 5 to 6: the options below had been renamed (`outputType` became `outputInterface`,
 * and takes a class; `imageBase64` became `outputBase64`), the constant the old code named
 * no longer existed, the resulting `Error` was caught, and every certificate was issued
 * without its code. No notice, no log line, and no test failed — the suite checked that the
 * design HAS a QR field and never that the field got a picture.
 *
 * `QrCodeTest` reads the picture back with the library's own reader and compares it with
 * the address. If these options are ever touched again, that test is the only thing that
 * will say whether a certificate still scans.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class QrCode {

	/**
	 * A QR code for a URL, as a `data:image/png;base64,…` URI.
	 *
	 * Returns an empty string on failure rather than throwing. A certificate without its QR
	 * is still a valid certificate that verifies by typing the URL; a fatal during issue
	 * would lose the award entirely, on the submit request an examinee is waiting on.
	 *
	 * @param string $url The verification URL.
	 */
	public function data_uri( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		try {
			$options = new QROptions(
				array(
					// php-qrcode 6: the output is named by its CLASS, and the data URI is
					// `outputBase64`. Both were other names in 5.
					'outputInterface'  => QRGdImagePNG::class,
					'outputBase64'     => true,
					// Level Q tolerates about 25% damage. A certificate is printed, folded,
					// and photographed under bad lighting; the lowest level does not survive
					// that, and the highest wastes room the design needs.
					'eccLevel'         => EccLevel::Q,
					'scale'            => 6,
					'imageTransparent' => false,
				)
			);

			return (string) ( new Chillerlan( $options ) )->render( $url );
		} catch ( Throwable $e ) {
			return '';
		}
	}
}
