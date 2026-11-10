<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Questions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which question types exist, and what each one's answer key means.
 *
 * THE SERVER-SIDE HALF OF THE SEAM EVERY OTHER TYPE ARRIVES THROUGH. This class hardcodes the
 * five basic types. The free `Cloze` module adds fill in the blanks, and premium modules add
 * ordering, matching, EMQ and the rest — all through the same filter an add-on would use.
 * Nothing here branches on "is pro"; the editor, the validator and the grader all read
 * whatever is registered.
 *
 * `option_editor` SAYS HOW THE OPTION LIST IS EDITED, and exists so that the authoring screen
 * never has to name a type. Marking a correct answer, dragging a sequence into order and
 * naming what each item pairs with are three different jobs, and the type knows which one it
 * needs — Core does not. Values: `correct_single`, `correct_multiple`, `sequence`, `match`,
 * `bank`. Anything unrecognised falls back to `correct_single`, which is safe: it shows one
 * more control than the type needs rather than one fewer.
 *
 * ONE PROPERTY MATTERS ABOVE THE REST: a type that is no longer registered must not break
 * the questions already written in it. Downgrading from premium to free leaves ordering
 * questions in the bank, and those questions must still render, still export and still
 * grade. So `is_registered()` gates CREATING a question, never reading one, and
 * {@see normalize()} passes an unknown type's key through untouched rather than discarding
 * it — discarding would silently destroy the answer key on the next save.
 */
final class QuestionTypes {

	/**
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $types = null;

	/**
	 * Every registered type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null !== $this->types ) {
			return $this->types;
		}

		$types = array(
			'mcq_single'   => array(
				'label'         => __( 'Multiple choice (single answer)', 'plugixa-exam' ),
				'description'   => __( 'One correct answer chosen from several options.', 'plugixa-exam' ),
				'graded_by'     => 'auto',
				'options'       => 'required',
				'option_editor' => 'correct_single',
				'order'         => 10,
			),
			'mcq_multiple' => array(
				'label'         => __( 'Multiple choice (multiple answers)', 'plugixa-exam' ),
				'description'   => __( 'One or more correct answers, with partial-credit options.', 'plugixa-exam' ),
				'graded_by'     => 'auto',
				'options'       => 'required',
				'option_editor' => 'correct_multiple',
				'order'         => 20,
			),
			'true_false'   => array(
				'label'       => __( 'True / False', 'plugixa-exam' ),
				'description' => __( 'A single true-or-false statement.', 'plugixa-exam' ),
				'graded_by'   => 'auto',
				'options'     => 'none',
				'order'       => 30,
			),
			'short_answer' => array(
				'label'       => __( 'Short answer', 'plugixa-exam' ),
				'description' => __( 'Free-text answer matched against accepted values.', 'plugixa-exam' ),
				'graded_by'   => 'auto',
				'options'     => 'none',
				'order'       => 40,
			),
			'essay'        => array(
				'label'       => __( 'Essay', 'plugixa-exam' ),
				'description' => __( 'Long-form written response, graded manually.', 'plugixa-exam' ),
				'graded_by'   => 'manual',
				'options'     => 'none',
				'order'       => 50,
			),
		);

		/**
		 * Register a question type.
		 *
		 * @param array<string, array<string, mixed>> $types Accumulated types.
		 */
		$this->types = (array) apply_filters( 'plugixa_exam_question_types', $types );

		uasort(
			$this->types,
			static fn( array $a, array $b ): int => ( $a['order'] ?? 100 ) <=> ( $b['order'] ?? 100 )
		);

		return $this->types;
	}

	/**
	 * @param string $type Type key.
	 */
	public function is_registered( string $type ): bool {
		return isset( $this->all()[ $type ] );
	}

	/**
	 * Whether a type is scored without a human.
	 *
	 * Defaults to manual for an unknown type — the safe direction. Auto-grading something
	 * this build does not understand would score it zero; queueing it for a human is
	 * merely slower.
	 *
	 * @param string $type Type key.
	 */
	public function is_auto_graded( string $type ): bool {
		return 'auto' === ( $this->all()[ $type ]['graded_by'] ?? 'manual' );
	}

	/**
	 * Whether a type stores rows in `question_options`.
	 *
	 * @param string $type Type key.
	 *
	 * @return string `required`, `optional` or `none`.
	 */
	public function options_mode( string $type ): string {
		return (string) ( $this->all()[ $type ]['options'] ?? 'none' );
	}

	/**
	 * How this type's option list is edited.
	 *
	 * @param string $type Type key.
	 *
	 * @return string One of `correct_single`, `correct_multiple`, `sequence`, `match`, `bank`.
	 */
	public function option_editor( string $type ): string {
		return (string) ( $this->all()[ $type ]['option_editor'] ?? 'correct_single' );
	}

	/**
	 * Coerce a raw answer key into the shape its type expects.
	 *
	 * @param string               $type Type key.
	 * @param array<string, mixed> $key  Raw key from the request.
	 *
	 * @return array<string, mixed>
	 */
	public function normalize( string $type, array $key ): array {
		switch ( $type ) {
			case 'mcq_single':
				// Correctness lives on the options themselves; the key holds nothing.
				return array();

			case 'mcq_multiple':
				return $this->normalize_mcq_multiple( $key );

			case 'true_false':
				return array( 'correct' => ! empty( $key['correct'] ) );

			case 'short_answer':
				return $this->normalize_short_answer( $key );

			case 'essay':
				return array(
					'min_words' => max( 0, absint( $key['min_words'] ?? 0 ) ),
					'max_words' => max( 0, absint( $key['max_words'] ?? 0 ) ),
				);

			default:
				/**
				 * Normalise a type this build does not know natively.
				 *
				 * Module types hook this. The default is to return the key UNCHANGED
				 * rather than empty: a cloze question edited on a site where the Cloze
				 * module has been switched off must keep its blanks, not have them
				 * silently erased by a save that only touched the title.
				 *
				 * @param array<string, mixed> $key  The raw answer key.
				 * @param string               $type The question type.
				 */
				return (array) apply_filters( 'plugixa_exam_normalize_answer_key', $key, $type );
		}
	}

	/**
	 * @param array<string, mixed> $key Raw key.
	 *
	 * @return array<string, mixed>
	 */
	private function normalize_mcq_multiple( array $key ): array {
		$policy = (string) ( $key['partial_policy'] ?? 'all_or_nothing' );

		if ( ! in_array( $policy, array( 'all_or_nothing', 'proportional' ), true ) ) {
			$policy = 'all_or_nothing';
		}

		return array(
			'partial_policy' => $policy,
			/*
			 * Negative marking. The system this is ported from persisted these two and
			 * never read them — the grader ignored both — so a site that switched it on got
			 * no negative marking and no error. Implemented here (see the specification,
			 * §12.1); the grader in the Attempts module reads them.
			 */
			'negative_marking' => ! empty( $key['negative_marking'] ),
			'min_score'        => (float) ( $key['min_score'] ?? 0 ),
		);
	}

	/**
	 * @param array<string, mixed> $key Raw key.
	 *
	 * @return array<string, mixed>
	 */
	private function normalize_short_answer( array $key ): array {
		$accepted = array();

		foreach ( (array) ( $key['accepted'] ?? array() ) as $value ) {
			$value = trim( sanitize_text_field( (string) $value ) );

			if ( '' !== $value ) {
				$accepted[] = $value;
			}
		}

		$use_regex = ! empty( $key['use_regex'] );

		if ( $use_regex ) {
			/*
			 * A pattern that does not compile would make `preg_match` emit a warning and
			 * return false on every attempt — every answer marked wrong, silently, with the
			 * cause visible only in the PHP error log. Patterns are validated here, at
			 * authoring time, where the author can still fix them.
			 *
			 * Length is capped as well. Catastrophic backtracking on an author-supplied
			 * pattern is a denial of service against the grader, and while the author is a
			 * trusted role, "trusted" is not the same as "never makes a mistake with a
			 * nested quantifier".
			 */
			$accepted = array_values(
				array_filter(
					$accepted,
					static fn ( string $pattern ): bool => plugixa_exam_is_valid_regex(
						'/' . str_replace( '/', '\/', $pattern ) . '/'
					)
				)
			);
		}

		return array(
			'accepted'         => $accepted,
			'case_insensitive' => ! isset( $key['case_insensitive'] ) || ! empty( $key['case_insensitive'] ),
			'trim'             => ! isset( $key['trim'] ) || ! empty( $key['trim'] ),
			'use_regex'        => $use_regex,
			'numeric'          => ! empty( $key['numeric'] ),
			'tolerance'        => max( 0.0, (float) ( $key['tolerance'] ?? 0 ) ),
		);
	}
}
