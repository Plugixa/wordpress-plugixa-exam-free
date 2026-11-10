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
 * A design plus a set of values, turned into a printable page.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * MILLIMETRES ALL THE WAY THROUGH, AND NEVER PIXELS.
 *
 * A pdfme template positions every field in millimetres on a physical page. This renders it
 * to HTML using `mm` units directly, and mPDF takes its page size in millimetres too — so
 * nothing in the chain has to pick a DPI, and nothing in the chain can pick a different one.
 * That is the usual reason a certificate which looked right in the designer prints with the
 * name a centimetre off the line it was supposed to sit on.
 *
 * `position` in pdfme is the TOP-LEFT of the box, which is also how CSS absolute positioning
 * works, so the two agree without arithmetic.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHY mPDF AND NOT DOMPDF, WHICH THIS USED UNTIL 0.7.0. Dompdf performs no bidirectional
 * reordering and no Arabic contextual shaping. Rendered through it, an Arabic name came out
 * as disconnected letterforms in reverse order and a Hebrew name came out backwards — not
 * approximately wrong, unreadable — while a Chinese name came out as empty boxes, because
 * the bundled DejaVu has no CJK coverage. All three were measured on a real page beside the
 * same HTML in a browser before the engine was changed.
 *
 * mPDF recognises the script, reorders right-to-left runs and shapes Arabic into its
 * contextual forms. See `pdf()` for the three settings that do it, and for why the fourth
 * one — the font selector — is deliberately left off.
 *
 * WHY HTML IN THE MIDDLE AT ALL, rather than drawing straight into a PDF. The verification
 * page has to show the certificate in a browser, and an examinee on a phone should not have
 * to download a PDF to check their own award. One render path producing HTML gives the
 * browser view and the PDF the same output by construction; two paths would drift, and the
 * drift would be invisible until somebody compared a screen to a printout.
 *
 * That HTML is also what gets frozen into `certificates.snapshot_html` at issue time.
 */
final class Renderer {

	/**
	 * Page sizes in millimetres, portrait.
	 *
	 * @var array<string, array{0: float, 1: float}>
	 */
	private const PAGES = array(
		'A4'     => array( 210.0, 297.0 ),
		'Letter' => array( 215.9, 279.4 ),
	);

	/**
	 * Guards from NFR-SEC-9. A design is authored by a trusted role, which is a reason to
	 * bound it rather than a reason not to: a pasted or imported design is still a document
	 * this server has to lay out, and 20 000 text boxes is a request that never returns.
	 */
	private const MAX_FIELDS = 200;
	private const MIN_PAGE_MM = 50.0;
	private const MAX_PAGE_MM = 2000.0;

	/**
	 * Render a design to standalone HTML.
	 *
	 * @param array<string, mixed>  $design      Decoded pdfme template.
	 * @param array<string, string> $values      Field name => value.
	 * @param string                $page_size   `A4` or `Letter`.
	 * @param string                $orientation `portrait` or `landscape`.
	 */
	public function html( array $design, array $values, string $page_size, string $orientation ): string {
		list( $width, $height ) = $this->page( $page_size, $orientation );

		$fields = $this->fields( $design );
		$boxes  = '';

		foreach ( $fields as $name => $field ) {
			$boxes .= $this->box( (string) $name, $field, $values, $width, $height );
		}

		$background = $this->background( $design, $width, $height );

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * THE FIELDS ARE DIRECT CHILDREN OF `<body>`, AND THAT IS NOT A STYLE PREFERENCE.
		 *
		 * They used to sit inside a `position: relative` wrapper, which is the ordinary way
		 * to give absolutely positioned children a containing block, and which a browser
		 * honours. mPDF does NOT: it supports `position: absolute` only for block elements
		 * that are direct children of BODY, and silently ignores the wrapper — so every
		 * field stacked at the top-left corner. Measured on a real render before this
		 * changed.
		 *
		 * A sized, relatively positioned BODY is a containing block in a browser too, so
		 * both engines still read the SAME document. That matters: rendering through HTML
		 * exists so the PDF and anything shown on screen cannot drift apart, and a second
		 * markup path for the PDF would have given that up to save a div.
		 * ════════════════════════════════════════════════════════════════════════════════
		 *
		 * EVERY RULE RIDES ON THE ELEMENT IT APPLIES TO, AS A `style` ATTRIBUTE. THE DOCUMENT
		 * HAS NO STYLESHEET BLOCK, AND IT MUST NOT GROW ONE.
		 *
		 * A stylesheet written out by hand in PHP is what WordPress.org's review asks to be
		 * enqueued, and this one never could be: the document is not a page, nothing prints
		 * it through `wp_head()`, and there is no handle for `wp_add_inline_style()` to
		 * attach to. So the rules moved rather than the argument being made. Both engines
		 * read them where they are now — mPDF parses BODY's own `style` attribute, and
		 * merges an element's inline declarations into the same set a class rule fed.
		 * `InlineTagsTest` fails the build if a block comes back.
		 *
		 * THE PAGE SIZE AND ITS ZERO MARGINS ARE NOT IN THE DOCUMENT AT ALL. A page rule is
		 * the one thing with no inline form, and it only ever repeated what `pdf()` hands
		 * mPDF directly: the format in millimetres and four margins of nothing.
		 *
		 * A certificate issued by 1.1.1 or earlier still carries the old block in its frozen
		 * snapshot, page rule included. It renders as it always did — `pdf()` takes whatever
		 * document it is given — and it is never rewritten, because an issued certificate
		 * does not change.
		 */
		$page = 'margin:0;padding:0;position:relative;'
			. 'width:' . $this->number( $width ) . 'mm;height:' . $this->number( $height ) . 'mm;'
			. 'overflow:hidden;background:#fff;font-family:DejaVu Sans, sans-serif;';

		return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
			. '<body style="' . esc_attr( $page ) . '">'
			. $background
			. $boxes
			. '</body></html>';
	}

	/**
	 * Render HTML to a PDF, as raw bytes.
	 *
	 * @param string $html        Standalone HTML, normally a frozen snapshot.
	 * @param string $page_size   `A4` or `Letter`.
	 * @param string $orientation `portrait` or `landscape`.
	 */
	public function pdf( string $html, string $page_size, string $orientation ): string {
		list( $width, $height ) = $this->page( $page_size, $orientation );

		$temp = get_temp_dir() . 'plugixa-exam-mpdf';

		if ( ! is_dir( $temp ) ) {
			wp_mkdir_p( $temp );
		}

		$config = array(
			'mode'             => 'utf-8',

			// mPDF takes the page in MILLIMETRES, which is what the whole chain already
			// speaks — so unlike Dompdf there is no conversion to points here, and one
			// fewer place for a rounding error to move somebody's name off its line.
			'format'           => array( $width, $height ),
			'tempDir'          => $temp,
			'margin_left'      => 0,
			'margin_right'     => 0,
			'margin_top'       => 0,
			'margin_bottom'    => 0,

			/*
			 * ════════════════════════════════════════════════════════════════════════════
			 * THE TWO SETTINGS THAT MAKE A NAME READABLE, AND THE ONE THAT MUST STAY OFF.
			 *
			 * `autoScriptToLang` tags each run of text with the script it is written in;
			 * `autoArabic` then reshapes Arabic into its initial, medial, final and
			 * isolated forms and reorders right-to-left runs. Without them Arabic comes out
			 * as disconnected letters in reverse order and Hebrew comes out backwards —
			 * measured on this renderer before the change, on a document carrying a
			 * person's name.
			 *
			 * `autoLangToFont` STAYS OFF, and that is the size of the plugin. On, mPDF
			 * reaches for a script-specific font — Sun-ExtA for CJK, 22 MB, plus 17 MB for
			 * Sun-ExtB — and if the file is not there it does not fall back: it THROWS, so
			 * a Chinese candidate would get no certificate at all rather than an imperfect
			 * one. Off, everything renders from DejaVu, which covers Latin, Cyrillic,
			 * Greek, Hebrew and Arabic; CJK falls back to empty boxes exactly as it did
			 * under Dompdf, and the premium download does not grow by 39 MB.
			 *
			 * A site that needs CJK can have it: turn `autoLangToFont` on through the
			 * filter below and point `fontDir` at a font of its own.
			 * ════════════════════════════════════════════════════════════════════════════
			 */
			'autoScriptToLang' => true,
			'autoArabic'       => true,
			'autoLangToFont'   => false,
			'default_font'     => Fonts::default_family(),

			/*
			 * THE COMPLETE LIST OF FONTS THIS DOCUMENT MAY USE, and the reason it is a
			 * list of one is the free download. See {@see Fonts} — mPDF reads what is
			 * AVAILABLE out of this array and never off the disk, so declaring a family
			 * the ZIP does not carry is a fatal at render time rather than a fallback.
			 * Overriding it here is what keeps the declaration and the shipped files one
			 * decision instead of two that can drift.
			 */
			'fontdata'         => Fonts::fontdata(),

			/*
			 * ⚠️ REMOTE FETCHING IS OFF, AND THIS IS NOT THE SWITCH DOMPDF USED.
			 *
			 * Dompdf refuses remote assets unless `isRemoteEnabled` is turned ON. mPDF is
			 * the other way round: it fetches `http`, `https` and `file` unless the
			 * whitelist is emptied. A port that simply dropped the old line would have
			 * turned the guard off while looking like it kept it — the same guard that
			 * stops a background-image URL in a design becoming an outbound request made by
			 * the server on behalf of whoever authored it. Images are embedded as data URIs
			 * at issue time, and `data:` is not a stream wrapper, so nothing legitimate
			 * needs this list.
			 */
			'whitelistStreamWrappers' => array(),
			'showImageErrors'         => false,
		);

		/**
		 * Filter the mPDF configuration used to render a certificate.
		 *
		 * The seam for a site that needs a script DejaVu does not carry — switch
		 * `autoLangToFont` on, add a `fontDir`, and ADD TO `fontdata` rather than replacing
		 * it, since what arrives here is already the complete declared set and dropping
		 * DejaVu Sans would take the certificate's own text with it. Adding a font mPDF is
		 * told about but cannot find is a fatal at render time, not a warning, so the entry
		 * and the file have to arrive together.
		 *
		 * @param array<string, mixed> $config      mPDF configuration.
		 * @param string               $page_size   `A4` or `Letter`.
		 * @param string               $orientation `portrait` or `landscape`.
		 */
		$config = (array) apply_filters( 'plugixa_exam_certificate_mpdf_config', $config, $page_size, $orientation );

		$mpdf = new \Mpdf\Mpdf( $config );

		$mpdf->WriteHTML( $html );

		return (string) $mpdf->Output( '', 'S' );
	}

	/**
	 * The page in millimetres, with the orientation applied.
	 *
	 * @param string $page_size   Page size key.
	 * @param string $orientation Orientation.
	 *
	 * @return array{0: float, 1: float}
	 */
	public function page( string $page_size, string $orientation ): array {
		list( $width, $height ) = self::PAGES[ $page_size ] ?? self::PAGES['A4'];

		if ( 'landscape' === $orientation ) {
			return array( $height, $width );
		}

		return array( $width, $height );
	}

	/**
	 * The fields of a pdfme template's first page.
	 *
	 * pdfme's `schemas` is an array of PAGES, each a map of field name => definition. Only
	 * the first page is rendered: a certificate is one sheet, and quietly dropping pages two
	 * onward is better than emitting a multi-page PDF nobody expected.
	 *
	 * @param array<string, mixed> $design Decoded template.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fields( array $design ): array {
		$schemas = $design['schemas'] ?? array();

		if ( ! is_array( $schemas ) || array() === $schemas ) {
			return array();
		}

		$page = reset( $schemas );

		if ( ! is_array( $page ) ) {
			return array();
		}

		$fields = array();

		foreach ( $page as $name => $field ) {
			if ( count( $fields ) >= self::MAX_FIELDS ) {
				break;
			}

			if ( is_array( $field ) ) {
				$fields[ (string) $name ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * One positioned field.
	 *
	 * @param string                $name   Field name, which is also the value key.
	 * @param array<string, mixed>  $field  Field definition.
	 * @param array<string, string> $values Values by field name.
	 * @param float                 $width  Page width in mm.
	 * @param float                 $height Page height in mm.
	 */
	private function box( string $name, array $field, array $values, float $width, float $height ): string {
		$position = is_array( $field['position'] ?? null ) ? $field['position'] : array();

		$w = $this->clamp( (float) ( $field['width'] ?? 40 ), 1.0, $width );
		$h = $this->clamp( (float) ( $field['height'] ?? 10 ), 1.0, $height );

		/*
		 * THE WHOLE BOX STAYS ON THE PAGE, not merely its corner. The corner used to be
		 * clamped to the page's width, which let a box start exactly on the right-hand edge
		 * and lie entirely beyond it: on a design drawn for landscape and printed portrait,
		 * the QR code — the one thing that lets anybody check the certificate — was off the
		 * sheet. A design that fits its page is not moved by this at all.
		 */
		$x = $this->clamp( (float) ( $position['x'] ?? 0 ), 0.0, max( 0.0, $width - $w ) );
		$y = $this->clamp( (float) ( $position['y'] ?? 0 ), 0.0, max( 0.0, $height - $h ) );

		$style = 'position:absolute;left:' . $this->number( $x ) . 'mm;top:' . $this->number( $y ) . 'mm;'
			. 'width:' . $this->number( $w ) . 'mm;height:' . $this->number( $h ) . 'mm;';

		$type = (string) ( $field['type'] ?? 'text' );

		if ( 'image' === $type ) {
			$src = (string) ( $values[ $name ] ?? ( $field['content'] ?? '' ) );

			// Only data URIs. See `pdf()` — a remote URL here would be an outbound request
			// made by the server on an author's behalf.
			if ( ! $this->is_data_uri( $src ) ) {
				return '';
			}

			return $this->picture( 'pxe-cert__f', $style, $w, $h, $src );
		}

		$style .= 'font-size:' . $this->number( $this->clamp( (float) ( $field['fontSize'] ?? 12 ), 1.0, 400.0 ) ) . 'pt;'
			. 'color:' . $this->colour( (string) ( $field['fontColor'] ?? '#000000' ) ) . ';'
			. 'text-align:' . $this->alignment( (string) ( $field['alignment'] ?? 'left' ) ) . ';'
			. 'line-height:' . $this->number( $this->clamp( (float) ( $field['lineHeight'] ?? 1.2 ), 0.5, 4.0 ) ) . ';';

		if ( ! empty( $field['bold'] ) ) {
			$style .= 'font-weight:bold;';
		}

		$text = (string) ( $values[ $name ] ?? ( $field['content'] ?? '' ) );

		/*
		 * `esc_html`, not `wp_kses`. A certificate field holds a name, a date or a score —
		 * text, never markup. Allowing tags here would let an examinee's own name, which they
		 * typed themselves as a guest, inject markup into a document an employer later opens.
		 */
		return '<div class="pxe-cert__f" style="' . esc_attr( $style ) . '">' . nl2br( esc_html( $text ) ) . '</div>';
	}

	/**
	 * The background image, if the design has one and it is embeddable.
	 *
	 * @param array<string, mixed> $design Decoded template.
	 * @param float                $width  Page width in mm.
	 * @param float                $height Page height in mm.
	 */
	private function background( array $design, float $width, float $height ): string {
		$base = (string) ( $design['basePdf'] ?? '' );

		if ( '' === $base || ! $this->is_data_uri( $base ) ) {
			return '';
		}

		// The whole page, in millimetres. First in the document, so every field is drawn
		// over it.
		$style = 'position:absolute;left:0;top:0;'
			. 'width:' . $this->number( $width ) . 'mm;height:' . $this->number( $height ) . 'mm;';

		return $this->picture( 'pxe-cert__bg', $style, $width, $height, $base );
	}

	/**
	 * A picture at a place on the page: a positioned block with the image inside it.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE BLOCK IS WHAT IS POSITIONED. THE IMAGE ONLY FILLS IT.
	 *
	 * The image used to carry `position: absolute` itself, which a browser honours and mPDF
	 * does not: it positions BLOCK elements that are direct children of BODY and nothing
	 * else, so an `<img>` stayed in the ordinary flow of the page. Measured on the default
	 * design, whose QR code belongs at 253 mm by 164 mm:
	 *
	 *   every certificate    the QR code was drawn in the top-left corner
	 *   landscape + a        the background took the whole first page of the flow and the
	 *   background image     QR code went on to a SECOND page
	 *   portrait + a         the background came out 210 mm square on a 297 mm page, its
	 *   background image     height read as a percentage of nothing
	 *
	 * A text field never had the problem, because a text field is a `div`. So a picture is
	 * now a `div` too, placed exactly as a text field is, with the image inside given its
	 * size in millimetres rather than as a percentage of a box the engine does not measure.
	 *
	 * A certificate issued before this still carries the old markup in its frozen snapshot,
	 * and renders as it did: an issued certificate is never rewritten.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $css_class The class the block carries.
	 * @param string $style     The block's own position and size.
	 * @param float  $width     Width in mm.
	 * @param float  $height    Height in mm.
	 * @param string $src       A data URI that {@see is_data_uri()} has accepted.
	 */
	private function picture( string $css_class, string $style, float $width, float $height, string $src ): string {
		$size = 'display:block;width:' . $this->number( $width ) . 'mm;height:' . $this->number( $height ) . 'mm;';

		return '<div class="' . esc_attr( $css_class ) . '" style="' . esc_attr( $style ) . '">'
			. '<img style="' . esc_attr( $size ) . '" src="' . esc_attr( $src ) . '" alt="">'
			. '</div>';
	}

	/**
	 * Whether a string is an inline image this renderer will accept.
	 *
	 * PNG, JPEG, GIF and WebP only — SVG is excluded for the same reason the media rules
	 * exclude it (NFR-SEC-7): it is a document format that can carry script.
	 *
	 * @param string $value Candidate.
	 */
	private function is_data_uri( string $value ): bool {
		return 1 === preg_match( '#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#i', $value );
	}

	/**
	 * A CSS colour, or black.
	 *
	 * @param string $value Candidate colour.
	 */
	private function colour( string $value ): string {
		return 1 === preg_match( '/^#[0-9a-f]{3,8}$/i', $value ) ? $value : '#000000';
	}

	/**
	 * @param string $value Candidate alignment.
	 */
	private function alignment( string $value ): string {
		return in_array( $value, array( 'left', 'center', 'right' ), true ) ? $value : 'left';
	}

	/**
	 * @param float $value Value.
	 * @param float $min   Lower bound.
	 * @param float $max   Upper bound.
	 */
	private function clamp( float $value, float $min, float $max ): float {
		return max( $min, min( $max, $value ) );
	}

	/**
	 * A number for CSS, with no locale decimal comma.
	 *
	 * `(string) 1.5` honours the locale in some PHP builds and yields "1,5", which is not a
	 * CSS length — the field silently collapses to zero width. `number_format` with an
	 * explicit point cannot.
	 *
	 * @param float $value Value.
	 */
	private function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' ) ?: '0';
	}

	/**
	 * Bounds a page size must satisfy. See NFR-SEC-9.
	 *
	 * @param float $mm Millimetres.
	 */
	public static function is_valid_page_mm( float $mm ): bool {
		return $mm >= self::MIN_PAGE_MM && $mm <= self::MAX_PAGE_MM;
	}
}
