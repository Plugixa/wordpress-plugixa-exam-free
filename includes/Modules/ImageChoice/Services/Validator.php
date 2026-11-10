<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\ImageChoice\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuse a picture-choice question that could not be answered correctly.
 *
 * Both rules describe a question that SAVES, RENDERS and GRADES without error, and marks every
 * examinee wrong or misleads them. So they are refusals at authoring time with a sentence
 * saying what is wrong, rather than repairs.
 */
final class Validator {

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_validate_question', array( $this, 'validate' ), 10, 4 );
	}

	/**
	 * @param bool|WP_Error                    $valid      Current verdict.
	 * @param string                           $type       Question type.
	 * @param array<int, array<string, mixed>> $options    Clean options.
	 * @param array<string, mixed>             $answer_key Normalised key.
	 *
	 * @return bool|WP_Error
	 */
	public function validate( $valid, string $type, array $options, array $answer_key ) {
		// Somebody already refused it, or it is not ours. The first reason is the useful one;
		// piling a second on top tells the author less, not more.
		if ( is_wp_error( $valid ) || 'image_choice' !== $type ) {
			return $valid;
		}

		$correct = 0;

		foreach ( $options as $option ) {
			if ( ! empty( $option['is_correct'] ) ) {
				++$correct;
			}
		}

		if ( 0 === $correct ) {
			return new WP_Error(
				'plugixa_exam_needs_correct',
				__( 'Mark at least one option correct — nobody can pass this question until you do.', 'plugixa-exam' )
			);
		}

		/*
		 * A picture on EVERY option, not merely on one. A list where three choices are
		 * photographs and the fourth is blank tells the examinee which one is odd, and it is
		 * usually the one the author forgot rather than the answer — so the question is both
		 * unfair and misleading, in whichever direction.
		 */
		$images = (array) ( $answer_key['images'] ?? array() );

		foreach ( array_keys( array_values( $options ) ) as $index ) {
			if ( empty( $images[ $index + 1 ] ) ) {
				return new WP_Error(
					'plugixa_exam_needs_images',
					__( 'Every option needs a picture. Image choice is answered by looking at them.', 'plugixa-exam' )
				);
			}
		}

		return true;
	}
}
