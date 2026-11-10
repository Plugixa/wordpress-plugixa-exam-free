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
 * What a cloze question tells the examinee's browser about its own shape.
 *
 * ────────────────────────────────────────────────────────────────────────────────────────
 * THE ONE RULE: SHAPE, NEVER ANSWERS.
 *
 * A cloze passage cannot be rendered from its options alone — it has none. The runner needs
 * to know that the passage has five gaps, and the blanks that define them live in
 * `answer_key` beside the accepted answers.
 *
 * So this returns a COUNT and nothing else. `accepted` never leaves the server. Getting this
 * wrong does not fail a test or throw an error — it puts every answer to a live exam in the
 * page source, where View Source finds it, and nobody notices until the results look
 * implausible.
 * ────────────────────────────────────────────────────────────────────────────────────────
 */
final class RunnerPayload {

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_runner_question', array( $this, 'shape' ), 10, 3 );
	}

	/**
	 * @param array<string, mixed>             $payload  What the runner will receive.
	 * @param array<string, mixed>             $question The full question row, answer key included.
	 * @param array<int, array<string, mixed>> $options  The full option rows.
	 *
	 * @return array<string, mixed>
	 */
	public function shape( array $payload, array $question, array $options = array() ): array {
		unset( $options );

		if ( 'cloze' !== (string) ( $payload['type'] ?? '' ) ) {
			return $payload;
		}

		/*
		 * The number of gaps is taken from the STEM's markers, not from the number of blanks
		 * defined. They can disagree — an author adds `[[6]]` to the passage and has not
		 * defined its blank yet — and when they do, the passage is what the examinee is
		 * reading. Rendering four inputs for five visible gaps is a paper that cannot be
		 * answered.
		 */
		$payload['blanks'] = AnswerKeys::count_markers( (string) ( $question['stem'] ?? '' ) );

		return $payload;
	}
}
