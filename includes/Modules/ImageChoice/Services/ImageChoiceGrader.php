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
 * Scoring for picture choice: multiple choice, with pictures instead of words.
 *
 * Hooked to `plugixa_exam_grade_response`, which `Grader` fires for any type it does not
 * handle itself. The contract is a FRACTION — or null to leave the response for a human, which
 * is what this passes through for anything that is not its own.
 *
 * `Grader::award()` turns the fraction into points and applies `negative_marking` and
 * `min_score`, so nothing here multiplies by anything or clamps a final score. Returning a
 * fraction below zero is how negative marking is expressed, not a bug.
 *
 * DELIBERATELY THE SAME ARITHMETIC AS `Grader::grade_mcq_multiple()`. The pictures change what
 * the examinee looks at and nothing about what counts as right, so a second scoring rule would
 * be a second thing to keep in agreement for no gain.
 */
final class ImageChoiceGrader {

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_grade_response', array( $this, 'grade' ), 10, 5 );
	}

	/**
	 * @param float|null                       $fraction The fraction earned so far, or null.
	 * @param string                           $type     Question type.
	 * @param array<string, mixed>             $key      The answer key.
	 * @param array<int, array<string, mixed>> $options  Canonical options.
	 * @param mixed                            $response The answer.
	 */
	public function grade( ?float $fraction, string $type, array $key, array $options, mixed $response ): ?float {
		if ( 'image_choice' !== $type ) {
			// Not ours. Passing the accumulated value through matters — another module's type
			// goes through this same filter.
			return $fraction;
		}

		$selected = array_map( 'intval', (array) $response );

		$correct_ids   = array();
		$incorrect_ids = array();

		foreach ( $options as $option ) {
			if ( ! empty( $option['is_correct'] ) ) {
				$correct_ids[] = (int) $option['id'];
			} else {
				$incorrect_ids[] = (int) $option['id'];
			}
		}

		if ( array() === $correct_ids ) {
			return 0.0;
		}

		$hits   = count( array_intersect( $selected, $correct_ids ) );
		$misses = count( array_intersect( $selected, $incorrect_ids ) );

		if ( 'proportional' !== ( $key['partial_policy'] ?? 'all_or_nothing' ) ) {
			if ( $hits === count( $correct_ids ) && 0 === $misses ) {
				return 1.0;
			}

			return empty( $key['negative_marking'] )
				? 0.0
				: -1.0 * ( $misses / count( $correct_ids ) );
		}

		$fraction = ( $hits - $misses ) / count( $correct_ids );

		return empty( $key['negative_marking'] ) ? max( 0.0, $fraction ) : $fraction;
	}
}
