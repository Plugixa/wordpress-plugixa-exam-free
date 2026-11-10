<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\ImageChoice;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\ImageChoice\Services\AnswerKeys;
use PlugixaExam\Modules\ImageChoice\Services\ImageChoiceGrader;
use PlugixaExam\Modules\ImageChoice\Services\NoJsInput;
use PlugixaExam\Modules\ImageChoice\Services\Responses;
use PlugixaExam\Modules\ImageChoice\Services\RunnerPayload;
use PlugixaExam\Modules\ImageChoice\Services\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picture choice: multiple choice whose options are pictures rather than words.
 *
 * ────────────────────────────────────────────────────────────────────────────────────────
 * A FREE MODULE, SPLIT OUT OF THE PREMIUM `VisualTypes` WHEN 1.0.0 WAS CUT.
 *
 * It is the picture format free competitors already give away, so it moved on its own, through
 * the same filters it used there — `plugixa_exam_question_types`, `…_normalize_answer_key`,
 * `…_validate_question`, `…_sanitize_response`, `…_grade_response`, `…_runner_question` and
 * `…_no_js_input`. Hotspot, image matching and range stay premium.
 *
 * What it shares with image matching — pictures stored by option POSITION and published by
 * option id — lives in {@see \PlugixaExam\Modules\Media\Services\QuestionImages}, so the two
 * cannot drift apart and the premium half reaches the free one rather than the reverse.
 * ────────────────────────────────────────────────────────────────────────────────────────
 *
 * `deps` IS `questions` ONLY, NOT `media`, AND THAT IS A DECISION ABOUT FINISHED WORK.
 *
 * A dependency in this plugin does not merely stop authoring: `AppRegistry` switches the
 * dependent module fully off, its hooks are never registered, and an examinee's answer to a
 * type nobody recognises is discarded — scored zero, in silence. The App Manager warns before
 * a switch only about the switched app's own `types`, and Images declares none. So a `media`
 * dependency would let somebody switch Images off and quietly zero every picture-choice paper
 * without a single warning. Without it, switching Images off costs only the upload button; the
 * pictures already chosen keep rendering, because they are ordinary attachments.
 */
final class Module implements ModuleInterface {

	/**
	 * The type this module contributes.
	 *
	 * @var string[]
	 */
	public const TYPES = array( 'image_choice' );

	public function id(): string {
		return 'imagechoice';
	}

	public function app(): array {
		return array(
			'category' => 'authoring',
			'setting'  => 'app_imagechoice',
			'default'  => true,
			// Declared so the App Manager can say what switching it off would cost.
			'types'    => self::TYPES,
			// Not `media` — see the class docblock.
			'deps'     => array( 'questions' ),
		);
	}

	public function migrate(): void {
		/*
		 * No tables. The options are ordinary `question_options` rows with `is_correct`, exactly
		 * as for multiple choice, and the pictures are attachment ids in `answer_key.images`.
		 */
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
		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );

		$container->get( AnswerKeys::class )->register_hooks();
		$container->get( Validator::class )->register_hooks();
		$container->get( Responses::class )->register_hooks();
		$container->get( ImageChoiceGrader::class )->register_hooks();
		$container->get( RunnerPayload::class )->register_hooks();
		$container->get( NoJsInput::class )->register_hooks();
	}

	/**
	 * Declare the type.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * `option_editor` IS `correct_multiple`, AND IT USED TO BE `image_choice`.
	 *
	 * That value names which option editor the authoring screen draws, and the screen knows
	 * five: `correct_single`, `correct_multiple`, `sequence`, `match` and `bank`. It did not
	 * know `image_choice`, so it drew option rows with NO correct-answer control — while the
	 * help text beneath said "Tick the correct options above" and {@see Validator} refused
	 * every save for having no correct option. Picture choice could not be authored from the
	 * admin at all. The options ARE multiple choice's options, so they get its editor.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * @param array<string, array<string, mixed>> $types Accumulated types.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function types( array $types ): array {
		return array_merge(
			$types,
			array(
				'image_choice' => array(
					'label'         => __( 'Image choice', 'plugixa-exam' ),
					'description'   => __( 'Choose from options that are pictures rather than words.', 'plugixa-exam' ),
					'graded_by'     => 'auto',
					'options'       => 'required',
					'option_editor' => 'correct_multiple',
					'order'         => 100,
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
		return array_merge( $defaults, array( 'app_imagechoice' => true ) );
	}

	/**
	 * What the authoring screen says. The picture pickers' own strings are the Media module's.
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
				'imagechoice'     => __( 'Image choice', 'plugixa-exam' ),
				'imagechoiceDesc' => __( 'Multiple choice where the options are pictures rather than words.', 'plugixa-exam' ),
				'typeImageChoice' => __( 'Image choice', 'plugixa-exam' ),
				'imageChoiceHelp' => __( 'Tick the correct options above, exactly as for multiple choice. Each one needs a picture below.', 'plugixa-exam' ),
			)
		);
	}
}
