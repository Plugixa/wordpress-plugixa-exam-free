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
 * The certificate a site has before anybody designs one.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * WITHOUT THIS, A SITE THAT PASSES SOMEBODY AWARDS THEM NOTHING, SILENTLY.
 *
 * `Issuer::issue()` asks `TemplateRepository::resolve()` for a design and returns null when
 * there is none — deliberately, because a certificate nobody can render would show up in the
 * learner's portal as a broken download. That was survivable while certificates were premium
 * and arrived with a drag-and-drop canvas: the first thing anybody did was draw one.
 *
 * It is not survivable now. The canvas is the premium half, so on a free site nothing would
 * ever create the first row, `resolve()` would answer null for ever, and the module would
 * look switched on and award nothing. The failure is invisible: no error, no notice, just an
 * empty "My certificates" page on a site whose owner believes it is issuing.
 *
 * So the free build ships a certificate rather than the means to draw one. One design, A4
 * landscape, seeded when the table is empty.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * THE STATIC TEXT IS TRANSLATED AT SEED TIME AND THEN IT IS THE SITE'S. Everything here goes
 * through `__()`, so a French site is seeded in French — but the row is DATA from that moment
 * on, and changing the site's language later does not rewrite somebody's certificate. That is
 * the same trade the notification templates make, and it is the right way round: an
 * administrator who edits the wording must not have it silently reverted.
 *
 * FIELD NAMES ARE THE CONTRACT WITH `Issuer::values()`. A field named there is filled in; a
 * field named anything else falls back to its own `content`, which is how the three headings
 * below print fixed words. Positions are millimetres from the top-left of the page, which is
 * what {@see Renderer} lays out in and what the canvas edits.
 */
final class DefaultDesign {

	/**
	 * The row to insert.
	 *
	 * @return array<string, mixed>
	 */
	public static function row(): array {
		return array(
			'title'         => __( 'Standard certificate', 'plugixa-exam' ),
			'description'   => __( 'The certificate this site awards. Edit it, or replace its layout entirely.', 'plugixa-exam' ),
			'design_json'   => (string) wp_json_encode( self::template() ),
			'page_size'     => 'A4',
			'orientation'   => 'landscape',
			'valid_days'    => 0,
			'number_format' => 'CERT-{Y}-{seq:5}',
			'published'     => 1,
		);
	}

	/**
	 * The layout, in the shape the canvas and the renderer both read.
	 *
	 * @return array<string, mixed>
	 */
	private static function template(): array {
		return array(
			'schemas' => array(
				array(
					'heading'        => self::text(
						24.0,
						32.0,
						249.0,
						16.0,
						30.0,
						array(
							'bold'    => true,
							'colour'  => '#1a1a1a',
							'content' => __( 'Certificate of Achievement', 'plugixa-exam' ),
						)
					),
					'intro'          => self::text(
						24.0,
						60.0,
						249.0,
						8.0,
						12.0,
						array(
							'colour'  => '#555555',
							'content' => __( 'This is to certify that', 'plugixa-exam' ),
						)
					),
					'recipient_name' => self::text( 24.0, 72.0, 249.0, 16.0, 26.0, array( 'bold' => true ) ),
					'completed'      => self::text(
						24.0,
						96.0,
						249.0,
						8.0,
						12.0,
						array(
							'colour'  => '#555555',
							'content' => __( 'has successfully completed', 'plugixa-exam' ),
						)
					),
					'exam_title'     => self::text( 24.0, 108.0, 249.0, 12.0, 18.0, array( 'bold' => true ) ),
					'percent'        => self::text( 24.0, 126.0, 249.0, 10.0, 14.0, array( 'colour' => '#555555' ) ),

					/*
					 * The footer reads left to right as the three questions somebody holding
					 * a certificate asks: who says so, when, and how do I check.
					 */
					'site_name'      => self::text(
						24.0,
						166.0,
						120.0,
						8.0,
						12.0,
						array(
							'bold'  => true,
							'align' => 'left',
						)
					),
					'issued_date'    => self::text(
						24.0,
						175.0,
						120.0,
						7.0,
						10.0,
						array(
							'align'  => 'left',
							'colour' => '#555555',
						)
					),
					'number'         => self::text(
						148.0,
						175.0,
						100.0,
						7.0,
						10.0,
						array(
							'align'  => 'right',
							'colour' => '#555555',
						)
					),
					'verify_url'     => self::text(
						118.0,
						184.0,
						130.0,
						6.0,
						8.0,
						array(
							'align'  => 'right',
							'colour' => '#777777',
						)
					),
					'verify_qr'      => array(
						'type'     => 'image',
						'position' => array(
							'x' => 253.0,
							'y' => 164.0,
						),
						'width'    => 20.0,
						'height'   => 20.0,
					),
				),
			),
		);
	}

	/**
	 * One text field.
	 *
	 * @param float                $x        Millimetres from the left edge.
	 * @param float                $y        Millimetres from the top edge.
	 * @param float                $width    Millimetres.
	 * @param float                $height   Millimetres.
	 * @param float                $size     Point size.
	 * @param array<string, mixed> $extras   Optional `bold`, `align`, `colour`, `content`.
	 *
	 * @return array<string, mixed>
	 */
	private static function text( float $x, float $y, float $width, float $height, float $size, array $extras = array() ): array {
		$field = array(
			'type'      => 'text',
			'position'  => array(
				'x' => $x,
				'y' => $y,
			),
			'width'     => $width,
			'height'    => $height,
			'fontSize'  => $size,
			'alignment' => (string) ( $extras['align'] ?? 'center' ),
			'fontColor' => (string) ( $extras['colour'] ?? '#111111' ),
		);

		if ( ! empty( $extras['bold'] ) ) {
			$field['bold'] = true;
		}

		if ( isset( $extras['content'] ) ) {
			$field['content'] = (string) $extras['content'];
		}

		return $field;
	}
}
