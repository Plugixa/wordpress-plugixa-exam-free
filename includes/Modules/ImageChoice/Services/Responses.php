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
 * What a picture-choice answer is allowed to look like on its way into the database.
 *
 * WITHOUT THIS CLASS EVERY PICTURE-CHOICE ANSWER SCORES ZERO, SILENTLY. `AttemptService`
 * DISCARDS an answer whose type it does not recognise — deliberately, because an unvalidated
 * shape written straight to the database is stored user input nothing has checked — so the
 * module owning the type has to say what the answer looks like. Miss this hook and the type
 * registers, authors, renders and grades perfectly, and every examinee scores zero.
 */
final class Responses {

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_sanitize_response', array( $this, 'sanitize' ), 10, 3 );
	}

	/**
	 * @param mixed  $sanitized The cleaned answer so far, null by default.
	 * @param mixed  $answer    The raw answer.
	 * @param string $type      Question type.
	 */
	public function sanitize( mixed $sanitized, mixed $answer, string $type ): mixed {
		if ( 'image_choice' !== $type ) {
			// Not ours. Passing the accumulated value through matters — another module's type
			// goes through this same filter.
			return $sanitized;
		}

		// Identical to `mcq_multiple`: a list of chosen option ids. A single-answer question is
		// the same list with one entry, which keeps one grader rather than two that must agree.
		return array_values( array_unique( array_map( 'absint', (array) $answer ) ) );
	}
}
