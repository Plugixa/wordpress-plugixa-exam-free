<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Cloze;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Cloze\Services\AnswerKeys;
use PlugixaExam\Modules\Cloze\Services\ClozeGrader;
use PlugixaExam\Modules\Cloze\Services\NoJsInput;
use PlugixaExam\Modules\Cloze\Services\Responses;
use PlugixaExam\Modules\Cloze\Services\RunnerPayload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fill in the blanks: a passage with numbered gaps, each with its own accepted answers.
 *
 * ────────────────────────────────────────────────────────────────────────────────────────
 * A MODULE OF ITS OWN, NOT A CASE IN THE QUESTIONS CORE, AND NOT PART OF A PREMIUM BUNDLE.
 *
 * Cloze was one of the four types in `AdvancedTypes` until 1.0.0 was cut, and it moved to the
 * free build on its own because it is the format free competitors already give away. It kept
 * the shape it had there: the core `Questions` module hardcodes the five basic types and fires
 * seven filters for everything else, and this module answers those filters exactly as a
 * premium or third-party type does —
 *
 *   `plugixa_exam_question_types`        → the type appears in the authoring picker
 *   `plugixa_exam_normalize_answer_key`  → its blanks are coerced into shape on save
 *   `plugixa_exam_sanitize_response`     → an examinee's answer is cleaned on its way in
 *   `plugixa_exam_grade_response`        → the passage is scored, blank by blank
 *   `plugixa_exam_runner_question`       → the runner learns how many gaps, never what fills them
 *   `plugixa_exam_no_js_input`           → it still works with JavaScript switched off
 *
 * Folding it into the core instead would have meant a new case in the grader, the attempt
 * service, the type registry, the take template and the authoring screen, for no gain beyond
 * a type that cannot be switched off — and the App Manager already warns before that switch
 * is thrown, because `types` below declares what it would cost.
 * ────────────────────────────────────────────────────────────────────────────────────────
 *
 * NO TABLES. The blanks live in `questions.answer_key` beside the `[[n]]` markers in the stem.
 */
final class Module implements ModuleInterface {

	/**
	 * The type this module contributes.
	 *
	 * @var string[]
	 */
	public const TYPES = array( 'cloze' );

	public function id(): string {
		return 'cloze';
	}

	public function app(): array {
		return array(
			'category' => 'authoring',
			'setting'  => 'app_cloze',
			'default'  => true,
			// Declared so the App Manager can say what switching it off would cost — see
			// ModuleInterface::app().
			'types'    => self::TYPES,
			// A question type is meaningless without a bank to put it in.
			'deps'     => array( 'questions' ),
		);
	}

	public function migrate(): void {
		// No tables. See the class docblock.
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		unset( $container );
	}

	public function register_hooks( Container $container ): void {
		add_filter( 'plugixa_exam_question_types', array( $this, 'types' ) );
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );
		add_filter( 'plugixa_exam_frontend_i18n_strings', array( $this, 'frontend_strings' ) );
		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );

		$container->get( AnswerKeys::class )->register_hooks();
		$container->get( ClozeGrader::class )->register_hooks();
		$container->get( RunnerPayload::class )->register_hooks();
		$container->get( Responses::class )->register_hooks();
		$container->get( NoJsInput::class )->register_hooks();
	}

	/**
	 * Declare the type.
	 *
	 * `options` is `none`: a cloze question has no option rows. Its blanks live in the answer
	 * key beside the stem markers, so the authoring screen hides the option editor.
	 *
	 * @param array<string, array<string, mixed>> $types Accumulated types.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function types( array $types ): array {
		return array_merge(
			$types,
			array(
				'cloze' => array(
					'label'       => __( 'Fill in the blanks', 'plugixa-exam' ),
					'description' => __( 'A passage with gaps to complete. Mark each gap [[1]], [[2]] and so on.', 'plugixa-exam' ),
					'graded_by'   => 'auto',
					'options'     => 'none',
					'order'       => 90,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $defaults Accumulated defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_defaults( array $defaults ): array {
		return array_merge( $defaults, array( 'app_cloze' => true ) );
	}

	/**
	 * What a candidate's screen says.
	 *
	 * ONE MSGID WITH A NAMED PLACEHOLDER, so a language that puts the number first can.
	 *
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function frontend_strings( array $strings ): array {
		return array_merge(
			$strings,
			array(
				/* translators: {n} is the number of the gap in the passage. */
				'clozeBlank' => __( 'Blank {n}', 'plugixa-exam' ),
			)
		);
	}

	/**
	 * What the authoring screen says.
	 *
	 * `acceptedAnswers` is deliberately NOT here. The short-answer editor already owns that
	 * key, and a second definition overwrote its help text on every site that had this type:
	 * the short-answer screen read the cloze sentence. The cloze editor uses the core one.
	 *
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function strings( array $strings ): array {
		return array_merge(
			$strings,
			array(
				// The App Manager labels this switch with `t( id, id )`; see `ModuleInterface::id()`.
				'cloze'               => __( 'Fill in the blanks', 'plugixa-exam' ),
				'clozeDesc' => __( 'Fill-in-the-blanks questions: a passage with gaps, each with its accepted answers.', 'plugixa-exam' ),
				'typeCloze'           => __( 'Fill in the blanks', 'plugixa-exam' ),
				'clozeBlanks'         => __( 'Blanks', 'plugixa-exam' ),
				'clozeBlanksHelp'     => __( 'One entry per [[n]] marker in the passage, in order.', 'plugixa-exam' ),
				'clozeNoMarkers'      => __( 'The passage has no [[1]] markers yet, so there is nothing to fill in.', 'plugixa-exam' ),
				/*
				 * Deliberately phrased without grammatical number agreement. A sentence like
				 * "the passage has %d markers but %d blanks are defined" reads as "1 markers"
				 * and "1 blanks", and no single `_n()` can fix it because TWO counts vary
				 * independently. Labelled counts are always correct, in every language.
				 */
				'clozeMarkerMismatch' => __( 'Markers in the passage: {markers} · Blanks defined: {blanks}. These should match.', 'plugixa-exam' ),
				'ignoreCase'          => __( 'Ignore capitals', 'plugixa-exam' ),
				'clozeAddBlank'       => __( 'Add a blank', 'plugixa-exam' ),
			)
		);
	}
}
