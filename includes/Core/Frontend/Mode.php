<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which way round the exam surface is.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THIS IS DECIDED ON THE SERVER, AND THE FIRST DRAFT DECIDED IT IN JAVASCRIPT.
 *
 * That draft read `--pxe-bg` off the DOM in `theme/index.tsx` and wrote `data-pxe-mode` from
 * a `useEffect`, which is after first paint — so a site branded dark rendered one frame of
 * light-mode hairlines and shadows before flipping. Worse, the SERVER-RENDERED markup is
 * what the reader looks at until the bundle boots, and it would have been styled light for
 * the whole of that.
 *
 * So there is exactly one derivation and it happens here, before a byte is sent.
 * `parts/header.php` prints the attribute onto the scope root, `styles/frontend.css` styles
 * `[data-pxe-mode='dark']` from it, and `theme/tokens.ts` READS the attribute rather than
 * deriving anything. Two consumers, one decision.
 *
 * ⚠️ SO DO NOT ADD A SECOND ONE IN JAVASCRIPT. A site whose child theme overrides `--pxe-bg`
 * in CSS is invisible from here and would be visible from there — and answering that by
 * re-deriving on the client buys a correct edge case at the price of the two halves being
 * able to disagree on every ordinary page. The setting is the authority.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class Mode {

	public const LIGHT = 'light';
	public const DARK  = 'dark';

	/**
	 * Is this background dark enough to want light type?
	 *
	 * ⚠️ TODO — THIS ONE IS DELIBERATELY LEFT OPEN. It is about five lines, and it decides
	 * how the product behaves on every dark-branded site. Until it is written this returns
	 * `LIGHT`, which is exactly today's behaviour, so nothing is broken while it waits.
	 *
	 * What there is to work with:
	 *
	 *   self::rgb( $colour )       → array{int, int, int}, or null when it is not a colour.
	 *   self::luminance( $rgb )    → 0.0 .. 1.0.
	 *
	 * Reference points: `#ffffff` is 1.0 · `#f4f6f9` ≈ 0.90 · `#808080` ≈ 0.216 ·
	 * `#1a1a1a` ≈ 0.011.
	 *
	 * THE TRADE-OFF. There is a principled threshold available: white type becomes more
	 * readable than black type at a luminance of about **0.179** — solve
	 * `1.05 / (L + 0.05) = (L + 0.05) / 0.05` and that is where the two contrast ratios
	 * cross. Taking it means "dark" means precisely "a page that wants light type". Taking
	 * something higher (0.35, say) pulls mid-tone brand colours — a deep teal, a navy — into
	 * dark mode as well, which usually looks better but will surprise an owner who chose a
	 * middling grey.
	 *
	 * AND THE HALF THAT ACTUALLY SHOWS: what should an UNPARSEABLE colour do? `LIGHT` means
	 * it gets dark-on-light styling, right on the overwhelming majority of sites and
	 * wrong-but-legible on the rest. `DARK` is the opposite bet. There is no third answer
	 * that is safe, only one that is safer.
	 *
	 * (What the RAMP does either way is already settled — every neutral in
	 * `styles/frontend.css` is declared as a distance from the background toward the text,
	 * so it inverts on its own. This decides only the three things that are NOT symmetric:
	 * the card surface, the hairline weight, and whether shadows exist at all.)
	 *
	 * @param string $background The `color_bg` setting, or an empty string for the default.
	 *
	 * @return self::LIGHT|self::DARK
	 */
	public static function of( string $background ): string {
		unset( $background );

		return self::LIGHT;
	}

	/**
	 * Resolve a CSS colour to sRGB channels, 0-255.
	 *
	 * ⚠️ HEX ONLY, AND THAT IS A KNOWN LIMIT RATHER THAN AN OVERSIGHT. The colour settings
	 * accept named colours and `rgb()` on purpose — {@see AppAssets::palette()} escapes
	 * rather than validating, and its comment says why — and PHP has no colour parser. A
	 * value this cannot read returns `null`, which {@see self::of()} must treat as "nothing
	 * is known", never as "it is light".
	 *
	 * The settings screen writes hex, so this covers everything the UI can produce.
	 *
	 * @param string $colour A CSS colour.
	 *
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	public static function rgb( string $colour ): ?array {
		$value = strtolower( trim( $colour ) );

		if ( 1 !== preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value ) ) {
			return null;
		}

		$digits = substr( $value, 1 );

		// Short-hand doubles each digit: `#abc` is `#aabbcc`. An alpha nibble or byte is
		// present in the 4- and 8-digit forms and is simply ignored — opacity has no
		// bearing on whether the page wants light type.
		if ( strlen( $digits ) < 6 ) {
			$expanded = '';

			foreach ( str_split( $digits ) as $digit ) {
				$expanded .= $digit . $digit;
			}

			$digits = $expanded;
		}

		return array(
			(int) hexdec( substr( $digits, 0, 2 ) ),
			(int) hexdec( substr( $digits, 2, 2 ) ),
			(int) hexdec( substr( $digits, 4, 2 ) ),
		);
	}

	/**
	 * WCAG 2.x relative luminance, 0 (black) to 1 (white).
	 *
	 * The standard formula, not a `(r + g + b) / 3` average: green carries most of perceived
	 * brightness, and an average calls `#0000ff` a mid-tone when it is very nearly black.
	 *
	 * @param array{0: int, 1: int, 2: int} $rgb Channels, 0-255.
	 */
	public static function luminance( array $rgb ): float {
		$channel = static function ( int $value ): float {
			$scaled = $value / 255;

			return $scaled <= 0.03928 ? $scaled / 12.92 : ( ( $scaled + 0.055 ) / 1.055 ) ** 2.4;
		};

		return 0.2126 * $channel( $rgb[0] ) + 0.7152 * $channel( $rgb[1] ) + 0.0722 * $channel( $rgb[2] );
	}
}
