<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which fonts a certificate may be rendered in, and why there is only one.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * mPDF DECIDES WHAT IS AVAILABLE FROM ITS MAP, NEVER FROM WHAT IS ON DISK.
 *
 * `Mpdf::available_unifonts` is built by walking the `fontdata` array. A family listed
 * there whose .ttf has been deleted is still "available" as far as mPDF is concerned, and
 * the first document that asks for it FATALS — there is no warning and no fallback. So the
 * set of fonts we declare and the set the release ZIPs carry have to be the same set, or
 * the failure shows up on a customer's site rather than here.
 *
 * That is why this file exists rather than a literal in `Renderer`: `fonts.json` is read
 * both by this class, which tells mPDF what exists, and by the release script, which
 * deletes everything else. One declaration, two readers, no drift.
 *
 * ONE FAMILY, AND THE REASON IS THE FREE DOWNLOAD. mPDF bundles ~88 MB of fonts; even
 * trimmed to the whole DejaVu set that is 4.66 MB compressed, which put the free ZIP over
 * WordPress.org's 10 MB upload limit when certificates stopped being premium. DejaVu Sans
 * alone is 1.3 MB and covers Latin, Cyrillic, Greek, Hebrew and Arabic. The certificate
 * designer deliberately offers no font selector, so nothing can ask for the families this
 * drops; a generic `serif` or `monospace` in a theme override lands back on DejaVu Sans
 * through mPDF's own substitution, which reads the same map.
 *
 * `useOTL` and `useKashida` are carried across from mPDF's own declaration and are not
 * decoration: they are what makes `autoArabic` shape Arabic into its initial, medial,
 * final and isolated forms instead of printing disconnected letters.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class Fonts {

	private const MANIFEST = __DIR__ . '/../fonts.json';

	/**
	 * The complete `fontdata` map to hand mPDF.
	 *
	 * @return array<string, array<string, int|string>>
	 */
	public static function fontdata(): array {
		$manifest = self::manifest();
		$families = array();

		foreach ( (array) ( $manifest['families'] ?? array() ) as $name => $faces ) {
			$families[ (string) $name ] = (array) $faces;
		}

		return $families;
	}

	/**
	 * The family a certificate is set in when nothing asks for another.
	 *
	 * The first family in the manifest, so the manifest stays the one place the decision
	 * is written down.
	 */
	public static function default_family(): string {
		$families = self::fontdata();

		return (string) ( array_key_first( $families ) ?? 'dejavusans' );
	}

	/**
	 * Every file the manifest names, faces and extras alike.
	 *
	 * Used by the tests that check the declaration against what mPDF actually ships.
	 *
	 * @return string[]
	 */
	public static function files(): array {
		$manifest = self::manifest();
		$files    = array();

		foreach ( self::fontdata() as $faces ) {
			foreach ( array( 'R', 'B', 'I', 'BI' ) as $style ) {
				if ( ! empty( $faces[ $style ] ) ) {
					$files[] = (string) $faces[ $style ];
				}
			}
		}

		foreach ( (array) ( $manifest['extra_files']['files'] ?? array() ) as $extra ) {
			$files[] = (string) $extra;
		}

		return $files;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function manifest(): array {
		static $cache = null;

		if ( null === $cache ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own manifest, not a remote resource.
			$raw   = (string) file_get_contents( self::MANIFEST );
			$cache = (array) json_decode( $raw, true );
		}

		return $cache;
	}
}
