<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Cloze\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A cloze passage, drawn with no JavaScript at all.
 *
 * The runner degrades to a native form POST, and that fallback is not decoration. It is what
 * an examinee gets when a bundle fails to load on a room's shared wifi, when a school network
 * strips scripts, or when they are using assistive technology the enhanced runner does not
 * suit. An exam is a high-stakes, time-limited event; "reload and hope" is not a recovery.
 *
 * The boxes post in exactly the shape {@see Responses} expects — the same shape the React
 * runner sends — so grading cannot diverge between the two paths.
 *
 * ESCAPING: every value is escaped here, at the point it enters the markup. The template then
 * runs the whole string through `wp_kses` with the `form` allow-list. Two passes, because this
 * is one of the few places a module hands a template raw HTML.
 */
final class NoJsInput {

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_no_js_input', array( $this, 'render' ), 10, 4 );
	}

	/**
	 * One text box per gap, numbered to match the passage.
	 *
	 * The boxes sit BELOW the passage rather than inside it. Splicing inputs into the stem
	 * would mean parsing author HTML and re-emitting it, which is a sanitising problem for a
	 * marginal layout gain — and the numbered labels make the correspondence explicit, which
	 * a screen reader handles better than an input floating mid-sentence.
	 *
	 * @param string               $html     Markup so far.
	 * @param array<string, mixed> $question The question being rendered.
	 * @param string               $name     Field name, e.g. `answers[12]`.
	 * @param mixed                $response The saved answer, if any.
	 */
	public function render( string $html, array $question, string $name, mixed $response ): string {
		if ( 'cloze' !== (string) ( $question['type'] ?? '' ) ) {
			return $html;
		}

		$saved  = is_array( $response ) ? $response : array();
		$blanks = (int) ( $question['blanks'] ?? 0 );

		if ( $blanks < 1 ) {
			return '';
		}

		$html = '<ol class="pxe-blanks">';

		for ( $index = 0; $index < $blanks; $index++ ) {
			$field   = $name . '[' . $index . ']';
			$current = isset( $saved[ $index ] ) ? (string) $saved[ $index ] : '';
			$label   = sprintf(
				/* translators: %d: which gap in the passage, counting from one. */
				__( 'Blank %d', 'plugixa-exam' ),
				$index + 1
			);

			$html .= '<li>'
				. '<label class="pxe-field">'
				. '<span class="pxe-field__label">' . esc_html( $label ) . '</span>'
				. '<input class="pxe-field__input" type="text" autocomplete="off" maxlength="500"'
				. ' name="' . esc_attr( $field ) . '"'
				. ' value="' . esc_attr( $current ) . '">'
				. '</label></li>';
		}

		return $html . '</ol>';
	}
}
