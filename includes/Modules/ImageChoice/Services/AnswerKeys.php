<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\ImageChoice\Services;

use PlugixaExam\Modules\Media\Services\QuestionImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a picture-choice answer key is allowed to contain.
 *
 *   image_choice { partial_policy, negative_marking, min_score, images: { position: id } }
 *
 * Correctness is `question_options.is_correct`, exactly as for multiple choice. This key
 * carries only the pictures and the credit policy — and the pictures are keyed by 1-based
 * option POSITION, never by option id, for the reason {@see QuestionImages} gives.
 */
final class AnswerKeys {

	/**
	 * @param QuestionImages $images Option pictures, stored by position.
	 */
	public function __construct( private readonly QuestionImages $images ) {}

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_normalize_answer_key', array( $this, 'normalize' ), 10, 2 );
	}

	/**
	 * @param array<string, mixed> $key  The raw answer key.
	 * @param string               $type The question type.
	 *
	 * @return array<string, mixed>
	 */
	public function normalize( array $key, string $type ): array {
		if ( 'image_choice' !== $type ) {
			// Not ours. Returning the key untouched matters — every other type passes through
			// this same filter.
			return $key;
		}

		return array(
			'partial_policy'   => 'proportional' === ( $key['partial_policy'] ?? '' )
				? 'proportional'
				: 'all_or_nothing',
			'negative_marking' => ! empty( $key['negative_marking'] ),
			'min_score'        => (float) ( $key['min_score'] ?? 0 ),
			'images'           => $this->images->normalize( $key ),
		);
	}
}
