<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\ImageChoice\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picture choice when JavaScript is switched off (FR-RUN-10): checkboxes with pictures in the
 * labels.
 *
 * The markup goes back through `wp_kses` on the `form` allow-list, which carries `img` for
 * exactly this reason. It did not always: this file's premium predecessor said it did, the
 * allow-list had no `img`, and every picture was stripped from the no-JavaScript paper — the
 * one path somebody lands on precisely when nothing else loaded.
 */
final class NoJsInput {

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_no_js_input', array( $this, 'render' ), 10, 4 );
	}

	/**
	 * Checkboxes whose labels are pictures.
	 *
	 * Checkboxes rather than radios even when one option is correct: the runner's JavaScript
	 * control is a multi-select too, and the grader treats a single answer as a list of one. A
	 * radio here would let an examinee who started on a scripted machine and finished on an
	 * unscripted one lose a selection they had already made.
	 *
	 * @param string               $html     Markup so far.
	 * @param array<string, mixed> $question The question being rendered.
	 * @param string               $name     Field name, e.g. `answers[12]`.
	 * @param mixed                $response The saved answer, if any.
	 */
	public function render( string $html, array $question, string $name, mixed $response ): string {
		// Somebody already drew it, or it is not ours. Two modules answering for one question
		// would concatenate two sets of controls submitting under the same name.
		if ( '' !== $html || 'image_choice' !== (string) ( $question['type'] ?? '' ) ) {
			return $html;
		}

		$images = (array) ( $question['images'] ?? array() );
		$chosen = array_map( 'intval', (array) $response );

		$html = '<fieldset class="pxe-visual"><legend class="screen-reader-text">'
			. esc_html__( 'Choose one or more', 'plugixa-exam' ) . '</legend><ul class="pxe-visual__choices">';

		foreach ( (array) ( $question['options'] ?? array() ) as $option ) {
			$id      = (int) ( $option['id'] ?? 0 );
			$src     = (string) ( $images[ $id ] ?? '' );
			$caption = wp_strip_all_tags( (string) ( $option['text'] ?? '' ) );

			$html .= '<li class="pxe-visual__choice"><label class="pxe-visual__label">'
				. '<input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( (string) $id ) . '"'
				. ( in_array( $id, $chosen, true ) ? ' checked' : '' ) . '>';

			if ( '' !== $src ) {
				/*
				 * The caption is the alt text. An option whose picture fails to load must still
				 * be answerable, and a screen-reader user has nothing else to go on — an empty
				 * alt here would make the whole question unanswerable for them rather than
				 * merely harder.
				 */
				$html .= '<img class="pxe-visual__image" src="' . esc_url( $src ) . '" alt="'
					. esc_attr( $caption ) . '" loading="lazy">';
			}

			$html .= '<span class="pxe-visual__caption">' . esc_html( $caption ) . '</span></label></li>';
		}

		return $html . '</ul></fieldset>';
	}
}
