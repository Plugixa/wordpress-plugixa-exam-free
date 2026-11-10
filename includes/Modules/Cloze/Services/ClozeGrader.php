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
 * Scoring for a cloze passage.
 *
 * Hooks `plugixa_exam_grade_response` and returns a FRACTION between 0 and 1, never a
 * points value. Core's {@see \PlugixaExam\Modules\Attempts\Services\Grader} owns points,
 * negative marking and the score floor; this owns "how much of the answer was right". Two
 * places computing points is how a module's type ends up ignoring a rule the core types obey.
 *
 * ────────────────────────────────────────────────────────────────────────────────────────
 * THE DENOMINATOR IS THE AUTHOR'S COUNT OF BLANKS, NOT THE EXAMINEE'S.
 *
 * That sounds obvious and is the single easiest thing to get wrong. Score a 5-blank passage
 * out of however many blanks the examinee filled in, and somebody who answers one blank
 * correctly and skips four scores 100%. The denominator must come from the question.
 *
 * The mirror-image bug is just as easy: counting the examinee's EXTRA answers. A response is
 * a map, and a client can send more keys than there are blanks. So this walks the AUTHOR's
 * blanks and looks up the examinee's answer, never the other way round.
 * ────────────────────────────────────────────────────────────────────────────────────────
 */
final class ClozeGrader {

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_grade_response', array( $this, 'grade' ), 10, 5 );
	}

	/**
	 * @param float|null                       $fraction Current value.
	 * @param string                           $type     Question type.
	 * @param array<string, mixed>             $key      The answer key.
	 * @param array<int, array<string, mixed>> $options  Canonical options (a cloze has none).
	 * @param mixed                            $response The answer.
	 */
	public function grade( $fraction, string $type, array $key, array $options, mixed $response ): ?float {
		unset( $options );

		if ( 'cloze' !== $type ) {
			// Another module's type. Pass its value through untouched.
			return null === $fraction ? null : (float) $fraction;
		}

		return $this->finish( $this->cloze( $key, $response ), $key );
	}

	/**
	 * Turn a correct/total tally into a fraction, applying the partial-credit policy.
	 *
	 * A passage with no blanks at all scores 0 rather than dividing by zero. That is an author
	 * error, and zero is the honest answer: there was nothing to get right. Returning 1 would
	 * award full marks for a question nobody could answer.
	 *
	 * @param array{correct: int, total: int} $tally Blanks right, blanks asked.
	 * @param array<string, mixed>            $key   The answer key.
	 */
	private function finish( array $tally, array $key ): float {
		if ( $tally['total'] < 1 ) {
			return 0.0;
		}

		if ( empty( $key['partial_credit'] ) ) {
			return $tally['correct'] === $tally['total'] ? 1.0 : 0.0;
		}

		return $tally['correct'] / $tally['total'];
	}

	/**
	 * Each blank matched against its own accepted values.
	 *
	 * The response is `{ blankIndex: text }`. Each blank carries its own case and trim
	 * settings, so a passage can accept "Paris" loosely in one gap and demand an exact
	 * chemical formula in another.
	 *
	 * @param array<string, mixed> $key      The answer key.
	 * @param mixed                $response `{ blankIndex: text }`.
	 *
	 * @return array{correct: int, total: int}
	 */
	private function cloze( array $key, mixed $response ): array {
		$response = is_array( $response ) ? $response : array();
		$blanks   = (array) ( $key['blanks'] ?? array() );
		$correct  = 0;

		foreach ( array_values( $blanks ) as $index => $blank ) {
			if ( ! array_key_exists( $index, $response ) ) {
				continue;
			}

			if ( $this->blank_matches( is_array( $blank ) ? $blank : array(), (string) $response[ $index ] ) ) {
				++$correct;
			}
		}

		return array(
			'correct' => $correct,
			'total'   => count( $blanks ),
		);
	}

	/**
	 * Whether one answer matches one blank.
	 *
	 * `mb_strtolower` rather than `strtolower`: an accented answer folded byte-wise does not
	 * match the same word typed in a different case, and a language test is exactly where
	 * that shows up.
	 *
	 * @param array<string, mixed> $blank The blank's settings.
	 * @param string               $given The examinee's answer.
	 */
	private function blank_matches( array $blank, string $given ): bool {
		$accepted = (array) ( $blank['accepted'] ?? array() );

		if ( array() === $accepted ) {
			return false;
		}

		$fold = ! isset( $blank['case_insensitive'] ) || ! empty( $blank['case_insensitive'] );
		$trim = ! isset( $blank['trim'] ) || ! empty( $blank['trim'] );

		$candidate = $trim ? trim( $given ) : $given;

		if ( '' === trim( $candidate ) ) {
			return false;
		}

		if ( $fold ) {
			$candidate = mb_strtolower( $candidate );
		}

		foreach ( $accepted as $value ) {
			$value = (string) $value;
			$value = $trim ? trim( $value ) : $value;
			$value = $fold ? mb_strtolower( $value ) : $value;

			if ( $candidate === $value ) {
				return true;
			}
		}

		return false;
	}
}
