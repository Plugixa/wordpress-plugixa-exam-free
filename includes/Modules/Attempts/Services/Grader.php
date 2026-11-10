<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores one response.
 *
 * STATELESS AND DATABASE-FREE, on purpose. Everything it needs arrives as arguments, so it
 * can be exercised by a table-driven test with no fixtures — which matters more here than
 * anywhere else in the plugin, because a grading bug does not throw an exception. It
 * produces a plausible number, and somebody fails an exam they passed.
 *
 * ────────────────────────────────────────────────────────────────────────────────────────
 * THE RULE THAT CATCHES THE MOST BUGS, STATED FIRST:
 *
 * An UNANSWERED auto-gradable question scores zero, checked once, here, before any
 * type-specific grader runs.
 *
 * The system this ports had eight graders each coercing a missing response into a value and
 * comparing it. `gradeTrueFalse()` ran `toBool(null) === false`, so EVERY blank True/False
 * whose correct answer was "False" was marked correct and awarded full marks. Nobody
 * noticed, because a blank paper scoring well looks like a lenient exam rather than a bug.
 *
 * And the converse matters just as much: `false`, `0` and `"0"` are ANSWERS. Treating them
 * as empty — which `empty()` does — marks a correct "False" as unanswered.
 * ────────────────────────────────────────────────────────────────────────────────────────
 */
final class Grader {

	/**
	 * Score a response.
	 *
	 * @param string                          $type    Question type.
	 * @param array<string, mixed>            $key     The question's normalised answer key.
	 * @param array<int, array<string, mixed>> $options The question's canonical options.
	 * @param mixed                           $response The examinee's answer, decoded.
	 * @param float                           $points  Points this question is worth here.
	 * @param bool                            $auto    Whether this type is auto-graded.
	 *
	 * @return array{is_correct: ?int, points_awarded: ?float, auto_graded: int}
	 */
	public function grade( string $type, array $key, array $options, mixed $response, float $points, bool $auto ): array {
		if ( ! $auto ) {
			// Essay, or a type this build does not understand. Left for a human — NOT
			// scored zero, which would silently fail every examinee on a question the site
			// merely lacks a module for.
			return array(
				'is_correct'     => null,
				'points_awarded' => null,
				'auto_graded'    => 0,
			);
		}

		if ( self::is_unanswered( $response ) ) {
			return array(
				'is_correct'     => 0,
				'points_awarded' => 0.0,
				'auto_graded'    => 1,
			);
		}

		switch ( $type ) {
			case 'mcq_single':
				return $this->award( $this->grade_mcq_single( $options, $response ), $points );

			case 'mcq_multiple':
				return $this->award( $this->grade_mcq_multiple( $options, $response, $key ), $points, $key );

			case 'true_false':
				return $this->award( $this->grade_true_false( $response, $key ), $points );

			case 'short_answer':
				return $this->award( $this->grade_short_answer( $response, $key ), $points );

			default:
				/**
				 * Score a type this build does not handle natively.
				 *
				 * Premium types hook this and return a fraction of the available points
				 * between 0 and 1. Returning null leaves the response for a human, which is
				 * the safe direction for anything unrecognised.
				 *
				 * @param float|null           $fraction The fraction earned, or null.
				 * @param string               $type     Question type.
				 * @param array<string, mixed> $key      The answer key.
				 * @param array<int, mixed>    $options  Canonical options.
				 * @param mixed                $response The answer.
				 */
				$fraction = apply_filters( 'plugixa_exam_grade_response', null, $type, $key, $options, $response );

				if ( null === $fraction ) {
					return array(
						'is_correct'     => null,
						'points_awarded' => null,
						'auto_graded'    => 0,
					);
				}

				return $this->award( (float) $fraction, $points, $key );
		}
	}

	/**
	 * Whether a response counts as "not given".
	 *
	 * NOT `empty()`. `false` is a valid True/False answer, `0` is a valid option index and
	 * `"0"` is a valid short answer — `empty()` calls all three blank, and marks a correct
	 * answer unanswered.
	 *
	 * @param mixed $response The answer.
	 */
	public static function is_unanswered( mixed $response ): bool {
		if ( null === $response ) {
			return true;
		}

		if ( is_string( $response ) ) {
			return '' === trim( $response );
		}

		if ( is_array( $response ) ) {
			return array() === $response;
		}

		// A bool, an int or a float is an answer, including false and 0.
		return false;
	}

	/**
	 * Turn a fraction into a score, applying negative marking and the floor.
	 *
	 * @param float|null           $fraction Fraction of the available points, or null.
	 * @param float                $points   Points available.
	 * @param array<string, mixed> $key      The answer key, for `min_score`.
	 *
	 * @return array{is_correct: ?int, points_awarded: ?float, auto_graded: int}
	 */
	private function award( ?float $fraction, float $points, array $key = array() ): array {
		if ( null === $fraction ) {
			return array(
				'is_correct'     => null,
				'points_awarded' => null,
				'auto_graded'    => 0,
			);
		}

		$awarded = round( $fraction * $points, 2 );

		// The floor applies to the QUESTION, not to the paper. A per-question floor of 0 is
		// the default, so negative marking cannot drag a total below what the other
		// questions earned unless the author asks for it.
		$floor = isset( $key['min_score'] ) ? (float) $key['min_score'] : 0.0;

		if ( $awarded < $floor ) {
			$awarded = $floor;
		}

		return array(
			// "Correct" means full marks. A partially credited answer is neither correct nor
			// wrong, and the review screen renders it as "Partial" — reporting it as correct
			// would tell an examinee they got it right when they did not.
			'is_correct'     => $awarded >= $points && $points > 0 ? 1 : 0,
			'points_awarded' => $awarded,
			'auto_graded'    => 1,
		);
	}

	/**
	 * One correct option.
	 *
	 * @param array<int, array<string, mixed>> $options  Canonical options.
	 * @param mixed                            $response Selected option id.
	 */
	private function grade_mcq_single( array $options, mixed $response ): float {
		$selected = $this->single_id( $response );

		foreach ( $options as $option ) {
			if ( (int) $option['id'] === $selected ) {
				return ! empty( $option['is_correct'] ) ? 1.0 : 0.0;
			}
		}

		// An id that is not among this question's options. Wrong, not an error — an attempt
		// started before the question was edited can legitimately carry a stale id.
		return 0.0;
	}

	/**
	 * One or more correct options.
	 *
	 * @param array<int, array<string, mixed>> $options  Canonical options.
	 * @param mixed                            $response Selected option ids.
	 * @param array<string, mixed>             $key      Answer key.
	 */
	private function grade_mcq_multiple( array $options, mixed $response, array $key ): float {
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
			// A question with no correct option cannot be answered correctly. Authoring
			// refuses to save one; this is the guard for rows that predate that check.
			return 0.0;
		}

		$hits   = count( array_intersect( $selected, $correct_ids ) );
		$misses = count( array_intersect( $selected, $incorrect_ids ) );

		$exact = $hits === count( $correct_ids ) && 0 === $misses;

		if ( 'proportional' !== ( $key['partial_policy'] ?? 'all_or_nothing' ) ) {
			if ( $exact ) {
				return 1.0;
			}

			// All-or-nothing still honours negative marking when it is on: selecting three
			// wrong options should not cost the same as leaving the question blank.
			return empty( $key['negative_marking'] )
				? 0.0
				: -1.0 * ( $misses / count( $correct_ids ) );
		}

		/*
		 * Proportional: the fraction of correct options found, less the incorrect ones.
		 * Floored at zero UNLESS negative marking is on — that is the whole difference
		 * between the two, and the reason `negative_marking` is not merely decorative here.
		 */
		$fraction = ( $hits - $misses ) / count( $correct_ids );

		return empty( $key['negative_marking'] ) ? max( 0.0, $fraction ) : $fraction;
	}

	/**
	 * True or false.
	 *
	 * @param mixed                $response The answer.
	 * @param array<string, mixed> $key      Answer key.
	 */
	private function grade_true_false( mixed $response, array $key ): float {
		$given = $this->to_bool( $response );

		return $given === (bool) ( $key['correct'] ?? false ) ? 1.0 : 0.0;
	}

	/**
	 * Free text matched against accepted values.
	 *
	 * @param mixed                $response The answer.
	 * @param array<string, mixed> $key      Answer key.
	 */
	private function grade_short_answer( mixed $response, array $key ): float {
		$given = is_scalar( $response ) ? (string) $response : '';

		$accepted = (array) ( $key['accepted'] ?? array() );

		if ( array() === $accepted ) {
			return 0.0;
		}

		$trim      = ! isset( $key['trim'] ) || ! empty( $key['trim'] );
		$fold_case = ! isset( $key['case_insensitive'] ) || ! empty( $key['case_insensitive'] );
		$numeric   = ! empty( $key['numeric'] );
		$regex     = ! empty( $key['use_regex'] );
		$tolerance = (float) ( $key['tolerance'] ?? 0 );

		$candidate = $trim ? trim( $given ) : $given;

		foreach ( $accepted as $entry ) {
			$entry = (string) $entry;

			if ( $numeric ) {
				// Both sides must actually be numbers. Comparing "twelve" to 12 with a
				// tolerance would otherwise coerce to 0 and match anything within it.
				if ( ! is_numeric( $candidate ) || ! is_numeric( $entry ) ) {
					continue;
				}

				if ( abs( (float) $candidate - (float) $entry ) <= $tolerance ) {
					return 1.0;
				}

				continue;
			}

			if ( $regex ) {
				$pattern = '/' . str_replace( '/', '\/', $entry ) . '/' . ( $fold_case ? 'i' : '' );

				/*
				 * Patterns are validated at authoring time (see `QuestionTypes`), so a bad
				 * one should not reach here — but a question written before that check
				 * existed still might, and `preg_match` returning false must mean "no
				 * match", never "correct".
				 */
				if ( ! plugixa_exam_is_valid_regex( $pattern ) ) {
					continue;
				}

				if ( 1 === preg_match( $pattern, $candidate ) ) {
					return 1.0;
				}

				continue;
			}

			$expected = $trim ? trim( $entry ) : $entry;

			$matched = $fold_case
				? 0 === strcasecmp( $candidate, $expected )
				: $candidate === $expected;

			if ( $matched ) {
				return 1.0;
			}
		}

		return 0.0;
	}

	/**
	 * Coerce a response to a single option id.
	 *
	 * @param mixed $response The answer.
	 */
	private function single_id( mixed $response ): int {
		if ( is_array( $response ) ) {
			$first = reset( $response );

			return is_scalar( $first ) ? (int) $first : 0;
		}

		return is_scalar( $response ) ? (int) $response : 0;
	}

	/**
	 * Coerce a response to a boolean.
	 *
	 * Explicit about the string forms because a JSON body may carry `"false"`, which is a
	 * truthy string in PHP — the single most common way a False answer is graded as True.
	 *
	 * @param mixed $response The answer.
	 */
	private function to_bool( mixed $response ): bool {
		if ( is_bool( $response ) ) {
			return $response;
		}

		if ( is_string( $response ) ) {
			return in_array( strtolower( trim( $response ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		if ( is_array( $response ) ) {
			$first = reset( $response );

			return $this->to_bool( $first );
		}

		return (bool) $response;
	}
}
