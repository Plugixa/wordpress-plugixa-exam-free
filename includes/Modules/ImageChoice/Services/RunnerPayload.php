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
 * What a picture-choice question tells the examinee's browser: which picture goes with which
 * option, and nothing else.
 *
 * Correctness is `question_options.is_correct`, which never reaches the runner, so the only
 * thing added here is `images` — `{ optionId: url }`. The pictures are read back by the
 * option's `ordering` rather than its array index, because the options have already been
 * shuffled by the time this runs; {@see QuestionImages::by_option()} owns that rule.
 */
final class RunnerPayload {

	/**
	 * @param QuestionImages $images Option pictures, published by option id.
	 */
	public function __construct( private readonly QuestionImages $images ) {}

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
		if ( 'image_choice' !== (string) ( $payload['type'] ?? '' ) ) {
			return $payload;
		}

		$key = $question['answer_key'] ?? array();
		$key = is_array( $key ) ? $key : (array) json_decode( (string) $key, true );

		$payload['images'] = $this->images->by_option( $key, $options );

		return $payload;
	}
}
